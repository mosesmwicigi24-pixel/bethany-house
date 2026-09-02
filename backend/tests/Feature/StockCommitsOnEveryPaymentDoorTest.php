<?php

namespace Tests\Feature;

use App\Exceptions\StaleStockHoldException;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Services\PosInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fully paid means the goods leave the shelf — through EVERY door.
 *
 * The deduction used to be written at each call site. PosController and
 * ReceiptService did it; PublicPaymentController never did. So every order a
 * CUSTOMER paid for themselves — the durable order link flow, the one Neema now
 * hands out with every order — took the money and left the shelf count
 * untouched. Measured on production: 82 orders holding a reservation that was
 * paid and never committed, KES 1.16m, still happening the day this was found.
 *
 * The fix moves the deduction onto syncPaymentStatus(), the one method every
 * payment door already calls, so a new door cannot forget.
 */
class StockCommitsOnEveryPaymentDoorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: InventoryItem} */
    private function reservedOrder(int $onHand = 10, int $qty = 2): array
    {
        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        $inv = InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $outlet->id, 'quantity_on_hand' => $onHand,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);
        $order = Order::factory()->create([
            'outlet_id' => $outlet->id, 'order_type' => 'pos', 'sales_bucket' => 'till',
            'status' => 'confirmed', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 1000,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_variant_id' => null, 'product_name' => 'Cassock', 'sku' => 'CAS-1',
            'quantity' => $qty, 'unit_price' => 500, 'total_price' => 500 * $qty,
        ]);
        PosInventoryService::reserveForOrder($order);

        return [$order->fresh(), $inv->fresh()];
    }

    public function test_paying_in_full_deducts_the_stock(): void
    {
        [$order, $inv] = $this->reservedOrder(onHand: 10, qty: 2);
        $this->assertSame(2, $inv->quantity_reserved, 'reserved, not yet deducted');
        $this->assertSame(10, $inv->quantity_on_hand);

        Payment::factory()->create([
            'order_id' => $order->id, 'status' => 'paid', 'amount' => 1000,
        ]);
        $order->syncPaymentStatus();

        $inv->refresh();
        $this->assertSame(8, $inv->quantity_on_hand, 'the goods left the shelf');
        $this->assertSame(0, $inv->quantity_reserved, 'the reservation was consumed');
        $this->assertNotNull($order->fresh()->stock_committed_at);
    }

    public function test_a_part_payment_does_not_deduct_anything(): void
    {
        [$order, $inv] = $this->reservedOrder(onHand: 10, qty: 2);

        Payment::factory()->create([
            'order_id' => $order->id, 'status' => 'paid', 'amount' => 400,
        ]);
        $order->syncPaymentStatus();

        $inv->refresh();
        $this->assertSame(10, $inv->quantity_on_hand, 'goods stay until the sale is settled');
        $this->assertSame(2, $inv->quantity_reserved, 'and stay reserved for this customer');
    }

    public function test_deducting_is_idempotent_across_doors(): void
    {
        // The till commits explicitly AND calls syncPaymentStatus elsewhere;
        // both must not deduct twice.
        [$order, $inv] = $this->reservedOrder(onHand: 10, qty: 2);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'paid', 'amount' => 1000]);

        $order->syncPaymentStatus();
        $order->fresh()->syncPaymentStatus();
        PosInventoryService::commitForOrder($order->fresh());

        $this->assertSame(8, $inv->fresh()->quantity_on_hand, 'deducted exactly once');
    }

    public function test_a_dead_order_never_deducts(): void
    {
        [$order, $inv] = $this->reservedOrder(onHand: 10, qty: 2);
        $order->forceFill(['status' => 'cancelled'])->save();

        Payment::factory()->create(['order_id' => $order->id, 'status' => 'paid', 'amount' => 1000]);
        $order->syncPaymentStatus();

        $this->assertSame(10, $inv->fresh()->quantity_on_hand,
            'a cancelled order must not take stock, however its money looks');
    }

    public function test_a_stale_hold_is_refused_by_the_service_itself(): void
    {
        // The guard belongs where every caller inherits it, not on one route.
        [$order] = $this->reservedOrder();
        $order->forceFill(['stock_unwound_at' => now()])->save();

        $this->expectException(StaleStockHoldException::class);
        PosInventoryService::commitForOrder($order->fresh());
    }

    public function test_a_stale_hold_never_undoes_a_customers_payment(): void
    {
        // Money has already arrived by the time we settle. A bookkeeping problem
        // must be logged for staff, never thrown back at the customer.
        [$order, $inv] = $this->reservedOrder(onHand: 10, qty: 2);
        $order->forceFill(['stock_unwound_at' => now()])->save();
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'paid', 'amount' => 1000]);

        $order->fresh()->syncPaymentStatus();          // must not throw

        $this->assertSame('paid', $order->fresh()->payment_status, 'the payment stands');
        $this->assertSame(10, $inv->fresh()->quantity_on_hand, 'and no wrong deduction happened');
    }
}
