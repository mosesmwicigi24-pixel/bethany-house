<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening, Phase 2 — the role catalogue the owner approved
 * (Role Hardening Plan §3, §6, §7, §15; bh-tools/phase2_target.md).
 *
 * permission:sync runs on every container start and only ever ADDS grants,
 * so it can build the new roles but never narrow the old ones. This migration
 * does the narrowing, and also makes the additions itself so production is
 * right the moment it runs rather than at the next sync:
 *
 *   - admin loses refunds, payment void/reassign/approval, procurement and
 *     stock writes, the till's register and discount keys, expense writes and
 *     approval, outlet/user/role administration, settings.edit and
 *     attendance.manage; gains its own profile keys.
 *   - finance_manager stops creating, editing and deleting expenses (it is the
 *     checker); gains read access to catalogue, BOMs, stock and customers.
 *   - procurement_officer stops approving POs and stock movements and loses
 *     payments.view / expenses.view.
 *   - procurement_manager gains bom.edit.
 *   - outlet_manager loses inventory.approve, expenses.delete, outlets.edit;
 *     gains quotations view/create/issue.
 *   - pos_clerk loses expenses (owner decision O2).
 *   - system_admin and accountant are REBUILT: every grant that is not in the
 *     new definition goes (in production that is the legacy space-named
 *     vocabulary — "manage settings", "view orders" … — which the API never
 *     checks), and the new definition is granted.
 *
 * Only ROLE grants (role_has_permissions) are touched. A permission given to a
 * person directly (model_has_permissions) is an individual decision and is
 * left exactly as it is.
 *
 * Every change is written to role_grant_changes as it is made, so down() puts
 * back precisely what this migration took — including whatever legacy grants
 * production's system_admin and accountant happened to hold — and takes back
 * precisely what it gave.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_03_300003_role_catalogue_v2';

    private const LOG = 'role_grant_changes';

    /** The two slugs Phase 2 introduces, with the metadata sync would give them. */
    private const NEW_PERMISSIONS = [
        'bom.edit'        => ['Edit Bills of Materials', 'Create, edit, delete and activate product BOMs', 'Production'],
        'setup.technical' => ['Technical Setup', 'Read and edit countries, languages, shipping zones and shipping methods', 'Settings'],
    ];

    /** Grants taken away from roles that keep the rest of their shape. */
    private const REVOKE = [
        'admin' => [
            'orders.refund',
            'payments.void', 'payments.reassign', 'payments.approve_international',
            'procurement.create', 'procurement.approve', 'procurement.receive',
            'inventory.adjust', 'inventory.transfer', 'inventory.approve',
            'pos.discount', 'pos.discount_override', 'pos.void', 'pos.open_register',
            'pos.close_register', 'pos.returns', 'pos.cash_management',
            'expenses.create', 'expenses.edit', 'expenses.delete', 'expenses.approve',
            'expenses.export', 'expenses.budgets',
            'outlets.create', 'outlets.edit', 'outlets.delete',
            'settings.edit',
            'users.create', 'users.edit', 'users.delete',
            'roles.edit',
            'attendance.manage',
        ],
        'finance_manager'     => ['expenses.create', 'expenses.edit', 'expenses.delete'],
        'procurement_officer' => ['procurement.approve', 'inventory.approve', 'payments.view', 'expenses.view'],
        'outlet_manager'      => ['inventory.approve', 'expenses.delete', 'outlets.edit'],
        'pos_clerk'           => ['expenses.view', 'expenses.create'],
    ];

    /**
     * Grants given. For the two rebuilt roles this is the WHOLE definition and
     * anything else they hold is revoked; for the rest it is the delta.
     */
    private const GRANT = [
        'admin'               => ['profile.view', 'profile.edit'],
        'finance_manager'     => ['products.view', 'production.view_bom', 'inventory.view', 'customers.view'],
        'procurement_manager' => ['bom.edit'],
        'outlet_manager'      => ['quotations.view', 'quotations.create', 'quotations.issue'],
        'system_admin' => [
            'profile.view', 'profile.edit', 'notifications.view', 'dashboard.view',
            'users.view', 'users.create', 'users.edit',
            'outlets.view', 'outlets.create', 'outlets.edit',
            'roles.view',
            'attendance.view_team', 'attendance.manage',
            'setup.technical',
        ],
        'accountant' => [
            'profile.view', 'profile.edit', 'notifications.view', 'dashboard.view',
            'orders.view',
            'payments.view', 'payments.transactions',
            'receivables.view',
            'pos.eod_review',
            'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.export',
            'procurement.view',
            'inventory.view',
            'products.view', 'products.view_cost',
        ],
    ];

    private const REBUILT = ['system_admin', 'accountant'];

    public function up(): void
    {
        if (!Schema::hasTable(self::LOG)) {
            Schema::create(self::LOG, function (Blueprint $table) {
                $table->id();
                $table->string('migration');
                $table->string('role_name')->nullable();
                $table->string('permission_name');
                // revoked | granted | created_permission
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

            foreach (self::REBUILT as $roleName) {
                $roleId = $this->roleId($roleName);
                if ($roleId === null) {
                    continue;
                }
                foreach ($this->grantsOf($roleId) as $permissionId => $permissionName) {
                    if (!in_array($permissionName, self::GRANT[$roleName], true)) {
                        $this->revoke($roleId, $permissionId);
                        $this->log($roleName, $permissionName, 'revoked');
                    }
                }
            }

            foreach (self::REVOKE as $roleName => $permissions) {
                $roleId = $this->roleId($roleName);
                if ($roleId === null) {
                    continue;
                }
                foreach ($permissions as $permissionName) {
                    $permissionId = $this->permissionId($permissionName);
                    if ($permissionId !== null && $this->holds($roleId, $permissionId)) {
                        $this->revoke($roleId, $permissionId);
                        $this->log($roleName, $permissionName, 'revoked');
                    }
                }
            }

            foreach (self::GRANT as $roleName => $permissions) {
                $roleId = $this->roleId($roleName);
                if ($roleId === null) {
                    // A fresh database: roles do not exist until permission:sync
                    // creates them, already in the new shape.
                    continue;
                }
                foreach ($permissions as $permissionName) {
                    $permissionId = $this->permissionId($permissionName);
                    if ($permissionId === null) {
                        DB::table($this->tables()['permissions'])->insert([
                            'name' => $permissionName, 'guard_name' => 'sanctum',
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $this->log(null, $permissionName, 'created_permission');
                        $permissionId = $this->permissionId($permissionName);
                    }
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
            // Newest first: grants and revocations are undone before the
            // permissions this migration created are considered for removal.
            $changes = DB::table(self::LOG)->where('migration', self::MIGRATION)->orderByDesc('id')->get();

            foreach ($changes as $change) {
                $permissionId = $this->permissionId($change->permission_name);
                $roleId = $change->role_name !== null ? $this->roleId($change->role_name) : null;

                if ($change->action === 'granted' && $roleId !== null && $permissionId !== null) {
                    $this->revoke($roleId, $permissionId);
                } elseif ($change->action === 'revoked' && $roleId !== null) {
                    if ($permissionId === null) {
                        DB::table($this->tables()['permissions'])->insert([
                            'name' => $change->permission_name, 'guard_name' => 'sanctum',
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $permissionId = $this->permissionId($change->permission_name);
                    }
                    if (!$this->holds($roleId, $permissionId)) {
                        DB::table($this->tables()['role_has_permissions'])
                            ->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                    }
                } elseif ($change->action === 'created_permission' && $permissionId !== null) {
                    $this->dropPermissionUnlessHeldDirectly($permissionId);
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

    /** @return array<int,string> permission id => name */
    private function grantsOf(int $roleId): array
    {
        $t = $this->tables();

        return DB::table($t['role_has_permissions'])
            ->join($t['permissions'], $t['permissions'] . '.id', '=', $t['role_has_permissions'] . '.permission_id')
            ->where($t['role_has_permissions'] . '.role_id', $roleId)
            ->pluck($t['permissions'] . '.name', $t['permissions'] . '.id')
            ->all();
    }

    private function holds(int $roleId, int $permissionId): bool
    {
        return DB::table($this->tables()['role_has_permissions'])
            ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();
    }

    private function revoke(int $roleId, int $permissionId): void
    {
        DB::table($this->tables()['role_has_permissions'])
            ->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
    }

    /**
     * A permission this migration created goes on rollback — unless somebody
     * has since been given it directly. Direct grants are never touched, so
     * then the (now role-less) permission row stays with them.
     */
    private function dropPermissionUnlessHeldDirectly(int $permissionId): void
    {
        $t = $this->tables();
        if (DB::table($t['model_has_permissions'])->where('permission_id', $permissionId)->exists()) {
            return;
        }
        DB::table($t['role_has_permissions'])->where('permission_id', $permissionId)->delete();
        DB::table($t['permissions'])->where('id', $permissionId)->delete();
    }

    private function log(?string $role, string $permission, string $action): void
    {
        DB::table(self::LOG)->insert([
            'migration' => self::MIGRATION, 'role_name' => $role, 'permission_name' => $permission,
            'action' => $action, 'created_at' => now(),
        ]);
    }
};
