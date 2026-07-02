<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialInventory;
use App\Models\MaterialTransaction;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RawMaterialsController extends Controller
{
    // Actual DB columns:
    // id, code, name, description, unit_of_measure, category,
    // unit_cost, reorder_point, is_active, created_at, updated_at

    const TX_TYPES = [
        'opening_stock'     => 'Opening Stock',
        'purchase'          => 'Purchase Receipt',
        'adjustment'        => 'Manual Adjustment',
        'production_use'    => 'Used in Production',
        'production_return' => 'Returned from Production',
        'damaged'           => 'Damaged / Scrapped',
        'correction'        => 'Stock Count Correction',
        'transfer_in'       => 'Transfer In',
        'transfer_out'      => 'Transfer Out',
    ];

    // =========================================================================
    // GET /api/v1/admin/materials
    // =========================================================================

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 30), 100);

        $query = Material::with(['inventory.outlet:id,name'])
            ->withSum('inventory as total_stock', 'quantity_on_hand');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name',     'ILIKE', "%{$search}%")
                  ->orWhere('code',     'ILIKE', "%{$search}%")
                  ->orWhere('category', 'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->boolean('low_stock')) {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(quantity_on_hand), 0) FROM material_inventory WHERE material_id = materials.id) <= materials.reorder_point'
                . ' AND materials.reorder_point > 0'
            );
        }

        $query->orderBy($request->get('sort_by', 'name'), $request->get('sort_dir', 'asc'));

        $materials = $query->paginate($perPage);

        $stats = [
            'total'       => Material::count(),
            'active'      => Material::where('is_active', true)->count(),
            'low_stock'   => Material::where('is_active', true)
                ->where('reorder_point', '>', 0)
                ->whereRaw('(SELECT COALESCE(SUM(quantity_on_hand),0) FROM material_inventory WHERE material_id=materials.id) <= materials.reorder_point')
                ->count(),
            'out_of_stock'=> Material::whereRaw('(SELECT COALESCE(SUM(quantity_on_hand),0) FROM material_inventory WHERE material_id=materials.id) = 0')
                ->count(),
            'categories'  => Material::select('category')->distinct()->orderBy('category')->pluck('category')->filter()->values(),
        ];

        return response()->json([
            'data'  => collect($materials->items())->map(fn ($m) => $this->formatMaterial($m)),
            'meta'  => [
                'current_page' => $materials->currentPage(),
                'last_page'    => $materials->lastPage(),
                'total'        => $materials->total(),
                'from'         => $materials->firstItem(),
                'to'           => $materials->lastItem(),
            ],
            'stats' => $stats,
        ]);
    }

    // =========================================================================
    // GET /api/v1/admin/materials/{id}
    // =========================================================================

    public function show($id)
    {
        $material = Material::with(['inventory.outlet:id,name,code'])
            ->withSum('inventory as total_stock', 'quantity_on_hand')
            ->findOrFail($id);

        $transactions = MaterialTransaction::whereHas('materialInventory', fn ($q) =>
            $q->where('material_id', $id)
        )
        ->with(['materialInventory.outlet:id,name', 'createdBy:id,first_name,last_name,email'])
        ->orderByDesc('created_at')
        ->limit(30)
        ->get();

        $bomUsage = DB::table('bom_items')
            ->join('bills_of_materials', 'bom_items.bom_id', '=', 'bills_of_materials.id')
            ->join('products', 'bills_of_materials.product_id', '=', 'products.id')
            ->leftJoin('product_translations', function ($j) {
                $j->on('product_translations.product_id', '=', 'products.id')
                  ->where('product_translations.language_code', '=', 'en');
            })
            ->where('bom_items.material_id', $id)
            ->where('bills_of_materials.is_active', true)
            ->select('products.id as product_id', 'products.sku', 'product_translations.name as product_name', 'bom_items.quantity', 'bom_items.unit_of_measure')
            ->get();

        return response()->json([
            'material'     => $this->formatMaterial($material, true),
            'transactions' => $transactions->map(fn ($t) => $this->formatTransaction($t)),
            'bom_usage'    => $bomUsage,
        ]);
    }

    // =========================================================================
    // POST /api/v1/admin/materials
    // =========================================================================

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'            => 'required|string|max:50|unique:materials,code',
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'category'        => 'nullable|string|max:100',
            'unit_of_measure' => 'required|string|max:20',
            'unit_cost'       => 'required|numeric|min:0',
            'reorder_point'   => 'nullable|numeric|min:0',
            'is_active'       => 'boolean',
        ]);

        $material = Material::create($validated);

        try {
            ActivityLogService::log('raw_material_created', null, [
                'material_id' => $material->id,
                'code'        => $material->code,
                'name'        => $material->name,
                'category'    => $material->category,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Material created.',
            'material' => $this->formatMaterial($material->fresh()->loadSum('inventory as total_stock', 'quantity_on_hand')),
        ], 201);
    }

    // =========================================================================
    // PUT /api/v1/admin/materials/{id}
    // =========================================================================

    public function update(Request $request, $id)
    {
        $material = Material::findOrFail($id);

        $validated = $request->validate([
            'code'            => ['sometimes', 'string', 'max:50', Rule::unique('materials')->ignore($material->id)],
            'name'            => 'sometimes|string|max:255',
            'description'     => 'nullable|string',
            'category'        => 'nullable|string|max:100',
            'unit_of_measure' => 'sometimes|string|max:20',
            'unit_cost'       => 'sometimes|numeric|min:0',
            'reorder_point'   => 'nullable|numeric|min:0',
            'is_active'       => 'sometimes|boolean',
        ]);

        $material->update($validated);

        try {
            ActivityLogService::log('raw_material_updated', null, [
                'material_id' => $material->id,
                'code'        => $material->code,
                'changes'     => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Material updated.',
            'material' => $this->formatMaterial(
                $material->fresh()->load(['inventory.outlet'])
                    ->loadSum('inventory as total_stock', 'quantity_on_hand')
            ),
        ]);
    }

    // =========================================================================
    // DELETE /api/v1/admin/materials/{id}
    // =========================================================================

    public function destroy($id)
    {
        $material = Material::withCount(['bomItems', 'allocations'])->findOrFail($id);

        if ($material->bom_items_count > 0) {
            return response()->json([
                'message' => "Cannot delete - used in {$material->bom_items_count} Bill(s) of Materials. Deactivate it instead.",
            ], 422);
        }
        if ($material->allocations_count > 0) {
            return response()->json([
                'message' => "Cannot delete - has {$material->allocations_count} production allocation(s). Deactivate it instead.",
            ], 422);
        }

        MaterialInventory::where('material_id', $id)->each(function ($inv) {
            MaterialTransaction::where('material_inventory_id', $inv->id)->delete();
        });
        MaterialInventory::where('material_id', $id)->delete();
        $material->delete();

        try {
            ActivityLogService::log('raw_material_deleted', null, [
                'material_id' => $id,
                'code'        => $material->code,
                'name'        => $material->name,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Material deleted.']);
    }

    // =========================================================================
    // POST /api/v1/admin/materials/{id}/receive
    // =========================================================================

    public function receive(Request $request, $id)
    {
        $material = Material::findOrFail($id);

        $validated = $request->validate([
            'outlet_id'        => 'required|exists:outlets,id',
            'quantity'         => 'required|numeric|min:0.001',
            'transaction_type' => 'required|in:opening_stock,purchase,adjustment,transfer_in',
            'unit_cost'        => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string|max:500',
            'reference'        => 'nullable|string|max:100',
        ]);

        DB::beginTransaction();
        try {
            $inv = MaterialInventory::firstOrCreate(
                ['material_id' => $id, 'outlet_id' => $validated['outlet_id']],
                ['quantity_on_hand' => 0]
            );

            $before = (float) $inv->quantity_on_hand;
            $inv->quantity_on_hand = $before + $validated['quantity'];
            $inv->last_counted_at  = now();
            $inv->save();

            MaterialTransaction::create([
                'material_inventory_id' => $inv->id,
                'transaction_type'      => $validated['transaction_type'],
                'reference_type'        => $validated['transaction_type'],
                'quantity_change'       => $validated['quantity'],
                'quantity_before'       => $before,
                'quantity_after'        => $inv->quantity_on_hand,
                'unit_cost'             => $validated['unit_cost'] ?? $material->unit_cost,
                'notes'                 => $validated['notes'] ?? null,
                'created_by'            => auth()->id(),
            ]);

            if (!empty($validated['unit_cost'])) {
                $material->update(['unit_cost' => $validated['unit_cost']]);
            }

            DB::commit();

            try {
                ActivityLogService::log('raw_material_received', null, [
                    'material_id'      => $id,
                    'material_code'    => $material->code,
                    'material_name'    => $material->name,
                    'outlet_id'        => $validated['outlet_id'],
                    'quantity'         => $validated['quantity'],
                    'transaction_type' => $validated['transaction_type'],
                    'unit_cost'        => $validated['unit_cost'] ?? $material->unit_cost,
                    'reference'        => $validated['reference'] ?? null,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'  => "Received {$validated['quantity']} {$material->unit_of_measure} of {$material->name}.",
                'inventory' => $this->formatInventoryRecord($inv->fresh()->load('outlet')),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to receive stock.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // POST /api/v1/admin/materials/{id}/adjust
    // =========================================================================

    public function adjust(Request $request, $id)
    {
        $material = Material::findOrFail($id);

        $validated = $request->validate([
            'outlet_id'        => 'required|exists:outlets,id',
            'quantity_change'  => 'required|numeric|not_in:0',
            'transaction_type' => 'required|in:adjustment,damaged,correction,transfer_out',
            'notes'            => 'required|string|max:500',
        ]);

        $inv = MaterialInventory::where('material_id', $id)
            ->where('outlet_id', $validated['outlet_id'])
            ->first();

        if (!$inv) {
            return response()->json([
                'message' => "No stock record found for {$material->name} at this outlet. Receive stock first before adjusting it.",
            ], 422);
        }

        $newQty = (float) $inv->quantity_on_hand + $validated['quantity_change'];
        if ($newQty < 0) {
            return response()->json([
                'message' => "Insufficient stock. Available: {$inv->quantity_on_hand} {$material->unit_of_measure}.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $before = (float) $inv->quantity_on_hand;
            $inv->quantity_on_hand = $newQty;
            $inv->save();

            MaterialTransaction::create([
                'material_inventory_id' => $inv->id,
                'transaction_type'      => $validated['transaction_type'],
                'reference_type'        => 'manual_adjustment',
                'quantity_change'       => $validated['quantity_change'],
                'quantity_before'       => $before,
                'quantity_after'        => $newQty,
                'notes'                 => $validated['notes'],
                'created_by'            => auth()->id(),
            ]);

            DB::commit();

            try {
                ActivityLogService::log('raw_material_adjusted', null, [
                    'material_id'      => $id,
                    'material_code'    => $material->code,
                    'outlet_id'        => $validated['outlet_id'],
                    'quantity_change'  => $validated['quantity_change'],
                    'quantity_before'  => $before,
                    'quantity_after'   => $newQty,
                    'transaction_type' => $validated['transaction_type'],
                    'notes'            => $validated['notes'],
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'   => 'Stock adjusted.',
                'inventory' => $this->formatInventoryRecord($inv->fresh()->load('outlet')),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Adjustment failed.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // GET /api/v1/admin/materials/{id}/transactions
    // =========================================================================

    public function transactions(Request $request, $id)
    {
        Material::findOrFail($id);
        $perPage = min((int) $request->get('per_page', 50), 200);

        $query = MaterialTransaction::whereHas('materialInventory', fn ($q) =>
            $q->where('material_id', $id)
        )
        ->with(['materialInventory.outlet:id,name', 'createdBy:id,first_name,last_name,email']);

        if ($request->filled('type')) $query->where('transaction_type', $request->type);
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to'))   $query->whereDate('created_at', '<=', $request->to);

        $txns = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => collect($txns->items())->map(fn ($t) => $this->formatTransaction($t)),
            'meta' => [
                'current_page' => $txns->currentPage(),
                'last_page'    => $txns->lastPage(),
                'total'        => $txns->total(),
            ],
        ]);
    }

    // =========================================================================
    // GET /api/v1/admin/materials/low-stock
    // =========================================================================

    public function lowStock()
    {
        $materials = Material::with(['inventory.outlet:id,name'])
            ->withSum('inventory as total_stock', 'quantity_on_hand')
            ->where('is_active', true)
            ->where('reorder_point', '>', 0)
            ->whereRaw('(SELECT COALESCE(SUM(quantity_on_hand),0) FROM material_inventory WHERE material_id=materials.id) <= materials.reorder_point')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data'  => $materials->map(fn ($m) => $this->formatMaterial($m)),
            'count' => $materials->count(),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function userName($user): string
    {
        if (!$user) return '-';
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        return $name ?: ($user->email ?? "User #{$user->id}");
    }

    private function formatMaterial(Material $m, bool $withDetail = false): array
    {
        $totalStock  = (float) ($m->total_stock ?? $m->inventory?->sum('quantity_on_hand') ?? 0);
        $reorderPt   = (float) ($m->reorder_point ?? 0);
        $unitCost    = (float) ($m->unit_cost ?? 0);
        $isLow       = $reorderPt > 0 && $totalStock <= $reorderPt && $totalStock > 0;
        $isOut       = $totalStock <= 0;

        $data = [
            'id'              => $m->id,
            'code'            => $m->code,
            'name'            => $m->name,
            'description'     => $m->description,
            'material_type'   => $m->category,   // alias for frontend compatibility
            'category'        => $m->category,
            'unit_of_measure' => $m->unit_of_measure,
            'cost_per_unit'   => $unitCost,       // alias for frontend compatibility
            'unit_cost'       => $unitCost,
            'reorder_point'   => $reorderPt,
            'reorder_quantity'=> 0,               // column doesn't exist - default 0
            'is_active'       => (bool) $m->is_active,
            'total_stock'     => $totalStock,
            'stock_status'    => $isOut ? 'out_of_stock' : ($isLow ? 'low_stock' : 'in_stock'),
            'stock_value'     => round($totalStock * $unitCost, 2),
            'supplier'        => null,            // column doesn't exist
            'created_at'      => $m->created_at,
            'updated_at'      => $m->updated_at,
        ];

        if ($withDetail && $m->relationLoaded('inventory')) {
            $data['inventory'] = $m->inventory->map(fn ($inv) =>
                $this->formatInventoryRecord($inv)
            )->values();
        }

        return $data;
    }

    private function formatInventoryRecord(MaterialInventory $inv): array
    {
        return [
            'id'               => $inv->id,
            'material_id'      => $inv->material_id,
            'outlet_id'        => $inv->outlet_id,
            'quantity_on_hand' => (float) $inv->quantity_on_hand,
            'last_counted_at'  => $inv->last_counted_at,
            'outlet'           => $inv->relationLoaded('outlet') && $inv->outlet
                ? ['id' => $inv->outlet->id, 'name' => $inv->outlet->name, 'code' => $inv->outlet->code ?? null]
                : null,
        ];
    }

    private function formatTransaction(MaterialTransaction $t): array
    {
        return [
            'id'               => $t->id,
            'transaction_type' => $t->transaction_type,
            'type_label'       => self::TX_TYPES[$t->transaction_type] ?? ucfirst(str_replace('_', ' ', $t->transaction_type)),
            'quantity_change'  => (float) $t->quantity_change,
            'quantity_before'  => (float) $t->quantity_before,
            'quantity_after'   => (float) $t->quantity_after,
            'unit_cost'        => $t->unit_cost ? (float) $t->unit_cost : null,
            'notes'            => $t->notes,
            'outlet'           => $t->materialInventory?->outlet
                ? ['id' => $t->materialInventory->outlet->id, 'name' => $t->materialInventory->outlet->name]
                : null,
            'created_at'       => $t->created_at,
            'created_by'       => $t->createdBy
                ? ['id' => $t->createdBy->id, 'name' => $this->userName($t->createdBy)]
                : null,
        ];
    }
}