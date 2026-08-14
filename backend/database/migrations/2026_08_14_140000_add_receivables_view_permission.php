<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Outstanding Balances stops riding on the till key.
 *
 * pos.access exists to open the point of sale. It was also opening the
 * receivables screen — every part-paid order in the group, with the customer's
 * name, phone number and what they still owe.
 *
 * The order rows themselves are already bounded: outstandingBalances queries
 * through Eloquent, so the viewer scope applies and a cashier sees only their
 * own. This is about who gets the screen at all, which is a decision rather
 * than a leak: chasing what a customer owes is a manager's job.
 *
 * Granted to the roles whose job it is — admin, outlet_manager and
 * finance_manager. Delivered by migration because deploys run `migrate` and
 * never `permission:sync`.
 */
return new class extends Migration
{
    private const ROLES = ['admin', 'outlet_manager', 'finance_manager'];

    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'receivables.view', 'guard_name' => 'sanctum'],
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
        Permission::where('name', 'receivables.view')
            ->where('guard_name', 'sanctum')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
