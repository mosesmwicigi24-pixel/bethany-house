<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Delivers the discount ceiling's escape hatch to the live database.
 *
 * PosDiscountPolicy refuses a discount above config('pos.discount_cap_percent')
 * unless the caller holds `pos.discount_override`. Declaring that permission in
 * SyncPermissions::PERMISSIONS is not enough: deploys run
 * `php artisan migrate --force` and never `permission:sync`, so a permission
 * that only exists in the catalogue never reaches production. Without this
 * migration the ceiling would ship with NO override path — every over-limit
 * discount would hard-fail with nobody able to approve it.
 *
 * `pos.discount` is granted here too, defensively. The policy refuses ANY
 * discount from a caller without it, and neither admin nor outlet_manager is
 * guaranteed to hold it on the live database — admin's is expected to have
 * arrived via a wildcard expansion during some past manual sync, which is not
 * something to bet the till on. Both roles are supposed to have it; this makes
 * that true rather than likely.
 *
 * super_admin needs neither: Gate::before answers true before any permission is
 * consulted.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['pos.discount', 'pos.discount_override'];

    /** Roles that may exceed the ceiling. */
    private const ROLES = ['admin', 'outlet_manager'];

    public function up(): void
    {
        $permissions = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'sanctum'],
            ),
        );

        foreach (self::ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'sanctum')->first();
            if ($role) {
                $role->givePermissionTo($permissions->all());
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Only the new permission is removed. pos.discount predates this
        // migration and other roles legitimately hold it — dropping it on a
        // rollback would take the till's discount button away from everyone.
        $override = Permission::where('name', 'pos.discount_override')
            ->where('guard_name', 'sanctum')
            ->first();

        if ($override) {
            $override->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
