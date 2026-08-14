<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PATCH /api/v1/admin/pos/pending-order/{id} — stock reconciliation on edit.
 *
 * Editing a pending sale returns whatever the old lines were holding and then
 * takes a fresh hold for the new ones. What "holding" means is read off the
 * order's reservation flags, and getting that wrong moves other people's stock:
 *
 *   reserved            → release the reservation (physical count untouched)
 *   committed, never    → the old model deducted on_hand, so restore it and drop
 *     reserved            the committed flag; the edit re-enters the new model
 *   neither             → the order holds NOTHING. Releasing its quantities here
 *                         would decrement quantity_reserved out of reservations
 *                         belonging to OTHER pending orders on the same row.
 *
 * That last case is reachable for guest storefront orders and for any order whose
 * phantom committed flag 2026_08_12_130000 cleared.
 */
class PosPendingOrderEditStockTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::factory()->create();

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
        $user->outlets()->attach($this->outlet->id);
    }

    /** A variant with its own inventory row in this outlet. */
    private function stockedVariant(int $onHand = 10, int $reserved = 0): ProductVariant
    {
        $product = Product::factory()->create();
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

        InventoryItem::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant->id,
            'outlet_id'          => $this->outlet->id,
            'quantity_on_hand'   => $onHand,
            'quantity_reserved'  => $reserved,
            'reorder_point'      => 0,
        ]);

        return $variant;
    }

    private function inventoryFor(ProductVariant $variant): InventoryItem
    {
        return InventoryItem::where('product_variant_id', $variant->id)->firstOrFail();
    }

    /** A pending, unpaid POS order with one line and the given flags. */
    private function pendingOrder(ProductVariant $variant, int $qty, array $flags): Order
    {
        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'outlet_id'      => $this->outlet->id,
        ]);

        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $variant->product_id,
            'product_variant_id' => $variant->id,
            'sku'                => $variant->sku,
            'product_name'       => 'Chasuble',
            'quantity'           => $qty,
            'unit_price'         => 1500,
            'total_price'        => 1500 * $qty,
        ]);

        $order->forceFill($flags)->save();

        return $order->fresh('items');
    }

    private function editTo(Order $order, ProductVariant $variant, int $qty)
    {
        return $this->patchJson("/api/v1/admin/pos/pending-order/{$order->id}", [
            'items' => [
                ['variant_id' => $variant->id, 'quantity' => $qty, 'unit_price' => 1500],
            ],
        ]);
    }

    private function assertStock(ProductVariant $variant, int $onHand, int $reserved): void
    {
        $inv = $this->inventoryFor($variant);
        $this->assertSame($onHand, (int) $inv->quantity_on_hand, 'quantity_on_hand');
        $this->assertSame($reserved, (int) $inv->quantity_reserved, 'quantity_reserved');
    }

    public function test_editing_an_order_that_holds_nothing_does_not_steal_another_orders_reservation(): void
    {
        // Another pending sale is holding six of the ten units.
        $variant = $this->stockedVariant(onHand: 10, reserved: 6);

        // This order's flags are all null — it is holding nothing at all.
        $order = $this->pendingOrder($variant, qty: 3, flags: []);

        $this->editTo($order, $variant, qty: 2)->assertStatus(200);

        // Nothing was returned (there was nothing to return) and the new line
        // took its own two — the other sale's six are untouched.
        $this->assertStock($variant, onHand: 10, reserved: 8);
        $this->assertNotNull($order->fresh()->stock_reserved_at, 'the edit re-enters the model');
    }

    public function test_editing_a_reserved_order_releases_the_old_hold_and_takes_a_new_one(): void
    {
        $variant = $this->stockedVariant(onHand: 10, reserved: 0);
        $order   = $this->pendingOrder($variant, qty: 3, flags: ['stock_reserved_at' => now()->subHour()]);
        $this->inventoryFor($variant)->reserveUnits(3);
        $this->assertStock($variant, onHand: 10, reserved: 3);

        $this->editTo($order, $variant, qty: 5)->assertStatus(200);

        // The three came back, the five went out; the shelf never moved.
        $this->assertStock($variant, onHand: 10, reserved: 5);
    }

    public function test_editing_a_legacy_committed_order_restores_the_shelf_and_rejoins_the_model(): void
    {
        // The old model deducted on_hand at creation; 2026_07_11 marked it
        // committed with no reservation.
        $variant = $this->stockedVariant(onHand: 7, reserved: 0);
        $order   = $this->pendingOrder($variant, qty: 3, flags: ['stock_committed_at' => now()->subDay()]);

        $this->editTo($order, $variant, qty: 2)->assertStatus(200);

        // The three deducted units go back on the shelf and the new line only
        // reserves — the physical count is honest again.
        $this->assertStock($variant, onHand: 10, reserved: 2);

        $order->refresh();
        $this->assertNull($order->stock_committed_at, 'the committed flag is dropped');
        $this->assertNotNull($order->stock_reserved_at);
    }
}
