<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Privileged production-order permission: hard-DELETE an order, and reduce the
 * quantity of an order that is already past draft (both are structural, not
 * routine — serials and materials were sized from the order). Granted to
 * `admin` here; super_admin bypasses via Gate::before. Deploys run migrate,
 * not permission:sync, so this seeds it on the live DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        $perm = Permission::firstOrCreate(
            ['name' => 'production.delete_order', 'guard_name' => 'sanctum'],
        );

        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($admin) {
            $admin->givePermissionTo($perm);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $perm = Permission::where('name', 'production.delete_order')
            ->where('guard_name', 'sanctum')
            ->first();
        if ($perm) {
            $perm->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
