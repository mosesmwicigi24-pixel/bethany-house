<?php

namespace App\Services\Documents;

use App\Models\DocumentRevision;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\SalesDocument;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\QuotationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revisions of issued SALES documents (owner, 2026-10-16) — the sales half of
 * App\Services\Procurement\PurchaseDocumentEditor. Every change keeps the
 * version it replaces in document_revisions (never changed or deleted), and
 * the document shows "Rev N".
 *
 *  - Quotation: the owner revises an issued quote (until it becomes an
 *    order — then the order is what changes).
 *  - Invoice: the INV copy follows its order. Whoever edits the order's lines,
 *    prices, shipping or currency — through the existing editors and their
 *    rules — the copy is refreshed and the old one kept; a voided or
 *    cancelled order voids it. Before, the copy silently went stale.
 *  - Receipt: the owner corrects a payment's details — reference, phone,
 *    method, date — never its amount: money taken is changed by voiding and
 *    recording again, so the drawer and collections still add up. A payment
 *    taken at a till keeps its method and date (the drawer counted it);
 *    cash is never switched in or out; a payment still awaiting approval
 *    keeps its method.
 */
class SalesDocumentRevisions
{
    // ── Invoice ──────────────────────────────────────────────────────────────

    /** The order fields an invoice copy shows: a change to any of them refreshes it. */
    public const ORDER_FIELDS = ['subtotal', 'discount_amount', 'tax_amount', 'shipping_amount', 'total_amount', 'currency_code', 'status'];

    public static function refreshInvoice(Order $order, ?User $by = null, ?string $reason = null): ?SalesDocument
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $doc = SalesDocument::where('type', SalesDocument::INVOICE)
                ->where('documentable_type', Order::class)
                ->where('documentable_id', $order->id)
                ->lockForUpdate()->first();
            if (!$doc || $doc->status === 'void') {
                return null;
            }

            $order = Order::withoutViewerScope()->with('items')->find($order->id);
            if (!$order) {
                return null;
            }

            $old = $doc->snapshot ?? [];
            $new = QuotationService::orderSnapshot($order, $doc->number);
            $new['issued_at'] = $old['issued_at'] ?? $new['issued_at'];

            $void   = in_array($order->status, ['voided', 'cancelled'], true);
            $total  = round((float) $order->total_amount, 2);
            $status = $void ? 'void'
                : ($total > 0 && $order->totalPaid() + 0.01 >= $total ? 'paid' : 'issued');

            $sameContent = ($old['items'] ?? null) == $new['items']
                && ($old['totals'] ?? null) == $new['totals']
                && ($old['currency_code'] ?? null) === $new['currency_code'];

            if ($sameContent && !$void) {
                if ($doc->status !== $status) {
                    $doc->update(['status' => $status]);   // paid ↔ issued only; content unchanged
                }

                return null;
            }

            $changes = [];
            $oldTotal = round((float) ($old['totals']['total_amount'] ?? $doc->amount), 2);
            if ($oldTotal !== $total) {
                $changes[] = 'Total ' . number_format($oldTotal, 2) . ' → ' . number_format($total, 2);
            }
            if (($old['items'] ?? null) != $new['items']) {
                $changes[] = 'Lines changed (' . count($old['items'] ?? []) . ' → ' . count($new['items']) . ')';
            }
            if (($old['currency_code'] ?? null) !== $new['currency_code']) {
                $changes[] = 'Currency ' . ($old['currency_code'] ?? '—') . ' → ' . $new['currency_code'];
            }
            if ($void) {
                $changes[] = 'Order ' . $order->status . ': invoice void';
            }

            $revision = (int) $doc->revision;
            DocumentRevision::create([
                'document_type' => DocumentRevision::INVOICE,
                'document_id'   => $doc->id,
                'revision'      => $revision,
                'snapshot'      => ['snapshot' => $old, 'amount' => (float) $doc->amount, 'status' => $doc->status],
                'changes'       => $changes,
                'reason'        => trim((string) $reason) !== '' ? trim($reason) : 'The order was changed.',
                'created_by'    => $by?->id,
            ]);

            $doc->update([
                'snapshot'      => $new + ['revision' => $revision + 1, 'revised_at' => now()->toIso8601String()],
                'amount'        => $total,
                'currency_code' => $order->currency_code,
                'status'        => $status,
                'revision'      => $revision + 1,
            ]);

            ActivityLogService::log('invoice_revised', $order, [
                'invoice_number' => $doc->number, 'revision' => $revision + 1, 'changes' => $changes, 'reason' => $reason,
            ], "Invoice {$doc->number} is now Rev " . ($revision + 1) . ': ' . implode('; ', $changes), $by);

