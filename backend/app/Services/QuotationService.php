<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Quotation;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issuing a quotation: allocate its gapless QUO number and freeze an immutable
 * snapshot into the sales_documents ledger — both in one transaction, so the
 * number is consumed only if the issue commits. Idempotent: re-issuing an
 * already-issued quotation returns the existing document (no new number burned).
 */
class QuotationService
{
    public static function issue(Quotation $quotation, ?int $userId = null): SalesDocument
    {
        return DB::transaction(function () use ($quotation, $userId) {
            $quotation->loadMissing('items');

            // Already issued → return the existing artifact (idempotent).
            $existing = SalesDocument::where('type', SalesDocument::QUOTATION)
                ->where('documentable_type', Quotation::class)
                ->where('documentable_id', $quotation->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            if (!$quotation->quote_number) {
                $quotation->quote_number = DocumentNumberService::next('quotation');
            }
            // Public, unguessable link token so the quote can be sent to the
            // customer (/quote/{token}); mirrors orders.payment_token.
            if (!$quotation->quote_token) {
                $quotation->quote_token = hash_hmac(
                    'sha256',
                    $quotation->quote_number . now()->toISOString() . Str::random(8),
                    config('app.key'),
                );
                $quotation->quote_token_expires_at = $quotation->valid_until
                    ? $quotation->valid_until->copy()->endOfDay()
                    : now()->addDays(30);
            }
            $quotation->status    = Quotation::SENT;
            $quotation->issued_at = $quotation->issued_at ?? now();
            $quotation->save();

            return SalesDocument::create([
                'type'              => SalesDocument::QUOTATION,
                'number'            => $quotation->quote_number,
                'documentable_type' => Quotation::class,
                'documentable_id'   => $quotation->id,
                'issued_at'         => now(),
                'valid_until'       => $quotation->valid_until,
                'status'            => 'sent',
                'amount'            => $quotation->total_amount,
                'currency_code'     => $quotation->currency_code,
                'snapshot'          => self::snapshot($quotation),
                'created_by'        => $userId,
            ]);
        });
    }

    /** A frozen copy of everything the quotation said at issue time. */
    public static function snapshot(Quotation $quotation): array
    {
        $quotation->loadMissing('items');

        return [
            'quote_number' => $quotation->quote_number,
            'issued_at'    => optional($quotation->issued_at)->toIso8601String(),
            'valid_until'  => optional($quotation->valid_until)->toDateString(),
            'currency_code'=> $quotation->currency_code,
            'customer'     => [
                'first_name' => $quotation->customer_first_name,
                'last_name'  => $quotation->customer_last_name,
                'email'      => $quotation->customer_email,
                'phone'      => $quotation->customer_phone,
            ],
            'items' => $quotation->items->map(fn ($i) => [
                'product_name'    => $i->product_name,
                'variant_name'    => $i->variant_name,
                'sku'             => $i->sku,
                'quantity'        => (int) $i->quantity,
                'unit_price'      => (float) $i->unit_price,
                'discount_amount' => (float) $i->discount_amount,
                'tax_amount'      => (float) $i->tax_amount,
                'total_price'     => (float) $i->total_price,
            ])->all(),
            'totals' => [
                'subtotal'        => (float) $quotation->subtotal,
                'discount_amount' => (float) $quotation->discount_amount,
                'tax_amount'      => (float) $quotation->tax_amount,
                'total_amount'    => (float) $quotation->total_amount,
            ],
            'notes' => $quotation->notes,
            'terms' => $quotation->terms,
        ];
    }

    /**
     * Accept a quotation and turn it into an INVOICE: create the Order (the
     * invoice), reserve its stock, mint the customer pay-link, and issue the INV
     * document (linked to the QUO it came from). All in one transaction.
     *
     * Idempotent: a quotation already converted returns its existing order+invoice.
     * Callers must ensure every line is product-linked (order_items.product_id is
     * NOT NULL) — ad-hoc quote lines can't become order lines.
     *
     * @return array{order: Order, invoice: SalesDocument, already: bool}
     */
    public static function convertToInvoice(Quotation $quotation, ?int $userId = null, int $dueInDays = 14): array
    {
        $quotation->loadMissing('items');

        if ($quotation->converted_order_id) {
            $order   = Order::with('items')->find($quotation->converted_order_id);
            $invoice = SalesDocument::where('type', SalesDocument::INVOICE)
                ->where('documentable_type', Order::class)
                ->where('documentable_id', $quotation->converted_order_id)
                ->first();
            return ['order' => $order, 'invoice' => $invoice, 'already' => true];
        }

        return DB::transaction(function () use ($quotation, $userId, $dueInDays) {
            // Order number (settings prefix + random, uniqueness-checked — same as
            // OrderController) and an HMAC pay-link token.
            $prefix      = DB::table('settings')->where('key', 'order_prefix')->value('value') ?? 'ORD-';
            $orderNumber = $prefix . strtoupper(Str::random(8));
            while (Order::where('order_number', $orderNumber)->exists()) {
                $orderNumber = $prefix . strtoupper(Str::random(8));
            }
            $paymentToken = hash_hmac('sha256', $orderNumber . now()->toISOString() . Str::random(8), config('app.key'));

            $order = Order::create([
                'order_number'             => $orderNumber,
                'user_id'                  => $quotation->user_id,
                'outlet_id'                => $quotation->outlet_id,
                'order_type'               => 'online',
                'status'                   => 'pending',
                'payment_status'           => 'pending',
                'currency_code'            => $quotation->currency_code,
                'customer_email'           => $quotation->customer_email,
                'customer_phone'           => $quotation->customer_phone,
                'customer_first_name'      => $quotation->customer_first_name,
                'customer_last_name'       => $quotation->customer_last_name,
                'subtotal'                 => $quotation->subtotal,
                'discount_amount'          => $quotation->discount_amount,
                'tax_amount'               => $quotation->tax_amount,
                'total_amount'             => $quotation->total_amount,
                'payment_token'            => $paymentToken,
                'payment_token_expires_at' => now()->addDays(max($dueInDays, 30)),
                'created_by'               => $userId,
            ]);

            foreach ($quotation->items as $qi) {
                OrderItem::create([
                    'order_id'           => $order->id,
                    'product_id'         => $qi->product_id,
                    'product_variant_id' => $qi->product_variant_id,
                    'sku'                => $qi->sku ?: 'N/A',
                    'product_name'       => $qi->product_name,
                    'variant_name'       => $qi->variant_name,
                    'quantity'           => (int) $qi->quantity,
                    'unit_price'         => $qi->unit_price,
                    'discount_amount'    => $qi->discount_amount,
                    'tax_amount'         => $qi->tax_amount,
                    'total_price'        => $qi->total_price,
                ]);
            }

            // Reserve stock — an issued invoice HOLDS the goods. Physical count is
            // untouched until payment commits (Phase 3). Non-inventory lines skip.
            PosInventoryService::reserveForOrder($order->fresh('items'));

            // Issue the INV document, linked to the QUO it derives from.
            $quoteDoc = SalesDocument::where('type', SalesDocument::QUOTATION)
                ->where('documentable_type', Quotation::class)
                ->where('documentable_id', $quotation->id)
                ->first();

            $invoiceNumber = DocumentNumberService::next('invoice');
            $invoice = SalesDocument::create([
                'type'               => SalesDocument::INVOICE,
                'number'             => $invoiceNumber,
                'documentable_type'  => Order::class,
                'documentable_id'    => $order->id,
                'parent_document_id' => $quoteDoc?->id,
                'issued_at'          => now(),
                'due_date'           => now()->addDays($dueInDays)->toDateString(),
                'status'             => 'issued',
                'amount'             => $order->total_amount,
                'currency_code'      => $order->currency_code,
                'snapshot'           => self::orderSnapshot($order, $invoiceNumber),
                'created_by'         => $userId,
            ]);

            $quotation->update([
                'converted_order_id' => $order->id,
                'status'             => Quotation::CONVERTED,
                'accepted_at'        => now(),
            ]);

            return ['order' => $order->fresh('items'), 'invoice' => $invoice, 'already' => false];
        });
    }

    /** Frozen snapshot of the order at invoice-issue time. */
    public static function orderSnapshot(Order $order, string $invoiceNumber): array
    {
        $order->loadMissing('items');

        return [
            'invoice_number' => $invoiceNumber,
            'order_number'   => $order->order_number,
            'issued_at'      => now()->toIso8601String(),
            'currency_code'  => $order->currency_code,
            'customer'       => [
                'first_name' => $order->customer_first_name,
                'last_name'  => $order->customer_last_name,
                'email'      => $order->customer_email,
                'phone'      => $order->customer_phone,
            ],
            'items' => $order->items->map(fn ($i) => [
                'product_name'    => $i->product_name,
                'variant_name'    => $i->variant_name,
                'sku'             => $i->sku,
                'quantity'        => (int) $i->quantity,
                'unit_price'      => (float) $i->unit_price,
                'discount_amount' => (float) $i->discount_amount,
                'tax_amount'      => (float) $i->tax_amount,
                'total_price'     => (float) $i->total_price,
            ])->all(),
            'totals' => [
                'subtotal'        => (float) $order->subtotal,
                'discount_amount' => (float) $order->discount_amount,
                'tax_amount'      => (float) $order->tax_amount,
                'total_amount'    => (float) $order->total_amount,
            ],
        ];
    }
}
