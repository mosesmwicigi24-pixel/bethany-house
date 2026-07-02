<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * Get all customers (Admin)
     */
    public function index(Request $request)
    {
        $query = Customer::with(['user', 'addresses']);

        // Search by name, email, or phone.
        // Handles both customers with a linked User and phone-only walk-in
        // customers (user_id IS NULL) whose data lives only on the customers table.
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                // Match fields stored directly on the customers table
                $q->where('first_name', 'ILIKE', "%{$search}%")
                  ->orWhere('last_name',  'ILIKE', "%{$search}%")
                  ->orWhere('email',      'ILIKE', "%{$search}%")
                  ->orWhere('phone',      'ILIKE', "%{$search}%")
                  // Also match via the linked User (for portal customers)
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('first_name', 'ILIKE', "%{$search}%")
                         ->orWhere('last_name',  'ILIKE', "%{$search}%")
                         ->orWhere('email',      'ILIKE', "%{$search}%")
                         ->orWhere('phone',      'ILIKE', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('status', $request->status);
            });
        }

        // Filter by customer type
        if ($request->has('type')) {
            $query->where('customer_type', $request->type);
        }

        // Sort by various criteria
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        
        if ($sortBy === 'name') {
            $query->join('users', 'customers.user_id', '=', 'users.id')
                ->orderBy('users.first_name', $sortOrder)
                ->orderBy('users.last_name',  $sortOrder)
                ->select('customers.*');
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 20);
        $customers = $query->paginate($perPage);

        return response()->json($customers);
    }

    /**
     * Get single customer (Admin)
     */
    public function show($id)
    {
        $customer = Customer::with(['user', 'addresses'])->findOrFail($id);

        // Orders link via user_id, not customer_id - query directly
        $ordersQuery = Order::where('user_id', $customer->user_id);

        $stats = [
            'total_orders'       => $ordersQuery->count(),
            'total_spent'        => (clone $ordersQuery)
                ->where('status', 'completed')
                ->sum('total_amount'),
            'average_order_value'=> (clone $ordersQuery)
                ->where('status', 'completed')
                ->avg('total_amount') ?? 0,
            'last_order_date'    => (clone $ordersQuery)
                ->latest()->value('created_at'),
        ];

        // Attach recent orders to the customer object for the frontend
        $customer->setRelation(
            'orders',
            $ordersQuery->with(['items'])->latest()->limit(10)->get()
        );

        return response()->json([
            'customer' => $customer,
            'stats'    => $stats,
        ]);
    }

    /**
     * Create customer (Admin)
     *
     * Email is optional when creating from the admin side. If no email is
     * supplied, a User record is NOT created - only a Customer record.
     * This supports walk-in / phone-only customers raised during order or
     * production-order intake.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'         => 'required|string|max:255',
            'last_name'          => 'required|string|max:255',
            // Email optional - unique across users only when present
            'email'              => 'nullable|email|unique:users,email|max:255',
            'phone'              => 'nullable|string|max:20',
            'type'               => 'sometimes|in:individual,business',
            'company_name'       => 'required_if:type,business|nullable|string|max:255',
            'tax_number'         => 'nullable|string|max:50',
            'preferred_language' => 'nullable|in:en,fr,pt',
            'preferred_currency' => 'nullable|in:KES,USD',
            'notes'              => 'nullable|string',
        ]);

        // At least one contact method is required so we can identify the customer
        if (empty($validated['email']) && empty($validated['phone'])) {
            return response()->json([
                'message' => 'At least one of email or phone is required.',
                'errors'  => ['email' => ['Provide an email or phone number.']],
            ], 422);
        }

        DB::beginTransaction();
        try {
            $userId = null;

            if (!empty($validated['email'])) {
                // Email present - create a User account so they can log in via portal
                $user = User::create([
                    'first_name'  => $validated['first_name'],
                    'last_name'   => $validated['last_name'],
                    'email'       => $validated['email'],
                    'phone'       => $validated['phone'] ?? null,
                    'password'    => bcrypt(\Illuminate\Support\Str::random(32)),
                    'status'      => 'active',
                    'is_portal_user' => true,
                    // user_type auto-set to CUSTOMER by User::boot()
                ]);
                $userId = $user->id;

                // Non-fatal - send portal activation link
                try {
                    \Illuminate\Support\Facades\Password::sendResetLink(['email' => $user->email]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Could not send password reset to new customer', [
                        'email' => $user->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Create customer profile (user_id nullable for phone-only walk-in customers)
            $customer = Customer::create([
                'user_id'            => $userId,
                'first_name'         => $validated['first_name'],
                'last_name'          => $validated['last_name'],
                'email'              => $validated['email'] ?? null,
                'phone'              => $validated['phone'] ?? null,
                'customer_type'      => $validated['type'] ?? 'individual',
                'company'            => $validated['company_name'] ?? null,
                'tax_id'             => $validated['tax_number'] ?? null,
                'preferred_language' => $validated['preferred_language'] ?? 'en',
                'preferred_currency' => $validated['preferred_currency'] ?? 'KES',
                'status'             => 'active',
                'notes'              => $validated['notes'] ?? null,
            ]);

            DB::commit();

            try {
                ActivityLogService::log('customer_created', $customer, [
                    'customer_number' => $customer->customer_number,
                    'name'            => $customer->first_name . ' ' . $customer->last_name,
                    'email'           => $customer->email,
                    'phone'           => $customer->phone,
                    'type'            => $customer->customer_type,
                    'has_portal_user' => !is_null($userId),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Customer created successfully',
                'customer' => $customer->load('user'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create customer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update customer (Admin)
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::with('user')->findOrFail($id);

        $validated = $request->validate([
            'first_name'         => 'sometimes|string|max:255',
            'last_name'          => 'sometimes|string|max:255',
            'email'              => ['sometimes', 'email', Rule::unique('users')->ignore($customer->user_id)],
            'phone'              => 'nullable|string|max:20',
            'type'               => 'sometimes|in:individual,business',
            'company_name'       => 'nullable|string|max:255',
            'tax_number'         => 'nullable|string|max:50',
            'preferred_language' => 'nullable|in:en,fr,pt',
            'preferred_currency' => 'nullable|in:KES,USD',
            'notes'              => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Update user
            $userUpdates = array_filter([
                'first_name' => $validated['first_name'] ?? null,
                'last_name'  => $validated['last_name'] ?? null,
                'email'      => $validated['email'] ?? null,
                'phone'      => $validated['phone'] ?? null,
            ], fn($v) => $v !== null);
            if (!empty($userUpdates)) {
                $customer->user->update($userUpdates);
            }

            // Update customer - use real column names
            $customerUpdates = array_filter([
                'first_name'         => $validated['first_name'] ?? null,
                'last_name'          => $validated['last_name'] ?? null,
                'email'              => $validated['email'] ?? null,
                'phone'              => $validated['phone'] ?? null,
                'customer_type'      => $validated['type'] ?? null,
                'company'            => $validated['company_name'] ?? null,
                'tax_id'             => $validated['tax_number'] ?? null,
                'preferred_language' => $validated['preferred_language'] ?? null,
                'preferred_currency' => $validated['preferred_currency'] ?? null,
                'notes'              => $validated['notes'] ?? null,
            ], fn($v) => $v !== null);
            if (!empty($customerUpdates)) {
                $customer->update($customerUpdates);
            }

            DB::commit();

            try {
                ActivityLogService::log('customer_updated', $customer, [
                    'customer_id'     => $customer->id,
                    'customer_number' => $customer->customer_number,
                    'changes'         => array_keys(array_merge($userUpdates ?? [], $customerUpdates ?? [])),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Customer updated successfully',
                'customer' => $customer->load('user'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update customer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete customer (Admin)
     */
    public function destroy($id)
    {
        $customer = Customer::with('user')->findOrFail($id);

        // Check if customer has orders
        if ($customer->orders()->exists()) {
            return response()->json([
                'message' => 'Cannot delete customer with existing orders. Consider deactivating instead.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $user = $customer->user;
            $customerName = $customer->first_name . ' ' . $customer->last_name;
            $customerEmail = $customer->email;
            $customerId = $customer->id;
            $customerNumber = $customer->customer_number;
            $customer->delete();
            $user->delete();

            DB::commit();

            try {
                ActivityLogService::log('customer_deleted', null, [
                    'customer_id'     => $customerId,
                    'customer_number' => $customerNumber,
                    'name'            => $customerName,
                    'email'           => $customerEmail,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Customer deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete customer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update customer status (Admin)
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,suspended',
        ]);

        $customer = Customer::with('user')->findOrFail($id);
        $oldStatus = $customer->user->status ?? null;
        $customer->user->update(['status' => $validated['status']]);

        try {
            ActivityLogService::log('customer_status_changed', $customer, [
                'customer_id'     => $customer->id,
                'customer_number' => $customer->customer_number,
                'new_status'      => $validated['status'],
            ]);
        } catch (\Exception) {}

        // Notify the customer if they have a user account
        try {
            if ($customer->user_id) {
                if ($validated['status'] === 'suspended') {
                    NotificationService::userSuspended($customer->user_id);
                } elseif ($validated['status'] === 'active' && $oldStatus !== 'active') {
                    NotificationService::accountReactivated($customer->user_id);
                }
            }
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Customer status updated successfully',
            'customer' => $customer->load('user'),
        ]);
    }

    /**
     * Get customer orders (Admin)
     */
    /**
     * Alias for backwards compatibility - old api.php routed to 'orders()'
     */
    public function orders($id)
    {
        return $this->customerOrders($id);
    }

    public function customerOrders($id)
    {
        $customer = Customer::findOrFail($id);

        $orders = Order::with(['items', 'payments'])
            ->where('user_id', $customer->user_id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($orders);
    }

    // ============= Customer Address Management =============

    /**
     * Get customer addresses
     */
    public function addresses(Request $request)
    {
        $customer = Customer::where('user_id', $request->user()->id)->first();

        if (!$customer) {
            return response()->json(['message' => 'Customer profile not found'], 404);
        }

        $addresses = Address::where('customer_id', $customer->id)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($addresses);
    }

    /**
     * Store new address
     */
    public function storeAddress(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'required|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'required|string|max:20',
            'is_default' => 'boolean',
        ]);

        $customer = $request->user()->customer;

        if (!$customer) {
            return response()->json([
                'message' => 'Customer profile not found',
            ], 404);
        }

        DB::beginTransaction();
        try {
            // If setting as default, unset other defaults
            if ($validated['is_default'] ?? false) {
                Address::where('customer_id', $customer->id)
                    ->update(['is_default' => false]);
            }

            $address = Address::create([
                'customer_id' => $customer->id,
                'name' => $validated['name'],
                'address_line_1' => $validated['address_line_1'],
                'address_line_2' => $validated['address_line_2'] ?? null,
                'city' => $validated['city'],
                'state' => $validated['state'] ?? null,
                'country' => $validated['country'],
                'postal_code' => $validated['postal_code'] ?? null,
                'phone' => $validated['phone'],
                'is_default' => $validated['is_default'] ?? false,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Address added successfully',
                'address' => $address,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update address
     */
    public function updateAddress(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address_line_1' => 'sometimes|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'sometimes|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'sometimes|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'sometimes|string|max:20',
            'is_default' => 'boolean',
        ]);

        $customer = $request->user()->customer;

        $address = Address::where('customer_id', $customer->id)
            ->where('id', $id)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            // If setting as default, unset other defaults
            if (isset($validated['is_default']) && $validated['is_default']) {
                Address::where('customer_id', $customer->id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Address updated successfully',
                'address' => $address,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete address
     */
    public function deleteAddress($id)
    {
        $customer = request()->user()->customer;

        $address = Address::where('customer_id', $customer->id)
            ->where('id', $id)
            ->firstOrFail();

        $address->delete();

        return response()->json([
            'message' => 'Address deleted successfully',
        ]);
    }

    // ============= Wishlist Management =============

    /**
     * Get customer wishlist
     */
    public function wishlist(Request $request)
    {
        $customer = $request->user()->customer;

        $wishlist = DB::table('wishlists')
            ->join('products', 'wishlists.product_id', '=', 'products.id')
            ->where('wishlists.customer_id', $customer->id)
            ->select('wishlists.*', 'products.*')
            ->get();

        return response()->json($wishlist);
    }

    /**
     * Add product to wishlist
     */
    public function addToWishlist($productId)
    {
        $customer = request()->user()->customer;

        $exists = DB::table('wishlists')
            ->where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Product already in wishlist',
            ], 422);
        }

        DB::table('wishlists')->insert([
            'customer_id' => $customer->id,
            'product_id' => $productId,
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Product added to wishlist',
        ], 201);
    }

    /**
     * Remove product from wishlist
     */
    public function removeFromWishlist($productId)
    {
        $customer = request()->user()->customer;

        DB::table('wishlists')
            ->where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->delete();

        return response()->json([
            'message' => 'Product removed from wishlist',
        ]);
    }
    // =========================================================================
    // POST /admin/customers/quick-create
    //
    // Lightweight inline creation used during order / production-order intake.
    // Returns the minimum customer data needed to link to the new order.
    // =========================================================================

    public function quickCreate(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'nullable|email|unique:users,email|max:255',
            'phone'      => 'nullable|string|max:20',
        ]);

        if (empty($validated['email']) && empty($validated['phone'])) {
            return response()->json([
                'message' => 'At least one of email or phone is required.',
                'errors'  => ['email' => ['Provide an email or phone number.']],
            ], 422);
        }

        DB::beginTransaction();
        try {
            $userId = null;

            if (!empty($validated['email'])) {
                $user = User::create([
                    'first_name'     => $validated['first_name'],
                    'last_name'      => $validated['last_name'],
                    'email'          => $validated['email'],
                    'phone'          => $validated['phone'] ?? null,
                    'password'       => bcrypt(\Illuminate\Support\Str::random(32)),
                    'status'         => 'active',
                    'is_portal_user' => true,
                ]);
                $userId = $user->id;

                try {
                    \Illuminate\Support\Facades\Password::sendResetLink(['email' => $user->email]);
                } catch (\Exception $e) {
                    Log::warning('Could not send reset link to quick-created customer', [
                        'email' => $user->email, 'error' => $e->getMessage(),
                    ]);
                }
            }

            $customer = Customer::create([
                'user_id'            => $userId,
                'first_name'         => $validated['first_name'],
                'last_name'          => $validated['last_name'],
                'email'              => $validated['email'] ?? null,
                'phone'              => $validated['phone'] ?? null,
                'status'             => 'active',
                'preferred_language' => 'en',
                'preferred_currency' => 'KES',
            ]);

            DB::commit();

            try {
                ActivityLogService::log('customer_created', $customer, [
                    'customer_number' => $customer->customer_number,
                    'name'            => $customer->first_name . ' ' . $customer->last_name,
                    'email'           => $customer->email,
                    'phone'           => $customer->phone,
                    'source'          => 'quick_create',
                    'has_portal_user' => !is_null($userId),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'  => 'Customer created successfully.',
                'customer' => [
                    'id'              => $customer->id,
                    'first_name'      => $customer->first_name,
                    'last_name'       => $customer->last_name,
                    'email'           => $customer->email,
                    'phone'           => $customer->phone,
                    'customer_number' => $customer->customer_number,
                    'is_portal_user'  => !is_null($userId),
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create customer.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // POST /admin/customers/{id}/invite-to-portal
    //
    // Sends a portal activation link to a phone-only customer who now has an
    // email address. Creates a User record if one does not yet exist.
    // =========================================================================

    public function inviteToPortal(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        if (empty($customer->email)) {
            return response()->json([
                'message' => 'Customer does not have an email address. Please add one before sending the invite.',
            ], 422);
        }

        // Create a User record if one does not exist yet
        if (is_null($customer->user_id)) {
            DB::beginTransaction();
            try {
                // Check if a user with this email already exists (e.g. was created
                // as a staff member or via another flow) — link them instead of
                // creating a duplicate, which would violate the unique email constraint.
                $user = User::where('email', $customer->email)->first();

                if ($user) {
                    // Existing user found — just mark as portal user and link
                    $user->update(['is_portal_user' => true]);
                } else {
                    $user = User::create([
                        'first_name'     => $customer->first_name,
                        'last_name'      => $customer->last_name,
                        'email'          => $customer->email,
                        'phone'          => $customer->phone,
                        'password'       => bcrypt(\Illuminate\Support\Str::random(32)),
                        'status'         => 'active',
                        'is_portal_user' => true,
                    ]);
                }

                $customer->update(['user_id' => $user->id]);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Failed to create portal account.',
                    'error'   => $e->getMessage(),
                ], 500);
            }
        } else {
            $user = $customer->user;
            $user->update(['is_portal_user' => true]);
        }

        try {
            \Illuminate\Support\Facades\Password::sendResetLink(['email' => $user->email]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Portal account ready but invite email failed to send. Please retry.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        try {
            ActivityLogService::log('customer_portal_invited', $customer, [
                'customer_id'     => $customer->id,
                'customer_number' => $customer->customer_number,
                'email'           => $user->email,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Portal invite sent to ' . $user->email,
        ]);
    }
}