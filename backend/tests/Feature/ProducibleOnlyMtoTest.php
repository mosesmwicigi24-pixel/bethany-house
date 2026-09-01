<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "MTO · Send to production" belongs only on things we can actually MAKE.
 *
 * The owner's rule: if a product is not marked producible at the hub, that
 * action must not show. It was showing on every line of every paid order —
 * including Communion Wafer Bread, a bought-in consumable, where a workshop
 * task is a job nobody can complete.
 *
 * Hiding the button is UX; refusing the endpoint is the contract, because the
 * route is reachable directly.
 */
class ProducibleOnlyMtoTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach (['orders.view', 'production.raise_order'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }

        return $user;
    }

    private function orderWithItem(bool $producible): array
    {
        $product = Product::factory()->create([
            'status'        => Product::STATUS_ACTIVE,
            'is_producible' => $producible,
        ]);
        $order = Order::factory()->create([
            'status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'total_amount' => 1000,
        ]);
        $item = OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $product->id,
            'product_variant_id' => null,
            'product_name'       => $producible ? 'Cassock' : 'Communion Wafer Bread 200PCs',
            'sku'                => $producible ? 'CAS-1' : 'CWIZIB1ZL',
            'quantity'           => 1,
            'unit_price'         => 1000,
            'total_price'        => 1000,
        ]);

        return [$order, $item];
    }

    public function test_the_payload_marks_a_producible_line(): void
    {
        [$order] = $this->orderWithItem(true);

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.items.0.is_producible', true);
    }

    public function test_the_payload_marks_a_bought_in_line_as_not_producible(): void
    {
        // Wafer bread: we buy it, we do not make it.
        [$order] = $this->orderWithItem(false);

        $this->actingAs($this->staff(), 'sanctum')
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.items.0.is_producible', false);
    }

    public function test_production_is_refused_for_a_non_producible_product(): void
    {
        [$order, $item] = $this->orderWithItem(false);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production", [])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_producible');
    }

    public function test_production_is_allowed_for_a_producible_product(): void
    {
        [$order, $item] = $this->orderWithItem(true);

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production", [])
            ->assertSuccessful();
    }
}
