<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\PaymentReversal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner deletes a payment that should never have been there (owner,
 * 2026-11-05 — #1158: a Mukuru payment the customer never sent, approved by
 * mistake). Softly: nothing is erased.
 *
 *   settled      → PaymentReversal (voided; order money, stock, receipt and
 *                  status undone exactly as a "recorded by mistake" reversal)
 *   not settled  → voided directly (awaiting approval, or never completed)
 *   then         → marked removed (who, when, why): hidden from the order's
 *                  payment list unless "Show removed" is pressed
 *
 * Refused as PaymentReversal refuses: money a provider verified (refund it)
 * and cash rung through a till (void it there).
 */
final class PaymentRemoval
{
    public const EVENT = 'payment_removed';

    public static function remove(Payment $payment, string $reason, User $by): Payment
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => ['Say why this payment is deleted (kept in the audit trail).']]);
        }
        if ($payment->removed_at !== null) {
            throw ValidationException::withMessages(['payment' => ['This payment is already deleted.']]);
        }

        if ($payment->status !== 'voided') {
            if (PaymentReversal::settled($payment)) {
                PaymentReversal::reverse($payment, "Deleted: {$reason}", $by);
            } else {
                DB::transaction(function () use ($payment, $reason, $by) {
                    $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                    $p->forceFill([
                        'status'          => 'voided',
                        'approval_status' => $p->requires_approval ? 'rejected' : $p->approval_status,
                        'void_reason'     => "Deleted: {$reason}",
                        'voided_at'       => now(),
                        'voided_by'       => $by->id,
                    ])->save();
                    if ($p->order_id && ($order = Order::withoutViewerScope()->find($p->order_id))) {
                        $order->syncPaymentStatus();
                    }
                });
            }
        }

        $payment = Payment::findOrFail($payment->id);
        $payment->forceFill(['removed_at' => now(), 'removed_by' => $by->id, 'removed_reason' => $reason])->save();

        ActivityLogService::log(self::EVENT, $payment, [
            'payment_number' => $payment->payment_number,
            'amount'         => (float) $payment->amount,
            'currency_code'  => $payment->currency_code,
            'order_id'       => $payment->order_id,
            'reason'         => $reason,
        ], "{$by->first_name} deleted payment {$payment->payment_number} ({$payment->currency_code} " . number_format((float) $payment->amount, 2) . "): {$reason}", $by);

        return $payment;
    }
}
