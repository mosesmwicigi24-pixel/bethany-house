<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Per-product stage templates + gated stage progression.
 *
 * A keyholder does not pass through Embroidery; a chasuble does. Products now
 * carry production_stage_ids (like measurements), orders seed their tasks from
 * that template, and tasks unlock strictly in sequence unless a production
 * manager explicitly allows a stage to run in parallel.
 */
class ProductionStageTemplateAndGatingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTailor(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{0: ProductionStage, 1: ProductionStage, 2: ProductionStage} */
    private function threeStages(): array
    {
        $mk = fn (string $name, int $sort) => ProductionStage::create([
            'name' => $name, 'slug' => strtolower($name) . '-' . $sort, 'sort_order' => $sort, 'is_active' => true,
        ]);

        return [$mk('Cutting', 1), $mk('Stitching', 2), $mk('Embroidery', 3)];
    }

    private function orderFor(Product $product): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'PRD-TEST-' . $product->id,
            'product_id'   => $product->id,
            'quantity'     => 1,
            'status'       => 'pending',
        ]);
    }

    // ── Template seeding ─────────────────────────────────────────────────────

    public function test_seed_tasks_honours_the_product_template(): void
    {
        [$cutting, , $embroidery] = $this->threeStages();

        // The template skips Stitching entirely — this product never needs it.
        $product = Product::factory()->create([
            'production_stage_ids' => [$embroidery->id, $cutting->id],
        ]);

        $order = $this->orderFor($product);
        $order->seedTasks();

        $tasks = $order->tasks()->orderBy('sequence')->with('stage')->get();

        $this->assertCount(2, $tasks);
        // Sequence follows the stage catalogue's order, not the array's:
        // Cutting (sort 1) before Embroidery (sort 3).
        $this->assertSame(['Cutting', 'Embroidery'], $tasks->pluck('stage.name')->all());
        $this->assertSame([1, 2], $tasks->pluck('sequence')->all());
    }

    public function test_a_product_without_a_template_gets_every_active_stage(): void
    {
        $this->threeStages();
        $product = Product::factory()->create(['production_stage_ids' => null]);

        $order = $this->orderFor($product);
        $order->seedTasks();

        $this->assertSame(3, $order->tasks()->count());
    }

    public function test_seeding_twice_does_not_duplicate_tasks(): void
    {
        $this->threeStages();
        $product = Product::factory()->create();
        $order   = $this->orderFor($product);

        // Both the create path and the confirm path call seedTasks — whichever
        // runs second must find the rows already there.
        $order->seedTasks();
        $order->seedTasks();

        $this->assertSame(3, $order->tasks()->count());
    }

    // ── Gating ───────────────────────────────────────────────────────────────

    /** @return array{0: ProductionTask, 1: ProductionTask} first and second task, both assigned to $user */
    private function seededPair(User $user): array
    {
        $this->threeStages();
        $product = Product::factory()->create();
        $order   = $this->orderFor($product);
        $order->seedTasks();

        $tasks = $order->tasks()->orderBy('sequence')->get();
        $order->tasks()->update(['assigned_to' => $user->id]);

        return [$tasks[0], $tasks[1]];
    }

    public function test_a_task_cannot_start_while_its_predecessor_is_unfinished(): void
    {
        $user = $this->actingAsTailor();
        [, $second] = $this->seededPair($user);

        $response = $this->putJson("/api/v1/admin/tailor/tasks/{$second->id}/status", ['action' => 'start']);

        $response->assertStatus(422);
        $response->assertJsonPath('blocked_by.stage', 'Cutting');
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_completing_the_predecessor_unblocks_the_next_stage(): void
    {
        $user = $this->actingAsTailor();
        [$first, $second] = $this->seededPair($user);

        $this->putJson("/api/v1/admin/tailor/tasks/{$first->id}/status", ['action' => 'start'])->assertOk();
        $this->putJson("/api/v1/admin/tailor/tasks/{$first->id}/status", ['action' => 'complete'])->assertOk();

        $this->putJson("/api/v1/admin/tailor/tasks/{$second->id}/status", ['action' => 'start'])->assertOk();
        $this->assertSame('in_progress', $second->fresh()->status);
    }

    public function test_complete_cannot_be_used_to_leapfrog_the_gate(): void
    {
        $user = $this->actingAsTailor();
        [, $second] = $this->seededPair($user);

        // 'complete' on a never-started task must hit the same wall as 'start' —
        // otherwise the gate is decorative.
        $this->putJson("/api/v1/admin/tailor/tasks/{$second->id}/status", ['action' => 'complete'])
            ->assertStatus(422);
    }

    public function test_a_manager_unlock_lets_the_stage_run_in_parallel(): void
    {
        $user = $this->actingAsTailor();
        [, $second] = $this->seededPair($user);

        // The tailor themselves cannot unlock — the route needs the manager
        // permission on top of the role.
        $this->postJson("/api/v1/admin/tailor/tasks/{$second->id}/unlock", [])->assertStatus(403);

        $user->givePermissionTo(Permission::findOrCreate('production.manage_assignees', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson("/api/v1/admin/tailor/tasks/{$second->id}/unlock", ['notes' => 'embroidery on separate pieces'])
            ->assertOk();

        $fresh = $second->fresh();
        $this->assertTrue($fresh->concurrent_allowed);
        $this->assertSame($user->id, $fresh->unlocked_by);

        // And now it starts even though Cutting is untouched.
        $this->putJson("/api/v1/admin/tailor/tasks/{$second->id}/status", ['action' => 'start'])->assertOk();
    }

    public function test_legacy_tasks_without_a_sequence_are_never_blocked(): void
    {
        $user = $this->actingAsTailor();
        [, $second] = $this->seededPair($user);

        // Rows that predate the snapshot (backfill missed them somehow) must
        // fail OPEN — a gate bug should never freeze the production floor.
        $second->update(['sequence' => null]);

        $this->putJson("/api/v1/admin/tailor/tasks/{$second->id}/status", ['action' => 'start'])->assertOk();
    }
}
