<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    /**
     * Low-stock items, read from the live inventory_items ledger.
     *
     * The other endpoints that used to live on this controller (index, adjust,
     * valuation, movements, transfer, thresholds) were dead: they ran against the
     * stale, empty `inventories` table using columns that don't exist there, and
     * nothing in the app called them. They are superseded by dedicated controllers
     * that operate on inventory_items:
     *   - StockLevelsController      (/inventory/stock-levels)   — listing/valuation
     *   - StockAdjustmentsController (/inventory/adjustments)    — stock adjustments
     *   - StockTransfersController   (/inventory/transfers)      — transfers
     *   - LowStockAlertsController   (/inventory/low-stock-alerts) — thresholds/alerts
     * Removing them (audit INV-1/INV-2) collapses the split-brain surface.
     */
    public function lowStock(Request $request)
    {
        // Low stock = available (on_hand - reserved) at or below reorder_point,
        // still in stock.
        $query = InventoryItem::with(['variant.product', 'outlet'])
            ->lowStock()
            ->where('quantity_on_hand', '>', 0);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        return response()->json($query->get());
    }
}
