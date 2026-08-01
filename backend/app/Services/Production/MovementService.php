<?php

namespace App\Services\Production;

use App\Models\PieceMovement;
use App\Models\ProductionOrderBatch;
use App\Models\ProductionWorkflow;
use App\Models\ProductionWorkflowStage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The quantity-transfer engine. Every physical piece movement on the floor goes
 * through here, and nothing else is permitted to write `batch_stage_states`.
 *
 * Five guarantees, each structural rather than conventional:
 *
 *  1. Conservation   — the sum of every stage bucket always equals the batch's
 *                      loaded_qty, because every movement is one debit and one
 *                      credit in a single transaction. There is no code path
 *                      that credits a stage without debiting another.
 *  2. Exclusivity    — a piece is in exactly one (stage, bucket) at a time.
 *  3. Idempotency    — replaying a client_ref returns the original movement.
 *  4. Serialisability— the debit is a conditional UPDATE guarded by `qty >= n`.
 *                      Two tailors both moving 8 of 10 pieces: the first matches
 *                      one row, the second matches zero and fails cleanly. No
 *                      read-then-write gap, no lost update, no negative stage.
 *  5. Auditability   — nothing is mutated or deleted; an undo posts an inverse.
 *
 * A note on time. Every write in one movement — the ledger row and both sides
 * of the transfer — is stamped with a single timestamp taken once, rather than
 * each statement calling now(). That is what lets `fn_rebuild_batch_state`,
 * which can only see `moved_at`, reconstruct byte-identical `entered_at` and
 * `last_out_at` values. Without it "replay must equal the projection" would be
 * approximately true, which is not a property worth having.
 */
class MovementService
{
    /**
     * Who may do what.
     *
     * The reference design hard-coded role names. This system already has a
     * permission catalogue with roles configured on top, so movement types map
     * to permissions instead: the same intent — ordinary floor staff move work
     * forward and can stop it, only a supervisor sends work backwards, writes it
     * off, releases a hold or reverses history — without a second, parallel
     * authorisation model that would drift from the first.
     */
    public const PERMISSIONS = [
        PieceMovement::TYPE_INTAKE   => 'production.load_pieces',
        PieceMovement::TYPE_FORWARD  => 'production.move_pieces',
        PieceMovement::TYPE_HOLD     => 'production.move_pieces',
        PieceMovement::TYPE_REWORK   => 'production.rework_pieces',
        PieceMovement::TYPE_RELEASE  => 'production.rework_pieces',
        PieceMovement::TYPE_SCRAP    => 'production.scrap_pieces',
        PieceMovement::TYPE_REVERSAL => 'production.reverse_movement',
    ];

    public function __construct(
        private readonly TaskBridge $bridge,
        private readonly WorkflowProvisioner $provisioner,
    ) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Move pieces between stages or buckets. The only write path for FORWARD,
     * REWORK, SCRAP, HOLD and RELEASE.
     *
     * @throws MovementException
     */
    public function apply(MoveCommand $cmd): PieceMovement
    {
        $this->assertQuantity($cmd->quantity);
        $this->assertAuthorised($cmd->actor, $cmd->type);

        // Fast path: this ref was already applied, so there is nothing to do.
        if ($existing = $this->findByClientRef($cmd->clientRef)) {
            return $existing;
        }

        return $this->idempotently($cmd->clientRef, fn () => DB::transaction(function () use ($cmd) {
            $batch = $this->lockBatch($cmd->batchId);
            $graph = $this->graphFor($batch);
            $at    = Carbon::now();

            $from = $graph->find($cmd->fromStageId)
                ?? throw new MovementException(
                    MovementException::INVALID_TRANSITION,
                    'That stage does not belong to this batch\'s workflow.',
                );

            // Terminals are refused before a destination is looked for, so
            // "Finished is the end of the line" wins over "no next stage".
            $graph->assertOriginMovable($cmd->type, $from);

            $to = $this->resolveDestination($graph, $cmd, $from);

            $graph->assertTransition($cmd->type, $from, $to, $cmd->reasonCode);

            [$fromBucket, $toBucket] = $this->bucketsFor($cmd->type);

            $this->transfer(
                batch: $batch,
                fromStage: $from, fromBucket: $fromBucket,
                toStage: $to,     toBucket: $toBucket,
                quantity: $cmd->quantity,
                at: $at,
            );

            $movement = $this->record([
                'client_ref'               => $cmd->clientRef,
                'production_order_id'      => $batch->production_order_id,
                'production_order_batch_id'=> $batch->id,
                'production_workflow_id'   => $batch->production_workflow_id,
                'movement_type'            => $cmd->type,
                'from_stage_id'            => $from->id,
                'to_stage_id'              => $to->id,
                'from_bucket'              => $fromBucket,
                'to_bucket'                => $toBucket,
                'quantity'                 => $cmd->quantity,
                'reason_code'              => $cmd->reasonCode,
                'note'                     => $cmd->note,
                'moved_by'                 => $cmd->actor->id,
                'moved_at'                 => $at,
                'device_id'                => $cmd->deviceId,
            ]);

            $this->bridge->onMovementApplied($movement, $batch, $graph);

            return $movement;
        }));
    }

