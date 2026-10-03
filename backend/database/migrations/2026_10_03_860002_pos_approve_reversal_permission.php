<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening, Phase 4B part 2 — the outlet manager's band key for till
 * voids and refunds.
 *
 *   pos.approve_reversal   sign the first band of a till void (≤ KES 20,000)
 *                          or refund (≤ KES 5,000). → outlet_manager
 *
 * Why a key of its own: pos.void / pos.returns are what a clerk holds to ASK;
 * the band key must be one a clerk never holds, or one clerk could sign
 * another's void. No wildcard in use matches it (admin holds pos.access and
 * pos.eod_review by name).
 *
 * permission:sync grants the same on every container start; this migration
 * makes production right the moment it runs. Each change is written to
 * role_grant_changes so down() takes back exactly what up() gave. Direct user
 * grants are never touched.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_03_860002_pos_approve_reversal_permission';

    private const LOG = 'role_grant_changes';

    private const PERMISSION = 'pos.approve_reversal';

    private const META = ['Approve Till Voids & Refunds', 'Sign the outlet manager\'s band of a till void or refund, on the till with a PIN or from the Approvals inbox', 'POS'];

    private const ROLES = ['outlet_manager'];

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
            $t = config('permission.table_names');
            if ($this->permissionId() === null) {
                [$display, $description, $group] = self::META;
                DB::table($t['permissions'])->insert([
                    'name' => self::PERMISSION, 'guard_name' => 'sanctum',
                    'display_name' => $display, 'description' => $description, 'group' => $group,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->log(null, 'created_permission');
            }

            foreach (self::ROLES as $roleName) {
                $roleId = DB::table($t['roles'])->where('name', $roleName)->where('guard_name', 'sanctum')->value('id');
                if ($roleId === null) {
                    continue;   // fresh database: permission:sync creates the role already granted
                }
                $exists = DB::table($t['role_has_permissions'])
                    ->where('role_id', $roleId)->where('permission_id', $this->permissionId())->exists();
                if (!$exists) {
                    DB::table($t['role_has_permissions'])->insert(['role_id' => $roleId, 'permission_id' => $this->permissionId()]);
                    $this->log($roleName, 'granted');
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
            $t = config('permission.table_names');
            $permissionId = $this->permissionId();
            $changes = DB::table(self::LOG)->where('migration', self::MIGRATION)->orderByDesc('id')->get();

            foreach ($changes as $change) {
                if ($permissionId === null) {
                    break;
                }
                if ($change->action === 'granted') {
                    $roleId = DB::table($t['roles'])->where('name', $change->role_name)->where('guard_name', 'sanctum')->value('id');
                    if ($roleId !== null) {
                        DB::table($t['role_has_permissions'])->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
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

    private function permissionId(): ?int
    {
        $id = DB::table(config('permission.table_names.permissions'))
            ->where('name', self::PERMISSION)->where('guard_name', 'sanctum')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function log(?string $role, string $action): void
    {
        DB::table(self::LOG)->insert([
            'migration' => self::MIGRATION, 'role_name' => $role, 'permission_name' => self::PERMISSION,
            'action' => $action, 'created_at' => now(),
        ]);
    }
};
