<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Resolves the per-unit cost (KES) for a product/variant at this moment.
 *
 * Single source of truth for "what does this item cost us", used to SNAPSHOT
 * order_items.cost_price at sale time. Mirrors the lateral lookup
 * MetricEngine::earnedPnl() uses for live estimates: the variant-level
 * product_prices row wins, the product-level row (product_variant_id IS NULL)
 * is the fallback. KES only — costs are entered in KES (the CSV importer's
 * cost_kes and the admin form both write KES rows; foreign-currency price rows
 * carry no cost).
 *
 * Returns null when no cost has been entered for the product — callers store
 * NULL and reports fall back to the live lookup, so an unpriced product never
 * fakes a zero-cost (100% margin) sale.
 *
 * Later phases may add fallbacks (last purchase cost, production material
 * cost) — add them here, nowhere else, and tag them via a new source string.
 */
class CostResolver
{
    public const SOURCE_PRODUCT_PRICE = 'product_price';

    /**
     * @return array{cost_price: float, cost_source: string}|null
     */
    public function resolve(?int $productId, ?int $variantId): ?array
    {
        if ($productId === null && $variantId === null) {
            return null;
        }

        $row = DB::table('product_prices')
            ->where(function ($q) use ($productId, $variantId) {
                if ($variantId !== null) {
                    $q->where('product_variant_id', $variantId);
                }
                if ($productId !== null) {
                    $q->orWhere(function ($qq) use ($productId) {
                        $qq->where('product_id', $productId)
                           ->whereNull('product_variant_id');
                    });
                }
            })
            ->whereRaw("UPPER(currency_code) = 'KES'")
            ->whereNotNull('cost_price')
            // Variant-level row first, product-level fallback second.
            ->orderByRaw('product_variant_id IS NULL')
            ->value('cost_price');

        if ($row === null) {
            return null;
        }

        return [
            'cost_price'  => (float) $row,
            'cost_source' => self::SOURCE_PRODUCT_PRICE,
        ];
    }

    /**
     * Convenience for the OrderItem::create payloads: returns the two columns
     * ready to merge into the attributes array (NULLs when unresolvable).
     *
     * @return array{cost_price: ?float, cost_source: ?string}
     */
    public function columns(?int $productId, ?int $variantId): array
    {
        $resolved = $this->resolve($productId, $variantId);

        return [
            'cost_price'  => $resolved['cost_price'] ?? null,
            'cost_source' => $resolved['cost_source'] ?? null,
        ];
    }
}
