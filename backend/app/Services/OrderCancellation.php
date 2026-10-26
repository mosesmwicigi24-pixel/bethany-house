<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\OrderStatusMachine;
use Illuminate\Support\Facades\DB;

/**
 * Staff cancelling an order — the one code path behind the admin status
 * dropdown (OrderController::updateStatus with status=cancelled) and the
 * super admin's "Void invoice" (App\Services\Documents\InvoiceVoid), so the
 * two can never drift apart.
 *
 * Moved here unchanged from updateStatus (2026-10-26): the legal-transition
 * guard, the status and cancelled_at, the stock the order was holding given
 * back exactly once (PosInventoryService::unwindForOrder is idempotent and
 * flag-guarded), the status-history row, the notification and the activity
 * entry. The invoice copy follows through Order's `updated` hook
 * (SalesDocumentRevisions::refreshInvoice), as before.
 *
 * Safe inside a caller's transaction: the history row commits or rolls back
 * with it, and the notification waits for the commit.
 */
class OrderCancellation
{
    /** @return string the status the order was cancelled from */
    public static function cancel(Order $order, User $by, ?string $notes = null, ?string $trackingNumber = null): string
    {
        OrderStatusMachine::assertCanTransition($order->status, 'cancelled');

        $oldStatus = $order->status;

        // The status and the stock it moves change together, or not at all.
        DB::transaction(function () use ($order, $by, $notes, $trackingNumber, $oldStatus) {
            $order->update([
                'status'          => 'cancelled',
                'tracking_number' => $trackingNumber ?? $order->tracking_number,
                'cancelled_at'    => $order->cancelled_at ?? now(),
            ]);

            // Cancelling from the admin status dropdown is the third door onto the
            // same order as cancelOrder() and voidOrder() — and the only one that
            // used to return no stock at all, silently stranding whatever the order
            // had drawn. Idempotent and flag-guarded, so it neither double-restores
            // an order a cancel/void/reap already unwound nor invents stock for one
            // that never drew any.
            PosInventoryService::unwindForOrder($order, $by->id);

            DB::table('order_status_history')->insert([
                'order_id'    => $order->id,
                'from_status' => $oldStatus,
                'to_status'   => 'cancelled',
                'created_by'  => $by->id,
                'notes'       => $notes,
                'created_at'  => now(),
            ]);
        });

        // Outside a transaction this runs at once, exactly as before; inside
        // one, nobody is told about a cancellation that was rolled back.
        $orderId = $order->id;
        $number  = $order->order_number;
        $userId  = $order->user_id;
        DB::afterCommit(fn () => NotificationService::orderStatusChanged($orderId, $number, $oldStatus, 'cancelled', $userId));

        ActivityLogService::log('status_changed', $order, [
            'old_status' => $oldStatus,
            'new_status' => 'cancelled',
        ], null, $by);

        return $oldStatus;
    }
}
