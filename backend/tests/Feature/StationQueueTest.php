<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderBatch;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\ProductionWorkflowStage;
use App\Models\ProductTranslation;
use App\Models\User;
use App\Services\Production\MoveCommand;
use App\Services\Production\MovementService;
use App\Services\Production\TailorUiFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The station queue — one round trip that renders a tailor's whole screen.
 *
 * What matters here is that the phone has to do no work and can leak no data:
 * the grouping and sorting are already decided, the line comes from the server
 * so the rail is never hard-coded, the rework reason and the handover note are
 * derived from the ledger rather than stored twice, and a worker sees only the
 * orders she is part of.
 */
class StationQueueTest extends TestCase
{
    use RefreshDatabase;

    private MovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->movements = app(MovementService::class);
    }

    private function supervisor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('production_manager', 'sanctum'));

        foreach (array_merge(['production.view', 'production.manage_assignees'], array_values(MovementService::PERMISSIONS)) as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function worker(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('production.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('production.move_pieces', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function stages(): void
    {
        ProductionStage::firstOrCreate(['slug' => 'cutting'],   ['name' => 'Cutting',   'sort_order' => 1, 'is_active' => true]);
        ProductionStage::firstOrCreate(['slug' => 'sewing'],    ['name' => 'Sewing',    'sort_order' => 2, 'is_active' => true]);
        ProductionStage::firstOrCreate(['slug' => 'qc'],        ['name' => 'QC',        'sort_order' => 3, 'is_active' => true]);
    }

    private function job(int $qty = 20, array $attributes = []): ProductionOrderBatch
    {
        $this->stages();

        $product = Product::factory()->create();
        ProductTranslation::create([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => 'Cassock — Roman',
        ]);

        $customer = Customer::create([
            'customer_number' => 'C-'.uniqid(),
            'first_name' => 'Rev. Fr.', 'last_name' => 'Odhiambo',
            'email' => uniqid().'@example.test',
        ]);

        $order = ProductionOrder::create([
            'order_number'   => 'BH-'.random_int(1000, 9999).'-'.uniqid(),
            'product_id'     => $product->id,
            'customer_id'    => $customer->id,
            'quantity'       => $qty,
            'status'         => 'in_progress',
            'priority'       => 'normal',
            'due_date'       => now()->addDays(3)->toDateString(),
            'specifications' => ['cut' => "Gentlemen's, single-breasted", 'fabric' => 'Terylene, 240 gsm'],
        ]);
        $order->seedTasks();

        return ProductionOrderBatch::create([
            'production_order_id' => $order->id,
            'label'               => 'Purple trim',
            'quantity'            => $qty,
            'attributes'          => $attributes ?: ['swatch_hex' => '#5B3A72'],
            'sort_order'          => 0,
        ]);
    }

    private function stageId(ProductionOrderBatch $batch, string $code): int
    {
        return (int) ProductionWorkflowStage::where('production_workflow_id', $batch->fresh()->production_workflow_id)
            ->where('code', $code)->value('id');
    }

    private function load(ProductionOrderBatch $batch, int $qty, User $actor): void
    {
        $this->movements->intake(
            clientRef: (string) Str::uuid(), batchId: $batch->id,
            quantity: $qty, actor: $actor, reasonCode: 'INITIAL_LOAD',
        );
    }

    private function move(
        ProductionOrderBatch $batch, string $from, int $qty, User $actor,
        string $type = 'FORWARD', ?string $to = null, ?string $reason = null, ?string $note = null,
    ): void {
        $this->movements->apply(new MoveCommand(
            clientRef: (string) Str::uuid(),
            batchId: $batch->id,
            fromStageId: $this->stageId($batch, $from),
            quantity: $qty,
            type: $type,
            actor: $actor,
            toStageId: $to ? $this->stageId($batch, $to) : null,
            reasonCode: $reason,
            note: $note,
        ));
    }

    private function queue(string $station = 'SEWING')
    {
        return $this->getJson("/api/v1/admin/production-movement/stations/{$station}/queue");
    }

    // ------------------------------------------------------------------

    public function test_one_call_returns_the_station_the_line_and_the_queue(): void
    {
        $actor = $this->supervisor();
        $batch = $this->job(20);
        $this->load($batch, 20, $actor);
        $this->move($batch, 'NOT_CUT', 20, $actor);
        $this->move($batch, 'CUTTING', 6, $actor);

        $response = $this->queue()->assertOk();

        $response->assertJsonPath('station.stage_code', 'SEWING');
        $response->assertJsonPath('station.next_stage_name', 'QC');
        $response->assertJsonPath('station.icon', 'needle');

        // The rail comes from the server, so it is never hard-coded client-side.
        $this->assertSame(
            ['NOT_CUT', 'CUTTING', 'SEWING', 'QC', 'FINISHED', 'SCRAPPED'],
            array_column($response->json('workflow'), 'code'),
        );

        $response->assertJsonPath('groups.ready.0.my_stage.qty_ready', 6);
        $response->assertJsonPath('groups.ready.0.distribution.CUTTING', 14);
        $response->assertJsonPath('groups.ready.0.customer_name', 'Rev. Fr. Odhiambo');
        $response->assertJsonPath('groups.ready.0.garment', 'Cassock — Roman');
        $response->assertJsonPath('groups.ready.0.swatch_hex', '#5B3A72');
    }

    public function test_work_the_station_cannot_touch_yet_is_reported_as_coming(): void
    {
        $actor = $this->supervisor();
        $batch = $this->job(20);
        $this->load($batch, 20, $actor);
        $this->move($batch, 'NOT_CUT', 20, $actor);

        // Everything is at Cutting; Sewing has nothing but is not idle-for-no-reason.
        $response = $this->queue()->assertOk();

        $this->assertCount(0, $response->json('groups.ready'));
        $this->assertCount(1, $response->json('groups.upstream'));
        $response->assertJsonPath('groups.upstream.0.distribution.CUTTING', 20);
    }

    public function test_pieces_sent_back_are_grouped_first_and_carry_their_reason(): void
    {
        $actor = $this->supervisor();
        $batch = $this->job(20);
        $this->load($batch, 20, $actor);
        $this->move($batch, 'NOT_CUT', 20, $actor);
        $this->move($batch, 'CUTTING', 20, $actor);
        $this->move($batch, 'SEWING', 20, $actor);
        $this->move($batch, 'QC', 2, $actor, 'REWORK', to: 'SEWING', reason: 'SEAM_PUCKER');

        $response = $this->queue()->assertOk();

        $this->assertCount(1, $response->json('groups.rework'));
        $response->assertJsonPath('groups.rework.0.rework.qty', 2);
        $response->assertJsonPath('groups.rework.0.rework.reason_label', 'Seam puckering');
        $response->assertJsonPath('groups.rework.0.rework.from_stage', 'QC');
    }

    /**
     * The note travels with the pieces because it was attached to the pieces
     * moving — no separate messaging feature to fall out of step.
     */
    public function test_the_handover_note_comes_from_the_movement_that_delivered_the_work(): void
    {
        $actor = $this->supervisor();
        $batch = $this->job(20);
        $this->load($batch, 20, $actor);
        $this->move($batch, 'NOT_CUT', 20, $actor);
        $this->move($batch, 'CUTTING', 6, $actor, note: 'Two panels cut slightly wide — trim at the side seam.');

        $response = $this->queue()->assertOk();

        $response->assertJsonPath('groups.ready.0.handover_note.from_stage', 'Cutting');
        $response->assertJsonPath(
            'groups.ready.0.handover_note.text',
            'Two panels cut slightly wide — trim at the side seam.',
        );
        $this->assertNotNull($response->json('groups.ready.0.handover_note.by_name'));
    }

    public function test_held_work_is_grouped_separately_with_the_reason_that_stopped_it(): void
    {
        $actor = $this->supervisor();
        $batch = $this->job(20);
        $this->load($batch, 20, $actor);
        $this->move($batch, 'NOT_CUT', 20, $actor);
        $this->move($batch, 'CUTTING', 4, $actor);
        $this->move($batch, 'SEWING', 4, $actor, 'HOLD', reason: 'NO_TRIM');

        $response = $this->queue()->assertOk();

        $this->assertCount(0, $response->json('groups.ready'));
        $this->assertCount(1, $response->json('groups.held'));
        $response->assertJsonPath('groups.held.0.hold.qty', 4);
        $response->assertJsonPath('groups.held.0.hold.reason_label', 'Trim or buttons short');
        $response->assertJsonPath('groups.held.0.hold.reason_sw', 'Vifungo havitoshi');
    }

    public function test_ready_work_is_sorted_by_how_close_the_due_date_is(): void
    {
        $actor = $this->supervisor();

        $soon = $this->job(10);
        $soon->productionOrder->update(['due_date' => now()->addDay()->toDateString()]);
        $late = $this->job(10);
        $late->productionOrder->update(['due_date' => now()->addDays(9)->toDateString()]);

        foreach ([$soon, $late] as $batch) {
            $this->load($batch, 10, $actor);
            $this->move($batch, 'NOT_CUT', 10, $actor);
            $this->move($batch, 'CUTTING', 10, $actor);
        }

        $response = $this->queue()->assertOk();

        $days = array_column($response->json('groups.ready'), 'days_left');
        $this->assertSame([1, 9], $days);
    }

    /** The station screen must not become the one place that leaks the floor. */
    public function test_a_worker_sees_only_the_orders_she_is_part_of(): void
    {
        $actor = $this->supervisor();
        $mine  = $this->job(10);
        $other = $this->job(10);

        foreach ([$mine, $other] as $batch) {
            $this->load($batch, 10, $actor);
            $this->move($batch, 'NOT_CUT', 10, $actor);
            $this->move($batch, 'CUTTING', 10, $actor);
        }

        $worker = $this->worker();
        ProductionTask::where('production_order_id', $mine->production_order_id)
            ->update(['assigned_to' => $worker->id]);

        Sanctum::actingAs($worker);

        $response = $this->queue()->assertOk();

        $this->assertCount(1, $response->json('groups.ready'));
        $response->assertJsonPath('groups.ready.0.batch_id', $mine->id);
    }

    public function test_the_spec_and_checklist_ride_along_with_the_job(): void
    {
        $actor = $this->supervisor();
        $batch = $this->job(20);
        $this->load($batch, 20, $actor);
        $this->move($batch, 'NOT_CUT', 20, $actor);
        $this->move($batch, 'CUTTING', 5, $actor);

        ProductionWorkflowStage::where('production_workflow_id', $batch->fresh()->production_workflow_id)
            ->where('code', 'SEWING')
            ->update(['checklist' => json_encode(['Side seams straight and pressed open'])]);

        $response = $this->queue()->assertOk();

        $response->assertJsonPath('groups.ready.0.checklist.0', 'Side seams straight and pressed open');
        $this->assertContains(
            ['label' => 'Cut', 'value' => "Gentlemen's, single-breasted"],
            $response->json('groups.ready.0.spec'),
        );
    }

    public function test_her_own_shift_numbers_are_reported_and_nobody_elses(): void
    {
        $actor = $this->supervisor();
        $batch = $this->job(20);
        $this->load($batch, 20, $actor);
        $this->move($batch, 'NOT_CUT', 20, $actor);
        $this->move($batch, 'CUTTING', 20, $actor);
        $this->move($batch, 'SEWING', 7, $actor);

        ProductionWorkflowStage::where('production_workflow_id', $batch->fresh()->production_workflow_id)
            ->where('code', 'SEWING')->update(['daily_target' => 40]);

        $response = $this->queue()->assertOk();

        $response->assertJsonPath('shift.moved_today', 7);
        $response->assertJsonPath('shift.target', 40);
        $response->assertJsonPath('shift.hour_target', 5);

        // A different tailor's totals are her own.
        Sanctum::actingAs($this->worker());
        $this->queue()->assertOk()->assertJsonPath('shift.moved_today', 0);
    }

    public function test_the_rollout_flag_is_reported_per_user_and_per_station(): void
    {
        $actor = $this->supervisor();
        $this->job(10);

        $this->queue()->assertOk()->assertJsonPath('ledger_ui', false);

        app(TailorUiFlag::class)->set([
            'enabled' => true, 'user_ids' => [$actor->id], 'stage_codes' => ['SEWING'],
        ]);

        $this->queue('SEWING')->assertOk()->assertJsonPath('ledger_ui', true);
        $this->queue('CUTTING')->assertOk()->assertJsonPath('ledger_ui', false);
    }

    public function test_an_unknown_station_is_a_404_not_an_empty_screen(): void
    {
        $this->supervisor();

        $this->queue('NOWHERE')->assertStatus(404)->assertJsonPath('code', 'UNKNOWN_STATION');
    }
}
