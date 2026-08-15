<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PosInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_08_12_120000_backfill_stock_flags_for_online_orders.
 *
 * The migration ran once against an empty schema during test setup, which proves
 * nothing about what it does. These tests seed the damaged state it exists to
 * repair — an order whose 'sale' transaction is on the ledger but whose three
 * reservation flags are all null — re-run up(), and inspect the rows.
 */
class StockFlagBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__
        . '/../../database/migrations/2026_08_12_120000_backfill_stock_flags_for_online_orders.php';

    private function runBackfill(): void
    {
        (require self::MIGRATION)->up();
    }

    private function inventory(int $onHand = 10): InventoryItem
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
            'quantity_reserved'  => 0,
            'reorder_point'      => 0,
        ]);
    }

    /**
     * An order exactly as the broken checkout() left it: stock deducted, a 'sale'
     * transaction on the ledger, all three flags null, and no inventory_item_id
     * pinned on the line.
     */
    private function brokenOrder(
        InventoryItem $inventory,
        int $qty = 4,
        string $status = 'pending',
        string $referenceType = Order::class,
    ): Order {
        $order = Order::factory()->create([
            'order_type' => 'online',
            'status'     => $status,
            'outlet_id'  => null,
        ]);

        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $inventory->product_id,
            'product_variant_id' => $inventory->product_variant_id,
            'sku'                => 'SKU-LINE',
            'product_name'       => 'Chasuble',
            'quantity'           => $qty,
            'unit_price'         => 1500,
            'total_price'        => 1500 * $qty,
        ]);

        $inventory->adjustQuantity(-$qty, 'sale', $referenceType, $order->id, null);
        // adjustQuantity does not touch the flags — that is the bug.
        $order->refresh();

        return $order;
    }

    public function test_it_stamps_committed_on_an_order_whose_stock_actually_left(): void
    {
        $inv   = $this->inventory(onHand: 10);
        $order = $this->brokenOrder($inv, qty: 4);

        $this->assertNull($order->stock_committed_at, 'precondition: the damage');

        $this->runBackfill();

        $order->refresh();
        $this->assertNotNull($order->stock_committed_at);
        $this->assertNull($order->stock_reserved_at, 'no reservation was ever held');
        $this->assertNull($order->stock_unwound_at, 'this order is still open');

        // Stamped when the stock actually moved, not when the migration ran.
        $saleAt = DB::table('inventory_transactions')
            ->where('reference_id', $order->id)
            ->where('transaction_type', 'sale')
            ->value('created_at');
        $this->assertSame(
            (string) $saleAt,
            $order->stock_committed_at->format('Y-m-d H:i:s'),
        );

        // The whole point: unwinding now restores the physical count.
        PosInventoryService::unwindForOrder($order->fresh('items'));
        $this->assertSame(10, (int) $inv->fresh()->quantity_on_hand);
    }

    public function test_it_pins_the_inventory_row_the_sale_drew_from(): void
    {
        $inv   = $this->inventory(onHand: 10);
        $order = $this->brokenOrder($inv, qty: 4);

        $this->assertNull($order->items()->first()->inventory_item_id);

        $this->runBackfill();

        $this->assertSame(
            $inv->id,
            (int) $order->items()->first()->inventory_item_id,
        );
    }

    public function test_an_already_restored_order_is_marked_unwound_and_not_restocked_again(): void
    {
        $inv   = $this->inventory(onHand: 10);
        $order = $this->brokenOrder($inv, qty: 4, status: 'cancelled');
        $this->assertSame(6, (int) $inv->fresh()->quantity_on_hand);

        // The old cancelOrder() put the units back with a bare adjustQuantity(+qty)
        // and recorded nothing on the order.
        $inv->adjustQuantity(4, 'cancellation', Order::class, $order->id, null);
        $this->assertSame(10, (int) $inv->fresh()->quantity_on_hand);

        $this->runBackfill();

        $order->refresh();
        $this->assertNotNull($order->stock_committed_at);
        $this->assertNotNull($order->stock_unwound_at, 'the stock is already back');

        // So a later unwind cannot hand the same four units back a second time.
        PosInventoryService::unwindForOrder($order->fresh('items'));
        $this->assertSame(10, (int) $inv->fresh()->quantity_on_hand);
    }

    public function test_it_catches_the_livewire_pos_reference_type_too(): void
    {
        // App\Http\Livewire\Admin\Pos\NewSale ledgers reference_type 'order'.
        $inv   = $this->inventory(onHand: 10);
        $order = $this->brokenOrder($inv, qty: 2, referenceType: 'order');

        $this->runBackfill();

        $this->assertNotNull($order->fresh()->stock_committed_at);
    }

    public function test_it_leaves_an_order_that_never_drew_stock_alone(): void
    {
        // A guest storefront order: items, no inventory movement, no ledger row.
        $inv   = $this->inventory(onHand: 10);
        $order = Order::factory()->create(['order_type' => 'online', 'status' => 'pending']);
        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $inv->product_id,
            'product_variant_id' => $inv->product_variant_id,
            'sku'                => 'SKU-LINE',
            'product_name'       => 'Chasuble',
            'quantity'           => 4,
            'unit_price'         => 1500,
            'total_price'        => 6000,
        ]);

        $this->runBackfill();

        $order->refresh();
        $this->assertNull($order->stock_committed_at);
        $this->assertNull($order->stock_reserved_at);
        $this->assertNull($order->stock_unwound_at);
    }

    public function test_it_leaves_orders_that_already_carry_a_flag_alone(): void
    {
        // A modern POS order mid-flight: reserved, not yet committed. It has no
        // 'sale' row yet, but even once it commits, its flags exclude it.
        $inv   = $this->inventory(onHand: 10);
        $order = $this->brokenOrder($inv, qty: 3);
        $reservedAt = now()->subDays(2);
        $order->forceFill(['stock_reserved_at' => $reservedAt])->save();

        $this->runBackfill();

        $order->refresh();
        $this->assertNull($order->stock_committed_at, 'a flagged order is not the damaged shape');
        $this->assertSame(
            $reservedAt->format('Y-m-d H:i:s'),
            $order->stock_reserved_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_it_is_idempotent(): void
    {
        $inv   = $this->inventory(onHand: 10);
        $order = $this->brokenOrder($inv, qty: 4);

        $this->runBackfill();
        $first = $order->fresh()->stock_committed_at;

        $this->runBackfill();
        $this->assertSame(
            $first->format('Y-m-d H:i:s'),
            $order->fresh()->stock_committed_at->format('Y-m-d H:i:s'),
        );
    }
}
