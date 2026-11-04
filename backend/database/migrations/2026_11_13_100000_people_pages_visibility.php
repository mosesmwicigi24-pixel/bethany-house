<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who sees People pages (owner, 2026-11-04: "The super admin, admin and
 * outlet manager is the one who should see 'people' content and private
 * information eg days requested off").
 *
 * permission:sync only ever ADDS permissions, so a grant taken out of the
 * role catalogue has to be revoked here; SyncPermissions::ROLE_DENIES keeps
 * it revoked from now on.
 *
 *   production_manager  no People pages (Overview, Requests, Attendance, Roster, Timesheets, Leave)
 *   system_admin        none either — staff accounts, not their attendance or leave
 *   finance_manager     keeps Employee files and Payroll, and Timesheets through
 *                       timesheets.lock; loses Overview, Requests, Attendance, Roster, Leave
 */
return new class extends Migration
{
    private const REVOKE = [
        'production_manager' => ['attendance.view_team', 'attendance.manage', 'roster.manage', 'leave.approve'],
        'system_admin'       => ['attendance.view_team', 'attendance.manage', 'roster.manage', 'leave.approve'],
        'finance_manager'    => ['attendance.view_team', 'attendance.manage', 'roster.manage', 'leave.approve'],
    ];

    public function up(): void
    {
        foreach (self::REVOKE as $role => $permissions) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
            if (!$roleId) continue;                      // a fresh database: permission:sync builds the roles already in this shape
            $permIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');
            DB::table('role_has_permissions')->where('role_id', $roleId)->whereIn('permission_id', $permIds)->delete();
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Not restored automatically: re-granting is a deliberate decision, made in Roles.
    }
};
