<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutletController extends Controller
{
    /**
     * List all outlets.
     * React admin expects { data: OutletSetup[] }
     */
    public function index(Request $request)
    {
        $query = Outlet::query();

        // Filter by active status (DB column is is_active, not status)
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by country_code (not country)
        if ($request->filled('country_code')) {
            $query->where('country_code', $request->country_code);
        }

        // Filter by outlet_type (not type)
        if ($request->filled('outlet_type')) {
            $query->where('outlet_type', $request->outlet_type);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('code', 'ILIKE', "%{$search}%")
                    ->orWhere('city',  'ILIKE', "%{$search}%");
            });
        }

        $outlets = $query->orderBy('name')->get();

        return response()->json(['data' => $outlets]);
    }

    /**
     * Get a single outlet with statistics.
     */
    public function show($id)
    {
        $outlet = Outlet::findOrFail($id);

        $statistics = [];

        // POS sales stats - graceful fallback if orders table not ready
        try {
            $statistics['today_sales_count'] = DB::table('orders')
                ->where('outlet_id', $outlet->id)
                ->where('channel', 'pos')
                ->whereDate('created_at', today())
                ->count();

            $statistics['today_sales_total'] = DB::table('orders')
                ->where('outlet_id', $outlet->id)
                ->where('channel', 'pos')
                ->whereDate('created_at', today())
                ->sum('total');

            $statistics['total_orders'] = DB::table('orders')
                ->where('outlet_id', $outlet->id)
                ->count();
        } catch (\Exception) {
            $statistics = [];
        }

        // Assigned users count via pivot
        try {
            $statistics['users_count'] = DB::table('outlet_user')
                ->where('outlet_id', $outlet->id)
                ->count();
        } catch (\Exception) {
            $statistics['users_count'] = 0;
        }

        return response()->json([
            'outlet'     => $outlet,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Create a new outlet.
     *
     * React form sends:
     *   outlet_type, address_line1, country_code, phone (nullable)
     *
     * Old controller expected:
     *   type, address_line_1, country, phone (required)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'               => 'required|string|max:20|unique:outlets,code',
            'name'               => 'required|string|max:255',
            'outlet_type'        => 'required|in:store,warehouse,outlet,workshop',
            'sales_channel'      => 'sometimes|in:pos,whatsapp,online',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'address_line1'      => 'nullable|string|max:255',
            'address_line2'      => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'state_province'     => 'nullable|string|max:100',
            'postal_code'        => 'nullable|string|max:20',
            'country_code'       => 'required|string|max:3',
            'latitude'           => 'nullable|numeric|between:-90,90',
            'longitude'          => 'nullable|numeric|between:-180,180',
            'geofence_radius_meters' => 'nullable|integer|min:10|max:5000',
            'is_active'          => 'sometimes|boolean',
            'is_pickup_location' => 'sometimes|boolean',
            'operating_hours'    => 'nullable|array',
        ]);

        $outlet = Outlet::create([
            'code'               => strtoupper($validated['code']),
            'name'               => $validated['name'],
            'outlet_type'        => $validated['outlet_type'],
            'sales_channel'      => $validated['sales_channel'] ?? 'pos',
            'email'              => $validated['email'] ?? null,
            'phone'              => $validated['phone'] ?? null,
            'address_line1'      => $validated['address_line1'] ?? null,
            'address_line2'      => $validated['address_line2'] ?? null,
            'city'               => $validated['city'] ?? null,
            'state_province'     => $validated['state_province'] ?? null,
            'postal_code'        => $validated['postal_code'] ?? null,
            'country_code'       => strtoupper($validated['country_code']),
            'latitude'           => $validated['latitude'] ?? null,
            'longitude'          => $validated['longitude'] ?? null,
            'geofence_radius_meters' => $validated['geofence_radius_meters'] ?? null,
            'is_active'          => $validated['is_active'] ?? true,
            'is_pickup_location' => $validated['is_pickup_location'] ?? false,
            'operating_hours'    => isset($validated['operating_hours'])
                ? json_encode($validated['operating_hours'])
                : null,
        ]);

        ActivityLogService::log('created', $outlet, [
            'name'        => $outlet->name,
            'code'        => $outlet->code,
            'outlet_type' => $outlet->outlet_type,
            'city'        => $outlet->city,
        ], "Outlet '{$outlet->name}' ({$outlet->code}) created");

        return response()->json([
            'message' => 'Outlet created successfully.',
            'outlet'  => $outlet,
        ], 201);
    }

    /**
     * Update an outlet.
     */
    public function update(Request $request, $id)
    {
        $outlet = Outlet::findOrFail($id);

        $validated = $request->validate([
            'code'               => 'sometimes|string|max:20|unique:outlets,code,' . $outlet->id,
            'name'               => 'sometimes|string|max:255',
            'outlet_type'        => 'sometimes|in:store,warehouse,outlet,workshop',
            'sales_channel'      => 'sometimes|in:pos,whatsapp,online',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:30',
            'address_line1'      => 'nullable|string|max:255',
            'address_line2'      => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'state_province'     => 'nullable|string|max:100',
            'postal_code'        => 'nullable|string|max:20',
            'country_code'       => 'sometimes|string|max:3',
            'latitude'           => 'nullable|numeric|between:-90,90',
            'longitude'          => 'nullable|numeric|between:-180,180',
            'geofence_radius_meters' => 'nullable|integer|min:10|max:5000',
            'is_active'          => 'sometimes|boolean',
            'is_pickup_location' => 'sometimes|boolean',
            'operating_hours'    => 'nullable|array',
        ]);

        // Encode operating_hours if provided
        if (isset($validated['operating_hours'])) {
            $validated['operating_hours'] = json_encode($validated['operating_hours']);
        }

        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $outlet->update($validated);

        ActivityLogService::log('updated', $outlet, [
            'changed_fields' => array_keys($validated),
        ], "Outlet '{$outlet->name}' updated: " . implode(', ', array_keys($validated)));

        return response()->json([
            'message' => 'Outlet updated successfully.',
            'outlet'  => $outlet->fresh(),
        ]);
    }

    /**
     * Delete an outlet (with safety checks).
     */
    public function destroy($id)
    {
        $outlet = Outlet::findOrFail($id);

        // Safety checks
        try {
            $userCount = DB::table('outlet_user')->where('outlet_id', $outlet->id)->count();
            if ($userCount > 0) {
                return response()->json([
                    'message' => 'Cannot delete an outlet with assigned users. Reassign users first.',
                ], 422);
            }

            $orderCount = DB::table('orders')->where('outlet_id', $outlet->id)->count();
            if ($orderCount > 0) {
                return response()->json([
                    'message' => 'Cannot delete an outlet with order history. Deactivate it instead.',
                ], 422);
            }
        } catch (\Exception) {
        }

        DB::beginTransaction();
        try {
            // Clean up related records before deleting
            DB::table('cash_registers')->where('outlet_id', $outlet->id)->delete();
            DB::table('outlet_user')->where('outlet_id', $outlet->id)->delete();

            $outlet->delete();
            DB::commit();

            ActivityLogService::log('deleted', null, [
                'outlet_name' => $outlet->name,
                'outlet_code' => $outlet->code,
                'outlet_id'   => $outlet->id,
            ], "Outlet '{$outlet->name}' deleted");

            return response()->json(['message' => 'Outlet deleted successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete outlet.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get outlet statistics.
     * Called by React admin on the outlets detail view.
     */
    public function statistics($id)
    {
        $outlet = Outlet::findOrFail($id);

        $stats = [
            'users_count'         => 0,
            'today_sales_count'   => 0,
            'today_sales_total'   => 0,
            'month_sales_count'   => 0,
            'month_sales_total'   => 0,
            'total_orders'        => 0,
            'cash_register_open'  => false,
        ];

        try {
            $stats['users_count'] = DB::table('outlet_user')
                ->where('outlet_id', $outlet->id)->count();
        } catch (\Exception) {
        }

        try {
            $stats['today_sales_count'] = DB::table('orders')
                ->where('outlet_id', $outlet->id)->where('channel', 'pos')
                ->whereDate('created_at', today())->count();

            $stats['today_sales_total'] = (float) DB::table('orders')
                ->where('outlet_id', $outlet->id)->where('channel', 'pos')
                ->whereDate('created_at', today())->sum('total');

            $stats['month_sales_count'] = DB::table('orders')
                ->where('outlet_id', $outlet->id)->where('channel', 'pos')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at',  now()->year)->count();

            $stats['month_sales_total'] = (float) DB::table('orders')
                ->where('outlet_id', $outlet->id)->where('channel', 'pos')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at',  now()->year)->sum('total');

            $stats['total_orders'] = DB::table('orders')
                ->where('outlet_id', $outlet->id)->count();
        } catch (\Exception) {
        }

        try {
            $stats['cash_register_open'] = DB::table('cash_registers')
                ->where('outlet_id', $outlet->id)
                ->where('status', 'open')
                ->exists();
        } catch (\Exception) {
        }

        return response()->json(['statistics' => $stats]);
    }

    /**
     * Assign a user to this outlet via the outlet_user pivot.
     */
    public function assignUser(Request $request, $id)
    {
        $validated = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'is_primary' => 'sometimes|boolean',
        ]);

        $outlet = Outlet::findOrFail($id);

        DB::table('outlet_user')->updateOrInsert(
            ['outlet_id' => $outlet->id, 'user_id' => $validated['user_id']],
            ['is_primary' => $validated['is_primary'] ?? false, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['message' => 'User assigned to outlet successfully.']);
    }

    /**
     * Remove a user from this outlet.
     */
    public function removeUser(Request $request, $id)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        DB::table('outlet_user')
            ->where('outlet_id', $id)
            ->where('user_id', $validated['user_id'])
            ->delete();

        return response()->json(['message' => 'User removed from outlet.']);
    }

    /**
     * Get users assigned to this outlet.
     */
    public function users($id)
    {
        $outlet = Outlet::findOrFail($id);

        $users = DB::table('outlet_user')
            ->join('users', 'outlet_user.user_id', '=', 'users.id')
            ->where('outlet_user.outlet_id', $outlet->id)
            ->select(
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.phone',
                'users.status',
                'outlet_user.is_primary'
            )
            ->get()
            ->map(function ($u) {
                $u->name = trim("{$u->first_name} {$u->last_name}");
                return $u;
            });

        return response()->json(['data' => $users]);
    }

    /**
     * Cash register - open / close / status.
     */
    public function cashRegister(Request $request, $id)
    {
        $outlet = Outlet::findOrFail($id);

        $validated = $request->validate([
            'action'       => 'required|in:open,close,status',
            'opening_cash' => 'required_if:action,open|nullable|numeric|min:0',
            'closing_cash' => 'required_if:action,close|nullable|numeric|min:0',
            'notes'        => 'nullable|string',
        ]);

        return match ($validated['action']) {
            'open'   => $this->openCashRegister($outlet, $validated, $request->user()),
            'close'  => $this->closeCashRegister($outlet, $validated, $request->user()),
            'status' => $this->getCashRegisterStatus($outlet),
        };
    }

    private function openCashRegister($outlet, $data, $user)
    {
        $existing = DB::table('cash_registers')
            ->where('outlet_id', $outlet->id)
            ->where('status', 'open')
            ->exists();

        if ($existing) {
            return response()->json(['message' => 'Cash register is already open for this outlet.'], 422);
        }

        $id = DB::table('cash_registers')->insertGetId([
            'outlet_id'    => $outlet->id,
            'opened_by'    => $user->id,
            'opening_cash' => $data['opening_cash'],
            'total_cash'   => $data['opening_cash'],
            'status'       => 'open',
            'opened_at'    => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json([
            'message'  => 'Cash register opened.',
            'register' => DB::table('cash_registers')->find($id),
        ]);
    }

    private function closeCashRegister($outlet, $data, $user)
    {
        $register = DB::table('cash_registers')
            ->where('outlet_id', $outlet->id)
            ->where('status', 'open')
            ->orderBy('id', 'desc')
            ->first();

        if (!$register) {
            return response()->json(['message' => 'No open cash register found.'], 404);
        }

        $variance = $data['closing_cash'] - $register->total_cash;

        DB::table('cash_registers')->where('id', $register->id)->update([
            'closed_by'    => $user->id,
            'closing_cash' => $data['closing_cash'],
            'variance'     => $variance,
            'status'       => 'closed',
            'notes'        => $data['notes'] ?? null,
            'closed_at'    => now(),
            'updated_at'   => now(),
        ]);

        return response()->json([
            'message'  => 'Cash register closed.',
            'register' => DB::table('cash_registers')->find($register->id),
            'variance' => $variance,
        ]);
    }

    private function getCashRegisterStatus($outlet)
    {
        $register = DB::table('cash_registers')
            ->where('outlet_id', $outlet->id)
            ->orderBy('id', 'desc')
            ->first();

        return response()->json([
            'register' => $register,
            'is_open'  => $register && $register->status === 'open',
        ]);
    }
}