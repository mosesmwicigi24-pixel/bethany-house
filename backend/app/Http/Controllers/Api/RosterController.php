<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The weekly roster (Bethany People PR 3).
 *
 * Managers (attendance.view_team to read, roster.manage to write) draft a
 * week per outlet, publish it, copy it forward and decide swaps. Staff read
 * their own shifts, claim open ones and ask to swap. Outlet scope is the
 * same rule as attendance: own outlets, or every outlet with
 * outlets.all_access.
 */
class RosterController extends Controller
{
    // ── Manager: the week ───────────────────────────────────────────────────

    /** GET /roster?outlet_id=&week=YYYY-MM-DD (any day; Monday is used) */
    public function week(Request $request): JsonResponse
    {
        $validated = $request->validate(['outlet_id' => 'required|exists:outlets,id', 'week' => 'nullable|date']);
        $outlet = Outlet::findOrFail($validated['outlet_id']);
        $this->authorizeOutlet($request->user(), $outlet->id);

        [$start, $end] = $this->weekBounds($validated['week'] ?? null);

        $members = User::query()->where('user_type', 'staff')->where('status', 'active')
            ->whereIn('id', DB::table('outlet_user')->where('outlet_id', $outlet->id)->pluck('user_id'))
            ->with('employeeProfile:id,user_id,job_title')
            ->orderBy('first_name')->orderBy('last_name')->get();

        $shifts = Shift::with('user:id,first_name,last_name')
            ->where('outlet_id', $outlet->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->live()
            ->orderBy('date')->orderBy('starts_at')->get();

        $pendingSwaps = ShiftSwapRequest::with(['shift', 'fromUser:id,first_name,last_name', 'toUser:id,first_name,last_name'])
            ->pending()->whereHas('shift', fn ($q) => $q->where('outlet_id', $outlet->id))->orderBy('created_at')->get();

        $holidays = PublicHoliday::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()
            ->mapWithKeys(fn ($h) => [$h->date->toDateString() => $h->name]);

        return response()->json([
            'outlet'      => ['id' => $outlet->id, 'name' => $outlet->name, 'operating_hours' => $outlet->operating_hours],
            'week_start'  => $start->toDateString(),
            'week_end'    => $end->toDateString(),
            'days'        => collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i)->toDateString())->values(),
            'holidays'    => $holidays,
            'members'     => $members->map(fn ($u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}"), 'job_title' => $u->employeeProfile?->job_title])->values(),
            'shifts'      => $shifts->map(fn ($s) => $this->shift($s))->values(),
            'open_count'  => $shifts->whereNull('user_id')->count(),
            'draft_count' => $shifts->where('status', 'draft')->count(),
            'hours_by_user' => $shifts->whereNotNull('user_id')->groupBy('user_id')->map(fn ($g) => round($g->sum(fn ($s) => $s->minutes()) / 60, 1)),
            'pending_swaps' => $pendingSwaps->map(fn ($r) => $this->swap($r))->values(),
            'can_manage'  => $request->user()->can('roster.manage'),
        ]);
    }

