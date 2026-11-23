<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Material sourcing at "Send to production" (owner, 2026-11-18/19).
 *
 * When a made-to-order line is sent to production, staff say for each
 * material on the bill of materials whether it comes FROM THE STORE or NEEDS
 * BUYING. What is bought goes onto the supplier's open draft purchase order,
 * each line tied to its job, and more jobs join the same draft until it is
 * submitted for approval.
 *
 * Sales staff never see a supplier (owner, 2026-11-19: "too much exposure of
 * suppliers to sales staff"): the supplier is set once on the material by
 * procurement and chosen by the hub.
 *
 *   materials.preferred_supplier_id        who this material is bought from
 *   purchase_orders.collects_production_needs
 *                                          the supplier's open draft that
 *                                          production needs are added to
 *   purchase_order_items.production_order_id
 *                                          the job a line was raised for
 *   material_allocations.quantity_reserved held in the store for the job
 *   material_allocations.quantity_to_buy   asked of procurement, not yet in
 *   material_allocations.sourced_at / _by  who decided, and when
 *
 * Additive and nullable; nothing existing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->boolean('collects_production_needs')->default(false);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $table->index('production_order_id');
        });

        Schema::table('material_allocations', function (Blueprint $table) {
            $table->decimal('quantity_reserved', 12, 2)->default(0);
            $table->decimal('quantity_to_buy', 12, 2)->default(0);
            $table->timestamp('sourced_at')->nullable();
            $table->foreignId('sourced_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sourced_by');
            $table->dropColumn(['quantity_reserved', 'quantity_to_buy', 'sourced_at']);
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_order_id');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('collects_production_needs');
        });
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_supplier_id');
        });
    }
};
