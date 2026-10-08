<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening 4D — "works across every outlet" becomes a permission.
 *
 * PosController::isAdminUser() and five checks in TimeClockController asked
 * hasRole('admin') / hasRole('super_admin') to decide whether someone is held
 * to the outlets on their outlet_user rows (the till's outlet list and outlet
 * access; the time clock's outlet list, geofence override, team entries,
 * flagged entries and entry access). None of the existing slugs expresses that
 * — admin and outlet_manager hold the same attendance and till-access keys —
 * so this adds one, outlets.all_access, and grants it to exactly the role the
 * name check let through: admin. super_admin keeps it by its Gate::before
 * bypass. Nobody's behaviour changes; the Roles screen can now say it.
 *
 * Same mechanics as 2026_10_03_300003_role_catalogue_v2: only role grants are
 * touched, every change is written to role_grant_changes, and down() undoes
 * precisely what up() did. permission:sync declares the slug (and excludes it
 * from wildcard expansion), but deploys run only `migrate`, so this is what
 * reaches production.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_03_480001_outlets_all_access_permission';

    private const LOG = 'role_grant_changes';

    private const PERMISSION = ['outlets.all_access', 'Work Across All Outlets',
        'Use the till and the time clock at every outlet, not only assigned ones (and see every outlet\'s time entries)', 'Outlets'];

    private const GRANT = ['admin'];

    public function up(): void
    {
        if (!Schema::hasTable(self::LOG)) {
            Schema::create(self::LOG, function (Blueprint $table) {
                $table->id();
                $table->string('migration');
                $table->string('role_name')->nullable();
                $table->string('permission_name');
                $table->string('action', 32);
                $table->timestamp('created_at')->nullable();
                $table->index('migration');
            });
        }

        DB::transaction(function () {
            [$name, $display, $description, $group] = self::PERMISSION;
            $t = config('permission.table_names');

            $permissionId = $this->permissionId($name);
            if ($permissionId === null) {
                DB::table($t['permissions'])->insert([
                    'name' => $name, 'guard_name' => 'sanctum',
                    'display_name' => $display, 'description' => $description, 'group' => $group,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->log(null, $name, 'created_permission');
                $permissionId = $this->permissionId($name);
            }

            foreach (self::GRANT as $roleName) {
                $roleId = DB::table($t['roles'])->where('name', $roleName)->where('guard_name', 'sanctum')->value('id');
                if ($roleId === null) {
                    continue;   // fresh database: permission:sync creates the role with it
                }
                $held = DB::table($t['role_has_permissions'])
                    ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();
                if (!$held) {
                    DB::table($t['role_has_permissions'])->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                    $this->log($roleName, $name, 'granted');
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::LOG)) {
            return;
        }
        $t = config('permission.table_names');

        DB::transaction(function () use ($t) {
            $changes = DB::table(self::LOG)->where('migration', self::MIGRATION)->orderByDesc('id')->get();
            foreach ($changes as $change) {
                $permissionId = $this->permissionId($change->permission_name);
                if ($permissionId === null) {
                    continue;
                }
                if ($change->action === 'granted') {
                    $roleId = DB::table($t['roles'])->where('name', $change->role_name)->where('guard_name', 'sanctum')->value('id');
                    if ($roleId !== null) {
                        DB::table($t['role_has_permissions'])
                            ->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
                    }
                } elseif ($change->action === 'created_permission'
                    && !DB::table($t['model_has_permissions'])->where('permission_id', $permissionId)->exists()) {
                    DB::table($t['role_has_permissions'])->where('permission_id', $permissionId)->delete();
                    DB::table($t['permissions'])->where('id', $permissionId)->delete();
                }
            }
            DB::table(self::LOG)->where('migration', self::MIGRATION)->delete();
        });

        if (DB::table(self::LOG)->count() === 0) {
            Schema::drop(self::LOG);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function permissionId(string $name): ?int
    {
        $id = DB::table(config('permission.table_names')['permissions'])
            ->where('name', $name)->where('guard_name', 'sanctum')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function log(?string $role, string $permission, string $action): void
    {
        DB::table(self::LOG)->insert([
            'migration' => self::MIGRATION, 'role_name' => $role, 'permission_name' => $permission,
            'action' => $action, 'created_at' => now(),
        ]);
    }
};
