<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Takes the agent's campaign pass-through back off every ROLE.
 *
 * 2026_09_02_000003 granted `pos.discount_campaign` to the agent's service
 * account and to no role. Within minutes the `admin` role held it too — because
 * admin is declared as `pos.*` and the container ENTRYPOINT runs
 * `permission:sync` on every start, expanding that wildcard over the newly
 * catalogued permission. (The note on 2026_08_14_090000 saying deploys never
 * run permission:sync is out of date; docker/entrypoint.sh line 56 does.)
 *
 * SyncPermissions only ever calls givePermissionTo, never revoke, so adding the
 * permission to $wildcardExcluded stops it happening again but cannot undo what
 * already happened. Hence this.
 *
 * Nothing is lost: admin holds pos.discount_override, which is the unbounded
 * version. The point is that a capability meant for one service account should
 * not arrive at a human role as a side effect of a wildcard.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::where('name', 'pos.discount_campaign')
            ->where('guard_name', 'sanctum')
            ->first();

        if ($permission) {
            // Roles only. The direct grant on the agent's account is the one
            // thing that must survive.
            $permission->roles()->detach();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing to restore: no role was ever meant to hold this.
    }
};
