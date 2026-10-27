<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * To-dos (People C5): "do this by then" for a person, outside production's
 * task flow. Anyone writes their own; a manager (attendance.view_team)
 * gives one to someone in their outlets and sees the team's. The person is
 * told; the giver hears when it is done.
 */
class TodosController extends Controller
{
    /** GET /todos?scope=mine|given|team&status=open|done|all */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $v = $request->validate(['scope' => ['nullable', Rule::in(['mine', 'given', 'team'])], 'status' => ['nullable', Rule::in(['open', 'done', 'all'])]]);
        $scope = $v['scope'] ?? 'mine';
        $q = Todo::with(['user:id,first_name,last_name', 'creator:id,first_name,last_name', 'outlet:id,name']);
        if ($scope === 'mine') $q->where('user_id', $me->id);
        elseif ($scope === 'given') $q->where('created_by', $me->id)->where('user_id', '!=', $me->id);
        else { abort_unless($me->can('attendance.view_team'), 403); $q->whereIn('user_id', $this->teamIds($me)); }
        $status = $v['status'] ?? 'open';
        if ($status === 'open') $q->where('status', 'open')->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderBy('id');
        elseif ($status === 'done') $q->where('status', 'done')->orderByDesc('done_at')->limit(50);
        else $q->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id')->limit(200);
        $rows = $q->get()->map(fn (Todo $t) => $this->present($t, $me))->values();
        $open = Todo::where('user_id', $me->id)->where('status', 'open')->count();
        $overdue = Todo::where('user_id', $me->id)->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<', now())->count();
        return response()->json(['data' => $rows, 'open' => $open, 'overdue' => $overdue, 'can_give' => $me->can('attendance.view_team')]);
    }

    /** POST /todos {title, details?, due_at?, user_id?} */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $v = $request->validate(['title' => 'required|string|max:140', 'details' => 'nullable|string|max:2000', 'due_at' => 'nullable|date', 'user_id' => 'nullable|integer|exists:users,id']);
        $for = !empty($v['user_id']) && (int) $v['user_id'] !== $me->id ? User::findOrFail($v['user_id']) : $me;
        if ($for->id !== $me->id) {
            abort_unless($me->can('attendance.view_team'), 403, 'Giving a to-do to someone else needs the team permission.');
            abort_unless(in_array($for->id, $this->teamIds($me), true), 403, 'Not in your outlets.');
        }
        $outletId = DB::table('outlet_user')->where('user_id', $for->id)->where('is_primary', true)->value('outlet_id') ?? DB::table('outlet_user')->where('user_id', $for->id)->value('outlet_id');
        $t = Todo::create(['user_id' => $for->id, 'created_by' => $me->id, 'outlet_id' => $outletId, 'title' => $v['title'], 'details' => $v['details'] ?? null, 'due_at' => $v['due_at'] ?? null, 'status' => 'open']);
        if ($for->id !== $me->id) {
            ActivityLogService::log('todo_given', $t, ['to' => $for->id, 'due_at' => $t->due_at?->toIso8601String()], "{$me->first_name} gave {$for->first_name} a to-do: {$t->title}");
            NotificationService::deliver($for, new InAppNotification(title: "To do: {$t->title}", body: ($t->due_at ? 'By ' . $t->due_at->format('D j M H:i') . '. ' : '') . "From {$me->first_name}." . ($t->details ? ' ' . \Illuminate\Support\Str::limit($t->details, 100) : ''), actionUrl: '/todos', icon: 'clipboard', data: ['todo_id' => $t->id]));
        }
        return response()->json(['message' => $for->id === $me->id ? 'Added.' : "Given to {$for->first_name}.", 'todo' => $this->present($t->fresh(['user', 'creator', 'outlet']), $me)], 201);
    }

    /** PATCH /todos/{id} {status?: done|open|cancelled, done_note?, title?, details?, due_at?} */
    public function update(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $t = Todo::with(['user', 'creator'])->findOrFail($id);
        $mine = $t->user_id === $me->id; $gave = $t->created_by === $me->id;
        abort_unless($mine || $gave || ($me->can('attendance.view_team') && in_array($t->user_id, $this->teamIds($me), true)), 403);
        $v = $request->validate(['status' => ['sometimes', Rule::in(['done', 'open', 'cancelled'])], 'done_note' => 'nullable|string|max:1000', 'title' => 'sometimes|string|max:140', 'details' => 'sometimes|nullable|string|max:2000', 'due_at' => 'sometimes|nullable|date']);
        if (isset($v['status'])) {
            if ($v['status'] === 'cancelled') abort_unless($gave || !$mine || $t->created_by === null || $t->created_by === $me->id, 403, 'Only whoever gave it can cancel it.');
            $t->status = $v['status'];
            $t->done_at = $v['status'] === 'done' ? now() : null;
            $t->done_note = $v['status'] === 'done' ? ($v['done_note'] ?? null) : null;
        }
        foreach (['title', 'details', 'due_at'] as $k) if (array_key_exists($k, $v) && ($gave || ($mine && $t->created_by === $me->id))) $t->{$k} = $v[$k];
        $t->save();
        if (($v['status'] ?? null) === 'done' && $t->creator && $t->created_by !== $me->id && $t->creator->status === 'active') {
            NotificationService::deliver($t->creator, new InAppNotification(title: "{$me->first_name} did: {$t->title}", body: $t->done_note ?: 'Done.', actionUrl: '/todos?scope=given', icon: 'clipboard', data: ['todo_id' => $t->id]));
        }
        if (($v['status'] ?? null) === 'cancelled' && $t->user && $t->user_id !== $me->id) {
            NotificationService::deliver($t->user, new InAppNotification(title: "No longer needed: {$t->title}", body: "{$me->first_name} cancelled it.", actionUrl: '/todos', icon: 'clipboard', data: ['todo_id' => $t->id]));
        }
        return response()->json(['message' => match ($v['status'] ?? null) { 'done' => 'Done.', 'open' => 'Reopened.', 'cancelled' => 'Cancelled.', default => 'Saved.' }, 'todo' => $this->present($t->fresh(['user', 'creator', 'outlet']), $me)]);
    }

    /** DELETE /todos/{id} — own, never given to anyone else, or the giver's. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $t = Todo::findOrFail($id);
        abort_unless($t->created_by === $me->id || ($t->user_id === $me->id && ($t->created_by === null || $t->created_by === $me->id)), 403);
        $t->delete();
        return response()->json(['message' => 'Removed.']);
    }

    /** GET /todos/people — who I may give a to-do to. */
    public function people(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->can('attendance.view_team'), 403);
        return response()->json(['data' => User::whereIn('id', $this->teamIds($me))->where('id', '!=', $me->id)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])->map(fn ($u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])->values()]);
    }

    private function teamIds(User $me): array
    {
        $q = User::where('status', 'active')->where('user_type', 'staff');
        if (!$me->can('outlets.all_access')) $q->whereIn('id', DB::table('outlet_user')->whereIn('outlet_id', DB::table('outlet_user')->where('user_id', $me->id)->pluck('outlet_id'))->pluck('user_id'));
        return $q->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function present(Todo $t, User $me): array
    {
        return ['id' => $t->id, 'title' => $t->title, 'details' => $t->details, 'status' => $t->status, 'due_at' => $t->due_at?->toIso8601String(), 'overdue' => $t->status === 'open' && $t->due_at && $t->due_at->isPast(),
            'done_at' => $t->done_at?->toIso8601String(), 'done_note' => $t->done_note, 'created_at' => $t->created_at?->toIso8601String(),
            'user' => $t->user ? ['id' => $t->user->id, 'name' => trim("{$t->user->first_name} {$t->user->last_name}")] : null,
            'creator' => $t->creator ? ['id' => $t->creator->id, 'name' => trim("{$t->creator->first_name} {$t->creator->last_name}")] : null,
            'outlet' => $t->outlet ? ['id' => $t->outlet->id, 'name' => $t->outlet->name] : null, 'mine' => $t->user_id === $me->id, 'gave' => $t->created_by === $me->id];
    }
}
