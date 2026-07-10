<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public, token-only customer view of a quotation (/quote/{token}) — the
 * customer-facing tail of the sales-document flow, mirroring the /pay/{token}
 * pay-link. The quote token IS the authorization; no login. The customer can
 * view their quote and accept it, which converts it into an invoice and hands
 * back the pay-link so they can pay immediately.
 */
class PublicQuotationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $quotation = $this->resolve($token);
        if (!$quotation) {
            return response()->json(['message' => 'Quotation link not found or expired.'], 404);
        }

        return response()->json(['quotation' => $this->publicView($quotation)]);
    }

    public function accept(string $token): JsonResponse
    {
        $quotation = $this->resolve($token);
        if (!$quotation) {
            return response()->json(['message' => 'Quotation link not found or expired.'], 404);
        }

        if ($quotation->converted_order_id) {
            // Already accepted — hand back the existing pay-link so the customer
            // can still pay (idempotent, friendly).
            $order = $quotation->convertedOrder;
            return response()->json([
                'message'   => 'This quotation was already accepted.',
                'pay_token' => $order?->payment_token,
            ]);
        }
        if ($quotation->status !== Quotation::SENT) {
            return response()->json(['message' => 'This quotation can no longer be accepted.'], 422);
        }
        if ($quotation->items->isEmpty() || $quotation->items->contains(fn ($i) => $i->product_id === null)) {
            // Ad-hoc lines can't become order lines — the business must finalise it.
            return response()->json(['message' => 'This quotation needs to be finalised by our team before it can be accepted online.'], 422);
        }

        $result = QuotationService::convertToInvoice($quotation);

        return response()->json([
            'message'   => 'Quotation accepted. You can now pay your invoice.',
            'pay_token' => $result['order']->payment_token,
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function resolve(string $token): ?Quotation
    {
        $quotation = Quotation::with('items')->where('quote_token', $token)->first();
        if (!$quotation) {
            return null;
        }
        if ($quotation->quote_token_expires_at && $quotation->quote_token_expires_at->isPast()) {
            return null;
        }
        return $quotation;
    }

    /** A safe, customer-facing projection — no internal ids, users, or costs. */
    private function publicView(Quotation $quotation): array
    {
        $business = DB::table('settings')->whereIn('key', ['app_name', 'app_email', 'app_phone', 'app_address'])
            ->pluck('value', 'key');

        return [
            'quote_number'  => $quotation->quote_number,
            'status'        => $quotation->status,
            'currency_code' => $quotation->currency_code,
            'issued_at'     => optional($quotation->issued_at)->toIso8601String(),
            'valid_until'   => optional($quotation->valid_until)->toDateString(),
            'is_expired'    => $quotation->isExpired(),
            'is_accepted'   => $quotation->converted_order_id !== null,
            'served_by'     => $quotation->served_by,
            'customer'      => [
                'first_name' => $quotation->customer_first_name,
                'last_name'  => $quotation->customer_last_name,
            ],
            'items' => $quotation->items->map(fn ($i) => [
                'product_name' => $i->product_name,
                'variant_name' => $i->variant_name,
                'quantity'     => (int) $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'total_price'  => (float) $i->total_price,
            ])->values(),
            'totals' => [
                'subtotal'        => (float) $quotation->subtotal,
                'tax_amount'      => (float) $quotation->tax_amount,
                'shipping_amount' => (float) $quotation->shipping_amount,
                'total_amount'    => (float) $quotation->total_amount,
            ],
            'notes'    => $quotation->notes,
            'terms'    => $quotation->terms,
            'business' => [
                'name'    => $business['app_name'] ?? 'Bethany House',
                'email'   => $business['app_email'] ?? null,
                'phone'   => $business['app_phone'] ?? null,
                'address' => $business['app_address'] ?? null,
            ],
        ];
    }
}
