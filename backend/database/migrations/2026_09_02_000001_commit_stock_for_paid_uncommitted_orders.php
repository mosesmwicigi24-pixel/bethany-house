<?php

use App\Models\Order;
use App\Services\PosInventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Take the goods off the shelf for orders that were paid and never deducted.
 *
 * PublicPaymentController settled the money and never called commitForOrder,
 * so every order a CUSTOMER paid through their own link recorded the payment
 * and moved no stock. Fixed in code by 2026-09-02 (the deduction now hangs off
 * Order::syncPaymentStatus, which every payment door calls). This migration
 * repairs what the gap already produced.
 *
 * Measured on production the day it was written: 82 orders, KES 1,159,695,
 * from 10 July onward — 181 sellable lines, 1,562 units. Every affected
 * inventory row was checked first and has both the on-hand and the reserved
 * quantity to absorb its deduction, so nothing here hits commitReservation's
 * max(0, …) floor and no count is silently clamped.
 *
 * SELECTED BY PREDICATE, NOT BY ID. The predicate IS the defect — paid, still
 * holding a reservation, never committed, never unwound, not dead — so this
 * reads correctly in any environment and is a no-op in a fresh one. The date
 * bound keeps it a repair of history rather than a sweeper competing with the
 * code fix.
 *
 * Idempotent: commitForOrder returns early on stock_committed_at, so a re-run
 * deducts nothing. Every deduction writes an inventory_transactions row of
 * type 'sale' referencing its order, attributed to whoever made the sale.
 */
return new class extends Migration
{
    /** Orders created before the fix shipped. */
    private const CUTOFF = '2026-09-03 00:00:00';

    public function up(): void
    {
        // Reconciliation runs outside any user's view of the world.
        $orders = Order::withoutViewerScope()
            ->where('payment_status', 'paid')
            ->whereNotNull('stock_reserved_at')
            ->whereNull('stock_committed_at')
            ->whereNull('stock_unwound_at')
            ->whereNotIn('status', Order::DEAD_STATUSES)
            ->where('created_at', '<', self::CUTOFF)
            ->with('items')
            ->get();

        $done = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                PosInventoryService::commitForOrder($order, $order->created_by);
                $done++;
            } catch (\Throwable $e) {
                // One unhappy order must not abandon the other eighty-one, and
                // must not be silent either: it stays uncommitted and named.
                $failed++;
                Log::error('stock backfill skipped an order', [
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'reason'       => $e->getMessage(),
                ]);
            }
        }

        Log::info('stock backfill for paid uncommitted orders', [
            'considered' => $orders->count(),
            'committed'  => $done,
            'failed'     => $failed,
        ]);
    }

    /**
     * Not reversible. Putting 1,562 units back would assert the goods are on
     * the shelf, and they are not — they left with the customers who paid for
     * them. Correcting a count downward is the whole point; a rollback here
     * would recreate the defect as data.
     */
    public function down(): void
    {
        //
    }
};
