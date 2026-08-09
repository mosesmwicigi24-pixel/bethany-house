<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily sales aggregates imported from the legacy POS (years of pre-hub
 * history living in a separate MySQL). The hub's own orders only start in
 * mid-2026 — far too young to see a liturgical year — so seasonal demand
 * projections lean on this table for the historical seasonal shape.
 *
 * ANALYTICS ONLY. These rows are aggregates keyed by the legacy product NAME
 * (product_id is a best-effort match, nullable); they must never be joined
 * into operational reports, revenue truth, or money reconciliation. The only
 * consumer is MetricEngine::seasonalDemand(). Operator runbook:
 * `php artisan seasonal:import-legacy <export.json> [--map=names.json]`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_sales_daily', function (Blueprint $table) {
            $table->id();
            $table->date('sale_date');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name', 200);
            $table->decimal('units', 12, 2)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->timestamps();

            // Import idempotency: one row per legacy name per day.
            $table->unique(['sale_date', 'product_name']);
            // The seasonal-demand query path: per-product date-range sums.
            $table->index(['product_id', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_sales_daily');
    }
};
