<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductTranslation;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PosInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Online / storefront orders inside the stock reservation model.
 *
 * OrderController::checkout() deducted quantity_on_hand at order creation but
 * stamped none of stock_reserved_at / stock_committed_at / stock_unwound_at, so
 * every order it produced claimed it had never drawn stock. void/cancel then
 * either stranded the deducted units (unwind saw "not committed" and released a
 * reservation that was never taken) or — via the old blind adjustQuantity(+qty)
 * in voidOrder()/cancelOrder() — invented stock on orders that never deducted
 * any, and again on every subsequent void/cancel.
 *
 * These tests pin the contract from both ends: an order that DID draw stock gets
 * it back exactly once, and an order that did NOT draw stock gets nothing back.
 */
class OnlineOrderStockFlagsTest extends TestCase
{
    use RefreshDatabase;

    private function signInWith(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** A published product with one variant, a KES price and stock on the shelf. */
    private function stockedVariant(int $onHand = 10): array
    {
        $product = Product::factory()->create([
            'status'       => 'active',
            'published_at' => now()->subDay(),
        ]);
        ProductTranslation::create([
            'product_id'    => $product->id,
            'language_code' => 'en',
            'name'          => 'Chasuble',
        ]);

        $variant = ProductVariant::create([
            'product_id'   => $product->id,
            'sku'          => 'SKU-' . $product->id,
            'variant_name' => 'Medium',
            'attributes'   => ['size' => 'M'],
            'is_active'    => true,
        ]);
        ProductPrice::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant->id,
            'currency_code'      => 'KES',
            'regular_price'      => 1500,
        ]);

