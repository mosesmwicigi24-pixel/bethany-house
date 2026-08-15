<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PosInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026_08_12_130000_clear_phantom_committed_stock_flags.
 *
 * 2026_07_11_120000 stamped stock_committed_at on every order in the table on the
 * assumption that all of them had deducted on_hand at creation. Orders that never
 * moved a unit — guest storefront orders above all — were left claiming their
 * goods had left the shelf, so a void handed the warehouse stock it never gave up.
 *
 * These tests seed that phantom state, run up(), and inspect the rows.
 */
class PhantomCommittedFlagMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__
        . '/../../database/migrations/2026_08_12_130000_clear_phantom_committed_stock_flags.php';

    private function runFix(): void
    {
        (require self::MIGRATION)->up();
    }

    private function inventory(int $onHand = 10, int $reserved = 0): InventoryItem
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::create([
            'product_id'   => $product->id,
            'sku'          => 'SKU-' . $product->id,
            'variant_name' => 'Medium',
            'attributes'   => ['size' => 'M'],
            'is_active'    => true,
        ]);

        return InventoryItem::create([
            'product_id'         => $product->id,
            'product_variant_id' => $variant->id,
            'outlet_id'          => null,
            'quantity_on_hand'   => $onHand,
            'quantity_reserved'  => $reserved,
            'reorder_point'      => 0,
        ]);
    }

    /** An order with lines, and whatever flags the back-fill would have left. */
    private function order(InventoryItem $inv, int $qty = 4, array $flags = []): Order
    {
        $order = Order::factory()->create([
            'order_type' => 'online',
            'status'     => 'pending',
            'outlet_id'  => null,
        ]);

        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $inv->product_id,
            'product_variant_id' => $inv->product_variant_id,
            'sku'                => 'SKU-LINE',
            'product_name'       => 'Chasuble',
            'quantity'           => $qty,
            'unit_price'         => 1500,
            'total_price'        => 1500 * $qty,
        ]);

        if ($flags) {
            $order->forceFill($flags)->save();
        }

        return $order->fresh('items');
    }

    public function test_it_clears_the_flag_on_an_order_that_never_drew_a_unit(): void
    {
        // A guest storefront order that predates the reservation model: no
        // inventory movement anywhere, but the back-fill called it committed.
        $inv   = $this->inventory(onHand: 10);
        $order = $this->order($inv, flags: ['stock_committed_at' => now()->subMonth()]);

        $this->runFix();

        $order->refresh();
        $this->assertNull($order->stock_committed_at);
        $this->assertNull($order->stock_reserved_at);
        $this->assertNull($order->stock_unwound_at);
    }

    public function test_voiding_a_repaired_order_no_longer_invents_stock(): void
    {
        $inv   = $this->inventory(onHand: 10);
        $order = $this->order($inv, qty: 4, flags: ['stock_committed_at' => now()->subMonth()]);

        // Before the fix the phantom flag makes unwind restore four units that
        // never left.
        PosInventoryService::unwindForOrder($order);
        $this->assertSame(14, (int) $inv->fresh()->quantity_on_hand, 'the bug');

        // Same order, repaired. (Reset the row through the query builder — the
        // unwind moved it behind this instance's back, so an Eloquent save of
        // the unchanged in-memory 10 would be a no-op.)
        InventoryItem::where('id', $inv->id)->update(['quantity_on_hand' => 10]);
        $order->forceFill([
            'stock_committed_at' => now()->subMonth(),
            'stock_unwound_at'   => null,
        ])->save();

        $this->runFix();

        PosInventoryService::unwindForOrder($order->fresh('items'));
        $this->assertSame(10, (int) $inv->fresh()->quantity_on_hand);
    }

    public function test_unwinding_a_repaired_order_does_not_steal_another_orders_reservation(): void
    {
        // Another pending order is holding six units on this row.
        $inv   = $this->inventory(onHand: 10, reserved: 6);
        $order = $this->order($inv, qty: 4, flags: ['stock_committed_at' => now()->subMonth()]);

        $this->runFix();
        PosInventoryService::unwindForOrder($order->fresh('items'));

        $inv->refresh();
        $this->assertSame(10, (int) $inv->quantity_on_hand);
        $this->assertSame(6, (int) $inv->quantity_reserved, 'the other order still holds its six');
        $this->assertNotNull($order->fresh()->stock_unwound_at);
    }

    public function test_it_leaves_an_order_that_really_did_draw_stock_alone(): void
    {
        $inv   = $this->inventory(onHand: 10);
        $order = $this->order($inv, qty: 4);
        $inv->adjustQuantity(-4, 'sale', Order::class, $order->id, null);
        $committedAt = now()->subMonth();
        $order->forceFill(['stock_committed_at' => $committedAt])->save();

        $this->runFix();

        $order->refresh();
        $this->assertNotNull($order->stock_committed_at);
        $this->assertSame(
            $committedAt->format('Y-m-d H:i:s'),
            $order->stock_committed_at->format('Y-m-d H:i:s'),
        );

        // And it still gives the four units back.
        PosInventoryService::unwindForOrder($order->fresh('items'));
        $this->assertSame(10, (int) $inv->fresh()->quantity_on_hand);
    }

    public function test_it_leaves_a_reserved_order_alone(): void
    {
        // reserved + committed is the new model's own bookkeeping, not the
        // back-fill's guess, so it is out of scope even with no ledger row yet.
        $inv   = $this->inventory(onHand: 10);
        $order = $this->order($inv, flags: [
            'stock_reserved_at'  => now()->subDay(),
            'stock_committed_at' => now(),
        ]);

        $this->runFix();

        $this->assertNotNull($order->fresh()->stock_committed_at);
    }

    public function test_a_return_transaction_is_not_mistaken_for_a_draw(): void
    {
        // Only NEGATIVE movement proves the goods left. A positive row (a return,
        // a restock) against the order must not keep the phantom flag alive.
        $inv   = $this->inventory(onHand: 10);
        $order = $this->order($inv, flags: ['stock_committed_at' => now()->subMonth()]);
        $inv->adjustQuantity(2, 'return', Order::class, $order->id, null);

        $this->runFix();

        $this->assertNull($order->fresh()->stock_committed_at);
    }

    public function test_it_is_idempotent(): void
    {
        $inv   = $this->inventory(onHand: 10);
        $order = $this->order($inv, flags: ['stock_committed_at' => now()->subMonth()]);

        $this->runFix();
        $this->runFix();

        $this->assertNull($order->fresh()->stock_committed_at);
    }
}
