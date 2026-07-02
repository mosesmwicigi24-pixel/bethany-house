<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * GET /api/v1/admin/permissions
     * Returns permissions grouped by 'group' column.
     * React admin RolesPage permissions matrix expects:
     * { data: [{ group: string, permissions: Permission[] }] }
     */
    public function index()
    {
        $permissions = Permission::where('guard_name', 'sanctum')
            ->orderBy('group')
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'group', 'description']);

        if ($permissions->isEmpty()) {
            return response()->json([
                'data'    => [],
                'message' => 'No permissions found. Run: php artisan permission:sync',
            ]);
        }

        // Group by the 'group' column for the React permissions matrix
        $grouped = $permissions
            ->groupBy('group')
            ->map(fn ($perms, $group) => [
                'group'       => $group ?? 'General',
                'permissions' => $perms->map(fn ($p) => [
                    'id'           => $p->id,
                    'name'         => $p->name,
                    'display_name' => $p->display_name ?? $p->name,
                    'group'        => $p->group ?? 'General',
                    'description'  => $p->description,
                ])->values(),
            ])
            ->values();

        return response()->json(['data' => $grouped]);
    }

    /**
     * POST /api/v1/admin/permissions
     * Create a single permission.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:100|unique:permissions,name',
            'display_name' => 'required|string|max:100',
            'description'  => 'nullable|string',
            'group'        => 'nullable|string|max:50',
        ]);

        $permission = Permission::create([
            'name'         => $validated['name'],
            'display_name' => $validated['display_name'],
            'description'  => $validated['description'] ?? null,
            'group'        => $validated['group'] ?? 'General',
            'guard_name'   => 'sanctum',
        ]);

        try {
            ActivityLogService::log('permission_created', null, [
                'permission_id' => $permission->id,
                'name'          => $permission->name,
                'group'         => $permission->group,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'    => 'Permission created.',
            'permission' => $permission,
        ], 201);
    }

    /**
     * PUT /api/v1/admin/permissions/{id}
     */
    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        $validated = $request->validate([
            'display_name' => 'sometimes|string|max:100',
            'description'  => 'nullable|string',
            'group'        => 'nullable|string|max:50',
        ]);

        $permission->update($validated);

        try {
            ActivityLogService::log('permission_updated', null, [
                'permission_id' => $permission->id,
                'name'          => $permission->name,
                'changes'       => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'    => 'Permission updated.',
            'permission' => $permission->fresh(),
        ]);
    }

    /**
     * DELETE /api/v1/admin/permissions/{id}
     */
    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);

        // Spatie handles pivot cleanup on delete
        $permName  = $permission->name;
        $permGroup = $permission->group;
        $permission->delete();

        try {
            ActivityLogService::log('permission_deleted', null, [
                'permission_id' => $id,
                'name'          => $permName,
                'group'         => $permGroup,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Permission deleted.']);
    }

    /**
     * POST /api/v1/admin/permissions/sync
     * Runs the permission:sync artisan command to seed all SRS permissions.
     * Called from the React admin when "No permissions found" message shown.
     */
    public function syncAll()
    {
        try {
            Artisan::call('permission:sync');
            Artisan::call('permission:cache-reset');

            $count = Permission::where('guard_name', 'sanctum')->count();

            try {
                ActivityLogService::log('permissions_synced', null, [
                    'permissions_count' => $count,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message'           => "Permissions synced successfully. {$count} permissions available.",
                'permissions_count' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}