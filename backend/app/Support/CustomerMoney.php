<?php

namespace App\Support;

/**
 * What a customer is billed is a whole unit — KES 1,000, R3,231, USD 150 —
 * never cents (owner, 2026-10-30: "All customer numbers should be rounded
 * off"). Halves round up.
 *
 * Applied where a billed part is decided: a unit price, a line or cart
 * discount, a shipping fee, and VAT added on top of a price. VAT already
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

    /** whole(), passing NULL through. */
    public static function wholeOrNull(?float $amount): ?float
    {
        return $amount === null ? null : self::whole($amount);
    }
}
