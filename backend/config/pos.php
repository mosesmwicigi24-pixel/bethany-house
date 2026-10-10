<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discount maximum
    |--------------------------------------------------------------------------
    |
    | The largest discount, as a percentage of the line, cart or price it is
    | applied to, that ANYONE but a super_admin may give — the owner's rule of
    | 2026-10-03: "Our products are exotic and exclusive. I did not want
    | discounts given. The max is 5%." It bounds each line, the order as a
    | whole, promotions/campaigns/coupons and product sale prices that anyone
    | but a super_admin sets, and the sales agent — a line a running owner-set
    | promotion covers may lose that promotion's value, never more and never
    | stacked. `pos.discount_override` and `pos.discount_campaign` lift nothing. See App\Support\DiscountRule, the one place it is read.
    |
    | Since 2026-10-10 it is the DEFAULT for staff discounts: a role may carry
    | its own limit (table role_discount_caps — seeded pos_clerk 10, admin 15
    | — set by the super admin at Setup → Discount limits). A role without
    | one, and the sales agent's service account whatever its role, stay here.
    | Promotions, coupons and sale prices are always measured against this.
    | Raising it is the owner's decision.
    |
    */

    'discount_cap_percent' => (float) env('POS_DISCOUNT_CAP_PERCENT', 5.0),

    /*
     * The sales agent's service account. It is the only holder of
     * `pos.discount_campaign`, so naming it here is what keeps the grant
     * reproducible instead of a thing somebody once typed into production.
     * (Its old 70% ceiling, pos.agent_discount_cap_percent, is gone, and the
     * grant now lifts nothing: the agent is held like everyone else.)
     */
    'agent_user_email' => env('POS_AGENT_USER_EMAIL', 'neema-bot@bethanyhouse.co.ke'),

];
