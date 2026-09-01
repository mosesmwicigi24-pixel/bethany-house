<?php

use App\Models\Order;
use App\Services\PosInventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give back the 12 till sales the system cancelled on its own.
 *
 * An hourly job cancelled unpaid POS orders older than 24 hours. Between 10
 * July and 30 August it cancelled 12 real till sales worth KES 170,400 — Faith
 * Muriuki's 49,500, olga Auma's 39,300, Cornelius achanga's 12,000 and nine
 * more. Every one was rung up by a named person at the counter: Priscilla
 * Ngari (7), David Nganga (2), everlyne jane (2), Moses (1). A day's silence
 * is not an abandonment.
 *
 * Owner's rule (2026-09-01): the system does not cancel receipts; only a person
 * does. The hourly schedule is gone and the command now reports unless a human
 * passes --force.
 *
 * ── Why the ids are listed, not matched ──────────────────────────────────────
 * An earlier draft selected on `notes ILIKE '%Auto-cancelled…%'`. That text is
 * also written by the FIRST reaper (CancelAbandonedPosPendingOrders, July),
 * which cancelled a different batch WITHOUT unwinding stock — a third flag
 * shape this migration is not written for. These 12 ids were audited one by
 * one; restore exactly what was checked.
 *
 * ── Why the stock is re-reserved ─────────────────────────────────────────────
 * An earlier draft left the orders live with stock_unwound_at still set,
 * reasoning that re-taking stock would "invent a shortage". That was wrong, and
 * it confused RESERVING with DEDUCTING: reserveUnits() only increments
 * quantity_reserved and never touches quantity_on_hand, so the physical count
 * is untouched either way.
 *
 * Worse, the shape it would have left is one no reader in the app handles:
 *   - paying order 54/109/122 would deduct NOTHING (commitForOrder returns
 *     early on stock_committed_at) — goods leave, the count never moves;
 *   - paying the other 8 would commit a reservation released back in July,
 *     stealing units earmarked for other live orders;
 *   - editing any of them returned their units a SECOND time (fixed separately
 *     in PosController, which did not read stock_unwound_at);
 *   - and a later human cancel would early-return, never releasing the hold.
 *
 * So each order goes back into the ORDINARY model: all three flags cleared,
 * lines reserved. Every reader handles that correctly. Available-to-sell drops
 * by what these orders claim, which is the truth and is visible on the
 * low-stock report; if the shop needs an item, a human cancels the order and
 * the reservation is released on the spot.
 */
return new class extends Migration
{
    /** The 12 audited orders. Ids, because the note text is not a safe key. */
    private const ORDER_IDS = [54, 109, 121, 122, 140, 243, 263, 265, 362, 522, 626, 662];

    public function up(): void
    {
        foreach (self::ORDER_IDS as $id) {
            $order = Order::withoutViewerScope()->with('items')->find($id);
            if (!$order || $order->status !== 'cancelled') {
                continue;                       // already restored, or gone — re-run safe
            }

            // Put the order back into the ordinary reservation model BEFORE it
            // goes live, so it is never briefly live with a stale hold.
            $order->forceFill([
                'stock_reserved_at'  => null,
                'stock_committed_at' => null,
                'stock_unwound_at'   => null,
            ])->save();

            PosInventoryService::reserveForOrder($order);   // stamps stock_reserved_at

            DB::table('orders')->where('id', $id)->update([
                'status'         => 'pending',
                'payment_status' => 'pending',
                'cancelled_at'   => null,
                'notes'          => trim(($order->notes ?? '') . "\n[system] Restored 2026-09-01: this till sale "
                    . 'was cancelled by an automated job, not by a person. Automatic cancellation has been '
                    . 'removed — only a human cancels an order. Its stock is reserved again; cancel the order '
                    . 'to release it.'),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::ORDER_IDS as $id) {
            $order = Order::withoutViewerScope()->with('items')->find($id);
            if (!$order || $order->status !== 'pending') {
                continue;
            }
            PosInventoryService::unwindForOrder($order);    // release + stamp unwound
            DB::table('orders')->where('id', $id)->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
                'updated_at'   => now(),
            ]);
        }
    }
};
