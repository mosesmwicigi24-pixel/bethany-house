<?php

namespace App\Support;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderAssignee;
use App\Models\ProductionTask;
use Illuminate\Support\Facades\DB;

/**
 * Everyone who MADE a production order — the people its quality check must
 * come from someone other than (owner's Policy 4, MakerChecker).
 *
 * Owner decision 5 Oct 2026: not only whoever holds a stage now, but anyone
 * who ever held one or counted pieces on it, plus the order's own assignees.
 * Before, a tailor moved off a stage she had sewn (by reassignment or by a
 * rework send-back), or an order-level assignee, could pass the order's QC.
 *
 * Floor work is read from the activity-log events the stage endpoints write.
 * Those endpoints accept the stage's assignee and anyone who manages
 * assignments, so a manager who counted pieces on a tailor's behalf counts
 * as a maker too — they did the counting. A manager who only assigned or
 * reassigned a stage did no work on it and is not counted.
 */
final class OrderMakers
{
    /** Events whose causer worked on the task named in properties.task_id. */
    private const WORK_EVENTS = ['production_task_progress', 'production_task_status_updated'];

    /** @return list<int> */
    public static function ids(ProductionOrder $order): array
    {
        $tasks = ProductionTask::withoutViewerScope()
            ->where('production_order_id', $order->id)
            ->get(['id', 'assigned_to']);
        $taskIds = $tasks->pluck('id')->map(fn ($id) => (string) $id)->all();

        $ids = $tasks->pluck('assigned_to')
            ->merge(ProductionOrderAssignee::where('production_order_id', $order->id)->pluck('user_id'));

        // Counted pieces on, started, paused or finished one of its stages.
        // Those events are filed under the order (an indexed subject), so this
        // never scans the whole log.
        $ids = $ids->merge(DB::table('activity_log')
            ->where('subject_type', ProductionOrder::class)
            ->where('subject_id', $order->id)
            ->whereIn('event', self::WORK_EVENTS)
            ->whereNotNull('causer_id')
            ->pluck('causer_id'));

        if ($taskIds !== []) {
            // Held a stage before it was reassigned to someone else. This event
            // has no subject; `action` (always equal to event) is indexed.
            $ids = $ids->merge(DB::table('activity_log')
                ->where('action', 'production_task_reassigned')
                ->whereIn(DB::raw("properties->>'task_id'"), $taskIds)
                ->selectRaw("properties->>'previous_assignee_id' as previous_id")
                ->pluck('previous_id'));

            // The audit trail (since 21 Sep 2026) records every change to a
            // stage, whichever screen made it, including the task edit form,
            // which writes no event of its own. A previous holder is any
            // changes.assigned_to.old; a counter is whoever RAISED
            // quantity_done. Lowering it is not making: that is the QC
            // manager's rework send-back, and they must still be able to
            // inspect the redo.
            $audit = DB::table('activity_log')
                ->where('subject_type', ProductionTask::class)
                ->whereIn('subject_id', $tasks->pluck('id'))
                ->where('event', 'updated')
                ->get(['causer_id', 'properties']);
            foreach ($audit as $row) {
                $changes = (json_decode((string) $row->properties, true) ?: [])['changes'] ?? [];
                $ids->push($changes['assigned_to']['old'] ?? null);
                $qd = $changes['quantity_done'] ?? null;
                if ($qd && (int) ($qd['new'] ?? 0) > (int) ($qd['old'] ?? 0)) {
                    $ids->push($row->causer_id);
                }
            }
        }

        // Held a stage through an earlier assignment of the order, or before a
        // rework send-back gave it to someone else.
        $orderEvents = DB::table('activity_log')
            ->whereIn('event', ['production_assigned', 'production_rework'])
            ->where('subject_type', ProductionOrder::class)
            ->where('subject_id', $order->id)
            ->get(['event', 'properties']);

        foreach ($orderEvents as $e) {
            $p = json_decode((string) $e->properties, true) ?: [];
            $ids = $e->event === 'production_assigned'
                ? $ids->merge(array_column($p['assignments'] ?? [], 'tailor_id'))
                : $ids->merge(array_map(fn ($s) => $s['before']['assigned_to'] ?? null, $p['stages'] ?? []));
        }

        return $ids->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
