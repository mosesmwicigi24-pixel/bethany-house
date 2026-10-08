<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening, Phase 3C — the keys a proposal is made with.
 *
 *   products.edit_cost           write a product's KES cost price. Applies at
 *                                once within the cost band (≤ 5%), otherwise it
 *                                becomes a product_cost_change proposal.
 *                                → procurement_manager (the cost maker). admin
 *                                keeps writing cost as it does today, through
 *                                products.* (permission:sync's wildcard).
 *   settings.financial_propose   propose a tax-rate, reporting-FX or payment
 *                                settlement change; the super admin signs it.
 *                                Also opens the three screens read-only.
 *                                → finance_manager
 *   settings.pricing_rate_propose  propose a change to a currency's customer-
 *                                facing pricing rate; finance signs it.
 *                                → admin (named explicitly: admin holds
 *                                settings.view, not settings.*)
 *
 * permission:sync grants the same on every container start; this migration
 * makes production right the moment it runs. Every change is written to
 * role_grant_changes (the Phase 2 log) so down() takes back exactly what up()
 * gave and nothing else. Direct user grants are never touched.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_03_530001_proposal_permissions';

    private const LOG = 'role_grant_changes';

    private const NEW_PERMISSIONS = [
        'products.edit_cost'            => ['Edit Product Cost',        'Change a product\'s KES cost price; above the cost band it waits for finance (and the super admin above that)', 'Catalogue'],
        'settings.financial_propose'    => ['Propose Financial Settings', 'Propose tax-rate, reporting exchange-rate and payment settlement changes; the super admin signs them', 'Settings'],
        'settings.pricing_rate_propose' => ['Propose Pricing Rate',     'Propose a change to a currency\'s customer-facing pricing rate; finance signs it', 'Settings'],
    ];

    private const GRANT = [
        'procurement_manager' => ['products.edit_cost'],
        'admin'               => ['products.edit_cost', 'settings.pricing_rate_propose'],
        'finance_manager'     => ['settings.financial_propose'],
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
