<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OrderTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Differential proof that OrderTotals reproduces OrderLineEditor's original
 * inline recalculateTotals() exactly.
 *
 * The 24 cases in OrderLineEditingTest passing after the swap shows the swap
 * did not break anything those cases reach. It does NOT show the two formulas
 * agree everywhere — most of them assert on line membership and permissions,
 * not on cents. This does: it runs both implementations over the same orders
 * and compares every figure either of them would persist.
 *
 * The interesting divergence risk is rounding. The original derived its total
 * from an already-ROUNDED subtotal and tax; OrderTotals derives it from the
 * unrounded subtotal and a rounded tax. Those coincide when the lines come
 * back from decimal(12,2) columns — which they always do here, because
 * recalculateTotals() reads persisted OrderItem rows. The cases below lean on
 * that boundary deliberately: long sums of values that are exact in decimal
 * but not in binary floating point, and clamp comparisons sitting exactly on
 * the subtotal.
 *
 * @see \App\Services\OrderTotals
 * @see \App\Services\OrderLineEditor::recalculateTotals()
 */
class OrderLineEditorTotalsParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The original implementation, copied verbatim from OrderLineEditor before
     * the swap (commit 6cdf179), minus the persistence.
     *
     * @return array{subtotal:float,discount:float,tax:float,total:float,clamped:bool}
     */
    private function legacy(Order $order, bool $taxInclusive): array
    {
        $lines = OrderItem::where('order_id', $order->id)->get();

        $subtotal = round($lines->sum(
            fn ($i) => (float) $i->unit_price * (int) $i->quantity - (float) $i->discount_amount
        ), 2);
        $taxTotal = round($lines->sum(fn ($i) => (float) $i->tax_amount), 2);

        $cartDiscount = (float) $order->discount_amount;
        $clamped      = $cartDiscount > $subtotal;
        if ($clamped) {
            $cartDiscount = $subtotal;
        }

        $total = round(
            $subtotal - $cartDiscount + ($taxInclusive ? 0 : $taxTotal) + (float) $order->shipping_amount,
            2,
        );

        return [
            'subtotal' => $subtotal,
            'discount' => round($cartDiscount, 2),
            'tax'      => $taxTotal,
            'total'    => max(0, $total),
            'clamped'  => $clamped,
        ];
    }

    /** What the swapped implementation produces. */
    private function viaOrderTotals(Order $order, bool $taxInclusive): array
    {
        $totals = OrderTotals::forOrder(
            $order,
            $taxInclusive,
            OrderItem::where('order_id', $order->id)->get(),
            clampDiscount: true,
        );

        return [
            'subtotal' => $totals->subtotal,
            'discount' => $totals->discount,
            'tax'      => $totals->tax,
            'total'    => $totals->total,
            'clamped'  => $totals->discountClamped,
        ];
    }

    /**
     * @param  list<array{0:float,1:int,2:float,3:float}>  $lines  [unit_price, qty, line discount, line tax]
     */
    private function orderWith(array $lines, float $cartDiscount, float $shipping): Order
    {
        $order = Order::factory()->create([
            'discount_amount' => $cartDiscount,
            'shipping_amount' => $shipping,
            'subtotal'        => 0,
            'tax_amount'      => 0,
            'total_amount'    => 0,
        ]);

        $product = Product::factory()->create();

        foreach ($lines as [$price, $qty, $discount, $tax]) {
            OrderItem::create([
                'order_id'        => $order->id,
                'product_id'      => $product->id,
                'product_name'    => 'P',
                'sku'             => 'P',
                'quantity'        => $qty,
                'unit_price'      => $price,
                'discount_amount' => $discount,
                'tax_amount'      => $tax,
                'total_price'     => 0,
            ]);
        }

        return $order->fresh();
    }

    /**
     * Compare as money, not as PHP values.
     *
     * The original's `max(0, $total)` returns int(0) when the total floors,
     * because 0 is an int literal; OrderTotals coerces to float(0.0) through
     * its typed property. Both persist as 0.00 in a decimal(12,2) column, so
     * the distinction cannot survive a write — but assertSame would flag it.
     * This is the one and only difference between the two implementations.
     */
    private function asMoney(array $figures): array
    {
        return array_map(
            fn ($v) => is_bool($v) ? $v : (float) $v,
            $figures,
        );
    }

    /**
     * Every case, both tax modes, both implementations — all figures identical.
     *
     * @dataProvider totalsCases
     */
    public function test_order_totals_reproduces_the_original_formula(array $lines, float $cartDiscount, float $shipping): void
    {
        $order = $this->orderWith($lines, $cartDiscount, $shipping);

        foreach ([true, false] as $taxInclusive) {
            $this->assertSame(
                $this->asMoney($this->legacy($order, $taxInclusive)),
                $this->asMoney($this->viaOrderTotals($order, $taxInclusive)),
                'tax_inclusive=' . var_export($taxInclusive, true),
            );
        }
    }

    public static function totalsCases(): iterable
    {
        yield 'single plain line' => [[[1000.00, 2, 0, 320.00]], 0, 0];

        yield 'line discount, cart discount and shipping' => [
            [[999.00, 3, 44.96, 479.52], [250.00, 2, 0, 80.00]], 100.00, 150.00,
        ];

        // ── The clamp boundary ──────────────────────────────────────────────
        yield 'cart discount below subtotal'  => [[[100.00, 1, 0, 16.00]], 99.99, 0];
        yield 'cart discount equals subtotal' => [[[100.00, 1, 0, 16.00]], 100.00, 0];
        yield 'cart discount just over'       => [[[100.00, 1, 0, 16.00]], 100.01, 0];
        yield 'cart discount far over'        => [[[100.00, 1, 0, 16.00]], 5000.00, 0];
        yield 'clamped but shipping remains'  => [[[100.00, 1, 0, 16.00]], 5000.00, 40.00];

        // ── Floating-point stress: exact in decimal, not in binary ──────────
        yield '30 lines of 0.07' => [
            array_fill(0, 30, [0.07, 3, 0.00, 0.01]), 0, 0,
        ];
        yield '50 lines of 0.10 with discounts' => [
            array_fill(0, 50, [0.10, 7, 0.03, 0.11]), 1.37, 0.29,
        ];
        yield 'many odd cents' => [
            [[19.99, 3, 0.01, 9.59], [0.03, 99, 1.97, 0.47], [7.77, 7, 7.07, 8.71]], 3.33, 1.11,
        ];

        // ── Larger magnitudes, where float precision thins out ──────────────
        yield 'large values' => [
            [[999999.99, 9, 12345.67, 159999.99], [0.01, 1, 0, 0]], 88888.88, 777.77,
        ];

        // ── Degenerate shapes ───────────────────────────────────────────────
        yield 'zero-price line'       => [[[0.00, 5, 0, 0]], 0, 0];
        yield 'line fully discounted' => [[[50.00, 2, 100.00, 16.00]], 0, 0];
        yield 'no cart discount, shipping only' => [[[10.00, 1, 0, 1.60]], 0, 25.00];
    }

    /**
     * The clamp is the one behaviour OrderLineEditor adds over the other
     * writers, and the one most easily lost in a refactor. Asserted head-on.
     */
    public function test_the_clamp_still_caps_the_discount_and_floors_the_total(): void
    {
        $order = $this->orderWith([[100.00, 1, 0, 16.00]], cartDiscount: 250.00, shipping: 0);

        // Tax-inclusive: the discount is capped at the 100.00 of goods and the
        // total floors at zero.
        $inclusive = $this->viaOrderTotals($order, true);
        $this->assertTrue($inclusive['clamped']);
        $this->assertSame(100.0, $inclusive['discount']);
        $this->assertSame(0.0, $inclusive['total']);

        // Tax-exclusive: the goods still net to zero, but the 16.00 of tax is
        // added on top and remains owed. Capping the discount does not waive it.
        $exclusive = $this->viaOrderTotals($order, false);
        $this->assertTrue($exclusive['clamped']);
        $this->assertSame(100.0, $exclusive['discount']);
        $this->assertSame(16.0, $exclusive['total']);

        $this->assertSame($this->asMoney($this->legacy($order, true)), $this->asMoney($inclusive));
        $this->assertSame($this->asMoney($this->legacy($order, false)), $this->asMoney($exclusive));
    }
}
