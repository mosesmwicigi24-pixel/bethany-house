<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A paid order from the quote→invoice→receipt flow can send a line to production
 * (the POS-style MTO toggle it never had): raises a production order for that
 * line, captures measurements + colour, and won't double-raise.
 */
class OrderRaiseProductionTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user = User::factory()->create();
        foreach (['orders.view', 'production.raise_order'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
        return $user;
    }

    private function paidOrderWithItem(int $qty = 2): array
    {
        $order = Order::factory()->create([
            'status'         => 'processing',
            'payment_status' => 'paid',
        ]);
        $product = Product::factory()->create();
        $item = OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $product->id,
            'product_name' => 'Preaching Gown',
            'sku'          => 'GOWN-1',
            'quantity'     => $qty,
            'unit_price'   => 130,
        ]);
        return [$order, $item];
    }

    public function test_raising_production_creates_a_po_with_measurements_and_colour(): void
    {
        $this->actor();
        [$order, $item] = $this->paidOrderWithItem(2);

        $res = $this->postJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production", [
            'measurements' => ['Chest' => '42 in', 'Sleeve' => '25 in'],
            'color'        => 'Purple',
            'notes'        => 'Ordination set',
        ])->assertOk();

        $poId = $res->json('production_order_id');
        $this->assertNotNull($poId);

        $po = DB::table('production_orders')->where('id', $poId)->first();
        $this->assertSame(2, (int) $po->quantity);                 // line quantity
        $this->assertSame($order->id, (int) $po->customer_order_id);
        $this->assertSame($item->id, (int) $po->order_item_id);

        $meas = json_decode($po->measurements, true);
        $this->assertSame('Purple', $meas['Colour']);              // colour folded in
        $this->assertSame('42 in', $meas['Chest']);

        // The line is now marked in production.
        $freshItem = DB::table('order_items')->where('id', $item->id)->first();
        $this->assertSame($poId, (int) $freshItem->production_order_id);
        $this->assertTrue((bool) $freshItem->requires_production);
    }

    public function test_a_line_already_in_production_cannot_be_raised_again(): void
    {
        $this->actor();
        [$order, $item] = $this->paidOrderWithItem();

        $this->postJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production", [])->assertOk();

        $this->postJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production", [])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'already_in_production');

        $this->assertSame(1, DB::table('production_orders')->where('customer_order_id', $order->id)->count());
    }

    public function test_production_cannot_be_raised_on_a_cancelled_order(): void
    {
        $this->actor();
        [$order, $item] = $this->paidOrderWithItem();
        $order->update(['status' => 'cancelled']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production", [])
            ->assertStatus(422);
    }
}
