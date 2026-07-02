<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CountryController extends Controller
{
    // =========================================================================
    // PUBLIC ENDPOINTS
    // =========================================================================

    /**
     * GET /api/v1/countries
     * List active countries for storefront - country selectors, checkout, address forms.
     * Public - no auth required. Always active-only: this endpoint has no
     * auth middleware, so there's no way to verify a caller claiming
     * internal use. The previous ?all=1 param bypassed the active-only
     * filter for anyone, unauthenticated, despite its docblock saying
     * "internal use" - nothing enforced that. Internal/admin tooling that
     * needs the full list (including inactive countries) already uses the
     * separate, permission-gated GET /admin/countries (adminIndex()) below;
     * the frontend doesn't call this endpoint with ?all=1 anywhere.
     */
    public function index(Request $request)
    {
        $query = Country::query()->where('is_active', true);

        if ($request->filled('shipping_enabled')) {
            $query->where('is_shipping_enabled', filter_var($request->shipping_enabled, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('region')) {
            $query->where('region', $request->region);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name',  'ILIKE', "%{$request->search}%")
                  ->orWhere('code', 'ILIKE', "%{$request->search}%");
            });
        }

        $countries = $query->orderBy('name')->get([
            'code', 'name', 'native_name', 'phone_code', 'flag',
            'region', 'subregion', 'default_currency_code',
            'is_active', 'is_shipping_enabled',
        ]);

        return response()->json(['data' => $countries]);
    }

    /**
     * GET /api/v1/countries/{code}
     * Single country detail - used by storefront and checkout.
     * Public - no auth required.
     */
    public function show($code)
    {
        $country = Country::where('code', strtoupper($code))->first();

        if (!$country) {
            return response()->json(['message' => 'Country not found.'], 404);
        }

        // Attach shipping zones if the table exists
        $shippingZones = [];
        try {
            $shippingZones = DB::table('shipping_zone_countries')
                ->join('shipping_zones', 'shipping_zone_countries.zone_id', '=', 'shipping_zones.id')
                ->where('shipping_zone_countries.country_code', strtoupper($code))
                ->select('shipping_zones.*')
                ->get();
        } catch (\Exception) {}

        return response()->json([
            'country'        => $country,
            'shipping_zones' => $shippingZones,
        ]);
    }

    // =========================================================================
    // ADMIN ENDPOINTS (super_admin only via routes)
    // =========================================================================

    /**
     * POST /api/v1/admin/countries
     * Create a new country.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'                   => 'required|string|min:2|max:3|unique:countries,code',
            'name'                   => 'required|string|max:100',
            'native_name'            => 'nullable|string|max:100',
            'phone_code'             => 'nullable|string|max:10',
            'flag'                   => 'nullable|string|max:10',
            'region'                 => 'nullable|string|max:100',
            'subregion'              => 'nullable|string|max:100',
            'default_currency_code'  => 'nullable|string|exists:currencies,code',
            'is_active'              => 'boolean',
            'is_shipping_enabled'    => 'boolean',
            'free_shipping_threshold'=> 'nullable|numeric|min:0',
            'standard_shipping_cost' => 'nullable|numeric|min:0',
            'express_shipping_cost'  => 'nullable|numeric|min:0',
            'estimated_delivery_days'=> 'nullable|integer|min:1|max:365',
        ]);

        $validated['code'] = strtoupper($validated['code']);

        $country = Country::create($validated);

        try {
            ActivityLogService::log('country_created', null, [
                'country_code' => $country->code,
                'name'         => $country->name,
                'is_active'    => $country->is_active,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Country created successfully.',
            'country' => $country,
        ], 201);
    }

    /**
     * GET /api/v1/admin/countries
     * Full country list with filters, stats and regional grouping for the admin UI.
     */
    public function adminIndex(Request $request)
    {
        $query = Country::query();

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('is_shipping_enabled')) {
            $query->where('is_shipping_enabled', filter_var($request->is_shipping_enabled, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('region')) {
            $query->where('region', $request->region);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name',  'ILIKE', "%{$search}%")
                  ->orWhere('code', 'ILIKE', "%{$search}%");
            });
        }

        $countries = $query->orderBy('name')->get();

        // Group by region for admin UI accordion/tabs
        $grouped = $countries
            ->groupBy(fn ($c) => $c->region ?? 'Other')
            ->map(fn ($group) => $group->values());

        return response()->json([
            'data'    => $countries,
            'grouped' => $grouped,
            'stats'   => [
                'total'            => $countries->count(),
                'active'           => $countries->where('is_active', true)->count(),
                'shipping_enabled' => $countries->where('is_shipping_enabled', true)->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/countries/regions
     * Distinct region names for filter dropdowns.
     */
    public function regions()
    {
        $regions = Country::whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region')
            ->filter()
            ->values();

        return response()->json(['data' => $regions]);
    }

    /**
     * PUT /api/v1/admin/countries/{code}
     * Update country metadata - active status, shipping, currency.
     */
    public function update(Request $request, $code)
    {
        $country = Country::where('code', strtoupper($code))->first();

        if (!$country) {
            return response()->json(['message' => 'Country not found.'], 404);
        }

        $validated = $request->validate([
            'is_active'            => 'sometimes|boolean',
            'is_shipping_enabled'  => 'sometimes|boolean',
            'default_currency_code'=> 'sometimes|nullable|string|exists:currencies,code',
            'phone_code'           => 'sometimes|nullable|string|max:10',
            'native_name'          => 'sometimes|nullable|string|max:100',
        ]);

        // Protect Kenya from being deactivated via update()
        if (strtoupper($code) === 'KE' && isset($validated['is_active']) && !$validated['is_active']) {
            return response()->json([
                'message' => 'Kenya cannot be deactivated - it is the primary market.',
            ], 422);
        }

        $country->update($validated);

        try {
            ActivityLogService::log('country_updated', null, [
                'country_code' => strtoupper($code),
                'name'         => $country->name,
                'changes'      => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Country updated successfully.',
            'country' => $country->fresh(),
        ]);
    }

    /**
     * PUT /api/v1/admin/countries/{code}/shipping-settings
     * Update shipping costs, thresholds, and delivery estimates.
     */
    public function updateShippingSettings(Request $request, $code)
    {
        $country = Country::where('code', strtoupper($code))->first();

        if (!$country) {
            return response()->json(['message' => 'Country not found.'], 404);
        }

        $validated = $request->validate([
            'is_shipping_enabled'    => 'required|boolean',
            'free_shipping_threshold'=> 'nullable|numeric|min:0',
            'standard_shipping_cost' => 'nullable|numeric|min:0',
            'express_shipping_cost'  => 'nullable|numeric|min:0',
            'estimated_delivery_days'=> 'nullable|integer|min:1|max:365',
        ]);

        $country->update($validated);

        try {
            ActivityLogService::log('country_shipping_settings_updated', null, [
                'country_code'           => strtoupper($code),
                'name'                   => $country->name,
                'is_shipping_enabled'    => $validated['is_shipping_enabled'],
                'standard_shipping_cost' => $validated['standard_shipping_cost'] ?? null,
                'free_shipping_threshold'=> $validated['free_shipping_threshold'] ?? null,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Shipping settings updated.',
            'country' => $country->fresh(),
        ]);
    }

    /**
     * PUT /api/v1/admin/countries/{code}/toggle
     * Toggle is_active. Kenya (KE) is always protected.
     */
    public function toggleStatus($code)
    {
        $code    = strtoupper($code);
        $country = Country::where('code', $code)->first();

        if (!$country) {
            return response()->json(['message' => 'Country not found.'], 404);
        }

        // Kenya is the home country - always stays active
        if ($code === 'KE' && $country->is_active) {
            return response()->json([
                'message' => 'Kenya cannot be deactivated - it is the primary market.',
            ], 422);
        }

        $country->update(['is_active' => !$country->is_active]);

        try {
            ActivityLogService::log('country_status_toggled', null, [
                'country_code' => $code,
                'name'         => $country->name,
                'is_active'    => $country->is_active,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'   => 'Country status updated.',
            'is_active' => $country->is_active,
            'country'   => $country->fresh(),
        ]);
    }
}