<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening, Phase 4B part 1 — who may do each step of the till lifecycle.
 *
 *   pos.till_verify     outlet_manager            verifies a counted till and finalizes it
 *                                                 (at their own outlets; never their own count)
 *   pos.reconcile       accountant, finance_manager  records the next-day check against payments
 *   pos.till_correction finance_manager           opens a correction against a finalized till
 *   pos.tills_view_all  admin, accountant, finance_manager  reads every outlet's tills
 *
 * Opening and counting stay pos.open_register / pos.close_register. super_admin
 * passes every check through Gate::before and holds no stored grants.
 *
 * Grants only — nothing is revoked. Written in the style of
 * 2026_10_03_300003_role_catalogue_v2: permission:sync creates the same grants
 * on a fresh database; this makes production right the moment it runs, and
 * every change goes to role_grant_changes so down() takes back exactly what
 * this gave and nothing else.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_03_440002_till_lifecycle_permissions';

    private const LOG = 'role_grant_changes';

    private const NEW_PERMISSIONS = [
        'pos.till_verify'     => ['Verify & Finalize Tills', 'Verify a cashier\'s blind till count at your outlet and finalize it', 'POS'],
        'pos.reconcile'       => ['Reconcile Tills', 'Record the next-day check of a finalized till against the payments ledger', 'POS'],
        'pos.till_correction' => ['Correct Finalized Tills', 'Open a linked correction against a finalized till (the original is never changed)', 'POS'],
        'pos.tills_view_all'  => ['View All Tills', 'Read every outlet\'s till sessions, counts and variances', 'POS'],
    ];

    private const GRANT = [
        'outlet_manager'  => ['pos.till_verify'],
        'accountant'      => ['pos.reconcile', 'pos.tills_view_all'],
        'finance_manager' => ['pos.reconcile', 'pos.till_correction', 'pos.tills_view_all'],
        'admin'           => ['pos.tills_view_all'],
    ];

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
            foreach (self::NEW_PERMISSIONS as $name => [$display, $description, $group]) {
                if ($this->permissionId($name) === null) {
                    DB::table($this->tables()['permissions'])->insert([
                        'name' => $name, 'guard_name' => 'sanctum',
                        'display_name' => $display, 'description' => $description, 'group' => $group,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->log(null, $name, 'created_permission');
                }
            }

            foreach (self::GRANT as $roleName => $permissions) {
                $roleId = $this->roleId($roleName);
                if ($roleId === null) {
                    // Fresh database: permission:sync creates the role already holding these.
                    continue;
                }
                foreach ($permissions as $permissionName) {
                    $permissionId = $this->permissionId($permissionName);
                    if (!$this->holds($roleId, $permissionId)) {
                        DB::table($this->tables()['role_has_permissions'])
                            ->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                        $this->log($roleName, $permissionName, 'granted');
                    }
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

        DB::transaction(function () {
            $changes = DB::table(self::LOG)->where('migration', self::MIGRATION)->orderByDesc('id')->get();

            foreach ($changes as $change) {
                $permissionId = $this->permissionId($change->permission_name);
                if ($permissionId === null) {
                    continue;
                }
                if ($change->action === 'granted' && $change->role_name !== null) {
                    $roleId = $this->roleId($change->role_name);
                    if ($roleId !== null) {
                        DB::table($this->tables()['role_has_permissions'])
                            ->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
                    }
                } elseif ($change->action === 'created_permission') {
                    $t = $this->tables();
                    // A permission someone has since been given directly stays with them.
                    if (DB::table($t['model_has_permissions'])->where('permission_id', $permissionId)->exists()) {
                        continue;
                    }
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

    /** @return array<string,string> */
    private function tables(): array
    {
        return config('permission.table_names');
    }

    private function roleId(string $name): ?int
    {
        $id = DB::table($this->tables()['roles'])->where('name', $name)->where('guard_name', 'sanctum')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function permissionId(string $name): ?int
    {
        $id = DB::table($this->tables()['permissions'])->where('name', $name)->where('guard_name', 'sanctum')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function holds(int $roleId, int $permissionId): bool
    {
        return DB::table($this->tables()['role_has_permissions'])
            ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();
    }

    private function log(?string $role, string $permission, string $action): void
    {
        DB::table(self::LOG)->insert([
            'migration' => self::MIGRATION, 'role_name' => $role, 'permission_name' => $permission,
            'action' => $action, 'created_at' => now(),
        ]);
    }
};
