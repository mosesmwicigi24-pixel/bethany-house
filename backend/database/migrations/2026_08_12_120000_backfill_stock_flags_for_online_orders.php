<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data fix: online orders whose stock left the shelf but which carry no
 * reservation flags at all.
 *
 * 2026_07_11_120000 introduced stock_reserved_at / stock_committed_at /
 * stock_unwound_at and back-filled every order that existed at that moment. It
 * could not, however, fix the code: OrderController::checkout() kept deducting
 * quantity_on_hand with a bare adjustQuantity(-qty, 'sale', …) and stamped
 * nothing. Every online order placed since has all three flags null while its
 * stock has physically gone — so PosInventoryService::unwindForOrder() read
 * "never committed" and released a reservation that was never taken, leaving the
 * deducted units stranded on a voided/cancelled order.
 *
 * checkout() now stamps stock_committed_at. This migration repairs the orders
 * created in the window between the two deploys, identified exactly as the
 * damage is defined: a 'sale' inventory_transaction pointing at the order, and
 * all three flags null.
 *
 *   1. Pin order_items.inventory_item_id from the ledger wherever it is missing,
 *      so an unwind restores to the very row the sale deducted from rather than
 *      re-resolving by product/variant and possibly landing on another outlet's
 *      row (or none at all).
 *   2. Stamp stock_committed_at = when the stock actually moved (the earliest
 *      'sale' transaction), giving these orders the same "committed, never
 *      reserved" shape the 2026_07_11 back-fill gave legacy orders — the shape
 *      every reader already handles correctly.
 *   3. Stamp stock_unwound_at on those whose stock has ALREADY been put back —
 *      cancelled/voided orders, or any order carrying a restore transaction —
 *      so the repaired flags cannot trigger a second restore.
 *
 * Postgres-only (the schema already is; see phpunit.xml). Idempotent: re-running
 * matches nothing, because step 2 removes every row from step 1's predicate.
 */
return new class extends Migration
{
    /** reference_type values the codebase has written for an Order. */
    private const ORDER_REFS = ['App\Models\Order', 'order'];

    /** Transaction types that mean "this order's stock went back on the shelf". */
    private const RESTORE_TYPES = ['void_return', 'abandon_restore', 'cancellation', 'void'];

    public function up(): void
    {
        if (!Schema::hasTable('orders')
            || !Schema::hasTable('inventory_items')
            || !Schema::hasTable('inventory_transactions')
            || !Schema::hasColumn('orders', 'stock_committed_at')) {
            return;
        }

        $refs = self::ORDER_REFS;

        // ── The damaged set: drew stock, carries no flag ──────────────────────
        // Captured up-front so steps 2 and 3 act on the same orders regardless of
        // the order in which they run.
        $brokenIds = DB::table('orders')
            ->whereNull('stock_reserved_at')
            ->whereNull('stock_committed_at')
            ->whereNull('stock_unwound_at')
            ->whereExists(function ($q) use ($refs) {
                $q->selectRaw('1')
                    ->from('inventory_transactions as it')
                    ->whereColumn('it.reference_id', 'orders.id')
                    ->whereIn('it.reference_type', $refs)
                    ->where('it.transaction_type', 'sale');
            })
            ->pluck('id')
            ->all();

        // ── 1. Pin the finished-goods row every line actually drew from ──────
        // Applies to ALL legacy lines, not just the damaged orders: the ledger
        // knows which inventory_item each sale hit, and writing that onto the
        // line is what lets PosInventoryService resolve it exactly later. Only
        // ever fills NULLs, and only from a transaction that (a) belongs to this
        // line's order and (b) sits on an inventory row for this line's exact
        // product + variant, so it can never invent a link.
        if (Schema::hasColumn('order_items', 'inventory_item_id')) {
            DB::statement(
                <<<'SQL'
                UPDATE order_items AS oi
                SET inventory_item_id = sub.inventory_item_id
                FROM (
                    SELECT oi2.id AS order_item_id,
                           MIN(it.inventory_item_id) AS inventory_item_id
                    FROM order_items oi2
                    JOIN inventory_transactions it
                      ON it.reference_id = oi2.order_id
                     AND it.reference_type IN (?, ?)
                     AND it.transaction_type = 'sale'
                    JOIN inventory_items ii
                      ON ii.id = it.inventory_item_id
                     AND ii.product_id = oi2.product_id
                     AND ii.product_variant_id IS NOT DISTINCT FROM oi2.product_variant_id
                    WHERE oi2.inventory_item_id IS NULL
                    GROUP BY oi2.id
                ) AS sub
                WHERE oi.id = sub.order_item_id
                  AND oi.inventory_item_id IS NULL
                SQL,
                self::ORDER_REFS,
            );
        }

        if (empty($brokenIds)) {
            return;
        }

        // ── 2. The stock left when the sale was ledgered ─────────────────────
        foreach (array_chunk($brokenIds, 1000) as $chunk) {
            DB::table('orders')
                ->whereIn('id', $chunk)
                ->update([
                    'stock_committed_at' => DB::raw(
                        "COALESCE((
                            SELECT MIN(it.created_at)
                            FROM inventory_transactions it
                            WHERE it.reference_id = orders.id
                              AND it.reference_type IN ('App\\Models\\Order', 'order')
                              AND it.transaction_type = 'sale'
                        ), orders.created_at, orders.updated_at)"
                    ),
                ]);
        }

        // ── 3. …and came back already, for the ones that ended terminal ──────
        // Their stock was restored by the old blind adjustQuantity(+qty) in
        // cancelOrder()/voidOrder(). Without this stamp the newly-truthful
        // stock_committed_at would let a later unwind restore it a second time.
        foreach (array_chunk($brokenIds, 1000) as $chunk) {
            DB::table('orders')
                ->whereIn('id', $chunk)
                ->where(function ($q) use ($refs) {
                    $q->whereIn('status', ['cancelled', 'voided'])
                        ->orWhereExists(function ($sub) use ($refs) {
                            $sub->selectRaw('1')
                                ->from('inventory_transactions as it')
                                ->whereColumn('it.reference_id', 'orders.id')
                                ->whereIn('it.reference_type', $refs)
                                ->whereIn('it.transaction_type', self::RESTORE_TYPES);
                        });
                })
                ->update([
                    'stock_unwound_at' => DB::raw('COALESCE(cancelled_at, updated_at)'),
                ]);
        }
    }

    /**
     * Not reversible: once stamped, a repaired order is indistinguishable from
     * one that was always correct, and clearing the flags would re-create the
     * double-restore bug. The schema change lives in 2026_07_11_120000.
     */
    public function down(): void
    {
    }
};
