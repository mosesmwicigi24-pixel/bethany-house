<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * List users with filters, sort and pagination.
     * React admin UsersPage expects Laravel paginated response shape.
     */
    public function index(Request $request)
    {
        $query = User::with(['roles', 'outlets']);

        // ── Filters ───────────────────────────────────────────────────────

        // Filter by Spatie role name (replaces old users.role varchar column)
        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->role));
        }

        // Filter by user_type enum (system | staff | customer)
        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }

        // Exclude a specific user_type - e.g. exclude_type=customer to get only staff+system
        if ($request->filled('exclude_type')) {
            $query->where('user_type', '!=', $request->exclude_type);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by outlet (via outlet_user pivot - no outlet_id column on users)
        if ($request->filled('outlet_id')) {
            $query->whereHas('outlets', fn ($q) => $q->where('outlets.id', $request->outlet_id));
        }

        // Search - model uses first_name + last_name, no single 'name' column
        // ILIKE = case-insensitive LIKE in PostgreSQL
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ILIKE', "%{$search}%")
                  ->orWhere('last_name',  'ILIKE', "%{$search}%")
                  ->orWhere('email',      'ILIKE', "%{$search}%")
                  ->orWhere('phone',      'ILIKE', "%{$search}%");
            });
        }

        // ── Sort ──────────────────────────────────────────────────────────

        $allowedSorts = ['first_name', 'last_name', 'email', 'created_at', 'status', 'user_type'];
        $sortBy    = in_array($request->get('sort_by'), $allowedSorts)
            ? $request->get('sort_by')
            : 'created_at';
        $sortOrder = $request->get('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortOrder);

        // ── Paginate ──────────────────────────────────────────────────────

        $perPage = min((int) $request->get('per_page', 20), 100);
        $users   = $query->paginate($perPage);

        // Append primaryOutlet() per user so the React admin shows outlet name
        // without a second request (outlet is via pivot, not a direct FK column)
        $users->getCollection()->transform(function ($user) {
            $user->outlet = $user->primaryOutlet();
            return $user;
        });

        return response()->json($users);
    }

    /**
     * Get single user with stats and recent activity.
     */
    public function show($id)
    {
        $user = User::with(['roles', 'outlets'])->findOrFail($id);
        $user->outlet = $user->primaryOutlet();

        $stats = [];

        // POS-related stats - check Spatie role names instead of users.role column
        if ($user->hasAnyRole(['pos_clerk', 'outlet_manager'])) {
            $stats['sales_count'] = Order::where('created_by', $user->id)
                ->where('channel', 'pos')
                ->count();

            $stats['sales_total'] = Order::where('created_by', $user->id)
                ->where('channel', 'pos')
                ->where('payment_status', 'paid')
                ->sum('total');

            $stats['today_sales'] = Order::where('created_by', $user->id)
                ->where('channel', 'pos')
                ->whereDate('created_at', today())
                ->count();
        }

        if ($user->hasRole('tailor')) {
            $stats['assigned_tasks'] = DB::table('production_orders')
                ->where('assigned_to', $user->id)
                ->whereIn('status', ['assigned', 'in_progress'])
                ->count();

            $stats['completed_tasks'] = DB::table('production_orders')
                ->where('assigned_to', $user->id)
                ->where('status', 'completed')
                ->count();
        }

        // Recent activity - graceful fallback if table doesn't exist
        $recentActivity = [];
        try {
            $recentActivity = DB::table('activity_log')
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        } catch (\Exception) {
            // activity_log table not yet migrated - ignore
        }

        return response()->json([
            'user'            => $user,
            'stats'           => $stats,
            'recent_activity' => $recentActivity,
        ]);
    }

    /**
     * Create a new admin/staff user.
     * React admin UsersPage sends: first_name, last_name, email, password,
     * user_type, status, role_ids[], outlet_id, must_setup_2fa
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'           => 'required|string|max:255',
            'last_name'            => 'required|string|max:255',
            'email'                => 'required|email|unique:users,email|max:255',
            'password'             => 'required|string|min:8|confirmed',
            'phone'                => 'nullable|string|max:20',
            'user_type'            => 'sometimes|in:system,staff,customer',
            'status'               => 'sometimes|in:active,inactive',
            'role_ids'             => 'sometimes|array',
            'role_ids.*'           => 'integer|exists:roles,id',
            'outlet_id'            => 'nullable|exists:outlets,id',
            'must_setup_2fa'       => 'sometimes|boolean',
            'send_welcome_email'   => 'sometimes|boolean',
        ]);

        // Create the user — no wrapping transaction; DB::beginTransaction() was
        // causing silent rollbacks in PostgreSQL due to a nested transaction issue.
        try {
            $user = User::create([
                'first_name'     => $validated['first_name'],
                'last_name'      => $validated['last_name'],
                'email'          => $validated['email'],
                'password'       => Hash::make($validated['password']),
                'phone'          => $validated['phone'] ?? null,
                'user_type'      => $validated['user_type'] ?? 'staff',
                'status'         => $validated['status'] ?? 'active',
                'must_setup_2fa' => $validated['must_setup_2fa'] ?? false,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create user', 'error' => $e->getMessage()], 500);
        }

        // Assign roles — if this fails, clean up and report
        if (!empty($validated['role_ids'])) {
            try {
                $this->syncUserRoles($user->id, $validated['role_ids']);
            } catch (\Exception $e) {
                $user->forceDelete();
                return response()->json(['message' => 'Failed to assign roles', 'error' => $e->getMessage()], 500);
            }
        }

        // Assign to outlet
        if (!empty($validated['outlet_id'])) {
            try {
                $user->outlets()->syncWithoutDetaching([
                    $validated['outlet_id'] => ['is_primary' => true],
                ]);
            } catch (\Exception) {}
        }

        // Create customer profile if user_type is customer
        if (($validated['user_type'] ?? 'staff') === 'customer') {
            try {
                \App\Models\Customer::create([
                    'user_id'            => $user->id,
                    'preferred_language' => 'en',
                    'preferred_currency' => 'KES',
                ]);
            } catch (\Exception) {}
        }

        // Post-create side-effects — failures must never affect the response
        try { $this->logActivity($request, 'user_created', "Created user: {$user->name} ({$user->email})"); } catch (\Exception) {}
        try { NotificationService::userWelcome($user->id, $user->first_name); } catch (\Exception) {}
        try { ActivityLogService::logCreated($user, $request->user()); } catch (\Exception) {}

        $user->load(['roles', 'outlets']);
        $user->outlet = $user->primaryOutlet();

        return response()->json([
            'message' => 'User created successfully',
            'user'    => $user,
        ], 201);
    }

    /**
     * Update a user.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'first_name'           => 'sometimes|string|max:255',
            'last_name'            => 'sometimes|string|max:255',
            'email'                => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone'                => 'nullable|string|max:20',
            'status'               => 'sometimes|in:active,inactive,suspended',
            'user_type'            => 'sometimes|in:system,staff,customer',
            'must_setup_2fa'       => 'sometimes|boolean',
            'role_ids'             => 'sometimes|array',
            'role_ids.*'           => 'integer|exists:roles,id',
            'outlet_id'            => 'nullable|exists:outlets,id',
            'password'             => 'sometimes|string|min:8',
            'password_confirmation'=> 'required_with:password|same:password',
        ]);

        DB::beginTransaction();
        try {
            // Update scalar fields
            $updates = array_filter([
                'first_name'     => $validated['first_name'] ?? null,
                'last_name'      => $validated['last_name'] ?? null,
                'email'          => $validated['email'] ?? null,
                'phone'          => $validated['phone'] ?? null,
                'status'         => $validated['status'] ?? null,
                'user_type'      => $validated['user_type'] ?? null,
                'must_setup_2fa' => $validated['must_setup_2fa'] ?? null,
            ], fn ($v) => $v !== null);

            // Hash new password if provided
            if (!empty($validated['password'])) {
                $updates['password'] = Hash::make($validated['password']);
                // Invalidate all existing sessions when password changes
                DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();
            }

            $user->update($updates);

            // Update outlet assignment
            if (array_key_exists('outlet_id', $validated)) {
                if ($validated['outlet_id']) {
                    $user->outlets()->detach();
                    $user->outlets()->attach($validated['outlet_id'], ['is_primary' => true]);
                } else {
                    $user->outlets()->detach();
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update user', 'error' => $e->getMessage()], 500);
        }

        // ── Role sync - runs OUTSIDE the transaction ──────────────────────────
        // Keeping it inside the transaction caused rollback when logActivity()
        // threw an exception (activity_log table missing), wiping the role insert.
        if (isset($validated['role_ids'])) {
            $this->syncUserRoles($user->id, $validated['role_ids']);
        }

        // Log activity - outside transaction so exceptions don't roll back data
        $this->logActivity($request, 'user_updated', "Updated user: {$user->name}");
        // Phase 3 - structured audit log
        ActivityLogService::log('updated', $user, ['changes' => array_keys($updates ?? [])], null, $request->user());

        // Reload fresh from DB - bypass Spatie's permission cache
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::with(['roles', 'outlets'])->find($user->id);
        $user->outlet = $user->primaryOutlet();

        return response()->json([
            'message' => 'User updated successfully',
            'user'    => $user,
        ]);
    }

    /**
     * Update user role (legacy single-role endpoint).
     */
    public function updateRole(Request $request, $id)
    {
        $validated = $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        $user = User::findOrFail($id);

        // Prevent removing your own super_admin role
        if ($user->id === $request->user()->id &&
            $user->hasRole('super_admin') &&
            $validated['role'] !== 'super_admin') {
            return response()->json(['message' => 'Cannot remove your own super admin role.'], 422);
        }

        // Find the role by name and sync via direct pivot
        $role = DB::table('roles')->where('name', $validated['role'])->first();
        if ($role) {
            $this->syncUserRoles($user->id, [$role->id]);
        }

        $this->logActivity($request, 'user_role_changed', "Changed {$user->name}'s role to {$validated['role']}");
        // Phase 3 - structured audit log
        ActivityLogService::log('role_changed', $user, ['new_role' => $validated['role']], null, $request->user());

        return response()->json([
            'message' => 'User role updated successfully',
            'user'    => $user->load('roles'),
        ]);
    }

    /**
     * Update user status.
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,suspended',
            'reason' => 'nullable|string',
        ]);

        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot change your own account status.'], 422);
        }

        $oldStatus = $user->status;
        $user->update(['status' => $validated['status']]);

        // Revoke all tokens if suspending
        if ($validated['status'] === 'suspended') {
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();
        }

        // Notify the affected user of meaningful status changes
        try {
            if ($validated['status'] === 'suspended') {
                NotificationService::userSuspended($user->id, $validated['reason'] ?? '');
            } elseif ($validated['status'] === 'active' && $oldStatus !== 'active') {
                NotificationService::accountReactivated($user->id);
            }
        } catch (\Exception) {}

        $reason = isset($validated['reason']) ? ". Reason: {$validated['reason']}" : '';
        $this->logActivity($request, 'user_status_changed',
            "Changed {$user->name}'s status from {$oldStatus} to {$validated['status']}{$reason}");
        // Phase 3 - structured audit log
        ActivityLogService::log('status_changed', $user, [
            'old_status' => $oldStatus,
            'new_status' => $validated['status'],
        ], null, $request->user());

        return response()->json([
            'message' => 'User status updated successfully',
            'user'    => $user,
        ]);
    }

    /**
     * Reset user password (admin-initiated).
     * React admin sends a password reset email - no new password required.
     */
    public function resetPassword(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Send password reset link via Laravel's built-in broker
        $status = \Illuminate\Support\Facades\Password::sendResetLink(['email' => $user->email]);

        // Invalidate existing tokens so they must log in fresh
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();

        $this->logActivity($request, 'password_reset', "Sent password reset link to {$user->name}");
        // Phase 3 - structured audit log
        ActivityLogService::log('password_reset_sent', $user, ['target_email' => $user->email], null, $request->user());

        return response()->json([
            'message' => $status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT
                ? 'Password reset link sent to ' . $user->email
                : 'Could not send reset link. Please check the email address.',
        ]);
    }

    /**
     * Delete a user (with safety checks).
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->id === request()->user()->id) {
            return response()->json(['message' => 'Cannot delete your own account.'], 422);
        }

        if ($user->hasRole('super_admin')) {
            return response()->json(['message' => 'Cannot delete a super admin account.'], 422);
        }

        // Look up customer profile directly — User model has no customer relationship
        $customer = \App\Models\Customer::where('user_id', $user->id)->first();

        if ($customer && $customer->orders()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a user with order history. Deactivate instead.',
            ], 422);
        }

        if (Order::where('created_by', $user->id)->exists()) {
            return response()->json([
                'message' => 'Cannot delete a user who created orders. Deactivate instead.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Delete customer profile if exists
            if ($customer) {
                $customer->delete();
            }

            // 2. Detach outlets
            $user->outlets()->detach();

            // 3. Clear roles via direct pivot — avoids guard mismatch
            DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->delete();

            // 4. Revoke all tokens
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->delete();

            // 5. Soft-delete the user
            $user->delete();

            DB::commit();

            // Post-commit logging — outside transaction so failures don't affect the delete
            try { $this->logActivity(request(), 'user_deleted', "Deleted user: {$user->name} ({$user->email})"); } catch (\Exception) {}
            try { ActivityLogService::logDeleted($user); } catch (\Exception) {}

            return response()->json(['message' => 'User deleted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete user',
                'error'   => $e->getMessage(),
                'step'    => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }
    }

    /**
     * Get user activity log.
     */
    public function activityLog(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $query = DB::table('activity_log')->where('user_id', $user->id);

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate(50)
        );
    }

    /**
     * Get users by Spatie role name.
     */
    public function byRole($role)
    {
        $users = User::whereHas('roles', fn ($q) => $q->where('name', $role))
            ->where('status', 'active')
            ->select('id', 'first_name', 'last_name', 'email', 'phone')
            ->get()
            ->map(function ($user) {
                $user->outlet = $user->primaryOutlet();
                return $user;
            });

        return response()->json(['data' => $users]);
    }

    /**
     * Bulk status update.
     */
    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'user_ids'   => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'status'     => 'required|in:active,inactive,suspended',
        ]);

        // Never update your own account
        $userIds = array_values(array_filter(
            $validated['user_ids'],
            fn ($id) => $id != $request->user()->id
        ));

        if (empty($userIds)) {
            return response()->json(['message' => 'No valid users to update.'], 422);
        }

        User::whereIn('id', $userIds)->update(['status' => $validated['status']]);

        if ($validated['status'] === 'suspended') {
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->delete();
            try {
                NotificationService::bulkUsersSuspended($userIds);
            } catch (\Exception) {}
        }

        $this->logActivity($request, 'bulk_status_update',
            "Updated status to {$validated['status']} for " . count($userIds) . ' users');

        return response()->json([
            'message'       => 'Users updated successfully',
            'updated_count' => count($userIds),
        ]);
    }

    /**
     * Export users as JSON (for CSV generation on the frontend).
     */
    public function export(Request $request)
    {
        $query = User::with(['roles', 'outlets']);

        if ($request->filled('user_type')) {
            $query->where('user_type', $request->user_type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->get()->map(function ($user) {
            return [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'user_type'  => $user->user_type,
                'roles'      => $user->roles->pluck('name')->implode(', '),
                'status'     => $user->status,
                'outlet'     => $user->primaryOutlet()?->name,
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'message' => 'Export ready',
            'count'   => $users->count(),
            'data'    => $users,
        ]);
    }

    /**
     * Get Spatie permissions for a user (flattened from their roles).
     */
    public function permissions($id)
    {
        $user = User::with('roles.permissions')->findOrFail($id);

        $permissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')
            ->unique()
            ->values();

        return response()->json([
            'user'        => $user->only(['id', 'name', 'email']),
            'permissions' => $permissions,
        ]);
    }

    /**
     * Promote a customer-type user to staff.
     *
     * - Switches user_type  'customer' → 'staff'
     * - Assigns the chosen staff role(s) via the direct pivot writer
     * - Optionally assigns an outlet
     * - Detaches the linked Customer profile's user_id so the customer record
     *   becomes a standalone walk-in record (all order history is preserved)
     * - Revokes all existing Sanctum tokens so the user must log in fresh
     *   (the next admin login will now pass canAccessAdmin())
     *
     * POST /v1/admin/users/{id}/promote-to-staff
     * Body: { role_ids: int[], outlet_id?: int|null }
     */
    public function promoteToStaff(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->user_type->value !== 'customer') {
            return response()->json([
                'message' => 'User is already a staff or system user.',
            ], 422);
        }

        $validated = $request->validate([
            'role_ids'   => 'required|array|min:1',
            'role_ids.*' => 'integer|exists:roles,id',
            'outlet_id'  => 'nullable|exists:outlets,id',
        ]);

        DB::beginTransaction();
        try {
            // 1. Switch user_type to staff
            $user->update(['user_type' => 'staff']);

            // 2. Detach linked customer profile — nulls user_id, keeps all order history
            \App\Models\Customer::where('user_id', $user->id)
                ->update(['user_id' => null]);

            // 3. Assign outlet if provided
            if (!empty($validated['outlet_id'])) {
                $user->outlets()->detach();
                $user->outlets()->attach($validated['outlet_id'], ['is_primary' => true]);
            }

            // 4. Revoke existing tokens — forces fresh login as staff
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->delete();

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to promote user.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        // 5. Sync roles outside transaction (matches pattern in update())
        $this->syncUserRoles($user->id, $validated['role_ids']);

        // 6. Audit log
        try {
            $this->logActivity(
                $request,
                'user_promoted_to_staff',
                "Promoted customer {$user->name} ({$user->email}) to staff"
            );
            ActivityLogService::log('promoted_to_staff', $user, [
                'role_ids'  => $validated['role_ids'],
                'outlet_id' => $validated['outlet_id'] ?? null,
            ], null, $request->user());
        } catch (\Exception) {}

        // 7. Return fresh user with updated roles and outlet
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::with(['roles', 'outlets'])->find($user->id);
        $user->outlet = $user->primaryOutlet();

        return response()->json([
            'message' => "{$user->first_name} {$user->last_name} has been promoted to staff.",
            'user'    => $user,
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function logActivity(Request $request, string $action, string $description): void
    {
        try {
            DB::table('activity_log')->insert([
                'user_id'     => $request->user()->id,
                'action'      => $action,
                'description' => $description,
                'ip_address'  => $request->ip(),
                'created_at'  => now(),
            ]);
        } catch (\Exception) {
            // activity_log table not yet migrated - ignore silently
        }
    }

    /**
     * Sync user roles directly via model_has_roles pivot.
     *
     * Bypasses Spatie's syncRoles() which enforces guard matching and throws:
     * "The given role or permission should use guard `web` instead of `sanctum`"
     * when auth uses sanctum tokens but roles were created with guard=web.
     */
    /**
     * Privilege-escalation ceiling (audit SEC-1). Role assignment is only gated by
     * `users.edit`, and every assignment path funnels through syncUserRoles(), so
     * this is the single choke point. Only a super administrator may grant the
     * super_admin role (which bypasses every gate via Gate::before) or change the
     * roles of a user who already holds it. Server-side/console callers (no auth
     * context, e.g. seeders) are trusted and skip the check.
     */
    private function assertMayAssignRoles(int $targetUserId, array $roleIds): void
    {
        $actor = auth()->user();
        if (! $actor || $actor->hasRole('super_admin')) {
            return;
        }

        $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $grantsSuperAdmin = $superAdminRoleId
            && in_array((int) $superAdminRoleId, array_map('intval', $roleIds), true);
        $targetIsSuperAdmin = User::find($targetUserId)?->hasRole('super_admin') ?? false;

        if ($grantsSuperAdmin || $targetIsSuperAdmin) {
            abort(403, 'Only a super administrator can assign or modify the super administrator role.');
        }
    }

    private function syncUserRoles(int $userId, array $roleIds): void
    {
        $this->assertMayAssignRoles($userId, $roleIds);

        $modelType = (new \App\Models\User())->getMorphClass();

        // Delete ALL rows for this user by model_id only - no model_type filter.
        // The PK is (role_id, model_id, model_type). If any stale rows exist with
        // a different model_type value, filtering by model_type would miss them
        // and the subsequent insert would silently fail on insertOrIgnore.
        DB::table('model_has_roles')
            ->where('model_id', $userId)
            ->delete();

        // Use insert() not insertOrIgnore() so failures throw visibly
        foreach (array_unique($roleIds) as $roleId) {
            DB::table('model_has_roles')->insert([
                'role_id'    => (int) $roleId,
                'model_type' => $modelType,
                'model_id'   => $userId,
            ]);
        }

        // Clear Spatie permission cache so new roles take effect immediately
        try {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Exception) {}
    }
}