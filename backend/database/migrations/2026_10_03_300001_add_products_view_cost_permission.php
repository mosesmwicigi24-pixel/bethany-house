<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening, Phase 1C item 1: `products.view_cost`.
 *
 * Cost (cost_price, material unit cost, BOM line and total cost) is stripped
 * from admin payloads for anyone without this permission. permission:sync
 * would create and grant it on the next container start, but the strip ships
 * in the same image — so for the minutes between the migration and the sync,
 * admin, finance and procurement would see blanks. Granting it here makes the
 * feature correct the moment the code is live, without relying on sync.
 *
 * admin is listed explicitly even though products.* reaches it on sync, for
 * the same reason.
 *
 * Roles absent from a fresh database are skipped; permission:sync creates
 * them later with this grant already in their definition.
 */
return new class extends Migration
{
    private const ROLES = ['admin', 'finance_manager', 'procurement_manager', 'procurement_officer'];

    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'products.view_cost', 'guard_name' => 'sanctum'],
        );

        foreach (self::ROLES as $name) {
            Role::where('name', $name)->where('guard_name', 'sanctum')->first()
                ?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->dropPermission('products.view_cost');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Remove a permission and every grant of it at the table level.
     * Permission::delete() fires Spatie's detach of the guard's user model,
     * which cannot resolve a model for the sanctum guard and throws.
     */
    private function dropPermission(string $name): void
    {
        $tables = config('permission.table_names');
        $id = DB::table($tables['permissions'])->where('name', $name)->where('guard_name', 'sanctum')->value('id');
        if ($id) {
            DB::table($tables['role_has_permissions'])->where('permission_id', $id)->delete();
            DB::table($tables['model_has_permissions'])->where('permission_id', $id)->delete();
            DB::table($tables['permissions'])->where('id', $id)->delete();
        }
    }
};
