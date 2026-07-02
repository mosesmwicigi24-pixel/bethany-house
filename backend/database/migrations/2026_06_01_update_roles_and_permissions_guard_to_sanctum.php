<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Update all roles and permissions from guard_name 'web' → 'sanctum'.
     *
     * Safe to run multiple times - only touches rows that are still on 'web'.
     * After running, re-sync permissions:
     *   php artisan permission:sync
     *   php artisan permission:cache-reset
     */
    public function up(): void
    {
        // Update roles
        $rolesUpdated = DB::table('roles')
            ->where('guard_name', 'web')
            ->update(['guard_name' => 'sanctum']);

        // Update permissions
        $permissionsUpdated = DB::table('permissions')
            ->where('guard_name', 'web')
            ->update(['guard_name' => 'sanctum']);

        // Update the role_has_permissions pivot (guard is stored there too in Spatie v6+)
        // Safe no-op on older versions where the pivot has no guard column
        if (DB::getSchemaBuilder()->hasColumn('role_has_permissions', 'guard_name')) {
            DB::table('role_has_permissions')
                ->where('guard_name', 'web')
                ->update(['guard_name' => 'sanctum']);
        }

        // Update model_has_roles pivot
        if (DB::getSchemaBuilder()->hasColumn('model_has_roles', 'guard_name')) {
            DB::table('model_has_roles')
                ->where('guard_name', 'web')
                ->update(['guard_name' => 'sanctum']);
        }

        // Update model_has_permissions pivot
        if (DB::getSchemaBuilder()->hasColumn('model_has_permissions', 'guard_name')) {
            DB::table('model_has_permissions')
                ->where('guard_name', 'web')
                ->update(['guard_name' => 'sanctum']);
        }

        echo "  Roles updated:       {$rolesUpdated}\n";
        echo "  Permissions updated: {$permissionsUpdated}\n";
    }

    /**
     * Reverse: revert sanctum → web.
     * Only reverts rows that were originally 'web' (i.e. all sanctum rows,
     * since the system started on web).
     */
    public function down(): void
    {
        DB::table('roles')
            ->where('guard_name', 'sanctum')
            ->update(['guard_name' => 'web']);

        DB::table('permissions')
            ->where('guard_name', 'sanctum')
            ->update(['guard_name' => 'web']);

        if (DB::getSchemaBuilder()->hasColumn('role_has_permissions', 'guard_name')) {
            DB::table('role_has_permissions')
                ->where('guard_name', 'sanctum')
                ->update(['guard_name' => 'web']);
        }

        if (DB::getSchemaBuilder()->hasColumn('model_has_roles', 'guard_name')) {
            DB::table('model_has_roles')
                ->where('guard_name', 'sanctum')
                ->update(['guard_name' => 'web']);
        }

        if (DB::getSchemaBuilder()->hasColumn('model_has_permissions', 'guard_name')) {
            DB::table('model_has_permissions')
                ->where('guard_name', 'sanctum')
                ->update(['guard_name' => 'web']);
        }
    }
};