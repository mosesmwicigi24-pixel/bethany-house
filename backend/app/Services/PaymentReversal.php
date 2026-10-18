<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductSerial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A payment recorded or approved BY MISTAKE, undone (owner, 2026-10-18).
 *
 * Nothing is deleted. The payment row is voided with the reason and the
 * actor (PaymentCorrections::void, the same void finance signs), and every
 * effect that "paid" had is undone with it:
 *   - the order's paid total, balance and payment_status are re-derived
 *     (pending when nothing settled remains);
 *   - goods a full payment took off the shelf go back on it, the reservation
 *     held again (PosInventoryService::uncommitForOrder); serial units marked
 *     sold go back in stock;
 *   - the receipt issued for the payment is marked void, the invoice back to
 *     issued (ReceiptService::onPaymentReversed);
 *   - when the payment itself advanced the order (pending → processing /
 *     confirmed, automatically), the order returns to the status it had,
 *     with a history row saying why — only when asked to.
 *
 * What it refuses, loudly: a payment a provider verified (M-Pesa, Paystack,
 * card — the money is real: that is a refund), cash rung through a till (the
 * till void gives the drawer its cash back), a payment already voided, and an
 * order whose serial units were dispatched.
 *
 * Who: a super admin executes it at once (the maker-checker rule would leave
 * the owner's own mistake unsignable when the owner is the only finance
 * signer); everyone else with payments.void / payments.request_void raises
 * the payment_void request, which carries `reversal` in its payload so that
 * signing it runs THIS reversal, not a bare void.
 */
final class PaymentReversal
{
    public const EVENT = 'payment_reversed';

    /** Methods whose money a provider confirmed: real money, not a mistake. */
    private const PROVIDER_METHODS = ['mpesa', 'm_pesa', 'paystack', 'card'];

    /** Why this payment cannot be reversed as a mistake; null when it can. */
    public static function blocker(Payment $payment): ?string
    {
        if ($payment->status === 'voided') {
            return 'This payment is already voided.';
        }
        if (!self::settled($payment)) {
            return $payment->isPaid()
                ? 'This payment is still awaiting approval: reject it in the approval queue instead of reversing it.'
                : 'Only settled money is reversed; this payment never settled.';
        }
        $method = strtolower((string) $payment->payment_method);
        if (in_array($method, self::PROVIDER_METHODS, true) && !empty($payment->provider_transaction_id)) {
            return 'This payment was verified by the provider, so the money is real. Refund it; a verified payment is not reversed as a mistake.';
        }
        if ($method === 'cash' && $payment->order_id
            && DB::table('cash_register_transactions')->where('order_id', $payment->order_id)->where('transaction_type', 'sale')->exists()) {
            return 'This cash was rung through a till. Void it from the till so the drawer gives the cash back.';
        }
        if ($payment->order_id && ProductSerial::where('order_id', $payment->order_id)->where('status', ProductSerial::DISPATCHED)->exists()) {
            return 'Serialised units on this order have been dispatched; the goods have left. Use a return or a refund.';
        }

        return null;
    }

    /** The money rule (App\Support\SettledPayment): paid, and approved where approval applies. */
    public static function settled(Payment $payment): bool
    {
        return $payment->isPaid() && (!$payment->requires_approval || $payment->approval_status === 'approved');
    }

    /**
     * Undo one payment. Runs in one transaction; the order row is locked.
     *
     * @return array{payment_id:int, previous_status:string, payment_status:string, order_status:string, status_restored:bool, stock_returned:bool, receipt_voided:bool}
     */
    public static function reverse(Payment $payment, string $reason, User $by, bool $restoreStatus = true, ?int $approvalRequestId = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required: it is written on the voided payment and in the audit trail.']]);
        }

