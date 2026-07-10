<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow the warehouse (no-outlet) inventory row.
 *
 * The whole finished-goods model relies on a null-outlet "warehouse" row that
 * POS treats as a sell-from-anywhere pool and that purchase-order warehouse
 * receives write to. But `inventory_items.outlet_id` was created NOT NULL
 * (`foreignId()->constrained()`), so on a fresh schema those rows can't be
 * created at all — every warehouse receive / warehouse fallback 500s. Production
 * has been running with a nullable column (historical divergence); this aligns a
 * fresh migrate with that reality. Idempotent: DROP NOT NULL on an
 * already-nullable column is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inventory_items', 'outlet_id')) {
            DB::statement('ALTER TABLE inventory_items ALTER COLUMN outlet_id DROP NOT NULL');
        }

        // Prevent duplicate warehouse rows for the same product/variant. The
        // original unique(product_id, product_variant_id, outlet_id) treats NULLs
        // as distinct, so it does not cover null-outlet rows — add a partial
        // unique index that does (COALESCE folds the nullable variant too).
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_inventory_warehouse_row '
            . 'ON inventory_items (product_id, COALESCE(product_variant_id, 0)) '
            . 'WHERE outlet_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_inventory_warehouse_row');
        // Leaving the column nullable on rollback is intentional — re-imposing NOT
        // NULL would fail if any warehouse rows exist.
    }
};
