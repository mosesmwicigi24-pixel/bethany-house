<?php

namespace App\Services\Production;

use App\Models\MovementReason;
use App\Models\PieceMovement;
use App\Models\ProductionOrderBatch;
use App\Models\ProductionTask;
use App\Models\ProductionWorkflowStage;
use Illuminate\Support\Facades\DB;

/**
 * Where the movement ledger meets the task system.
 *
 * The two answer different questions and both are worth keeping:
 *
 *     Who should work on what next?   → tasks
 *     Where are the hundred pieces?   → movements
 *
 * The task system keeps everything it already does — one card per production
 * order, customer resolution, priority, assignment, the sequence gate. It loses
 * exactly one responsibility: counting.
 *
 * The reference spec deprecates `task.quantity_done` and asks the UI to render
 * progress from stage state instead, with a release of dual-writing and a diff
 * to prove they agree. That migration is right in spirit and wrong for this
 * codebase in one specific way: `quantity_done` is not merely displayed here.
 * It is load-bearing — `ProductionTask::effectivePassed()` and `blockingTask()`
 * gate whether a stage may start at all, `recordBatchProgress` enforces the
 * pipeline ceiling and floor with it, completion percentages derive from it,
 * and the React admin reads it on several screens. Orphaning it would freeze
 * the floor; dual-writing it would invite exactly the drift the ledger exists
 * to abolish.
 *
 * So it is neither orphaned nor dual-written: it becomes a **derived
 * projection**, recomputed from the ledger inside the same transaction as the
 * movement that changed it. Nobody types it, it cannot disagree with the
 * ledger, and every existing consumer keeps working untouched.
 *
 * The derivation is exact rather than approximate:
 *
 *     passed(stage S) = Σ pieces now standing at every LINE stage after S
 *
 * A piece at Finishing has, by construction, passed Cutting and Sewing. Scrap
 * is excluded because scrapped pieces have left the line — counting them as
 * "passed" would report work still standing on a garment that no longer exists.
 * The pieces are not lost from the books: they sit in the SCRAP stage, and the
 * shortfall figure on the order card is what surfaces them.
 */
class TaskBridge
{
    /**
     * Re-derive everything the task system shows for this batch, and flag or
     * clear holds. Runs inside the movement's transaction, so the counters can
     * never be observed disagreeing with the ledger.
     */
    public function onMovementApplied(
        PieceMovement $movement,
        ProductionOrderBatch $batch,
        WorkflowGraph $graph,
    ): void {
        $totals = $this->stageTotals($batch->id);
        $line   = $graph->line();

        $order = $batch->productionOrder()->first();
        if (!$order) {
            return;
        }

        // Tasks for this order, keyed by the legacy stage they stand over.
        $tasks = ProductionTask::where('production_order_id', $order->id)
            ->get()
            ->keyBy('production_stage_id');

        if ($tasks->isEmpty()) {
            return;
        }

        $orderQty  = max(1, (int) $order->quantity);
        $touched   = [];
        $cumulative = 0;

        // Walk the line from the far end backwards: the running total of
        // everything downstream is precisely what each stage has passed.
        foreach ($line->reverse() as $stage) {
            /** @var ProductionWorkflowStage $stage */
            $legacyId = $stage->production_stage_id;
            $task     = $legacyId ? $tasks->get($legacyId) : null;

            if ($task) {
                $this->writeBatchProgress($task, $batch, $cumulative);
                $this->applyHoldFlag($task, $stage, $totals, $movement);
                $touched[$task->id] = $task;
            }

            $cumulative += $totals[$stage->id]['total'] ?? 0;
        }

        foreach ($touched as $task) {
            $this->resyncTaskTotal($task, $orderQty);
        }

        $this->resyncOrderLifecycle($order, $orderQty);
    }

