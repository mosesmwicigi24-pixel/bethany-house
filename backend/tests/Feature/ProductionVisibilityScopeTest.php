<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderAssignee;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role-scoped production visibility.
 *
 * ProductionController::index/show/schedule previously returned EVERY order to
 * anyone holding production.view — which the tailor role holds — so any tailor
 * could browse the entire order book, customers included. Orders are now scoped
 * server-side (ProductionOrder::visibleTo): a worker sees only orders they are
 * part of (an assigned task, or the order's assignee list); coordinators —
 * manage_assignees / raise_order / confirm_order / admin — see the whole floor.
 * Hiding the menu item is presentation; this is the gate.
 */
class ProductionVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    private function worker(): User
    {
        $user = User::factory()->create();
        // Exactly what a tailor holds: enough to reach the routes, no more.
        $user->givePermissionTo(Permission::findOrCreate('production.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{0: ProductionOrder, 1: ProductionOrder} mine, not-mine */
    private function twoOrders(User $me): array
    {
        $stage   = ProductionStage::create(['name' => 'Stitching', 'slug' => 'stitching', 'sort_order' => 1, 'is_active' => true]);
        $product = Product::factory()->create();

        $mine  = ProductionOrder::create(['order_number' => 'PRD-MINE',  'product_id' => $product->id, 'quantity' => 1, 'status' => 'in_progress']);
        $other = ProductionOrder::create(['order_number' => 'PRD-OTHER', 'product_id' => $product->id, 'quantity' => 1, 'status' => 'in_progress']);

        ProductionTask::create(['production_order_id' => $mine->id, 'production_stage_id' => $stage->id, 'status' => 'pending', 'assigned_to' => $me->id]);
        ProductionTask::create(['production_order_id' => $other->id, 'production_stage_id' => $stage->id, 'status' => 'pending']);

        return [$mine, $other];
    }

    public function test_a_worker_lists_only_orders_they_are_part_of(): void
    {
        $me = $this->worker();
        [$mine, $other] = $this->twoOrders($me);

        $response = $this->getJson('/api/v1/admin/production-orders');

        $response->assertOk();
        $numbers = collect($response->json('data'))->pluck('order_number');
        $this->assertTrue($numbers->contains('PRD-MINE'));
        $this->assertFalse($numbers->contains('PRD-OTHER'), 'A worker can see an order they are not part of.');
    }

    public function test_a_worker_cannot_open_an_unassigned_order_by_id(): void
    {
        $me = $this->worker();
        [, $other] = $this->twoOrders($me);

        // 404, deliberately — the scope makes it not exist for them, which also
        // avoids confirming the id is real.
        $this->getJson("/api/v1/admin/production-orders/{$other->id}")->assertStatus(404);
    }

    public function test_assignee_list_membership_also_grants_visibility(): void
    {
        $me = $this->worker();
        [, $other] = $this->twoOrders($me);

        ProductionOrderAssignee::create([
            'production_order_id' => $other->id,
            'user_id'             => $me->id,
            'role_in_order'       => 'helper',
        ]);

        $this->getJson("/api/v1/admin/production-orders/{$other->id}")->assertOk();
    }

    public function test_a_coordinator_still_sees_the_whole_floor(): void
    {
        $me = $this->worker();
        $me->givePermissionTo(Permission::findOrCreate('production.manage_assignees', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        [, $other] = $this->twoOrders($me);

        $response = $this->getJson('/api/v1/admin/production-orders');
        $numbers  = collect($response->json('data'))->pluck('order_number');
        $this->assertTrue($numbers->contains('PRD-OTHER'));
    }
}
