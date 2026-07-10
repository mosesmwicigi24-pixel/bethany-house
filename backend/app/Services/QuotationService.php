<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;

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
}
