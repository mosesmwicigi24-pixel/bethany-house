<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 4A: an outlet manager sees the outlets they are assigned to.
 *
 * Declared in SyncPermissions::ROLE_SCOPES too, but deploys run
 * `php artisan migrate --force` and never `permission:sync`, so the catalogue
 * alone would never reach production (the same reason the pos_clerk and
 * tailor scopes shipped as migrations).
 *
 * What a manager will feel: orders, quotations, invoices, the pending queue,
 * payments awaiting approval, stock, transfers, production raised at their
 * shop and that shop's customers narrow to the outlets on the outlet_user
 * pivot. A manager with NO assignment sees none of it — an empty assignment
 * is nothing, never everything. Reports do not move: the owner kept them
 * business-wide (2026-10-03).
 *
 * Reversible: down() puts the role back to 'all'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('name', 'outlet_manager')
            ->where('guard_name', 'sanctum')
            ->update(['data_scope' => 'outlet']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'outlet_manager')
            ->where('guard_name', 'sanctum')
            ->update(['data_scope' => 'all']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
