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
    | discounts given. The max is 5%." It also bounds promotions, campaigns and
    | coupons that anyone but a super_admin may set, and the sales agent unless
    | a running owner-set promotion covers the line. `pos.discount_override`
    | no longer lifts it. See App\Support\DiscountRule, the one place it is read.
    |
    | This is the single number. Raising it is the owner's decision.
    |
    */

    'discount_cap_percent' => (float) env('POS_DISCOUNT_CAP_PERCENT', 5.0),

    /*
     * The sales agent's service account. It is the only holder of
     * `pos.discount_campaign`, so naming it here is what keeps the grant
     * reproducible instead of a thing somebody once typed into production.
     * (Its old 70% ceiling, pos.agent_discount_cap_percent, is gone: the agent
     * is held to discount_cap_percent unless a running promotion covers the line.)
     */
    'agent_user_email' => env('POS_AGENT_USER_EMAIL', 'neema-bot@bethanyhouse.co.ke'),

];
