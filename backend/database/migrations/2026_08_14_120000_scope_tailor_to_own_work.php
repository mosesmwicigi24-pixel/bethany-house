<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * A tailor sees the work assigned to them, not the shop's.
 *
 * Nine people — the largest group in the hub. GET /admin/production-tasks was
 * gated on production.view alone, so every tailor could list every task in the
 * business, and the production board showed them all 103 orders.
 *
 * "Own" resolves differently here than for a cashier, because a tailor never
 * creates the work they do: a task is theirs through assigned_to, and a
 * production order is theirs if they raised it OR have a task on it.
 *
 * As with pos_clerk, declaring the scope in SyncPermissions::ROLE_SCOPES is not
 * enough — deploys run `migrate` and never `permission:sync`.
 *
 * Reversible: down() puts tailor back to 'all'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('name', 'tailor')
            ->where('guard_name', 'sanctum')
            ->update(['data_scope' => 'own']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'tailor')
            ->where('guard_name', 'sanctum')
            ->update(['data_scope' => 'all']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