    /**
     * Current occupancy per stage for one batch. Stages with no row yet are
     * simply absent and read as zero.
     */
    private function stageTotals(int $batchId): array
    {
        return DB::table('batch_stage_states')
            ->where('production_order_batch_id', $batchId)
            ->get()
            ->mapWithKeys(fn ($r) => [
                (int) $r->production_workflow_stage_id => [
                    'ready' => (int) $r->qty_ready,
                    'held'  => (int) $r->qty_held,
                    'total' => (int) $r->qty_ready + (int) $r->qty_held,
                ],
            ])
            ->all();
    }

    /**
     * The per-(task, batch) counter the batch screens read. Written, never
     * typed — the value is whatever the ledger says has passed this stage.
     */
    private function writeBatchProgress(ProductionTask $task, ProductionOrderBatch $batch, int $passed): void
    {
        DB::table('production_task_batch_progress')->upsert(
            [[
                'production_task_id'         => $task->id,
                'production_order_batch_id'  => $batch->id,
                'quantity_done'              => $passed,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]],
            ['production_task_id', 'production_order_batch_id'],
            ['quantity_done', 'updated_at'],
        );
    }

    /**
     * The task total is the sum across batches — the same rule the manual path
     * used, so nothing downstream can tell the difference.
     *
     * Status follows the count, including downwards: rework and scrap can pull
     * a completed stage back under its order quantity, and a stage that no
     * longer has all its pieces through is genuinely not finished. The old
     * manual path forbade that only because a monotone counter could not
     * express it.
     */
    private function resyncTaskTotal(ProductionTask $task, int $orderQty): void
    {
        $sum = (int) DB::table('production_task_batch_progress')
            ->where('production_task_id', $task->id)
            ->sum('quantity_done');

        $update = ['quantity_done' => $sum];

        if ($sum > 0 && in_array($task->status, ['pending', 'paused'], true)) {
            $update['status']     = 'in_progress';
            $update['started_at'] = $task->started_at ?? now();
        }

        if ($sum >= $orderQty && $task->status !== 'completed') {
            $update['status']       = 'completed';
            $update['completed_at'] = now();
        }

        if ($sum < $orderQty && $task->status === 'completed') {
            $update['status']       = 'in_progress';
            $update['completed_at'] = null;
        }

        $task->forceFill($update)->save();
    }

    /**
     * BLOCK / UNBLOCK. Held pieces are stopped for a reason somebody has to
     * resolve, and the task card is where that person will be looking.
     */
    private function applyHoldFlag(
        ProductionTask $task,
        ProductionWorkflowStage $stage,
        array $totals,
        PieceMovement $movement,
    ): void {
        $held = $totals[$stage->id]['held'] ?? 0;

        if ($held > 0 && !$task->blocked_reason) {
            $task->forceFill([
                'blocked_reason' => $this->reasonLabel($movement) ?? 'Work stopped at this stage',
                'blocked_at'     => now(),
            ])->save();

            return;
        }

        if ($held === 0 && $task->blocked_reason) {
            $task->forceFill(['blocked_reason' => null, 'blocked_at' => null])->save();
        }
    }

    private function reasonLabel(PieceMovement $movement): ?string
    {
        if (!$movement->reason_code) {
            return null;
        }

        return MovementReason::find($movement->reason_code)?->label;
    }

    /**
     * Order-level lifecycle, matching the behaviour the manual progress path
     * already had. Deliberately conservative: the movement system does not
     * decide that an order is finished — QC and the existing completion gate
     * still do — it only reports that work has started, and that every stage
     * has now passed everything.
     */
    private function resyncOrderLifecycle($order, int $orderQty): void
    {
        $anyProgress = ProductionTask::where('production_order_id', $order->id)
            ->where('quantity_done', '>', 0)
            ->exists();

        if ($anyProgress && !$order->started_at) {
            $order->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();
        }

        $allDone = !ProductionTask::where('production_order_id', $order->id)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($allDone && in_array($order->status, ['pending', 'in_progress'], true)) {
            $order->forceFill(['status' => 'qc_pending'])->save();
        }
    }
}
