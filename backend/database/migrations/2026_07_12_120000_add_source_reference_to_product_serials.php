<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serialize NON-manufactured stock too.
 *
 * Until now only units produced through a production order got a serial (traced
 * via production_order_id). Goods bought in for resale — received from a
 * supplier, restocked, or adjusted up — entered inventory with no per-unit code,
 * so they couldn't be tracked, dispatched-with-authorization, or reconciled for
 * loss the way produced goods can.
 *
 * `source_reference` is a free-form, indexed tag for where a non-production
 * serial came from (e.g. "purchase_order:123", "stock_adjustment:45"). It makes
 * receipt-time serial minting idempotent (re-receiving the same reference is a
 * no-op) and keeps every unit traceable to its inbound event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            if (!Schema::hasColumn('product_serials', 'source_reference')) {
                $table->string('source_reference')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            if (Schema::hasColumn('product_serials', 'source_reference')) {
                $table->dropColumn('source_reference');
            }
        });
    }
};
