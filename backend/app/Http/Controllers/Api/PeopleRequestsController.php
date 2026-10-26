<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\ShiftSwapRequest;
use App\Models\StaffAdvance;
use App\Models\TimesheetFixRequest;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\People\Leave;
use App\Support\AttendanceAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One Requests queue (People C1): everything waiting on the signed-in person
 * — leave to decide, shift swaps, advances, timesheet fixes — plus the
 * timesheet "Request a fix" flow for staff.
 */
class PeopleRequestsController extends Controller
{
    /** GET /people/requests — attendance.view_team. Each list holds only what this person may decide. */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $all = $u->can('outlets.all_access');
        $myOutlets = DB::table('outlet_user')->where('user_id', $u->id)->pluck('outlet_id');
        $inScope = fn ($q, $col = 'user_id') => $all ? $q : $q->whereIn($col, DB::table('outlet_user')->whereIn('outlet_id', $myOutlets)->pluck('user_id'));

        $leave = LeaveRequest::with(['user:id,first_name,last_name', 'type:id,name'])->where('status', 'pending')->orderBy('starts_on')->get()
            ->filter(fn ($r) => $r->user && Leave::mayDecide($u, $r->user))
            ->map(fn ($r) => ['kind' => 'leave', 'id' => $r->id, 'person' => $this->name($r->user), 'summary' => ($r->type?->name ?? 'Leave') . " · {$r->starts_on->format('j M')} – {$r->ends_on->format('j M')} · {$r->days} day(s)", 'note' => $r->reason ?? null, 'asked_at' => $r->created_at?->toIso8601String(), 'href' => '/leave'])->values();

        $swaps = $u->can('roster.manage')
            ? $inScope(ShiftSwapRequest::with(['shift.outlet:id,name', 'fromUser:id,first_name,last_name', 'toUser:id,first_name,last_name'])->where('status', 'pending'), 'from_user_id')->orderBy('created_at')->get()
                ->map(fn ($s) => ['kind' => 'swap', 'id' => $s->id, 'person' => $this->name($s->fromUser), 'summary' => 'Swap ' . ($s->shift ? $s->shift->date->format('D j M') . ' ' . \App\Models\Shift::hhmm($s->shift->starts_at) : 'shift') . ($s->toUser ? ' with ' . $this->name($s->toUser) : ' (open)'), 'note' => $s->note, 'asked_at' => $s->created_at?->toIso8601String(), 'href' => '/roster'])->values()
            : collect();

        $advances = $u->can('payroll.run')
            ? StaffAdvance::with('user:id,first_name,last_name')->where('status', 'requested')->orderBy('requested_at')->get()
                ->map(fn ($a) => ['kind' => 'advance', 'id' => $a->id, 'person' => $this->name($a->user), 'summary' => ucfirst($a->kind) . ' · KES ' . number_format((float) $a->amount, 2), 'note' => $a->reason, 'asked_at' => $a->requested_at?->toIso8601String(), 'href' => '/payroll?tab=advances'])->values()
            : collect();

        $fixes = $u->can('attendance.manage')
            ? $inScope(TimesheetFixRequest::with('user:id,first_name,last_name')->where('status', 'requested'))->orderBy('created_at')->get()
                ->map(fn ($f) => ['kind' => 'fix', 'id' => $f->id, 'person' => $this->name($f->user), 'summary' => 'Hours on ' . $f->date->format('D j M'), 'note' => $f->message, 'asked_at' => $f->created_at?->toIso8601String(), 'href' => '/settings/attendance?user=' . $f->user_id . '&date=' . $f->date->toDateString()])->values()
            : collect();

        $items = $leave->concat($swaps)->concat($advances)->concat($fixes)->sortBy('asked_at')->values();

