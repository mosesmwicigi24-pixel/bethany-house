<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Services\AbandonedOrderReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two-step POS deducts stock at pending-order creation. Abandoned orders
 * must give that stock back — this covers restore, the scheduled reap, and the
 * one-time backfill, and guards against ever double-restoring or touching money.
 */
class AbandonedOrderReaperTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Outlet $outlet;
    private InventoryItem $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::factory()->create();
        $this->outlet  = Outlet::factory()->create();
        $this->inventory = InventoryItem::create([
            'product_id'        => $this->product->id,
            'product_variant_id' => null,
            'outlet_id'         => $this->outlet->id,
            'quantity_on_hand'  => 8,
            'quantity_reserved' => 0,
            'reorder_point'     => 0,
        ]);
    }

    private function abandonedOrder(int $qty, string $status = 'pending', string $payStatus = 'pending', ?string $ageDays = '3'): Order
    {
        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'outlet_id'      => $this->outlet->id,
            'status'         => $status,
            'payment_status' => $payStatus,
            'total_amount'   => 100 * $qty,
        ]);
        // Timestamps are ignored by mass-assignment — backdate directly.
        DB::table('orders')->where('id', $order->id)
            ->update(['created_at' => now()->subDays((int) $ageDays)]);
        OrderItem::create([
            'order_id'       => $order->id,
            'product_id'     => $this->product->id,
            'product_variant_id' => null,
            'product_name'   => 'Test',
            'sku'            => 'TEST-SKU',
            'quantity'       => $qty,
            'unit_price'     => 100,
            'total_price'    => 100 * $qty,
        ]);
        return $order;
    }

    public function test_restore_gives_the_stock_back_and_is_idempotent(): void
    {
        $order = $this->abandonedOrder(2);

        $restored = AbandonedOrderReaper::restoreInventoryForOrder($order->id);
        $this->assertSame(2, $restored);
        $this->assertSame(10, (int) $this->inventory->fresh()->quantity_on_hand);

        // Second run must not restore again.
        $this->assertSame(0, AbandonedOrderReaper::restoreInventoryForOrder($order->id));
        $this->assertSame(10, (int) $this->inventory->fresh()->quantity_on_hand);
    }

    public function test_reap_cancels_restores_and_releases_serials(): void
    {
        $order = $this->abandonedOrder(2);
        $serial = ProductSerial::create([
            'serial_number' => 'SN-REAP-1',
            'product_id'    => $this->product->id,
            'order_id'      => $order->id,
            'status'        => ProductSerial::SOLD,
            'sold_at'       => now(),
        ]);

        $result = AbandonedOrderReaper::reap(24);

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(2, $result['restored']);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, (int) $this->inventory->fresh()->quantity_on_hand);
        $this->assertSame(ProductSerial::IN_STOCK, $serial->fresh()->status);
    }

    public function test_reap_skips_recent_and_money_orders(): void
    {
        $recent = $this->abandonedOrder(1, ageDays: '0');
        $paid   = $this->abandonedOrder(1);
        DB::table('payments')->insert([
            'order_id' => $paid->id, 'payment_number' => 'PMT-' . $paid->id,
            'payment_method' => 'cash', 'amount' => 100, 'currency_code' => 'KES',
            'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = AbandonedOrderReaper::reap(24);

        $this->assertSame(0, $result['cancelled']);
        $this->assertSame('pending', $recent->fresh()->status);
        $this->assertSame('pending', $paid->fresh()->status);
        $this->assertSame(8, (int) $this->inventory->fresh()->quantity_on_hand);
    }

    public function test_backfill_restores_already_cancelled_orders(): void
    {
        // Simulates an order the earlier cleanup cancelled without restoring.
        $order = $this->abandonedOrder(3, status: 'cancelled');
        $order->update(['notes' => '[system] Auto-cancelled: abandoned unpaid POS order.']);

        $result = AbandonedOrderReaper::backfillCancelledUnrestored();

        $this->assertSame(1, $result['restored_orders']);
        $this->assertSame(3, $result['restored_units']);
        $this->assertSame(11, (int) $this->inventory->fresh()->quantity_on_hand);
    }
}
