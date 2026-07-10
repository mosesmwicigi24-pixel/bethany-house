<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;

/**
 * Payment → receipt, the tail of the quotation → invoice → receipt flow.
 *
 * Called after a payment settles on an order. It participates ONLY for
 * quote-originated invoices — orders that carry an INVOICE row in
 * sales_documents (created by QuotationService::convertToInvoice). For any other
 * order (a POS sale, a plain online order) it is a no-op, so it can be called
 * from a shared settlement path without double-processing POS, which issues its
 * own receipt and commits its own stock inside recordPosPay/createSale.
 *
 * On each settled payment it issues an RCP receipt (one per payment — supporting
 * partial and full). When the invoice becomes fully paid it commits the reserved
 * stock (the commit half of the reservation Phase 2 held) and marks the invoice
 * paid. Everything is idempotent: one receipt per payment; commit guarded by
 * stock_committed_at.
 */
class ReceiptService
{
    public static function onPaymentSettled(Order $order, Payment $payment, ?int $userId = null): ?SalesDocument
    {
        $invoiceDoc = SalesDocument::where('type', SalesDocument::INVOICE)
            ->where('documentable_type', Order::class)
            ->where('documentable_id', $order->id)
            ->first();

        // Not a quote-originated invoice → POS/plain order handles itself.
        if (!$invoiceDoc) {
            return null;
        }

        // Only settled money produces a receipt.
        if (!$payment->isPaid()) {
            return null;
        }

        return DB::transaction(function () use ($order, $payment, $userId, $invoiceDoc) {
            // One receipt per payment (idempotent on re-delivered callbacks / retries).
            $receipt = SalesDocument::where('type', SalesDocument::RECEIPT)
                ->where('payment_id', $payment->id)
                ->first();

            if (!$receipt) {
                $number  = DocumentNumberService::next('receipt');
                $receipt = SalesDocument::create([
                    'type'               => SalesDocument::RECEIPT,
                    'number'             => $number,
                    'documentable_type'  => Order::class,
                    'documentable_id'    => $order->id,
                    'parent_document_id' => $invoiceDoc->id,
                    'payment_id'         => $payment->id,
                    'issued_at'          => now(),
                    'status'             => 'issued',
                    'amount'             => (float) $payment->amount,
                    'currency_code'      => $order->currency_code,
                    'snapshot'           => self::snapshot($order, $payment, $number),
                    'created_by'         => $userId,
                ]);
            }

            // Fully paid → the goods leave the shelf: commit the reservation
            // (deduct physical count), mark the serialized units sold, close the
            // invoice. commitForOrder is idempotent (stock_committed_at guard).
            $order->refresh();
            if ($order->totalPaid() + 0.01 >= (float) $order->total_amount) {
                PosInventoryService::commitForOrder($order, $userId);
                ProductSerialService::syncSoldForOrder($order);
                if ($invoiceDoc->status !== 'paid') {
                    $invoiceDoc->update(['status' => 'paid']);
                }
            }

            return $receipt;
        });
    }

    /** A receipt records THIS payment and the invoice balance after it. */
    private static function snapshot(Order $order, Payment $payment, string $receiptNumber): array
    {
        $order->refresh();
        $paidToDate = $order->totalPaid();
        $balance    = max(0, (float) $order->total_amount - $paidToDate);

        return [
            'receipt_number' => $receiptNumber,
            'order_number'   => $order->order_number,
            'issued_at'      => now()->toIso8601String(),
            'currency_code'  => $order->currency_code,
            'payment' => [
                'method'    => $payment->payment_method,
                'reference' => $payment->provider_reference,
                'amount'    => (float) $payment->amount,
                'paid_at'   => optional($payment->paid_at)->toIso8601String(),
            ],
            'invoice_total' => (float) $order->total_amount,
            'paid_to_date'  => round($paidToDate, 2),
            'balance_due'   => round($balance, 2),
            'fully_paid'    => $balance <= 0.01,
        ];
    }
}
