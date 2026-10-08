<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening follow-up: the legacy space-named vocabulary ("view orders",
 * "create users", "manage settings" …) leaves every role.
 *
 * An early seeder wrote these; the application checks the dotted names
 * (orders.view). Phase 2 cleared them from system_admin and accountant only;
 * the live check after Phase 2 (2026-10-03) found admin still holding 31,
 * outlet_manager 13, procurement_officer 6, pos_clerk 5. Nothing staff can
 * reach consults them today — the one reader, UserPolicy, is never invoked and
 * now reads the dotted names — but a grant nobody can see is a back door
 * waiting for a future check to use it.
 *
 * Only ROLE grants are revoked; the permission rows stay (direct grants to a
 * person, if any, are left exactly as they are). Every revocation is logged to
 * role_grant_changes, so down() restores precisely what was taken.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_03_600001_revoke_legacy_space_named_role_grants';

    private const LOG = 'role_grant_changes';

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

        $t = config('permission.table_names');

        DB::transaction(function () use ($t) {
            $grants = DB::table($t['role_has_permissions'] . ' as rp')
                ->join($t['roles'] . ' as r', 'r.id', '=', 'rp.role_id')
                ->join($t['permissions'] . ' as p', 'p.id', '=', 'rp.permission_id')
                ->where('p.name', 'like', '% %')
                ->get(['rp.role_id', 'rp.permission_id', 'r.name as role_name', 'p.name as permission_name']);

            foreach ($grants as $g) {
                DB::table($t['role_has_permissions'])
                    ->where('role_id', $g->role_id)->where('permission_id', $g->permission_id)->delete();
                DB::table(self::LOG)->insert([
                    'migration' => self::MIGRATION, 'role_name' => $g->role_name,
                    'permission_name' => $g->permission_name, 'action' => 'revoked', 'created_at' => now(),
                ]);
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
            foreach (DB::table(self::LOG)->where('migration', self::MIGRATION)->get() as $change) {
                $roleId = DB::table($t['roles'])->where('name', $change->role_name)->where('guard_name', 'sanctum')->value('id');
                $permissionId = DB::table($t['permissions'])->where('name', $change->permission_name)->where('guard_name', 'sanctum')->value('id');
                if ($roleId && $permissionId && !DB::table($t['role_has_permissions'])
                        ->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    DB::table($t['role_has_permissions'])->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
            DB::table(self::LOG)->where('migration', self::MIGRATION)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
