<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Anti-fraud guardrail: reducing shipping on a paid receipt is admin-only.
 *
 * Without this, a cashier could inflate shipping (e.g. 50,000), collect it, then
 * quietly reduce it (e.g. 20,000) and pocket the difference. OrderController::
 * setShippingFee now requires `orders.reduce_shipping_fee` to lower shipping once
 * any payment has been collected. Deploys only run migrate (no permission:sync),
 * so this creates the permission and grants it to `admin` on the live DB.
 * super_admin bypasses every gate via AuthServiceProvider::Gate::before, so it
 * needs no explicit grant; outlet_manager/pos_clerk deliberately do NOT get it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $perm = Permission::firstOrCreate(
            ['name' => 'orders.reduce_shipping_fee', 'guard_name' => 'sanctum'],
        );

        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($admin) {
            $admin->givePermissionTo($perm);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $perm = Permission::where('name', 'orders.reduce_shipping_fee')
            ->where('guard_name', 'sanctum')
            ->first();
        if ($perm) {
            $perm->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
