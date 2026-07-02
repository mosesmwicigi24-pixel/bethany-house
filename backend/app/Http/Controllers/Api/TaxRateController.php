<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\TaxCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxRateController extends Controller
{
    // ── GET /admin/tax-rates ──────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = DB::table('tax_rates');

        if ($request->filled('country_code')) {
            $query->where('country_code', $request->country_code);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ILIKE', "%{$request->search}%")
                  ->orWhere('code', 'ILIKE', "%{$request->search}%");
            });
        }

        $rates = $query->orderBy('is_default', 'desc')
                       ->orderBy('country_code')
                       ->orderBy('name')
                       ->get();

        // Attach product count to each rate
        $rates = $rates->map(function ($rate) {
            $rate->product_count = DB::table('product_tax_rates')
                ->where('tax_rate_id', $rate->id)
                ->count();
            return $rate;
        });

        return response()->json(['data' => $rates]);
    }

    // ── GET /admin/tax-rates/{id} ─────────────────────────────────────────────

    public function show($id)
    {
        $rate = DB::table('tax_rates')->find($id);
        if (!$rate) return response()->json(['message' => 'Not found'], 404);

        $rate->product_count = DB::table('product_tax_rates')
            ->where('tax_rate_id', $id)
            ->count();

        return response()->json(['tax_rate' => $rate]);
    }

    // ── POST /admin/tax-rates ─────────────────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'code'         => 'nullable|string|max:50',
            'country_code' => 'nullable|string|max:3',
            'rate'         => 'required|numeric|min:0|max:100',
            'tax_type'     => 'sometimes|in:percentage,fixed',
            'type'         => 'sometimes|in:percentage,fixed',
            'applies_to'   => 'sometimes|in:all,products,shipping',
            'is_default'   => 'sometimes|boolean',
            'is_active'    => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            // Ensure only one default rate exists
            if (!empty($validated['is_default'])) {
                DB::table('tax_rates')->update(['is_default' => false, 'updated_at' => now()]);
            }

            $id = DB::table('tax_rates')->insertGetId([
                'name'         => $validated['name'],
                'code'         => $validated['code'] ?? strtoupper(preg_replace('/[^A-Z0-9]/i', '_', $validated['name'])),
                'country_code' => $validated['country_code'] ?? null,
                'rate'         => $validated['rate'],
                'tax_type'     => $validated['tax_type'] ?? $validated['type'] ?? 'percentage',
                'type'         => $validated['type'] ?? $validated['tax_type'] ?? 'percentage',
                'applies_to'   => $validated['applies_to'] ?? 'all',
                'is_default'   => $validated['is_default'] ?? false,
                'is_active'    => $validated['is_active'] ?? true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            DB::commit();
            TaxCalculationService::invalidateGlobalCache();

            try {
                ActivityLogService::log('tax_rate_created', null, [
                    'tax_rate_id'  => $id,
                    'name'         => $validated['name'],
                    'rate'         => $validated['rate'],
                    'country_code' => $validated['country_code'] ?? null,
                    'is_default'   => $validated['is_default'] ?? false,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'  => 'Tax rate created successfully',
                'tax_rate' => DB::table('tax_rates')->find($id),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create tax rate', 'error' => $e->getMessage()], 500);
        }
    }

    // ── PUT /admin/tax-rates/{id} ─────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $existing = DB::table('tax_rates')->find($id);
        if (!$existing) return response()->json(['message' => 'Not found'], 404);

        $validated = $request->validate([
            'name'         => 'sometimes|string|max:100',
            'code'         => 'sometimes|string|max:50',
            'country_code' => 'sometimes|nullable|string|max:3',
            'rate'         => 'sometimes|numeric|min:0|max:100',
            'tax_type'     => 'sometimes|in:percentage,fixed',
            'type'         => 'sometimes|in:percentage,fixed',
            'applies_to'   => 'sometimes|in:all,products,shipping',
            'is_default'   => 'sometimes|boolean',
            'is_active'    => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['is_default']) && $validated['is_default']) {
                DB::table('tax_rates')->where('id', '!=', $id)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $update = array_filter([
                'name'         => $validated['name'] ?? null,
                'code'         => $validated['code'] ?? null,
                'country_code' => array_key_exists('country_code', $validated) ? $validated['country_code'] : null,
                'rate'         => $validated['rate'] ?? null,
                'tax_type'     => $validated['tax_type'] ?? $validated['type'] ?? null,
                'type'         => $validated['type'] ?? $validated['tax_type'] ?? null,
                'applies_to'   => $validated['applies_to'] ?? null,
                'is_default'   => isset($validated['is_default']) ? (bool) $validated['is_default'] : null,
                'is_active'    => isset($validated['is_active']) ? (bool) $validated['is_active'] : null,
            ], fn ($v) => $v !== null);

            $update['updated_at'] = now();
            DB::table('tax_rates')->where('id', $id)->update($update);

            DB::commit();
            TaxCalculationService::invalidateGlobalCache();

            try {
                ActivityLogService::log('tax_rate_updated', null, [
                    'tax_rate_id' => $id,
                    'changes'     => array_keys(array_diff_key($update, ['updated_at' => 1])),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'  => 'Tax rate updated successfully',
                'tax_rate' => DB::table('tax_rates')->find($id),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update tax rate', 'error' => $e->getMessage()], 500);
        }
    }

    // ── DELETE /admin/tax-rates/{id} ──────────────────────────────────────────

    public function destroy($id)
    {
        $rate = DB::table('tax_rates')->find($id);
        if (!$rate) return response()->json(['message' => 'Not found'], 404);

        $productCount = DB::table('product_tax_rates')->where('tax_rate_id', $id)->count();
        if ($productCount > 0) {
            return response()->json([
                'message' => "Cannot delete - this rate is assigned to {$productCount} product(s). Detach it first.",
            ], 422);
        }

        DB::table('tax_rates')->where('id', $id)->delete();
        TaxCalculationService::invalidateGlobalCache();

        try {
            ActivityLogService::log('tax_rate_deleted', null, [
                'tax_rate_id' => $id,
                'name'        => $rate->name,
                'rate'        => $rate->rate,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Tax rate deleted successfully']);
    }

    // ── PUT /admin/tax-rates/{id}/toggle ─────────────────────────────────────

    public function toggleStatus($id)
    {
        $rate = DB::table('tax_rates')->find($id);
        if (!$rate) return response()->json(['message' => 'Not found'], 404);

        DB::table('tax_rates')->where('id', $id)->update([
            'is_active'  => !$rate->is_active,
            'updated_at' => now(),
        ]);

        TaxCalculationService::invalidateGlobalCache();

        try {
            ActivityLogService::log('tax_rate_toggled', null, [
                'tax_rate_id' => $id,
                'name'        => $rate->name,
                'is_active'   => !$rate->is_active,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Status updated',
            'tax_rate' => DB::table('tax_rates')->find($id),
        ]);
    }

    // ── POST /admin/products/{productId}/tax-rates  ───────────────────────────
    // Sync the tax rates assigned to a product.

    public function syncProductRates(Request $request, $productId)
    {
        $validated = $request->validate([
            'tax_rate_ids'   => 'required|array',
            'tax_rate_ids.*' => 'integer|exists:tax_rates,id',
        ]);

        // Verify product exists
        $product = DB::table('products')->find($productId);
        if (!$product) return response()->json(['message' => 'Product not found'], 404);

        DB::beginTransaction();
        try {
            // Remove all existing and re-insert (sync)
            DB::table('product_tax_rates')->where('product_id', $productId)->delete();

            $now = now();
            $inserts = array_map(fn ($rateId) => [
                'product_id'  => $productId,
                'tax_rate_id' => $rateId,
                'created_at'  => $now,
                'updated_at'  => $now,
            ], $validated['tax_rate_ids']);

            if (!empty($inserts)) {
                DB::table('product_tax_rates')->insert($inserts);
            }

            DB::commit();
            TaxCalculationService::invalidateProductCache((int) $productId);

            try {
                ActivityLogService::log('product_tax_rates_synced', null, [
                    'product_id'   => $productId,
                    'tax_rate_ids' => $validated['tax_rate_ids'],
                    'count'        => count($validated['tax_rate_ids']),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'      => 'Tax rates updated for product.',
                'tax_rate_ids' => $validated['tax_rate_ids'],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update product tax rates', 'error' => $e->getMessage()], 500);
        }
    }

    // ── GET /admin/products/{productId}/tax-rates ────────────────────────────

    public function productRates($productId)
    {
        $product = DB::table('products')->find($productId);
        if (!$product) return response()->json(['message' => 'Product not found'], 404);

        $rates = DB::table('product_tax_rates as ptr')
            ->join('tax_rates as tr', 'ptr.tax_rate_id', '=', 'tr.id')
            ->where('ptr.product_id', $productId)
            ->select('tr.*')
            ->get();

        return response()->json(['data' => $rates]);
    }
}