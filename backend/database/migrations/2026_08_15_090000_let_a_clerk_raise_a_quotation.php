<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A cashier may raise a quotation, having just been given the right to see
 * her own.
 *
 * Scoping quotations to their creator left pos_clerk holding quotations.view
 * over a list that could never contain anything: she could see the quotations
 * she had raised, and she had no way to raise one. The screen was reachable
 * from the menu and permanently empty.
 *
 * quotations.create covers POST /quotations and PUT /quotations/{id} — raising
 * a draft and editing it. Both refuse anything that is not a DRAFT, and both
 * resolve through the scoped model, so she can only ever touch her own.
 *
 * quotations.issue is NOT granted: issuing allocates the QUO number and sends a
 * price to the customer, which stays a second person's decision. Be honest
 * about who that person is, though — today `quotations.issue` and
 * `quotations.delete` are held by `admin` and nobody else. `outlet_manager`,
 * the intended escalation target, holds no quotation permission at all and has
 * zero users. So in practice every clerk draft needs an administrator to issue
 * it, and a mistaken draft needs an administrator to remove it. That is a
 * bottleneck to fix by giving outlet_manager the quotation permissions, not by
 * widening the cashier.
 *
 * Delivered by migration because deploys run `migrate` and never
 * `permission:sync`, so a declaration in SyncPermissions.php alone would reach
 * every test database and no production one.
 */
return new class extends Migration
{
    private const PERMISSION = 'quotations.create';
    private const ROLE       = 'pos_clerk';

    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'sanctum'],
        );

        $role = Role::where('name', self::ROLE)->where('guard_name', 'sanctum')->first();
        if ($role) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Revoke the grant, but leave the permission itself alone: other roles
        // hold it, and deleting the row would strip it from them too.
        $role = Role::where('name', self::ROLE)->where('guard_name', 'sanctum')->first();
        $permission = Permission::where('name', self::PERMISSION)
            ->where('guard_name', 'sanctum')
            ->first();

        if ($role && $permission) {
            $role->revokePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
