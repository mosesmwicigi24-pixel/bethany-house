<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Material;
use Illuminate\Http\Request;

class LowStockAlertsController extends Controller
{
    // =========================================================================
    // GET /api/v1/admin/inventory/low-stock-alerts
    // Unified low stock view - finished goods + raw materials.
    // =========================================================================

    public function index(Request $request)
    {
        $type = $request->get('type', 'all'); // all | products | materials

        $productAlerts  = [];
        $materialAlerts = [];

        // ── Finished goods low stock ──────────────────────────────────────────
        if (in_array($type, ['all', 'products'])) {
            $items = InventoryItem::with([
                'product:id,sku,low_stock_threshold,status',
                'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
                'product.images'       => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
                'variant:id,sku,variant_name',
                'outlet:id,name',
            ])
            ->whereHas('product', fn ($q) => $q->where('status', 'active'))
            ->where(function ($q) {
                // Out of stock OR at/below reorder point
                $q->where('quantity_on_hand', '<=', 0)
                  ->orWhereRaw('reorder_point > 0 AND quantity_on_hand <= reorder_point');
            })
            ->orderBy('quantity_on_hand')
            ->get();

            $productAlerts = $items->map(function ($item) {
                $available   = max(0, $item->quantity_on_hand - $item->quantity_reserved);
                $threshold   = $item->reorder_point ?? $item->product?->low_stock_threshold ?? 0;
                $isOut       = $available <= 0;

                return [
                    'type'            => 'product',
                    'id'              => $item->id,
                    'severity'        => $isOut ? 'out_of_stock' : 'low_stock',
                    'quantity'        => $item->quantity_on_hand,
                    'quantity_available' => $available,
                    'reorder_point'   => $threshold,
                    'product' => [
                        'id'        => $item->product?->id,
                        'sku'       => $item->product?->sku,
                        'name'      => $item->product?->translations?->first()?->name ?? $item->product?->sku,
                        'image_url' => $item->product?->images?->first()?->image_url,
                    ],
                    'variant' => $item->variant ? [
                        'id'           => $item->variant->id,
                        'sku'          => $item->variant->sku,
                        'variant_name' => $item->variant->variant_name,
                    ] : null,
                    'outlet' => $item->outlet ? [
                        'id'   => $item->outlet->id,
                        'name' => $item->outlet->name,
                    ] : null,
                ];
            })->values()->toArray();
        }

        // ── Raw materials low stock ───────────────────────────────────────────
        if (in_array($type, ['all', 'materials'])) {
            $materials = Material::with(['inventory.outlet:id,name'])
                ->withSum('inventory as total_stock', 'quantity_on_hand')
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereRaw('(SELECT COALESCE(SUM(quantity_on_hand),0) FROM material_inventory WHERE material_id=materials.id) = 0')
                      ->orWhere(function ($q2) {
                          $q2->where('reorder_point', '>', 0)
                             ->whereRaw('(SELECT COALESCE(SUM(quantity_on_hand),0) FROM material_inventory WHERE material_id=materials.id) <= materials.reorder_point');
                      });
                })
                ->orderByRaw('(SELECT COALESCE(SUM(quantity_on_hand),0) FROM material_inventory WHERE material_id=materials.id)')
                ->get();

            $materialAlerts = $materials->map(function ($m) {
                $totalStock = (float) ($m->total_stock ?? 0);
                return [
                    'type'         => 'material',
                    'id'           => $m->id,
                    'severity'     => $totalStock <= 0 ? 'out_of_stock' : 'low_stock',
                    'quantity'     => $totalStock,
                    'reorder_point'=> (float) ($m->reorder_point ?? 0),
                    'unit'         => $m->unit_of_measure,
                    'material' => [
                        'id'       => $m->id,
                        'code'     => $m->code,
                        'name'     => $m->name,
                        'category' => $m->category,
                    ],
                    'by_outlet' => $m->inventory->map(fn ($inv) => [
                        'outlet_name'     => $inv->outlet?->name ?? 'Unknown',
                        'quantity_on_hand' => (float) $inv->quantity_on_hand,
                    ])->values(),
                ];
            })->values()->toArray();
        }

        $all = array_merge($productAlerts, $materialAlerts);

        // Sort: out_of_stock first, then low_stock
        usort($all, fn ($a, $b) =>
            ($a['severity'] === 'out_of_stock' ? 0 : 1) - ($b['severity'] === 'out_of_stock' ? 0 : 1)
        );

        return response()->json([
            'data'    => $all,
            'summary' => [
                'products_out_of_stock'  => collect($productAlerts)->where('severity', 'out_of_stock')->count(),
                'products_low_stock'     => collect($productAlerts)->where('severity', 'low_stock')->count(),
                'materials_out_of_stock' => collect($materialAlerts)->where('severity', 'out_of_stock')->count(),
                'materials_low_stock'    => collect($materialAlerts)->where('severity', 'low_stock')->count(),
                'total'                  => count($all),
            ],
        ]);
    }

    // =========================================================================
    // PUT /api/v1/admin/inventory/low-stock-alerts/{id}/threshold
    // Update the reorder point (threshold) for a stock item.
    // =========================================================================

    public function updateThreshold(Request $request, $id)
    {
        $request->validate([
            'reorder_point'    => 'required|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
        ]);

        $item = InventoryItem::findOrFail($id);
        $item->update([
            'reorder_point'    => $request->reorder_point,
            'reorder_quantity' => $request->reorder_quantity ?? $item->reorder_quantity,
        ]);

        return response()->json(['message' => 'Alert threshold updated.']);
    }
}