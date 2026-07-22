<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Support\Collection;

/**
 * The single source of truth for storefront discounts.
 *
 * Used by BOTH the product API (to overlay a discounted `sale_price` for
 * DISPLAY) and StorefrontCheckoutController (to compute the real per-line
 * discount that is CHARGED). Because both paths call the same resolver, the
 * shelf price and the charged price can never diverge.
 *
 * v1 scope: the highest-priority running promotion applies (no stacking).
 * `conditions` may narrow scope to {category_ids:[…], product_ids:[…]}; an
 * empty/legacy conditions value means site-wide. Money stays server-side —
 * the storefront never computes a discount.
 */
class PromotionService
{
    /** @var Collection<int,Promotion>|null memoised per instance (per request) */
    private ?Collection $active = null;

    /** Running promotions (active + within window), highest priority first. */
    public function activePromotions(): Collection
    {
        return $this->active ??= Promotion::active()
            ->orderByDesc('priority')
            ->orderByDesc('starts_at')
            ->get();
    }

    /** The highest-priority running promotion that applies to this product, or null. */
    public function promotionFor(Product $product): ?Promotion
    {
        foreach ($this->activePromotions() as $promo) {
            if ($this->applies($promo, $product)) {
                return $promo;
            }
        }
        return null;
    }

    /**
     * Scope check. Site-wide when conditions is empty or the legacy admin
     * [{key,value},…] shape; otherwise honour {category_ids,product_ids}.
     */
    private function applies(Promotion $promo, Product $product): bool
    {
        $c = $promo->conditions;
        if (!is_array($c) || $c === []) {
            return true; // site-wide
        }
        // Legacy admin editor shape: a list of {key,value} — not a real scope.
        if (isset($c[0]) && is_array($c[0]) && array_key_exists('key', $c[0])) {
            return true;
        }
        $productIds  = $c['product_ids']  ?? null;
        $categoryIds = $c['category_ids'] ?? null;
        if (is_array($productIds) && in_array($product->id, $productIds)) {
            return true;
        }
        if (is_array($categoryIds) && $product->category_id && in_array($product->category_id, $categoryIds)) {
            return true;
        }
        // A structured scope was given and this product is outside it.
        if (is_array($productIds) || is_array($categoryIds)) {
            return false;
        }
        return true; // unknown structured shape → don't accidentally exclude
    }

    /**
     * The discounted unit price for a base amount under a promotion. Rounded to
     * 2dp (money is decimal(12,2)), never below 0, never above the base.
     */
    public function discountedUnit(float $base, Promotion $promo): float
    {
        if ($base <= 0) {
            return $base;
        }
        $value = (float) $promo->discount_value;
        $off = $promo->discount_type === 'percentage'
            ? $base * (min(max($value, 0), 100) / 100)
            : min(max($value, 0), $base); // fixed: never more than the price
        return round(max(0, $base - $off), 2);
    }

    /**
     * Overlay a discounted `sale_price` on loaded price rows, IN MEMORY (never
     * persisted). Base = the current selling price (manual sale if on sale,
     * else regular); the regular price stays as the struck-through original.
     */
    public function overlayPriceRows(iterable $priceRows, ?Promotion $promo): void
    {
        if (!$promo) {
            return;
        }
        foreach ($priceRows as $row) {
            $regular = (float) $row->regular_price;
            if ($regular <= 0) {
                continue;
            }
            $base  = ($row->sale_price && $row->isOnSale()) ? (float) $row->sale_price : $regular;
            $final = $this->discountedUnit($base, $promo);
            if ($final < $regular) {
                $row->sale_price = $final; // in-memory overlay for display
            }
        }
    }

    /** Overlay a product's own price rows (and any loaded variants') for display. */
    public function overlayProduct(Product $product): void
    {
        $promo = $this->promotionFor($product);
        if (!$promo) {
            return;
        }
        if ($product->relationLoaded('prices')) {
            $this->overlayPriceRows($product->prices, $promo);
        }
        if ($product->relationLoaded('variants')) {
            foreach ($product->variants as $variant) {
                if ($variant->relationLoaded('prices')) {
                    $this->overlayPriceRows($variant->prices, $promo);
                }
            }
        }
    }

    /** Overlay every product in a collection (the product list endpoint). */
    public function overlayCollection(iterable $products): void
    {
        foreach ($products as $product) {
            $this->overlayProduct($product);
        }
    }

    /** Overlay variant price rows under their parent's promotion (variants endpoint). */
    public function overlayVariants(Product $parent, iterable $variants): void
    {
        $promo = $this->promotionFor($parent);
        if (!$promo) {
            return;
        }
        foreach ($variants as $variant) {
            if ($variant->relationLoaded('prices')) {
                $this->overlayPriceRows($variant->prices, $promo);
            }
        }
    }
}
