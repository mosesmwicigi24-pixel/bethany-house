<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions gating the quotation surface (front of the quotation → invoice →
 * receipt flow). Granted to `admin` here (super_admin bypasses via Gate::before);
 * deploys run migrate, not permission:sync, so this seeds them on the live DB.
 */
return new class extends Migration
{
    private array $names = [
        'quotations.view',
        'quotations.create',
        'quotations.issue',
        'quotations.delete',
    ];

    public function up(): void
    {
        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();

        foreach ($this->names as $name) {
            $perm = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
            $admin?->givePermissionTo($perm);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', $this->names)->where('guard_name', 'sanctum')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
