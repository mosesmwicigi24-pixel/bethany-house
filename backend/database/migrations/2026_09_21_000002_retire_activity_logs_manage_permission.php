<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * activity_logs.manage let its holder "permanently delete audit log entries
 * older than a given date". The audit trail is append-only now (2026_09_21_000001):
 * the database refuses the delete and the endpoint answers 403. A toggle on the
 * Roles screen that governs nothing is how pos.discount once looked like a
 * control while doing nothing (PermissionIntegrityTest), so it goes: deleting
 * the permission removes it from every role and user that held it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::where('name', 'activity_logs.manage')->where('guard_name', 'sanctum')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $perm = Permission::firstOrCreate(['name' => 'activity_logs.manage', 'guard_name' => 'sanctum']);
        Role::where('name', 'admin')->where('guard_name', 'sanctum')->first()?->givePermissionTo($perm);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
