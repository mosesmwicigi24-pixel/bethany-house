<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\PayGroup;
use App\Models\PayPeriod;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\AttendanceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** People › Overview (C1): the team's day on one screen, scoped to the viewer's outlets. */
class PeopleOverviewController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $u = $request->user();
        $all = $u->can('outlets.all_access');
        $outletIds = $all ? null : DB::table('outlet_user')->where('user_id', $u->id)->pluck('outlet_id');
        $staffIds = User::where('user_type', 'staff')->where('status', 'active')
            ->when($outletIds, fn ($q) => $q->whereIn('id', DB::table('outlet_user')->whereIn('outlet_id', $outletIds)->pluck('user_id')))->pluck('id');
        $now = now(); $today = $now->toDateString();

        $active = TimeEntry::with(['user:id,first_name,last_name,avatar_url', 'outlet:id,name'])->active()->whereIn('user_id', $staffIds)->get();
        $todayEntries = TimeEntry::whereIn('user_id', $staffIds)->whereDate('clock_in_at', $today)->get()->groupBy('user_id');
        $shifts = Shift::with(['user:id,first_name,last_name,avatar_url', 'outlet:id,name'])->published()->whereIn('user_id', $staffIds)->where('date', $today)->get();
        $onLeave = LeaveRequest::with(['user:id,first_name,last_name,avatar_url', 'type:id,name'])->approved()->whereIn('user_id', $staffIds)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->get();

        $person = fn ($x) => $x ? ['id' => $x->id, 'name' => trim("{$x->first_name} {$x->last_name}"), 'avatar_url' => $x->avatar_url] : null;
        $in = $active->map(fn ($e) => ['person' => $person($e->user), 'since' => $e->clock_in_at->toIso8601String(), 'on_break' => $e->hasOpenBreak(), 'flags' => $e->flags ?? [], 'outlet' => $e->outlet?->name, 'left_site_at' => $e->left_site_at?->toIso8601String(), 'entry_id' => $e->id])->values();
        $late = []; $absent = []; $expected = [];
        foreach ($shifts as $s) {
            if (!$s->user) continue;
            $starts = $s->startsAtCarbon(); $ends = $s->endsAtCarbon();
            $hasEntry = $todayEntries->has($s->user_id) || $active->contains('user_id', $s->user_id);
            $row = ['person' => $person($s->user), 'shift' => Shift::hhmm($s->starts_at) . '–' . Shift::hhmm($s->ends_at), 'outlet' => $s->outlet?->name, 'shift_id' => $s->id];
            if ($hasEntry) continue;
            if ($onLeave->contains('user_id', $s->user_id)) continue;
            if ($now->gte($ends)) $absent[] = $row;
            elseif ($now->gte($starts->copy()->addMinutes(15))) $late[] = $row + ['minutes_late' => (int) $starts->diffInMinutes($now)];
            elseif ($now->lt($starts)) $expected[] = $row;
        }

        $weekStart = $now->copy()->startOfWeek(); $weekEnd = $weekStart->copy()->addDays(6);
        $open = Shift::published()->whereNull('user_id')->when($outletIds, fn ($q) => $q->whereIn('outlet_id', $outletIds))->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])->count();
        $flaggedOpen = TimeEntry::whereIn('user_id', $staffIds)->where('status', 'flagged')->where('clock_in_at', '>=', $now->copy()->subDays(14))->count();

        $group = PayGroup::defaultGroup();
        $period = $group ? $group->periodFor($now) : null;
        $periodInfo = $period ? ['id' => $period->id, 'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString(), 'status' => $period->status, 'days_left' => (int) max(0, $now->startOfDay()->diffInDays($period->ends_on, false)),
            'approved' => $period->timesheets()->where('status', 'approved')->count(), 'submitted' => $period->timesheets()->where('status', 'submitted')->count(), 'people' => $staffIds->count()] : null;

        $requests = app(PeopleRequestsController::class)->index($request)->getData(true);

        return response()->json([
            'now' => ['in' => $in, 'in_count' => $in->count(), 'on_break' => $in->where('on_break', true)->count(), 'late' => $late, 'absent' => $absent, 'expected' => $expected,
                'on_leave' => $onLeave->map(fn ($l) => ['person' => $person($l->user), 'type' => $l->type?->name, 'until' => $l->ends_on->toDateString()])->values(), 'people' => $staffIds->count()],
            'week' => ['open_shifts' => $open, 'flagged_punches' => $flaggedOpen],
            'period' => $periodInfo,
            'requests' => ['count' => $requests['count'], 'counts' => $requests['counts'], 'items' => array_slice($requests['data'], 0, 8)],
            'rules' => ['location_mode' => AttendanceRules::get('location_mode'), 'clock_in_without_shift' => AttendanceRules::get('clock_in_without_shift')],
            'server_time' => $now->toIso8601String(),
        ]);
    }
}
