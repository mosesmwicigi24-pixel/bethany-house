<?php

namespace Tests\Feature;

use App\Models\BatchStageState;
use App\Models\PieceMovement;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatch;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\ProductionWorkflowStage;
use App\Models\User;
use App\Services\Production\MoveCommand;
use App\Services\Production\MovementException;
use App\Services\Production\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The piece-movement ledger.
 *
 * Every test here defends one of the invariants the model exists for:
 * conservation, exclusivity, non-negativity, append-only history, and
 * rebuildability. The edge cases mirror the operating spec's table — the
 * situations a garment floor produces on an ordinary Tuesday.
 */
class PieceMovementTest extends TestCase
{
    use RefreshDatabase;

    private MovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->movements = app(MovementService::class);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function supervisor(): User
    {
        $user = User::factory()->create();

        foreach (MovementService::PERMISSIONS as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** A worker who may move pieces forward and stop them, nothing else. */
    private function tailor(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('production.move_pieces', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** Cutting → Stitching → QC, 100 pieces, one batch. */
    private function batchOf(int $quantity = 100): ProductionOrderBatch
    {
        ProductionStage::create(['name' => 'Cutting',   'slug' => 'cutting-m',   'sort_order' => 1, 'is_active' => true]);
        ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitching-m', 'sort_order' => 2, 'is_active' => true]);
        ProductionStage::create(['name' => 'QC',        'slug' => 'qc-m',        'sort_order' => 3, 'is_active' => true]);

        $product = Product::factory()->create();
        $order   = ProductionOrder::create([
            'order_number' => 'PRD-MOVE-'.$product->id,
            'product_id'   => $product->id,
            'quantity'     => $quantity,
            'status'       => 'in_progress',
        ]);
        $order->seedTasks();

        return ProductionOrderBatch::create([
            'production_order_id' => $order->id,
            'label'               => 'Purple',
            'quantity'            => $quantity,
            'sort_order'          => 0,
        ]);
    }

    private function stage(ProductionOrderBatch $batch, string $code): ProductionWorkflowStage
    {
        return ProductionWorkflowStage::where('production_workflow_id', $batch->fresh()->production_workflow_id)
            ->where('code', $code)
            ->firstOrFail();
    }

    private function load(ProductionOrderBatch $batch, int $qty, User $actor): PieceMovement
    {
        return $this->movements->intake(
            clientRef: (string) Str::uuid(),
            batchId: $batch->id,
            quantity: $qty,
            actor: $actor,
            reasonCode: 'INITIAL_LOAD',
        );
    }

    private function move(
        ProductionOrderBatch $batch,
        string $from,
        int $qty,
        User $actor,
        string $type = PieceMovement::TYPE_FORWARD,
        ?string $to = null,
        ?string $reason = null,
    ): PieceMovement {
        return $this->movements->apply(new MoveCommand(
            clientRef: (string) Str::uuid(),
            batchId: $batch->id,
            fromStageId: $this->stage($batch, $from)->id,
            quantity: $qty,
            type: $type,
            actor: $actor,
            toStageId: $to ? $this->stage($batch, $to)->id : null,
            reasonCode: $reason,
        ));
    }

    private function qtyAt(ProductionOrderBatch $batch, string $code): array
    {
        $row = BatchStageState::where('production_order_batch_id', $batch->id)
            ->where('production_workflow_stage_id', $this->stage($batch, $code)->id)
            ->first();

        return ['ready' => (int) ($row->qty_ready ?? 0), 'held' => (int) ($row->qty_held ?? 0)];
    }

    /** The invariant every other test leans on. */
    private function assertConserved(ProductionOrderBatch $batch): void
    {
        $row = DB::table('v_batch_integrity')->where('batch_id', $batch->id)->first();

        $this->assertTrue(
            (bool) $row->is_balanced,
            "Conservation broken: loaded {$row->loaded_qty} but {$row->distributed_qty} distributed."
        );
    }

    // ------------------------------------------------------------------
    // Intake and conservation
    // ------------------------------------------------------------------

    public function test_intake_loads_pieces_into_the_entry_queue(): void
    {
        $batch = $this->batchOf(100);
        $this->load($batch, 100, $this->supervisor());

        $this->assertSame(100, $this->qtyAt($batch, 'NOT_CUT')['ready']);
        $this->assertSame(100, (int) $batch->fresh()->loaded_qty);
        $this->assertConserved($batch);
    }

    public function test_the_line_is_provisioned_from_the_stages_the_order_runs(): void
    {
        $batch = $this->batchOf(10);
        $this->load($batch, 10, $this->supervisor());

        $codes = ProductionWorkflowStage::where('production_workflow_id', $batch->fresh()->production_workflow_id)
            ->orderBy('seq')->pluck('name')->all();

        $this->assertSame(
            ['Not Cut', 'Cutting', 'Stitching', 'QC', 'Finished', 'Scrapped'],
            $codes,
        );
    }

    public function test_qc_is_recognised_as_a_gate_that_can_send_work_backwards(): void
    {
        $batch = $this->batchOf(10);
        $this->load($batch, 10, $this->supervisor());

        $qc = $this->stage($batch, 'QC_M');

        $this->assertSame(ProductionWorkflowStage::KIND_INSPECT, $qc->kind);
        $this->assertTrue((bool) $qc->allows_rework);
    }

    // ------------------------------------------------------------------
    // Forward movement
    // ------------------------------------------------------------------

    public function test_pieces_advance_one_stage_at_a_time(): void
    {
        $batch = $this->batchOf(100);
        $actor = $this->supervisor();
        $this->load($batch, 100, $actor);

        $this->move($batch, 'NOT_CUT', 30, $actor);

        $this->assertSame(70, $this->qtyAt($batch, 'NOT_CUT')['ready']);
        $this->assertSame(30, $this->qtyAt($batch, 'CUTTING_M')['ready']);
        $this->assertConserved($batch);
    }

    public function test_stages_cannot_be_skipped(): void
    {
        $batch = $this->batchOf(20);
        $actor = $this->supervisor();
        $this->load($batch, 20, $actor);

        $this->expectException(MovementException::class);
        $this->expectExceptionMessage('Stages cannot be skipped');

        $this->move($batch, 'NOT_CUT', 5, $actor, to: 'QC_M');
    }

    public function test_moving_more_than_available_is_refused_and_writes_nothing(): void
    {
        $batch = $this->batchOf(10);
        $actor = $this->supervisor();
        $this->load($batch, 10, $actor);
        $this->move($batch, 'NOT_CUT', 10, $actor);

        try {
            $this->move($batch, 'CUTTING_M', 11, $actor);
            $this->fail('Expected the move to be refused.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::INSUFFICIENT_QUANTITY, $e->errorCode);
        }

        $this->assertSame(10, $this->qtyAt($batch, 'CUTTING_M')['ready']);
        $this->assertSame(0, $this->qtyAt($batch, 'STITCHING_M')['ready']);
        $this->assertConserved($batch);
    }

    /**
     * Two tailors, ten pieces, both reaching for eight. The conditional UPDATE
     * means the second one's transaction matches zero rows and fails cleanly
     * rather than driving the stage to -6.
     */
    public function test_concurrent_moves_cannot_oversubscribe_a_stage(): void
    {
        $batch = $this->batchOf(10);
        $actor = $this->supervisor();
        $this->load($batch, 10, $actor);
        $this->move($batch, 'NOT_CUT', 10, $actor);

        $this->move($batch, 'CUTTING_M', 8, $actor);

        try {
            $this->move($batch, 'CUTTING_M', 8, $actor);
            $this->fail('The second move should not have been possible.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::INSUFFICIENT_QUANTITY, $e->errorCode);
        }

        $this->assertSame(2, $this->qtyAt($batch, 'CUTTING_M')['ready']);
        $this->assertConserved($batch);
    }

    // ------------------------------------------------------------------
    // Idempotency
    // ------------------------------------------------------------------

    public function test_replaying_a_client_ref_does_not_move_the_pieces_twice(): void
    {
        $batch = $this->batchOf(50);
        $actor = $this->supervisor();
        $this->load($batch, 50, $actor);

        $ref = (string) Str::uuid();
        $cmd = new MoveCommand(
            clientRef: $ref,
            batchId: $batch->id,
            fromStageId: $this->stage($batch, 'NOT_CUT')->id,
            quantity: 5,
            type: PieceMovement::TYPE_FORWARD,
            actor: $actor,
        );

        $first  = $this->movements->apply($cmd);
        $second = $this->movements->apply($cmd);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5, $this->qtyAt($batch, 'CUTTING_M')['ready']);
        $this->assertSame(1, PieceMovement::where('client_ref', $ref)->count());
    }

    // ------------------------------------------------------------------
    // Terminals
    // ------------------------------------------------------------------

    public function test_finished_pieces_are_the_end_of_the_line(): void
    {
        $batch = $this->batchOf(4);
        $actor = $this->supervisor();
        $this->load($batch, 4, $actor);

        foreach (['NOT_CUT', 'CUTTING_M', 'STITCHING_M', 'QC_M'] as $from) {
            $this->move($batch, $from, 4, $actor);
        }

        $this->assertSame(4, $this->qtyAt($batch, 'FINISHED')['ready']);

        try {
            $this->move($batch, 'FINISHED', 1, $actor);
            $this->fail('Finished pieces should not move on.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::TERMINAL_STAGE, $e->errorCode);
        }
    }

    public function test_scrapped_pieces_cannot_re_enter_production(): void
    {
        $batch = $this->batchOf(10);
        $actor = $this->supervisor();
        $this->load($batch, 10, $actor);
        $this->move($batch, 'NOT_CUT', 10, $actor);
        $this->move($batch, 'CUTTING_M', 3, $actor, PieceMovement::TYPE_SCRAP, reason: 'FABRIC_FLAW');

        try {
            $this->move($batch, 'SCRAPPED', 1, $actor, PieceMovement::TYPE_REWORK, to: 'CUTTING_M', reason: 'WRONG_SIZE');
            $this->fail('Scrap is terminal.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::TERMINAL_STAGE, $e->errorCode);
        }
    }

    // ------------------------------------------------------------------
    // Rework
    // ------------------------------------------------------------------

    public function test_qc_sends_failures_back_and_lets_the_rest_continue(): void
    {
        $batch = $this->batchOf(8);
        $actor = $this->supervisor();
        $this->load($batch, 8, $actor);
        $this->move($batch, 'NOT_CUT', 8, $actor);
        $this->move($batch, 'CUTTING_M', 8, $actor);
        $this->move($batch, 'STITCHING_M', 8, $actor);

        // QC fails 2 of 8: back to Stitching. The other 6 go forward.
        $this->move($batch, 'QC_M', 2, $actor, PieceMovement::TYPE_REWORK, to: 'STITCHING_M', reason: 'SEAM_PUCKER');
        $this->move($batch, 'QC_M', 6, $actor);

        $this->assertSame(2, $this->qtyAt($batch, 'STITCHING_M')['ready']);
        $this->assertSame(6, $this->qtyAt($batch, 'FINISHED')['ready']);
        $this->assertConserved($batch);
    }

    public function test_rework_requires_a_reason(): void
    {
        $batch = $this->batchOf(4);
        $actor = $this->supervisor();
        $this->load($batch, 4, $actor);
        foreach (['NOT_CUT', 'CUTTING_M', 'STITCHING_M'] as $from) {
            $this->move($batch, $from, 4, $actor);
        }

        try {
            $this->move($batch, 'QC_M', 1, $actor, PieceMovement::TYPE_REWORK, to: 'STITCHING_M');
            $this->fail('Rework without a reason should be refused.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::REASON_REQUIRED, $e->errorCode);
        }
    }

    public function test_rework_cannot_return_pieces_to_the_uncut_queue(): void
    {
        $batch = $this->batchOf(4);
        $actor = $this->supervisor();
        $this->load($batch, 4, $actor);
        foreach (['NOT_CUT', 'CUTTING_M', 'STITCHING_M'] as $from) {
            $this->move($batch, $from, 4, $actor);
        }

        $this->expectException(MovementException::class);
        $this->expectExceptionMessage('Scrap them instead');

        $this->move($batch, 'QC_M', 1, $actor, PieceMovement::TYPE_REWORK, to: 'NOT_CUT', reason: 'WRONG_SIZE');
    }

    public function test_a_working_stage_cannot_send_work_backwards(): void
    {
        $batch = $this->batchOf(4);
        $actor = $this->supervisor();
        $this->load($batch, 4, $actor);
        $this->move($batch, 'NOT_CUT', 4, $actor);
        $this->move($batch, 'CUTTING_M', 4, $actor);

        $this->expectException(MovementException::class);
        $this->expectExceptionMessage('cannot send work backwards');

        $this->move($batch, 'STITCHING_M', 1, $actor, PieceMovement::TYPE_REWORK, to: 'CUTTING_M', reason: 'WRONG_SIZE');
    }

    /** A scrap filed under "waiting on buttons" would poison the defect Pareto. */
    public function test_a_reason_must_explain_the_movement_it_is_attached_to(): void
    {
        $batch = $this->batchOf(4);
        $actor = $this->supervisor();
        $this->load($batch, 4, $actor);
        $this->move($batch, 'NOT_CUT', 4, $actor);

        try {
            $this->move($batch, 'CUTTING_M', 1, $actor, PieceMovement::TYPE_SCRAP, reason: 'NO_TRIM');
            $this->fail('A hold reason should not explain a scrap.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::REASON_INVALID, $e->errorCode);
        }
    }

    // ------------------------------------------------------------------
    // Scrap, shortfall and replacement
    // ------------------------------------------------------------------

    public function test_scrap_keeps_pieces_counted_and_surfaces_the_shortfall(): void
    {
        $batch = $this->batchOf(100);
        $actor = $this->supervisor();
        $this->load($batch, 100, $actor);
        $this->move($batch, 'NOT_CUT', 100, $actor);

        $this->move($batch, 'CUTTING_M', 3, $actor, PieceMovement::TYPE_SCRAP, reason: 'FABRIC_FLAW');

        $this->assertSame(3, $this->qtyAt($batch, 'SCRAPPED')['ready']);
        $this->assertSame(100, (int) $batch->fresh()->loaded_qty, 'Scrap must not subtract from what was loaded.');
        $this->assertSame(3, $batch->fresh()->shortfall());
        $this->assertConserved($batch);

        $snapshot = DB::table('v_order_snapshot')
            ->where('production_order_id', $batch->production_order_id)->first();

        $this->assertSame(3, (int) $snapshot->scrapped);
        $this->assertSame(97, (int) $snapshot->deliverable);
        $this->assertSame(3, (int) $snapshot->shortfall);
    }

    public function test_re_cutting_the_scrapped_pieces_closes_the_shortfall(): void
    {
        $batch = $this->batchOf(100);
        $actor = $this->supervisor();
        $this->load($batch, 100, $actor);
        $this->move($batch, 'NOT_CUT', 100, $actor);
        $this->move($batch, 'CUTTING_M', 3, $actor, PieceMovement::TYPE_SCRAP, reason: 'FABRIC_FLAW');

        $this->movements->intake(
            clientRef: (string) Str::uuid(),
            batchId: $batch->id,
            quantity: 3,
            actor: $actor,
            reasonCode: 'REPLACEMENT',
        );

        $this->assertSame(103, (int) $batch->fresh()->loaded_qty);
        $this->assertSame(0, $batch->fresh()->shortfall());
        $this->assertConserved($batch);
    }

    // ------------------------------------------------------------------
    // Hold
    // ------------------------------------------------------------------

    public function test_holding_pieces_stops_them_without_moving_them(): void
    {
        $batch = $this->batchOf(10);
        $actor = $this->supervisor();
        $this->load($batch, 10, $actor);
        $this->move($batch, 'NOT_CUT', 10, $actor);

        $this->move($batch, 'CUTTING_M', 4, $actor, PieceMovement::TYPE_HOLD, reason: 'NO_TRIM');

        $at = $this->qtyAt($batch, 'CUTTING_M');
        $this->assertSame(6, $at['ready'], 'Only ready pieces can move on.');
        $this->assertSame(4, $at['held'], 'Held pieces stay where they physically are.');
        $this->assertConserved($batch);
    }

    public function test_held_pieces_cannot_move_forward_until_released(): void
    {
        $batch = $this->batchOf(4);
        $actor = $this->supervisor();
        $this->load($batch, 4, $actor);
        $this->move($batch, 'NOT_CUT', 4, $actor);
        $this->move($batch, 'CUTTING_M', 4, $actor, PieceMovement::TYPE_HOLD, reason: 'NO_TRIM');

        try {
            $this->move($batch, 'CUTTING_M', 1, $actor);
            $this->fail('Held pieces are not workable.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::INSUFFICIENT_QUANTITY, $e->errorCode);
        }

        $this->move($batch, 'CUTTING_M', 4, $actor, PieceMovement::TYPE_RELEASE);
        $this->move($batch, 'CUTTING_M', 4, $actor);

        $this->assertSame(4, $this->qtyAt($batch, 'STITCHING_M')['ready']);
        $this->assertConserved($batch);
    }

    public function test_a_hold_flags_the_task_and_releasing_clears_it(): void
    {
        $batch = $this->batchOf(10);
        $actor = $this->supervisor();
        $this->load($batch, 10, $actor);
        $this->move($batch, 'NOT_CUT', 10, $actor);
        $this->move($batch, 'CUTTING_M', 4, $actor, PieceMovement::TYPE_HOLD, reason: 'NO_TRIM');

        $cuttingTask = ProductionTask::where('production_order_id', $batch->production_order_id)
            ->where('production_stage_id', $this->stage($batch, 'CUTTING_M')->production_stage_id)
            ->first();

        $this->assertSame('Waiting on buttons or trim', $cuttingTask->fresh()->blocked_reason);

        $this->move($batch, 'CUTTING_M', 4, $actor, PieceMovement::TYPE_RELEASE);

        $this->assertNull($cuttingTask->fresh()->blocked_reason);
    }

    // ------------------------------------------------------------------
    // Reversal
    // ------------------------------------------------------------------

    public function test_a_mistaken_move_is_reversed_by_an_inverse_not_a_delete(): void
    {
        $batch = $this->batchOf(50);
        $actor = $this->supervisor();
        $this->load($batch, 50, $actor);

        $oops = $this->move($batch, 'NOT_CUT', 40, $actor);   // meant 4
        $this->assertSame(40, $this->qtyAt($batch, 'CUTTING_M')['ready']);

        $this->movements->reverse((string) Str::uuid(), $oops->id, $actor);

        $this->assertSame(0, $this->qtyAt($batch, 'CUTTING_M')['ready']);
        $this->assertSame(50, $this->qtyAt($batch, 'NOT_CUT')['ready']);
        $this->assertDatabaseHas('piece_movements', ['id' => $oops->id]);
        $this->assertSame(2, PieceMovement::where('production_order_batch_id', $batch->id)
            ->whereIn('movement_type', [PieceMovement::TYPE_FORWARD, PieceMovement::TYPE_REVERSAL])->count());
        $this->assertConserved($batch);
    }

    public function test_a_movement_can_only_be_reversed_once(): void
    {
        $batch = $this->batchOf(10);
        $actor = $this->supervisor();
        $this->load($batch, 10, $actor);
        $move = $this->move($batch, 'NOT_CUT', 5, $actor);

        $this->movements->reverse((string) Str::uuid(), $move->id, $actor);

        try {
            $this->movements->reverse((string) Str::uuid(), $move->id, $actor);
            $this->fail('A movement should only be reversible once.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::ALREADY_REVERSED, $e->errorCode);
        }
    }

    public function test_a_movement_cannot_be_reversed_once_the_pieces_have_moved_on(): void
    {
        $batch = $this->batchOf(10);
        $actor = $this->supervisor();
        $this->load($batch, 10, $actor);
        $move = $this->move($batch, 'NOT_CUT', 10, $actor);
        $this->move($batch, 'CUTTING_M', 10, $actor);

        try {
            $this->movements->reverse((string) Str::uuid(), $move->id, $actor);
            $this->fail('The pieces are no longer where that movement put them.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::INSUFFICIENT_QUANTITY, $e->errorCode);
        }
    }

    /**
     * The case the reference implementation got wrong: un-loading has to bring
     * loaded_qty back down, or conservation breaks the moment somebody corrects
     * a mistaken cut load.
     */
    public function test_reversing_an_intake_unloads_the_pieces_and_conserves(): void
    {
        $batch = $this->batchOf(100);
        $actor = $this->supervisor();

        $load = $this->load($batch, 100, $actor);
        $this->assertSame(100, (int) $batch->fresh()->loaded_qty);

        $this->movements->reverse((string) Str::uuid(), $load->id, $actor);

        $this->assertSame(0, (int) $batch->fresh()->loaded_qty);
        $this->assertSame(0, $this->qtyAt($batch, 'NOT_CUT')['ready']);
        $this->assertConserved($batch);
    }

    // ------------------------------------------------------------------
    // Rebuildability — the ledger is the truth
    // ------------------------------------------------------------------

    public function test_replaying_the_ledger_reproduces_the_projection_exactly(): void
    {
        $batch = $this->batchOf(100);
        $actor = $this->supervisor();

        $this->load($batch, 100, $actor);
        $this->move($batch, 'NOT_CUT', 60, $actor);
        $this->move($batch, 'CUTTING_M', 30, $actor);
        $this->move($batch, 'CUTTING_M', 5, $actor, PieceMovement::TYPE_HOLD, reason: 'NO_MATERIAL');
        $this->move($batch, 'CUTTING_M', 2, $actor, PieceMovement::TYPE_SCRAP, reason: 'CUT_ERROR');
        $this->move($batch, 'STITCHING_M', 20, $actor);
        $this->move($batch, 'QC_M', 4, $actor, PieceMovement::TYPE_REWORK, to: 'STITCHING_M', reason: 'SEAM_PUCKER');
        $this->move($batch, 'QC_M', 10, $actor);

        $before = $this->projection($batch);
        $loaded = (int) $batch->fresh()->loaded_qty;

        $this->movements->rebuild($batch->id);

        $this->assertEquals($before, $this->projection($batch), 'Replay must reproduce the projection.');
        $this->assertSame($loaded, (int) $batch->fresh()->loaded_qty);
        $this->assertConserved($batch);
    }

    private function projection(ProductionOrderBatch $batch): array
    {
        return DB::table('batch_stage_states')
            ->where('production_order_batch_id', $batch->id)
            ->orderBy('production_workflow_stage_id')
            ->get()
            ->map(fn ($r) => [
                'stage'      => (int) $r->production_workflow_stage_id,
                'ready'      => (int) $r->qty_ready,
                'held'       => (int) $r->qty_held,
                'entered_at' => $r->entered_at,
                'last_out'   => $r->last_out_at,
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // The bridge back to tasks
    // ------------------------------------------------------------------

    public function test_the_legacy_progress_counters_are_derived_from_the_ledger(): void
    {
        $batch = $this->batchOf(10);
        $actor = $this->supervisor();
        $this->load($batch, 10, $actor);
        $this->move($batch, 'NOT_CUT', 10, $actor);
        $this->move($batch, 'CUTTING_M', 6, $actor);

        $order = $batch->productionOrder;
        $tasks = ProductionTask::where('production_order_id', $order->id)
            ->get()->keyBy('production_stage_id');

        $cutting   = $this->stage($batch, 'CUTTING_M')->production_stage_id;
        $stitching = $this->stage($batch, 'STITCHING_M')->production_stage_id;

        // 6 pieces are downstream of Cutting, so Cutting has passed 6.
        $this->assertSame(6, (int) $tasks[$cutting]->fresh()->quantity_done);
        // Nothing is downstream of Stitching yet.
        $this->assertSame(0, (int) $tasks[$stitching]->fresh()->quantity_done);
    }

    public function test_rework_pulls_a_completed_stage_back_below_its_quantity(): void
    {
        $batch = $this->batchOf(4);
        $actor = $this->supervisor();
        $this->load($batch, 4, $actor);
        foreach (['NOT_CUT', 'CUTTING_M', 'STITCHING_M'] as $from) {
            $this->move($batch, $from, 4, $actor);
        }

        $stitchingStage = $this->stage($batch, 'STITCHING_M')->production_stage_id;
        $task = ProductionTask::where('production_order_id', $batch->production_order_id)
            ->where('production_stage_id', $stitchingStage)->first();

        $this->assertSame('completed', $task->fresh()->status);

        $this->move($batch, 'QC_M', 1, $actor, PieceMovement::TYPE_REWORK, to: 'STITCHING_M', reason: 'SEAM_PUCKER');

        $this->assertSame(3, (int) $task->fresh()->quantity_done);
        $this->assertSame('in_progress', $task->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Authorisation
    // ------------------------------------------------------------------

    public function test_a_tailor_may_move_work_forward_but_not_write_it_off(): void
    {
        $batch  = $this->batchOf(10);
        $tailor = $this->tailor();
        $this->load($batch, 10, $this->supervisor());

        $this->move($batch, 'NOT_CUT', 10, $tailor);
        $this->assertSame(10, $this->qtyAt($batch, 'CUTTING_M')['ready']);

        try {
            $this->move($batch, 'CUTTING_M', 1, $tailor, PieceMovement::TYPE_SCRAP, reason: 'FABRIC_FLAW');
            $this->fail('A tailor should not be able to scrap pieces.');
        } catch (MovementException $e) {
            $this->assertSame(MovementException::NOT_AUTHORISED, $e->errorCode);
        }
    }

    public function test_a_tailor_cannot_send_work_backwards(): void
    {
        $batch  = $this->batchOf(4);
        $tailor = $this->tailor();
        $actor  = $this->supervisor();
        $this->load($batch, 4, $actor);
        foreach (['NOT_CUT', 'CUTTING_M', 'STITCHING_M'] as $from) {
            $this->move($batch, $from, 4, $actor);
        }

        $this->expectException(MovementException::class);
        $this->expectExceptionMessage('cannot perform rework movements');

        $this->move($batch, 'QC_M', 1, $tailor, PieceMovement::TYPE_REWORK, to: 'STITCHING_M', reason: 'SEAM_PUCKER');
    }
}
