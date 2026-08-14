<?php

namespace App\Services;

use App\Models\Order;

/**
 * OrderTotals — the one place order-level money is derived.
 *
 * Every writer that persists subtotal / tax_amount / total_amount on an order
 * used to carry its own inline copy of this arithmetic. They agreed, but only
 * by convention: nothing stopped one of them drifting, and one already had
 * (see the divergence note at the bottom). This class is the single definition.
 *
 * The formula:
 *
 *   subtotal = Σ (unit_price × quantity − line discount_amount)
 *   tax      = Σ line tax_amount        — taken as given, NOT recomputed here
 *   total    = subtotal − cart discount
 *              + (prices_include_tax ? 0 : tax)
 *              + shipping
 *
 * Tax is deliberately an *input*. Deciding what a line's tax is — which rate
 * applies, whether it is extracted from a gross price or added to a net one —
 * belongs to TaxCalculationService. This class only aggregates what the lines
 * already carry and combines it with the order-level discount and shipping.
 * Keeping that boundary is what makes the result reproducible: two callers that
 * agree on their lines will always agree on their totals.
 *
 * ── Rounding ──────────────────────────────────────────────────────────────
 *
 * Money columns are decimal(12,2), but intermediate values are not always
 * clean: a percentage discount on an odd base (1.5% of 999.00 = 14.985) carries
 * more than two places. So subtotal and the cart discount stay UNROUNDED while
 * the total is derived, and are rounded only for storage. Tax, by contrast, is
 * rounded to 2dp *before* it enters the total.
 *
 * That is not an arbitrary choice — it is what the existing writers already do.
 * PosController rounds its tax sum before adding it in; OrderController does
 * not, but its lines come back from decimal(12,2) columns, so its sum is
 * already exact to 2dp and the rounding is a no-op. Rounding tax early is
 * therefore correct for every caller, and reproduces all of them to the cent.
 *
 * @see \Tests\Feature\OrderTotalsTest                    formula + rounding
 * @see \Tests\Feature\OrderTotalsCharacterizationTest    per-writer parity
 */
final class OrderTotals
{
    private function __construct(
        /** Σ (unit_price × qty − line discount), rounded for storage. */
        public readonly float $subtotal,
        /** Order-level ("cart") discount, rounded for storage. */
        public readonly float $discount,
        /** Σ line tax, rounded. */
        public readonly float $tax,
        public readonly float $shipping,
        /** subtotal − discount + (exclusive ? tax : 0) + shipping. */
        public readonly float $total,
        public readonly bool $pricesIncludeTax,
        /** True when the cart discount exceeded the subtotal and was capped. */
        public readonly bool $discountClamped,
    ) {
    }

    /**
     * Derive totals from a set of lines.
     *
     * Lines may be OrderItem models, raw DB rows (stdClass) or plain arrays —
     * anything carrying unit_price, quantity, discount_amount and tax_amount.
     * That mixture is deliberate: POS computes totals from in-memory line data
     * before the order row exists, while the order-editing paths read theirs
     * back out of the database.
     *
     * @param  iterable<mixed>  $lines
     * @param  bool  $clampDiscount  Cap a cart discount larger than the subtotal
     *                               (and floor the total at zero) instead of
     *                               letting the total go negative.
     */
    public static function forLines(
        iterable $lines,
        bool $pricesIncludeTax,
        float $cartDiscount = 0.0,
        float $shipping = 0.0,
        bool $clampDiscount = false,
    ): self {
        $rawSubtotal = 0.0;
        $rawTax      = 0.0;

        foreach ($lines as $line) {
            $rawSubtotal += self::field($line, 'unit_price') * (int) self::field($line, 'quantity')
                - self::field($line, 'discount_amount');
            $rawTax += self::field($line, 'tax_amount');
        }

        return self::fromParts(
            $rawSubtotal,
            $rawTax,
            $pricesIncludeTax,
            $cartDiscount,
            $shipping,
            $clampDiscount,
        );
    }