    /**
     * Introduce pieces into a batch: the initial cut load, or replacements for
     * pieces that were scrapped. The only movement with no origin — the pieces
     * come from outside the line.
     *
     * @throws MovementException
     */
    public function intake(
        string $clientRef,
        int $batchId,
        int $quantity,
        User $actor,
        ?string $reasonCode = null,
        ?string $note = null,
        ?string $deviceId = null,
    ): PieceMovement {
        $this->assertQuantity($quantity);
        $this->assertAuthorised($actor, PieceMovement::TYPE_INTAKE);

        if ($existing = $this->findByClientRef($clientRef)) {
            return $existing;
        }

        return $this->idempotently($clientRef, fn () => DB::transaction(function () use (
            $clientRef, $batchId, $quantity, $actor, $reasonCode, $note, $deviceId
        ) {
            $batch = $this->lockBatch($batchId);
            $this->ensureWorkflowPinned($batch);
            $graph = $this->graphFor($batch);
            $at    = Carbon::now();

            $entry = $graph->queueStage() ?? throw new MovementException(
                MovementException::INVALID_TRANSITION,
                'This workflow has no entry queue, so pieces have nowhere to enter.',
            );

            $this->credit($batch->id, $entry->id, PieceMovement::BUCKET_READY, $quantity, $at);
            $this->adjustLoaded($batch, $quantity);

            $movement = $this->record([
                'client_ref'               => $clientRef,
                'production_order_id'      => $batch->production_order_id,
                'production_order_batch_id'=> $batch->id,
                'production_workflow_id'   => $batch->production_workflow_id,
                'movement_type'            => PieceMovement::TYPE_INTAKE,
                'from_stage_id'            => null,
                'to_stage_id'              => $entry->id,
                'from_bucket'              => null,
                'to_bucket'                => PieceMovement::BUCKET_READY,
                'quantity'                 => $quantity,
                'reason_code'              => $reasonCode,
                'note'                     => $note,
                'moved_by'                 => $actor->id,
                'moved_at'                 => $at,
                'device_id'                => $deviceId,
            ]);

            $this->bridge->onMovementApplied($movement, $batch, $graph);

            return $movement;
        }));
    }

