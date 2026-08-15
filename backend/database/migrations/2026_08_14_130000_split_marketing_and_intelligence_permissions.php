<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Splits two overloaded permissions, and delivers the new ones to production.
 *
 * products.view is a READ permission on the catalogue. It was also opening the
 * marketing calendar and — because the home-front pages are built on marketing
 * banners — EDITING the public storefront. A cashier held it without anyone
 * granting it, through the orders.create dependency.
 *
 * customers.view is what a cashier needs to serve someone at the counter. It
 * was also opening customer geography, channel engagement and churn risk.
 *
 * Granted to admin only. No other role has a marketing or analytics job today:
 * outlet_manager runs a shop, procurement buys, finance reconciles. If someone
 * takes on marketing later, marketing.view is now a thing that can be given to
 * them without also handing over the product catalogue.
 *
 * Declaring them in SyncPermissions is not enough — deploys run `migrate` and
 * never `permission:sync`.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'marketing.view',
        'marketing.manage',
        'intelligence.view',
    ];

    public function up(): void
    {
        $created = collect(self::PERMISSIONS)->map(
            fn (string $name) => Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'sanctum'],
            ),
        );

        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        if ($admin) {
            $admin->givePermissionTo($created->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'sanctum')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
