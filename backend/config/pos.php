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

];