    /**
     * Undo. Never deletes — posts a compensating inverse so the history shows
     * both the mistake and the correction, which is exactly what you want when
     * a tailor logs 40 instead of 4 and the manager asks what happened.
     *
     * A movement can only be reversed while its pieces are still standing where
     * it put them. Once they have moved on, the correction has to be made
     * forward — which is also how it works in the real world.
     *
     * @throws MovementException
     */
    public function reverse(
        string $clientRef,
        int $movementId,
        User $actor,
        ?string $note = null,
        ?string $deviceId = null,
    ): PieceMovement {
        $this->assertAuthorised($actor, PieceMovement::TYPE_REVERSAL);

        if ($existing = $this->findByClientRef($clientRef)) {
            return $existing;
        }

        return $this->idempotently($clientRef, fn () => DB::transaction(function () use (
            $clientRef, $movementId, $actor, $note, $deviceId
        ) {
            $original = PieceMovement::lockForUpdate()->find($movementId)
                ?? throw new MovementException(
                    MovementException::MOVEMENT_NOT_FOUND,
                    'That movement no longer exists.',
                );

            if ($original->movement_type === PieceMovement::TYPE_REVERSAL) {
                throw new MovementException(
                    MovementException::INVALID_TRANSITION,
                    'A reversal cannot itself be reversed. Post the movement again instead.',
                );
            }

            if (PieceMovement::where('reverses_id', $movementId)->exists()) {
                throw new MovementException(
                    MovementException::ALREADY_REVERSED,
                    'This movement was already reversed.',
                );
            }

            $batch = $this->lockBatch($original->production_order_batch_id);
            $graph = $this->graphFor($batch);
            $at    = Carbon::now();

            if ($original->movement_type === PieceMovement::TYPE_INTAKE) {
                // Un-load: a one-sided debit, the exact mirror of the intake.
                // Pieces leave the line entirely, so loaded_qty comes back down
                // with them and conservation still holds.
                $this->debit(
                    $batch->id,
                    (int) $original->to_stage_id,
                    (string) $original->to_bucket,
                    (int) $original->quantity,
                    $at,
                    $graph->find($original->to_stage_id)?->name ?? 'The entry queue',
                    'Those pieces have already moved down the line and cannot be un-loaded.',
                );
                $this->adjustLoaded($batch, -(int) $original->quantity);

                $inverse = [
                    'from_stage_id' => $original->to_stage_id,
                    'to_stage_id'   => null,
                    'from_bucket'   => $original->to_bucket,
                    'to_bucket'     => null,
                ];
            } else {
                $this->transfer(
                    batch: $batch,
                    fromStage: $graph->find($original->to_stage_id),
                    fromBucket: (string) $original->to_bucket,
                    toStage: $graph->find($original->from_stage_id),
                    toBucket: (string) $original->from_bucket,
                    quantity: (int) $original->quantity,
                    at: $at,
                    shortfallMessage: 'Those pieces have already moved on, so this movement can no longer be reversed.',
                );

                $inverse = [
                    'from_stage_id' => $original->to_stage_id,
                    'to_stage_id'   => $original->from_stage_id,
                    'from_bucket'   => $original->to_bucket,
                    'to_bucket'     => $original->from_bucket,
                ];
            }

            $movement = $this->record($inverse + [
                'client_ref'               => $clientRef,
                'production_order_id'      => $original->production_order_id,
                'production_order_batch_id'=> $original->production_order_batch_id,
                'production_workflow_id'   => $original->production_workflow_id,
                'movement_type'            => PieceMovement::TYPE_REVERSAL,
                'quantity'                 => $original->quantity,
                // The reason travels with the reversal so the defect Pareto does
                // not keep counting a defect that was retracted.
                'reason_code'              => $original->reason_code,
                'note'                     => $note ?? 'Reversal',
                'moved_by'                 => $actor->id,
                'moved_at'                 => $at,
                'device_id'                => $deviceId,
                'reverses_id'              => $original->id,
            ]);

            $this->bridge->onMovementApplied($movement, $batch, $graph);

            return $movement;
        }));
    }

    /** Throw away the projection for a batch and replay the ledger over it. */
    public function rebuild(int $batchId): void
    {
        DB::statement('SELECT fn_rebuild_batch_state(?)', [$batchId]);
    }

    public function graphForBatch(ProductionOrderBatch $batch): WorkflowGraph
    {
        return $this->graphFor($batch);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The two-row transfer: one stage loses exactly what another gains.
     *
     * Rows are touched in a deterministic order (lower stage id first) so a
     * FORWARD and a concurrent REWORK across the same pair of stages cannot
     * deadlock each other by grabbing them in opposite orders.
     */
    private function transfer(
        ProductionOrderBatch $batch,
        ?ProductionWorkflowStage $fromStage,
        string $fromBucket,
        ?ProductionWorkflowStage $toStage,
        string $toBucket,
        int $quantity,
        Carbon $at,
        ?string $shortfallMessage = null,
    ): void {
        if (!$fromStage || !$toStage) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                'This movement references a stage that is not on the batch\'s workflow.',
            );
        }

