<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurrencyController extends Controller
{
    /**
     * List all currencies.
     * Admin: returns all. Public: only active ones.
     */
    public function index(Request $request)
    {
        $query = DB::table('currencies');

        // Public storefront only sees active currencies
        // Admin sees all (is authenticated with admin role by middleware)
        if (!$request->user()) {
            $query->where('is_active', true);
        }

        $currencies = $query->orderBy('is_base', 'desc')->orderBy('code')->get();

        return response()->json(['data' => $currencies]);
    }

    /**
     * Get single currency by ID.
     */
    public function show($id)
    {
        $currency = DB::table('currencies')->find($id);

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        return response()->json(['currency' => $currency]);
    }

    /**
     * Create a new currency.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'               => 'required|string|max:10|unique:currencies,code',
            'name'               => 'required|string|max:100',
            'symbol'             => 'required|string|max:10',
            'exchange_rate'      => 'required|numeric|min:0.000001',
            'decimal_places'     => 'required|integer|min:0|max:4',
            'symbol_position'    => 'sometimes|in:before,after',
            'thousand_separator' => 'sometimes|string|max:5',
            'decimal_separator'  => 'sometimes|string|max:5',
            'is_default'         => 'sometimes|boolean',
            'is_active'          => 'sometimes|boolean',
        ]);

        // If setting as default, clear existing default
        if (!empty($validated['is_default'])) {
            DB::table('currencies')->update(['is_default' => false, 'is_base' => false]);
        }

        $id = DB::table('currencies')->insertGetId([
            'code'               => strtoupper($validated['code']),
            'name'               => $validated['name'],
            'symbol'             => $validated['symbol'],
            'exchange_rate'      => $validated['exchange_rate'],
            'decimal_places'     => $validated['decimal_places'],
            'symbol_position'    => $validated['symbol_position'] ?? 'before',
            'thousand_separator' => $validated['thousand_separator'] ?? ',',
            'decimal_separator'  => $validated['decimal_separator'] ?? '.',
            'is_default'         => $validated['is_default'] ?? false,
            'is_base'            => $validated['is_default'] ?? false,
            'is_active'          => $validated['is_active'] ?? true,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        try {
            ActivityLogService::log('currency_created', null, [
                'currency_id' => $id,
                'code'        => strtoupper($validated['code']),
                'name'        => $validated['name'],
                'is_default'  => $validated['is_default'] ?? false,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Currency created successfully.',
            'currency' => DB::table('currencies')->find($id),
        ], 201);
    }

    /**
     * Update a currency.
     */
    public function update(Request $request, $id)
    {
        $currency = DB::table('currencies')->find($id);

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        $validated = $request->validate([
            'name'               => 'sometimes|string|max:100',
            'symbol'             => 'sometimes|string|max:10',
            'exchange_rate'      => 'sometimes|numeric|min:0.000001',
            'decimal_places'     => 'sometimes|integer|min:0|max:4',
            'symbol_position'    => 'sometimes|in:before,after',
            'thousand_separator' => 'sometimes|string|max:5',
            'decimal_separator'  => 'sometimes|string|max:5',
            'is_active'          => 'sometimes|boolean',
        ]);

        DB::table('currencies')
            ->where('id', $id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        try {
            ActivityLogService::log('currency_updated', null, [
                'currency_id' => $id,
                'code'        => $currency->code,
                'changes'     => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Currency updated successfully.',
            'currency' => DB::table('currencies')->find($id),
        ]);
    }

    /**
     * Delete a currency (only non-default, non-base currencies).
     */
    public function destroy($id)
    {
        $currency = DB::table('currencies')->find($id);

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        if ($currency->is_default || $currency->is_base) {
            return response()->json([
                'message' => 'Cannot delete the default/base currency.',
            ], 422);
        }

        // Check if any country uses this currency as its default
        $linkedCountries = DB::table('countries')
            ->where('default_currency_code', $currency->code)
            ->orderBy('name')
            ->pluck('name');

        if ($linkedCountries->isNotEmpty()) {
            return response()->json([
                'message'   => "Cannot delete {$currency->code} - it is the default currency for: "
                    . $linkedCountries->join(', ')
                    . '. Reassign those countries to a different currency first.',
                'countries' => $linkedCountries,
            ], 422);
        }

        // Check if currency is referenced in orders
        try {
            $inUse = DB::table('orders')->where('currency', $currency->code)->exists();
            if ($inUse) {
                return response()->json([
                    'message' => 'This currency has been used in orders and cannot be deleted.',
                ], 422);
            }
        } catch (\Exception) {
            // orders table may not exist yet - skip check
        }

        DB::table('currencies')->where('id', $id)->delete();

        try {
            ActivityLogService::log('currency_deleted', null, [
                'currency_id' => $id,
                'code'        => $currency->code,
                'name'        => $currency->name,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Currency deleted successfully.']);
    }

    /**
     * Toggle active status.
     * Cannot disable the default/base currency.
     */
    public function toggleStatus($id)
    {
        $currency = DB::table('currencies')->find($id);

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        if (($currency->is_default || $currency->is_base) && $currency->is_active) {
            return response()->json([
                'message' => 'Cannot disable the default currency.',
            ], 422);
        }

        $newStatus = !$currency->is_active;

        DB::table('currencies')->where('id', $id)->update([
            'is_active'  => $newStatus,
            'updated_at' => now(),
        ]);

        try {
            ActivityLogService::log('currency_toggled', null, [
                'currency_id' => $id,
                'code'        => $currency->code,
                'is_active'   => $newStatus,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'   => 'Currency status updated.',
            'currency'  => DB::table('currencies')->find($id),
        ]);
    }

    /**
     * Set a currency as the system default.
     * Auto-enables it and clears existing default.
     */
    public function setDefault($id)
    {
        $currency = DB::table('currencies')->find($id);

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        DB::beginTransaction();
        try {
            // Clear existing default and base flags
            DB::table('currencies')->update([
                'is_default'  => false,
                'is_base'     => false,
                'updated_at'  => now(),
            ]);

            // Set new default
            DB::table('currencies')->where('id', $id)->update([
                'is_default'  => true,
                'is_base'     => true,
                'is_active'   => true,    // Default must be active
                'updated_at'  => now(),
            ]);

            DB::commit();

            try {
                ActivityLogService::log('currency_set_default', null, [
                    'currency_id' => $id,
                    'code'        => $currency->code,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'  => 'Default currency updated.',
                'currency' => DB::table('currencies')->find($id),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to set default currency.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update exchange rate for a single currency.
     */
    public function updateRates(Request $request, $id)
    {
        $validated = $request->validate([
            'exchange_rate' => 'required|numeric|min:0.000001',
        ]);

        $currency = DB::table('currencies')->find($id);

        if (!$currency) {
            return response()->json(['message' => 'Currency not found.'], 404);
        }

        if ($currency->is_base) {
            return response()->json([
                'message' => 'Cannot change the exchange rate of the base currency.',
            ], 422);
        }

        DB::table('currencies')->where('id', $id)->update([
            'exchange_rate' => $validated['exchange_rate'],
            'updated_at'    => now(),
        ]);

        try {
            ActivityLogService::log('currency_rate_updated', null, [
                'currency_id'   => $id,
                'code'          => $currency->code,
                'old_rate'      => $currency->exchange_rate,
                'new_rate'      => $validated['exchange_rate'],
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Exchange rate updated.',
            'currency' => DB::table('currencies')->find($id),
        ]);
    }

    /**
     * Sync exchange rates from external API.
     * Placeholder - integrate with exchangerate-api.com or similar.
     */
    public function syncRates()
    {
        // TODO: Fetch live rates and update all non-base currencies
        // Example: https://api.exchangerate-api.com/v4/latest/KES
        return response()->json([
            'message' => 'Automatic rate sync not yet configured.',
            'note'    => 'Set up an exchangerate-api.com key in settings to enable this.',
        ], 501);
    }
}