        return DB::transaction(function () use ($payment, $reason, $by, $restoreStatus, $approvalRequestId) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($blocker = self::blocker($payment)) {
                throw ValidationException::withMessages(['payment' => [$blocker]]);
            }
            $order = Order::withoutViewerScope()->lockForUpdate()->findOrFail($payment->order_id);

            $statusBefore        = (string) $order->status;
            $paymentStatusBefore = (string) $order->payment_status;
            $wasCommitted        = $order->stock_committed_at !== null;
            $paidAt              = $payment->paid_at ?? $payment->created_at;

            // 1. The void finance would sign: payment voided, order money re-derived.
            PaymentCorrections::void($payment, $reason, $by, $approvalRequestId);
            $order->refresh();

            // 2. Goods: a full payment took them off the shelf; no longer fully paid → back on it.
            $stockReturned = false;
            if ($wasCommitted && $order->totalPaid() + 0.01 < (float) $order->total_amount) {
                PosInventoryService::uncommitForOrder($order, $by->id);
                ProductSerialService::unsoldForOrder($order);
                $stockReturned = true;
            }

            // 3. The receipt for this payment is void; the invoice reopens.
            $receipt = ReceiptService::onPaymentReversed($order, $payment, $reason, $by->id);

            // 4. The status the payment advanced, returned — when asked, and only
            //    when the advance was the payment's doing.
            $statusRestored = false;
            if ($restoreStatus) {
                $previous = self::statusBeforePayment($order, $paidAt);
                if ($previous !== null && $previous !== $order->status) {
                    $from = $order->status;
                    $order->update(['status' => $previous, 'completed_at' => null]);
                    DB::table('order_status_history')->insert([
                        'order_id'    => $order->id,
                        'from_status' => $from,
                        'to_status'   => $previous,
                        'created_by'  => $by->id,
                        'notes'       => "Returned after a payment was reversed: {$reason}",
                        'created_at'  => now(),
                    ]);
                    $statusRestored = true;
                }
            }

            ActivityLogService::log(self::EVENT, $payment, [
                'payment_number'        => $payment->payment_number,
                'amount'                => (float) $payment->amount,
                'currency_code'         => $payment->currency_code,
                'order_id'              => $order->id,
                'order_number'          => $order->order_number,
                'reason'                => $reason,
                'by'                    => $by->id,
                'owner_override'        => $approvalRequestId === null,
                'approval_request_id'   => $approvalRequestId,
                'payment_status_before' => $paymentStatusBefore,
                'payment_status_after'  => $order->payment_status,
                'order_status_before'   => $statusBefore,
                'order_status_after'    => $order->status,
                'stock_returned'        => $stockReturned,
                'receipt_voided'        => $receipt !== null,
            ], "Payment {$payment->payment_number} on {$order->order_number} reversed" . ($approvalRequestId === null ? ' by the super admin' : ' on approval') . ": {$reason}", $by);

            return [
                'payment_id'      => $payment->id,
                'previous_status' => $paymentStatusBefore,
                'payment_status'  => (string) $order->payment_status,
                'order_status'    => (string) $order->status,
                'status_restored' => $statusRestored,
                'stock_returned'  => $stockReturned,
                'receipt_voided'  => $receipt !== null,
            ];
        });
    }

    /**
     * Every settled payment on the order, newest first; refused as a whole
     * when any one of them cannot be reversed (nothing half-done).
     *
     * @return list<array<string, mixed>>
     */
    public static function reverseAll(Order $order, string $reason, User $by, bool $restoreStatus = true, ?int $approvalRequestId = null): array
    {
        $payments = Payment::where('order_id', $order->id)->orderByDesc('id')->get()->filter(fn (Payment $p) => self::settled($p));
        if ($payments->isEmpty()) {
            throw ValidationException::withMessages(['payment' => ['There is no settled payment on this order to reverse.']]);
        }
        $blocked = $payments->map(fn (Payment $p) => self::blocker($p) ? "{$p->payment_number}: " . self::blocker($p) : null)->filter()->values();
        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages(['payment' => $blocked->all()]);
        }

        return DB::transaction(fn () => $payments->map(fn (Payment $p) => self::reverse($p, $reason, $by, $restoreStatus, $approvalRequestId))->values()->all());
    }

    /**
     * The status the order had before this payment advanced it, from the
     * status history: the `from_status` of the automatic advance recorded at
     * or after the payment. Approval-queue advances write no history row, so a
     * confirmed/processing order with no row after the payment is taken back
     * to pending — the state every web, chat and quoted order starts in.
     * Anything a person set by hand (a later manual change) is left alone.
     */
    public static function statusBeforePayment(Order $order, $paidAt): ?string
    {
        if (!in_array($order->status, ['processing', 'confirmed'], true)) {
            return null;
        }
        $rows = DB::table('order_status_history')
            ->where('order_id', $order->id)
            ->where('created_at', '>=', $paidAt ? \Illuminate\Support\Carbon::parse($paidAt)->subSeconds(5) : now())
            ->orderBy('id')
            ->get(['from_status', 'to_status', 'notes']);

        if ($rows->isEmpty()) {
            return 'pending';
        }
        $manual = $rows->first(fn ($r) => !str_starts_with((string) $r->notes, 'Automatically advanced'));
        if ($manual) {
            return null;        // a person moved it since; not the payment's doing
        }

        return (string) $rows->first()->from_status ?: 'pending';
    }
}
