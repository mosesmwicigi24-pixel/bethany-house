<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 2026-10-07: "all the permissions should be granted to super admin by
 * default". The role passed every check through Gate::before but held only 68
 * of 174 permissions, so the Roles screen looked incomplete and explicit
 * checks disagreed. This grants the super_admin role every sanctum permission
 * now; SyncPermissions keeps it complete on every start (including permissions
 * declared later). Each grant is logged in role_grant_changes, so down() takes
 * back precisely what this migration gave.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_07_100001_grant_super_admin_every_permission';
    private const LOG = 'role_grant_changes';

    public function up(): void
    {
        $t = config('permission.table_names');
        $roleId = DB::table($t['roles'])->where('name', 'super_admin')->where('guard_name', 'sanctum')->value('id');
        if (!$roleId) {
            return;
        }
        $this->ensureLog();

        DB::transaction(function () use ($t, $roleId) {
            $missing = DB::table($t['permissions'])
                ->where('guard_name', 'sanctum')
                ->whereNotIn('id', DB::table($t['role_has_permissions'])->where('role_id', $roleId)->select('permission_id'))
                ->get(['id', 'name']);
            foreach ($missing as $p) {
                DB::table($t['role_has_permissions'])->insert(['role_id' => $roleId, 'permission_id' => $p->id]);
                DB::table(self::LOG)->insert([
                    'migration' => self::MIGRATION, 'role_name' => 'super_admin',
                    'permission_name' => $p->name, 'action' => 'granted', 'created_at' => now(),
                ]);
            }
        });

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::LOG)) {
            return;
        }
        $t = config('permission.table_names');
        $roleId = DB::table($t['roles'])->where('name', 'super_admin')->where('guard_name', 'sanctum')->value('id');

        DB::transaction(function () use ($t, $roleId) {
            $names = DB::table(self::LOG)->where('migration', self::MIGRATION)->where('action', 'granted')->pluck('permission_name');
            if ($roleId && $names->isNotEmpty()) {
                $ids = DB::table($t['permissions'])->where('guard_name', 'sanctum')->whereIn('name', $names)->pluck('id');
                DB::table($t['role_has_permissions'])->where('role_id', $roleId)->whereIn('permission_id', $ids)->delete();
            }
            DB::table(self::LOG)->where('migration', self::MIGRATION)->delete();
        });

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureLog(): void
    {
        if (Schema::hasTable(self::LOG)) {
            return;
        }
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
};
