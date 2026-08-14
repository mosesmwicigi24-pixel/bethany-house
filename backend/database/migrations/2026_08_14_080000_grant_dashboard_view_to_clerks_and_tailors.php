<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps the dashboard reachable for the roles that already had it.
 *
 * GET /admin/dashboard carried no permission middleware, so every
 * authenticated user could load it. It is now gated on `dashboard.view` —
 * which pos_clerk and tailor were never granted, because until now they did
 * not need it.
 *
 * Adding 'dashboard.view' to their lists in SyncPermissions is not enough:
 * deploys run `php artisan migrate --force` and never `permission:sync`, so a
 * catalogue-only grant never reaches production. Without this migration the
 * gate would take the dashboard away from five cashiers and nine tailors —
 * fourteen of twenty-four staff — the moment it deployed.
 *
 * The leak being closed is the group revenue figure, not the page. buildStats
 * withholds today_sales and pending_payment_approvals behind reports.view,
 * which neither role holds, so they keep a dashboard with the money removed.
 */
return new class extends Migration
{
    private const ROLES = ['pos_clerk', 'tailor'];

    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'dashboard.view', 'guard_name' => 'sanctum'],
        );

        foreach (self::ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'sanctum')->first();
            if ($role) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Revoke from these two roles only. dashboard.view predates this
        // migration and admin, outlet_manager and the finance/procurement roles
        // all hold it legitimately.
        foreach (self::ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'sanctum')->first();
            if ($role) {
                $role->revokePermissionTo('dashboard.view');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