            return $doc->fresh();
        });
    }

    // ── Quotation ────────────────────────────────────────────────────────────

    /**
     * $applyEdit performs the quotation edit itself (the controller's own
     * update rules). This keeps the issued copy as it was, refreshes it, and
     * re-offers a declined or expired quote.
     */
    public static function reviseQuotation(Quotation $quotation, callable $applyEdit, string $reason, User $owner): SalesDocument
    {
        return DB::transaction(function () use ($quotation, $applyEdit, $reason, $owner) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status === Quotation::DRAFT) {
                self::refuse('status', 'This quotation is still a draft: use Edit.');
            }
            if ($quotation->status === Quotation::VOID) {
                self::refuse('status', 'This quotation was voided with its invoice and can no longer be changed.');
            }
            if ($quotation->status === Quotation::CONVERTED || $quotation->converted_order_id) {
                self::refuse('status', 'This quotation became an order. Change the order instead — its invoice follows.');
            }
            $doc = SalesDocument::where('type', SalesDocument::QUOTATION)
                ->where('documentable_type', Quotation::class)
                ->where('documentable_id', $quotation->id)
                ->lockForUpdate()->first()
                ?? self::refuse('status', 'This quotation was never issued.');

            $old = $doc->snapshot ?? [];
            $applyEdit($quotation);
            $quotation = $quotation->fresh('items');

            $reoffer = in_array($quotation->status, [Quotation::DECLINED, Quotation::EXPIRED], true);
            if ($reoffer) {
                $quotation->update(['status' => Quotation::SENT]);
            }

            $new = QuotationService::snapshot($quotation);
            $compare = fn (array $s) => [$s['items'] ?? null, $s['totals'] ?? null, $s['valid_until'] ?? null, $s['customer'] ?? null,
                $s['currency_code'] ?? null, $s['notes'] ?? null, $s['terms'] ?? null, $s['served_by'] ?? null];
            if ($compare($old) == $compare($new)) {
                self::refuse('items', 'Nothing was changed.');
            }

            $changes = [];
            $oldTotal = round((float) ($old['totals']['total_amount'] ?? $doc->amount), 2);
            $newTotal = round((float) $quotation->total_amount, 2);
            if ($oldTotal !== $newTotal) {
                $changes[] = 'Total ' . number_format($oldTotal, 2) . ' → ' . number_format($newTotal, 2);
            }
            if (($old['items'] ?? null) != $new['items']) {
                $changes[] = 'Lines changed (' . count($old['items'] ?? []) . ' → ' . count($new['items']) . ')';
            }
            if (($old['valid_until'] ?? null) !== $new['valid_until']) {
                $changes[] = 'Valid until ' . ($old['valid_until'] ?? '—') . ' → ' . ($new['valid_until'] ?? '—');
            }
            if ($compare($old) != $compare($new) && $changes === []) {
                $changes[] = 'Customer, notes or terms changed';
            }
            if ($reoffer) {
                $changes[] = 'Offered again (was ' . $doc->status . ')';
            }

            $revision = (int) $doc->revision;
            DocumentRevision::create([
                'document_type' => DocumentRevision::QUOTATION,
                'document_id'   => $doc->id,
                'revision'      => $revision,
                'snapshot'      => ['snapshot' => $old, 'amount' => (float) $doc->amount, 'status' => $doc->status, 'valid_until' => optional($doc->valid_until)->toDateString()],
                'changes'       => $changes,
                'reason'        => $reason,
                'created_by'    => $owner->id,
            ]);

            $doc->update([
                'snapshot'      => $new + ['revision' => $revision + 1, 'revised_at' => now()->toIso8601String()],
                'amount'        => $newTotal,
                'currency_code' => $quotation->currency_code,
                'valid_until'   => $quotation->valid_until,
                'status'        => $reoffer ? 'sent' : $doc->status,
                'revision'      => $revision + 1,
            ]);

            ActivityLogService::log('quotation_revised', $quotation, [
                'quote_number' => $quotation->quote_number, 'revision' => $revision + 1, 'changes' => $changes, 'reason' => $reason,
            ], "Quotation {$quotation->quote_number} revised to Rev " . ($revision + 1) . " by the owner: {$reason}", $owner);

            return $doc->fresh();
        });
    }

    /**
     * The issued QUO copy of a quotation being voided (with its invoice —
     * App\Services\Documents\InvoiceVoid): the version it replaces is kept as
     * a revision, like an invoice voided by its order, and the copy says void.
     * Its content is untouched. Returns null when the quotation was never
     * issued or its copy is already void.
     */
    public static function voidQuotationCopy(Quotation $quotation, string $reason, User $by): ?SalesDocument
    {
        return DB::transaction(function () use ($quotation, $reason, $by) {
            $doc = SalesDocument::where('type', SalesDocument::QUOTATION)
                ->where('documentable_type', Quotation::class)
                ->where('documentable_id', $quotation->id)
                ->lockForUpdate()->first();
            if (!$doc || $doc->status === 'void') {
                return null;
            }

            $revision = (int) $doc->revision;
            $changes  = ['Quotation void (was ' . $doc->status . ')'];
            DocumentRevision::create([
                'document_type' => DocumentRevision::QUOTATION,
                'document_id'   => $doc->id,
                'revision'      => $revision,
                'snapshot'      => ['snapshot' => $doc->snapshot ?? [], 'amount' => (float) $doc->amount, 'status' => $doc->status, 'valid_until' => optional($doc->valid_until)->toDateString()],
                'changes'       => $changes,
                'reason'        => $reason,
                'created_by'    => $by->id,
            ]);

            $doc->update([
                'snapshot' => array_merge($doc->snapshot ?? [], ['revision' => $revision + 1, 'voided_at' => now()->toIso8601String(), 'void_reason' => $reason]),
                'status'   => 'void',
                'revision' => $revision + 1,
            ]);

            return $doc->fresh();
        });
    }

    // ── Receipt (a payment's details) ────────────────────────────────────────

    /** @param array $data provider_reference, phone_number, payment_method, paid_at (each optional) */
    public static function revisePayment(Payment $payment, array $data, string $reason, User $owner): Payment
    {
        return DB::transaction(function () use ($payment, $data, $reason, $owner) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($payment->status, ['voided', 'failed', 'cancelled'], true)) {
                self::refuse('payment', "This payment is {$payment->status}; there is nothing to correct.");
            }

            $atTill = DB::table('cash_register_transactions')->where('order_id', $payment->order_id)->exists();
            $changes = [];
            $update  = [];

            foreach (['provider_reference' => 'Reference', 'phone_number' => 'Phone'] as $field => $label) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $payment->{$field}) {
                    $update[$field] = $data[$field] === '' ? null : $data[$field];
                    $changes[] = "{$label} " . ($payment->{$field} ?? '—') . ' → ' . ($update[$field] ?? '—');
                }
            }

            if (array_key_exists('payment_method', $data) && $data['payment_method'] !== $payment->payment_method) {
                if ($payment->status !== 'paid') {
                    self::refuse('payment_method', 'A payment still awaiting approval keeps its method.');
                }
                if ($atTill) {
                    self::refuse('payment_method', 'This payment went through a till, which counted it by method. Void it and record it again.');
                }
                if ($payment->payment_method === 'cash' || $data['payment_method'] === 'cash') {
                    self::refuse('payment_method', 'Cash is counted in a drawer: a payment is never switched to or from cash. Void it and record it again.');
                }
                $update['payment_method'] = $data['payment_method'];
                $changes[] = "Method {$payment->payment_method} → {$data['payment_method']}";
            }

            if (array_key_exists('paid_at', $data) && $data['paid_at'] !== null) {
                $new = \Illuminate\Support\Carbon::parse($data['paid_at'], config('app.timezone'));
                $old = $payment->paid_at;
                if (!$old || !$new->equalTo($old)) {
                    if ($atTill) {
                        self::refuse('paid_at', "This payment went through a till; its date is that till's day. Void it and record it again.");
                    }
                    if ($payment->status !== 'paid') {
                        self::refuse('paid_at', 'A payment still awaiting approval is dated when it is approved.');
                    }
                    $orderDate = optional(Order::withoutViewerScope()->find($payment->order_id))->created_at;
                    if ($new->isFuture() || ($orderDate && $new->lt($orderDate->copy()->startOfDay()))) {
                        self::refuse('paid_at', 'The date must be between the order date and now.');
                    }
                    $update['paid_at'] = $new;
                    $changes[] = 'Paid ' . ($old?->format('j M Y H:i') ?? '—') . ' → ' . $new->format('j M Y H:i');
                }
            }

            if ($changes === []) {
                self::refuse('payment', 'Nothing was changed.');
            }

            $receipt = SalesDocument::where('type', SalesDocument::RECEIPT)->where('payment_id', $payment->id)->lockForUpdate()->first();
            $revision = DocumentRevision::where('document_type', DocumentRevision::PAYMENT)->where('document_id', $payment->id)->count();
            DocumentRevision::create([
                'document_type' => DocumentRevision::PAYMENT,
                'document_id'   => $payment->id,
                'revision'      => $revision,
                'snapshot'      => [
                    'payment' => $payment->only(['payment_number', 'payment_method', 'amount', 'currency_code', 'status', 'provider_reference', 'phone_number', 'paid_at']),
                    'receipt' => $receipt ? ['number' => $receipt->number, 'snapshot' => $receipt->snapshot] : null,
                ],
                'changes'       => $changes,
                'reason'        => $reason,
                'created_by'    => $owner->id,
            ]);

            $payment->update($update);

            if ($receipt) {
                $snap = $receipt->snapshot ?? [];
                $snap['payment'] = array_merge($snap['payment'] ?? [], [
                    'method'    => $payment->payment_method,
                    'reference' => $payment->provider_reference,
                    'paid_at'   => optional($payment->paid_at)->toIso8601String(),
                ]);
                $snap['revision']   = (int) $receipt->revision + 1;
                $snap['revised_at'] = now()->toIso8601String();
                $receipt->update(['snapshot' => $snap, 'revision' => (int) $receipt->revision + 1]);
            }

            ActivityLogService::log('payment_details_revised', $payment, [
                'revision' => $revision + 1, 'changes' => $changes, 'reason' => $reason, 'receipt' => $receipt?->number,
            ], "Payment {$payment->payment_number} details corrected by the owner: " . implode('; ', $changes), $owner);

            return $payment->fresh();
        });
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
