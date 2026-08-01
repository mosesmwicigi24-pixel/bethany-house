<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MovementReason;
use App\Models\PieceMovement;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatch;
use App\Services\Production\MoveCommand;
use App\Services\Production\MovementException;
use App\Services\Production\MovementService;
use App\Services\Production\ProductionReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where the floor talks to the ledger.
 *
 * Writes are deliberately thin — validation, then MovementService, then the
 * refreshed distribution. Every refusal comes back as a coded 4xx rather than a
 * 500, because a worker being told "Cutting only has 6" is a normal Tuesday,
 * not an outage; the client switches on the code to decide between showing the
 * message and rolling back an optimistic update.
 *
 * Every write echoes the batch's new distribution, so the screen that just
 * moved pieces never has to make a second round trip to find out what happened
 * — which matters on a workshop connection.
 */
class PieceMovementController extends Controller
{
    public function __construct(
        private readonly MovementService $movements,
        private readonly ProductionReadModel $read,
    ) {}

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** The whole picture for one order: batches, stages, headline, alerts. */
    public function order(int $orderId): JsonResponse
    {
        $order   = ProductionOrder::findOrFail($orderId);
        $batches = $this->read->batchesForOrder($order->id);

        return response()->json([
            'order' => [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'status'       => $order->status,
                'quantity'     => (int) $order->quantity,
                'due_date'     => $order->due_date,
            ],
            'snapshot' => $this->read->snapshotOfOrder($batches),
            'batches'  => $batches->map(fn ($b) => $b + [
                'snapshot' => $this->read->snapshot($b['stages'], $b['ordered_qty']),
                'chips'    => $this->read->distributionChips($b['stages']),
                'alerts'   => $this->read->stageAlerts($b['stages']),
            ])->values(),
        ]);
    }

    /** What a worker standing at one stage may do right now. */
    public function moveSheet(int $batchId, int $stageId): JsonResponse
    {
        $batch  = ProductionOrderBatch::findOrFail($batchId);
        $stages = $this->stagesFor($batch);

        return response()->json([
            'batch_id'   => $batch->id,
            'move_sheet' => $this->read->moveSheet($stages, $stageId),
        ]);
    }

    /** The audit trail for a batch: who moved what, when, and why. */
    public function history(Request $request, int $batchId): JsonResponse
    {
        $batch = ProductionOrderBatch::findOrFail($batchId);

        $movements = PieceMovement::with([
            'fromStage:id,name,code',
            'toStage:id,name,code',
            'reason:code,label',
            'mover:id,first_name,last_name',
        ])
            ->where('production_order_batch_id', $batch->id)
            ->orderByDesc('moved_at')
            ->orderByDesc('id')
            ->paginate(min(100, (int) $request->query('per_page', 50)));

        return response()->json($movements);
    }

    /** The reason catalogue, grouped by the movement each reason explains. */
    public function reasons(): JsonResponse
    {
        return response()->json(
            MovementReason::active()
                ->orderBy('applies_to')->orderBy('sort_order')
                ->get(['code', 'label', 'applies_to', 'is_defect'])
                ->groupBy('applies_to')
        );
    }

