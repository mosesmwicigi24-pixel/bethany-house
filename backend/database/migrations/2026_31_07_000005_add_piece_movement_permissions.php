<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may move pieces.
 *
 * The split is the one the shop floor already runs on: anybody working a
 * station moves work forward and can stop it when the buttons run out, but
 * sending work backwards, writing it off, releasing somebody else's hold or
 * rewriting history are supervisor acts — each of them distorts yield
 * reporting, so each needs a name against it.
 *
 * Granted to the roles that exist here; `super_admin` bypasses via Gate::before.
 * Deploys run `migrate`, not `permission:sync`, so this seeds the live database.
 */
return new class extends Migration
{
    /** permission => roles that get it. */
    private const GRANTS = [
        // Ordinary floor work.
        'production.move_pieces'      => ['tailor', 'production_manager', 'admin'],
        // Loading a batch: the initial cut, and re-cuts after scrap.
        'production.load_pieces'      => ['production_manager', 'admin'],
        // Sending work backwards, and releasing a hold somebody else placed.
        'production.rework_pieces'    => ['production_manager', 'admin'],
        // Writing pieces off.
        'production.scrap_pieces'     => ['production_manager', 'admin'],
        // Correcting the record.
        'production.reverse_movement' => ['production_manager', 'admin'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);

            foreach ($roles as $roleName) {
                $role = Role::where('name', $roleName)->where('guard_name', 'sanctum')->first();
                $role?->givePermissionTo($permission);
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
