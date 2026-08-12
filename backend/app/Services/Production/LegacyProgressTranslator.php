<?php

namespace App\Services\Production;

use App\Models\PieceMovement;
use App\Models\ProductionOrderBatch;
use App\Models\ProductionTask;
use App\Models\ProductionWorkflowStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The bridge that lets the old screen keep working while the ledger is the truth.
 *
 * The legacy tailor endpoint posts an absolute count — *"Cutting has now passed
 * 11"*. The ledger records transfers. Running both against one batch is the
 * single thing that can silently corrupt live data: the old path writes
 * `quantity_done` without a movement, the ledger goes stale, and the next real
 * movement recomputes the counter from that stale ledger and wipes the tailor's
 * afternoon.
 *
 * The fix is to translate rather than refuse. A legacy call on a ledger-backed
 * batch becomes the movement it always meant:
 *
 *     delta = new_done − passed_now
 *     delta > 0  → FORWARD delta out of the task's own stage
 *     delta = 0  → nothing happened; say so and write nothing
 *     delta < 0  → 409 REQUIRES_REVERSAL
 *
 * A decrement is refused on purpose. Lowering a counter used to mean "I typed
 * the wrong number", but pieces that have moved on are physically at the next
 * bench, and no amount of arithmetic brings them back — that is a supervisor
 * reversal, which the ledger records honestly, rather than an edit that quietly
 * rewrites where garments are.
 *
 * **Idempotency without a device.** Old clients have no `client_ref`, and a
 * retried POST on a bad connection would otherwise move the pieces twice. The
 * ref is therefore derived from the call itself —
 * `uuid5(task:batch:old:new)` — so the same legacy request always names the
 * same movement, and a replay returns the original instead of moving anything.
 * Two genuinely different taps produce different refs because `old` differs.
 *
 * **Pulling work forward.** `quantity_done = 5` on Cutting asserts that five
 * pieces finished cutting, which also asserts that five *started* it. On an
 * order nobody has opened yet those five are still in the entry queue, so a
 * bare FORWARD out of Cutting would fail on a stage holding nothing. The
 * translator walks upstream and brings the shortfall down first — the movements
 * the floor performed but the old model had no way to record.
 */
class LegacyProgressTranslator
{
    /** Stable namespace so a ref derived today matches one derived next year. */
    private const NAMESPACE = '6f9619ff-8b86-d011-b42d-00c04fc964ff';

    public function __construct(private readonly MovementService $movements) {}

    /** Has this batch been migrated onto the ledger? */
    public function isOnLedger(int $batchId): bool
    {
        return DB::table('piece_movements')
            ->where('production_order_batch_id', $batchId)
            ->exists();
    }

    /**
     * Convert an absolute counter write into the movements it describes.
     *
     * @return array{moved:int, from:string, to:?string, movements:list<PieceMovement>}
     *
     * @throws MovementException
     */
    public function translate(
        ProductionTask $task,
        ProductionOrderBatch $batch,
        int $newDone,
        User $actor,
        ?string $deviceId = null,
    ): array {
        $graph = $this->movements->graphForBatch($batch);
        $stage = $this->stageFor($graph, $task);

        $line   = $graph->line();
        $index  = $line->search(fn ($s) => $s->id === $stage->id);
        $passed = $this->passedAt($batch->id, $line, $index);
        $delta  = $newDone - $passed;

        if ($delta === 0) {
            return ['moved' => 0, 'from' => $stage->name, 'to' => null, 'movements' => []];
        }

        if ($delta < 0) {
            throw new MovementException(
                MovementException::REQUIRES_REVERSAL,
                "{$stage->name} has already passed {$passed} piece(s). Lowering that is a "
                    .'correction, not progress — ask a supervisor to reverse the movement.',
                ['stage_id' => $stage->id, 'passed' => $passed, 'requested' => $newDone],
            );
        }

        $next = $graph->nextStage($stage->id);

        if (!$next) {
            throw new MovementException(
                MovementException::NO_NEXT_STAGE,
                "{$stage->name} is the end of the line.",
            );
        }

        $ref = fn (string $suffix) => Uuid::uuid5(
            self::NAMESPACE,
            "legacy:{$task->id}:{$batch->id}:{$passed}:{$newDone}:{$suffix}",
        )->toString();

        $movements = $this->pullForward($batch, $graph, $line, $index, $delta, $actor, $deviceId, $ref);

        $movements[] = $this->movements->applyPreAuthorised(new MoveCommand(
            clientRef: $ref('fwd'),
            batchId: $batch->id,
            fromStageId: $stage->id,
            quantity: $delta,
            type: PieceMovement::TYPE_FORWARD,
            actor: $actor,
            note: 'Recorded on the stage counter.',
            deviceId: $deviceId,
        ));

        return [
            'moved'     => $delta,
            'from'      => $stage->name,
            'to'        => $next->name,
            'movements' => $movements,
        ];
    }

