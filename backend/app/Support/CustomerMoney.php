<?php

namespace App\Support;

/**
 * How customer money is rounded.
 *
 * PRICES keep two decimals (owner, 2026-11-16: "No rounding off — 400
 * plastic cups × USD 0.10 should be USD 40"): a unit price, typed or from the
 * price list, converted or not, is never rounded to a whole unit. See price().
 *
 * FEES stay whole (owner, 2026-10-30: "All customer numbers should be rounded
 * off"; halves round up): a line or cart discount, a shipping fee, and VAT
 * added on top of a price. See whole(). VAT already
 * inside a whole price is the tax authority's figure and keeps its cents;
 * the customer pays the whole price either way.
 *
 * Never applied to money that has moved — a payment or refund is recorded
 * as received — nor to the books: KES reporting conversions, costs, purchase
 * orders, expenses and rates keep their precision.
 */
final class CustomerMoney
{
    /** Nearest whole unit, halves up (2,307.50 → 2,308). */
    public static function whole(float $amount): float
    {
        return (float) round($amount, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * A price as it is SAVED on a product: to the cent, in every currency
     * (owner, 2026-11-16 — USD 0.10 stays 0.10; until then other currencies
     * were saved whole). `$currency` is kept for the callers' signature.
     */
    public static function listPrice(?float $amount, string $currency): ?float
    {
        return $amount === null ? null : self::price($amount);
    }

    /**
     * An amount as a customer reads it (owner, 2026-10-30): "KES 4,500",
     * not "KES 4,500.00". Cents are shown only when there are some — VAT
     * inside a price (137.93), or an amount written before the whole-unit
     * rule — so nothing is ever hidden by the display. Display only: the
     * stored figure is untouched.
     */
    public static function format(float $amount, ?string $currency = null): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
        $text     = number_format($amount, $decimals);

        return $currency !== null && $currency !== '' ? "{$currency} {$text}" : $text;
    }

    /**
     * A unit price, to the cent, in any currency (owner, 2026-11-16): USD
     * 44.50 stays 44.50 and USD 0.10 stays 0.10 — typed or from the price
     * list, on an order, a quotation, the till or a conversion.
     */
    public static function price(float $amount): float
    {
        return round($amount, 2);
    }

    /** price(), passing NULL through. */
    public static function priceOrNull(?float $amount): ?float
    {
        return $amount === null ? null : self::price($amount);
    }

    /** whole(), passing NULL through. */
    public static function wholeOrNull(?float $amount): ?float
    {
        return $amount === null ? null : self::whole($amount);
    }
}
