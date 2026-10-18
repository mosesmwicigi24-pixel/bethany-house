<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Voiding a payment, and moving one to another order — carried out only once
 * the approval engine has the signatures (Phase 3B). This is the body the
 * void/reassign endpoints used to run directly, unchanged in what it does to
 * the payment and the orders' balances.
 */
final class PaymentCorrections
{
    public static function void(Payment $payment, string $reason, User $by, ?int $approvalRequestId = null): Payment
    {
        $payment->loadMissing('order');
        if ($payment->status === 'voided') {
            self::fail('Payment is already voided.');
        }

        $oldStatus  = $payment->status;
        $oldOrderId = $payment->order_id;

        $payment->update([
            'status'      => 'voided',
            'void_reason' => $reason,
            'voided_at'   => now(),
            'voided_by'   => $by->id,
        ]);

        // syncPaymentStatus() is the authoritative source (it drives
        // payment_status from the net of the remaining paid payments); the
        // amount_paid/balance_due columns are kept on the SAME net figure.
        if ($payment->order) {
            $order   = $payment->order;
            $netPaid = $order->totalPaid();
            $order->update([
                'amount_paid' => $netPaid,
                'balance_due' => max(0, (float) $order->total_amount - $netPaid),
            ]);
            $order->syncPaymentStatus();
        }

        ActivityLogService::log('payment_voided', $payment, [
            'payment_number'      => $payment->payment_number,
            'amount'              => $payment->amount,
            'order_id'            => $oldOrderId,
            'previous_status'     => $oldStatus,
            'reason'              => $reason,
            'voided_by'           => $by->id,
            'approval_request_id' => $approvalRequestId,
        ], null, $by);

        return $payment->fresh();
    }

    public static function reassign(Payment $payment, int $newOrderId, string $reason, User $by, ?int $approvalRequestId = null): Payment
    {
        if ($payment->status === 'voided') {
            self::fail('Voided payments cannot be reassigned.');
        }
        if ((int) $payment->order_id === $newOrderId) {
            self::fail('Payment is already assigned to that order.');
        }
        $target = Order::withoutViewerScope()->find($newOrderId);
        if (!$target) {
            self::fail('That order does not exist.');
        }
        // A USD payment on a KES order would be added as shillings everywhere
        // the order's paid total is read. Money moves only within its currency.
        if (strtoupper((string) $target->currency_code) !== strtoupper((string) $payment->currency_code)) {
            self::fail("This payment is in {$payment->currency_code}; that order is in {$target->currency_code}. A payment moves only between orders in the same currency.");
        }

        $oldOrderId = $payment->order_id;
        $payment->update(['order_id' => $newOrderId]);

        foreach (array_filter([$oldOrderId, $newOrderId]) as $oid) {
            $order = Order::withoutViewerScope()->find($oid);
            if (!$order) {
                continue;
            }
            // The one rule for "paid" (Order::totalPaid), and the status that
            // follows from it — a moved payment used to leave both orders'
            // payment_status as it was.
            $order->syncPaymentStatus();
            $totalPaid = $order->totalPaid();
            $order->update([
                'amount_paid' => $totalPaid,
                'balance_due' => max(0, (float) $order->total_amount - $totalPaid),
            ]);
        }

        ActivityLogService::log('payment_reassigned', $payment, [
            'payment_number'      => $payment->payment_number,
            'amount'              => $payment->amount,
            'from_order_id'       => $oldOrderId,
            'to_order_id'         => $newOrderId,
            'reason'              => $reason,
            'reassigned_by'       => $by->id,
            'approval_request_id' => $approvalRequestId,
        ], null, $by);

        return $payment->fresh();
    }

    private static function fail(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], 422));
    }
}