        $debit = fn () => $this->debit(
            $batch->id, $fromStage->id, $fromBucket, $quantity, $at,
            $fromStage->name, $shortfallMessage,
        );
        $credit = fn () => $this->credit($batch->id, $toStage->id, $toBucket, $quantity, $at);

        if ($fromStage->id <= $toStage->id) {
            $debit();
            $credit();
        } else {
            $credit();
            $debit();
        }
    }

    /**
     * The guarded decrement. `WHERE qty >= n` is the whole concurrency story:
     * if the pieces are not there, zero rows match and we fail cleanly rather
     * than driving a stage negative.
     */
    private function debit(
        int $batchId,
        int $stageId,
        string $bucket,
        int $quantity,
        Carbon $at,
        string $stageName,
        ?string $message = null,
    ): void {
        $col = $this->column($bucket);

        $affected = DB::affectingStatement(
            "UPDATE batch_stage_states
                SET {$col} = {$col} - ?, last_out_at = ?, version = version + 1
              WHERE production_order_batch_id = ?
                AND production_workflow_stage_id = ?
                AND {$col} >= ?",
            [$quantity, $at, $batchId, $stageId, $quantity],
        );

        if ($affected === 0) {
            throw new MovementException(
                MovementException::INSUFFICIENT_QUANTITY,
                $message ?? "{$stageName} does not have {$quantity} piece(s) available to move.",
                ['stage_id' => $stageId, 'requested' => $quantity],
            );
        }
    }

    /**
     * The credit. `entered_at` is only reset when the stage was empty before —
     * a stage that already had work carries on aging from when the work first
     * arrived, which is what makes the STALLED alert mean anything.
     */
    private function credit(int $batchId, int $stageId, string $bucket, int $quantity, Carbon $at): void
    {
        $col = $this->column($bucket);

        DB::statement(
            "INSERT INTO batch_stage_states
                 (production_order_batch_id, production_workflow_stage_id, {$col},
                  entered_at, last_in_at, version)
             VALUES (?, ?, ?, ?, ?, 1)
             ON CONFLICT (production_order_batch_id, production_workflow_stage_id) DO UPDATE
                SET {$col}     = batch_stage_states.{$col} + EXCLUDED.{$col},
                    last_in_at = EXCLUDED.last_in_at,
                    entered_at = CASE
                                   WHEN batch_stage_states.qty_ready + batch_stage_states.qty_held = 0
                                   THEN EXCLUDED.entered_at
                                   ELSE batch_stage_states.entered_at
                                 END,
                    version    = batch_stage_states.version + 1",
            [$batchId, $stageId, $quantity, $at, $at],
        );
    }

    /** Only ever 'qty_ready' or 'qty_held'; never anything a caller supplied. */
    private function column(string $bucket): string
    {
        return match ($bucket) {
            PieceMovement::BUCKET_READY => 'qty_ready',
            PieceMovement::BUCKET_HELD  => 'qty_held',
            default => throw new MovementException(
                MovementException::INVALID_TRANSITION,
                "'{$bucket}' is not a bucket.",
            ),
        };
    }

    /** HOLD stops work in place; RELEASE restarts it. Everything else is READY→READY. */
    private function bucketsFor(string $type): array
    {
        return match ($type) {
            PieceMovement::TYPE_HOLD    => [PieceMovement::BUCKET_READY, PieceMovement::BUCKET_HELD],
            PieceMovement::TYPE_RELEASE => [PieceMovement::BUCKET_HELD,  PieceMovement::BUCKET_READY],
            default                     => [PieceMovement::BUCKET_READY, PieceMovement::BUCKET_READY],
        };
    }

    private function resolveDestination(
        WorkflowGraph $graph,
        MoveCommand $cmd,
        ProductionWorkflowStage $from,
    ): ProductionWorkflowStage {
        $to = match (true) {
            $cmd->toStageId !== null              => $graph->find($cmd->toStageId),
            $cmd->type === PieceMovement::TYPE_FORWARD => $graph->nextStage($from->id),
            $cmd->type === PieceMovement::TYPE_SCRAP   => $graph->scrapStage(),
            default                               => $from,   // hold and release stay put
        };

        return $to ?? throw new MovementException(
            MovementException::NO_NEXT_STAGE,
            $cmd->type === PieceMovement::TYPE_FORWARD
                ? "{$from->name} has no next stage."
                : 'No destination stage could be resolved for this movement.',
        );
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new MovementException(
                MovementException::BAD_QUANTITY,
                'Quantity must be a whole number above zero.',
            );
        }
    }

    private function assertAuthorised(User $actor, string $type): void
    {
        $permission = self::PERMISSIONS[$type] ?? null;

        if (!$permission || !$actor->can($permission)) {
            throw new MovementException(
                MovementException::NOT_AUTHORISED,
                'Your role cannot perform '.strtolower($type).' movements.',
                ['required_permission' => $permission],
            );
        }
    }

    private function findByClientRef(string $clientRef): ?PieceMovement
    {
        if (!Str::isUuid($clientRef)) {
            throw new MovementException(
                MovementException::BAD_QUANTITY,
                'The movement reference must be a UUID generated on the device.',
            );
        }

        return PieceMovement::where('client_ref', $clientRef)->first();
    }

    /**
     * Idempotency under genuine concurrency.
     *
     * The pre-flight lookup catches the ordinary retry. It cannot catch two
     * copies of the same request arriving together — both read "not applied",
     * both proceed, and the unique index on client_ref decides the winner. The
     * loser's transaction is already aborted by then, so the re-read has to
     * happen out here, after the rollback. Without this, a double-tap on a weak
     * connection surfaces as a 500 instead of the movement the worker expected.
     */
    private function idempotently(string $clientRef, callable $work): PieceMovement
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if ($this->isDuplicateClientRef($e)) {
                if ($existing = PieceMovement::where('client_ref', $clientRef)->first()) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function isDuplicateClientRef(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            && str_contains($e->getMessage(), 'client_ref');
    }

    private function lockBatch(int $batchId): ProductionOrderBatch
    {
        $batch = ProductionOrderBatch::lockForUpdate()->find($batchId);

        if (!$batch) {
            throw new MovementException(MovementException::BATCH_NOT_FOUND, 'Batch not found.');
        }

        return $batch;
    }

    /**
     * A batch created before the ledger existed — or by the batch editor, which
     * does not ask — has no workflow. Pinning the default on first intake means
     * the floor never meets a batch it cannot start, and the pin is permanent
     * from that moment on.
     */
    private function ensureWorkflowPinned(ProductionOrderBatch $batch): void
    {
        if ($batch->production_workflow_id) {
            return;
        }

        $order = $batch->productionOrder()->first();

        // The line comes from what this order actually runs — its own tasks —
        // rather than from a single global default, because products already
        // carry different stage templates. Pinning happens once, on first
        // intake, and is permanent from that moment: re-sequencing the template
        // later mints a new workflow and leaves this batch on the one it started.
        $workflow = $order
            ? $this->provisioner->forOrder($order)
            : ProductionWorkflow::default();

        if (!$workflow) {
            throw new MovementException(
                MovementException::WORKFLOW_NOT_PINNED,
                'No production workflow is configured, so there is no line to load pieces onto.',
            );
        }

        $batch->forceFill(['production_workflow_id' => $workflow->id])->save();
    }

    private function graphFor(ProductionOrderBatch $batch): WorkflowGraph
    {
        if (!$batch->production_workflow_id) {
            throw new MovementException(
                MovementException::WORKFLOW_NOT_PINNED,
                'This batch has no workflow pinned yet. Load its pieces first.',
            );
        }

        return new WorkflowGraph(
            ProductionWorkflowStage::where('production_workflow_id', $batch->production_workflow_id)
                ->orderBy('seq')
                ->get()
        );
    }

    private function adjustLoaded(ProductionOrderBatch $batch, int $delta): void
    {
        DB::affectingStatement(
            'UPDATE production_order_batches SET loaded_qty = loaded_qty + ? WHERE id = ?',
            [$delta, $batch->id],
        );

        $batch->loaded_qty = (int) $batch->loaded_qty + $delta;
    }

    private function record(array $attributes): PieceMovement
    {
        return PieceMovement::create($attributes);
    }
}
