<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\ProductVariant;
use App\Models\Outlet;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Get inventory list
     */
    public function index(Request $request)
    {
        $query = Inventory::with(['variant.product', 'outlet']);

        // Filter by outlet
        if ($request->has('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        // Filter by location type
        if ($request->has('location_type')) {
            $query->where('location_type', $request->location_type);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->whereHas('variant', function ($q) use ($request) {
                $q->where('product_id', $request->product_id);
            });
        }

        // Filter by variant
        if ($request->has('variant_id')) {
            $query->where('variant_id', $request->variant_id);
        }

        // Low stock filter
        if ($request->get('low_stock', false)) {
            $query->whereColumn('quantity', '<=', 'low_stock_threshold');
        }

        // Out of stock filter
        if ($request->get('out_of_stock', false)) {
            $query->where('quantity', '<=', 0);
        }

        // Search by product name or SKU
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('variant.product', function ($q) use ($search) {
                $q->where('name_en', 'LIKE', "%{$search}%")
                  ->orWhere('sku', 'LIKE', "%{$search}%");
            })->orWhereHas('variant', function ($q) use ($search) {
                $q->where('sku', 'LIKE', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 50);
        $inventory = $query->paginate($perPage);

        return response()->json($inventory);
    }

    /**
     * Get low stock items
     */
    public function lowStock(Request $request)
    {
        // Reads the live inventory_items ledger (audit INV-1). The old query hit
        // the stale, empty `inventories` table using columns that don't exist
        // there (`quantity`, `low_stock_threshold`) and errored. Low stock =
        // available (on_hand - reserved) at or below reorder_point, still > 0.
        $query = InventoryItem::with(['variant.product', 'outlet'])
            ->lowStock()
            ->where('quantity_on_hand', '>', 0);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        return response()->json($query->get());
    }

    /**
     * Adjust inventory
     */
    public function adjust(Request $request)
    {
        $validated = $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'outlet_id' => 'nullable|exists:outlets,id',
            'location_type' => 'required|in:warehouse,outlet',
            'quantity' => 'required|integer',
            'type' => 'required|in:addition,reduction,set',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
            'cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Find or create inventory record
            $inventory = Inventory::firstOrCreate([
                'variant_id' => $validated['variant_id'],
                'outlet_id' => $validated['outlet_id'] ?? null,
                'location_type' => $validated['location_type'],
            ], [
                'quantity' => 0,
                'low_stock_threshold' => 10,
            ]);

            $oldQuantity = $inventory->quantity;

            // Apply adjustment
            switch ($validated['type']) {
                case 'addition':
                    $inventory->increment('quantity', $validated['quantity']);
                    $actualChange = $validated['quantity'];
                    break;
                
                case 'reduction':
                    if ($inventory->quantity < $validated['quantity']) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Insufficient inventory to reduce',
                            'available' => $inventory->quantity,
                        ], 422);
                    }
                    $inventory->decrement('quantity', $validated['quantity']);
                    $actualChange = -$validated['quantity'];
                    break;
                
                case 'set':
                    $actualChange = $validated['quantity'] - $inventory->quantity;
                    $inventory->update(['quantity' => $validated['quantity']]);
                    break;
            }

            // Update cost if provided
            if (isset($validated['cost_per_unit'])) {
                $inventory->update(['cost_per_unit' => $validated['cost_per_unit']]);
            }

            // Log transaction
            DB::table('inventory_transactions')->insert([
                'inventory_id' => $inventory->id,
                'type' => 'adjustment',
                'quantity' => $actualChange,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $inventory->quantity,
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'performed_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            // Phase 3 - fire low-stock alert if new quantity is at or below threshold
            try {
                $threshold = $inventory->low_stock_threshold ?? $inventory->variant?->product?->low_stock_threshold ?? 0;
                if ($threshold > 0 && $inventory->quantity <= $threshold) {
                    $variant  = $inventory->variant?->load('product.translations') ?? ProductVariant::with('product.translations')->find($validated['variant_id']);
                    $productName = $variant?->product?->translations->firstWhere('language_code', 'en')?->name
                        ?? $variant?->product?->sku ?? 'Unknown product';
                    NotificationService::lowStockAlert(
                        $inventory->id,
                        $productName,
                        $inventory->quantity,
                        $threshold
                    );
                }
                ActivityLogService::log('inventory_adjusted', $inventory->variant, [
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $inventory->quantity,
                    'change'       => $actualChange,
                    'reason'       => $validated['reason'],
                    'outlet_id'    => $validated['outlet_id'] ?? null,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'   => 'Inventory adjusted successfully',
                'inventory' => $inventory->load(['variant.product', 'outlet']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to adjust inventory',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Transfer inventory between locations
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'from_outlet_id' => 'nullable|exists:outlets,id',
            'to_outlet_id' => 'nullable|exists:outlets,id',
            'from_location_type' => 'required|in:warehouse,outlet',
            'to_location_type' => 'required|in:warehouse,outlet',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        // Validate that from and to are different
        if ($validated['from_outlet_id'] === $validated['to_outlet_id'] && 
            $validated['from_location_type'] === $validated['to_location_type']) {
            return response()->json([
                'message' => 'Source and destination must be different',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Find source inventory
            $fromInventory = Inventory::where('variant_id', $validated['variant_id'])
                ->where('outlet_id', $validated['from_outlet_id'] ?? null)
                ->where('location_type', $validated['from_location_type'])
                ->first();

            if (!$fromInventory || $fromInventory->quantity < $validated['quantity']) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Insufficient inventory at source location',
                    'available' => $fromInventory ? $fromInventory->quantity : 0,
                ], 422);
            }

            // Find or create destination inventory
            $toInventory = Inventory::firstOrCreate([
                'variant_id' => $validated['variant_id'],
                'outlet_id' => $validated['to_outlet_id'] ?? null,
                'location_type' => $validated['to_location_type'],
            ], [
                'quantity' => 0,
                'low_stock_threshold' => 10,
                'cost_per_unit' => $fromInventory->cost_per_unit,
            ]);

            // Perform transfer
            $fromInventory->decrement('quantity', $validated['quantity']);
            $toInventory->increment('quantity', $validated['quantity']);

            // Log transactions
            $transferId = DB::table('inventory_transfers')->insertGetId([
                'variant_id' => $validated['variant_id'],
                'from_inventory_id' => $fromInventory->id,
                'to_inventory_id' => $toInventory->id,
                'quantity' => $validated['quantity'],
                'notes' => $validated['notes'] ?? null,
                'transferred_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Log source transaction
            DB::table('inventory_transactions')->insert([
                'inventory_id' => $fromInventory->id,
                'type' => 'transfer_out',
                'quantity' => -$validated['quantity'],
                'reference_type' => 'transfer',
                'reference_id' => $transferId,
                'notes' => $validated['notes'] ?? null,
                'performed_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Log destination transaction
            DB::table('inventory_transactions')->insert([
                'inventory_id' => $toInventory->id,
                'type' => 'transfer_in',
                'quantity' => $validated['quantity'],
                'reference_type' => 'transfer',
                'reference_id' => $transferId,
                'notes' => $validated['notes'] ?? null,
                'performed_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            try {
                ActivityLogService::log('inventory_transferred', null, [
                    'transfer_id'         => $transferId,
                    'variant_id'          => $validated['variant_id'],
                    'quantity'            => $validated['quantity'],
                    'from_outlet_id'      => $validated['from_outlet_id'] ?? null,
                    'from_location_type'  => $validated['from_location_type'],
                    'to_outlet_id'        => $validated['to_outlet_id'] ?? null,
                    'to_location_type'    => $validated['to_location_type'],
                    'notes'               => $validated['notes'] ?? null,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Inventory transferred successfully',
                'from_inventory' => $fromInventory->load(['variant.product', 'outlet']),
                'to_inventory' => $toInventory->load(['variant.product', 'outlet']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to transfer inventory',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get inventory movements for a product/variant
     */
    public function movements(Request $request, $variantId)
    {
        $query = DB::table('inventory_transactions')
            ->join('inventories', 'inventory_transactions.inventory_id', '=', 'inventories.id')
            ->leftJoin('users', 'inventory_transactions.performed_by', '=', 'users.id')
            ->where('inventories.variant_id', $variantId)
            ->select([
                'inventory_transactions.*',
                'inventories.outlet_id',
                'inventories.location_type',
                'users.name as performed_by_name'
            ]);

        // Filter by outlet
        if ($request->has('outlet_id')) {
            $query->where('inventories.outlet_id', $request->outlet_id);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('inventory_transactions.created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('inventory_transactions.created_at', '<=', $request->end_date);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('inventory_transactions.type', $request->type);
        }

        $movements = $query->orderBy('inventory_transactions.created_at', 'desc')
            ->paginate(50);

        return response()->json($movements);
    }

    /**
     * Get inventory valuation
     */
    public function valuation(Request $request)
    {
        $query = Inventory::with(['variant.product', 'outlet'])
            ->where('quantity', '>', 0);

        // Filter by outlet
        if ($request->has('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        // Filter by location type
        if ($request->has('location_type')) {
            $query->where('location_type', $request->location_type);
        }

        $inventory = $query->get();

        // Calculate valuation
        $totalValue = 0;
        $valuationByOutlet = [];

        foreach ($inventory as $item) {
            $value = $item->quantity * ($item->cost_per_unit ?? 0);
            $totalValue += $value;

            $outletKey = $item->outlet_id ?? 'warehouse';
            if (!isset($valuationByOutlet[$outletKey])) {
                $valuationByOutlet[$outletKey] = [
                    'outlet' => $item->outlet ? $item->outlet->name : 'Warehouse',
                    'total_value' => 0,
                    'items_count' => 0,
                ];
            }

            $valuationByOutlet[$outletKey]['total_value'] += $value;
            $valuationByOutlet[$outletKey]['items_count']++;
        }

        return response()->json([
            'total_value' => $totalValue,
            'total_items' => $inventory->count(),
            'total_quantity' => $inventory->sum('quantity'),
            'by_outlet' => array_values($valuationByOutlet),
            'currency' => 'KES', // TODO: Make configurable
        ]);
    }

    /**
     * Set low stock threshold
     */
    public function setThreshold(Request $request)
    {
        $validated = $request->validate([
            'variant_id' => 'required|exists:product_variants,id',
            'outlet_id' => 'nullable|exists:outlets,id',
            'location_type' => 'required|in:warehouse,outlet',
            'threshold' => 'required|integer|min:0',
        ]);

        $inventory = Inventory::where('variant_id', $validated['variant_id'])
            ->where('outlet_id', $validated['outlet_id'] ?? null)
            ->where('location_type', $validated['location_type'])
            ->firstOrFail();

        $inventory->update(['low_stock_threshold' => $validated['threshold']]);

        try {
            ActivityLogService::log('stock_threshold_updated', null, [
                'variant_id'     => $validated['variant_id'],
                'outlet_id'      => $validated['outlet_id'] ?? null,
                'location_type'  => $validated['location_type'],
                'new_threshold'  => $validated['threshold'],
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Low stock threshold updated successfully',
            'inventory' => $inventory,
        ]);
    }

    /**
     * Bulk update thresholds
     */
    public function bulkSetThreshold(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.inventory_id' => 'required|exists:inventories,id',
            'items.*.threshold' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $item) {
                Inventory::where('id', $item['inventory_id'])
                    ->update(['low_stock_threshold' => $item['threshold']]);
            }

            DB::commit();

            try {
                ActivityLogService::log('stock_threshold_bulk_updated', null, [
                    'items_count' => count($validated['items']),
                    'inventory_ids' => array_column($validated['items'], 'inventory_id'),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Thresholds updated successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update thresholds',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}