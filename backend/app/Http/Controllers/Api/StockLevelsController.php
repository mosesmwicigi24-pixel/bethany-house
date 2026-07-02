<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\IntelligenceService;

class StockLevelsController extends Controller
{
    // =========================================================================
    // GET /api/v1/admin/inventory/stock-levels
    // Paginated stock levels across all products, outlets, variants.
    // =========================================================================

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 30), 100);

        $query = InventoryItem::with([
            'product:id,sku,status',
            'product.translations' => fn($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'product.images'       => fn($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'variant:id,sku,variant_name,attributes',
            'outlet:id,name,code',
        ]);

        // Filters
        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->filled('status')) {
            match ($request->status) {
                'out_of_stock' => $query->outOfStock(),
                'low_stock'    => $query->lowStock(),
                'in_stock'     => $query->inStock(),
                default        => null,
            };
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas(
                    'product',
                    fn($p) =>
                    $p->where('sku', 'ILIKE', "%{$search}%")
                        ->orWhereHas(
                            'translations',
                            fn($t) =>
                            $t->where('language_code', 'en')->where('name', 'ILIKE', "%{$search}%")
                        )
                )
                    ->orWhereHas(
                        'variant',
                        fn($v) =>
                        $v->where('sku', 'ILIKE', "%{$search}%")
                    );
            });
        }

        $sortBy = $request->get('sort_by', 'quantity_on_hand');
        $query->orderBy($sortBy, $request->get('sort_dir', 'asc'));

        $items = $query->paginate($perPage);

        // Stats
        $stats = [
            'total_skus'      => InventoryItem::count(),
            'in_stock'        => InventoryItem::inStock()->count(),
            'low_stock'       => InventoryItem::lowStock()->count(),
            'out_of_stock'    => InventoryItem::outOfStock()->count(),
            'total_value'     => InventoryItem::sum(DB::raw('quantity_on_hand * COALESCE(reorder_point, 0)')),
        ];

        return response()->json([
            'data'  => collect($items->items())->map(fn($i) => $this->formatItem($i)),
            'meta'  => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'total'        => $items->total(),
                'from'         => $items->firstItem(),
                'to'           => $items->lastItem(),
            ],
            'stats' => $stats,
        ]);
    }

    // =========================================================================
    // GET /api/v1/admin/inventory/stock-levels/{id}
    // Single stock record with movement history.
    // =========================================================================

    public function show($id)
    {
        $item = InventoryItem::with([
            'product.translations',
            'product.images' => fn($q) => $q->where('is_primary', true),
            'variant',
            'outlet',
        ])->findOrFail($id);

        $transactions = InventoryTransaction::where('inventory_item_id', $id)
            ->with('createdBy:id,first_name,last_name,email')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'item'         => $this->formatItem($item, true),
            'transactions' => $transactions->map(fn($t) => $this->formatTransaction($t)),
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/inventory/stock-levels/opening
    // Set opening stock for a product/variant/outlet combination.
    // Creates the InventoryItem if it doesn't exist.
    // =========================================================================

    public function setOpeningStock(Request $request)
    {
        $validated = $request->validate([
            'entries'                   => 'required|array|min:1',
            'entries.*.product_id'      => 'required|exists:products,id',
            'entries.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'entries.*.outlet_id'       => 'required|exists:outlets,id',
            'entries.*.quantity'        => 'required|integer|min:0',
            'entries.*.reorder_point'   => 'nullable|integer|min:0',
            'entries.*.reorder_quantity' => 'nullable|integer|min:0',
            'entries.*.notes'           => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $results = [];
            $userId  = auth()->id();

            foreach ($validated['entries'] as $entry) {
                $item = InventoryItem::firstOrNew([
                    'product_id'         => $entry['product_id'],
                    'product_variant_id' => $entry['product_variant_id'] ?? null,
                    'outlet_id'          => $entry['outlet_id'],
                ]);

                $isNew       = !$item->exists;
                $oldQuantity = $item->quantity_on_hand ?? 0;
                $newQuantity = $entry['quantity'];

                $item->quantity_on_hand  = $newQuantity;
                $item->quantity_reserved = $item->quantity_reserved ?? 0;
                $item->reorder_point     = $entry['reorder_point']    ?? $item->reorder_point ?? 0;
                $item->reorder_quantity  = $entry['reorder_quantity']  ?? $item->reorder_quantity ?? 0;

                if ($isNew) {
                    $item->last_counted_at = now();
                }

                $item->save();

                // Intelligence #1 — auto-draft PO if stock drops to/below reorder point
                if ($item->reorder_point > 0 && $item->quantity_on_hand <= $item->reorder_point) {
                    IntelligenceService::autoReorderSuggestion($item, $userId);
                }

                // Log the opening stock transaction
                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'transaction_type'  => $isNew ? 'opening_stock' : 'adjustment',
                    'reference_type'    => 'opening_stock',
                    'reference_id'      => $item->id,
                    'quantity_change'   => $newQuantity - $oldQuantity,
                    'quantity_before'   => $oldQuantity,
                    'quantity_after'    => $newQuantity,
                    'notes'             => $entry['notes'] ?? ($isNew ? 'Opening stock entry' : 'Opening stock adjustment'),
                    'created_by'        => $userId,
                ]);

                $results[] = $this->formatItem($item->fresh()->load(['product.translations', 'variant', 'outlet']));
            }

            DB::commit();

            return response()->json([
                'message' => count($results) . ' stock record(s) saved.',
                'data'    => $results,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to save stock.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // GET /api/v1/admin/inventory/stock-levels/{id}/history
    // Movement log for a single inventory item.
    // =========================================================================

    public function history(Request $request, $id)
    {
        $item = InventoryItem::findOrFail($id);

        $perPage = min((int) $request->get('per_page', 50), 200);

        $query = InventoryTransaction::where('inventory_item_id', $id)
            ->with('createdBy:id,first_name,last_name,email');

        if ($request->filled('type')) {
            $query->where('transaction_type', $request->type);
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to . ' 23:59:59');
        }

        $transactions = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'item'         => $this->formatItem($item->load(['product.translations', 'variant', 'outlet'])),
            'data'         => $transactions->items(),
            'meta'         => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'total'        => $transactions->total(),
            ],
        ]);
    }

    // =========================================================================
    // GET /api/v1/admin/inventory/stock-levels/by-product/{productId}
    // All outlet stock for a single product - useful from the product page.
    // =========================================================================

    public function byProduct($productId)
    {
        $product = Product::with([
            'translations' => fn($q) => $q->where('language_code', 'en'),
            'variants:id,sku,variant_name,attributes',
        ])->findOrFail($productId);

        $items = InventoryItem::with(['outlet:id,name,code', 'variant:id,sku,variant_name'])
            ->where('product_id', $productId)
            ->get()
            ->map(fn($i) => $this->formatItem($i));

        // Group by outlet for the UI
        $byOutlet = $items->groupBy('outlet.id')->map(function ($outletItems) {
            $first = $outletItems->first();
            return [
                'outlet'        => $first['outlet'],
                'items'         => $outletItems->values(),
                'total_on_hand' => $outletItems->sum('quantity_on_hand'),
                'total_available' => $outletItems->sum('quantity_available'),
            ];
        })->values();

        return response()->json([
            'product'  => [
                'id'   => $product->id,
                'sku'  => $product->sku,
                'name' => $product->translations->first()?->name ?? $product->sku,
            ],
            'data'      => $items,
            'by_outlet' => $byOutlet,
            'totals'    => [
                'quantity_on_hand'  => $items->sum('quantity_on_hand'),
                'quantity_reserved' => $items->sum('quantity_reserved'),
                'quantity_available' => $items->sum('quantity_available'),
            ],
        ]);
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/stock-levels/{id}
    // Update reorder settings for an inventory item.
    // =========================================================================

    public function update(Request $request, $id)
    {
        $item = InventoryItem::findOrFail($id);

        $validated = $request->validate([
            'reorder_point'    => 'sometimes|integer|min:0',
            'reorder_quantity' => 'sometimes|integer|min:0',
        ]);

        $item->update($validated);

        return response()->json([
            'message' => 'Stock settings updated.',
            'item'    => $this->formatItem($item->fresh()->load(['product.translations', 'variant', 'outlet'])),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function formatItem(InventoryItem $item, bool $withStats = false): array
    {
        $available = max(0, $item->quantity_on_hand - $item->quantity_reserved);
        $isLow     = $item->reorder_point > 0 && $available <= $item->reorder_point && $available > 0;
        $isOut     = $available <= 0;

        $data = [
            'id'                  => $item->id,
            'product_id'          => $item->product_id,
            'product_variant_id'  => $item->product_variant_id,
            'outlet_id'           => $item->outlet_id,
            'quantity_on_hand'    => (int) $item->quantity_on_hand,
            'quantity_reserved'   => (int) $item->quantity_reserved,
            'quantity_available'  => (int) $available,
            'reorder_point'       => (int) ($item->reorder_point ?? 0),
            'reorder_quantity'    => (int) ($item->reorder_quantity ?? 0),
            'last_counted_at'     => $item->last_counted_at,
            'status'              => $isOut ? 'out_of_stock' : ($isLow ? 'low_stock' : 'in_stock'),
            'product'             => $item->relationLoaded('product') && $item->product ? [
                'id'         => $item->product->id,
                'sku'        => $item->product->sku,
                'name'       => $item->product->translations?->first()?->name ?? $item->product->sku,
                'image_url'  => $item->product->images?->first()?->image_url,
            ] : null,
            'variant'             => $item->relationLoaded('variant') && $item->variant ? [
                'id'           => $item->variant->id,
                'sku'          => $item->variant->sku,
                'variant_name' => $item->variant->variant_name,
                'attributes'   => $item->variant->attributes,
            ] : null,
            'outlet'              => $item->relationLoaded('outlet') && $item->outlet ? [
                'id'   => $item->outlet->id,
                'name' => $item->outlet->name,
                'code' => $item->outlet->code ?? null,
            ] : null,
        ];

        return $data;
    }

    private function formatTransaction(InventoryTransaction $t): array
    {
        return [
            'id'               => $t->id,
            'transaction_type' => $t->transaction_type,
            'reference_type'   => $t->reference_type,
            'reference_id'     => $t->reference_id,
            'quantity_change'  => $t->quantity_change,
            'quantity_before'  => $t->quantity_before,
            'quantity_after'   => $t->quantity_after,
            'notes'            => $t->notes,
            'created_at'       => $t->created_at,
            'created_by'       => $t->createdBy ? [
                'id'   => $t->createdBy->id,
                'name' => trim(($t->createdBy->first_name ?? '') . ' ' . ($t->createdBy->last_name ?? ''))
                    ?: ($t->createdBy->email ?? "User #{$t->createdBy->id}"),
            ] : null,
        ];
    }
}