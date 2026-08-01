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
use App\Services\Production\StationQueueService;
use App\Services\Production\TailorUiFlag;
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
    /**
     * How long a worker has to take a move back before it needs a supervisor.
     * Long enough to notice a mis-tap, short enough that the pieces are still
     * where the movement put them.
     */
    private const UNDO_SECONDS = 15;

    public function __construct(
        private readonly MovementService $movements,
        private readonly ProductionReadModel $read,
        private readonly StationQueueService $stationQueue,
        private readonly TailorUiFlag $flag,
    ) {}

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * One station's whole screen: who I am, what I have moved, the line, and
     * the queue already grouped and sorted.
     */
    public function station(Request $request, string $stageCode): JsonResponse
    {
        $payload = $this->stationQueue->forStation($stageCode, $request->user());

        if (!$payload['station']) {
            return response()->json([
                'message' => "No station called '{$stageCode}' exists on any production line.",
                'code'    => 'UNKNOWN_STATION',
            ], 404);
        }

        // The client needs to know which screen it is entitled to draw, and the
        // answer is per user AND per station during the rollout.
        $payload['ledger_ui'] = $this->flag->enabledFor($request->user(), $stageCode);

        return response()->json($payload);
    }

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

    /**
     * The reason catalogue, grouped by the movement each reason explains.
     * Codes live in data so a supervisor can add one without shipping an app.
     */
    public function reasons(Request $request): JsonResponse
    {
        $reasons = MovementReason::active()
            ->when(
                $request->filled('type'),
                fn ($q) => $q->where('applies_to', strtoupper($request->query('type')))
            )
            ->orderBy('applies_to')->orderBy('sort_order')
            ->get(['code', 'label', 'label_sw', 'applies_to', 'is_defect']);

        return response()->json(
            $request->filled('type') ? $reasons->values() : $reasons->groupBy('applies_to')
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
        ), $batchId, $data['client_ref']);
    }

    /**
     * Move pieces: forward, back for rework, to scrap, on hold, or released.
     *
     * Stages may be addressed by id or by code. Codes are what the floor screen
     * and the printed bundle ticket both speak — a tailor's phone should not
     * have to know a database key to say "hand these to Button Fixing".
     */
    public function move(Request $request, int $batchId): JsonResponse
    {
        $data = $request->validate([
            'client_ref'      => 'required|uuid',
            'from_stage_id'   => 'required_without:from_stage_code|nullable|integer|exists:production_workflow_stages,id',
            'from_stage_code' => 'required_without:from_stage_id|nullable|string|max:60',
            'to_stage_id'     => 'nullable|integer|exists:production_workflow_stages,id',
            'to_stage_code'   => 'nullable|string|max:60',
            'quantity'        => 'required|integer|min:1',
            'type'            => 'required|in:FORWARD,REWORK,SCRAP,HOLD,RELEASE',
            'reason_code'     => 'nullable|string|exists:movement_reasons,code',
            'note'            => 'nullable|string|max:500',
            'device_id'       => 'nullable|string|max:120',
        ]);

        $batch = ProductionOrderBatch::findOrFail($batchId);

        try {
            $from = $this->resolveStage($batch, $data['from_stage_id'] ?? null, $data['from_stage_code'] ?? null);
            $to   = $this->resolveStage($batch, $data['to_stage_id'] ?? null, $data['to_stage_code'] ?? null);
        } catch (MovementException $e) {
            return response()->json($e->toArray(), $e->status());
        }

        return $this->attempt(fn () => $this->movements->apply(new MoveCommand(
            clientRef: $data['client_ref'],
            batchId: $batchId,
            fromStageId: $from,
            quantity: (int) $data['quantity'],
            type: $data['type'],
            actor: $request->user(),
            toStageId: $to,
            reasonCode: $data['reason_code'] ?? null,
            note: $data['note'] ?? null,
            deviceId: $data['device_id'] ?? null,
        )), $batchId, $data['client_ref']);
    }

    /**
     * A stage id, from whichever of the two the client sent. Codes are resolved
     * against the batch's own pinned workflow, so a code can never select a
     * stage from a line this batch is not running.
     */
    private function resolveStage(ProductionOrderBatch $batch, ?int $id, ?string $code): ?int
    {
        if ($id !== null) {
            return $id;
        }

        if ($code === null || $code === '') {
            return null;
        }

        $stage = $this->movements->graphForBatch($batch)->all()
            ->first(fn ($s) => strcasecmp($s->code, $code) === 0);

        if (!$stage) {
            throw new MovementException(
                MovementException::INVALID_TRANSITION,
                "'{$code}' is not a station on this batch's production line.",
                ['stage_code' => $code],
            );
        }

        return (int) $stage->id;
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
        ), (int) $original->production_order_batch_id, $data['client_ref']);
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
    private function attempt(callable $work, int $batchId, ?string $clientRef = null): JsonResponse
    {
        // Was this ref already on the ledger before we tried? That is the
        // difference between "applied now" (201) and "you already sent this"
        // (200) — the signal an offline client uses to tell a successful flush
        // from a duplicate it can quietly drop.
        $replay = $clientRef !== null
            && PieceMovement::where('client_ref', $clientRef)->exists();

        try {
            $movement = $work();
        } catch (MovementException $e) {
            return response()->json($e->toArray(), $e->status());
        }

        return response()->json([
            'movement'     => $movement,
            'distribution' => $this->distribution($batchId),
            // Undo is a local grace period, not a lock: after it expires the
            // correction is a supervisor reversal, which the ledger keeps
            // forever. Sent as an absolute time so a phone with a wrong clock
            // still counts down against the server's.
            'undo_until'   => $replay ? null : now()->addSeconds(self::UNDO_SECONDS)->toIso8601String(),
            'replayed'     => $replay,
        ], $replay ? 200 : 201);
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
