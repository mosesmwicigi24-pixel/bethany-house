<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discount ceiling
    |--------------------------------------------------------------------------
    |
    | The largest discount, as a percentage of the line or cart it is applied
    | to, that a holder of `pos.discount` may give unaided. Anything above it
    | needs `pos.discount_override`.
    |
    | This is a leakage control, not a pricing rule: discounting is the quietest
    | way money leaves a till, because unlike a void it produces no reversal to
    | notice. Raising it is a business decision — make it deliberately.
    |
    */

    'discount_cap_percent' => (float) env('POS_DISCOUNT_CAP_PERCENT', 5.0),

    /*
     * The ceiling for a caller holding `pos.discount_campaign` — the agent's
     * service account passing through an owner-declared campaign, not a person
     * exercising discretion at a till. Matches the hard 70% limit Neema's own
     * campaign parser enforces, so this bounds the blast radius rather than
     * setting policy: the real limit is the campaign the owner declares.
     */
    'agent_discount_cap_percent' => (float) env('POS_AGENT_DISCOUNT_CAP_PERCENT', 70.0),

    /*
     * The sales agent's service account. It is the only holder of
     * `pos.discount_campaign`, so naming it here is what keeps the grant
     * reproducible instead of a thing somebody once typed into production.
     */
    'agent_user_email' => env('POS_AGENT_USER_EMAIL', 'neema-bot@bethanyhouse.co.ke'),

];
