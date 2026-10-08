<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tailoring PR 2 — the person who sewed it does not pass it (owner's Policy 4,
 * approved 2026-10-05: option 1, "managers inspect").
 *
 * The tailor role held production.submit_qc through the @shop_floor bundle,
 * so every tailor could record the final QC result, including on their own
 * work. Final QC now belongs to the roles that already hold it beside
 * approve_qc: outlet managers and the Operations Head (admin), plus the owner.
 * The code side is in ProductionController::qualityCheck — nobody, whatever
 * their role, may inspect an order they worked a stage on (MakerChecker).
 *
 * permission:sync only ever ADDS grants, so the catalogue change alone would
 * leave production's tailors holding the key. This takes it off the ROLE.
 * A submit_qc given to a specific person directly (model_has_permissions) is
 * an individual decision and is left exactly as it is.
 *
 * The revocation is written to role_grant_changes, so down() gives back
 * precisely what this took — nothing if the role never held it.
 */
return new class extends Migration
{
    private const MIGRATION = '2026_10_05_100001_tailors_stop_submitting_qc';

    private const LOG = 'role_grant_changes';

    private const ROLE = 'tailor';

    private const PERMISSION = 'production.submit_qc';

    public function up(): void
    {
        if (! Schema::hasTable(self::LOG)) {
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
            $roleId       = $this->id('roles', self::ROLE);
            $permissionId = $this->id('permissions', self::PERMISSION);
            if ($roleId === null || $permissionId === null) {
                // A fresh database: permission:sync builds the role already
                // without the key.
                return;
            }

            $deleted = DB::table($this->tables()['role_has_permissions'])
                ->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();

            if ($deleted > 0) {
                DB::table(self::LOG)->insert([
                    'migration'       => self::MIGRATION,
                    'role_name'       => self::ROLE,
                    'permission_name' => self::PERMISSION,
                    'action'          => 'revoked',
                    'created_at'      => now(),
                ]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::LOG)) {
            return;
        }

        DB::transaction(function () {
            $revoked = DB::table(self::LOG)
                ->where('migration', self::MIGRATION)->where('action', 'revoked')->exists();

            $roleId       = $this->id('roles', self::ROLE);
            $permissionId = $this->id('permissions', self::PERMISSION);
            if ($revoked && $roleId !== null && $permissionId !== null) {
                DB::table($this->tables()['role_has_permissions'])->insertOrIgnore([
                    'role_id' => $roleId, 'permission_id' => $permissionId,
                ]);
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

    private function id(string $table, string $name): ?int
    {
        $id = DB::table($this->tables()[$table])
            ->where('name', $name)->where('guard_name', 'sanctum')->value('id');

        return $id === null ? null : (int) $id;
    }
};
