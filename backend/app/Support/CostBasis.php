<?php

namespace App\Support;

/**
 * WHAT a sold line cost — one lookup for every cost-of-goods figure
 * (owner delegated the decision, 2026-10-02).
 *
 *   the cost snapshotted on the line at sale (order_items.cost_price),
 *   else the price book's KES cost: the variant's row first, then the product's
 *
 * Rows with no cost are skipped, not chosen — an empty cost is absence, the
 * same rule CurrencyPricing applies to an empty price. The classic P&L did
 * this; the earned P&L did not, so a variant row with no cost shadowed the
 * product's own cost: 15 sold lines, KES 55,600 of cost the earned P&L left
 * out (measured 2026-10-02). Data Quality's "lines sold with no cost" uses the
 * same lookup, so it states exactly what both P&Ls leave out.
 */
final class CostBasis
{
    /** The price-book lookup for a line alias, as a scalar subquery. */
    public static function bookCostSql(string $oi = 'oi'): string
    {
        return "(SELECT pp.cost_price FROM product_prices pp
                WHERE UPPER(pp.currency_code) = 'KES' AND pp.cost_price IS NOT NULL
                  AND (({$oi}.product_variant_id IS NOT NULL AND pp.product_variant_id = {$oi}.product_variant_id)
                       OR (pp.product_id = {$oi}.product_id AND pp.product_variant_id IS NULL))
                ORDER BY pp.product_variant_id IS NULL
                LIMIT 1)";
    }

    /** A line's unit cost: the snapshot, else the price book; NULL when neither has one. */
    public static function unitCostSql(string $oi = 'oi'): string
    {
        return "COALESCE({$oi}.cost_price, " . self::bookCostSql($oi) . ')';
    }
}
