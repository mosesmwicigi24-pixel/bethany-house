<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Services\ActivityLogService;
use App\Services\PermissionDependencyService;

class RoleController extends Controller
{
    /**
     * List all roles with user count and permissions.
     * React admin RolesPage expects: { data: Role[] }
     */
    public function index()
    {
        $roles = Role::with('permissions')->get();

        // Count users per role directly from pivot - avoids Role::withCount('users')
        // which triggers morphedByMany and crashes when morph map isn't registered
        $userCounts = DB::table('model_has_roles')
            ->select('role_id', DB::raw('count(*) as count'))
            ->groupBy('role_id')
            ->pluck('count', 'role_id');

        $result = $roles->map(function ($role) use ($userCounts) {
            $role->users_count = $userCounts->get($role->id, 0);
            return $this->formatRole($role);
        });

        return response()->json(['data' => $result]);
    }

    /**
     * Get a single role with permissions.
     */
    public function show($id)
    {
        $role = Role::with('permissions')->findOrFail($id);

        $role->users_count = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->count();

        return response()->json(['role' => $this->formatRole($role)]);
    }

    /**
     * Create a new role.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100|unique:roles,name',
            'display_name'  => 'required|string|max:100',
            'description'   => 'nullable|string',
            'user_type'     => 'sometimes|in:system,staff,customer',
            'is_active'     => 'sometimes|boolean',
            'permissions'   => 'sometimes|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        // Privilege ceiling: a new role can only be given permissions the actor
        // holds (every permission is "added"). Before the transaction so the 403
        // isn't swallowed by the catch below.
        $this->assertMayGrantPermissions(
            $this->expandWithDependencies(array_map('intval', $validated['permissions'] ?? []))
        );

        DB::beginTransaction();
        try {
            $role = Role::create([
                'name'         => $validated['name'],
                'display_name' => $validated['display_name'],
                'description'  => $validated['description'] ?? null,
                // Was 'web'. User::$guard_name is hardcoded to 'sanctum'
                // (see User.php), which forces every hasRole(), can(), and
                // permission:/role: route middleware check to resolve
                // against the 'sanctum' guard. A role created with guard
                // 'web' never matches that guard, so any user assigned
                // ONLY to a role created through this endpoint would fail
                // essentially every authorization check in the app -
                // DashboardController::getUserRoles() has a comment
                // documenting this exact failure mode and works around it
                // by bypassing Spatie's helpers entirely for its own
                // purposes. Every other role in the system (SyncPermissions,
                // and every Permission record) already uses 'sanctum'; this
                // brings custom roles created via the UI in line with them.
                'guard_name'   => 'sanctum',
                'user_type'    => $validated['user_type'] ?? 'staff',
                'is_active'    => $validated['is_active'] ?? true,
                'is_system'    => false,
            ]);

            if (!empty($validated['permissions'])) {
                $this->writePermissions($role->id, $validated['permissions']);
            }

            DB::commit();

            $role->load('permissions');
            $role->users_count = 0;

            ActivityLogService::log('created', null, [
                'role_name'       => $role->name,
                'display_name'    => $role->display_name,
                'permissions_count' => count($validated['permissions'] ?? []),
            ], "Role '{$role->display_name}' created");

            return response()->json([
                'message' => 'Role created successfully.',
                'role'    => $this->formatRole($role),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create role.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a role's metadata.
     */
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'display_name' => 'sometimes|string|max:100',
            'description'  => 'nullable|string',
            'user_type'    => 'sometimes|in:system,staff,customer',
            'is_active'    => 'sometimes|boolean',
        ]);

        $role->update($validated);

        ActivityLogService::log('updated', null, [
            'role_name'      => $role->name,
            'changed_fields' => array_keys($validated),
        ], "Role '{$role->name}' updated: " . implode(', ', array_keys($validated)));

        $role->load('permissions');
        $role->users_count = DB::table('model_has_roles')->where('role_id', $role->id)->count();

        return response()->json([
            'message' => 'Role updated successfully.',
            'role'    => $this->formatRole($role),
        ]);
    }

    /**
     * Delete a role (system roles and roles with users are protected).
     */
    public function destroy($id)
    {
        $role = Role::findOrFail($id);

        if ($role->is_system ?? false) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 422);
        }

        $userCount = DB::table('model_has_roles')->where('role_id', $role->id)->count();
        if ($userCount > 0) {
            return response()->json([
                'message' => 'Cannot delete a role that has assigned users. Remove users first.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
            $role->delete();
            $this->clearPermissionCache();
            DB::commit();

            ActivityLogService::log('deleted', null, [
                'role_name' => $role->name,
                'role_id'   => $role->id,
            ], "Role '{$role->name}' deleted");

            return response()->json(['message' => 'Role deleted successfully.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete role.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get permissions assigned to a role.
     */
    public function permissions($id)
    {
        $role = Role::with('permissions')->findOrFail($id);

        return response()->json([
            'role'        => $role->name,
            'permissions' => $role->permissions->map(fn ($p) => $this->formatPermission($p)),
        ]);
    }

    /**
     * Sync permissions on a role.
     * Accepts: { permissions: [1, 2, 3] }  (permission IDs)
     *
     * Uses direct pivot writes - bypasses Spatie's syncPermissions()
     * which triggers morphedByMany and crashes without a morph map.
     */
    public function syncPermissions(Request $request, $id)
    {
        $validated = $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'integer|exists:permissions,id',
        ]);

        $role = Role::findOrFail($id);

        $requestedIds = array_map('intval', $validated['permissions']);
        $finalIds     = $this->expandWithDependencies($requestedIds);
        $autoAddedIds = array_diff($finalIds, $requestedIds);

        // Privilege ceiling — check only the permissions being ADDED (keeping a
        // role's existing permissions is not escalation). Before the transaction
        // so the 403 isn't swallowed by the catch below.
        $currentIds = DB::table('role_has_permissions')->where('role_id', $role->id)
            ->pluck('permission_id')->map(fn ($p) => (int) $p)->all();
        $this->assertMayGrantPermissions(array_values(array_diff($finalIds, $currentIds)), $role->id);

        DB::beginTransaction();
        try {
            $this->writePermissionIds($role->id, $finalIds);
            DB::commit();

            $role->load('permissions');
            $role->users_count = DB::table('model_has_roles')->where('role_id', $role->id)->count();

            ActivityLogService::log('permissions_synced', null, [
                'role_name'        => $role->name,
                'permissions_count'=> count($finalIds),
            ], "Permissions updated for role '{$role->name}' ({$role->permissions->count()} permissions)");

            $message = 'Permissions updated successfully.';
            if (!empty($autoAddedIds)) {
                $autoAddedNames = Permission::whereIn('id', $autoAddedIds)->pluck('display_name', 'name');
                $message .= ' ' . count($autoAddedIds) . ' additional permission(s) were auto-granted because the selected permissions depend on them: '
                    . implode(', ', $autoAddedNames->toArray()) . '.';
            }

            return response()->json([
                'message' => $message,
                'role'    => $this->formatRole($role),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update permissions.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Duplicate a role with all its permissions.
     */
    public function duplicate($id)
    {
        $source = Role::with('permissions')->findOrFail($id);

        // Privilege ceiling: you can't clone a role more powerful than yourself
        // (the copy would carry permissions you don't hold, then be assignable).
        $this->assertMayGrantPermissions(
            $source->permissions->pluck('id')->map(fn ($i) => (int) $i)->all()
        );

        DB::beginTransaction();
        try {
            $copy = Role::create([
                'name'         => $source->name . '_copy_' . time(),
                'display_name' => ($source->display_name ?? $source->name) . ' (Copy)',
                'description'  => $source->description,
                'guard_name'   => $source->guard_name,
                'user_type'    => $source->user_type,
                'is_active'    => $source->is_active,
                'is_system'    => false,
            ]);

            $permissionIds = $source->permissions->pluck('id')->toArray();
            if (!empty($permissionIds)) {
                $this->writePermissions($copy->id, $permissionIds);
            }

            DB::commit();

            $copy->load('permissions');
            $copy->users_count = 0;

            return response()->json([
                'message' => 'Role duplicated successfully.',
                'role'    => $this->formatRole($copy),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to duplicate role.', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Privilege ceiling for granting permissions to a role.
     *
     * Without this, a mere `roles.edit` holder could self-escalate to any
     * permission (up to full super_admin reach) by adding permissions to their
     * own role, editing the built-in `admin` role, or duplicating a powerful role.
     * So a non-super-admin may NOT (a) modify the super_admin role's permissions,
     * nor (b) grant a permission they do not themselves hold.
     *
     * Mirrors UserController::assertMayAssignRoles: console/seeder callers (no auth
     * context → null actor) and super_admin are unrestricted. `$grantedIds` is the
     * set of permission ids being ADDED (already dependency-expanded). Must be
     * called BEFORE opening a DB transaction — abort(403) throws, and the callers'
     * catch(\Exception) would otherwise convert it into a 500.
     *
     * Note: protection is derived from the role NAME `super_admin`, not the
     * `is_system` flag, which does not exist on a fresh schema.
     */
    private function assertMayGrantPermissions(array $grantedIds, ?int $targetRoleId = null): void
    {
        $actor = auth()->user();
        if (! $actor || $actor->hasRole('super_admin', 'sanctum')) {
            return;
        }

        if ($targetRoleId) {
            $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');
            if ($superAdminRoleId && (int) $targetRoleId === (int) $superAdminRoleId) {
                abort(403, 'Only a super administrator can modify the super administrator role.');
            }
        }

        $heldIds   = $actor->getAllPermissions()->pluck('id')->map(fn ($i) => (int) $i)->all();
        $exceeding = array_diff(array_map('intval', $grantedIds), $heldIds);
        if (! empty($exceeding)) {
            abort(403, 'You can only grant permissions you hold yourself.');
        }
    }

    /**
     * Write permissions directly to role_has_permissions pivot.
     * Replaces ALL Spatie syncPermissions() calls to avoid morphedByMany crash.
     *
     * Permission IDs are expanded through PermissionDependencyService first,
     * so ticking "Set Shipping Fee" in the UI also grants the "View Orders"
     * and "View Settings" permissions it needs to actually work, the same
     * way `php artisan permission:sync` does for the built-in roles.
     */
    private function writePermissions(int $roleId, array $permissionIds): void
    {
        $this->writePermissionIds($roleId, $this->expandWithDependencies($permissionIds));
    }

    /**
     * Raw pivot write for an already-final list of permission IDs (no
     * dependency expansion). Used directly by syncPermissions(), which
     * expands up front so it can report which IDs were auto-added.
     */
    private function writePermissionIds(int $roleId, array $permissionIds): void
    {
        DB::table('role_has_permissions')->where('role_id', $roleId)->delete();

        $rows = array_map(fn ($permId) => [
            'permission_id' => (int) $permId,
            'role_id'       => $roleId,
        ], array_unique($permissionIds));

        if (!empty($rows)) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }

        $this->clearPermissionCache();
    }

    /**
     * Resolve the requested permission IDs to names, run them through the
     * dependency map, then translate the (possibly larger) resulting name
     * list back to IDs. Unknown/duplicate IDs are ignored gracefully.
     */
    private function expandWithDependencies(array $permissionIds): array
    {
        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));

        $names = Permission::whereIn('id', $permissionIds)->pluck('name')->toArray();
        $resolvedNames = PermissionDependencyService::resolve($names);

        $resolvedIds = Permission::whereIn('name', $resolvedNames)->pluck('id')->toArray();

        return array_values(array_unique(array_merge($permissionIds, $resolvedIds)));
    }

    private function clearPermissionCache(): void
    {
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Exception) {}
    }

    private function formatRole(Role $role): array
    {
        return [
            'id'           => $role->id,
            'name'         => $role->name,
            'display_name' => $role->display_name ?? ucwords(str_replace('_', ' ', $role->name)),
            'description'  => $role->description,
            'guard_name'   => $role->guard_name,
            'user_type'    => $role->user_type ?? 'staff',
            'is_active'    => (bool) ($role->is_active ?? true),
            'is_system'    => (bool) ($role->is_system ?? false),
            'users_count'  => $role->users_count ?? 0,
            'permissions'  => ($role->relationLoaded('permissions') ? $role->permissions : collect())
                ->map(fn ($p) => $this->formatPermission($p))
                ->values(),
            'created_at'   => $role->created_at,
            'updated_at'   => $role->updated_at,
        ];
    }

    private function formatPermission(Permission $permission): array
    {
        return [
            'id'           => $permission->id,
            'name'         => $permission->name,
            'display_name' => $permission->display_name ?? $permission->name,
            'group'        => $permission->group ?? 'General',
            'description'  => $permission->description ?? null,
        ];
    }
}