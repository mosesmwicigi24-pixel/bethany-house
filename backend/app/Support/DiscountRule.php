<?php

namespace App\Support;

use App\Exceptions\DiscountAboveMaximum;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Promotion;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Services\PromotionService;

/**
 * The owner's discount rule, in one place (2026-10-03).
 *
 *   "Our products are exotic and exclusive. I did not want discounts given.
 *    The max is 5%. Unless the super admin set the % discount, we can only
 *    give the discount up to 5%."
 *
 * Three consequences, and every path that lowers a price calls this class for
 * them rather than deciding for itself:
 *
 *   1. STAFF. Any discount a person gives — a till line or cart, a pending
 *      order, a quotation line, an edit to an order's lines — is at most
 *      config('pos.discount_cap_percent') (5) of whatever it is applied to,
 *      for every role. `pos.discount_override` used to lift that for outlet
 *      managers and admins; it no longer lifts anything. Only a holder of the
 *      super_admin role goes further. The cap is measured on the RESOLVED
 *      amount, so a flat discount cannot walk around a percentage: 500 off a
 *      1,000 line is 50% however it was typed.
 *
 *   2. PROMOTIONS, CAMPAIGNS, COUPONS. A promotion runs by itself for every
 *      customer until it ends, so the rule bites where it is SET. Creating,
 *      raising or switching on one worth more than 5% is the super_admin's
 *      alone. Once he has set it, it applies at his value — the storefront
 *      and Neema both honour it. Worth, for a fixed amount, is measured against
 *      the cheapest thing it can reach (see promotionPercent()).
 *
 *   3. NEEMA. The sales agent's service account (`pos.discount_campaign`) is
 *      held to 5% like everyone else, except on a line that a running hub
 *      promotion covers — then up to that promotion's value and no further.
 *      The hub cannot see who typed a campaign into Neema's own dashboard; it
 *      can see its own promotions, and since this rule only the owner can set
 *      one above 5%. That is what makes a running promotion his word.
 *
 * Nothing here reprices history. Orders, promotions and coupons that already
 * carry more than 5% are left exactly as they are; the rule stops a discount
 * being GIVEN or made bigger, it does not take one back.
 *
 * @see \Tests\Feature\DiscountMaximumTest
 * @see \Tests\Feature\PromotionDiscountMaximumTest
 * @see \Tests\Feature\AgentDiscountMaximumTest
 */
final class DiscountRule
{
    /** The one role that may exceed the maximum. */
    public const OWNER_ROLE = 'super_admin';

    /** The single number. */
    public static function capPercent(): float
    {
        return (float) config('pos.discount_cap_percent', 5.0);
    }

    /**
     * True for a super_admin, under any guard. Deliberately the ROLE, not a
     * permission: super_admin passes every permission through Gate::before,
     * and so does nobody else — a permission would be one more thing an
     * admin could grant himself.
     */
    public static function isOwner(?User $user): bool
    {
        return $user !== null && $user->hasRole(self::OWNER_ROLE);
    }

    /** The sales agent's service account — never the owner, who passes every can(). */
    public static function isAgent(?User $user): bool
    {
        return $user !== null && !self::isOwner($user) && $user->can('pos.discount_campaign');
    }

    /** The most this caller may give, as a percentage. Null: no ceiling (the owner). */
    public static function capFor(?User $user): ?float
    {
        return self::isOwner($user) ? null : self::capPercent();
    }

    /** The plain sentence every refusal says. */
    public static function message(): string
    {
        return sprintf(
            'The most anyone can give is %s%%. Larger discounts are set by the owner.',
            self::formatPercent(self::capPercent()),
        );
    }

    // ── Staff discounts ───────────────────────────────────────────────────────

