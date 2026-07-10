<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Services\ActivityLogService;
use App\Services\QuotationService;
use App\Services\TaxCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quotations — the priced offer at the front of the quotation → invoice → receipt
 * flow. Drafts are freely editable; once issued (a QUO number + immutable snapshot
 * in sales_documents) the quotation is frozen. See App\Services\QuotationService.
 */
class QuotationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Quotation::query()->with('items')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->integer('outlet_id'));
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->string('search'));
            $query->where(function ($q) use ($s) {
                $q->where('quote_number', 'ILIKE', "%{$s}%")
                    ->orWhere('customer_email', 'ILIKE', "%{$s}%")
                    ->orWhere('customer_phone', 'ILIKE', "%{$s}%")
                    ->orWhere('customer_first_name', 'ILIKE', "%{$s}%")
                    ->orWhere('customer_last_name', 'ILIKE', "%{$s}%");
            });
        }

        return response()->json($query->paginate(min((int) $request->integer('per_page', 25), 100)));
    }

    public function show(int $id): JsonResponse
    {
        $quotation = Quotation::with(['items', 'documents', 'convertedOrder:id,order_number,status,payment_status'])
            ->findOrFail($id);

        return response()->json(['quotation' => $quotation]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $quotation = DB::transaction(function () use ($validated, $request) {
            $quotation = Quotation::create([
                'user_id'             => $validated['user_id'] ?? null,
                'outlet_id'           => $validated['outlet_id'] ?? null,
                'source'              => $validated['source'] ?? 'admin',
                'status'              => Quotation::DRAFT,
                'currency_code'       => $validated['currency_code'] ?? 'KES',
                'shipping_amount'     => $validated['shipping_amount'] ?? 0,
                'served_by'           => $validated['served_by'] ?? $request->user()?->name,
                'customer_email'      => $validated['customer_email'] ?? null,
                'customer_phone'      => $validated['customer_phone'] ?? null,
                'customer_first_name' => $validated['customer_first_name'] ?? null,
                'customer_last_name'  => $validated['customer_last_name'] ?? null,
                'valid_until'         => $validated['valid_until'] ?? null,
                'notes'               => $validated['notes'] ?? null,
                'terms'               => $validated['terms'] ?? null,
                'created_by'          => $request->user()?->id,
            ]);

            $this->syncItemsAndTotals($quotation, $validated['items']);

            return $quotation;
        });

        ActivityLogService::log('created', $quotation, ['status' => 'draft']);

        return response()->json(['quotation' => $quotation->fresh('items')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);

        // An issued quotation is frozen — corrections are made by issuing a new one.
        if ($quotation->status !== Quotation::DRAFT) {
            return response()->json(['message' => 'Only a draft quotation can be edited.'], 422);
        }

        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($quotation, $validated) {
            $quotation->update([
                'user_id'             => $validated['user_id'] ?? $quotation->user_id,
                'outlet_id'           => $validated['outlet_id'] ?? $quotation->outlet_id,
                'currency_code'       => $validated['currency_code'] ?? $quotation->currency_code,
                'shipping_amount'     => $validated['shipping_amount'] ?? $quotation->shipping_amount,
                'served_by'           => $validated['served_by'] ?? $quotation->served_by,
                'customer_email'      => $validated['customer_email'] ?? null,
                'customer_phone'      => $validated['customer_phone'] ?? null,
                'customer_first_name' => $validated['customer_first_name'] ?? null,
                'customer_last_name'  => $validated['customer_last_name'] ?? null,
                'valid_until'         => $validated['valid_until'] ?? null,
                'notes'               => $validated['notes'] ?? null,
                'terms'               => $validated['terms'] ?? null,
            ]);

            QuotationItem::where('quotation_id', $quotation->id)->delete();
            $this->syncItemsAndTotals($quotation, $validated['items']);
        });

        return response()->json(['quotation' => $quotation->fresh('items')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);

        if ($quotation->status !== Quotation::DRAFT) {
            return response()->json(['message' => 'Only a draft quotation can be deleted.'], 422);
        }

        $quotation->delete();

        return response()->json(['message' => 'Quotation deleted.']);
    }

    /** Issue the quotation: allocate its QUO number + freeze the snapshot. */
    public function issue(Request $request, int $id): JsonResponse
    {
        $quotation = Quotation::with('items')->findOrFail($id);

        if ($quotation->items->isEmpty()) {
            return response()->json(['message' => 'Cannot issue a quotation with no items.'], 422);
        }
        if (!in_array($quotation->status, [Quotation::DRAFT, Quotation::SENT])) {
            return response()->json(['message' => 'This quotation can no longer be issued.'], 422);
        }

        $document = QuotationService::issue($quotation, $request->user()?->id);

        ActivityLogService::log('issued', $quotation->fresh(), ['quote_number' => $document->number]);

        return response()->json([
            'message'   => 'Quotation issued.',
            'quotation' => $quotation->fresh('items'),
            'document'  => $document,
        ]);
    }

    /**
     * Accept an issued quotation and convert it into an invoice (an Order + INV
     * document + reserved stock + pay-link).
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        $quotation = Quotation::with('items')->findOrFail($id);

        if ($quotation->converted_order_id) {
            return response()->json(['message' => 'This quotation has already been converted to an invoice.'], 422);
        }
        if (!in_array($quotation->status, [Quotation::SENT, Quotation::ACCEPTED])) {
            return response()->json(['message' => 'Issue the quotation before accepting it.'], 422);
        }
        if ($quotation->items->isEmpty()) {
            return response()->json(['message' => 'Cannot convert a quotation with no items.'], 422);
        }

        $validated = $request->validate([
            'due_in_days' => 'nullable|integer|min:0|max:365',
        ]);

        $result = QuotationService::convertToInvoice(
            $quotation,
            $request->user()?->id,
            $validated['due_in_days'] ?? 14,
        );

        ActivityLogService::log('converted', $quotation->fresh(), [
            'order_id'       => $result['order']->id,
            'invoice_number' => $result['invoice']->number,
        ]);

        return response()->json([
            'message'  => 'Quotation accepted — invoice created.',
            'order'    => $result['order'],
            'invoice'  => $result['invoice'],
            'pay_token' => $result['order']->payment_token,
        ], 201);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'user_id'             => 'nullable|integer|exists:users,id',
            'outlet_id'           => 'nullable|integer|exists:outlets,id',
            'source'              => 'nullable|in:admin,storefront',
            'currency_code'       => 'nullable|string|size:3',
            'shipping_amount'     => 'nullable|numeric|min:0',
            'served_by'           => 'nullable|string|max:150',
            'customer_email'      => 'nullable|email|max:255',
            'customer_phone'      => 'nullable|string|max:20',
            'customer_first_name' => 'nullable|string|max:100',
            'customer_last_name'  => 'nullable|string|max:100',
            'valid_until'         => 'nullable|date',
            'notes'               => 'nullable|string',
            'terms'               => 'nullable|string',
            'items'                       => 'required|array|min:1',
            'items.*.product_id'          => 'nullable|integer|exists:products,id',
            'items.*.product_variant_id'  => 'nullable|integer|exists:product_variants,id',
            'items.*.product_name'        => 'required|string|max:255',
            'items.*.variant_name'        => 'nullable|string|max:255',
            'items.*.sku'                 => 'nullable|string|max:100',
            'items.*.quantity'            => 'required|integer|min:1',
            'items.*.unit_price'          => 'required|numeric|min:0',
            'items.*.discount_amount'     => 'nullable|numeric|min:0',
        ]);
    }

    /**
     * Build the quotation's line items and roll up its totals, deriving tax from
     * TaxCalculationService (same engine orders use), then persist both.
     */
    private function syncItemsAndTotals(Quotation $quotation, array $items): void
    {
        $lines = collect($items)->map(fn ($i) => [
            'product_id'      => (int) ($i['product_id'] ?? 0),
            'unit_price'      => (float) $i['unit_price'],
            'quantity'        => (int) $i['quantity'],
            'discount_amount' => (float) ($i['discount_amount'] ?? 0),
        ])->all();

        $calc = TaxCalculationService::calculateOrder($lines);

        foreach ($items as $idx => $item) {
            $line      = $calc['lines'][$idx];
            $productId = $item['product_id'] ?? null;
            QuotationItem::create([
                'quotation_id'       => $quotation->id,
                'product_id'         => $productId,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'sku'                => $item['sku'] ?? ($productId ? Product::find($productId)?->sku : null),
                'product_name'       => $item['product_name'],
                'variant_name'       => $item['variant_name'] ?? null,
                'quantity'           => (int) $item['quantity'],
                'unit_price'         => (float) $item['unit_price'],
                'discount_amount'    => (float) ($item['discount_amount'] ?? 0),
                'tax_amount'         => round((float) $line['tax_amount'], 2),
                'total_price'        => round((float) $line['subtotal_gross'], 2),
            ]);
        }

        $quotation->update([
            'subtotal'        => $calc['subtotal'],
            'discount_amount' => round(collect($items)->sum(fn ($i) => (float) ($i['discount_amount'] ?? 0)), 2),
            'tax_amount'      => $calc['total_tax'],
            // Grand total includes the shipping charge (already persisted on the row).
            'total_amount'    => round($calc['total_gross'] + (float) $quotation->shipping_amount, 2),
        ]);
    }
}
