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
 * "Generate Tasks from Active Stages" on an order with no tasks posted to a
 * route whose method did not exist, so the button always failed. It now
 * delegates to ProductionOrder::seedTasks: missing stages are added, existing
 * tasks are kept, and orders off the floor are refused.
 */
class ProductionRegenerateTasksTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $status): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'PRD-REGEN-' . uniqid(), 'product_id' => Product::factory()->create()->id,
            'status' => $status, 'quantity' => 2, 'due_date' => now()->addDays(4)->toDateString(),
        ]);
    }

    private function tasks(ProductionOrder $o)
    {
        return ProductionTask::withoutViewerScope()->where('production_order_id', $o->id)->orderBy('sequence')->get();
    }

    public function test_it_adds_the_missing_stages_once_and_refuses_orders_off_the_floor(): void
    {
        Artisan::call('permission:sync');
        $manager = User::factory()->create();
        $manager->assignRole(Role::findByName('production_manager', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($manager);

        $cut    = ProductionStage::create(['name' => 'Cutting', 'slug' => 'cut-regen', 'sort_order' => 1, 'is_active' => true]);
        $stitch = ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitch-regen', 'sort_order' => 2, 'is_active' => true]);
        ProductionStage::create(['name' => 'Retired', 'slug' => 'old-regen', 'sort_order' => 3, 'is_active' => false]);

        $order = $this->order('pending');
        $this->postJson("/api/v1/admin/production-orders/{$order->id}/regenerate-tasks")
            ->assertOk()->assertJsonPath('added', 2);
        $this->assertSame([$cut->id, $stitch->id], $this->tasks($order)->pluck('production_stage_id')->all());
        $this->assertSame([1, 2], $this->tasks($order)->pluck('sequence')->map(fn ($s) => (int) $s)->all());

        // Again: nothing duplicated.
        $this->postJson("/api/v1/admin/production-orders/{$order->id}/regenerate-tasks")
            ->assertOk()->assertJsonPath('added', 0);
        $this->assertCount(2, $this->tasks($order));

        foreach (['draft', 'completed', 'cancelled'] as $status) {
            $off = $this->order($status);
            $this->postJson("/api/v1/admin/production-orders/{$off->id}/regenerate-tasks")->assertStatus(422);
            $this->assertCount(0, $this->tasks($off));
        }
    }
}
