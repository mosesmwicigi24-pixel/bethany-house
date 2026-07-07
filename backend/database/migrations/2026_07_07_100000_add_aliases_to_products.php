<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alternate names a product is known by (e.g. "bishops ring", "eliad oil").
     * Lets the Neema WhatsApp agent match a shopper's phrasing to the catalogue
     * SKU. Nullable JSON array — safe, additive.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('measurements');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
