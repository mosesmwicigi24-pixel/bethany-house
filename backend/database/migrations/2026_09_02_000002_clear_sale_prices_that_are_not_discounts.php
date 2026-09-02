<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear every "sale price" that never discounted anything.
 *
 * A sale_price with no window is on sale FOREVER (ProductPrice::isOnSale returns
 * true when both dates are null), so a sale price equal to the regular price
 * marks the product permanently on sale at no saving. Measured on production:
 *
 *   · 180 rows across 61 products where sale_price = regular_price
 *   ·   2 rows where sale_price was ABOVE it — Cassock (VES-PC-001) listed at
 *       13,000 with a 20,000 "sale", and Communion Wafer Bread 200PCs at 400
 *       with a 500 "sale". The storefront bills effective_price and the till
 *       bills regular_price, so those two carried a different price depending
 *       on which door the customer came through.
 *
 * The owner's instruction: a sale price equal to the regular price is not a
 * sale — the selling price comes from Regular Price, and the Sale Price box
 * stays empty until a discount is deliberately set.
 *
 * The window goes with it. A start/end date guarding a price that no longer
 * exists is just a trap for whoever reads the row next.
 *
 * Raw SQL on purpose: ProductPrice::saving now REFUSES a sale price that is not
 * a discount, so loading these rows as models to fix them would throw on the
 * very rows this exists to repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_prices')
            ->whereNotNull('sale_price')
            ->whereColumn('sale_price', '>=', 'regular_price')
            ->update([
                'sale_price'      => null,
                'sale_start_date' => null,
                'sale_end_date'   => null,
                'updated_at'      => now(),
            ]);
    }

    /**
     * Not reversible, and should not be: restoring these would put back a
     * permanent no-op sale and two prices above list. Nothing is lost that the
     * regular price does not already say.
     */
    public function down(): void
    {
        //
    }
};