        $inventory = InventoryItem::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant->id,
            'outlet_id'          => null,
            'quantity_on_hand'   => $onHand,
            'quantity_reserved'  => 0,
            'reorder_point'      => 0,
        ]);

        return [$product, $variant, $inventory];
    }

    /**
     * Seed the authenticated cart checkout() reads.
     *
     * NOTE the two tables: the Cart model maps to `carts`, while cart_items.cart_id
     * carries a foreign key to `shopping_carts`. A cart row therefore needs a
     * shopping_carts row of the same id to hold items at all. That schema split is
     * pre-existing and not what these tests are about — this helper just satisfies
     * it so checkout() can be driven end to end.
     */
    private function seedCart(User $user, ProductVariant $variant, int $qty): void
    {
        $cartId = DB::table('carts')->insertGetId([
            'user_id'       => $user->id,
            'cart_type'     => 'shopping',
            'currency_code' => 'KES',
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('shopping_carts')->insert([
            'id'            => $cartId,
            'user_id'       => $user->id,
            'currency_code' => 'KES',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('cart_items')->insert([
            'cart_id'            => $cartId,
            'product_id'         => $variant->product_id,
            'product_variant_id' => $variant->id,
            'quantity'           => $qty,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** An order in the shape checkout() now produces: stock gone, committed. */
    private function committedOnlineOrder(
        ProductVariant $variant,
        InventoryItem $inventory,
        int $qty,
        string $status = 'pending',
    ): Order {
        $order = Order::factory()->create([
            'order_type' => 'online',
            'status'     => $status,
            'outlet_id'  => null,
        ]);

        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $variant->product_id,
            'product_variant_id' => $variant->id,
            'inventory_item_id'  => $inventory->id,
            'sku'                => 'SKU-LINE',
            'product_name'       => 'Chasuble',
            'quantity'           => $qty,
            'unit_price'         => 1500,
            'total_price'        => 1500 * $qty,
        ]);

        // The sale itself: the physical count moves and the order records that.
        $inventory->adjustQuantity(-$qty, 'sale', Order::class, $order->id, null);
        $order->forceFill(['stock_committed_at' => now()])->save();

        return $order->fresh('items');
    }

    private function onHand(InventoryItem $inventory): int
    {
        return (int) $inventory->fresh()->quantity_on_hand;
    }

    // ── checkout() ────────────────────────────────────────────────────────────

    public function test_checkout_stamps_stock_committed_at_when_it_deducts_the_shelf(): void
    {
        [$product, $variant, $inventory] = $this->stockedVariant(onHand: 10);
        $user   = $this->signInWith([]);
        $outlet = Outlet::factory()->create();
        $this->seedCart($user, $variant, qty: 3);

        $res = $this->postJson('/api/v1/checkout', [
            'delivery_method'    => 'pickup',
            'pickup_location_id' => $outlet->id,
            'payment_method'     => 'cash_on_delivery',
        ]);

        $res->assertStatus(201);
        $order = Order::findOrFail($res->json('order.id'));

        // The goods left the shelf …
        $this->assertSame(7, $this->onHand($inventory));
        // … and the order says so, in the "committed, never reserved" shape the
        // rest of the reservation model already understands.
        $this->assertNotNull($order->stock_committed_at);
        $this->assertNull($order->stock_reserved_at);
        $this->assertNull($order->stock_unwound_at);

        // The line pins the exact row it drew from, so an unwind cannot guess.
        $this->assertSame($inventory->id, (int) $order->items->first()->inventory_item_id);
        $this->assertSame($variant->id, (int) $order->items->first()->product_variant_id);
    }

    // ── void ──────────────────────────────────────────────────────────────────

    public function test_voiding_an_online_order_restores_the_stock_exactly_once(): void
    {
        [$product, $variant, $inventory] = $this->stockedVariant(onHand: 10);
        $order = $this->committedOnlineOrder($variant, $inventory, qty: 4);
        $this->assertSame(6, $this->onHand($inventory));

        $this->signInWith(['orders.view', 'orders.cancel']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/void", [
            'reason' => 'Duplicate order',
        ])->assertStatus(200);

        $this->assertSame(10, $this->onHand($inventory));
        $order->refresh();
        $this->assertSame('voided', $order->status);
        $this->assertNotNull($order->stock_unwound_at);

        // A second unwind — a reap, a retry, a second operator — adds nothing.
        PosInventoryService::unwindForOrder($order->fresh('items'));
        $this->assertSame(10, $this->onHand($inventory));

        $this->assertSame(
            1,
            InventoryTransaction::where('reference_type', Order::class)
                ->where('reference_id', $order->id)
                ->where('transaction_type', 'void_return')
                ->count(),
            'exactly one restore should ever be ledgered for this order',
        );
    }

    public function test_voiding_a_guest_storefront_order_restores_nothing(): void
    {
        // StorefrontCheckoutController deliberately moves no stock, so all three
        // flags are null. The old void restocked every line regardless and
        // conjured units that had never left.
        [$product, $variant, $inventory] = $this->stockedVariant(onHand: 10);

        $order = Order::factory()->create([
            'order_type' => 'online',
            'status'     => 'pending',
            'outlet_id'  => null,
        ]);
        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $variant->product_id,
            'product_variant_id' => $variant->id,
            'sku'                => 'SKU-LINE',
            'product_name'       => 'Chasuble',
            'quantity'           => 4,
            'unit_price'         => 1500,
            'total_price'        => 6000,
        ]);

        $this->signInWith(['orders.view', 'orders.cancel']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/void", [
            'reason' => 'Customer changed their mind',
        ])->assertStatus(200);

        $this->assertSame(10, $this->onHand($inventory));
        $this->assertNotNull($order->fresh()->stock_unwound_at);
    }

    public function test_an_order_cancelled_by_status_change_then_voided_restores_once(): void
    {
        // The admin status dropdown, the customer cancel button and the admin
        // void button are three doors onto the same order. Whichever runs first
        // returns the stock; the rest must add nothing.
        [$product, $variant, $inventory] = $this->stockedVariant(onHand: 10);
        $order = $this->committedOnlineOrder($variant, $inventory, qty: 4);

        $this->signInWith(['orders.view', 'orders.edit', 'orders.cancel']);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'cancelled',
        ])->assertStatus(200);

        $this->assertSame(10, $this->onHand($inventory));
        $this->assertNotNull($order->fresh()->stock_unwound_at);

        // A void on top of it is rejected by the status guard, and a direct
        // second unwind is a no-op — either way the units come back only once.
        PosInventoryService::unwindForOrder($order->fresh('items'));
        $this->assertSame(10, $this->onHand($inventory));
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function test_cancelling_an_online_order_restores_the_stock_exactly_once(): void
    {
        [$product, $variant, $inventory] = $this->stockedVariant(onHand: 10);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $order = $this->committedOnlineOrder($variant, $inventory, qty: 4);
        $order->forceFill(['user_id' => $user->id])->save();
        $this->assertSame(6, $this->onHand($inventory));

        $this->postJson("/api/v1/customer/orders/{$order->id}/cancel")
            ->assertStatus(200);

        $this->assertSame(10, $this->onHand($inventory));
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->stock_unwound_at);

        PosInventoryService::unwindForOrder($order->fresh('items'));
        $this->assertSame(10, $this->onHand($inventory));
    }

    public function test_cancelling_an_order_that_never_drew_stock_restores_nothing(): void
    {
        [$product, $variant, $inventory] = $this->stockedVariant(onHand: 10);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $order = Order::factory()->create([
            'order_type' => 'online',
            'status'     => 'pending',
            'user_id'    => $user->id,
            'outlet_id'  => null,
        ]);
        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $variant->product_id,
            'product_variant_id' => $variant->id,
            'sku'                => 'SKU-LINE',
            'product_name'       => 'Chasuble',
            'quantity'           => 4,
            'unit_price'         => 1500,
            'total_price'        => 6000,
        ]);

        $this->postJson("/api/v1/customer/orders/{$order->id}/cancel")
            ->assertStatus(200);

        $this->assertSame(10, $this->onHand($inventory));
    }
}
