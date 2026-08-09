<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COGS phase 1: snapshot the per-unit cost onto each order line at sale time.
 *
 * cost_price is the per-unit cost in KES resolved when the line was created
 * (variant-level product_prices.cost_price, falling back to the product-level
 * row — the same lookup MetricEngine::earnedPnl uses live). Snapshotting stops
 * the P&L from mutating retroactively whenever someone edits a product's cost.
 *
 * cost_source records where the number came from ('product_price' today;
 * 'purchase', 'production', 'manual' reserved for later phases). NULL cost on
 * historical/unpriced lines is expected — reports COALESCE to the live lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('cost_price', 12, 2)->nullable()->after('unit_price');
            $table->string('cost_source', 20)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'cost_source']);
        });
    }
};
