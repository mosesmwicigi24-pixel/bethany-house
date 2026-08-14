<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A tailor's TASK list shows their own work, not the shop's.
 *
 * Production orders were already scoped: ProductionOrder::visibleTo() bounds a
 * worker to orders they have a task on or are an assignee of, and
 * ProductionController applies it at six sites. That rule is not duplicated
 * here — a second definition of "which production orders may I see" is exactly
 * the drift this programme has been removing.
 *
 * The gap visibleTo does not cover is the TASK list. allTasks starts at
 * `ProductionTask::with([...])` with no bound at all, and its `tailor_id` is a
 * client-supplied FILTER, not a boundary — the same shape as the `outlet_id`
 * filter on the orders list. So all nine tailors could list every task in the
 * business, with the customer and order attached.
 *
 * A task is owned by ASSIGNMENT, not creation: production_tasks has no
 * created_by, and scoping it that way would have shown every tailor nothing.
 */
class TailorOwnWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function stage(): ProductionStage
    {
        return ProductionStage::firstOrCreate(
            ['slug' => 'cutting-t'],
            ['name' => 'Cutting', 'sort_order' => 1, 'is_active' => true],
        );
    }

    private function productionOrder(): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'PO-' . fake()->unique()->numerify('######'),
            'product_id'   => Product::factory()->create()->id,
            'status'       => 'in_progress',
            'quantity'     => 1,
        ]);
    }

    private function taskOn(ProductionOrder $po, ?User $assignee): ProductionTask
    {
        return ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $po->id,
            'production_stage_id' => $this->stage()->id,
            'assigned_to'         => $assignee?->id,
            'status'              => 'pending',
        ]);
    }

    public function test_a_tailor_sees_only_the_tasks_assigned_to_them(): void
    {
        $mine   = $this->productionOrder();
        $theirs = $this->productionOrder();

        $tailor  = $this->actAs('tailor');
        $myTask  = $this->taskOn($mine, $tailor);
        $notMine = $this->taskOn($theirs, User::factory()->create());

        $ids = ProductionTask::pluck('id');

        $this->assertTrue($ids->contains($myTask->id));
        $this->assertFalse($ids->contains($notMine->id), "another tailor's task must not appear");
    }

    public function test_an_unassigned_task_is_not_anyones(): void
    {
        $po = $this->productionOrder();
        $this->actAs('tailor');
        $orphan = $this->taskOn($po, null);

        $this->assertFalse(ProductionTask::pluck('id')->contains($orphan->id));
    }

    public function test_a_task_is_owned_by_assignment_not_creation(): void
    {
        // The design point, asserted rather than described. production_tasks has
        // no created_by; scoping on the default column would have shown every
        // tailor an empty screen, which reads as a broken system.
        $this->assertSame('assigned_to', (new ProductionTask)->ownerColumn());
    }

    // ── The paths that must not narrow ───────────────────────────────────────

    public function test_a_coordinator_still_sees_every_task(): void
    {
        $po = $this->productionOrder();
        $a  = $this->taskOn($po, User::factory()->create());
        $b  = $this->taskOn($po, User::factory()->create());

        $this->actAs('admin');

        $ids = ProductionTask::pluck('id');
        $this->assertTrue($ids->contains($a->id) && $ids->contains($b->id));
    }

    public function test_code_without_a_session_is_not_narrowed(): void
    {
        $po = $this->productionOrder();
        $this->taskOn($po, User::factory()->create());
        $this->taskOn($po, User::factory()->create());

        $this->assertNull(auth()->user());
        $this->assertSame(2, ProductionTask::count());
    }

    public function test_the_escape_hatch_still_sees_everything(): void
    {
        $po = $this->productionOrder();
        $this->actAs('tailor');
        $this->taskOn($po, User::factory()->create());

        $this->assertSame(0, ProductionTask::count());
        $this->assertSame(1, ProductionTask::withoutViewerScope()->count());
    }

    public function test_tailor_ships_scoped_to_own(): void
    {
        $this->assertSame(
            'own',
            DB::table('roles')->where('name', 'tailor')->value('data_scope'),
        );
    }
}
