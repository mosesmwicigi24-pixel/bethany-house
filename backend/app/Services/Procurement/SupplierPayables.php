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
 *             supplier actually billed — any credit for goods sent back
 *             is theirs to state on it), else the PO total less the value
 *             of approved purchase returns (quantity × the line's price).
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
        if ($po->supplier_invoice_amount !== null) {
            return round((float) $po->supplier_invoice_amount, 2);
        }

        return round((float) $po->total_amount - self::returned($po), 2);
    }

    /** Statuses of a purchase return whose goods have gone back. */
    public const RETURNED_STATUSES = ['approved', 'completed'];

    /** Value of goods sent back on approved returns, at the order's prices, in the PO's currency. */
    public static function returned(PurchaseOrder $po): float
    {
        return round((float) DB::table('purchase_return_items as ri')
            ->join('purchase_returns as r', 'r.id', '=', 'ri.return_id')
            ->join('purchase_order_items as pi', 'pi.id', '=', 'ri.po_item_id')
            ->where('r.purchase_order_id', $po->id)
            ->whereIn('r.status', self::RETURNED_STATUSES)
            ->sum(DB::raw('ri.quantity * pi.unit_price')), 2);
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
            'returned'        => self::returned($po),
            'payable'         => $payable,
            'payable_source'  => $po->supplier_invoice_amount !== null ? 'supplier_invoice' : 'po_total',
            'paid'            => $paid,
            'paid_kes'        => round((float) SupplierPayment::where('purchase_order_id', $po->id)->standing()->sum('amount_kes'), 2),
            'balance'         => $balance,
            'balance_kes'     => $balKes,
            'balance_kes_basis' => $currency === 'KES' ? null : 'today\'s reporting rate',
            'status'          => self::statusFor($payable, $paid),
            // Approved/paid expenses linked to this order. Freight or clearing
            // belongs there; paying the supplier for the goods does not — that
            // would be counted twice (in the P&L and here).
            'linked_expenses' => self::linkedExpenses($po),
        ];
    }

    /**
     * Approved/paid expenses linked to the order — count and KES total, as
     * numbers whatever the database driver hands back.
     *
     * @return array{count: int, amount_kes: float}
     */
    public static function linkedExpenses(PurchaseOrder $po): array
    {
        $row = DB::table('expenses')
            ->where('purchase_order_id', $po->id)->whereNull('deleted_at')
            ->whereIn('status', \App\Services\Reporting\MetricEngine::EXPENSE_SPEND_STATUSES)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount_kes), 0) AS amount_kes')
            ->first();

        return ['count' => (int) $row->count, 'amount_kes' => round((float) $row->amount_kes, 2)];
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

    /**
     * Every purchase order that still owes its supplier money — the list
     * the bulk "mark as paid" picks from. Same rule as summary(): payable
     * (supplier invoice, else total less returns) minus standing payments,
     * on payable statuses, where something is still owed.
     *
     * @return list<array{id:int, po_number:string, supplier_id:int, supplier:string, order_date:?string, status:string, currency:string, payable:float, paid:float, balance:float, balance_kes:?float}>
     */
    public static function owedOrders(): array
    {
        $rates = ReportingCurrency::rates();

        return PurchaseOrder::with('supplier:id,name')
            ->whereIn('status', self::PAYABLE_STATUSES)
            ->orderBy('order_date')->orderBy('id')
            ->get()
            ->map(function (PurchaseOrder $po) use ($rates) {
                $payable  = self::payable($po);
                $paid     = self::paid($po);
                $balance  = round($payable - $paid, 2);
                $currency = strtoupper((string) ($po->currency_code ?: 'KES'));
                $rate     = $currency === 'KES' ? 1.0 : ($rates[$currency] ?? null);

                return [
                    'id'          => (int) $po->id,
                    'po_number'   => (string) $po->po_number,
                    'supplier_id' => (int) $po->supplier_id,
                    'supplier'    => (string) ($po->supplier->name ?? ''),
                    'order_date'  => $po->order_date?->toDateString(),
                    'status'      => (string) $po->status,
                    'currency'    => $currency,
                    'payable'     => $payable,
                    'paid'        => $paid,
                    'balance'     => $balance,
                    'balance_kes' => $rate === null ? null : round($balance * $rate, 2),
                ];
            })
            ->filter(fn (array $r) => (int) round($r['balance'] * 100) > 0)
            ->values()->all();
    }

    /**
     * Pay several purchase orders in full, one payment each for exactly what
     * that order still owes — record() per order, so every rule there holds
     * (locked, never more than owed, payable statuses only, audited). An
     * order that cannot be paid is skipped and named; the rest still go.
     * Sending the same batch again pays nothing twice.
     *
     * $data['date_mode']: 'order_date' dates each payment on its own order's
     * date (money paid when the order was made); 'one_date' uses paid_at.
     *
     * @param list<int> $orderIds
     * @return array{paid: list<array>, skipped: list<array>}
     */
    public static function payInFull(array $orderIds, array $data, User $by): array
    {
        $paid = $skipped = [];
        $batch = (string) $data['client_request_id'];

        foreach (array_values(array_unique(array_map('intval', $orderIds))) as $id) {
            $po = PurchaseOrder::find($id);
            if (!$po) {
                $skipped[] = ['id' => $id, 'po_number' => null, 'reason' => 'This purchase order no longer exists.'];
                continue;
            }

            $balance = round(self::payable($po) - self::paid($po), 2);
            // One request id per order, stable across a retry of the batch.
            $requestId = substr(hash('sha256', $batch . ':' . $po->id), 0, 64);
            $already   = SupplierPayment::where('client_request_id', $requestId)->exists();

            if (!$already && (int) round($balance * 100) <= 0) {
                $skipped[] = ['id' => $po->id, 'po_number' => $po->po_number, 'reason' => 'Nothing is outstanding on this purchase order.'];
                continue;
            }

            $paidAt = ($data['date_mode'] ?? 'order_date') === 'order_date'
                ? ($po->order_date ?? $po->created_at)?->copy()->setTime(12, 0)->toDateTimeString()
                : ($data['paid_at'] ?? null);

            try {
                $payment = self::record($po, [
                    'amount'            => $balance,
                    'method'            => $data['method'],
                    'reference'         => $data['reference'] ?? null,
                    'paid_at'           => $paidAt,
                    'notes'             => $data['notes'] ?? null,
                    'client_request_id' => $requestId,
                ], $by);

                $paid[] = [
                    'id' => $po->id, 'po_number' => $po->po_number,
                    'payment_number' => $payment->payment_number,
                    'amount' => (float) $payment->amount, 'currency' => $payment->currency_code,
                    'amount_kes' => $payment->amount_kes === null ? null : (float) $payment->amount_kes,
                    'paid_at' => $payment->paid_at?->toDateString(),
                ];
            } catch (ValidationException $e) {
                $skipped[] = ['id' => $po->id, 'po_number' => $po->po_number, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        if ($paid) {
            ActivityLogService::log('supplier_payments_bulk_recorded', null, [
                'orders'     => count($paid),
                'skipped'    => count($skipped),
                'method'     => $data['method'],
                'date_mode'  => $data['date_mode'] ?? 'order_date',
                'payments'   => array_column($paid, 'payment_number'),
            ], count($paid) . ' purchase orders marked as paid in one go', $by);
        }

        return ['paid' => $paid, 'skipped' => $skipped];
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
