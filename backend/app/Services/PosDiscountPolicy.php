<?php

namespace App\Services;

use App\Models\User;

/**
 * Who may discount at the till, and by how much.
 *
 * `pos.discount` — "Apply manual discounts at POS" — has existed in the
 * catalogue since the permission system was built, is granted to pos_clerk and
 * outlet_manager, and was checked NOWHERE. Every one of the three POS entry
 * points accepted `discount_type` / `discount_value` with `min:0` and no upper
 * bound, from any caller who could reach the till. Discount authority was a
 * line on the Roles screen and nothing else.
 *
 * That matters more than it first looks. A void at least leaves a reversal
 * somebody can count; a discount just completes the sale for less, and the only
 * trace is a number on a line nobody re-reads. It is the quietest way money
 * leaves a till.
 *
 * Two rules, applied identically to a line discount and to a cart discount:
 *
 *   1. No `pos.discount`, no discount. At all.
 *   2. With it, up to config('pos.discount_cap_percent') of whatever the
 *      discount is being applied to. Beyond that needs
 *      `pos.discount_override`.
 *
 * The cap is checked against the EFFECTIVE percentage, not the input, so a flat
 * discount cannot walk around a percentage ceiling — 500 off a 1,000 line is
 * 50% however it was typed.
 *
 * @see \Tests\Feature\PosDiscountPolicyTest
 */
final class PosDiscountPolicy
{
    /**
     * 403 unless this discount is one the caller may give.
     *
     * @param  float   $discount  The resolved discount AMOUNT, after flat/percent
     *                            has been worked out — never the raw input.
     * @param  float   $base      What it is applied to: the line's gross, or the
     *                            cart's subtotal before the cart discount.
     * @param  string  $scope     'line' or 'cart', for the message only.
     */
    public static function assertAllowed(?User $user, float $discount, float $base, string $scope = 'line'): void
    {
        $discount = round($discount, 2);

        // No discount, nothing to authorise. Keeps the ordinary sale — which is
        // the overwhelming majority — free of any permission lookup.
        if ($discount <= 0.0) {
            return;
        }

        if (!$user?->can('pos.discount')) {
            abort(403, 'You are not permitted to apply discounts.');
        }

        if ($user->can('pos.discount_override')) {
            return;
        }

        $cap = self::capPercent();

        // Rounded to the column before comparing, so float noise in
        // ($base * $cap / 100) cannot refuse a discount that is exactly at the
        // ceiling. A base of zero yields a ceiling of zero, which is right: a
        // discount on nothing is unbounded by definition.
        $ceiling = round($base * $cap / 100, 2);

        if ($discount > $ceiling) {
            abort(403, sprintf(
                'A %s%% ceiling applies to POS discounts. This %s discount of %s exceeds the %s allowed on %s. A supervisor can approve a larger one.',
                rtrim(rtrim(number_format($cap, 2, '.', ''), '0'), '.'),
                $scope,
                number_format($discount, 2),
                number_format($ceiling, 2),
                number_format($base, 2),
            ));
        }
    }

    public static function capPercent(): float
    {
        return (float) config('pos.discount_cap_percent', 5.0);
    }
}
