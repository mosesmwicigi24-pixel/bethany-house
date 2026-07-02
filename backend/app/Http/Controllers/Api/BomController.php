<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillOfMaterial;
use App\Models\BomItem;
use App\Models\Material;
use App\Models\Product;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BomController extends Controller
{
    // =========================================================================
    // GET /api/v1/admin/products/{productId}/bom
    // Returns all BOM versions for a product, with items and cost analysis.
    // =========================================================================

    public function index($productId)
    {
        $product = Product::findOrFail($productId);

        $boms = BillOfMaterial::with(['items.material', 'variant'])
            ->where('product_id', $productId)
            ->orderByDesc('version')
            ->get()
            ->map(fn($bom) => $this->formatBom($bom, true));

        return response()->json([
            'product' => [
                'id'          => $product->id,
                'sku'         => $product->sku,
                'name'        => $product->translations->firstWhere('language_code', 'en')?->name ?? $product->sku,
                'is_producible' => $product->is_producible,
            ],
            'data' => $boms,
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/products/{productId}/bom
    // Create a new BOM version (or first BOM) for a product.
    // =========================================================================

    public function store(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);

        $validated = $request->validate([
            'product_variant_id'      => 'nullable|exists:product_variants,id',
            'notes'                   => 'nullable|string|max:1000',
            'items'                   => 'required|array|min:1',
            'items.*.material_id'     => 'required|exists:materials,id',
            'items.*.quantity'        => 'required|numeric|min:0.001',
            'items.*.unit_of_measure' => 'required|string|max:20',
            'items.*.notes'           => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            // Deactivate previous active BOM for this product/variant combination
            BillOfMaterial::where('product_id', $productId)
                ->where('product_variant_id', $validated['product_variant_id'] ?? null)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            // Next version number
            $version = BillOfMaterial::where('product_id', $productId)
                ->where('product_variant_id', $validated['product_variant_id'] ?? null)
                ->max('version') + 1;

            $bom = BillOfMaterial::create([
                'product_id'         => $productId,
                'product_variant_id' => $validated['product_variant_id'] ?? null,
                'version'            => $version,
                'is_active'          => true,
                'notes'              => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                BomItem::create([
                    'bom_id'          => $bom->id,
                    'material_id'     => $item['material_id'],
                    'quantity'        => $item['quantity'],
                    'unit_of_measure' => $item['unit_of_measure'],
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            // Mark product as producible if not already
            if (!$product->is_producible) {
                $product->update(['is_producible' => true]);
            }

            DB::commit();

            $productName = $product->translations->firstWhere('language_code', 'en')?->name ?? $product->sku;
            ActivityLogService::log('bom_created', $bom, [
                'product_name' => $productName,
                'product_id'   => $productId,
                'version'      => $version,
                'items_count'  => count($validated['items']),
            ], "BOM v{$version} created for product {$productName}");

            return response()->json([
                'message' => 'Bill of Materials saved.',
                'bom'     => $this->formatBom($bom->fresh()->load(['items.material', 'variant'])),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to save BOM.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // GET /api/v1/admin/products/{productId}/bom/{bomId}
    // Single BOM with full detail.
    // =========================================================================

    public function show($productId, $bomId)
    {
        $bom = BillOfMaterial::with(['items.material.inventory', 'variant'])
            ->where('product_id', $productId)
            ->findOrFail($bomId);

        return response()->json(['bom' => $this->formatBom($bom, true)]);
    }

    // =========================================================================
    // PUT /api/v1/admin/products/{productId}/bom/{bomId}
    // Update a BOM - replaces all items (saves as new version).
    // =========================================================================

    public function update(Request $request, $productId, $bomId)
    {
        $bom = BillOfMaterial::where('product_id', $productId)->findOrFail($bomId);

        $validated = $request->validate([
            'notes'                   => 'nullable|string|max:1000',
            'items'                   => 'required|array|min:1',
            'items.*.material_id'     => 'required|exists:materials,id',
            'items.*.quantity'        => 'required|numeric|min:0.001',
            'items.*.unit_of_measure' => 'required|string|max:20',
            'items.*.notes'           => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $bom->update(['notes' => $validated['notes'] ?? $bom->notes]);

            // Replace all items
            $bom->items()->delete();

            foreach ($validated['items'] as $item) {
                BomItem::create([
                    'bom_id'          => $bom->id,
                    'material_id'     => $item['material_id'],
                    'quantity'        => $item['quantity'],
                    'unit_of_measure' => $item['unit_of_measure'],
                    'notes'           => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $product     = Product::find($productId);
            $productName = $product?->translations->firstWhere('language_code', 'en')?->name ?? $product?->sku ?? "Product #{$productId}";
            ActivityLogService::log('bom_updated', $bom, [
                'product_name' => $productName,
                'product_id'   => $productId,
                'version'      => $bom->version,
                'items_count'  => count($validated['items']),
            ], "BOM v{$bom->version} updated for product {$productName}");

            return response()->json([
                'message' => 'BOM updated.',
                'bom'     => $this->formatBom($bom->fresh()->load(['items.material', 'variant'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update BOM.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // DELETE /api/v1/admin/products/{productId}/bom/{bomId}
    // =========================================================================

    public function destroy($productId, $bomId)
    {
        $bom = BillOfMaterial::where('product_id', $productId)->findOrFail($bomId);
        $bom->items()->delete();
        $bom->delete();

        ActivityLogService::log('bom_deleted', null, [
            'bom_id'     => $bomId,
            'product_id' => $productId,
            'version'    => $bom->version,
        ], "BOM v{$bom->version} deleted for product #{$productId}");

        return response()->json(['message' => 'BOM deleted.']);
    }

    // =========================================================================
    // PUT /api/v1/admin/products/{productId}/bom/{bomId}/activate
    // Set this version as the active BOM for the product.
    // =========================================================================

    public function activate($productId, $bomId)
    {
        $bom = BillOfMaterial::where('product_id', $productId)->findOrFail($bomId);

        DB::transaction(function () use ($bom, $productId) {
            BillOfMaterial::where('product_id', $productId)
                ->where('product_variant_id', $bom->product_variant_id)
                ->update(['is_active' => false]);
            $bom->update(['is_active' => true]);
        });

        ActivityLogService::log('bom_activated', $bom, [
            'product_id' => $productId,
            'version'    => $bom->version,
        ], "BOM v{$bom->version} activated for product #{$productId}");

        return response()->json(['message' => 'BOM activated.']);
    }

    // =========================================================================
    // GET /api/v1/admin/products/{productId}/bom/{bomId}/feasibility
    // Check if we have enough stock to produce N units.
    // =========================================================================

    public function feasibility(Request $request, $productId, $bomId)
    {
        $quantity = max(1, (int) $request->get('quantity', 1));

        $bom = BillOfMaterial::with(['items.material'])
            ->where('product_id', $productId)
            ->findOrFail($bomId);

        $shortfalls = [];
        $available  = true;

        foreach ($bom->items as $item) {
            $required = $item->quantity * $quantity;

            // Get current stock from material_inventory
            $stock = DB::table('material_inventory')
                ->where('material_id', $item->material_id)
                ->sum('quantity_on_hand') ?? 0;

            if ($stock < $required) {
                $available = false;
                $shortfalls[] = [
                    'material_id'   => $item->material_id,
                    'material_name' => $item->material->name,
                    'uom'           => $item->unit_of_measure,
                    'required'      => $required,
                    'available'     => (float) $stock,
                    'shortfall'     => $required - $stock,
                ];
            }
        }

        return response()->json([
            'quantity'   => $quantity,
            'feasible'   => $available,
            'shortfalls' => $shortfalls,
            'summary'    => $available
                ? "All materials available for {$quantity} unit(s)."
                : count($shortfalls) . ' material(s) have insufficient stock.',
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function formatBom(BillOfMaterial $bom, bool $withStock = false): array
    {
        $items = $bom->items->map(function ($item) use ($withStock) {
            $data = [
                'id'              => $item->id,
                'material_id'     => $item->material_id,
                'quantity'        => (float) $item->quantity,
                'unit_of_measure' => $item->unit_of_measure,
                'notes'           => $item->notes,
                'material'        => $item->material ? [
                    'id'              => $item->material->id,
                    'code'            => $item->material->code,
                    'name'            => $item->material->name,
                    'material_type'   => $item->material->material_type,
                    'unit_of_measure' => $item->material->unit_of_measure,
                    'cost_per_unit'   => (float) ($item->material->cost_per_unit ?? 0),
                ] : null,
                'line_cost' => (float) $item->quantity * (float) ($item->material?->cost_per_unit ?? 0),
            ];

            if ($withStock && $item->material) {
                $data['stock_on_hand'] = (float) DB::table('material_inventory')
                    ->where('material_id', $item->material_id)
                    ->sum('quantity_on_hand');
            }

            return $data;
        });

        $totalCost = $items->sum('line_cost');

        return [
            'id'                 => $bom->id,
            'product_id'         => $bom->product_id,
            'product_variant_id' => $bom->product_variant_id,
            'variant'            => $bom->variant ? [
                'id'           => $bom->variant->id,
                'variant_name' => $bom->variant->variant_name,
                'sku'          => $bom->variant->sku,
            ] : null,
            'version'            => $bom->version,
            'is_active'          => (bool) $bom->is_active,
            'notes'              => $bom->notes,
            'items'              => $items->values(),
            'total_cost'         => $totalCost,
            'items_count'        => $items->count(),
            'created_at'         => $bom->created_at,
            'updated_at'         => $bom->updated_at,
        ];
    }
}