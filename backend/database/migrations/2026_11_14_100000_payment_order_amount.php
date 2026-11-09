<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a payment counts for on its order (owner, 2026-11-05).
 *
 * A payment keeps the currency it was paid in (amount, currency_code — cash
 * truth, reported at its own rate). When the order's currency later changes,
 * the payment also records what it counts for in the order's NEW currency:
 * order_amount in order_currency_code. Balances read that; nothing restates
 * the money that arrived.
 *
 * Both NULL (every payment before this, and every payment made in the
 * order's own currency): the payment counts as its own amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->decimal('order_amount', 14, 2)->nullable()->after('currency_code');
            $t->string('order_currency_code', 3)->nullable()->after('order_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn(['order_amount', 'order_currency_code']);
        });
    }
};
