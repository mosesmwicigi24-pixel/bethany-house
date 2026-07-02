<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns required for lossless POS order restore.
 *
 * order_items
 *   discount_type   – 'none' | 'flat' | 'percent'  (was only discount_amount stored)
 *   discount_value  – the raw value entered by the cashier (e.g. 10 for 10%)
 *   measurement_values – JSON blob of structured measurement fields for MTO items
 *
 * orders
 *   cart_discount_type   – 'none' | 'flat' | 'percent'
 *   cart_discount_value  – raw cart-level discount value
 *   customer_id          – FK to customers table (was missing from the POS order shape)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Discount type/value — persisted so restore can reconstruct the
            // exact discount the cashier entered rather than the resolved amount.
            $table->string('discount_type', 10)->default('none')->after('discount_amount');
            $table->decimal('discount_value', 12, 4)->default(0)->after('discount_type');

            // Structured measurement values for MTO (made-to-order) items.
            // Stored as JSON: {"chest": "42in", "waist": "34in"}.
            // Null for regular (non-MTO) lines.
            $table->json('measurement_values')->nullable()->after('notes');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Cart-level discount type and raw value — separate from the resolved
            // discount_amount so restore can reconstruct it without conflating it
            // with per-item discounts.
            $table->string('cart_discount_type', 10)->default('none')->after('discount_amount');
            $table->decimal('cart_discount_value', 12, 4)->default(0)->after('cart_discount_type');

            // Customer FK — was stored on customer_first_name/last_name/phone/email
            // columns but not linked back to the customers table, breaking restore.
            $table->unsignedBigInteger('customer_id')->nullable()->after('user_id');
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'measurement_values']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn(['cart_discount_type', 'cart_discount_value', 'customer_id']);
        });
    }
};