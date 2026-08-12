<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Correcting and erasing payments.
 *
 * Recording money and fixing a record of money are different acts, so they get
 * different names. A cashier records; correcting what was recorded is an
 * office decision, because it moves an order's balance after the fact.
 *
 * `payments.edit` goes to admin — the realistic case is an order's line items
 * being edited after the money was taken, or a keying error, and making that
 * wait for a super admin means it gets fixed with a note in the margin instead.
 *
 * `payments.delete` goes to nobody by default. It is granted to `super_admin`
 * only, and the controller checks the role directly on top of the permission:
 * deletion is the one action on this surface that cannot be undone, and a
 * permission accidentally attached to a role should not be enough to destroy a
 * financial record. Voiding — which removes a payment from every total and
 * report while keeping the row — is the path everything else should take.
 *
 * Deploys run `migrate`, not `permission:sync`, so this seeds the live database.
 */
return new class extends Migration
{
    private const GRANTS = [
        'payments.edit'   => ['admin'],
        'payments.delete' => [],   // super_admin only, via Gate::before
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);

            foreach ($roles as $roleName) {
                Role::where('name', $roleName)->where('guard_name', 'sanctum')->first()
                    ?->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys(self::GRANTS))
            ->where('guard_name', 'sanctum')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
