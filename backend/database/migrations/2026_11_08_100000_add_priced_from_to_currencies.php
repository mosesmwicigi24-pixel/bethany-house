<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * currencies.priced_from — which price list a currency's customer prices are
 * converted from when a product has no price of its own in that currency
 * (owner, 2026-10-29). NULL = the shop's own (KES), which is how every
 * currency priced before this column. 'USD' for the rand: a Cope set at
 * USD 150 is quoted in rand from that 150 at the rand's pricing rate, so
 * South Africa follows the international list rather than the shilling one.
 *
 * Customer pricing only — reports never read it (they convert at each
 * record's stored reporting rate). Exact conversion, no rounding: a price
 * typed on a product for that currency always holds. Changed through the
 * customer_pricing_fx_change proposal, like the pricing rate itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $t) {
            $t->string('priced_from', 3)->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('currencies', fn (Blueprint $t) => $t->dropColumn('priced_from'));
    }
};