    /**
     * Null when this discount may be given, otherwise the sentence to refuse it with.
     *
     * @param  float  $discount        the resolved discount AMOUNT, never the raw input
     * @param  float  $base            what it is applied to: the line's gross, or the cart's subtotal
     * @param  float  $agentAllowance  money a running owner-set promotion lets the AGENT carry on
     *                                 this line; ignored for anybody else
     */
    public static function refusal(?User $user, float $discount, float $base, float $agentAllowance = 0.0): ?string
    {
        $discount = round($discount, 2);

        // No discount, nothing to decide — the ordinary sale stays free of any lookup.
        if ($discount <= 0.0 || self::isOwner($user)) {
            return null;
        }

        // Rounded to the money column before comparing, so float noise in
        // ($base * 5 / 100) cannot refuse a discount that is exactly at the
        // maximum. A base of zero gives a ceiling of zero.
        $ceiling = round(max(0.0, $base) * self::capPercent() / 100, 2);

        if ($agentAllowance > 0 && self::isAgent($user)) {
            $ceiling = max($ceiling, round($agentAllowance, 2));
        }

        return $discount > $ceiling ? self::message() : null;
    }

    /** 422 naming $field unless refusal() is null. */
    public static function assertWithin(?User $user, float $discount, float $base, string $field, float $agentAllowance = 0.0): void
    {
        if ($message = self::refusal($user, $discount, $base, $agentAllowance)) {
            throw new DiscountAboveMaximum($field, $message);
        }
    }

    /**
     * What a running promotion lets the agent carry on one line: the
     * promotion's own value applied to the line — its percentage of the line,
     * or its fixed amount per unit, never more than the line itself.
     */
    public static function promotionAllowance(?Promotion $promotion, float $unitPrice, int $quantity): float
    {
        if (!$promotion || !$promotion->isRunning() || $unitPrice <= 0 || $quantity <= 0) {
            return 0.0;
        }

        $value   = max(0.0, (float) $promotion->discount_value);
        $perUnit = $promotion->discount_type === 'percentage'
            ? $unitPrice * min($value, 100.0) / 100
            : min($value, $unitPrice);

        return round($perUnit * $quantity, 2);
    }

    // ── Promotions and coupons ────────────────────────────────────────────────

    /**
     * The largest share of a price this promotion can take, in percent.
     *
     * A percentage is its own answer. A FIXED amount is measured against the
     * cheapest item it can reach — the active, priced products in its scope,
     * at their selling price in the base currency — because a fixed sum is the
     * biggest share of the cheapest price, and the rule is about the share:
     * KES 100 off is 0.5% of a 20,000 cope and 10% of a 1,000 stole, and the
     * stole is what the promotion actually lets go for 10% less. Measuring
     * against an average or the dearest item would let it quietly exceed 5%
     * on everything cheaper.
     *
     * Null when there is nothing priced in scope to measure against — the
     * caller treats that as "cannot be shown to be within the maximum".
     *
     * @param  mixed  $conditions  the promotion's scope, as PromotionService reads it
     */
    public static function promotionPercent(string $type, float $value, mixed $conditions): ?float
    {
        $value = max(0.0, $value);

        if ($type === 'percentage') {
            return min($value, 100.0);
        }
        if ($value <= 0.0) {
            return 0.0;
        }

        $scope    = PromotionService::scopeOf($conditions);
        $cheapest = self::cheapestPrice($scope['product_ids'] ?? null, $scope['category_ids'] ?? null, $scope === null);

        return $cheapest === null ? null : min(100.0, $value / $cheapest * 100);
    }

