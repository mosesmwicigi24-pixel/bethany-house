<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\People\Leave;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Time off (Bethany People PR 4).
 *
 * A person asks; the manager they report to (users.reports_to) or a
 * leave.approve holder at their outlet decides; nobody decides their own.
 * Balances are derived (App\Services\People\Leave). Leave is not money:
 * nothing here touches pay; PR 6 reads approved leave when it needs to.
 */
class LeaveController extends Controller
{
    // ── Me ──────────────────────────────────────────────────────────────────

    /** GET /me/leave?year= */
    public function mine(Request $request): JsonResponse
    {
        $year = $this->year($request);
        $me   = $request->user();
        $requests = LeaveRequest::with(['type', 'decidedBy:id,first_name,last_name'])->where('user_id', $me->id)
            ->where(fn ($q) => $q->whereYear('starts_on', $year)->orWhere('ends_on', '>=', now()->toDateString()))
            ->orderByDesc('starts_on')->limit(100)->get();

        return response()->json([
            'year'      => $year,
            'balances'  => array_map(fn ($b) => $this->balance($b), Leave::balances($me, $year)),
            'requests'  => $requests->map(fn ($r) => $this->leaveRequest($r))->values(),
            'types'     => LeaveType::where('is_active', true)->orderBy('sort_order')->get()->map(fn ($t) => $this->type($t))->values(),
            'approvers' => Leave::approversFor($me)->map(fn ($u) => trim("{$u->first_name} {$u->last_name}"))->values(),
        ]);
    }

    /** GET /me/leave/preview?leave_type_id&starts_on&ends_on — how many days this would be. */
    public function preview(Request $request): JsonResponse
    {
        $v = $request->validate(['leave_type_id' => 'required|exists:leave_types,id', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on']);
        $type = LeaveType::findOrFail($v['leave_type_id']);
        $m = Leave::measure($type, $v['starts_on'], $v['ends_on']);
        $bal = Leave::balance($request->user(), $type, Carbon::parse($v['starts_on'])->year);
        return response()->json(['days' => $m['days'], 'holidays_skipped' => $m['holidays'], 'available' => $bal['available'],
            'enough' => $bal['available'] === null || $bal['available'] >= $m['days']]);
    }

    /** POST /me/leave {leave_type_id, starts_on, ends_on, reason?, evidence_document_id?} */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $v  = $request->validate([
            'leave_type_id'        => 'required|exists:leave_types,id',
            'starts_on'            => 'required|date|after_or_equal:' . now()->subDays(7)->toDateString(),
            'ends_on'              => 'required|date|after_or_equal:starts_on',
            'reason'               => 'nullable|string|max:500',
            'evidence_document_id' => 'nullable|integer',
        ]);
        if (!empty($v['evidence_document_id']) && !$me->staffDocuments()->whereNull('removed_at')->whereKey($v['evidence_document_id'])->exists()) {
            return response()->json(['message' => 'That document is not on your file.'], 422);
        }
        $r = $this->create($me, $v, $me, approved: false);
        if ($r instanceof JsonResponse) return $r;

        $approvers = Leave::approversFor($me);
        if ($approvers->isNotEmpty()) {
            NotificationService::deliver($approvers, new InAppNotification(
                title: "{$me->first_name} asks for {$r->type->name}",
                body:  $this->span($r) . " · {$r->days} day(s)" . ($r->reason ? " — {$r->reason}" : ''),
                actionUrl: '/leave', icon: 'calendar', data: ['leave_request_id' => $r->id],
            ));
        }

        return response()->json(['message' => $approvers->isNotEmpty() ? 'Your request is with ' . $approvers->map(fn ($u) => $u->first_name)->join(', ', ' and ') . '.' : 'Your request is recorded; nobody is set to approve it yet.',
            'request' => $this->leaveRequest($r->fresh(['type', 'decidedBy']))], 201);
    }

    /** DELETE /me/leave/{id} — cancel my pending request, or my approved leave before it starts. */
    public function cancelMine(Request $request, int $id): JsonResponse
    {
        $r = LeaveRequest::with('type')->findOrFail($id);
        abort_if($r->user_id !== $request->user()->id, 403);
        abort_if(!in_array($r->status, ['pending', 'approved'], true), 422, 'This request is already closed.');
        abort_if($r->status === 'approved' && $r->starts_on->lte(now()->startOfDay()), 422, 'Leave that has started can only be changed by your manager.');
        $r->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id]);