    /** POST /roster/shifts  {outlet_id, user_id?, date, starts_at, ends_at, label?, notes?, publish?} */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'user_id'   => 'nullable|exists:users,id',
            'date'      => 'required|date',
            'starts_at' => 'required|date_format:H:i',
            'ends_at'   => 'required|date_format:H:i|different:starts_at',
            'label'     => 'nullable|string|max:60',
            'notes'     => 'nullable|string|max:500',
            'publish'   => 'sometimes|boolean',
        ]);
        $this->authorizeOutlet($request->user(), (int) $v['outlet_id']);
        if (!empty($v['user_id'])) {
            $this->assertMember((int) $v['user_id'], (int) $v['outlet_id']);
            if ($clash = $this->clash((int) $v['user_id'], $v['date'], $v['starts_at'], $v['ends_at'])) {
                return response()->json(['message' => "That overlaps a shift already on {$clash->date->format('D j M')} {$clash->startsAtCarbon()->format('H:i')}–{$clash->endsAtCarbon()->format('H:i')}."], 422);
            }
        }

        $shift = Shift::create(array_merge($v, [
            'status'     => 'draft',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));
        if ($v['publish'] ?? false) {
            $this->publishShifts(collect([$shift]), $request->user());
        }

        return response()->json(['message' => 'Shift added.', 'shift' => $this->shift($shift->fresh('user'))], 201);
    }

    /** PUT /roster/shifts/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $this->authorizeOutlet($request->user(), $shift->outlet_id);
        abort_if($shift->status === 'cancelled', 422, 'A cancelled shift cannot be edited; add a new one.');

        $v = $request->validate([
            'user_id'   => 'sometimes|nullable|exists:users,id',
            'date'      => 'sometimes|date',
            'starts_at' => 'sometimes|date_format:H:i',
            'ends_at'   => 'sometimes|date_format:H:i',
            'label'     => 'nullable|string|max:60',
            'notes'     => 'nullable|string|max:500',
        ]);
        $userId = array_key_exists('user_id', $v) ? $v['user_id'] : $shift->user_id;
        $date   = $v['date'] ?? $shift->date->toDateString();
        $starts = $v['starts_at'] ?? Shift::hhmm($shift->starts_at);
        $ends   = $v['ends_at'] ?? Shift::hhmm($shift->ends_at);
        abort_if($starts === $ends, 422, 'A shift must end at a different time from when it starts.');
        if ($userId) {
            $this->assertMember((int) $userId, $shift->outlet_id);
            if ($clash = $this->clash((int) $userId, $date, $starts, $ends, $shift->id)) {
                return response()->json(['message' => "That overlaps a shift already on {$clash->date->format('D j M')}."], 422);
            }
        }

        $wasPublishedTo = $shift->status === 'published' ? $shift->user_id : null;
        $shift->fill($v + ['updated_by' => $request->user()->id])->save();

        // Someone already told about this shift is told about the change; a
        // person it moved away from is told it is no longer theirs.
        if ($shift->status === 'published') {
            if ($wasPublishedTo && $wasPublishedTo !== $shift->user_id) {
                $this->notify([$wasPublishedTo], 'A shift was taken off your roster', $this->describe($shift) . ' is no longer yours.', $shift);
            }
            if ($shift->user_id) {
                $this->notify([$shift->user_id], $wasPublishedTo === $shift->user_id ? 'Your shift changed' : 'You have a new shift', $this->describe($shift), $shift);
            }
        }

        return response()->json(['message' => 'Shift saved.', 'shift' => $this->shift($shift->fresh('user'))]);
    }

    /** DELETE /roster/shifts/{id} — a draft is deleted; a published shift is cancelled and the person told. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $this->authorizeOutlet($request->user(), $shift->outlet_id);

        if ($shift->status === 'draft') {
            $shift->delete();
            return response()->json(['message' => 'Draft shift removed.']);
        }
        if ($shift->status === 'published') {
            $shift->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_by' => $request->user()->id]);
            ShiftSwapRequest::where('shift_id', $shift->id)->pending()->update(['status' => 'withdrawn']);
            if ($shift->user_id) {
                $this->notify([$shift->user_id], 'Shift cancelled', $this->describe($shift) . ' has been cancelled.', $shift);
            }
        }

        return response()->json(['message' => 'Shift cancelled.', 'shift' => $this->shift($shift->fresh('user'))]);
    }

    /** POST /roster/publish {outlet_id, week} — every draft in the week goes live; each person is told once. */
    public function publish(Request $request): JsonResponse
    {
        $v = $request->validate(['outlet_id' => 'required|exists:outlets,id', 'week' => 'nullable|date']);
        $this->authorizeOutlet($request->user(), (int) $v['outlet_id']);
        [$start, $end] = $this->weekBounds($v['week'] ?? null);

        $drafts = Shift::where('outlet_id', $v['outlet_id'])->where('status', 'draft')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get();
        $this->publishShifts($drafts, $request->user());

        ActivityLogService::log('roster_published', Outlet::find($v['outlet_id']), [
            'week_start' => $start->toDateString(), 'shifts' => $drafts->count(),
        ], "{$request->user()->first_name} published {$drafts->count()} shift(s) for the week of {$start->format('j M')}");

        return response()->json(['message' => $drafts->count() ? "Published {$drafts->count()} shift(s)." : 'Nothing to publish.', 'published' => $drafts->count()]);
    }

    /** POST /roster/copy-week {outlet_id, from_week, to_week} — last week's shape as this week's drafts. */
    public function copyWeek(Request $request): JsonResponse
    {
        $v = $request->validate(['outlet_id' => 'required|exists:outlets,id', 'from_week' => 'required|date', 'to_week' => 'required|date']);
        $this->authorizeOutlet($request->user(), (int) $v['outlet_id']);
        [$fromStart, $fromEnd] = $this->weekBounds($v['from_week']);
        [$toStart, $toEnd]     = $this->weekBounds($v['to_week']);
        abort_if($fromStart->eq($toStart), 422, 'Pick a different week to copy into.');

        $source = Shift::where('outlet_id', $v['outlet_id'])->live()
            ->whereBetween('date', [$fromStart->toDateString(), $fromEnd->toDateString()])->get();
        $existing = Shift::where('outlet_id', $v['outlet_id'])->live()
            ->whereBetween('date', [$toStart->toDateString(), $toEnd->toDateString()])->get();

        $offset = $fromStart->diffInDays($toStart, false);
        $made = 0;
        foreach ($source as $s) {
            $date = $s->date->copy()->addDays($offset)->toDateString();
            // A person no longer at the outlet becomes an open shift.
            $userId = $s->user_id && $this->isMember($s->user_id, $s->outlet_id) ? $s->user_id : null;
            // Idempotent: the same person (or open slot), day and start already there is skipped.
            $dup = $existing->first(fn ($e) => $e->date->toDateString() === $date && $e->user_id === $userId && Shift::hhmm($e->starts_at) === Shift::hhmm($s->starts_at));
            if ($dup) continue;
            Shift::create([
                'outlet_id' => $s->outlet_id, 'user_id' => $userId, 'date' => $date,
                'starts_at' => Shift::hhmm($s->starts_at), 'ends_at' => Shift::hhmm($s->ends_at),
                'label' => $s->label, 'status' => 'draft', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $made++;
        }

        return response()->json(['message' => "Copied {$made} shift(s) as drafts.", 'copied' => $made]);
    }

    // ── Manager: swaps ──────────────────────────────────────────────────────

    /** GET /roster/swaps?status=pending */
    public function swaps(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => 'nullable|in:pending,approved,declined,withdrawn'])['status'] ?? 'pending';
        $q = ShiftSwapRequest::with(['shift.outlet:id,name', 'fromUser:id,first_name,last_name', 'toUser:id,first_name,last_name'])
            ->where('status', $status)->orderByDesc('created_at');
        if (!$request->user()->can('outlets.all_access')) {
            $ids = DB::table('outlet_user')->where('user_id', $request->user()->id)->pluck('outlet_id');
            $q->whereHas('shift', fn ($s) => $s->whereIn('outlet_id', $ids));
        }

        return response()->json(['data' => $q->limit(100)->get()->map(fn ($r) => $this->swap($r))->values()]);
    }

    /** POST /roster/swaps/{id}/approve  {note?} */
    public function approveSwap(Request $request, int $id): JsonResponse
    {
        $req = ShiftSwapRequest::with('shift')->findOrFail($id);
        $this->authorizeOutlet($request->user(), $req->shift->outlet_id);
        abort_if($req->status !== 'pending', 422, 'This request has already been decided.');
        $note = $request->validate(['note' => 'nullable|string|max:300'])['note'] ?? null;

        $shift = $req->shift;
        if ($req->to_user_id) {
            $this->assertMember($req->to_user_id, $shift->outlet_id);
            if ($this->clash($req->to_user_id, $shift->date->toDateString(), Shift::hhmm($shift->starts_at), Shift::hhmm($shift->ends_at), $shift->id)) {
                return response()->json(['message' => 'The colleague already has a shift at that time.'], 422);
            }
        }

        DB::transaction(function () use ($req, $shift, $request, $note) {
            $shift->update(['user_id' => $req->to_user_id, 'updated_by' => $request->user()->id]);
            $req->update(['status' => 'approved', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $note]);
        });

        $this->notify([$req->from_user_id], 'Swap approved', $this->describe($shift) . ($req->to_user_id ? ' now belongs to your colleague.' : ' is now an open shift.'), $shift);
        if ($req->to_user_id) {
            $this->notify([$req->to_user_id], 'You have a new shift', $this->describe($shift) . ' was handed to you.', $shift);
        }

        return response()->json(['message' => 'Swap approved.', 'request' => $this->swap($req->fresh(['shift', 'fromUser', 'toUser']))]);
    }

    /** POST /roster/swaps/{id}/decline  {note?} */
    public function declineSwap(Request $request, int $id): JsonResponse
    {
        $req = ShiftSwapRequest::with('shift')->findOrFail($id);
        $this->authorizeOutlet($request->user(), $req->shift->outlet_id);
        abort_if($req->status !== 'pending', 422, 'This request has already been decided.');
        $note = $request->validate(['note' => 'nullable|string|max:300'])['note'] ?? null;
        $req->update(['status' => 'declined', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $note]);
        $this->notify([$req->from_user_id], 'Swap declined', $this->describe($req->shift) . ' stays with you.' . ($note ? " {$note}" : ''), $req->shift);

        return response()->json(['message' => 'Swap declined.', 'request' => $this->swap($req->fresh(['shift', 'fromUser', 'toUser']))]);
    }

    // ── Me ──────────────────────────────────────────────────────────────────

    /** GET /me/shifts?from=&to= — my published shifts (default: today + 14 days) and open shifts I could claim. */
    public function mine(Request $request): JsonResponse
    {
        $v = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = isset($v['from']) ? Carbon::parse($v['from']) : now()->startOfDay();
        $to   = isset($v['to']) ? Carbon::parse($v['to']) : $from->copy()->addDays(14);
        $me   = $request->user();

        $mine = Shift::with('outlet:id,name')->where('user_id', $me->id)->published()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')->orderBy('starts_at')->get();
        $myOutlets = DB::table('outlet_user')->where('user_id', $me->id)->pluck('outlet_id');
        $open = Shift::with('outlet:id,name')->whereIn('outlet_id', $myOutlets)->published()->open()
            ->where('date', '>=', now()->toDateString())->orderBy('date')->orderBy('starts_at')->limit(30)->get();
        $pending = ShiftSwapRequest::with('shift.outlet:id,name')->where('from_user_id', $me->id)->pending()->get();

        $today = $mine->first(fn ($s) => $s->date->isToday());
        $next  = $mine->first(fn ($s) => $s->startsAtCarbon()->isFuture());

        return response()->json([
            'shifts'       => $mine->map(fn ($s) => $this->shift($s))->values(),
            'open_shifts'  => $open->map(fn ($s) => $this->shift($s))->values(),
            'pending_swaps'=> $pending->map(fn ($r) => $this->swap($r))->values(),
            'today'        => $today ? $this->shift($today) : null,
            'next'         => $next ? $this->shift($next) : null,
        ]);
    }

    /** POST /me/shifts/{id}/claim — take an open, published shift at my outlet. Race-safe. */
    public function claim(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $me = $request->user();
        abort_if(!$this->isMember($me->id, $shift->outlet_id), 403, 'That shift is at an outlet you do not work at.');
        abort_if($shift->status !== 'published' || $shift->user_id !== null, 422, 'That shift is no longer open.');
        if ($this->clash($me->id, $shift->date->toDateString(), Shift::hhmm($shift->starts_at), Shift::hhmm($shift->ends_at))) {
            return response()->json(['message' => 'You already have a shift at that time.'], 422);
        }

        $taken = Shift::whereKey($shift->id)->whereNull('user_id')->where('status', 'published')
            ->update(['user_id' => $me->id, 'updated_by' => $me->id, 'updated_at' => now()]);
        if (!$taken) {
            return response()->json(['message' => 'Someone claimed that shift just before you.'], 409);
        }
        ActivityLogService::log('shift_claimed', $shift, [], "{$me->first_name} claimed the open shift on {$shift->date->format('D j M')}");

        return response()->json(['message' => 'The shift is yours.', 'shift' => $this->shift($shift->fresh(['user', 'outlet']))]);
    }

    /** POST /me/shifts/{id}/swap  {to_user_id?, note?} — ask the manager to hand my shift on. */
    public function requestSwap(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        $me = $request->user();
        abort_if($shift->user_id !== $me->id, 403, 'That is not your shift.');
        abort_if($shift->status !== 'published', 422, 'Only a published shift can be swapped.');
        abort_if($shift->startsAtCarbon()->isPast(), 422, 'That shift has already started.');
        $v = $request->validate(['to_user_id' => 'nullable|exists:users,id|different:from', 'note' => 'nullable|string|max:300']);
        if (!empty($v['to_user_id'])) {
            abort_if((int) $v['to_user_id'] === $me->id, 422, 'Pick a colleague, not yourself.');
            abort_if(!$this->isMember((int) $v['to_user_id'], $shift->outlet_id), 422, 'That colleague does not work at this outlet.');
        }
        if (ShiftSwapRequest::where('shift_id', $shift->id)->pending()->exists()) {
            return response()->json(['message' => 'A request for this shift is already waiting.'], 422);
        }

        $req = ShiftSwapRequest::create(['shift_id' => $shift->id, 'from_user_id' => $me->id, 'to_user_id' => $v['to_user_id'] ?? null, 'note' => $v['note'] ?? null]);
        $managers = User::whereIn('id', DB::table('outlet_user')->where('outlet_id', $shift->outlet_id)->pluck('user_id'))
            ->where('status', 'active')->get()->filter(fn (User $u) => $u->id !== $me->id && $u->can('roster.manage'));
        if ($managers->isNotEmpty()) {
            NotificationService::deliver($managers, new InAppNotification(
                title: "{$me->first_name} asks to swap a shift",
                body: $this->describe($shift) . ($v['note'] ?? false ? " — {$v['note']}" : ''),
                actionUrl: '/roster?outlet_id=' . $shift->outlet_id . '&week=' . $shift->date->toDateString(),
                icon: 'calendar',
                data: ['swap_request_id' => $req->id],
            ));
        }

        return response()->json(['message' => 'Your request is with the manager.', 'request' => $this->swap($req->fresh(['shift', 'fromUser', 'toUser']))], 201);
    }

    /** DELETE /me/swaps/{id} — withdraw my own pending request. */
    public function withdrawSwap(Request $request, int $id): JsonResponse
    {
        $req = ShiftSwapRequest::findOrFail($id);
        abort_if($req->from_user_id !== $request->user()->id, 403);
        abort_if($req->status !== 'pending', 422, 'This request has already been decided.');
        $req->update(['status' => 'withdrawn']);

        return response()->json(['message' => 'Request withdrawn.']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function weekBounds(?string $any): array
    {
        $start = ($any ? Carbon::parse($any) : now())->startOfWeek(Carbon::MONDAY)->startOfDay();
        return [$start, $start->copy()->addDays(6)];
    }

    private function authorizeOutlet(User $user, int $outletId): void
    {
        if ($user->can('outlets.all_access')) return;
        abort_if(!$this->isMember($user->id, $outletId), 403, 'You do not manage this outlet.');
    }

    private function isMember(int $userId, int $outletId): bool
    {
        return DB::table('outlet_user')->where('user_id', $userId)->where('outlet_id', $outletId)->exists();
    }

    private function assertMember(int $userId, int $outletId): void
    {
        abort_if(!$this->isMember($userId, $outletId), 422, 'That person does not work at this outlet.');
    }

    /** A live shift of this person overlapping the span (overnight aware). */
    private function clash(int $userId, string $date, string $starts, string $ends, ?int $exceptId = null): ?Shift
    {
        $probe = new Shift(['date' => $date, 'starts_at' => $starts, 'ends_at' => $ends]);
        $from = $probe->startsAtCarbon(); $to = $probe->endsAtCarbon();
        $candidates = Shift::where('user_id', $userId)->live()
            ->whereBetween('date', [$from->copy()->subDay()->toDateString(), $to->toDateString()])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->get();
        foreach ($candidates as $c) {
            if ($c->startsAtCarbon()->lt($to) && $c->endsAtCarbon()->gt($from)) return $c;
        }
        return null;
    }

    private function publishShifts($shifts, User $by): void
    {
        if ($shifts->isEmpty()) return;
        Shift::whereIn('id', $shifts->pluck('id'))->update(['status' => 'published', 'published_at' => now(), 'updated_by' => $by->id, 'updated_at' => now()]);
        foreach ($shifts->whereNotNull('user_id')->groupBy('user_id') as $userId => $group) {
            $first = $group->sortBy(fn ($s) => $s->date->toDateString() . Shift::hhmm($s->starts_at))->first();
            $this->notify([$userId], $group->count() === 1 ? 'You have a new shift' : "Your roster: {$group->count()} shifts",
                $group->count() === 1 ? $this->describe($first) : "First one {$this->describe($first)}.", $first);
        }
    }

    private function notify(array $userIds, string $title, string $body, Shift $shift): void
    {
        $users = User::whereIn('id', $userIds)->where('status', 'active')->get();
        if ($users->isEmpty()) return;
        NotificationService::deliver($users, new InAppNotification(
            title: $title, body: $body, actionUrl: '/me/shifts', icon: 'calendar', data: ['shift_id' => $shift->id],
        ));
    }

    private function describe(Shift $shift): string
    {
        $shift->loadMissing('outlet:id,name');
        return $shift->date->format('D j M') . ' ' . $shift->startsAtCarbon()->format('H:i') . '–' . $shift->endsAtCarbon()->format('H:i')
            . ($shift->label ? " ({$shift->label})" : '') . ' at ' . ($shift->outlet?->name ?? 'the outlet');
    }

    private function shift(Shift $s): array
    {
        return [
            'id'        => $s->id,
            'outlet_id' => $s->outlet_id,
            'outlet'    => $s->relationLoaded('outlet') && $s->outlet ? ['id' => $s->outlet->id, 'name' => $s->outlet->name] : null,
            'user_id'   => $s->user_id,
            'user'      => $s->relationLoaded('user') && $s->user ? ['id' => $s->user->id, 'name' => trim("{$s->user->first_name} {$s->user->last_name}")] : null,
            'date'      => $s->date->toDateString(),
            'starts_at' => Shift::hhmm($s->starts_at),
            'ends_at'   => Shift::hhmm($s->ends_at),
            'overnight' => $s->endsAtCarbon()->toDateString() !== $s->date->toDateString(),
            'minutes'   => $s->minutes(),
            'label'     => $s->label,
            'notes'     => $s->notes,
            'status'    => $s->status,
            'published_at' => $s->published_at?->toIso8601String(),
        ];
    }

    private function swap(ShiftSwapRequest $r): array
    {
        return [
            'id'        => $r->id,
            'status'    => $r->status,
            'note'      => $r->note,
            'decision_note' => $r->decision_note,
            'from_user' => $r->fromUser ? ['id' => $r->fromUser->id, 'name' => trim("{$r->fromUser->first_name} {$r->fromUser->last_name}")] : null,
            'to_user'   => $r->toUser ? ['id' => $r->toUser->id, 'name' => trim("{$r->toUser->first_name} {$r->toUser->last_name}")] : null,
            'shift'     => $r->shift ? $this->shift($r->shift) : null,
            'created_at'=> $r->created_at?->toIso8601String(),
            'decided_at'=> $r->decided_at?->toIso8601String(),
        ];
    }
}
