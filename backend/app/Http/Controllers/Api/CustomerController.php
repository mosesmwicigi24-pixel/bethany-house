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
use App\Support\SortResolver;
use App\Support\ServerError;

class CustomerController extends Controller
{
    /**
     * Columns a client may sort the customers list by. 'name' is handled
     * specially below — it maps to the linked user's first/last name.
     */
    private const SORTABLE_COLUMNS = [
        'name', 'created_at', 'updated_at', 'customer_number', 'customer_type',
        'loyalty_points', 'outstanding_balance', 'credit_limit', 'last_purchase_at',
    ];

    /**
     * Get all customers (Admin)
     */
    /**
     * Fields a caller WITHOUT customers.insights may see. customers.view is
     * the picker — find a person, attach them to a sale. Addresses, credit
     * limits, balances, loyalty points, tax ids and notes are the customer's
     * private and financial profile, and stay with insights holders.
     */
    private const LITE_FIELDS = [
        'id', 'customer_number', 'user_id', 'first_name', 'last_name',
        'email', 'phone', 'company', 'customer_type', 'status', 'created_at',
    ];

    /**
     * The outlet a new customer is taken on at: the outlet the request names,
     * if the caller may act there; otherwise the caller's primary (or only)
     * assigned outlet; otherwise none (head office).
     */
    private function originOutlet(Request $request): ?int
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        $named = $request->integer('outlet_id') ?: null;
        if ($named && \App\Services\DataScopeResolver::allowsOutlet($user, 'customers.view', $named)
            && \App\Models\Outlet::whereKey($named)->exists()) {
            return $named;
        }

        $assigned = $user->outlets()->orderByDesc('outlet_user.is_primary')->orderBy('outlets.id')->value('outlets.id');

