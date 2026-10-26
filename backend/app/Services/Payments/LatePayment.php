<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Money that arrives for an order after it died.
 *
 * Blocking payment on a dead order (Order::isPayable) stops anyone STARTING a
 * payment. It cannot stop one already in flight: an M-Pesa push the customer
 * approved a minute before the cancel, a card charge whose webhook lands
 * late, a bank proof finance approves afterwards. That money is real and is
 * in the business's account, so refusing the callback would only hide it.
 *
 * So it is recorded — and marked. When a payment becomes settled money
 * (SettledPayment's rule) on a dead order, this stamps
 * payments.received_after_cancel_at, tells the finance team, and writes one
 * audit entry. The order stays dead: Sold and the receivables never see it
 * (both read recognised orders only), while Collected counts it — it is money
 * received — until finance refunds it.
 *
 * Hooked on the Payment model's own save, not at each door, because there are
 * six settle doors today (two M-Pesa callbacks, the Paystack and Flutterwave
 * webhooks, both verify buttons, proof approval) and the next one must not be
 * able to forget.
 */
final class LatePayment
{
    /** Payments stamped in this save, awaiting their announcement. */
    private static ?\WeakMap $stamped = null;

    /** Money that counts as collected — the one rule (SettledPayment). */
    public static function isSettled(Payment $payment): bool
    {
        return $payment->status === 'paid'
            && (!$payment->requires_approval || $payment->approval_status === 'approved');
    }

    /** Payment::saving — mark a payment that settles onto a dead order. */
    public static function stamp(Payment $payment): void
    {
        // Finance moved the money to a live order instead of refunding it
        // (PaymentController::reassignPayment): nothing is owed back any more.
        if ($payment->exists && $payment->received_after_cancel_at !== null && $payment->isDirty('order_id')) {
            $target = Order::withoutViewerScope()->find($payment->order_id);
            if ($target && $target->isPayable()) {
                $payment->received_after_cancel_at = null;
            }
            return;
        }

        if ($payment->received_after_cancel_at !== null || !self::isSettled($payment)) {
            return;
        }
        // Only the moment it BECOMES settled money here: a refund or a
        // detail correction on an already-settled payment is not an arrival.
        if ($payment->exists && !$payment->isDirty(['status', 'approval_status', 'requires_approval', 'order_id'])) {
            return;
        }

        // Unscoped: gateway callbacks run with no signed-in viewer.
        $order = Order::withoutViewerScope()->find($payment->order_id);
        if (!$order || $order->isPayable()) {
            return;
        }

        $payment->received_after_cancel_at = now();
        self::stamped()[$payment] = true;
    }

    /** Payment::saved — tell finance and audit it, once the money is committed. */
    public static function announce(Payment $payment): void
    {
        $stamped = self::stamped();
        if (!isset($stamped[$payment])) {
            return;
        }
        unset($stamped[$payment]);

        $paymentId = $payment->id;

        DB::afterCommit(function () use ($paymentId) {
            $p     = Payment::find($paymentId);
            $order = $p ? Order::withoutViewerScope()->find($p->order_id) : null;
            if (!$p || !$order) {
                return;
            }

            try {
                NotificationService::paymentAfterCancel(
                    $p->id,
                    (string) ($p->payment_number ?? $p->id),
                    $order->id,
                    (string) $order->order_number,
                    (string) $order->status,
                    (float) $p->amount,
                    (string) $p->currency_code,
                    (string) $p->payment_method,
                );
            } catch (\Throwable $e) {
                // The money is recorded and flagged on the row either way;
                // a failed bell must be loud in the log, not silent.
                Log::error('paid-after-cancel notification failed', ['payment_id' => $p->id, 'error' => $e->getMessage()]);
            }

            ActivityLogService::log('payment_received_after_cancel', $order, [
                'payment_id'     => $p->id,
                'payment_number' => $p->payment_number,
                'amount'         => (float) $p->amount,
                'currency'       => $p->currency_code,
                'method'         => $p->payment_method,
                'reference'      => $p->provider_reference,
                'order_status'   => $order->status,
                'refund_needed'  => true,
            ], "Payment {$p->payment_number} arrived after order {$order->order_number} was {$order->status} — recorded, order left {$order->status}, refund needed.");
        });
    }

    private static function stamped(): \WeakMap
    {
        return self::$stamped ??= new \WeakMap();
    }
}
