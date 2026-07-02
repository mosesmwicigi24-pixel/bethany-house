<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShippingController extends Controller
{
    // =========================================================================
    // ADMIN ALIASES  (routes reference these names)
    // =========================================================================

    /**
     * Admin: list zones - GET /v1/admin/shipping/zones
     */
    public function adminZones(Request $request)
    {
        return $this->zones($request);
    }

    /**
     * Admin: list methods - GET /v1/admin/shipping/methods
     */
    public function adminMethods(Request $request)
    {
        return $this->methods($request);
    }

    // =========================================================================
    // SHIPPING ZONES
    // =========================================================================

    /**
     * List all zones with their country codes (resolved from pivot).
     */
    public function zones(Request $request)
    {
        $query = DB::table('shipping_zones');

        if ($request->filled('search')) {
            $query->where('name', 'ILIKE', "%{$request->search}%");
        }

        $zones = $query->orderBy('name')->get();

        // Attach country codes from pivot
        $zones = $zones->map(function ($zone) {
            $zone->countries = DB::table('shipping_zone_countries')
                ->where('shipping_zone_id', $zone->id)
                ->pluck('country_code')
                ->toArray();
            return $zone;
        });

        return response()->json($zones);
    }

    /**
     * Single zone with countries + methods.
     */
    public function showZone($id)
    {
        $zone = DB::table('shipping_zones')->find($id);

        if (!$zone) {
            return response()->json(['message' => 'Zone not found'], 404);
        }

        $zone->countries = DB::table('shipping_zone_countries')
            ->where('shipping_zone_id', $zone->id)
            ->pluck('country_code')
            ->toArray();

        $methods = DB::table('shipping_methods')
            ->where('shipping_zone_id', $zone->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'zone'    => $zone,
            'methods' => $methods,
        ]);
    }

    /**
     * Create a shipping zone and assign its countries via the pivot.
     */
    public function createZone(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'countries'   => 'required|array|min:1',
            'countries.*' => 'string|max:3',
            'description' => 'nullable|string',
        ]);

        $id = DB::table('shipping_zones')->insertGetId([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->syncZoneCountries($id, $validated['countries']);

        $zone = DB::table('shipping_zones')->find($id);
        $zone->countries = $validated['countries'];

        try {
            ActivityLogService::log('shipping_zone_created', null, [
                'zone_id'   => $id,
                'name'      => $validated['name'],
                'countries' => $validated['countries'],
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Shipping zone created successfully',
            'zone'    => $zone,
        ], 201);
    }

    /**
     * Update zone name/description and replace its countries.
     */
    public function updateZone(Request $request, $id)
    {
        $zone = DB::table('shipping_zones')->find($id);

        if (!$zone) {
            return response()->json(['message' => 'Zone not found'], 404);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'countries'   => 'sometimes|array|min:1',
            'countries.*' => 'string|max:3',
            'description' => 'nullable|string',
        ]);

        $update = ['updated_at' => now()];
        if (isset($validated['name']))        $update['name']        = $validated['name'];
        if (array_key_exists('description', $validated)) $update['description'] = $validated['description'];

        DB::table('shipping_zones')->where('id', $id)->update($update);

        if (isset($validated['countries'])) {
            $this->syncZoneCountries($id, $validated['countries']);
        }

        $updated = DB::table('shipping_zones')->find($id);
        $updated->countries = DB::table('shipping_zone_countries')
            ->where('shipping_zone_id', $id)
            ->pluck('country_code')
            ->toArray();

        try {
            ActivityLogService::log('shipping_zone_updated', null, [
                'zone_id' => $id,
                'changes' => array_keys($update),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Shipping zone updated successfully',
            'zone'    => $updated,
        ]);
    }

    /**
     * Delete a zone (only if it has no methods).
     */
    public function deleteZone($id)
    {
        $methodCount = DB::table('shipping_methods')
            ->where('shipping_zone_id', $id)
            ->count();

        if ($methodCount > 0) {
            return response()->json([
                'message' => 'Cannot delete a zone that still has shipping methods. Remove them first.',
            ], 422);
        }

        DB::table('shipping_zone_countries')->where('shipping_zone_id', $id)->delete();
        DB::table('shipping_zones')->where('id', $id)->delete();

        try {
            ActivityLogService::log('shipping_zone_deleted', null, ['zone_id' => $id]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Shipping zone deleted successfully']);
    }

    // ── Country helpers ───────────────────────────────────────────────────────

    /**
     * Add countries to a zone.
     * POST /v1/admin/shipping/zones/{id}/countries
     */
    public function addCountries(Request $request, $id)
    {
        $zone = DB::table('shipping_zones')->find($id);
        if (!$zone) return response()->json(['message' => 'Zone not found'], 404);

        $request->validate([
            'countries'   => 'required|array|min:1',
            'countries.*' => 'string|max:3',
        ]);

        $existing = DB::table('shipping_zone_countries')
            ->where('shipping_zone_id', $id)
            ->pluck('country_code')
            ->toArray();

        $toInsert = array_diff(
            array_map('strtoupper', $request->countries),
            array_map('strtoupper', $existing)
        );

        foreach ($toInsert as $code) {
            DB::table('shipping_zone_countries')->insert([
                'shipping_zone_id' => $id,
                'country_code'     => strtoupper($code),
            ]);
        }

        $updated = DB::table('shipping_zones')->find($id);
        $updated->countries = DB::table('shipping_zone_countries')
            ->where('shipping_zone_id', $id)->pluck('country_code')->toArray();

        return response()->json(['message' => 'Countries added', 'zone' => $updated]);
    }

    /**
     * Remove a single country from a zone.
     * DELETE /v1/admin/shipping/zones/{id}/countries/{code}
     */
    public function removeCountry($id, $code)
    {
        $zone = DB::table('shipping_zones')->find($id);
        if (!$zone) return response()->json(['message' => 'Zone not found'], 404);

        DB::table('shipping_zone_countries')
            ->where('shipping_zone_id', $id)
            ->where('country_code', strtoupper($code))
            ->delete();

        $updated = DB::table('shipping_zones')->find($id);
        $updated->countries = DB::table('shipping_zone_countries')
            ->where('shipping_zone_id', $id)->pluck('country_code')->toArray();

        return response()->json(['message' => 'Country removed', 'zone' => $updated]);
    }

    /**
     * List countries in a zone with resolved names from the countries table.
     * GET /v1/admin/shipping/zones/{id}/countries
     */
    public function zoneCountries($id)
    {
        $zone = DB::table('shipping_zones')->find($id);
        if (!$zone) return response()->json(['message' => 'Zone not found'], 404);

        $codes = DB::table('shipping_zone_countries')
            ->where('shipping_zone_id', $id)
            ->pluck('country_code')
            ->toArray();

        $countries = $this->resolveCountryNames($codes);

        return response()->json(['countries' => $countries]);
    }

    // =========================================================================
    // SHIPPING METHODS
    // =========================================================================

    /**
     * List methods, optionally filtered by zone.
     * Columns: shipping_zone_id, name, description, delivery_time,
     *          cost_type (flat_rate|free|percentage), flat_rate,
     *          min_order_amount, is_active, sort_order
     */
    public function methods(Request $request)
    {
        $query = DB::table('shipping_methods')
            ->leftJoin('shipping_zones', 'shipping_methods.shipping_zone_id', '=', 'shipping_zones.id')
            ->select('shipping_methods.*', 'shipping_zones.name as zone_name');

        if ($request->filled('zone_id')) {
            $query->where('shipping_methods.shipping_zone_id', $request->zone_id);
        }

        if ($request->filled('is_active')) {
            $query->where('shipping_methods.is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $methods = $query
            ->orderBy('shipping_zones.name')
            ->orderBy('shipping_methods.sort_order')
            ->orderBy('shipping_methods.name')
            ->get();

        return response()->json($methods);
    }

    /**
     * Create a shipping method.
     */
    public function createMethod(Request $request)
    {
        $validated = $request->validate([
            'zone_id'          => 'required|exists:shipping_zones,id',
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'delivery_time'    => 'nullable|string|max:100',
            'cost_type'        => 'required|in:flat_rate,free,percentage',
            'flat_rate'        => 'required|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'is_active'        => 'boolean',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        $id = DB::table('shipping_methods')->insertGetId([
            'shipping_zone_id' => $validated['zone_id'],
            'name'             => $validated['name'],
            'description'      => $validated['description'] ?? null,
            'delivery_time'    => $validated['delivery_time'] ?? null,
            'cost_type'        => $validated['cost_type'],
            'flat_rate'        => $validated['flat_rate'],
            'min_order_amount' => $validated['min_order_amount'] ?? null,
            'is_active'        => $validated['is_active'] ?? true,
            'sort_order'       => $validated['sort_order'] ?? 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $method = DB::table('shipping_methods')->find($id);

        try {
            ActivityLogService::log('shipping_method_created', null, [
                'method_id' => $id,
                'zone_id'   => $validated['zone_id'],
                'name'      => $validated['name'],
                'cost_type' => $validated['cost_type'],
                'flat_rate' => $validated['flat_rate'],
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Shipping method created successfully',
            'method'  => $method,
        ], 201);
    }

    /**
     * Update a shipping method.
     */
    public function updateMethod(Request $request, $id)
    {
        $method = DB::table('shipping_methods')->find($id);

        if (!$method) {
            return response()->json(['message' => 'Method not found'], 404);
        }

        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'description'      => 'nullable|string',
            'delivery_time'    => 'nullable|string|max:100',
            'cost_type'        => 'sometimes|in:flat_rate,free,percentage',
            'flat_rate'        => 'sometimes|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'is_active'        => 'sometimes|boolean',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        DB::table('shipping_methods')
            ->where('id', $id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        try {
            ActivityLogService::log('shipping_method_updated', null, [
                'method_id' => $id,
                'changes'   => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Shipping method updated successfully',
            'method'  => DB::table('shipping_methods')->find($id),
        ]);
    }

    /**
     * Delete a shipping method.
     */
    public function deleteMethod($id)
    {
        DB::table('shipping_methods')->where('id', $id)->delete();

        try {
            ActivityLogService::log('shipping_method_deleted', null, ['method_id' => $id]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Shipping method deleted successfully']);
    }

    // =========================================================================
    // PUBLIC ENDPOINTS
    // =========================================================================

    /**
     * Calculate available shipping methods for a given country + cart.
     */
    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'country'    => 'required|string|max:3',
            'weight'     => 'nullable|numeric|min:0',
            'cart_total' => 'nullable|numeric|min:0',
        ]);

        // Find zone via pivot
        $zoneId = DB::table('shipping_zone_countries')
            ->where('country_code', strtoupper($validated['country']))
            ->value('shipping_zone_id');

        if (!$zoneId) {
            return response()->json([
                'message'           => 'No shipping available to this location',
                'available_methods' => [],
            ]);
        }

        $zone = DB::table('shipping_zones')->find($zoneId);

        $methods = DB::table('shipping_methods')
            ->where('shipping_zone_id', $zoneId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $available = [];
        foreach ($methods as $method) {
            if ($method->min_order_amount && ($validated['cart_total'] ?? 0) < $method->min_order_amount) {
                continue;
            }

            $cost = match ($method->cost_type) {
                'free'       => 0,
                'flat_rate'  => (float) $method->flat_rate,
                'percentage' => round(($validated['cart_total'] ?? 0) * $method->flat_rate / 100, 2),
                default      => (float) $method->flat_rate,
            };

            $available[] = [
                'id'            => $method->id,
                'name'          => $method->name,
                'description'   => $method->description,
                'delivery_time' => $method->delivery_time,
                'cost'          => $cost,
                'cost_type'     => $method->cost_type,
            ];
        }

        return response()->json([
            'zone'              => ['id' => $zone->id, 'name' => $zone->name],
            'available_methods' => $available,
        ]);
    }

    /**
     * Pickup locations (outlets marked as pickup points).
     */
    public function pickupLocations(Request $request)
    {
        $query = DB::table('outlets')
            ->where('is_active', true)
            ->select('id', 'name', 'address_line1', 'city', 'phone');

        if ($request->filled('city')) {
            $query->where('city', $request->city);
        }

        return response()->json($query->orderBy('city')->orderBy('name')->get());
    }

    /**
     * Countries that have at least one active shipping method.
     * Uses the countries table for proper names.
     */
    public function availableCountries()
    {
        $codes = DB::table('shipping_zone_countries')
            ->join('shipping_zones', 'shipping_zone_countries.shipping_zone_id', '=', 'shipping_zones.id')
            ->join('shipping_methods', 'shipping_methods.shipping_zone_id', '=', 'shipping_zones.id')
            ->where('shipping_methods.is_active', true)
            ->distinct()
            ->pluck('shipping_zone_countries.country_code')
            ->toArray();

        $countries = $this->resolveCountryNames($codes);

        return response()->json(['countries' => $countries]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Replace a zone's countries in the pivot table.
     */
    private function syncZoneCountries(int $zoneId, array $codes): void
    {
        DB::table('shipping_zone_countries')->where('shipping_zone_id', $zoneId)->delete();

        foreach (array_unique(array_map('strtoupper', $codes)) as $code) {
            DB::table('shipping_zone_countries')->insert([
                'shipping_zone_id' => $zoneId,
                'country_code'     => $code,
            ]);
        }
    }

    /**
     * Resolve country codes to names using the countries table,
     * with a safe fallback to the code itself if not found.
     */
    private function resolveCountryNames(array $codes): array
    {
        if (empty($codes)) return [];

        $names = DB::table('countries')
            ->whereIn('code', array_map('strtoupper', $codes))
            ->pluck('name', 'code');

        return array_map(fn($code) => [
            'code' => $code,
            'name' => $names[$code] ?? $code,
        ], $codes);
    }
}