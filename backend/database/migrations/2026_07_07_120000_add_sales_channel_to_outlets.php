<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales channel per outlet.
 *
 * WhatsApp orders are captured through a dedicated "WhatsApp Orders (Neema)"
 * outlet, but they were indistinguishable from physical-store POS orders (both
 * order_type='pos'). This tags each outlet with the sales channel it represents
 * so orders can be split into POS / WhatsApp (and Online, which is order_type
 * driven) in the Sales navigation.
 *
 *   pos      → physical point-of-sale (default)
 *   whatsapp → orders taken over WhatsApp
 *   online   → storefront pickup/branch outlet (rare; online orders are keyed
 *              off order_type, so this is informational)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('outlets', 'sales_channel')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->string('sales_channel')->default('pos')->after('outlet_type');
            });
        }

        // Mark the existing WhatsApp/Neema outlet(s) so their orders group under
        // "WhatsApp Orders" instead of "POS Orders".
        DB::table('outlets')
            ->where('name', 'ILIKE', '%whatsapp%')
            ->orWhere('name', 'ILIKE', '%neema%')
            ->update(['sales_channel' => 'whatsapp']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('outlets', 'sales_channel')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->dropColumn('sales_channel');
            });
        }
    }
};