        return response()->json(['data' => $items, 'count' => $items->count(), 'counts' => ['leave' => $leave->count(), 'swap' => $swaps->count(), 'advance' => $advances->count(), 'fix' => $fixes->count()]]);
    }

    /** GET /people/requests/count — for the sidebar badge. */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->index($request)->getData()->count]);
    }

    // ── Timesheet fix requests ──

    /** GET /me/timesheet/fixes */
    public function myFixes(Request $request): JsonResponse
    {
        return response()->json(['data' => TimesheetFixRequest::with('decidedBy:id,first_name,last_name')->where('user_id', $request->user()->id)->orderByDesc('created_at')->limit(30)->get()->map(fn ($f) => $this->fix($f))->values()]);
    }

    /** POST /me/timesheet/fixes {date, message} */
    public function storeFix(Request $request): JsonResponse
    {
        $v = $request->validate(['date' => 'required|date|before_or_equal:today', 'message' => 'required|string|max:300']);
        $u = $request->user();
        if (TimesheetFixRequest::where('user_id', $u->id)->where('date', $v['date'])->where('status', 'requested')->exists()) {
            return response()->json(['message' => 'You already asked about that day; your manager has it.'], 422);
        }
        $f = TimesheetFixRequest::create($v + ['user_id' => $u->id, 'status' => 'requested']);
        ActivityLogService::log('timesheet_fix_requested', $f, ['date' => $v['date']], "{$u->first_name} asked for a fix to {$v['date']}");
        NotificationService::deliver(AttendanceAlerts::managersFor($u), new InAppNotification(title: "{$u->first_name}: fix my hours on " . $f->date->format('D j M'), body: $v['message'], actionUrl: '/people/requests', icon: 'clock', data: ['fix_id' => $f->id]));

        return response()->json(['message' => 'Sent to your manager.', 'fix' => $this->fix($f)], 201);
    }

    /** DELETE /me/timesheet/fixes/{id} */
    public function cancelFix(Request $request, int $id): JsonResponse
    {
        $f = TimesheetFixRequest::where('user_id', $request->user()->id)->findOrFail($id);
        abort_if($f->status !== 'requested', 422, 'Already decided.');
        $f->delete();
        return response()->json(['message' => 'Withdrawn.']);
    }

    /** POST /people/requests/fixes/{id}/approve|decline {note?} — attendance.manage. Approving means "I corrected it"; the entry edit is the manager's, in Attendance. */
    public function decideFix(Request $request, int $id, string $decision): JsonResponse
    {
        abort_unless(in_array($decision, ['approve', 'decline'], true), 404);
        $f = TimesheetFixRequest::with('user')->findOrFail($id);
        abort_if($f->status !== 'requested', 422, 'Already decided.');
        $v = $request->validate(['note' => ($decision === 'decline' ? 'required' : 'nullable') . '|string|max:300']);
        $f->update(['status' => $decision === 'approve' ? 'approved' : 'declined', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $v['note'] ?? null]);
        ActivityLogService::log("timesheet_fix_{$f->status}", $f, [], "{$request->user()->first_name} {$f->status} {$f->user?->first_name}'s fix for {$f->date->toDateString()}");
        if ($f->user) NotificationService::deliver($f->user, new InAppNotification(title: 'Hours on ' . $f->date->format('D j M') . ($f->status === 'approved' ? ' corrected' : ' not changed'), body: $v['note'] ?? 'Your manager has corrected the day.', actionUrl: '/me/timesheet', icon: 'clock'));

        return response()->json(['message' => ucfirst($f->status) . '.', 'fix' => $this->fix($f)]);
    }

    private function fix(TimesheetFixRequest $f): array
    {
        return ['id' => $f->id, 'date' => $f->date->toDateString(), 'message' => $f->message, 'status' => $f->status, 'decided_at' => $f->decided_at?->toIso8601String(), 'decision_note' => $f->decision_note,
            'decided_by' => $f->relationLoaded('decidedBy') && $f->decidedBy ? $this->name($f->decidedBy) : null, 'created_at' => $f->created_at?->toIso8601String()];
    }

    private function name(?User $u): string
    {
        return $u ? trim("{$u->first_name} {$u->last_name}") : '';
    }
}
