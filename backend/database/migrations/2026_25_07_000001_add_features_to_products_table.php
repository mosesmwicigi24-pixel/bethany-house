<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product-level selling-point features shown on the storefront product page —
 * an ordered list of { icon, text } (e.g. "24K gold-plated brass", "Free
 * engraving available"). Stored as JSON on the product, edited from the new
 * Features tab in the product form, the same way measurements are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('features')->nullable()->after('measurements');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }
};
