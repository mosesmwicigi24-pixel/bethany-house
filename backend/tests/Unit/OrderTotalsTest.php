<?php

namespace Tests\Unit;

use App\Services\OrderTotals;
use PHPUnit\Framework\TestCase;

/**
 * The calculator's own contract, driven directly rather than through a writer.
 *
 * The cases that matter are the rounding ones. Every writer this class replaced
 * derived the total from UNROUNDED subtotal and discount but a ROUNDED tax sum,
 * and stored a rounded subtotal alongside. That looks like an inconsistency
 * until you hit a percentage discount, where the difference is a real cent.
 */
class OrderTotalsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function line(float $price, int $qty, float $discount = 0, float $tax = 0): array
    {
        return [
            'unit_price'      => $price,
            'quantity'        => $qty,
            'discount_amount' => $discount,
            'tax_amount'      => $tax,
        ];
    }

    public function test_subtotal_is_the_sum_of_lines_net_of_their_own_discounts(): void
    {
        $totals = OrderTotals::forLines(
            [$this->line(1000, 2, 500), $this->line(250, 2)],
            pricesIncludeTax: false,
        );

        $this->assertSame(2000.0, $totals->subtotal);   // (2000 − 500) + 500
    }

    public function test_tax_exclusive_pricing_adds_the_line_tax_to_the_total(): void
    {
        $totals = OrderTotals::forLines(
            [$this->line(1000, 2, tax: 320)],
            pricesIncludeTax: false,
        );

        $this->assertSame(2000.0, $totals->subtotal);
        $this->assertSame(320.0, $totals->tax);
        $this->assertSame(2320.0, $totals->total);
    }

    public function test_tax_inclusive_pricing_records_the_tax_but_leaves_the_total_alone(): void
    {
        $totals = OrderTotals::forLines(
            [$this->line(1000, 2, tax: 275.86)],
            pricesIncludeTax: true,
        );

        $this->assertSame(2000.0, $totals->subtotal);
        $this->assertSame(275.86, $totals->tax);   // still recorded, for reporting
        $this->assertSame(2000.0, $totals->total); // but the customer pays 2000
    }

    public function test_cart_discount_and_shipping_move_the_total(): void
    {
        $totals = OrderTotals::forLines(
            [$this->line(1000, 2, tax: 320)],
            pricesIncludeTax: false,
            cartDiscount: 100,
            shipping: 150,
        );

        $this->assertSame(2000.0, $totals->subtotal);  // subtotal is BEFORE the cart discount
        $this->assertSame(100.0, $totals->discount);
        $this->assertSame(2370.0, $totals->total);     // 2000 − 100 + 320 + 150
    }

    /**
     * THE ONE THAT MATTERS. 1.5% of 2997.00 is 44.955 — three decimal places on
     * a two-decimal column. The stored subtotal rounds to 3452.05, but the total
     * must be derived from the unrounded 3452.045, or it lands a cent high.
     *
     * Every writer got this right by accident of expression order. Pinning it
     * here is what stops a future "tidy-up" from rounding subtotal early.
     */
    public function test_the_total_is_derived_from_unrounded_subtotal_and_discount(): void
    {
        $totals = OrderTotals::forLines(
            [
                $this->line(999, 3, 44.955, 479.52),   // 1.5% off, taxed on the full 2997
                $this->line(250, 2, 0, 80),
            ],
            pricesIncludeTax: false,
            cartDiscount: 86.301125,                   // 2.5% of 3452.045
            shipping: 150,
        );

        $this->assertSame(3452.05, $totals->subtotal);   // rounded for storage
        $this->assertSame(86.3, $totals->discount);      // rounded for storage
        $this->assertSame(559.52, $totals->tax);

        // 3452.045 − 86.301125 + 559.52 + 150 = 4075.263875 → 4075.26
        // Rounding the subtotal first would have given 4075.27.
        $this->assertSame(4075.26, $totals->total);
    }

    public function test_tax_is_rounded_before_it_enters_the_total(): void
    {
        // Two lines whose raw tax sums to 0.125 — rounds to 0.13, not 0.12.
        $totals = OrderTotals::forLines(
            [$this->line(100, 1, 0, 0.0625), $this->line(100, 1, 0, 0.0625)],
            pricesIncludeTax: false,
        );

        $this->assertSame(0.13, $totals->tax);
        $this->assertSame(200.13, $totals->total);
    }

    public function test_an_oversized_cart_discount_is_left_alone_by_default(): void
    {
        // The order-editing and POS writers never clamped; a discount bigger than
        // the goods drives the total negative and that stays visible.
        $totals = OrderTotals::forLines(
            [$this->line(100, 1)],
            pricesIncludeTax: true,
            cartDiscount: 250,
        );

        $this->assertSame(-150.0, $totals->total);
        $this->assertFalse($totals->discountClamped);
    }

    public function test_clamping_caps_the_discount_at_the_subtotal_and_floors_the_total(): void
    {
        $totals = OrderTotals::forLines(
            [$this->line(100, 1)],
            pricesIncludeTax: true,
            cartDiscount: 250,
            clampDiscount: true,
        );

        $this->assertTrue($totals->discountClamped);
        $this->assertSame(100.0, $totals->discount);   // capped at the subtotal
        $this->assertSame(0.0, $totals->total);
    }

    public function test_shipping_still_lands_on_a_clamped_total(): void
    {
        // Clamping floors the goods at zero; it must not swallow the freight.
        $totals = OrderTotals::forLines(
            [$this->line(100, 1)],
            pricesIncludeTax: true,
            cartDiscount: 250,
            shipping: 40,
            clampDiscount: true,
        );

        $this->assertTrue($totals->discountClamped);
        $this->assertSame(40.0, $totals->total);
    }

    public function test_lines_may_be_arrays_or_objects(): void
    {
        $asObject = (object) $this->line(1000, 2, 500, 240);

        $this->assertEquals(
            OrderTotals::forLines([$this->line(1000, 2, 500, 240)], false),
            OrderTotals::forLines([$asObject], false),
        );
    }

    public function test_missing_fields_are_read_as_zero(): void
    {
        $totals = OrderTotals::forLines([['unit_price' => 100, 'quantity' => 2]], false);

        $this->assertSame(200.0, $totals->subtotal);
        $this->assertSame(0.0, $totals->tax);
    }

    public function test_no_lines_gives_shipping_only(): void
    {
        $totals = OrderTotals::forLines([], false, shipping: 500);

        $this->assertSame(0.0, $totals->subtotal);
        $this->assertSame(500.0, $totals->total);
    }

    // ── resolveDiscount ─────────────────────────────────────────────────────

    public function test_a_flat_discount_cannot_exceed_what_it_discounts(): void
    {
        $this->assertSame(300.0, OrderTotals::resolveDiscount('flat', 300, 1000));
        $this->assertSame(1000.0, OrderTotals::resolveDiscount('flat', 5000, 1000));
    }

    public function test_a_percentage_discount_keeps_its_fractional_cents(): void
    {
        $this->assertSame(44.955, OrderTotals::resolveDiscount('percent', 1.5, 2997));
    }

    public function test_no_discount_type_means_no_discount(): void
    {
        $this->assertSame(0.0, OrderTotals::resolveDiscount('none', 50, 1000));
        $this->assertSame(0.0, OrderTotals::resolveDiscount(null, 50, 1000));
    }
}
