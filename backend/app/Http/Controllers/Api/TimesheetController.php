<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayGroup;
use App\Models\PayPeriod;
use App\Models\Timesheet;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\People\TimesheetEngine;
use App\Support\Automations;
use App\Support\People\TimesheetCsv;
use App\Mail\PeriodLockedCsvMail;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Timesheets (Bethany People PR 5).
 *
 * Read with attendance.view_team (own outlets; everyone with
 * outlets.all_access). Approve / send back with attendance.manage. Lock a
 * period with timesheets.lock (finance, the owner): after that nothing on
 * it moves, and payroll (PR 6) reads the approved snapshots. Everyone sees
 * and may submit their own.
 */
class TimesheetController extends Controller
{
    // ── Team ────────────────────────────────────────────────────────────────

    /** GET /timesheets?pay_group_id=&date= — every visible person in the period that contains the date. */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['pay_group_id' => 'nullable|exists:pay_groups,id', 'date' => 'nullable|date', 'outlet_id' => 'nullable|exists:outlets,id']);
        $group = !empty($v['pay_group_id']) ? PayGroup::findOrFail($v['pay_group_id']) : PayGroup::defaultGroup();
        abort_if(!$group, 422, 'No pay group is set up yet.');
        $period = $group->periodFor(isset($v['date']) ? Carbon::parse($v['date']) : now());

        $people = User::query()->where('user_type', 'staff')->where('status', 'active')
            ->whereIn('id', $this->visibleUserIds($request->user(), $v['outlet_id'] ?? null))
            ->with('employeeProfile:id,user_id,job_title,pay_group_id')
            ->orderBy('first_name')->orderBy('last_name')->get()
            ->filter(fn (User $u) => ($u->employeeProfile?->pay_group_id ?? PayGroup::defaultGroup()?->id) === $group->id);

        $sheets = Timesheet::where('pay_period_id', $period->id)->whereIn('user_id', $people->pluck('id'))->get()->keyBy('user_id');
        $rows = $people->map(function (User $u) use ($period, $sheets) {
            $sheet = $sheets->get($u->id);
            $live  = $sheet?->status === 'approved' ? null : TimesheetEngine::compute($u, $period);
            return [
                'user'          => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}"), 'job_title' => $u->employeeProfile?->job_title],
                'timesheet_id'  => $sheet?->id,
                'status'        => $sheet?->status ?? 'draft',
                'totals'        => $sheet?->status === 'approved' ? $this->snapshotTotals($sheet) : $live['totals'],
                'flags'         => $live ? count($live['flags']) : 0,
                'absences'      => $live ? count($live['absences']) : 0,
                'submitted_at'  => $sheet?->submitted_at?->toIso8601String(),
                'approved_by'   => $sheet?->approved_by ? trim(($sheet->approvedBy?->first_name ?? '') . ' ' . ($sheet->approvedBy?->last_name ?? '')) : null,
            ];
        })->values();

        return response()->json([
            'group'    => ['id' => $group->id, 'name' => $group->name, 'frequency' => $group->frequency],
            'groups'   => PayGroup::where('is_active', true)->orderBy('name')->get(['id', 'name', 'frequency']),
            'period'   => $this->period($period),
            'rows'     => $rows,
            'summary'  => [
                'people'    => $rows->count(),
                'approved'  => $rows->where('status', 'approved')->count(),
                'submitted' => $rows->where('status', 'submitted')->count(),
                'flagged'   => $rows->sum('flags'),
            ],
            'can_manage' => $request->user()->can('attendance.manage'),
            'can_lock'   => $request->user()->can('timesheets.lock'),
        ]);
    }

    /** GET /timesheets/person/{user}?date= — one person's period, day by day. */
    public function show(Request $request, int $user): JsonResponse
    {
        $person = User::where('user_type', 'staff')->findOrFail($user);
        abort_if(!$this->visibleUserIds($request->user())->contains($person->id), 403, 'This person is not in your outlets.');
        $period = $this->periodForPerson($person, $request->validate(['date' => 'nullable|date'])['date'] ?? null);
        $sheet  = Timesheet::with('approvedBy:id,first_name,last_name')->firstWhere(['pay_period_id' => $period->id, 'user_id' => $person->id]);

        return response()->json($this->detail($person, $period, $sheet) + ['can_manage' => $request->user()->can('attendance.manage') && $request->user()->id !== $person->id]);
    }

    /** POST /timesheets/person/{user}/approve — attendance.manage; snapshots the figures. */
    public function approve(Request $request, int $user): JsonResponse
    {
        $person = User::where('user_type', 'staff')->findOrFail($user);
        abort_if(!$this->visibleUserIds($request->user())->contains($person->id), 403, 'This person is not in your outlets.');
        abort_if($request->user()->id === $person->id, 403, 'You cannot approve your own timesheet.');
        $period = $this->periodForPerson($person, $request->validate(['date' => 'nullable|date'])['date'] ?? null);
        abort_if($period->isLocked(), 422, 'This period is locked.');

        $calc = TimesheetEngine::compute($person, $period);
        if ($calc['flags']) {
            return response()->json(['message' => count($calc['flags']) . ' punch(es) still need attention (Attendance › flagged) before this can be approved.', 'flags' => $calc['flags']], 422);
        }
        $sheet = Timesheet::firstOrNew(['pay_period_id' => $period->id, 'user_id' => $person->id]);
        abort_if($sheet->status === 'approved', 422, 'Already approved.');
        $sheet->fill([
            'status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(),
            'regular_minutes' => $calc['totals']['regular_minutes'], 'overtime_minutes' => $calc['totals']['overtime_minutes'],
            'rest_day_minutes' => $calc['totals']['rest_day_minutes'], 'holiday_minutes' => $calc['totals']['holiday_minutes'],
            'paid_leave_days' => $calc['totals']['paid_leave_days'], 'unpaid_leave_days' => $calc['totals']['unpaid_leave_days'],
            'days_worked' => $calc['totals']['days_worked'], 'snapshot' => ['days' => $calc['days'], 'group' => $calc['group'], 'absences' => $calc['absences']],
        ])->save();
        ActivityLogService::log('timesheet_approved', $sheet, ['totals' => $calc['totals']], "{$request->user()->first_name} approved {$person->first_name}'s timesheet for {$period->starts_on->format('j M')}–{$period->ends_on->format('j M')}");
        $this->tell($person, 'Timesheet approved', "Your hours for {$period->starts_on->format('j M')}–{$period->ends_on->format('j M')} are approved: " . $this->fmt($calc['totals']['worked_minutes']) . ($calc['totals']['overtime_minutes'] ? ' incl. ' . $this->fmt($calc['totals']['overtime_minutes']) . ' overtime' : '') . '.');

        return response()->json(['message' => 'Approved.'] + $this->detail($person, $period, $sheet->fresh('approvedBy')));
    }

    /** POST /timesheets/person/{user}/send-back {note} — attendance.manage. */
    public function sendBack(Request $request, int $user): JsonResponse
    {
        $person = User::where('user_type', 'staff')->findOrFail($user);
        abort_if(!$this->visibleUserIds($request->user())->contains($person->id), 403, 'This person is not in your outlets.');
        $v = $request->validate(['date' => 'nullable|date', 'note' => 'required|string|min:3|max:500']);
        $period = $this->periodForPerson($person, $v['date'] ?? null);
        abort_if($period->isLocked(), 422, 'This period is locked.');
        $sheet = Timesheet::firstOrNew(['pay_period_id' => $period->id, 'user_id' => $person->id]);
        $sheet->fill(['status' => 'sent_back', 'sent_back_note' => $v['note'], 'approved_by' => null, 'approved_at' => null,
            'regular_minutes' => null, 'overtime_minutes' => null, 'rest_day_minutes' => null, 'holiday_minutes' => null,
            'paid_leave_days' => null, 'unpaid_leave_days' => null, 'days_worked' => null, 'snapshot' => null])->save();
        ActivityLogService::log('timesheet_sent_back', $sheet, ['note' => $v['note']], "{$request->user()->first_name} sent back {$person->first_name}'s timesheet: {$v['note']}");
        $this->tell($person, 'Timesheet sent back', $v['note']);

        return response()->json(['message' => 'Sent back.'] + $this->detail($person, $period, $sheet->fresh()));
    }

    /** POST /pay-periods/{id}/lock — timesheets.lock; every sheet must be approved. */
    public function lock(Request $request, int $id): JsonResponse
    {
        $period = PayPeriod::with('group')->findOrFail($id);
        abort_if($period->isLocked(), 422, 'Already locked.');
        $people = User::where('user_type', 'staff')->where('status', 'active')->with('employeeProfile:id,user_id,pay_group_id')->get()
            ->filter(fn (User $u) => ($u->employeeProfile?->pay_group_id ?? PayGroup::defaultGroup()?->id) === $period->pay_group_id);
        $approved = Timesheet::where('pay_period_id', $period->id)->where('status', 'approved')->pluck('user_id');
        $missing = $people->reject(fn ($u) => $approved->contains($u->id));
        if ($missing->isNotEmpty() && !$request->boolean('force')) {
            return response()->json(['message' => $missing->count() . ' timesheet(s) not yet approved: ' . $missing->map(fn ($u) => $u->first_name)->take(5)->join(', ') . '. Approve them, or lock with force to leave them out of this period.',
                'missing' => $missing->map(fn ($u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])->values()], 422);
        }
        $period->update(['status' => 'locked', 'locked_by' => $request->user()->id, 'locked_at' => now()]);
        ActivityLogService::log('pay_period_locked', $period, ['approved' => $approved->count(), 'left_out' => $missing->pluck('id')->all()],
            "{$request->user()->first_name} locked {$period->group?->name} {$period->starts_on->format('j M')}–{$period->ends_on->format('j M')}");
        $sent = self::emailLockedCsv($period->fresh('group'), $request->user(), $approved->count(), $missing->count());

        return response()->json(['message' => 'Period locked.' . ($sent === null ? '' : ($sent ? " CSV emailed to {$sent}." : ' Nobody to email the CSV to.')), 'period' => $this->period($period->fresh())]);
    }

    /**
     * C6: the `period_csv` automation — the approved-hours CSV goes to its
     * recipients by email the moment a period locks. null when the automation
     * is off; otherwise the number of people it went to. A mail failure is
     * reported (the period stays locked; the export is still there to download).
     */
    public static function emailLockedCsv(PayPeriod $period, User $by, int $sheets, int $leftOut): ?int
    {
        if (!Automations::active('period_csv')) return null;
        $to = Automations::recipientsFor('period_csv')->filter(fn (User $u) => filter_var($u->email, FILTER_VALIDATE_EMAIL));
        if ($to->isEmpty()) return 0;
        $mail = new PeriodLockedCsvMail($period, trim("{$by->first_name} {$by->last_name}"), $sheets, $leftOut);
        $n = 0;
        foreach ($to as $u) {
            try { Mail::to($u->email)->send($mail); $n++; }
            catch (\Throwable $e) { report($e); }
        }
        ActivityLogService::log('pay_period_csv_emailed', $period, ['to' => $to->pluck('id')->all(), 'sent' => $n], "The locked period's CSV was emailed to {$n} of {$to->count()}.");
        return $n;
    }

    /** POST /pay-periods/{id}/unlock {reason} — the owner only. */
    public function unlock(Request $request, int $id): JsonResponse
    {
        abort_if(!$request->user()->isSuperAdmin(), 403, 'Only the owner unlocks a period.');
        $period = PayPeriod::findOrFail($id);
        abort_if(!$period->isLocked(), 422, 'Not locked.');
        $reason = $request->validate(['reason' => 'required|string|min:5|max:300'])['reason'];
        $period->update(['status' => 'open', 'unlocked_by' => $request->user()->id, 'unlocked_at' => now(), 'unlock_reason' => $reason]);
        ActivityLogService::log('pay_period_unlocked', $period, ['reason' => $reason], "{$request->user()->first_name} unlocked a pay period: {$reason}");

        return response()->json(['message' => 'Period unlocked.', 'period' => $this->period($period->fresh())]);
    }

    /** GET /timesheets/export?pay_period_id= — CSV of approved snapshots (timesheets.lock, step-up, download gate). */
    public function export(Request $request)
    {
        $period = PayPeriod::with('group')->findOrFail($request->validate(['pay_period_id' => 'required|exists:pay_periods,id'])['pay_period_id']);
        $csv = TimesheetCsv::build($period); $name = TimesheetCsv::filename($period);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    // ── Pay groups ──────────────────────────────────────────────────────────

    /** GET /pay-groups — attendance.view_team. */
    public function groups(): JsonResponse
    {
        return response()->json(['data' => PayGroup::orderBy('name')->get()->map(fn ($g) => $this->group($g))->values()]);
    }

    /** POST /pay-groups (hr.edit) */
    public function storeGroup(Request $request): JsonResponse
    {
        $g = PayGroup::create($this->groupRules($request));
        if ($g->is_default) PayGroup::whereKeyNot($g->id)->update(['is_default' => false]);
        ActivityLogService::log('pay_group_created', $g, $g->toArray(), "{$request->user()->first_name} created pay group {$g->name}");

        return response()->json(['message' => 'Pay group added.', 'group' => $this->group($g->fresh())], 201);
    }

    /** PUT /pay-groups/{id} (hr.edit) */
    public function updateGroup(Request $request, int $id): JsonResponse
    {
        $g = PayGroup::findOrFail($id);
        $v = $this->groupRules($request, true);
        $before = $g->only(array_keys($v));
        $g->update($v);
        if ($g->is_default) PayGroup::whereKeyNot($g->id)->update(['is_default' => false]);
        ActivityLogService::log('pay_group_changed', $g, ['before' => $before, 'after' => $v], "{$request->user()->first_name} changed pay group {$g->name}");

        return response()->json(['message' => 'Pay group saved.', 'group' => $this->group($g->fresh())]);
    }

    // ── Me ──────────────────────────────────────────────────────────────────

    /** GET /me/timesheet?date= */
    public function mine(Request $request): JsonResponse
    {
        $me = $request->user();
        $period = $this->periodForPerson($me, $request->validate(['date' => 'nullable|date'])['date'] ?? null);
        $sheet = Timesheet::with('approvedBy:id,first_name,last_name')->firstWhere(['pay_period_id' => $period->id, 'user_id' => $me->id]);

        return response()->json($this->detail($me, $period, $sheet) + ['can_submit' => !$period->isLocked() && (!$sheet || in_array($sheet->status, ['draft', 'sent_back'], true))]);
    }

    /** POST /me/timesheet/submit {note?} — "I have looked; these are my hours." */
    public function submit(Request $request): JsonResponse
    {
        $me = $request->user();
        $v = $request->validate(['date' => 'nullable|date', 'note' => 'nullable|string|max:500']);
        $period = $this->periodForPerson($me, $v['date'] ?? null);
        abort_if($period->isLocked(), 422, 'This period is locked.');
        $sheet = Timesheet::firstOrNew(['pay_period_id' => $period->id, 'user_id' => $me->id]);
        abort_if($sheet->status === 'approved', 422, 'Already approved.');
        $sheet->fill(['status' => 'submitted', 'submitted_at' => now(), 'note' => $v['note'] ?? $sheet->note])->save();

        return response()->json(['message' => 'Submitted to your manager.'] + $this->detail($me, $period, $sheet->fresh()));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function periodForPerson(User $person, ?string $date): PayPeriod
    {
        $person->loadMissing('employeeProfile');
        $group = $person->employeeProfile?->pay_group_id ? PayGroup::find($person->employeeProfile->pay_group_id) : null;
        $group ??= PayGroup::defaultGroup();
        abort_if(!$group, 422, 'No pay group is set up yet.');
        return $group->periodFor($date ? Carbon::parse($date) : now());
    }

    private function detail(User $person, PayPeriod $period, ?Timesheet $sheet): array
    {
        $approved = $sheet?->status === 'approved';
        $calc = $approved ? null : TimesheetEngine::compute($person, $period);
        return [
            'person'   => ['id' => $person->id, 'name' => trim("{$person->first_name} {$person->last_name}")],
            'period'   => $this->period($period),
            'status'   => $sheet?->status ?? 'draft',
            'note'     => $sheet?->note,
            'sent_back_note' => $sheet?->sent_back_note,
            'submitted_at'   => $sheet?->submitted_at?->toIso8601String(),
            'approved_by'    => $sheet?->approvedBy ? trim("{$sheet->approvedBy->first_name} {$sheet->approvedBy->last_name}") : null,
            'approved_at'    => $sheet?->approved_at?->toIso8601String(),
            'totals'   => $approved ? $this->snapshotTotals($sheet) : $calc['totals'],
            'group'    => $approved ? ($sheet->snapshot['group'] ?? null) : $calc['group'],
            'days'     => $approved ? ($sheet->snapshot['days'] ?? []) : $calc['days'],
            'flags'    => $approved ? [] : $calc['flags'],
            'absences' => $approved ? ($sheet->snapshot['absences'] ?? []) : $calc['absences'],
            'snapshot' => $approved,
        ];
    }

    private function snapshotTotals(Timesheet $s): array
    {
        return [
            'regular_minutes' => (int) $s->regular_minutes, 'overtime_minutes' => (int) $s->overtime_minutes,
            'rest_day_minutes' => (int) $s->rest_day_minutes, 'holiday_minutes' => (int) $s->holiday_minutes,
            'worked_minutes' => (int) $s->regular_minutes + (int) $s->overtime_minutes + (int) $s->rest_day_minutes + (int) $s->holiday_minutes,
            'paid_leave_days' => (float) $s->paid_leave_days, 'unpaid_leave_days' => (float) $s->unpaid_leave_days, 'days_worked' => (int) $s->days_worked,
        ];
    }

    private function period(PayPeriod $p): array
    {
        return ['id' => $p->id, 'pay_group_id' => $p->pay_group_id, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(),
            'status' => $p->status, 'locked_at' => $p->locked_at?->toIso8601String(), 'unlock_reason' => $p->unlock_reason];
    }

    private function group(PayGroup $g): array
    {
        return ['id' => $g->id, 'name' => $g->name, 'frequency' => $g->frequency, 'standard_hours_per_day' => (float) $g->standard_hours_per_day,
            'overtime_after_daily_hours' => $g->overtime_after_daily_hours !== null ? (float) $g->overtime_after_daily_hours : null,
            'overtime_after_weekly_hours' => $g->overtime_after_weekly_hours !== null ? (float) $g->overtime_after_weekly_hours : null,
            'overtime_multiplier' => (float) $g->overtime_multiplier, 'rest_day_multiplier' => (float) $g->rest_day_multiplier,
            'holiday_multiplier' => (float) $g->holiday_multiplier, 'round_to_minutes' => $g->round_to_minutes, 'is_default' => $g->is_default, 'is_active' => $g->is_active,
            // C1
            'period_end_day' => $g->period_end_day, 'pay_date_day' => $g->pay_date_day, 'hours_by_weekday' => $g->hours_by_weekday,
            'unpaid_break_minutes' => (int) $g->unpaid_break_minutes, 'unpaid_break_after_hours' => $g->unpaid_break_after_hours !== null ? (float) $g->unpaid_break_after_hours : null];
    }

    private function groupRules(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'name'                        => "$req|string|max:60",
            'frequency'                   => "$req|in:monthly,weekly",
            'standard_hours_per_day'      => 'sometimes|numeric|min:1|max:24',
            'overtime_after_daily_hours'  => 'sometimes|nullable|numeric|min:1|max:24',
            'overtime_after_weekly_hours' => 'sometimes|nullable|numeric|min:1|max:168',
            'overtime_multiplier'         => 'sometimes|numeric|min:1|max:5',
            'rest_day_multiplier'         => 'sometimes|numeric|min:1|max:5',
            'holiday_multiplier'          => 'sometimes|numeric|min:1|max:5',
            'round_to_minutes'            => 'sometimes|integer|in:1,5,10,15,30',
            'is_default'                  => 'sometimes|boolean',
            'is_active'                   => 'sometimes|boolean',
            // C1: period end day (Bethany: 10 → 11th to 10th), pay date, hours per weekday, unpaid lunch
            'period_end_day'              => 'sometimes|nullable|integer|between:1,28',
            'pay_date_day'                => 'sometimes|nullable|integer|between:1,28',
            'hours_by_weekday'            => 'sometimes|nullable|array',
            'hours_by_weekday.*'          => 'nullable|numeric|min:0|max:24',
            'unpaid_break_minutes'        => 'sometimes|integer|between:0,180',
            'unpaid_break_after_hours'    => 'sometimes|nullable|numeric|min:1|max:24',
        ]);
    }

    private function visibleUserIds(User $viewer, ?int $outletId = null)
    {
        if ($viewer->can('outlets.all_access')) {
            $q = DB::table('users')->where('user_type', 'staff');
            if ($outletId) $q->whereIn('id', DB::table('outlet_user')->where('outlet_id', $outletId)->pluck('user_id'));
            return $q->pluck('id');
        }
        $outlets = DB::table('outlet_user')->where('user_id', $viewer->id)->pluck('outlet_id');
        if ($outletId) $outlets = $outlets->filter(fn ($o) => (int) $o === (int) $outletId);
        return DB::table('outlet_user')->whereIn('outlet_id', $outlets)->pluck('user_id')->push($viewer->id)->unique()->values();
    }

    private function tell(User $user, string $title, string $body): void
    {
        if ($user->status !== 'active') return;
        NotificationService::deliver($user, new InAppNotification(title: $title, body: $body, actionUrl: '/me/timesheet', icon: 'clock'));
    }

    private function fmt(int $minutes): string
    {
        return intdiv($minutes, 60) . 'h' . ($minutes % 60 ? ' ' . ($minutes % 60) . 'm' : '');
    }
}
