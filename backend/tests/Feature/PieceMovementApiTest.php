<?php

namespace Tests\Feature;

use App\Models\PieceMovement;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatch;
use App\Models\ProductionStage;
use App\Models\ProductionWorkflowStage;
use App\Models\User;
use App\Services\Production\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The HTTP surface the floor tablet talks to.
 *
 * The invariants themselves are proven in PieceMovementTest against the engine;
 * what matters here is that the endpoints authorise correctly, that a refused
 * movement comes back as a coded 4xx rather than a 500, and that every write
 * echoes the batch's new distribution so the screen never needs a second round
 * trip on a workshop connection.
 */
class PieceMovementApiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions): User
    {
        $user = User::factory()->create();

        foreach (array_merge(['production.view'], $permissions) as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function supervisor(): User
    {
        return $this->actor(array_values(MovementService::PERMISSIONS));
    }

    private function batch(int $quantity = 20): ProductionOrderBatch
    {
        ProductionStage::create(['name' => 'Cutting',   'slug' => 'cutting-api',   'sort_order' => 1, 'is_active' => true]);
        ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitching-api', 'sort_order' => 2, 'is_active' => true]);

        $product = Product::factory()->create();
        $order   = ProductionOrder::create([
            'order_number' => 'PRD-API-'.$product->id,
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

    private function stageId(ProductionOrderBatch $batch, string $code): int
    {
        return (int) ProductionWorkflowStage::where('production_workflow_id', $batch->fresh()->production_workflow_id)
            ->where('code', $code)->value('id');
    }

    private function intake(ProductionOrderBatch $batch, int $qty): void
    {
        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/intake", [
            'client_ref' => (string) Str::uuid(),
            'quantity'   => $qty,
        ])->assertCreated();
    }

    // ------------------------------------------------------------------

    public function test_loading_a_batch_returns_the_new_distribution(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);

        $response = $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/intake", [
            'client_ref'  => (string) Str::uuid(),
            'quantity'    => 20,
            'reason_code' => 'INITIAL_LOAD',
        ])->assertCreated();

        $response->assertJsonPath('distribution.loaded_qty', 20);
        $response->assertJsonPath('distribution.snapshot.not_started', 20);
        $response->assertJsonPath('distribution.snapshot.total', 20);
    }

    public function test_moving_pieces_forward_echoes_the_updated_stages(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        $response = $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'NOT_CUT'),
            'quantity'      => 8,
            'type'          => 'FORWARD',
        ])->assertCreated();

        $response->assertJsonPath('distribution.snapshot.not_started', 12);
        $response->assertJsonPath('distribution.snapshot.in_production', 8);
    }

    public function test_an_impossible_move_is_a_coded_422_not_a_server_error(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'NOT_CUT'),
            'quantity'      => 50,
            'type'          => 'FORWARD',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INSUFFICIENT_QUANTITY');
    }

    public function test_a_replayed_client_ref_does_not_move_pieces_twice(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        $payload = [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'NOT_CUT'),
            'quantity'      => 5,
            'type'          => 'FORWARD',
        ];

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", $payload)->assertCreated();
        $second = $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", $payload)->assertCreated();

        $second->assertJsonPath('distribution.snapshot.in_production', 5);
        $this->assertSame(1, PieceMovement::where('client_ref', $payload['client_ref'])->count());
    }

    public function test_a_worker_without_the_scrap_grant_is_refused(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        // Re-authenticate as somebody who may only move work forward.
        $this->actor(['production.move_pieces']);

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'NOT_CUT'),
            'quantity'      => 1,
            'type'          => 'SCRAP',
            'reason_code'   => 'FABRIC_FLAW',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'NOT_AUTHORISED');
    }

    public function test_the_order_view_reports_the_headline_and_the_shortfall(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'NOT_CUT'),
            'quantity'      => 20,
            'type'          => 'FORWARD',
        ])->assertCreated();

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'CUTTING_API'),
            'quantity'      => 2,
            'type'          => 'SCRAP',
            'reason_code'   => 'FABRIC_FLAW',
        ])->assertCreated();

        $this->getJson("/api/v1/admin/production-movement/orders/{$batch->production_order_id}")
            ->assertOk()
            ->assertJsonPath('snapshot.scrapped', 2)
            ->assertJsonPath('snapshot.deliverable', 18)
            ->assertJsonPath('snapshot.shortfall', 2);
    }

    public function test_the_move_sheet_never_offers_a_quantity_the_stage_cannot_honour(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'NOT_CUT'),
            'quantity'      => 6,
            'type'          => 'FORWARD',
        ])->assertCreated();

        $cutting = $this->stageId($batch, 'CUTTING_API');

        $response = $this->getJson("/api/v1/admin/production-movement/batches/{$batch->id}/move-sheet/{$cutting}")
            ->assertOk();

        $response->assertJsonPath('move_sheet.available', 6);
        $response->assertJsonPath('move_sheet.can_move', true);
        // 1 and 5 are honourable, 10 is not; "All 6" is the tap they want.
        $response->assertJsonPath('move_sheet.chips.0.enabled', true);
        $response->assertJsonPath('move_sheet.chips.1.enabled', true);
        $response->assertJsonPath('move_sheet.chips.2.enabled', false);
        $response->assertJsonPath('move_sheet.all_chip.value', 6);
    }

    public function test_a_stage_whose_work_is_all_on_hold_says_so(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'NOT_CUT'),
            'quantity'      => 4,
            'type'          => 'FORWARD',
        ])->assertCreated();

        $cutting = $this->stageId($batch, 'CUTTING_API');

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $cutting,
            'quantity'      => 4,
            'type'          => 'HOLD',
            'reason_code'   => 'NO_TRIM',
        ])->assertCreated();

        $this->getJson("/api/v1/admin/production-movement/batches/{$batch->id}/move-sheet/{$cutting}")
            ->assertOk()
            ->assertJsonPath('move_sheet.can_move', false)
            ->assertJsonPath('move_sheet.blocked_reason', 'All 4 piece(s) here are on hold. Release them first.');
    }

    public function test_the_reason_catalogue_is_grouped_by_the_movement_it_explains(): void
    {
        $this->supervisor();

        $this->getJson('/api/v1/admin/production-movement/reasons')
            ->assertOk()
            ->assertJsonStructure(['REWORK', 'SCRAP', 'HOLD', 'INTAKE']);
    }

    public function test_defects_are_reported_with_their_reason(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'NOT_CUT'),
            'quantity'      => 20,
            'type'          => 'FORWARD',
        ])->assertCreated();

        $this->postJson("/api/v1/admin/production-movement/batches/{$batch->id}/movements", [
            'client_ref'    => (string) Str::uuid(),
            'from_stage_id' => $this->stageId($batch, 'CUTTING_API'),
            'quantity'      => 3,
            'type'          => 'SCRAP',
            'reason_code'   => 'CUT_ERROR',
        ])->assertCreated();

        $this->getJson("/api/v1/admin/production-movement/orders/{$batch->production_order_id}/defects")
            ->assertOk()
            ->assertJsonPath('0.reason_code', 'CUT_ERROR')
            ->assertJsonPath('0.pieces', 3);
    }

    public function test_the_history_endpoint_shows_who_moved_what_and_why(): void
    {
        $this->supervisor();
        $batch = $this->batch(20);
        $this->intake($batch, 20);

        $this->getJson("/api/v1/admin/production-movement/batches/{$batch->id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.movement_type', 'INTAKE')
            ->assertJsonPath('data.0.quantity', 20);
    }
}
