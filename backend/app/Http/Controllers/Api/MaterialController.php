<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    /**
     * Get all materials
     */
    public function index(Request $request)
    {
        $query = DB::table('materials')
            ->leftJoin('material_inventory', 'materials.id', '=', 'material_inventory.material_id')
            ->select(
                'materials.*',
                DB::raw('COALESCE(material_inventory.quantity, 0) as stock_quantity'),
                DB::raw('COALESCE(material_inventory.cost_per_unit, materials.default_cost) as current_cost')
            );

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('materials.name', 'LIKE', "%{$search}%")
                  ->orWhere('materials.sku', 'LIKE', "%{$search}%")
                  ->orWhere('materials.description', 'LIKE', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('materials.category', $request->category);
        }

        // Filter by supplier
        if ($request->has('supplier_id')) {
            $query->where('materials.supplier_id', $request->supplier_id);
        }

        // Low stock filter
        if ($request->get('low_stock', false)) {
            $query->whereColumn('material_inventory.quantity', '<=', 'materials.low_stock_threshold');
        }

        // Out of stock filter
        if ($request->get('out_of_stock', false)) {
            $query->where(function ($q) {
                $q->whereNull('material_inventory.quantity')
                  ->orWhere('material_inventory.quantity', '<=', 0);
            });
        }

        $sortBy = $request->get('sort_by', 'materials.name');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 50);
        $materials = $query->paginate($perPage);

        return response()->json($materials);
    }

    /**
     * Get single material
     */
    public function show($id)
    {
        $material = DB::table('materials')
            ->leftJoin('material_inventory', 'materials.id', '=', 'material_inventory.material_id')
            ->leftJoin('suppliers', 'materials.supplier_id', '=', 'suppliers.id')
            ->where('materials.id', $id)
            ->select(
                'materials.*',
                DB::raw('COALESCE(material_inventory.quantity, 0) as stock_quantity'),
                DB::raw('COALESCE(material_inventory.cost_per_unit, materials.default_cost) as current_cost'),
                'suppliers.name as supplier_name'
            )
            ->first();

        if (!$material) {
            return response()->json(['message' => 'Material not found'], 404);
        }

        // Get usage history
        $usageHistory = DB::table('production_material_usage')
            ->join('production_orders', 'production_material_usage.production_order_id', '=', 'production_orders.id')
            ->join('products', 'production_orders.product_id', '=', 'products.id')
            ->where('production_material_usage.material_id', $id)
            ->select(
                'production_material_usage.*',
                'production_orders.production_number',
                'products.name_en as product_name'
            )
            ->latest('production_material_usage.created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'material' => $material,
            'usage_history' => $usageHistory,
        ]);
    }

    /**
     * Create material
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sku' => 'required|string|unique:materials,sku|max:100',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|in:fabric,thread,button,zipper,other',
            'unit' => 'required|string|max:50',
            'default_cost' => 'required|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'low_stock_threshold' => 'required|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'specifications' => 'nullable|json',
            'color' => 'nullable|string|max:50',
            'size' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $materialId = DB::table('materials')->insertGetId(array_merge($validated, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            // Initialize inventory
            DB::table('material_inventory')->insert([
                'material_id' => $materialId,
                'quantity' => 0,
                'cost_per_unit' => $validated['default_cost'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            $material = DB::table('materials')->find($materialId);

            try {
                ActivityLogService::log('material_created', null, [
                    'material_id' => $materialId,
                    'sku'         => $validated['sku'],
                    'name'        => $validated['name'],
                    'category'    => $validated['category'],
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Material created successfully',
                'material' => $material,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create material',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update material
     */
    public function update(Request $request, $id)
    {
        $material = DB::table('materials')->find($id);

        if (!$material) {
            return response()->json(['message' => 'Material not found'], 404);
        }

        $validated = $request->validate([
            'sku' => ['sometimes', 'string', 'max:100', Rule::unique('materials')->ignore($id)],
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category' => 'sometimes|in:fabric,thread,button,zipper,other',
            'unit' => 'sometimes|string|max:50',
            'default_cost' => 'sometimes|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'low_stock_threshold' => 'sometimes|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'specifications' => 'nullable|json',
            'color' => 'nullable|string|max:50',
            'size' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        DB::table('materials')
            ->where('id', $id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        $updated = DB::table('materials')->find($id);

        try {
            ActivityLogService::log('material_updated', null, [
                'material_id' => $id,
                'sku'         => $material->sku,
                'changes'     => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Material updated successfully',
            'material' => $updated,
        ]);
    }

    /**
     * Delete material
     */
    public function destroy($id)
    {
        // Check if material is used in any BOM
        $usedInBOM = DB::table('bill_of_materials')
            ->where('material_id', $id)
            ->exists();

        if ($usedInBOM) {
            return response()->json([
                'message' => 'Cannot delete material that is used in Bill of Materials',
            ], 422);
        }

        // Check if material has been used in production
        $usedInProduction = DB::table('production_material_usage')
            ->where('material_id', $id)
            ->exists();

        if ($usedInProduction) {
            return response()->json([
                'message' => 'Cannot delete material with production history',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $materialRecord = DB::table('materials')->find($id);
            DB::table('material_inventory')->where('material_id', $id)->delete();
            DB::table('materials')->where('id', $id)->delete();

            DB::commit();

            try {
                ActivityLogService::log('material_deleted', null, [
                    'material_id' => $id,
                    'sku'         => $materialRecord->sku ?? null,
                    'name'        => $materialRecord->name ?? null,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Material deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete material',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Adjust material inventory
     */
    public function adjust(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer',
            'type' => 'required|in:addition,reduction,set',
            'reason' => 'required|string',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $inventory = DB::table('material_inventory')
                ->where('material_id', $id)
                ->first();

            if (!$inventory) {
                DB::rollBack();
                return response()->json(['message' => 'Material inventory not found'], 404);
            }

            $oldQty = $inventory->quantity;

            // Calculate new quantity
            $newQty = match($validated['type']) {
                'addition' => $oldQty + $validated['quantity'],
                'reduction' => $oldQty - $validated['quantity'],
                'set' => $validated['quantity'],
            };

            if ($newQty < 0) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Cannot reduce inventory below zero',
                    'current_quantity' => $oldQty,
                ], 422);
            }

            // Update inventory
            $updateData = ['quantity' => $newQty, 'updated_at' => now()];
            
            if (isset($validated['cost_per_unit'])) {
                $updateData['cost_per_unit'] = $validated['cost_per_unit'];
            }

            DB::table('material_inventory')
                ->where('material_id', $id)
                ->update($updateData);

            // Log transaction
            DB::table('material_transactions')->insert([
                'material_id' => $id,
                'type' => 'adjustment',
                'quantity' => $newQty - $oldQty,
                'old_quantity' => $oldQty,
                'new_quantity' => $newQty,
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'performed_by' => $request->user()->id,
                'created_at' => now(),
            ]);

            DB::commit();

            $updated = DB::table('material_inventory')
                ->where('material_id', $id)
                ->first();

            try {
                ActivityLogService::log('material_adjusted', null, [
                    'material_id'   => $id,
                    'adjustment_type' => $validated['type'],
                    'quantity'      => $validated['quantity'],
                    'old_quantity'  => $oldQty,
                    'new_quantity'  => $newQty,
                    'reason'        => $validated['reason'],
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Material inventory adjusted successfully',
                'inventory' => $updated,
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
     * Get material movements/transactions
     */
    public function movements(Request $request, $id)
    {
        $query = DB::table('material_transactions')
            ->leftJoin('users', 'material_transactions.performed_by', '=', 'users.id')
            ->where('material_transactions.material_id', $id)
            ->select(
                'material_transactions.*',
                'users.name as performed_by_name'
            );

        // Filter by type
        if ($request->has('type')) {
            $query->where('material_transactions.type', $request->type);
        }

        // Date range
        if ($request->has('start_date')) {
            $query->whereDate('material_transactions.created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('material_transactions.created_at', '<=', $request->end_date);
        }

        $movements = $query->orderBy('material_transactions.created_at', 'desc')
            ->paginate(50);

        return response()->json($movements);
    }

    /**
     * Get low stock materials
     */
    public function lowStock()
    {
        $materials = DB::table('materials')
            ->join('material_inventory', 'materials.id', '=', 'material_inventory.material_id')
            ->whereColumn('material_inventory.quantity', '<=', 'materials.low_stock_threshold')
            ->select(
                'materials.*',
                'material_inventory.quantity as stock_quantity'
            )
            ->orderBy('material_inventory.quantity')
            ->get();

        return response()->json($materials);
    }

    /**
     * Get material valuation
     */
    public function valuation()
    {
        $valuation = DB::table('materials')
            ->join('material_inventory', 'materials.id', '=', 'material_inventory.material_id')
            ->where('material_inventory.quantity', '>', 0)
            ->selectRaw('
                materials.category,
                SUM(material_inventory.quantity) as total_quantity,
                SUM(material_inventory.quantity * material_inventory.cost_per_unit) as total_value,
                COUNT(materials.id) as material_count
            ')
            ->groupBy('materials.category')
            ->get();

        $totalValue = $valuation->sum('total_value');

        return response()->json([
            'total_value' => $totalValue,
            'by_category' => $valuation,
        ]);
    }

    /**
     * Generate material reorder report
     */
    public function reorderReport()
    {
        $materials = DB::table('materials')
            ->leftJoin('material_inventory', 'materials.id', '=', 'material_inventory.material_id')
            ->leftJoin('suppliers', 'materials.supplier_id', '=', 'suppliers.id')
            ->whereColumn('material_inventory.quantity', '<=', 'materials.low_stock_threshold')
            ->select(
                'materials.*',
                'material_inventory.quantity as current_stock',
                'suppliers.name as supplier_name',
                'suppliers.email as supplier_email',
                'suppliers.phone as supplier_phone'
            )
            ->get();

        return response()->json([
            'materials_to_reorder' => $materials,
            'total_items' => $materials->count(),
        ]);
    }

    /**
     * Get material usage statistics
     */
    public function usageStatistics(Request $request, $id)
    {
        $startDate = $request->get('start_date', now()->subMonths(3));
        $endDate = $request->get('end_date', now());

        $usage = DB::table('production_material_usage')
            ->join('production_orders', 'production_material_usage.production_order_id', '=', 'production_orders.id')
            ->where('production_material_usage.material_id', $id)
            ->whereBetween('production_material_usage.created_at', [$startDate, $endDate])
            ->selectRaw('
                SUM(production_material_usage.quantity) as total_used,
                COUNT(DISTINCT production_orders.id) as production_count,
                AVG(production_material_usage.quantity) as avg_per_production
            ')
            ->first();

        $monthlyUsage = DB::table('production_material_usage')
            ->where('material_id', $id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m") as month,
                SUM(quantity) as total_quantity
            ')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total_usage' => $usage,
            'monthly_usage' => $monthlyUsage,
        ]);
    }

    /**
     * Bulk update material costs
     */
    public function bulkUpdateCosts(Request $request)
    {
        $validated = $request->validate([
            'updates' => 'required|array',
            'updates.*.material_id' => 'required|exists:materials,id',
            'updates.*.cost_per_unit' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['updates'] as $update) {
                DB::table('material_inventory')
                    ->where('material_id', $update['material_id'])
                    ->update([
                        'cost_per_unit' => $update['cost_per_unit'],
                        'updated_at' => now(),
                    ]);

                DB::table('materials')
                    ->where('id', $update['material_id'])
                    ->update([
                        'default_cost' => $update['cost_per_unit'],
                        'updated_at' => now(),
                    ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Material costs updated successfully',
                'updated_count' => count($validated['updates']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update costs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}