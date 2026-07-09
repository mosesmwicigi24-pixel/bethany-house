<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission that gates authorizing dispatch (hand-over) of a paid POS order.
 * Only holders may confirm goods can leave. Granted to `admin` here (super_admin
 * bypasses via Gate::before); the business assigns it to whichever one or two
 * people should authorize dispatch. Deploys run migrate, not permission:sync,
 * so this seeds it on the live DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        $perm = Permission::firstOrCreate(
            ['name' => 'orders.authorize_dispatch', 'guard_name' => 'sanctum'],
        );

        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($admin) {
            $admin->givePermissionTo($perm);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $perm = Permission::where('name', 'orders.authorize_dispatch')
            ->where('guard_name', 'sanctum')
            ->first();
        if ($perm) {
            $perm->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
