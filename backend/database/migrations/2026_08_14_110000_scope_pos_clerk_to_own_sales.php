<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Closes the order-book leak: a cashier now sees their own sales, not the
 * shop's.
 *
 * This is the one change in the access-control programme that staff will feel.
 * Five cashiers stop seeing each other's POS orders, and the invoice list, the
 * CSV export and the search box narrow with them, because all three run the
 * same query.
 *
 * Declaring the scope in SyncPermissions::ROLE_SCOPES is not enough — deploys
 * run `php artisan migrate --force` and never `permission:sync`, so a
 * catalogue-only change never reaches production.
 *
 * DECIDED, not overlooked: a cashier cannot take a balance payment or process a
 * return against a colleague's sale. Both now answer with an explanation and a
 * route out ("a supervisor has to…") rather than a bare 404. The escalation
 * target is any role at scope 'all' — admin, or outlet_manager once it has a
 * holder.
 *
 * Reversible: down() puts pos_clerk back to 'all' and the leak reopens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')
            ->where('name', 'pos_clerk')
            ->where('guard_name', 'sanctum')
            ->update(['data_scope' => 'own']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'pos_clerk')
            ->where('guard_name', 'sanctum')
            ->update(['data_scope' => 'all']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
