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
            // KES per ONE unit of this currency, used ONLY to state completed
            // sales in the reporting currency — 128 for USD, 6.5 for ZMW. It is
            // NOT exchange_rate above, which is the base-relative PRICING rate a
            // customer is quoted at (0.01 for USD). Nullable on purpose: unset
            // means "do not convert", and those orders are reported apart rather
            // than at a guess.
            'reporting_rate_to_kes' => 'sometimes|nullable|numeric|min:0.000001',
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
            'reporting_rate_to_kes' => $validated['reporting_rate_to_kes'] ?? null,
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

        // Both rate caches, because a currency row feeds both: pricing (what a
        // customer is quoted) and reporting (what earned money is worth). Left
        // un-busted, an edited rate sat behind a 5-minute TTL and looked like
        // the save had not worked.
        \App\Services\CurrencyPricing::forget();
        \App\Support\ReportingCurrency::forget();

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
            // KES per ONE unit of this currency, used ONLY to state completed
            // sales in the reporting currency — 128 for USD, 6.5 for ZMW. It is
            // NOT exchange_rate above, which is the base-relative PRICING rate a
            // customer is quoted at (0.01 for USD). Nullable on purpose: unset
            // means "do not convert", and those orders are reported apart rather
            // than at a guess.
            'reporting_rate_to_kes' => 'sometimes|nullable|numeric|min:0.000001',
            'decimal_places'     => 'sometimes|integer|min:0|max:4',
            'symbol_position'    => 'sometimes|in:before,after',
            'thousand_separator' => 'sometimes|string|max:5',
            'decimal_separator'  => 'sometimes|string|max:5',
            'is_active'          => 'sometimes|boolean',
            // Phase 3C: when a reporting-rate change takes effect (now or later).
            'effective_from'     => 'sometimes|nullable|date',
        ]);

        // The two rates are proposals (Phase 3C); everything else about a
        // currency is a plain edit, and stays the super admin's (settings.edit).
        $plain = array_diff_key($validated, array_flip(['exchange_rate', 'reporting_rate_to_kes', 'effective_from']));
        $plain = array_filter($plain, fn ($v, $k) => !property_exists($currency, $k) || (string) $currency->{$k} !== (string) (is_bool($v) ? (int) $v : $v), ARRAY_FILTER_USE_BOTH);
        if ($plain !== [] && !$request->user()->can('settings.edit')) {
            return response()->json([
                'message' => 'You can propose rate changes; the currency\'s other details are the super admin\'s to edit.',
                'code'    => 'NOT_A_SETTINGS_EDITOR',
            ], 403);
        }

        $proposals = DB::transaction(function () use ($id, $plain, $validated, $request) {
            if ($plain !== []) {
                DB::table('currencies')->where('id', $id)->update(array_merge($plain, ['updated_at' => now()]));
            }

            return $this->proposeRates((int) $id, $validated, $request);
        });

        // Both rate caches, because a currency row feeds both: pricing (what a
        // customer is quoted) and reporting (what earned money is worth). Left
        // un-busted, an edited rate sat behind a 5-minute TTL and looked like
        // the save had not worked.
        \App\Services\CurrencyPricing::forget();
        \App\Support\ReportingCurrency::forget();

        if ($plain !== []) {
            try {
                ActivityLogService::log('currency_updated', null, [
                    'currency_id' => $id,
                    'code'        => $currency->code,
                    'changes'     => array_keys($plain),
                ]);
            } catch (\Exception) {}
        }

        return $this->ratesResponse('Currency updated successfully.', (int) $id, $proposals, $request);
    }

    /**
     * The pricing and reporting rates as proposals (Phase 3C):
     *   exchange_rate          customer_pricing_fx_change — admin proposes, finance signs
     *   reporting_rate_to_kes  reporting_fx_change — finance proposes, the super admin
     *                          signs; effective-dated
     * A rate that does not change raises nothing.
     *
     * @return list<\App\Models\ChangeProposal>
     */
    private function proposeRates(int $id, array $validated, Request $request): array
    {
        $service = app(\App\Services\Approvals\ProposalService::class);
        $out = [];
        if (array_key_exists('exchange_rate', $validated)) {
            $out[] = $service->propose('customer_pricing_fx_change', $id, ['exchange_rate' => $validated['exchange_rate']], $request->user());
        }
        if (array_key_exists('reporting_rate_to_kes', $validated)) {
            $out[] = $service->propose('reporting_fx_change', $id, ['reporting_rate_to_kes' => $validated['reporting_rate_to_kes']],
                $request->user(), $validated['effective_from'] ?? null);
        }

        return array_values(array_filter($out));
    }

    /** @param list<\App\Models\ChangeProposal> $proposals */
    private function ratesResponse(string $base, int $id, array $proposals, Request $request)
    {
        $waiting = array_filter($proposals, fn ($p) => $p->status !== \App\Models\ChangeProposal::APPLIED);

        return response()->json([
            'message'   => \App\Services\Approvals\ProposalMessages::saved($base, $proposals),
            'currency'  => DB::table('currencies')->find($id),
            'proposals' => \App\Services\Approvals\ProposalMessages::present($proposals, $request->user()),
        ], $waiting !== [] ? 202 : 200);
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
     * Update the PRICING exchange rate for a single currency.
     *
     * This is the rate a customer is quoted at (100 KES = 1 USD). The reporting
     * rate — what earned money is worth, 128 KES = 1 USD — is a different
     * column and is set through update(); a caller may pass it here too, but
     * naming it explicitly is the point, so that nobody changes what customers
     * are charged while intending to change what a report says.
     */
    public function updateRates(Request $request, $id)
    {
        $validated = $request->validate([
            'exchange_rate' => 'required|numeric|min:0.000001',
            'reporting_rate_to_kes' => 'sometimes|nullable|numeric|min:0.000001',
            'effective_from' => 'sometimes|nullable|date',
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

        // Both rates are proposals (Phase 3C). NOT array_filter on the
        // reporting rate: null is a legitimate value meaning "do not convert".
        $proposals = DB::transaction(fn () => $this->proposeRates((int) $id, $validated, $request));

        \App\Services\CurrencyPricing::forget();
        \App\Support\ReportingCurrency::forget();

        return $this->ratesResponse('Exchange rate updated.', (int) $id, $proposals, $request);
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