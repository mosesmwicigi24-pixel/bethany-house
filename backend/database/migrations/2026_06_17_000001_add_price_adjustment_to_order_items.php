<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration: add_price_adjustment_to_order_items
 *
 * Adds two columns to order_items to support manual price overrides at the
 * POS or on unpaid admin orders:
 *
 *   original_price  — snapshot of the catalogue unit_price at the moment
 *                     the override was first applied.  NULL means no override
 *                     was ever made on this line (standard catalogue price).
 *                     Once written, this value is NEVER updated again — it is
 *                     an immutable audit record of what the price was before
 *                     the cashier/admin changed it.
 *
 *   price_adjusted  — boolean flag.  true when a cashier or admin manually
 *                     set unit_price above the catalogue price.  Used by
 *                     reporting queries to surface adjusted lines quickly
 *                     (e.g. SELECT * FROM order_items WHERE price_adjusted = true).
 *
 * Existing rows are left with original_price = NULL and price_adjusted = false,
 * which correctly signals "no adjustment was ever made".
 *
 * Also adds an index on price_adjusted to make reporting queries fast even on
 * large order_items tables.
 *
 * ── Companion changes required (already applied in code) ────────────────────
 *
 *  OrderItem model  — add 'original_price' and 'price_adjusted' to $fillable
 *                     and $casts (see bottom of this file for the snippet).
 *
 *  OrderController  — adjustItemPrice() method reads/writes these columns.
 *                     show() annotates each item with original_price and
 *                     price_adjusted in its JSON response.
 *
 *  PosController    — createSale(), createPendingOrder(), updatePendingOrder()
 *                     pass original_price and price_adjusted through from the
 *                     frontend cart state when present.
 *
 *  PosPage.tsx      — CartRow exposes a "price" override button; cart state
 *                     tracks original_price and price_adjusted per line.
 *
 *  OrderDetailPage  — unit price cell shows an inline edit button on orders
 *                     with payment_status = 'pending'; adjusted lines show
 *                     an "✎ Adjusted" badge and the original price.
 *
 * ── Reporting ────────────────────────────────────────────────────────────────
 *
 *  To find all adjusted lines in a date range:
 *
 *      SELECT
 *          oi.id,
 *          oi.product_name,
 *          oi.original_price,
 *          oi.unit_price,
 *          oi.unit_price - oi.original_price AS price_uplift,
 *          (oi.unit_price - oi.original_price) * oi.quantity AS uplift_total,
 *          o.order_number,
 *          o.created_at,
 *          o.outlet_id
 *      FROM order_items oi
 *      JOIN orders o ON o.id = oi.order_id
 *      WHERE oi.price_adjusted = true
 *        AND o.created_at BETWEEN :start AND :end
 *      ORDER BY o.created_at DESC;
 *
 * ── Rollback ─────────────────────────────────────────────────────────────────
 *
 *  php artisan migrate:rollback --step=1
 *
 *  The down() method safely drops both columns and the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {

            // ── original_price ─────────────────────────────────────────────────
            // Stores the catalogue unit_price at the moment of first override.
            // NULL = catalogue price was never changed on this line.
            // Placed immediately after unit_price for logical grouping.
            $table->decimal('original_price', 12, 2)
                ->nullable()
                ->default(null)
                ->after('unit_price')
                ->comment('Catalogue price before any manual adjustment. NULL = no adjustment made.');

            // ── price_adjusted ────────────────────────────────────────────────
            // Boolean flag: true when unit_price was manually raised by staff.
            // Default false so all existing rows are treated as unadjusted.
            $table->boolean('price_adjusted')
                ->default(false)
                ->after('original_price')
                ->comment('True when a cashier or admin manually overrode the unit price upward.');

            // ── Index for reporting queries ────────────────────────────────────
            // Allows "WHERE price_adjusted = true" scans to skip unmodified rows
            // efficiently, even on tables with millions of order items.
            // Partial indexes are PostgreSQL-specific; this is a regular index
            // that works on both PostgreSQL and MySQL/MariaDB.
            $table->index(['price_adjusted'], 'order_items_price_adjusted_idx');

            // ── Composite index for date-range reporting ───────────────────────
            // Speeds up the most common audit query:
            //   WHERE oi.price_adjusted = true AND o.created_at BETWEEN ...
            // The join to orders is still needed, but this index prevents a
            // full scan of order_items when price_adjusted = true is selective.
            // Omit if your order_items table is small (< 100k rows).
            // $table->index(['price_adjusted', 'order_id'], 'order_items_adjusted_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_price_adjusted_idx');
            $table->dropColumn(['original_price', 'price_adjusted']);
        });
    }
};

/*
|--------------------------------------------------------------------------
| OrderItem model — add to $fillable and $casts
|--------------------------------------------------------------------------
|
| In app/Models/OrderItem.php, update the two arrays as shown below.
| The full model file is shipped separately; this comment is for reference.
|
| protected $fillable = [
|     'order_id',
|     'product_id',
|     'product_variant_id',
|     'product_name',
|     'variant_name',
|     'sku',
|     'quantity',
|     'unit_price',
|     'original_price',       // ← new
|     'price_adjusted',       // ← new
|     'discount_amount',
|     'tax_amount',
|     'total_price',
|     'production_order_id',
|     'notes',
| ];
|
| protected $casts = [
|     'quantity'        => 'integer',
|     'unit_price'      => 'decimal:2',
|     'original_price'  => 'decimal:2',   // ← new
|     'price_adjusted'  => 'boolean',     // ← new
|     'discount_amount' => 'decimal:2',
|     'tax_amount'      => 'decimal:2',
|     'total_price'     => 'decimal:2',
| ];
|
*/