<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3: authorized dispatch. Only holders of orders.authorize_dispatch can
 * release a paid POS sale for hand-over; doing so marks it dispatched and moves
 * its serials sold → dispatched.
 */
class ProductSerialDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $perms): void
    {
        $user = User::factory()->create();
        foreach ($perms as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function paidOrderWithSoldSerials(int $count): Order
    {
        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();
        $order   = Order::factory()->create([
            'order_type'     => 'pos',
            'outlet_id'      => $outlet->id,
            'status'         => 'confirmed',
            'payment_status' => 'paid',
        ]);
        for ($i = 1; $i <= $count; $i++) {
            ProductSerial::create([
                'serial_number' => "SN-{$order->id}-{$i}",
                'product_id'    => $product->id,
                'order_id'      => $order->id,
                'status'        => ProductSerial::SOLD,
                'sold_at'       => now(),
            ]);
        }
        return $order;
    }

    public function test_an_authorizer_dispatches_and_flips_serials(): void
    {
        $this->actingWith(['pos.access', 'orders.authorize_dispatch']);
        $order = $this->paidOrderWithSoldSerials(2);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/dispatch")->assertOk();

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->dispatched_at);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(2, ProductSerial::where('order_id', $order->id)
            ->where('status', ProductSerial::DISPATCHED)->count());
    }

    public function test_a_non_authorizer_is_blocked(): void
    {
        $this->actingWith(['pos.access']);   // no orders.authorize_dispatch
        $order = $this->paidOrderWithSoldSerials(1);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/dispatch")->assertStatus(403);

        $this->assertNull($order->fresh()->dispatched_at);
        $this->assertSame(1, ProductSerial::where('order_id', $order->id)
            ->where('status', ProductSerial::SOLD)->count());
    }

    public function test_cannot_dispatch_an_unpaid_order(): void
    {
        $this->actingWith(['pos.access', 'orders.authorize_dispatch']);
        $outlet = Outlet::factory()->create();
        $order  = Order::factory()->create([
            'order_type'     => 'pos',
            'outlet_id'      => $outlet->id,
            'status'         => 'processing',
            'payment_status' => 'partial',
        ]);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/dispatch")->assertStatus(422);
    }

    public function test_cannot_dispatch_twice(): void
    {
        $this->actingWith(['pos.access', 'orders.authorize_dispatch']);
        $order = $this->paidOrderWithSoldSerials(1);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/dispatch")->assertOk();
        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/dispatch")->assertStatus(422);
    }
}
