<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The stage-sequence gate stopped firing for the people it exists to gate.
 *
 * blockingTask() asks "has an earlier stage passed me any pieces yet" by
 * reading its siblings on the same production order. Those siblings belong to
 * OTHER tailors — a different bench does Cutting, she does Stitching — and
 * ProductionTask is bounded by assigned_to. So for a tailor the sibling query
 * returned almost nothing, minPassed stayed at the order quantity, and the
 * gate answered "nothing is blocking you" on every task in the shop.
 *
 * Both halves broke together, which is worse than either alone: the gate that
 * refuses the work failed open, and the padlock that would have told her to
 * wait never rendered. She could start Stitching on a garment nobody had cut,
 * and nothing on the screen said otherwise.
 *
 * The question these queries ask is about the ORDER's pipeline, not about the
 * caller — the same category as the order-number lookups exempted in #287.
 */
class TailorStageGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function tailor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function stage(string $slug, string $name, int $order): ProductionStage
    {
        return ProductionStage::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'sort_order' => $order, 'is_active' => true],
        );
    }

    private function order(int $quantity = 1): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'PO-' . fake()->unique()->numerify('######'),
            'product_id'   => Product::factory()->create()->id,
            'status'       => 'in_progress',
            'quantity'     => $quantity,
        ]);
    }

    private function task(
        ProductionOrder $po,
        ProductionStage $stage,
        ?User $assignee,
        int $sequence,
        string $status = 'pending',
        int $done = 0,
    ): ProductionTask {
        return ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $po->id,
            'production_stage_id' => $stage->id,
            'assigned_to'         => $assignee?->id,
            'sequence'            => $sequence,
            'status'              => $status,
            'quantity_done'       => $done,
        ]);
    }

    /**
     * GET /tailor/tasks returns a BARE array (smartTaskSort's list), not an
     * envelope. Reading a 'tasks' key here silently yielded null and made
     * these assertions pass without testing anything, so the shape is
     * asserted once, here.
     */
    private function myTasks(): \Illuminate\Support\Collection
    {
        $body = $this->getJson('/api/v1/tailor/tasks')->assertOk()->json();

        $this->assertIsArray($body, 'the endpoint returns a list');
        $this->assertArrayNotHasKey('tasks', $body, 'and not an envelope');

        return collect($body);
    }

    // ── the gate itself ─────────────────────────────────────────────────────

    /**
     * The headline. Cutting belongs to someone else and has produced nothing;
     * Stitching is hers and must be blocked by it.
     */
    public function test_an_earlier_stage_on_another_tailors_bench_still_blocks_her(): void
    {
        $po      = $this->order();
        $cutter  = $this->tailor();
        $stitcher = $this->tailor();

        $this->task($po, $this->stage('cut-g', 'Cutting', 1), $cutter, 1);
        $mine = $this->task($po, $this->stage('stitch-g', 'Stitching', 2), $stitcher, 2);

        Sanctum::actingAs($stitcher);

        $blocker = ProductionTask::withoutViewerScope()->find($mine->id)->blockingTask();

        $this->assertNotNull($blocker,
            'Cutting has passed nothing, so Stitching is not available');
        $this->assertSame('Cutting', $blocker->stage->name);
    }

    public function test_she_is_released_once_the_earlier_bench_has_passed_a_piece(): void
    {
        $po       = $this->order(2);
        $cutter   = $this->tailor();
        $stitcher = $this->tailor();

        // One of the two pieces is cut — the surplus she may work on.
        $this->task($po, $this->stage('cut-g', 'Cutting', 1), $cutter, 1, 'in_progress', 1);
        $mine = $this->task($po, $this->stage('stitch-g', 'Stitching', 2), $stitcher, 2);

        Sanctum::actingAs($stitcher);

        $this->assertNull(
            ProductionTask::withoutViewerScope()->find($mine->id)->blockingTask(),
            'one cut piece is one piece she may stitch'
        );
    }

    public function test_a_completed_earlier_stage_does_not_block(): void
    {
        $po       = $this->order();
        $cutter   = $this->tailor();
        $stitcher = $this->tailor();

        $this->task($po, $this->stage('cut-g', 'Cutting', 1), $cutter, 1, 'completed');
        $mine = $this->task($po, $this->stage('stitch-g', 'Stitching', 2), $stitcher, 2);

        Sanctum::actingAs($stitcher);

        $this->assertNull(ProductionTask::withoutViewerScope()->find($mine->id)->blockingTask());
    }

    /** An admin was never scoped, so the gate must read the same for both. */
    public function test_the_gate_reads_the_same_for_a_manager_and_for_the_tailor(): void
    {
        $po       = $this->order();
        $cutter   = $this->tailor();
        $stitcher = $this->tailor();

        $this->task($po, $this->stage('cut-g', 'Cutting', 1), $cutter, 1);
        $mine = $this->task($po, $this->stage('stitch-g', 'Stitching', 2), $stitcher, 2);

        $admin = User::factory()->create();
        $admin->assignRole(Role::findByName('admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($admin->fresh());
        $asManager = ProductionTask::withoutViewerScope()->find($mine->id)->blockingTask();

        Sanctum::actingAs($stitcher);
        $asTailor = ProductionTask::withoutViewerScope()->find($mine->id)->blockingTask();

        $this->assertSame($asManager?->id, $asTailor?->id,
            'a gate that depends on who is looking is not a gate');
    }

    // ── the padlock on the card ─────────────────────────────────────────────

    /**
     * The tailor should SEE "waiting on Cutting" rather than discover it by
     * tapping Start. myTasks() built that flag from the same sibling lookup.
     */
    public function test_her_task_list_shows_what_she_is_waiting_on(): void
    {
        $po       = $this->order();
        $cutter   = $this->tailor();
        $stitcher = $this->tailor();

        $this->task($po, $this->stage('cut-g', 'Cutting', 1), $cutter, 1);
        $mine = $this->task($po, $this->stage('stitch-g', 'Stitching', 2), $stitcher, 2);

        Sanctum::actingAs($stitcher);

        $row = $this->myTasks()->firstWhere('id', $mine->id);

        $this->assertNotNull($row, 'her own task is on her list');
        $this->assertSame('Cutting', data_get($row, 'blocked_by_stage'),
            'the padlock names the bench she is waiting on');
    }

    public function test_an_unblocked_task_carries_no_padlock(): void
    {
        $po       = $this->order();
        $cutter   = $this->tailor();
        $stitcher = $this->tailor();

        $this->task($po, $this->stage('cut-g', 'Cutting', 1), $cutter, 1, 'completed');
        $mine = $this->task($po, $this->stage('stitch-g', 'Stitching', 2), $stitcher, 2);

        Sanctum::actingAs($stitcher);

        $row = $this->myTasks()->firstWhere('id', $mine->id);

        $this->assertNotNull($row, 'her task is on the list at all');
        $this->assertNull(data_get($row, 'blocked_by_stage'));
    }

    /**
     * The boundary itself must not move: reading the pipeline to answer "am I
     * blocked" is not permission to see other people's work.
     */
    public function test_reading_the_pipeline_does_not_widen_what_she_can_list(): void
    {
        $po       = $this->order();
        $cutter   = $this->tailor();
        $stitcher = $this->tailor();

        $cutting = $this->task($po, $this->stage('cut-g', 'Cutting', 1), $cutter, 1);
        $mine    = $this->task($po, $this->stage('stitch-g', 'Stitching', 2), $stitcher, 2);

        Sanctum::actingAs($stitcher);

        $ids = $this->myTasks()->pluck('id');

        $this->assertTrue($ids->contains($mine->id), 'her own task is listed');
        $this->assertFalse($ids->contains($cutting->id),
            "the cutter's task is still not hers to see");
        $this->assertFalse(ProductionTask::pluck('id')->contains($cutting->id));
    }
}