        return response()->json(['message' => 'Request cancelled.', 'request' => $this->leaveRequest($r->fresh(['type', 'decidedBy']))]);
    }

    // ── Team ────────────────────────────────────────────────────────────────

    /** GET /leave/requests?status=pending&outlet_id= (attendance.view_team) */
    public function requests(Request $request): JsonResponse
    {
        $v = $request->validate(['status' => 'nullable|in:pending,approved,declined,cancelled', 'outlet_id' => 'nullable|exists:outlets,id', 'year' => 'nullable|integer']);
        $q = LeaveRequest::with(['type', 'user:id,first_name,last_name,reports_to', 'decidedBy:id,first_name,last_name'])
            ->whereIn('user_id', $this->visibleUserIds($request->user(), $v['outlet_id'] ?? null))
            ->orderByDesc('created_at');
        if (!empty($v['status'])) $q->where('status', $v['status']);
        if (!empty($v['year'])) $q->whereYear('starts_on', (int) $v['year']);
        $rows = $q->limit(200)->get();
        $me = $request->user();

        return response()->json(['data' => $rows->map(fn ($r) => $this->leaveRequest($r) + [
            'can_decide'  => $r->status === 'pending' && Leave::mayDecide($me, $r->user),
            'roster_clash'=> $r->status === 'pending' ? $this->rosterClash($r) : [],
        ])->values()]);
    }

    /** GET /leave/calendar?from&to&outlet_id (attendance.view_team) — who is off when. */
    public function calendar(Request $request): JsonResponse
    {
        $v = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'outlet_id' => 'nullable|exists:outlets,id']);
        $from = isset($v['from']) ? Carbon::parse($v['from']) : now()->startOfMonth();
        $to   = isset($v['to']) ? Carbon::parse($v['to']) : $from->copy()->endOfMonth();
        $rows = LeaveRequest::with(['type', 'user:id,first_name,last_name'])->live()
            ->whereIn('user_id', $this->visibleUserIds($request->user(), $v['outlet_id'] ?? null))
            ->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())
            ->orderBy('starts_on')->get();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(),
            'data' => $rows->map(fn ($r) => $this->leaveRequest($r))->values()]);
    }

    /** POST /leave/requests/{id}/approve {note?} */
    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'approved');
    }

    /** POST /leave/requests/{id}/decline {note?} */
    public function decline(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'declined');
    }

    /** POST /leave/requests {user_id, leave_type_id, starts_on, ends_on, reason?} — a manager records leave on someone's behalf; approved at once. */
    public function storeForPerson(Request $request): JsonResponse
    {
        $v = $request->validate([
            'user_id'       => 'required|exists:users,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'starts_on'     => 'required|date',
            'ends_on'       => 'required|date|after_or_equal:starts_on',
            'reason'        => 'nullable|string|max:500',
        ]);
        $person = User::findOrFail($v['user_id']);
        abort_if(!Leave::mayDecide($request->user(), $person), 403, 'You do not decide leave for this person.');
        $r = $this->create($person, $v, $request->user(), approved: true);
        if ($r instanceof JsonResponse) return $r;
        $this->tell($person, 'Leave recorded for you', "{$r->type->name}: " . $this->span($r) . " · {$r->days} day(s), entered by {$request->user()->first_name}.", $r);

        return response()->json(['message' => 'Leave recorded.', 'request' => $this->leaveRequest($r->fresh(['type', 'decidedBy']))], 201);
    }

    /** DELETE /leave/requests/{id} — a manager cancels approved or pending leave (balance returns). */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $r = LeaveRequest::with(['type', 'user'])->findOrFail($id);
        abort_if(!Leave::mayDecide($request->user(), $r->user), 403, 'You do not decide leave for this person.');
        abort_if(!in_array($r->status, ['pending', 'approved'], true), 422, 'This request is already closed.');
        $note = $request->validate(['note' => 'nullable|string|max:300'])['note'] ?? null;
        $r->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'decision_note' => $note]);
        $this->tell($r->user, 'Leave cancelled', "{$r->type->name} " . $this->span($r) . ' was cancelled by ' . $request->user()->first_name . ($note ? ": {$note}" : '.'), $r);

        return response()->json(['message' => 'Leave cancelled.', 'request' => $this->leaveRequest($r->fresh(['type', 'decidedBy']))]);
    }

    /** GET /people/{user}/leave?year= (hr.view) */
    public function forPerson(Request $request, int $user): JsonResponse
    {
        $person = User::where('user_type', 'staff')->findOrFail($user);
        abort_if(!$this->visibleUserIds($request->user())->contains($person->id), 403, 'This person is not in your outlets.');
        $year = $this->year($request);
        $rows = LeaveRequest::with(['type', 'decidedBy:id,first_name,last_name'])->where('user_id', $person->id)->whereYear('starts_on', $year)->orderByDesc('starts_on')->get();
        $adj  = LeaveAdjustment::with('type')->where('user_id', $person->id)->where('year', $year)->orderByDesc('created_at')->get();

        return response()->json([
            'year'        => $year,
            'balances'    => array_map(fn ($b) => $this->balance($b), Leave::balances($person, $year)),
            'requests'    => $rows->map(fn ($r) => $this->leaveRequest($r))->values(),
            'adjustments' => $adj->map(fn ($a) => ['id' => $a->id, 'type' => $a->type?->name, 'days' => (float) $a->days, 'reason' => $a->reason, 'created_at' => $a->created_at?->toIso8601String()])->values(),
            'can_adjust'  => $request->user()->can('hr.edit'),
            'can_record'  => Leave::mayDecide($request->user(), $person),
        ]);
    }

    /** POST /leave/adjustments {user_id, leave_type_id, year, days, reason} (hr.edit) — carry-over, a grant, a correction. */
    public function adjust(Request $request): JsonResponse
    {
        $v = $request->validate([
            'user_id'       => 'required|exists:users,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'year'          => 'required|integer|min:2020|max:2100',
            'days'          => 'required|numeric|not_in:0|between:-365,365',
            'reason'        => 'required|string|min:3|max:300',
        ]);
        $a = LeaveAdjustment::create($v + ['created_by' => $request->user()->id]);
        ActivityLogService::log('leave_adjusted', $a, $v, "{$request->user()->first_name} adjusted leave by {$v['days']} day(s): {$v['reason']}");

        return response()->json(['message' => 'Balance adjusted.', 'adjustment' => $a], 201);
    }

    // ── Types ───────────────────────────────────────────────────────────────

    /** GET /leave/types — everyone. */
    public function types(): JsonResponse
    {
        return response()->json(['data' => LeaveType::orderBy('sort_order')->get()->map(fn ($t) => $this->type($t))->values()]);
    }

    /** PUT /leave/types/{id} (hr.edit) */
    public function updateType(Request $request, int $id): JsonResponse
    {
        $t = LeaveType::findOrFail($id);
        $v = $request->validate([
            'name'                 => 'sometimes|string|max:60',
            'days_per_year'        => 'sometimes|nullable|numeric|min:0|max:366',
            'accrual'              => 'sometimes|in:front,monthly',
            'is_paid'              => 'sometimes|boolean',
            'counts_calendar_days' => 'sometimes|boolean',
            'evidence_after_days'  => 'sometimes|nullable|integer|min:0|max:365',
            'is_active'            => 'sometimes|boolean',
        ]);
        $before = $t->only(array_keys($v));
        $t->update($v);
        ActivityLogService::log('leave_type_changed', $t, ['before' => $before, 'after' => $v], "{$request->user()->first_name} changed the {$t->name} policy");

        return response()->json(['message' => 'Leave type saved.', 'type' => $this->type($t->fresh())]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function create(User $person, array $v, User $by, bool $approved): LeaveRequest|JsonResponse
    {
        $type = LeaveType::findOrFail($v['leave_type_id']);
        abort_if(!$type->is_active, 422, 'That leave type is not in use.');
        $m = Leave::measure($type, $v['starts_on'], $v['ends_on']);
        if ($m['days'] <= 0) {
            return response()->json(['message' => 'Those dates contain no working days.'], 422);
        }
        $overlap = LeaveRequest::where('user_id', $person->id)->live()
            ->where('starts_on', '<=', $v['ends_on'])->where('ends_on', '>=', $v['starts_on'])->first();
        if ($overlap) {
            return response()->json(['message' => 'That overlaps leave already ' . $overlap->status . ' from ' . $overlap->starts_on->format('j M') . '.'], 422);
        }
        if ($type->tracksBalance()) {
            $bal = Leave::balance($person, $type, Carbon::parse($v['starts_on'])->year);
            if ($bal['available'] < $m['days']) {
                return response()->json(['message' => "Only {$bal['available']} day(s) of {$type->name} available this year; this needs {$m['days']}."], 422);
            }
        }
        $r = LeaveRequest::create([
            'user_id' => $person->id, 'leave_type_id' => $type->id, 'starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'],
            'days' => $m['days'], 'reason' => $v['reason'] ?? null, 'evidence_document_id' => $v['evidence_document_id'] ?? null,
            'status' => $approved ? 'approved' : 'pending', 'created_by' => $by->id,
            'decided_by' => $approved ? $by->id : null, 'decided_at' => $approved ? now() : null,
        ]);
        $r->setRelation('type', $type);
        return $r;
    }

    private function decide(Request $request, int $id, string $status): JsonResponse
    {
        $r = LeaveRequest::with(['type', 'user'])->findOrFail($id);
        abort_if(!Leave::mayDecide($request->user(), $r->user), 403, 'You do not decide leave for this person.');
        abort_if($r->status !== 'pending', 422, 'This request has already been decided.');
        $note = $request->validate(['note' => 'nullable|string|max:300'])['note'] ?? null;
        if ($status === 'approved' && $r->type->tracksBalance()) {
            $bal = Leave::balance($r->user, $r->type, $r->starts_on->year);
            if ($bal['available'] < (float) $r->days) {
                return response()->json(['message' => "Only {$bal['available']} day(s) available; this needs {$r->days}. Adjust the balance first or decline."], 422);
            }
        }
        $r->update(['status' => $status, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $note]);
        ActivityLogService::log('leave_' . $status, $r, ['note' => $note], "{$request->user()->first_name} {$status} {$r->user->first_name}'s {$r->type->name} " . $this->span($r));
        $this->tell($r->user, $status === 'approved' ? 'Leave approved' : 'Leave declined',
            "{$r->type->name} " . $this->span($r) . ($note ? " — {$note}" : '.'), $r);

        return response()->json(['message' => $status === 'approved' ? 'Approved.' : 'Declined.',
            'request' => $this->leaveRequest($r->fresh(['type', 'decidedBy'])), 'roster_clash' => $status === 'approved' ? $this->rosterClash($r) : []]);
    }

    /** Published shifts of the person inside the leave span: the manager re-rosters them. */
    private function rosterClash(LeaveRequest $r): array
    {
        return Shift::where('user_id', $r->user_id)->where('status', 'published')
            ->whereBetween('date', [$r->starts_on->toDateString(), $r->ends_on->toDateString()])->orderBy('date')->get()
            ->map(fn ($s) => ['id' => $s->id, 'date' => $s->date->toDateString(), 'starts_at' => Shift::hhmm($s->starts_at), 'ends_at' => Shift::hhmm($s->ends_at)])->all();
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
        return DB::table('outlet_user')->whereIn('outlet_id', $outlets)->pluck('user_id')
            ->merge(DB::table('users')->where('reports_to', $viewer->id)->pluck('id'))->push($viewer->id)->unique()->values();
    }

    private function tell(User $user, string $title, string $body, LeaveRequest $r): void
    {
        if ($user->status !== 'active') return;
        NotificationService::deliver($user, new InAppNotification(title: $title, body: $body, actionUrl: '/me/leave', icon: 'calendar', data: ['leave_request_id' => $r->id]));
    }

    private function year(Request $request): int
    {
        $y = (int) ($request->validate(['year' => 'nullable|integer|min:2020|max:2100'])['year'] ?? 0);
        return $y ?: now()->year;
    }

    private function span(LeaveRequest $r): string
    {
        return $r->starts_on->eq($r->ends_on) ? $r->starts_on->format('D j M') : $r->starts_on->format('D j M') . ' – ' . $r->ends_on->format('D j M');
    }

    private function type(LeaveType $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'days_per_year' => $t->days_per_year !== null ? (float) $t->days_per_year : null,
            'accrual' => $t->accrual, 'is_paid' => $t->is_paid, 'counts_calendar_days' => $t->counts_calendar_days,
            'evidence_after_days' => $t->evidence_after_days, 'is_active' => $t->is_active];
    }

    private function balance(array $b): array
    {
        return ['type' => $this->type($b['type']), 'entitled' => $b['entitled'], 'used' => $b['used'], 'requested' => $b['requested'],
            'adjusted' => $b['adjusted'], 'available' => $b['available']];
    }

    private function leaveRequest(LeaveRequest $r): array
    {
        return [
            'id'         => $r->id,
            'user'       => $r->relationLoaded('user') && $r->user ? ['id' => $r->user->id, 'name' => trim("{$r->user->first_name} {$r->user->last_name}")] : null,
            'type'       => $r->type ? $this->type($r->type) : null,
            'starts_on'  => $r->starts_on->toDateString(),
            'ends_on'    => $r->ends_on->toDateString(),
            'days'       => (float) $r->days,
            'reason'     => $r->reason,
            'status'     => $r->status,
            'evidence_document_id' => $r->evidence_document_id,
            'decided_by' => $r->relationLoaded('decidedBy') && $r->decidedBy ? trim("{$r->decidedBy->first_name} {$r->decidedBy->last_name}") : null,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
