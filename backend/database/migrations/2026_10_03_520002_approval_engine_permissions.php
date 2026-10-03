<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening, Phase 3B — the keys the approval engine signs with.
 *
 *   approvals.finance_sign     finance's signature on a band that is not an
 *                              expense or a payment (a purchase order or a
 *                              stock adjustment above the procurement band).
 *                              → finance_manager
 *   approvals.super_sign       the top band. Granted to NO role: super_admin
 *                              reaches it through Gate::before. Declared so it
 *                              exists for the threshold table to name.
 *   payments.request_void      ask for a payment to be voided / moved; finance
 *   payments.request_reassign  executes it only once the request is signed.
 *                              → accountant (finance_manager asks through the
 *                              keys it already holds, payments.void/reassign)
 *
 * permission:sync grants the same on every container start; this migration
 * makes production right the moment it runs. Every change is written to
 * role_grant_changes (the Phase 2 log) so down() takes back exactly what up()
 * gave and nothing else. Direct user grants are never touched.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_03_520002_approval_engine_permissions';

    private const LOG = 'role_grant_changes';

    private const NEW_PERMISSIONS = [
        'approvals.finance_sign'    => ['Sign Approvals (Finance Band)', 'Sign the finance band of a purchase order or stock adjustment above the procurement band', 'Approvals'],
        'approvals.super_sign'      => ['Sign Approvals (Top Band)',     'Sign the top band of any approval (super admin only)',                                      'Approvals'],
        'payments.request_void'     => ['Request Payment Void',          'Ask for a payment applied to the wrong order to be voided; finance executes it on approval', 'Payments'],
        'payments.request_reassign' => ['Request Payment Reassign',      'Ask for a payment to be moved to another order; finance executes it on approval',           'Payments'],
    ];

    private const GRANT = [
        'finance_manager' => ['approvals.finance_sign'],
        'accountant'      => ['payments.request_void', 'payments.request_reassign'],
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
                    continue;   // fresh database: permission:sync creates the role already granted
                }
                foreach ($permissions as $permissionName) {
                    $permissionId = $this->permissionId($permissionName);
                    if (!DB::table($this->tables()['role_has_permissions'])
                        ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
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
            $t = $this->tables();

            foreach ($changes as $change) {
                $permissionId = $this->permissionId($change->permission_name);
                if ($permissionId === null) {
                    continue;
                }
                if ($change->action === 'granted' && ($roleId = $this->roleId($change->role_name)) !== null) {
                    DB::table($t['role_has_permissions'])
                        ->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
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

    /** @return array<string,string> */
    private function tables(): array
    {
        return config('permission.table_names');
    }

    private function roleId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }
        $id = DB::table($this->tables()['roles'])->where('name', $name)->where('guard_name', 'sanctum')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function permissionId(string $name): ?int
    {
        $id = DB::table($this->tables()['permissions'])->where('name', $name)->where('guard_name', 'sanctum')->value('id');

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
