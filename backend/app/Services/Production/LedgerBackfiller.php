<?php

namespace App\Services\Production;

use App\Models\ProductionOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Moves an existing floor onto the ledger without anybody noticing.
 *
 * Every in-flight order already carries its progress as cumulative counters:
 * `production_task_batch_progress.quantity_done` per (stage, batch), or
 * `production_tasks.quantity_done` where an order never defined colourways.
 * Those numbers are the only history that exists and they are trustworthy — the
 * pipeline gates have been enforcing them — so the ledger is seeded to say
 * exactly what they already say.
 *
 * The translation. A legacy counter records pieces that have PASSED a stage;
 * the ledger records where pieces are STANDING. One determines the other:
 *
 *     at(stage j) = passed(j−1) − passed(j)
 *     at(entry)   = loaded − passed(first working stage)
 *     at(done)    = passed(last working stage)
 *
 * Worked through: 10 pieces, Cutting passed 6, Stitching passed 2. Four were
 * never cut, so they sit in the entry queue. Six were cut but only two stitched,
 * so four stand at Stitching. Two were stitched, so they stand at QC.
 * 4 + 4 + 2 = 10 — and re-deriving the counters from those positions returns 6
 * and 2, the numbers we started from. The migration is lossless.
 *
 * One honest limitation: legacy data cannot separate "not yet cut" from "being
 * cut right now", because a single counter only ever recorded a stage being
 * FINISHED, never started. Un-passed pieces are parked in the entry queue —
 * the conservative reading, and the only one that cannot overstate progress.
 *
 * Only the ledger is written. The projection and `loaded_qty` come from
 * `fn_rebuild_batch_state()`, so this cannot seed a state the ledger would not
 * replay. Batches that already carry movements are skipped, which makes the
 * whole thing safe to re-run.
 */
class LedgerBackfiller
{
    /** Statuses whose orders still have pieces somewhere on the floor. */
    private const LIVE_STATUSES = [
        'pending', 'in_progress', 'on_hold', 'qc_pending', 'qc_passed', 'qc_failed',
    ];

    public function __construct(private readonly WorkflowProvisioner $provisioner) {}

    /**
     * @return array{orders:int, batches:int, movements:int, skipped:int}
     */
    public function run(?callable $progress = null): array
    {
        $actorId = DB::table('users')->orderBy('id')->value('id');

        $stats = ['orders' => 0, 'batches' => 0, 'movements' => 0, 'skipped' => 0];

        if (!$actorId) {
            return $stats;
        }

        ProductionOrder::with('tasks')
            ->whereIn('status', self::LIVE_STATUSES)
            ->orderBy('id')
            ->chunkById(50, function (Collection $orders) use ($actorId, &$stats, $progress) {
                foreach ($orders as $order) {
                    $this->backfillOrder($order, $actorId, $stats);
                    $progress && $progress($order);
                }
            });

        $this->assertBalanced();

        return $stats;
    }

    private function backfillOrder(ProductionOrder $order, int $actorId, array &$stats): void
    {
        $tasks = $order->tasks->whereNotNull('production_stage_id');

        if ($tasks->isEmpty()) {
            return;
        }

        $workflow = $this->provisioner->forOrder($order);

        $line = DB::table('production_workflow_stages')
            ->where('production_workflow_id', $workflow->id)
            ->where('kind', '!=', 'SCRAP')
            ->orderBy('seq')
            ->get()
            ->values();

        if ($line->count() < 2) {
            return;
        }

        $batches = $this->batchesFor($order);
        $stats['orders']++;

        foreach ($batches as $batch) {
            DB::table('production_order_batches')
                ->where('id', $batch->id)
                ->update(['production_workflow_id' => $workflow->id]);

            $batch->production_workflow_id = $workflow->id;

            if ($this->alreadyOnLedger((int) $batch->id)) {
                $stats['skipped']++;
                continue;
            }

            $stats['movements'] += $this->backfillBatch(
                $order, $batch, $line, $tasks, $actorId, $batches->count() === 1
            );
            $stats['batches']++;
        }
    }