    /**
     * Combine an already-summed subtotal and tax with the order-level figures.
     *
     * The POS writers need this rather than forLines(): they accumulate their
     * subtotal from raw per-line values while the line rows they go on to
     * persist carry the discount rounded to the column. On a percentage
     * discount those differ — 1.5% of 2997.00 is 44.955, stored as 44.96 — so
     * re-summing the persisted lines would move the total by a cent. The
     * unrounded running figure is the authority, and this is how it gets in.
     *
     * @param  float  $subtotal  Σ (unit_price × qty − line discount), UNROUNDED.
     * @param  float  $tax       Σ line tax, unrounded; rounded to 2dp here.
     */
    public static function fromParts(
        float $subtotal,
        float $tax,
        bool $pricesIncludeTax,
        float $cartDiscount = 0.0,
        float $shipping = 0.0,
        bool $clampDiscount = false,
    ): self {
        $tax = round($tax, 2);

        $clamped = $clampDiscount && $cartDiscount > $subtotal;
        if ($clamped) {
            $cartDiscount = $subtotal;
        }

        $total = round(
            $subtotal - $cartDiscount + ($pricesIncludeTax ? 0 : $tax) + $shipping,
            2,
        );

        return new self(
            subtotal:         round($subtotal, 2),
            discount:         round($cartDiscount, 2),
            tax:              $tax,
            shipping:         round($shipping, 2),
            total:            $clampDiscount ? max(0, $total) : $total,
            pricesIncludeTax: $pricesIncludeTax,
            discountClamped:  $clamped,
        );
    }

    /**
     * Derive totals for an order from the lines it currently has, reusing the
     * discount and shipping already on the row.
     *
     * @param  iterable<mixed>|null  $lines  Defaults to the order's own items.
     */
    public static function forOrder(
        Order $order,
        bool $pricesIncludeTax,
        ?iterable $lines = null,
        bool $clampDiscount = false,
    ): self {
        return self::forLines(
            $lines ?? $order->items,
            $pricesIncludeTax,
            (float) $order->discount_amount,
            (float) $order->shipping_amount,
            $clampDiscount,
        );
    }

    /**
     * Write subtotal / tax_amount / total_amount back to the order.
     *
     * Only those three, because that is what the order-editing callers have
     * always written — the cart discount and shipping ride along untouched.
     * Pass anything else the caller needs to set in the same update via $extra;
     * use columns() instead when building a brand-new order row.
     *
     * @param  array<string,mixed>  $extra
     */
    public function persistTo(Order $order, array $extra = []): void
    {
        $order->update([
            'subtotal'     => $this->subtotal,
            'tax_amount'   => $this->tax,
            'total_amount' => $this->total,
        ] + $extra);
    }

    /**
     * The full set of money columns, for Order::create() on a new order.
     *
     * @return array<string,float|bool>
     */
    public function columns(): array
    {
        return [
            'subtotal'           => $this->subtotal,
            'discount_amount'    => $this->discount,
            'tax_amount'         => $this->tax,
            'prices_include_tax' => $this->pricesIncludeTax,
            'shipping_amount'    => $this->shipping,
            'total_amount'       => $this->total,
        ];
    }

    /**
     * Resolve a flat/percent discount against the amount it applies to.
     *
     * Shared by the POS writers, which take the discount as a raw type+value
     * pair from the till and persist both the inputs and the resolved figure.
     * A flat discount is capped at the base so it can never exceed what is
     * being discounted; a percentage cannot, by construction.
     */
    public static function resolveDiscount(?string $type, float $value, float $base): float
    {
        return match ($type) {
            'flat'    => min($value, $base),
            'percent' => ($base * $value) / 100,
            default   => 0.0,
        };
    }

    /** Read a money/quantity field off a model, DB row or array line. */
    private static function field(mixed $line, string $key): float
    {
        $value = is_array($line) ? ($line[$key] ?? 0) : ($line->{$key} ?? 0);

        return (float) $value;
    }
}

/*
 * ── Known divergence, deliberately NOT folded in here ─────────────────────
 *
 * TaxCalculationService::calculateOrder() answers a different question and
 * disagrees with this class on two points:
 *
 *   1. Its 'subtotal' is the sum of NET line subtotals. Under tax-inclusive
 *      pricing that is (price × qty) − tax, not (price × qty). On 2 × 1000.00
 *      at 16% VAT it reports 1724.14 where this class reports 2000.00.
 *   2. It applies the line discount to the unit price BEFORE computing tax,
 *      so a discounted line is taxed on the discounted amount. This class
 *      takes each line's tax as already computed on the undiscounted base.
 *
 * OrderController::checkout and QuotationController (whose figures
 * QuotationService::convertToInvoice copies onto the order verbatim) both
 * source their totals from calculateOrder, so they already persist figures
 * this class would not reproduce. Pointing them here would silently restate
 * live order and quotation totals, so they were left alone; their current
 * behaviour is pinned by OrderTotalsCharacterizationTest so the divergence
 * is visible and cannot drift further unnoticed.
 *
 * OrderController::setShippingFee is a third abstainer, for an unrelated
 * reason: it never recomputes from lines at all. It moves total_amount by the
 * shipping delta and leaves subtotal and tax_amount untouched, which is what
 * lets it adjust freight on an already-paid receipt without restating the
 * goods. Recomputing there would rewrite totals on exactly the orders that
 * must not be rewritten.
 */
