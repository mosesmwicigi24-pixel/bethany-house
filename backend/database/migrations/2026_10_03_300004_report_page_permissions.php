<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening, Phase 3A — one permission per report page, and the export
 * split (Role Hardening Plan §6; bh-tools/phase3_spec.md §3A).
 *
 * permission:sync runs on every container start and only ever ADDS grants, so
 * it can hand out the new page slugs but never take the old front door away.
 * This migration does both, so production is right the moment it runs:
 *
 *   - every catalogue role is granted its report pages per the plan's table;
 *   - reports.export stays with admin and finance_manager; the procurement
 *     manager trades it for reports.export_supply (Inventory + Procurement);
 *   - admin loses reports.financial (plan §6: Finance & Cash "—" for ADM);
 *   - reports.view is revoked from every role and retired. A role made in the
 *     Roles screen that held it is given the ten pages it used to open —
 *     never Finance, never a file — so nobody the owner set up loses a page
 *     they could read yesterday. The permission row itself is deleted unless
 *     a person holds it directly.
 *
 * Only ROLE grants (role_has_permissions) are touched. A permission given to a
 * person directly (model_has_permissions) is an individual decision and is
 * left exactly as it is.
 *
 * Every change is written to role_grant_changes as it is made (the log Phase 2
 * introduced), so down() puts back precisely what this migration took and
 * takes back precisely what it gave.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_03_300004_report_page_permissions';

    private const LOG = 'role_grant_changes';

    private const RETIRED = 'reports.view';

    private const RETIRED_META = ['View Reports', 'Access sales, inventory and financial reports', 'Reports'];

    /** The slugs Phase 3A introduces, with the metadata sync would give them. */
    private const NEW_PERMISSIONS = [
        'reports.executive'     => ['View Executive Overview', 'The Executive report page: headline figures, opportunities and their drill-downs', 'Reports'],
        'reports.sales'         => ['View Sales & Orders Report', 'Sales & Orders report page (and the dashboard revenue row)', 'Reports'],
        'reports.customers'     => ['View Customers & Neema Report', 'Customers & Neema report page, and Storefront Insights', 'Reports'],
        'reports.production'    => ['View Production & Fulfilment Report', 'Production & Fulfilment report page', 'Reports'],
        'reports.inventory'     => ['View Inventory Report', 'Inventory report page', 'Reports'],
        'reports.procurement'   => ['View Procurement & Suppliers Report', 'Procurement & Suppliers report page', 'Reports'],
        'reports.performance'   => ['View Staff, Outlets & Performance Report', 'Staff, Outlets & Performance report page', 'Reports'],
        'reports.signals'       => ['View Signals', 'Signals report page: reorder, shortage, churn and budget warnings', 'Reports'],
        'reports.data_quality'  => ['View Audit & Data Quality Report', 'Audit & Data Quality report page', 'Reports'],
        'reports.explorer'      => ['View Business Explorer', 'Business Explorer report page', 'Reports'],
        'reports.export_supply' => ['Export Supply Reports', 'Download CSV/PDF files from the Inventory and Procurement report pages only', 'Reports'],
    ];

    /** What reports.view opened: every page except Finance & Cash. */
    private const LEGACY_EQUIVALENT = [
        'reports.executive', 'reports.sales', 'reports.customers', 'reports.production',
        'reports.inventory', 'reports.procurement', 'reports.performance', 'reports.signals',
        'reports.data_quality', 'reports.explorer',
    ];

    /** Plan §6, per catalogue role. */
    private const GRANT = [
        'admin' => [
            'reports.executive', 'reports.sales', 'reports.customers', 'reports.production',
            'reports.inventory', 'reports.procurement', 'reports.performance', 'reports.signals',
            'reports.data_quality', 'reports.explorer', 'reports.export',
        ],
        'finance_manager' => [
            'reports.executive', 'reports.sales', 'reports.financial', 'reports.production',
            'reports.inventory', 'reports.procurement', 'reports.performance', 'reports.signals',
            'reports.data_quality', 'reports.explorer', 'reports.export',
        ],
        'accountant' => [
            'reports.sales', 'reports.financial', 'reports.production', 'reports.inventory',
            'reports.procurement', 'reports.performance',
        ],
        'outlet_manager'      => ['reports.sales', 'reports.production', 'reports.inventory', 'reports.performance'],
        'procurement_manager' => ['reports.production', 'reports.inventory', 'reports.procurement', 'reports.export_supply'],
        'procurement_officer' => ['reports.production', 'reports.inventory'],
    ];

    /** Taken from catalogue roles (reports.view is taken from every role). */
    private const REVOKE = [
        'admin'               => ['reports.financial', 'reports.export_supply'],
        'accountant'          => ['reports.export', 'reports.export_supply'],
        'outlet_manager'      => ['reports.export', 'reports.export_supply'],
        'procurement_manager' => ['reports.export'],
    ];

    /** Roles whose report grants the catalogue defines; any other role is one the owner made. */
    private const CATALOGUE = [
        'super_admin', 'admin', 'finance_manager', 'accountant', 'outlet_manager', 'procurement_manager',
        'procurement_officer', 'pos_clerk', 'tailor', 'system_admin',
    ];

    public function up(): void
    {
        if (!Schema::hasTable(self::LOG)) {
            Schema::create(self::LOG, function (Blueprint $table) {
                $table->id();
                $table->string('migration');
                $table->string('role_name')->nullable();
                $table->string('permission_name');
                // revoked | granted | created_permission | deleted_permission
                $table->string('action', 32);
                $table->timestamp('created_at')->nullable();
                $table->index('migration');
            });
        }

        DB::transaction(function () {
            foreach (self::GRANT as $roleName => $permissions) {
                $roleId = $this->roleId($roleName);
                if ($roleId === null) {
                    // A fresh database: roles do not exist until permission:sync
                    // creates them, already in the new shape.
                    continue;
                }
                foreach ($permissions as $permissionName) {
                    $this->grant($roleName, $roleId, $permissionName);
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

            $retiredId = $this->permissionId(self::RETIRED);
            if ($retiredId === null) {
                return;
            }

            foreach ($this->rolesHolding($retiredId) as $roleId => $roleName) {
                if (!in_array($roleName, self::CATALOGUE, true)) {
                    foreach (self::LEGACY_EQUIVALENT as $permissionName) {
                        $this->grant($roleName, $roleId, $permissionName);
                    }
                }
                $this->revoke($roleId, $retiredId);
                $this->log($roleName, self::RETIRED, 'revoked');
            }

            // Retired: nothing declares it any more, so sync will not bring it
            // back. Kept only while a person holds it directly.
            if (!DB::table($this->tables()['model_has_permissions'])->where('permission_id', $retiredId)->exists()) {
                DB::table($this->tables()['permissions'])->where('id', $retiredId)->delete();
                $this->log(null, self::RETIRED, 'deleted_permission');
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
            // Newest first: the retired permission is recreated before the
            // grants of it are put back, and grants are undone before the
            // permissions this migration created are considered for removal.
            $changes = DB::table(self::LOG)->where('migration', self::MIGRATION)->orderByDesc('id')->get();

            foreach ($changes as $change) {
                $permissionId = $this->permissionId($change->permission_name);
                $roleId = $change->role_name !== null ? $this->roleId($change->role_name) : null;

                if ($change->action === 'deleted_permission' && $permissionId === null) {
                    [$display, $description, $group] = self::RETIRED_META;
                    DB::table($this->tables()['permissions'])->insert([
                        'name' => $change->permission_name, 'guard_name' => 'sanctum',
                        'display_name' => $display, 'description' => $description, 'group' => $group,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } elseif ($change->action === 'granted' && $roleId !== null && $permissionId !== null) {
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

    /** @return array<int,string> role id => name */
    private function rolesHolding(int $permissionId): array
    {
        $t = $this->tables();

        return DB::table($t['role_has_permissions'])
            ->join($t['roles'], $t['roles'] . '.id', '=', $t['role_has_permissions'] . '.role_id')
            ->where($t['role_has_permissions'] . '.permission_id', $permissionId)
            ->orderBy($t['roles'] . '.id')
            ->pluck($t['roles'] . '.name', $t['roles'] . '.id')
            ->all();
    }

    private function holds(int $roleId, int $permissionId): bool
    {
        return DB::table($this->tables()['role_has_permissions'])
            ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists();
    }

    private function grant(string $roleName, int $roleId, string $permissionName): void
    {
        $permissionId = $this->permissionId($permissionName);
        if ($permissionId === null) {
            [$display, $description, $group] = self::NEW_PERMISSIONS[$permissionName]
                ?? [null, null, 'Reports'];
            DB::table($this->tables()['permissions'])->insert([
                'name' => $permissionName, 'guard_name' => 'sanctum',
                'display_name' => $display, 'description' => $description, 'group' => $group,
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
