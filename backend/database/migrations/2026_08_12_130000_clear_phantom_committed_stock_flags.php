<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data fix: orders the 2026_07_11 back-fill marked committed that never drew a
 * single unit.
 *
 * That migration assumed one thing about every order in the table — "on_hand was
 * deducted at creation under the old model" — and stamped stock_committed_at on
 * all of them unconditionally. It was right about POS and online sales and wrong
 * about everything else: guest storefront orders (StorefrontCheckoutController
 * deliberately moves no stock), quote-originated orders, orders whose product had
 * no inventory row at the time, MTO-only orders. Those now claim goods left the
 * shelf that never did, so voiding or cancelling one hands the warehouse units it
 * never gave up — inventing stock out of a flag.
 *
 * Repaired by asking the ledger rather than assuming: an order that drew stock
 * has at least one inventory_transaction against it with a negative
 * quantity_change. No such row, no draw, so the committed flag is false and comes
 * off. Deliberately narrow:
 *
 *   - stock_reserved_at must be null. A reserved order is inside the new model
 *     and its flags were set by code that meant them, not by the back-fill.
 *   - Any negative transaction counts, not just transaction_type 'sale'. The
 *     ledger is never pruned, so this is a sound witness, and a wider net here
 *     fails safe: it leaves a flag alone rather than clearing one it shouldn't.
 *
 * stock_unwound_at is deliberately NOT touched. On an order that drew nothing it
 * is harmless (there is nothing to give back either way), and clearing it would
 * re-open a terminal order to an unwind it does not need.
 *
 * Runs after 2026_08_12_120000, which stamps the orders that DID draw stock —
 * those all carry a 'sale' row, so the two migrations never fight over an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')
            || !Schema::hasTable('inventory_transactions')
            || !Schema::hasColumn('orders', 'stock_committed_at')) {
            return;
        }

        DB::table('orders')
            ->whereNotNull('stock_committed_at')
            ->whereNull('stock_reserved_at')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('inventory_transactions as it')
                    ->whereColumn('it.reference_id', 'orders.id')
                    ->whereIn('it.reference_type', ['App\Models\Order', 'order'])
                    ->where('it.quantity_change', '<', 0);
            })
            ->update(['stock_committed_at' => null]);
    }

    /**
     * Not reversible: re-stamping would restore the phantom flag and with it the
     * invented stock. The schema change lives in 2026_07_11_120000.
     */
    public function down(): void
    {
    }
};