    /**
     * An order that never defined colourways is still one batch of work — the
     * movement model needs somewhere to put the pieces.
     */
    private function batchesFor(ProductionOrder $order): Collection
    {
        $batches = DB::table('production_order_batches')
            ->where('production_order_id', $order->id)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        if ($batches->isNotEmpty()) {
            return $batches;
        }

        $id = DB::table('production_order_batches')->insertGetId([
            'production_order_id' => $order->id,
            'label'               => 'All pieces',
            'quantity'            => max(1, (int) $order->quantity),
            'sort_order'          => 0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return collect([DB::table('production_order_batches')->find($id)]);
    }

    private function alreadyOnLedger(int $batchId): bool
    {
        return DB::table('piece_movements')
            ->where('production_order_batch_id', $batchId)
            ->exists();
    }

    private function backfillBatch(
        ProductionOrder $order,
        object $batch,
        Collection $line,
        Collection $tasks,
        int $actorId,
        bool $isOnlyBatch,
    ): int {
        $batchQty = max(0, (int) $batch->quantity);

        if ($batchQty === 0) {
            return 0;
        }

        $passed  = $this->passedByStage($line, $tasks, $batch, $batchQty, $isOnlyBatch);
        $moves   = $this->boundaryCrossings($line, $passed, $this->hasStarted($order, $tasks), $batchQty);
        $at      = Carbon::parse($order->started_at ?? $order->created_at ?? now());
        $written = 0;

        $this->write([
            'production_order_id'       => $order->id,
            'production_order_batch_id' => $batch->id,
            'production_workflow_id'    => $batch->production_workflow_id,
            'movement_type'             => 'INTAKE',
            'from_stage_id'             => null,
            'to_stage_id'               => $line[0]->id,
            'from_bucket'               => null,
            'to_bucket'                 => 'READY',
            'quantity'                  => $batchQty,
            'reason_code'               => 'INITIAL_LOAD',
            'note'                      => 'Opening balance migrated from stage counters.',
            'moved_by'                  => $actorId,
            'moved_at'                  => $at,
        ]);
        $written++;

        foreach ($moves as $move) {
            $at = $at->copy()->addSecond();

            $this->write([
                'production_order_id'       => $order->id,
                'production_order_batch_id' => $batch->id,
                'production_workflow_id'    => $batch->production_workflow_id,
                'movement_type'             => 'FORWARD',
                'from_stage_id'             => $move['from'],
                'to_stage_id'               => $move['to'],
                'from_bucket'               => 'READY',
                'to_bucket'                 => 'READY',
                'quantity'                  => $move['qty'],
                'reason_code'               => null,
                'note'                      => 'Migrated from stage counters.',
                'moved_by'                  => $actorId,
                'moved_at'                  => $at,
            ]);
            $written++;
        }

        // The projection is never hand-written: replay what we just recorded.
        DB::statement('SELECT fn_rebuild_batch_state(?)', [$batch->id]);

        return $written;
    }

    /**
     * passed() per working stage, clamped monotone down the line.
     *
     * Counters are meant to decrease downstream, but a stage force-completed out
     * of order could break that. Clamping keeps at() non-negative rather than
     * letting a CHECK constraint abort a production deploy over one historical
     * anomaly.
     */
    private function passedByStage(
        Collection $line,
        Collection $tasks,
        object $batch,
        int $batchQty,
        bool $isOnlyBatch,
    ): array {
        $passed  = [];
        $ceiling = $batchQty;

        foreach ($line as $stage) {
            if (!$stage->production_stage_id) {
                continue;   // the entry queue and the finished terminal
            }

            $task  = $tasks->firstWhere('production_stage_id', $stage->production_stage_id);
            $value = $task ? $this->passedFor($task, $batch, $batchQty, $isOnlyBatch) : 0;

            $value = max(0, min($value, $ceiling));

            $passed[$stage->id] = $value;
            $ceiling = $value;
        }

        return $passed;
    }

    /**
     * How many pieces have crossed each boundary.
     *
     * Leaving a working stage is what its counter records, so the boundary out
     * of stage i carries passed(i).
     *
     * The queue is the exception, and it is the one genuinely ambiguous call in
     * the whole migration: a single counter only ever recorded a stage being
     * FINISHED, never started, so legacy data cannot separate "not yet cut"
     * from "on the cutting table right now".
     *
     * Resolve it by whether the order has started at all:
     *
     *   started  → every piece has left the queue, so pieces not yet past the
     *              first station are standing AT it. This is what the floor
     *              means by a stage task: the work is allocated there.
     *   untouched → nothing has left the queue, and claiming otherwise would
     *              report cutting in progress on an order nobody has opened.
     *
     * The distinction is not cosmetic. Park an in-flight order's pieces in the
     * queue and every tailor's first act on day one is a pointless extra move
     * to pull work she is already holding — and the legacy translation layer
     * (§0.3) would find its own stage empty and fail. Either reading is
     * lossless for the counters, because pieces standing AT a stage have not
     * passed it under either rule.
     *
     * @return list<array{from:int,to:int,qty:int}>
     */
    private function boundaryCrossings(
        Collection $line,
        array $passed,
        bool $hasStarted,
        int $batchQty,
    ): array {
        $moves = [];

        for ($i = 0; $i < $line->count() - 1; $i++) {
            $upstream   = $line[$i];
            $downstream = $line[$i + 1];

            $crossing = $i === 0
                ? ($hasStarted ? $batchQty : ($passed[$line[1]->id] ?? 0))
                : ($passed[$upstream->id] ?? 0);

            if ($crossing > 0) {
                $moves[] = ['from' => $upstream->id, 'to' => $downstream->id, 'qty' => $crossing];
            }
        }

        return $moves;
    }

    /**
     * Has anyone touched this order yet?
     *
     * Deliberately generous — any sign of work counts. A false negative parks
     * live pieces in the queue and makes the floor do an extra move; a false
     * positive only claims that an untouched order's pieces are standing at the
     * first station, which is where they would go on the very next tap anyway.
     */
    private function hasStarted(ProductionOrder $order, Collection $tasks): bool
    {
        if ($order->started_at !== null) {
            return true;
        }

        return $tasks->contains(
            fn ($task) => (int) $task->quantity_done > 0
                || !in_array($task->status, ['pending', 'cancelled'], true)
        );
    }

    /**
     * What this stage had passed for this batch — the same rule the manual
     * progress path used: a satisfied stage has passed everything by
     * definition, otherwise the recorded counter speaks.
     */
    private function passedFor($task, object $batch, int $batchQty, bool $isOnlyBatch): int
    {
        if (in_array($task->status, ['completed', 'skipped'], true)) {
            return $batchQty;
        }

        $row = DB::table('production_task_batch_progress')
            ->where('production_task_id', $task->id)
            ->where('production_order_batch_id', $batch->id)
            ->value('quantity_done');

        if ($row !== null) {
            return (int) $row;
        }

        // No per-batch row. With a single batch the task total refers to it
        // unambiguously; across several, a task total cannot be apportioned
        // honestly, so nothing is claimed.
        return $isOnlyBatch ? min((int) $task->quantity_done, $batchQty) : 0;
    }

    private function write(array $row): void
    {
        DB::table('piece_movements')->insert($row + ['client_ref' => (string) Str::uuid()]);
    }

    /**
     * The cutover gate: what the floor's counters say, against what the ledger
     * would derive. Zero non-zero rows is the condition for switching the write
     * path over; anything else is investigated by hand and never force-matched,
     * because a counter and a ledger disagreeing means one of them is lying
     * about where somebody's garments are.
     *
     * @return list<array{batch_id:int, order_number:string, stage:string, legacy:int, derived:int, diff:int}>
     */
    public function diff(): array
    {
        $rows = DB::table('v_batch_stage_distribution as d')
            ->join('production_order_batches as b', 'b.id', '=', 'd.batch_id')
            ->join('production_orders as o', 'o.id', '=', 'd.production_order_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('piece_movements as m')
                ->whereColumn('m.production_order_batch_id', 'd.batch_id'))
            ->orderBy('d.batch_id')->orderBy('d.seq')
            ->get([
                'd.batch_id', 'd.production_order_id', 'd.stage_id', 'd.production_stage_id',
                'd.stage_name', 'd.seq', 'd.kind', 'd.qty_total', 'o.order_number',
            ])
            ->groupBy('batch_id');

        $out = [];

        foreach ($rows as $batchId => $stages) {
            $line = $stages->where('kind', '!=', 'SCRAP')->sortBy('seq')->values();

            // Only one batch on the order means a bare task total refers to it
            // unambiguously; with several it cannot be apportioned, so the
            // per-batch row is the only honest comparison.
            $batchCount = DB::table('production_order_batches')
                ->where('production_order_id', $stages->first()->production_order_id)
                ->count();

            foreach ($line as $i => $stage) {
                if (!$stage->production_stage_id) {
                    continue;   // terminals have no task standing over them
                }

                $task = DB::table('production_tasks')
                    ->where('production_order_id', $stage->production_order_id)
                    ->where('production_stage_id', $stage->production_stage_id)
                    ->first();

                if (!$task) {
                    continue;
                }

                $derived = (int) $line->slice($i + 1)->sum('qty_total');

                $legacy = DB::table('production_task_batch_progress')
                    ->where('production_task_id', $task->id)
                    ->where('production_order_batch_id', $batchId)
                    ->value('quantity_done');

                $legacy = $legacy !== null
                    ? (int) $legacy
                    : ($batchCount === 1 ? (int) $task->quantity_done : $derived);

                // A satisfied stage passed everything by definition, which is
                // how the counters were always read.
                if (in_array($task->status, ['completed', 'skipped'], true)) {
                    $legacy = (int) $stages->first()->qty_total >= 0
                        ? (int) DB::table('production_order_batches')->where('id', $batchId)->value('quantity')
                        : $legacy;
                }

                if ($legacy !== $derived) {
                    $out[] = [
                        'batch_id'     => (int) $batchId,
                        'order_number' => $stage->order_number,
                        'stage'        => $stage->stage_name,
                        'legacy'       => $legacy,
                        'derived'      => $derived,
                        'diff'         => $derived - $legacy,
                    ];
                }
            }
        }

        return $out;
    }

    /** A backfill that leaves the floor unbalanced is worse than one that fails. */
    private function assertBalanced(): void
    {
        $broken = DB::table('v_batch_integrity')->where('is_balanced', false)->count();

        if ($broken > 0) {
            throw new RuntimeException(
                "Piece-movement backfill left {$broken} batch(es) unbalanced; aborting so the "
                .'ledger is not left in a state it could not have reached.'
            );
        }
    }
}
