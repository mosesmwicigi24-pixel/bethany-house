<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pin each sale line to the exact inventory row it drew from.
 *
 * POS selects a specific finished-goods row to reserve/deduct from (outlet row,
 * warehouse fallback, variant vs product-level), picking the one with the most
 * available stock. But commit-on-payment, void, and abandoned-order restore
 * re-resolved the row by (variant + outlet) only — no product_id — so for simple
 * (no-variant) products they could hit an ARBITRARY other product's row, and for
 * variant products they could hit the outlet row when the warehouse row was the
 * one reserved. Recording the chosen row's id on the line makes every downstream
 * transition act on precisely the row the sale touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'inventory_item_id')) {
                $table->unsignedBigInteger('inventory_item_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'inventory_item_id')) {
                $table->dropColumn('inventory_item_id');
            }
        });
    }
};
