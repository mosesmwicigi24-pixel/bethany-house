<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give back the 12 orders the system cancelled on its own.
 *
 * An hourly job cancelled unpaid POS orders older than 24 hours. Between 10
 * July and 30 August it cancelled 12 real orders worth KES 170,400 — Faith
 * Muriuki's KES 49,500, olga Auma's KES 39,300, Cornelius achanga's KES 12,000
 * and nine more. Every one was a customer a human had served at the counter and
 * might still have closed; a day's silence is not an abandonment.
 *
 * Owner's rule (2026-09-01): "The system should not cancel the receipts, only
 * human should." The schedule is gone (routes/console.php) and the command now
 * reports rather than cancels unless a person passes --force.
 *
 * This restores the orders themselves: status and payment_status back to
 * 'pending', cancelled_at cleared, with a note recording what happened. They
 * reappear in the Pending Queue, where a person decides each one.
 *
 * NO FINANCIAL MOVEMENT: pending + unpaid is not income under the recognition
 * rule (Order::scopeRecognised), so not a shilling of reported revenue changes.
 * The KES 170,400 returns to the PIPELINE, which is where it always belonged.
 *
 * STOCK IS DELIBERATELY NOT RE-TAKEN. Cancelling returned each order's units to
 * the shelf — a reservation for 8 of them, a physical void_return for 3. Those
 * goods have been counted as available for up to two months and some have
 * certainly been sold to somebody else since. Re-deducting them now would
 * invent a shortage that does not exist on the shelf. The honest position is:
 * restore the ORDER, leave the physical count physically true, and let staff
 * take stock again when they actually work the order. stock_unwound_at stays
 * set so nothing double-unwinds.
 */
return new class extends Migration
{
    private const FINGERPRINT = '%Auto-cancelled: abandoned unpaid POS order%';

    public function up(): void
    {
        $orders = DB::table('orders')
            ->where('status', 'cancelled')
            ->where('notes', 'ILIKE', self::FINGERPRINT)
            ->get(['id', 'notes']);

        foreach ($orders as $order) {
            DB::table('orders')->where('id', $order->id)->update([
                'status'         => 'pending',
                'payment_status' => 'pending',
                'cancelled_at'   => null,
                'notes'          => trim(($order->notes ?? '') . "\n[system] Restored 2026-09-01: this order was "
                    . 'cancelled by an automated job, not by a person. Automatic cancellation has been removed — '
                    . 'only a human cancels an order. Stock was not re-taken; check the shelf before promising it.'),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Re-cancel exactly the orders this migration restored.
        DB::table('orders')
            ->where('status', 'pending')
            ->where('notes', 'ILIKE', '%Restored 2026-09-01%')
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
    }
};