    /**
     * Null when this caller may set a promotion to $after, else the sentence.
     *
     * @param  array{discount_type:string, discount_value:mixed, conditions?:mixed, is_active?:mixed}  $after
     * @param  array{discount_type:string, discount_value:mixed, conditions?:mixed, is_active?:mixed}|null  $before
     *         the promotion as it stands, or null when it is being created
     */
    public static function promotionRefusal(?User $user, array $after, ?array $before = null): ?string
    {
        if (self::isOwner($user)) {
            return null;
        }

        $worth = self::promotionPercent(
            (string) $after['discount_type'],
            (float) ($after['discount_value'] ?? 0),
            $after['conditions'] ?? null,
        );

        if ($worth !== null && round($worth, 4) <= self::capPercent()) {
            return null;
        }

        // Switching the owner's promotion off — or tidying one that stays off
        // without touching what it is worth — gives nothing away. Anyone who
        // may manage marketing may still stop a promotion.
        if ($before !== null
            && !filter_var($after['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)
            && self::sameWorth($after, $before)) {
            return null;
        }

        return self::message();
    }

    /** 422 naming $field unless promotionRefusal() is null. */
    public static function assertPromotionAllowed(?User $user, array $after, ?array $before = null, string $field = 'discount_value'): void
    {
        if ($message = self::promotionRefusal($user, $after, $before)) {
            throw new DiscountAboveMaximum($field, $message);
        }
    }

    /**
     * The largest share of an order a coupon can take, in percent. A coupon
     * comes off the cart subtotal, so a fixed one is measured against the
     * smallest cart it can apply to: its minimum order amount, or the cheapest
     * item in its scope if that is higher. Free shipping takes nothing off the
     * goods. Null when there is nothing to measure against.
     *
     * @param  list<int>|null  $productIds
     * @param  list<int>|null  $categoryIds
     */
    public static function couponPercent(string $type, float $value, ?float $minimumOrder, ?array $productIds = null, ?array $categoryIds = null): ?float
    {
        $value = max(0.0, $value);

        if ($type === 'free_shipping' || $value <= 0.0) {
            return 0.0;
        }
        if ($type === 'percentage') {
            return min($value, 100.0);
        }

        $siteWide = empty($productIds) && empty($categoryIds);
        $smallest = max(
            (float) ($minimumOrder ?? 0),
            (float) (self::cheapestPrice($productIds ?: null, $categoryIds ?: null, $siteWide) ?? 0),
        );

        return $smallest > 0 ? min(100.0, $value / $smallest * 100) : null;
    }

    /** Null when this caller may set a coupon worth $percent, else the sentence. */
    public static function couponRefusal(?User $user, ?float $percent): ?string
    {
        if (self::isOwner($user)) {
            return null;
        }

        return $percent !== null && round($percent, 4) <= self::capPercent() ? null : self::message();
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * The lowest selling price, in the base currency, among the active
     * products in scope (their own rows and their variants'). Null if none is
     * priced.
     */
    private static function cheapestPrice(?array $productIds, ?array $categoryIds, bool $siteWide): ?float
    {
        $products = Product::query()->where('status', Product::STATUS_ACTIVE);

        if (!$siteWide) {
            $products->where(function ($q) use ($productIds, $categoryIds) {
                $any = false;
                if (is_array($productIds) && $productIds !== []) {
                    $q->orWhereIn('id', array_map('intval', $productIds));
                    $any = true;
                }
                if (is_array($categoryIds) && $categoryIds !== []) {
                    $q->orWhereIn('category_id', array_map('intval', $categoryIds));
                    $any = true;
                }
                if (!$any) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $cheapest = null;
        ProductPrice::query()
            ->whereIn('product_id', $products->select('id'))
            ->where('currency_code', CurrencyPricing::baseCode())
            ->where('regular_price', '>', 0)
            ->each(function (ProductPrice $row) use (&$cheapest) {
                $price = (float) $row->effective_price;
                if ($price > 0 && ($cheapest === null || $price < $cheapest)) {
                    $cheapest = $price;
                }
            });

        return $cheapest;
    }

    private static function sameWorth(array $a, array $b): bool
    {
        return (string) $a['discount_type'] === (string) $b['discount_type']
            && round((float) ($a['discount_value'] ?? 0), 2) === round((float) ($b['discount_value'] ?? 0), 2)
            && json_encode($a['conditions'] ?? null) === json_encode($b['conditions'] ?? null);
    }

    private static function formatPercent(float $p): string
    {
        return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
    }
}
