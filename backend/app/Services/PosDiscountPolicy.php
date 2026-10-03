<?php

namespace App\Services;

use App\Models\User;
use App\Support\DiscountRule;

/**
 * Who may discount at the till, and by how much.
 *
 * `pos.discount` — "Apply manual discounts at POS" — existed in the catalogue
 * for a long time and was checked NOWHERE: every POS entry point accepted
 * `discount_type` / `discount_value` with `min:0` and no upper bound. A void
 * at least leaves a reversal somebody can count; a discount just completes the
 * sale for less, and the only trace is a number on a line nobody re-reads.
 *
 * Two rules, applied identically to a line discount and to a cart discount:
 *
 *   1. No `pos.discount`, no discount. At all. (403 — a missing permission.)
 *   2. With it, at most what App\Support\DiscountRule allows: 5% of whatever
 *      the discount is applied to, for everyone but a super_admin. (422.)
 *
 * `pos.discount_override` used to lift rule 2. Since the owner's rule of
 * 2026-10-03 it lifts nothing; the slug stays so existing grants and the Roles
 * screen keep working. The sales agent's `pos.discount_campaign` is handled
 * inside DiscountRule (a running owner-set promotion is its only lift).
 *
 * @see \App\Support\DiscountRule
 * @see \Tests\Feature\PosDiscountPolicyTest
 */
final class PosDiscountPolicy
{
    /**
     * 403 without `pos.discount`; 422 (naming $field) above the maximum.
     *
     * @param  float   $discount        The resolved discount AMOUNT, after flat/percent
     *                                  has been worked out — never the raw input.
     * @param  float   $base            What it is applied to: the line's gross, or the
     *                                  cart's subtotal before the cart discount.
     * @param  string  $field           The request field the discount came in on.
     * @param  float   $agentAllowance  See DiscountRule::refusal().
     */
    public static function assertAllowed(?User $user, float $discount, float $base, string $field, float $agentAllowance = 0.0): void
    {
        // No discount, nothing to authorise. Keeps the ordinary sale — which is
        // the overwhelming majority — free of any permission lookup.
        if (round($discount, 2) <= 0.0) {
            return;
        }

        if (!$user?->can('pos.discount')) {
            abort(403, 'You are not permitted to apply discounts.');
        }

        DiscountRule::assertWithin($user, $discount, $base, $field, $agentAllowance);
    }
}