    /**
     * Bring `$needed` pieces down to the stage that is about to hand them on.
     *
     * Only ever moves what is genuinely upstream and workable, nearest bench
     * first, so the ledger ends up describing the route the garments actually
     * took rather than teleporting them. If the line simply does not hold that
     * many pieces, the guarded debit refuses and the whole call rolls back.
     *
     * @return list<PieceMovement>
     */
    private function pullForward(
        ProductionOrderBatch $batch,
        WorkflowGraph $graph,
        $line,
        int $targetIndex,
        int $needed,
        User $actor,
        ?string $deviceId,
        callable $ref,
    ): array {
        $shortfall = $needed - $this->readyAt($batch->id, $line[$targetIndex]->id);

        if ($shortfall <= 0) {
            return [];
        }

        $posted = [];

        for ($i = $targetIndex - 1; $i >= 0 && $shortfall > 0; $i--) {
            $take = min($shortfall, $this->readyAt($batch->id, $line[$i]->id));

            if ($take <= 0) {
                continue;
            }

            // Walk the pieces down one bench at a time — no stage is skipped.
            for ($j = $i; $j < $targetIndex; $j++) {
                $posted[] = $this->movements->applyPreAuthorised(new MoveCommand(
                    clientRef: $ref("pull:{$i}:{$j}"),
                    batchId: $batch->id,
                    fromStageId: $line[$j]->id,
                    quantity: $take,
                    type: PieceMovement::TYPE_FORWARD,
                    actor: $actor,
                    note: 'Implied by the stage counter — work had already begun.',
                    deviceId: $deviceId,
                ));
            }

            $shortfall -= $take;
        }

        return $posted;
    }

    /** Pieces standing at a stage and workable. */
    private function readyAt(int $batchId, int $stageId): int
    {
        return (int) DB::table('batch_stage_states')
            ->where('production_order_batch_id', $batchId)
            ->where('production_workflow_stage_id', $stageId)
            ->value('qty_ready');
    }

    /**
     * What this stage has passed: everything standing downstream of it on the
     * line. The same derivation the task mirror uses, read straight from the
     * projection so the translator can never disagree with it.
     */
    private function passedAt(int $batchId, $line, int $index): int
    {
        $downstream = $line->slice($index + 1)->pluck('id')->all();

        if (!$downstream) {
            return 0;
        }

        return (int) DB::table('batch_stage_states')
            ->where('production_order_batch_id', $batchId)
            ->whereIn('production_workflow_stage_id', $downstream)
            ->sum(DB::raw('qty_ready + qty_held'));
    }

    private function stageFor(WorkflowGraph $graph, ProductionTask $task): ProductionWorkflowStage
    {
        $stage = $graph->all()
            ->firstWhere('production_stage_id', $task->production_stage_id);

        if (!$stage) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                'This task\'s stage is not part of the line its batch is running.',
                ['production_stage_id' => $task->production_stage_id],
            );
        }

        return $stage;
    }
}
