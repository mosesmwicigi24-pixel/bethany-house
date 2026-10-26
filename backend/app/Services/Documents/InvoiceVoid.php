<?php

namespace App\Services\Documents;

use App\Models\Order;
use App\Models\Quotation;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\OrderCancellation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The super admin voids an invoice before it is paid (owner, 2026-10-26:
 * "for super admin, put a point where one can void the invoice before it is
 * paid. When voided, it voids the quotation too.").
 *
 * In one transaction, or not at all:
 *  1. the order the invoice bills is cancelled through the same path the
 *     admin status dropdown uses (OrderCancellation) — the stock it reserved
 *     goes back, the status history and activity are written;
 *  2. the invoice copy is voided by SalesDocumentRevisions::refreshInvoice,
 *     which keeps the version it replaces as a revision;
 *  3. the order's pay-link stops working (its expiry is set to now);
 *  4. the quotation it came from is void — who, when, why — its public
 *     link no longer resolves to an offer, and its issued QUO copy is void
 *     (the version replaced kept as a revision);
 *  5. one activity entry names the invoice, quotation, order, reason and
 *     the person.
 *
 * Refused, with nothing changed, when the invoice is not open (issued), when
 * any payment on the order still counts or could (paid, partial, awaiting
 * approval, a payment in flight) — money is undone by voiding or refunding
 * the payment first, so the drawer and collections still add up — or when
 * the order is past the point of no return (being processed, shipped,
 * delivered, completed) or already cancelled, voided or refunded.
 *
 * Nothing is deleted: the order, the invoice, the quotation and their
 * numbers all stay, marked void/cancelled.
 */
class InvoiceVoid
{
    /** An order in one of these states may still have its invoice voided. */
    public const VOIDABLE_ORDER_STATUSES = ['pending', 'confirmed'];

    /**
     * Payments that no longer count and never will: undone (voided, refunded,
     * cancelled), never landed (failed), or refused by finance (approval
     * rejected — the approval flow leaves those at 'pending'). Every other
     * payment row blocks the void.
     */
    private const DEAD_PAYMENT_STATUSES = ['voided', 'refunded', 'cancelled', 'failed'];

    public const PAYMENTS_MESSAGE = 'This invoice has payments. Void or refund them first.';

    /**
     * @return array{invoice: SalesDocument, order: Order, quotation: ?Quotation}
     */
    public static function void(int $invoiceId, string $reason, User $by): array
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($invoiceId, $reason, $by) {
            $invoice = SalesDocument::where('type', SalesDocument::INVOICE)
                ->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== 'issued') {
                self::refuse('status', match ($invoice->status) {
                    'void'  => "Invoice {$invoice->number} is already void.",
                    'paid'  => self::PAYMENTS_MESSAGE,
                    default => "Only an open (issued) invoice can be voided; {$invoice->number} is {$invoice->status}.",
                });
            }

            $order = $invoice->documentable_type === Order::class
                ? Order::withoutViewerScope()->with(['payments', 'items'])->whereKey($invoice->documentable_id)->lockForUpdate()->first()
                : null;
            if (!$order) {
                self::refuse('status', "Invoice {$invoice->number} has no order to cancel.");
            }

            $live = $order->payments->reject(fn ($p) => in_array($p->status, self::DEAD_PAYMENT_STATUSES, true)
                || $p->approval_status === 'rejected');
            if ($live->isNotEmpty()) {
                self::refuse('payments', self::PAYMENTS_MESSAGE);
            }

            if (!in_array($order->status, self::VOIDABLE_ORDER_STATUSES, true)) {
                self::refuse('status', in_array($order->status, ['cancelled', 'voided', 'refunded'], true)
                    ? "Order {$order->order_number} is already {$order->status}."
                    : "Order {$order->order_number} is already {$order->status}. An invoice can only be voided while its order is pending or confirmed.");
            }

            $quotation = self::quotationFor($invoice, $order);

            // 1. Cancel the order — the shared path. Its `updated` hook also
            //    asks for the invoice to be refreshed after commit; by then the
            //    copy is void and that call does nothing.
            OrderCancellation::cancel($order, $by, "Invoice {$invoice->number} voided: {$reason}");

            // 3. The pay-link dies with the invoice.
            $order->forceFill(['payment_token_expires_at' => now()])->save();

            // 2. Void the invoice copy now, inside this transaction, with the reason.
            SalesDocumentRevisions::refreshInvoice($order, $by, $reason);
            $invoice->refresh();
            if ($invoice->status !== 'void') {
                // Never leave a cancelled order behind an open invoice.
                throw new \LogicException("Invoice {$invoice->number} did not void with its order.");
            }

            // 4. The quotation it came from.
            if ($quotation && $quotation->status !== Quotation::VOID) {
                $quotation->update([
                    'status'                 => Quotation::VOID,
                    'voided_at'              => now(),
                    'voided_by'              => $by->id,
                    'void_reason'            => $reason,
                    // The public link stops offering it (the page says it is
                    // no longer valid). The token itself is kept for the record.
                    'quote_token_expires_at' => now(),
                ]);
                SalesDocumentRevisions::voidQuotationCopy($quotation, $reason, $by);
            }

            // 5. One entry that says it all.
            $quoteNumber = $quotation?->quote_number;
            ActivityLogService::log('invoice_voided', $order, [
                'invoice_number' => $invoice->number,
                'quote_number'   => $quoteNumber,
                'order_number'   => $order->order_number,
                'reason'         => $reason,
                'voided_by'      => $by->id,
                'voided_name'    => $by->name,
            ], "Invoice {$invoice->number} voided by {$by->name}: order {$order->order_number} cancelled"
                . ($quoteNumber ? ", quotation {$quoteNumber} voided" : '')
                . ". Reason: {$reason}", $by);

            return ['invoice' => $invoice->fresh(), 'order' => $order->fresh(), 'quotation' => $quotation?->fresh()];
        });
    }

    /** The quotation an invoice derives from: its QUO parent, else the one converted into its order. */
    private static function quotationFor(SalesDocument $invoice, Order $order): ?Quotation
    {
        $parent = $invoice->parent_document_id ? SalesDocument::find($invoice->parent_document_id) : null;
        $id = $parent && $parent->documentable_type === Quotation::class ? $parent->documentable_id : null;

        $query = Quotation::withoutViewerScope()->lockForUpdate();

        return $id
            ? $query->whereKey($id)->first()
            : $query->where('converted_order_id', $order->id)->first();
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
