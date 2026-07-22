<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{InventoryItem, Order, ProductionOrder, ProductionTask};
use App\Services\IntelligenceService;
use Illuminate\Http\Request;

/**
 * IntelligenceController
 *
 * Exposes all IntelligenceService features as REST endpoints.
 *
 * Routes (all under /api/v1/admin/intelligence):
 *   GET  /reorder-suggestions          — items needing a draft PO
 *   GET  /tailor-workload              — workload snapshot per tailor
 *   GET  /churn-risk                   — customers at churn risk
 *   GET  /material-shortages           — aggregate BOM demand vs stock
 *   POST /material-shortages/preflight — check before creating a new prod order
 *   GET  /budget-warnings              — expense categories >80% utilization
 *   GET  /smart-tasks                  — tailor's tasks sorted by risk score
 *   POST /entity-previews              — rich data for message entity chips
 *   POST /auto-reorder/{itemId}        — manually trigger auto-reorder draft
 */
class IntelligenceController extends Controller
{
    // ── GET /intelligence/reorder-suggestions ────────────────────────────────
    // Items currently at or below their reorder_point that have no open draft PO.

    public function reorderSuggestions()
    {
        $items = InventoryItem::with([
            'product:id,sku',
            'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'product.images'       => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'variant:id,sku,variant_name',
            'outlet:id,name',
        ])
        ->whereHas('product', fn ($q) => $q->where('status', 'active'))
        ->where(function ($q) {
            $q->where('quantity_on_hand', '<=', 0)
              ->orWhereRaw('reorder_point > 0 AND quantity_on_hand <= reorder_point');
        })
        ->orderBy('quantity_on_hand')
        ->get();

        // Filter to only items with no existing open draft PO
        $suggestions = $items->filter(function ($item) {
            return !\App\Models\PurchaseOrder::where('status', 'draft')
                ->whereHas('items', fn ($q) =>
                    $q->where('product_id', $item->product_id)->where('item_type', 'product')
                )->exists();
        })->map(function ($item) {
            $available = max(0, $item->quantity_on_hand - $item->quantity_reserved);
            return [
                'inventory_item_id' => $item->id,
                'product_id'        => $item->product_id,
                'product_name'      => $item->product?->translations?->first()?->name ?? $item->product?->sku,
                'product_image'     => $item->product?->images?->first()?->image_url,
                'sku'               => $item->product?->sku,
                'variant'           => $item->variant ? [
                    'id'   => $item->variant->id,
                    'name' => $item->variant->variant_name,
                    'sku'  => $item->variant->sku,
                ] : null,
                'outlet'            => $item->outlet ? ['id' => $item->outlet->id, 'name' => $item->outlet->name] : null,
                'quantity_on_hand'  => (int) $item->quantity_on_hand,
                'quantity_available'=> (int) $available,
                'reorder_point'     => (int) ($item->reorder_point ?? 0),
                'reorder_quantity'  => (int) ($item->reorder_quantity ?? 0),
                'severity'          => $available <= 0 ? 'out_of_stock' : 'low_stock',
            ];
        })->values();

        return response()->json([
            'suggestions' => $suggestions,
            'total'       => $suggestions->count(),
        ]);
    }

    // ── POST /intelligence/auto-reorder/{itemId} ─────────────────────────────
    // Manually trigger a draft PO for a specific inventory item.

    public function triggerAutoReorder(Request $request, int $itemId)
    {
        $item = InventoryItem::with(['product.translations', 'outlet'])->findOrFail($itemId);
        $po   = IntelligenceService::autoReorderSuggestion($item, $request->user()->id);

        if (!$po) {
            return response()->json([
                'message' => 'Could not create draft PO — a draft already exists for this product, or no active supplier found.',
            ], 422);
        }

        return response()->json([
            'message'       => 'Draft purchase order created.',
            'purchase_order' => $po->load(['supplier:id,name', 'items']),
        ], 201);
    }

    // ── GET /intelligence/tailor-workload ─────────────────────────────────────

    public function tailorWorkload()
    {
        return response()->json([
            'tailors' => IntelligenceService::tailorWorkloadSnapshot(),
        ]);
    }

    // ── GET /intelligence/churn-risk ──────────────────────────────────────────

    public function churnRisk(Request $request)
    {
        $limit = min((int) $request->get('limit', 50), 200);
        return response()->json([
            'customers' => IntelligenceService::churnRiskCustomers($limit),
        ]);
    }

    // ── GET /intelligence/customer-geography ──────────────────────────────────
    // "Which country has more customers?" — a country league table (customers,
    // orders, revenue) resolved from order geography. No new instrumentation.
    public function customerGeography()
    {
        return response()->json(IntelligenceService::customerGeography());
    }

    // ── GET /intelligence/material-shortages ──────────────────────────────────
    // Aggregate shortages across all active production orders.

    public function materialShortages()
    {
        return response()->json([
            'shortages' => IntelligenceService::materialShortagePreFlight(),
        ]);
    }

    // ── POST /intelligence/material-shortages/preflight ───────────────────────
    // Check shortages including a proposed new production order.

    public function materialShortagesPreflight(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
        ]);

        return response()->json([
            'shortages' => IntelligenceService::materialShortagePreFlight(
                $validated['product_id'],
                $validated['quantity']
            ),
        ]);
    }

    // ── GET /intelligence/budget-warnings ─────────────────────────────────────

    public function budgetWarnings()
    {
        return response()->json([
            'warnings' => IntelligenceService::expenseBudgetWarnings(),
        ]);
    }

    // ── GET /intelligence/smart-tasks ─────────────────────────────────────────
    // The authenticated tailor's tasks sorted by deadline-miss risk score.

    public function smartTasks(Request $request)
    {
        $tasks = \App\Models\ProductionTask::with([
            'productionOrder:id,order_number,priority,due_date,status,quantity,product_id,specifications,measurements,customer_preferences,notes',
            'productionOrder.product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'productionOrder.product.images'       => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'stage:id,name,slug,description',
        ])
        ->where('assigned_to', $request->user()->id)
        ->whereIn('status', ['pending', 'in_progress', 'paused'])
        ->get()
        ->toArray();

        $sorted = IntelligenceService::smartTaskSort($tasks);

        return response()->json($sorted);
    }

    // ── POST /intelligence/entity-previews ────────────────────────────────────
    // Returns rich preview data for entity chips in channel messages.

    public function entityPreviews(Request $request)
    {
        $validated = $request->validate([
            'entities'        => 'required|array|min:1|max:20',
            'entities.*.type' => 'required|in:order,production_order',
            'entities.*.id'   => 'required|integer',
        ]);

        return response()->json([
            'previews' => IntelligenceService::entityChipPreviews($validated['entities']),
        ]);
    }
}