        return $assigned ? (int) $assigned : null;
    }

    private function callerHasInsights(Request $request): bool
    {
        return (bool) $request->user()?->can('customers.insights');
    }

    /** Reduce a customer payload to the picker fields (plus a minimal user). */
    private function liteCustomer(Customer $c): array
    {
        $row = collect($c->toArray())->only(self::LITE_FIELDS)->all();
        if ($c->relationLoaded('user') && $c->user) {
            $row['user'] = [
                'id'         => $c->user->id,
                'first_name' => $c->user->first_name,
                'last_name'  => $c->user->last_name,
                'email'      => $c->user->email,
                'phone'      => $c->user->phone,
                'status'     => $c->user->status,
            ];
        } else {
            $row['user'] = null;
        }
        return $row;
    }

    public function index(Request $request)
    {
        $rich  = $this->callerHasInsights($request);
        // Phase 4A: scoped BEFORE any search or filter — a manager's search
        // matches inside their outlets' customers, never across the business.
        $query = Customer::visibleTo($request->user())->with($rich ? ['user', 'addresses'] : ['user']);

        // Search by name, email, or phone.
        // Handles both customers with a linked User and phone-only walk-in
        // customers (user_id IS NULL) whose data lives only on the customers table.
        // Phase 4A anti-scraping: a search obeys App\Support\CustomerSearch
        // (3 real characters, wildcards literal, 20 rows at most).
        $searching = $request->filled('search');
        if ($searching) {
            $like = \App\Support\CustomerSearch::contains(
                \App\Support\CustomerSearch::term((string) $request->search, 'search'),
            );
            $query->where(function ($q) use ($like) {
                // Match fields stored directly on the customers table
                $q->where('first_name', 'ILIKE', $like)
                  ->orWhere('last_name',  'ILIKE', $like)
                  ->orWhere('email',      'ILIKE', $like)
                  ->orWhere('phone',      'ILIKE', $like)
                  // Also match via the linked User (for portal customers)
                  ->orWhereHas('user', function ($uq) use ($like) {
                      $uq->where('first_name', 'ILIKE', $like)
                         ->orWhere('last_name',  'ILIKE', $like)
                         ->orWhere('email',      'ILIKE', $like)
                         ->orWhere('phone',      'ILIKE', $like);
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
        [$sortBy, $sortOrder] = SortResolver::resolve(
            $request->get('sort_by'),
            $request->get('sort_order', 'desc'),
            self::SORTABLE_COLUMNS,
            'created_at'
        );

        if ($sortBy === 'name') {
            $query->join('users', 'customers.user_id', '=', 'users.id')
                ->orderBy('users.first_name', $sortOrder)
                ->orderBy('users.last_name',  $sortOrder)
                ->select('customers.*');
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        // A search returns at most 20; a plain list pages at up to 100.
        $perPage   = max(1, min((int) $request->get('per_page', 20), $searching ? \App\Support\CustomerSearch::MAX_RESULTS : 100));
        $customers = $query->paginate($perPage);

        if (!$rich) {
            $customers->getCollection()->transform(
                fn (Customer $c) => $this->liteCustomer($c)
            );
        }

        return response()->json($customers);
    }

    /**
     * Get single customer (Admin)
     */
    public function show($id, Request $request)
    {
        // Without insights the profile is the picker view: identity and
        // contact only — no addresses, no spend history, no credit figures.
        if (!$this->callerHasInsights($request)) {
            $customer = Customer::visibleTo($request->user())->with('user')->findOrFail($id);

            return response()->json([
                'customer' => $this->liteCustomer($customer),
                'stats'    => null,
            ]);
        }

        $customer = Customer::visibleTo($request->user())->with(['user', 'addresses'])->findOrFail($id);

        // A customer's orders are the ones placed AS that customer
        // (customer_id), or by their web account (user_id) — one definition
        // for every door, App\Support\CustomerOrderHistory::of. It used to
        // read user_id alone, null for a walk-in, so every walk-in's history
        // showed empty while their orders sat in the table.
        $ordersQuery = \App\Support\CustomerOrderHistory::of($customer);

        // What a customer has SPENT is recognised income — Order::scopeRecognised,
        // the one definition every report in this system uses: a human accepted
        // the order or the money arrived, and the order is not dead.
        //
        // This used to count status = 'completed' alone. A till sale is
        // confirmed the moment it is paid and rarely moves on, so that matched
        // 47 of 817 orders: the page showed KES 806,915 against the reports'
        // KES 6,087,000, and read zero for customers who had bought plenty.
        // Voided and cancelled orders stay out, which is why a customer whose
        // only two orders were voided still reads zero.
        $recognised = (clone $ordersQuery)->recognised();

        $stats = [
            // Every order they placed — their history, pipeline included.
            'total_orders'        => $ordersQuery->count(),
            // The subset that is income, so the two figures can be told apart.
            'recognised_orders'   => (clone $recognised)->count(),
            // In KES at each order's own reporting rate (Step 5); the native
            // figures per currency sit beside it. Summing total_amount added
            // dollars to shillings for a customer who bought in both.
            'total_spent'         => (float) (clone $recognised)
                ->whereRaw(\App\Support\ReportingCurrency::convertibleFilter('orders.currency_code'))
                ->selectRaw('COALESCE(SUM(' . \App\Support\ReportingCurrency::kes('orders.total_amount', 'orders.currency_code') . '), 0) AS v')->value('v'),
            'average_order_value' => (float) ((clone $recognised)
                ->whereRaw(\App\Support\ReportingCurrency::convertibleFilter('orders.currency_code'))
                ->selectRaw('COALESCE(AVG(' . \App\Support\ReportingCurrency::kes('orders.total_amount', 'orders.currency_code') . '), 0) AS v')->value('v') ?? 0),
            'spent_by_currency'   => \App\Support\MoneyByCurrency::of(
                (clone $recognised)->get(['orders.total_amount', 'orders.currency_code', 'orders.reporting_rate_to_kes']),
                'total_amount', 'currency_code', 'reporting_rate_to_kes',
            )['by_currency'],
            'money_basis'         => 'KES at the reporting rate',
            'last_order_date'     => (clone $ordersQuery)
                ->latest()->value('created_at'),
            // The detail page has always rendered these three cards; nothing
            // ever sent them, so they showed blank next to the figures above.
            'online_orders'       => (clone $ordersQuery)->where('order_type', '!=', 'pos')->count(),
            'pos_orders'          => (clone $ordersQuery)->where('order_type', 'pos')->count(),
            'cancelled_orders'    => (clone $ordersQuery)->whereIn('status', Order::DEAD_STATUSES)->count(),
        ];

        // The most recent orders, newest first, on the customer object —
        // every channel (till, web, chat, invoice). At most LIMIT of them;
        // `orders_truncated` says when there were more, and the stats above
        // cover them all either way. (It was the latest 10, silently.)
        [$orders, $truncated, $total] = \App\Support\CustomerOrderHistory::recent($ordersQuery);
        $invoices = \App\Support\CustomerOrderHistory::invoiceNumbers($orders);
        $orders->each(function (Order $o) use ($invoices) {
            $o->setAttribute('channel', $o->sales_bucket ?: (Order::LEGACY_CHANNEL_MAP[$o->order_type] ?? $o->order_type));
            $o->setAttribute('invoice_number', $invoices[$o->id] ?? null);
        });
        $customer->setRelation('orders', $orders);

        return response()->json([
            // What an item COST us rides on every order line. It is not the
            // reader's to see without products.view_cost — the owner's rule
            // everywhere else (CostVisibility), and here it would hand our
            // costs to whoever, or whatever, reads a customer's history.
            'customer'         => \App\Support\CostVisibility::forViewer($customer->toArray(), $request->user()),
            'stats'            => $stats,
            'orders_total'     => $total,
            'orders_truncated' => $truncated,
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
            'phone'              => ['nullable', 'string', 'max:32', new \App\Rules\CustomerPhone()],
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
            // Where this customer was taken on — so the outlet manager who
            // adds them, before any sale, can still open them (Phase 4A).
            $customer->forceFill(['created_outlet_id' => $this->originOutlet($request)])->save();

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
            return ServerError::respond($e, 'Failed to create customer');
        }
    }

    /**
     * Update customer (Admin)
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::visibleTo($request->user())->with('user')->findOrFail($id);

        $validated = $request->validate([
            'first_name'         => 'sometimes|string|max:255',
            'last_name'          => 'sometimes|string|max:255',
            'email'              => ['sometimes', 'email', Rule::unique('users')->ignore($customer->user_id)],
            'phone'              => ['nullable', 'string', 'max:32', new \App\Rules\CustomerPhone($customer->phone)],
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
            return ServerError::respond($e, 'Failed to update customer');
        }
    }

    /**
     * Delete customer (Admin)
     */
    public function destroy($id)
    {
        $customer = Customer::visibleTo(request()->user())->with('user')->findOrFail($id);

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
            return ServerError::respond($e, 'Failed to delete customer');
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

        $customer = Customer::visibleTo($request->user())->with('user')->findOrFail($id);
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
        $customer = Customer::visibleTo(request()->user())->findOrFail($id);

        // The same "this customer's orders" as the profile (customer_id, or
        // their web account). Reading user_id alone made `user_id IS NULL`
        // for a walk-in: every OTHER walk-in's orders listed under them.
        $orders = \App\Support\CustomerOrderHistory::of($customer)->with(['items', 'payments'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(\App\Support\CostVisibility::forViewer($orders->toArray(), request()->user()));
    }

    /**
     * GET /admin/orders/by-phone?phone=<number>&since=<date>
     *
     * The orders of whoever holds this phone number — one call instead of
     * "search, then open each customer" (2026-11-24, for the chat agent).
     * Read-only.
     *
     * The number must be WHOLE (9+ digits): it is compared through
     * normalize_phone, the one rule for "the same person's number" — 0712…,
     * 254712… and +254712… are one number, a number with no country code is
     * Kenyan, and +256712… is somebody else. Matched on the customer record,
     * its web account, and the phone typed on a sale with no customer record
     * (a walk-in). Orders are read through the caller's own order scope.
     */
    public function ordersByPhone(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:32',
            'since' => 'nullable|date',
        ]);

        $phone = \App\Support\CustomerSearch::wholePhone(trim($validated['phone']));
        if ($phone === null) {
            return response()->json([
                'message' => 'Give the whole phone number (at least 9 digits).',
                'errors'  => ['phone' => ['Give the whole phone number (at least 9 digits).']],
            ], 422);
        }

        $customers = Customer::query()
            ->whereRaw('normalize_phone(customers.phone) = normalize_phone(?)', [$phone])
            ->orWhereHas('user', fn ($u) => $u->whereRaw('normalize_phone(users.phone) = normalize_phone(?)', [$phone]))
            ->orderBy('id')
            ->get(['id', 'customer_number', 'user_id', 'first_name', 'last_name', 'phone']);

        $customerIds = $customers->pluck('id')->all();
        $userIds     = $customers->pluck('user_id')->filter()->values()->all();

        $orders = Order::where(function ($q) use ($customerIds, $userIds, $phone) {
            $q->whereRaw('normalize_phone(orders.customer_phone) = normalize_phone(?)', [$phone]);
            if ($customerIds) {
                $q->orWhereIn('orders.customer_id', $customerIds);
            }
            if ($userIds) {
                $q->orWhereIn('orders.user_id', $userIds);
            }
        });

        // Stats cover every order of the number; `since` narrows the list only.
        $stats = \App\Support\CustomerOrderHistory::stats($orders);

        $listed = clone $orders;
        if (!empty($validated['since'])) {
            // Stored in Nairobi wall-clock time; the boundary stays there.
            $listed->where('orders.created_at', '>=',
                \Illuminate\Support\Carbon::parse($validated['since'], config('app.timezone')));
        }
        [$rows, $truncated, $total] = \App\Support\CustomerOrderHistory::recent($listed);
        $invoices = \App\Support\CustomerOrderHistory::invoiceNumbers($rows);

        return response()->json([
            'phone'     => DB::selectOne('SELECT normalize_phone(?) AS p', [$phone])->p,
            'customers' => $customers->map(fn (Customer $c) => [
                'id'              => $c->id,
                'customer_number' => $c->customer_number,
                'name'            => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: "Customer #{$c->id}",
                'phone'           => $c->phone,
            ])->values(),
            'orders'           => $rows->map(fn (Order $o) => \App\Support\CustomerOrderHistory::row($o, $invoices))->values(),
            'orders_total'     => $total,
            'orders_truncated' => $truncated,
            'since'            => $validated['since'] ?? null,
            'stats'            => $stats,
        ]);
    }

    // ============= Customer Address Management =============

    // ============= Wishlist Management =============
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
            'phone'      => ['nullable', 'string', 'max:32', new \App\Rules\CustomerPhone()],
            'company'    => 'nullable|string|max:255',
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
                'company'            => $validated['company'] ?? null,
                'status'             => 'active',
                'preferred_language' => 'en',
                'preferred_currency' => 'KES',
            ]);
            // Where this customer was taken on — so the outlet manager who
            // adds them, before any sale, can still open them (Phase 4A).
            $customer->forceFill(['created_outlet_id' => $this->originOutlet($request)])->save();

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
            return ServerError::respond($e, 'Failed to create customer.');
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
        $customer = Customer::visibleTo($request->user())->findOrFail($id);

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
                return ServerError::respond($e, 'Failed to create portal account.');
            }
        } else {
            $user = $customer->user;
            $user->update(['is_portal_user' => true]);
        }

        try {
            \Illuminate\Support\Facades\Password::sendResetLink(['email' => $user->email]);
        } catch (\Exception $e) {
            return ServerError::respond($e, 'Portal account ready but invite email failed to send. Please retry.');
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

    /**
     * POST /customers/export/google-contacts   (the owner account only)
     *
     * The phone book for Google Contacts (App\Support\GoogleContactsCsv):
     * one row per phone, the best name the hub knows, an empty name when it
     * knows none. Customer contacts in bulk leave the building here, so the
     * door is the narrowest in the hub (owner, 2026-10-20):
     *   - only the owner account (audit.owner_account_email) who is a super
     *     admin — any other caller is refused, super admin or not;
     *   - the password AND the current two-step code, both typed now, every
     *     time; an account without two-step sign-in cannot take it;
     *   - five wrong tries lock the door for five minutes; every refusal and
     *     every file is in the audit trail.
     */
    public function exportGoogleContacts(Request $request, \App\Services\Auth\TwoFactor $twoFactor, \App\Services\Downloads\DownloadPolicy $policy)
    {
        $user = $request->user();
        if (!$policy->isOwner($user) || !$user->hasRole('super_admin', 'sanctum')) {
            ActivityLogService::log('contacts_export_refused', $user, ['why' => 'not the owner account'], 'Google Contacts export refused: not the owner account', $user);

            return response()->json(['message' => 'Only the owner account can take the contacts file.'], 403);
        }
        if (!$user->two_factor_enabled) {
            return response()->json(['message' => 'Two-step sign-in must be on for this account before the contacts file can be taken.'], 403);
        }

        $validated = $request->validate([
            'password' => 'required|string|max:255',
            'code'     => 'required|string|size:6',
        ]);

        $key = 'contacts-export-attempts:' . $user->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many attempts. Wait a few minutes and try again.',
                'retry_after_seconds' => \Illuminate\Support\Facades\RateLimiter::availableIn($key)], 429);
        }
        $passwordOk = \Illuminate\Support\Facades\Hash::check($validated['password'], $user->password);
        $codeOk     = $passwordOk && $twoFactor->verifyCode($user, $validated['code']);
        if (!$passwordOk || !$codeOk) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 300);
            ActivityLogService::log('contacts_export_refused', $user, ['why' => $passwordOk ? 'wrong code' : 'wrong password'],
                'Google Contacts export refused: ' . ($passwordOk ? 'wrong two-step code' : 'wrong password'), $user);
            $field = $passwordOk ? 'code' : 'password';

            return response()->json(['message' => $passwordOk ? 'That code is not right.' : 'That password is not right.',
                'errors' => [$field => [$passwordOk ? 'That code is not right.' : 'That password is not right.']]], 422);
        }
        \Illuminate\Support\Facades\RateLimiter::clear($key);

        $built = \App\Support\GoogleContactsCsv::build();
        $csv   = \App\Support\GoogleContactsCsv::csv($built['rows']);
        $name  = 'bethany-house-google-contacts-' . now(config('app.timezone'))->format('Ymd-His') . '.csv';

        ActivityLogService::log('contacts_export_taken', $user, $built['stats'] + ['file' => $name],
            "Google Contacts file taken: {$built['stats']['contacts']} contacts ({$built['stats']['unnamed']} without a name)", $user);

        return response($csv, 200, [
            'Content-Type'          => 'text/csv; charset=UTF-8',
            'Content-Disposition'   => 'attachment; filename="' . $name . '"',
            'X-Contacts'            => (string) $built['stats']['contacts'],
            'X-Contacts-Unnamed'    => (string) $built['stats']['unnamed'],
            'X-Contacts-Merged'     => (string) $built['stats']['merged_spellings'],
            'Cache-Control'         => 'no-store',
        ]);
    }
}