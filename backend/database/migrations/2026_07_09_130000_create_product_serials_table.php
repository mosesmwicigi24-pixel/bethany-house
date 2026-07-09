<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item serial tracking (Phase 1).
 *
 * Every unit produced through a production order gets its own unique number,
 * assigned when the order is approved for production and tracked through its
 * whole life: in_production → in_stock → sold → dispatched (→ returned). This is
 * the foundation for knowing exactly whether a specific product is still on the
 * shelf or has left the shop, and for loss/theft detection via reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->unique();

            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('production_order_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();

            // in_production | in_stock | sold | dispatched | returned | cancelled
            $table->string('status')->default('in_production')->index();

            // The sale that sold this unit (Phase 2) + lifecycle timestamps.
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
    }
};
