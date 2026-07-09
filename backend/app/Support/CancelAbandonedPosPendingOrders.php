<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clean up abandoned POS pending orders.
 *
 * The POS creates a "pending" order the moment a cart is charged; if the sale is
 * never completed it lingers as status='pending', payment_status='pending'. Old
 * ones (e.g. #54, ~KES 17,500) sit around and get offered for auto-resume, which
 * confused cashiers and — before the resume fix — caused payments to be charged
 * against the wrong total.
 *
 * This cancels genuinely-abandoned ones: POS orders that are still pending AND
 * unpaid AND older than the given window AND have NO money attached (no paid /
 * pending / partial payment). Nothing with money is ever touched, and pending
 * orders never deducted stock, so there's no inventory or ledger impact.
 * Idempotent — re-running finds nothing.
 */
class CancelAbandonedPosPendingOrders
{
    /**
     * @return array{cancelled:int}
     */
    public static function run(int $olderThanHours = 24): array
    {
        $result = ['cancelled' => 0];

        if (!Schema::hasTable('orders')) {
            return $result;
        }

        $cutoff = now()->subHours($olderThanHours);

        $orderIds = DB::table('orders')
            ->where('order_type', 'pos')
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->pluck('id');

        foreach ($orderIds as $orderId) {
            // Never cancel an order that has any money recorded against it.
            $hasMoney = Schema::hasTable('payments') && DB::table('payments')
                ->where('order_id', $orderId)
                ->whereIn('status', ['paid', 'pending', 'partial'])
                ->exists();
            if ($hasMoney) {
                continue;
            }

            $order = DB::table('orders')->where('id', $orderId)->first();
            $note  = trim(($order->notes ?? '') . "\n[system] Auto-cancelled: abandoned unpaid POS order.");

            DB::table('orders')->where('id', $orderId)->update([
                'status'     => 'cancelled',
                'notes'      => $note,
                'updated_at' => now(),
            ]);
            $result['cancelled']++;
        }

        return $result;
    }
}