    /**
     * The defect Pareto — the payoff for making a reason mandatory on every
     * rework and scrap.
     */
    public function defects(Request $request, int $orderId): JsonResponse
    {
        return response()->json(
            DB::table('v_movement_defect_pareto')
                ->where('production_order_id', $orderId)
                ->orderByDesc('pieces')
                ->get()
        );
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** Load pieces onto the line: the initial cut, or a re-cut after scrap. */
    public function intake(Request $request, int $batchId): JsonResponse
    {
        $data = $request->validate([
            'client_ref'  => 'required|uuid',
            'quantity'    => 'required|integer|min:1',
            'reason_code' => 'nullable|string|exists:movement_reasons,code',
            'note'        => 'nullable|string|max:500',
            'device_id'   => 'nullable|string|max:120',
        ]);

        return $this->attempt(fn () => $this->movements->intake(
            clientRef: $data['client_ref'],
            batchId: $batchId,
            quantity: (int) $data['quantity'],
            actor: $request->user(),
            reasonCode: $data['reason_code'] ?? null,
            note: $data['note'] ?? null,
            deviceId: $data['device_id'] ?? null,
        ), $batchId);
    }

    /** Move pieces: forward, back for rework, to scrap, on hold, or released. */
    public function move(Request $request, int $batchId): JsonResponse
    {
        $data = $request->validate([
            'client_ref'    => 'required|uuid',
            'from_stage_id' => 'required|integer|exists:production_workflow_stages,id',
            'to_stage_id'   => 'nullable|integer|exists:production_workflow_stages,id',
            'quantity'      => 'required|integer|min:1',
            'type'          => 'required|in:FORWARD,REWORK,SCRAP,HOLD,RELEASE',
            'reason_code'   => 'nullable|string|exists:movement_reasons,code',
            'note'          => 'nullable|string|max:500',
            'device_id'     => 'nullable|string|max:120',
        ]);

        return $this->attempt(fn () => $this->movements->apply(new MoveCommand(
            clientRef: $data['client_ref'],
            batchId: $batchId,
            fromStageId: (int) $data['from_stage_id'],
            quantity: (int) $data['quantity'],
            type: $data['type'],
            actor: $request->user(),
            toStageId: isset($data['to_stage_id']) ? (int) $data['to_stage_id'] : null,
            reasonCode: $data['reason_code'] ?? null,
            note: $data['note'] ?? null,
            deviceId: $data['device_id'] ?? null,
        )), $batchId);
    }

    /** Undo a movement by posting its inverse. The original stays in history. */
    public function reverse(Request $request, int $movementId): JsonResponse
    {
        $data = $request->validate([
            'client_ref' => 'required|uuid',
            'note'       => 'nullable|string|max:500',
            'device_id'  => 'nullable|string|max:120',
        ]);

        $original = PieceMovement::findOrFail($movementId);

        return $this->attempt(fn () => $this->movements->reverse(
            clientRef: $data['client_ref'],
            movementId: $movementId,
            actor: $request->user(),
            note: $data['note'] ?? null,
            deviceId: $data['device_id'] ?? null,
        ), (int) $original->production_order_batch_id);
    }

    /**
     * Replay the ledger over a batch. The projection is derived, so this can
     * only ever restore it — never invent state.
     */
    public function rebuild(int $batchId): JsonResponse
    {
        $batch = ProductionOrderBatch::findOrFail($batchId);
        $this->movements->rebuild($batch->id);

        return response()->json([
            'message'      => 'Replayed the ledger for this batch.',
            'distribution' => $this->distribution($batch->id),
            'integrity'    => DB::table('v_batch_integrity')->where('batch_id', $batch->id)->first(),
        ]);
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * Run a movement and answer with the batch's new state.
     *
     * A refused movement is a coded 4xx, not a 500: the request was well-formed
     * and the floor simply said no.
     */
    private function attempt(callable $work, int $batchId): JsonResponse
    {
        try {
            $movement = $work();
        } catch (MovementException $e) {
            return response()->json($e->toArray(), $e->status());
        }

        return response()->json([
            'movement'     => $movement,
            'distribution' => $this->distribution($batchId),
        ], 201);
    }

    private function distribution(int $batchId): array
    {
        $batch  = ProductionOrderBatch::find($batchId);
        $stages = $batch ? $this->stagesFor($batch) : [];

        return [
            'batch_id'   => $batchId,
            'loaded_qty' => (int) ($batch->loaded_qty ?? 0),
            'shortfall'  => $batch?->shortfall() ?? 0,
            'stages'     => $stages,
            'snapshot'   => $this->read->snapshot($stages, (int) ($batch->quantity ?? 0)),
            'chips'      => $this->read->distributionChips($stages),
            'alerts'     => $this->read->stageAlerts($stages),
        ];
    }

    private function stagesFor(ProductionOrderBatch $batch): array
    {
        return $this->read->batchesForOrder((int) $batch->production_order_id)
            ->firstWhere('batch_id', $batch->id)['stages'] ?? [];
    }
}
