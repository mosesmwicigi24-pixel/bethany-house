<?php

namespace App\Services\Procurement;

use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\ReportingCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a purchase order owes its supplier, and what has been paid.
 *
 *   Payable = the supplier's invoice amount when one is recorded (what the
 *             supplier actually billed), else the PO total.
 *   Paid    = standing (non-voided) supplier payments.
 *   Balance = Payable − Paid; below zero is a credit with the supplier.
 *
 * All three are in the PO's currency. The KES figures use each payment's
 * own snapshot (amount_kes) for money paid and today's reporting rate for
 * the balance still owed, and say so.
 *
 * payment_status on the PO is derived from these and written only here.
 */
class SupplierPayables
{
    /** A PO is a commitment to pay once approved; drafts and requests are not. */
    public const PAYABLE_STATUSES = ['approved', 'ordered', 'partially_received', 'received'];

    public static function payable(PurchaseOrder $po): float
    {
        return round((float) ($po->supplier_invoice_amount ?? $po->total_amount), 2);
    }

    public static function paid(PurchaseOrder $po): float
    {
        return round((float) SupplierPayment::where('purchase_order_id', $po->id)->standing()->sum('amount'), 2);
    }

    public static function statusFor(float $payable, float $paid): string
    {
        $h = fn (float $v) => (int) round($v * 100);
        if ($h($paid) <= 0) {
            return 'unpaid';
        }
        if ($h($paid) < $h($payable)) {
            return 'partially_paid';
        }

        return $h($paid) === $h($payable) ? 'paid' : 'overpaid';
    }

    public static function summary(PurchaseOrder $po): array
    {
        $payable  = self::payable($po);
        $paid     = self::paid($po);
        $balance  = round($payable - $paid, 2);
        $currency = strtoupper((string) ($po->currency_code ?: 'KES'));
        $balKes   = LatestCost::kes($balance, $currency);

        return [
            'currency'        => $currency,
            'payable'         => $payable,
            'payable_source'  => $po->supplier_invoice_amount !== null ? 'supplier_invoice' : 'po_total',
            'paid'            => $paid,
            'paid_kes'        => round((float) SupplierPayment::where('purchase_order_id', $po->id)->standing()->sum('amount_kes'), 2),
            'balance'         => $balance,
            'balance_kes'     => $balKes,
            'balance_kes_basis' => $currency === 'KES' ? null : 'today\'s reporting rate',
            'status'          => self::statusFor($payable, $paid),
        ];
    }

    /** Write the derived status onto the PO when it differs. */
    public static function sync(PurchaseOrder $po): string
    {
        $status = self::statusFor(self::payable($po), self::paid($po));
        if ($po->payment_status !== $status) {
            PurchaseOrder::whereKey($po->id)->update(['payment_status' => $status]);
            $po->payment_status = $status;
        }

        return $status;
    }

    /**
     * Record a payment. Locks the PO so two clerks can't both pay the last
     * shilling; a repeated client_request_id returns the first payment.
     *
     * @param array{amount: float|string, method: string, reference?: ?string, paid_at?: ?string, notes?: ?string, client_request_id?: ?string} $data
     */
    public static function record(PurchaseOrder $po, array $data, User $by): SupplierPayment
    {
        if (!empty($data['client_request_id'])) {
            $seen = SupplierPayment::where('client_request_id', $data['client_request_id'])->first();
            if ($seen) {
                if ((int) $seen->purchase_order_id !== (int) $po->id) {
                    self::refuse('client_request_id', 'This request was already used for another purchase order.');
                }

                return $seen;
            }
        }

        return DB::transaction(function () use ($po, $data, $by) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (!in_array($po->status, self::PAYABLE_STATUSES, true)) {
                self::refuse('amount', 'A purchase order is paid once it is approved. This one is ' . str_replace('_', ' ', $po->status) . '.');
            }

            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                self::refuse('amount', 'The amount must be more than zero.');
            }
            $currency = strtoupper((string) ($po->currency_code ?: 'KES'));
            $balance  = round(self::payable($po) - self::paid($po), 2);
            if ((int) round($amount * 100) > (int) round($balance * 100)) {
                self::refuse('amount', $balance > 0
                    ? "That is more than is owed: {$currency} " . number_format($balance, 2) . ' is outstanding.'
                    : 'Nothing is outstanding on this purchase order.');
            }

            if (in_array($data['method'], SupplierPayment::NEEDS_REFERENCE, true) && trim((string) ($data['reference'] ?? '')) === '') {
                self::refuse('reference', 'Give the transaction reference (bank, M-PESA or cheque number).');
            }

            $paidAt = isset($data['paid_at']) && $data['paid_at']
                ? Carbon::parse($data['paid_at'], config('app.timezone'))
                : now();
            if ($paidAt->isFuture()) {
                self::refuse('paid_at', 'A payment is dated when it was made, not in the future.');
            }

            $rate = $currency === 'KES' ? 1.0 : (ReportingCurrency::rates()[$currency] ?? null);

            $payment = SupplierPayment::create([
                'purchase_order_id' => $po->id,
                'supplier_id'       => $po->supplier_id,
                'amount'            => $amount,
                'currency_code'     => $currency,
                'rate_to_kes'       => $rate,
                'amount_kes'        => $rate === null ? null : round($amount * $rate, 2),
                'method'            => $data['method'],
                'reference'         => trim((string) ($data['reference'] ?? '')) ?: null,
                'paid_at'           => $paidAt,
                'notes'             => $data['notes'] ?? null,
                'client_request_id' => $data['client_request_id'] ?? null,
                'recorded_by'       => $by->id,
            ]);

            $status = self::sync($po);

            ActivityLogService::log('supplier_payment_recorded', $po, [
                'payment_number' => $payment->payment_number,
                'amount'         => $amount,
                'currency'       => $currency,
                'amount_kes'     => $payment->amount_kes,
                'method'         => $payment->method,
                'reference'      => $payment->reference,
                'payment_status' => $status,
            ], "Paid {$currency} " . number_format($amount, 2) . " to the supplier on {$po->po_number} ({$payment->payment_number})", $by);

            return $payment;
        });
    }

    public static function void(SupplierPayment $payment, string $reason, User $by): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $reason, $by) {
            $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->voided_at !== null) {
                self::refuse('reason', 'This payment is already voided.');
            }
            $po = PurchaseOrder::whereKey($payment->purchase_order_id)->lockForUpdate()->firstOrFail();

            $payment->update(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason]);
            $status = self::sync($po);

            ActivityLogService::log('supplier_payment_voided', $po, [
                'payment_number' => $payment->payment_number,
                'amount'         => (float) $payment->amount,
                'currency'       => $payment->currency_code,
                'reason'         => $reason,
                'payment_status' => $status,
            ], "Voided supplier payment {$payment->payment_number} on {$po->po_number}: {$reason}", $by);

            return $payment->fresh();
        });
    }

    /** Refuse cancelling a PO that still has money paid against it. */
    public static function assertCancellable(PurchaseOrder $po): void
    {
        $paid = self::paid($po);
        if ($paid > 0) {
            throw ValidationException::withMessages(['status' => [
                strtoupper((string) $po->currency_code) . ' ' . number_format($paid, 2)
                . ' has been paid to the supplier on this order. Void those payments (if they were recorded in error) before cancelling it.',
            ]]);
        }
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
