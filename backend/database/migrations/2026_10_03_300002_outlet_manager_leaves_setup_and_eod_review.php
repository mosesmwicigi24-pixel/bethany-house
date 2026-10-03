<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role hardening, Phase 1C item 9 — the "Setup leak".
 *
 * outlet_manager came to hold settings.view as a side effect: the dependency
 * map handed it to anyone with orders.set_shipping_fee (the shipping-fee
 * modal read its method picker from a Setup endpoint), and EoD review was
 * gated on the same key. The result was Setup — business settings, tax rates,
 * currencies, payment-method configuration — open to every outlet manager.
 *
 * The code no longer needs it: the picker reads /admin/orders/shipping-methods
 * (orders.set_shipping_fee) and EoD review has its own key, pos.eod_review.
 * But permission:sync only ever ADDS grants, so the old settings.view stays on
 * the role in production until something takes it off. This does, and grants
 * pos.eod_review to the three reviewing roles so the EoD screens keep working
 * the moment the code is live, without waiting for sync.
 *
 * Only the ROLE grant is revoked. A settings.view given to a specific person
 * directly (model_has_permissions) is a deliberate individual decision and is
 * left alone.
 */
return new class extends Migration
{
    private const REVIEWERS = ['admin', 'outlet_manager', 'finance_manager'];

    public function up(): void
    {
        $review = Permission::firstOrCreate(
            ['name' => 'pos.eod_review', 'guard_name' => 'sanctum'],
        );

        foreach (self::REVIEWERS as $name) {
            Role::where('name', $name)->where('guard_name', 'sanctum')->first()
                ?->givePermissionTo($review);
        }

        $om       = Role::where('name', 'outlet_manager')->where('guard_name', 'sanctum')->first();
        $settings = Permission::where('name', 'settings.view')->where('guard_name', 'sanctum')->first();
        if ($om && $settings && $om->hasPermissionTo($settings)) {
            $om->revokePermissionTo($settings);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Restores the previous shape: outlet_manager back on settings.view,
        // and the review key removed (the routes it gates would then refuse
        // everyone but super_admin, so roll the code back with it).
        $om       = Role::where('name', 'outlet_manager')->where('guard_name', 'sanctum')->first();
        $settings = Permission::where('name', 'settings.view')->where('guard_name', 'sanctum')->first();
        if ($om && $settings) {
            $om->givePermissionTo($settings);
        }

        $this->dropPermission('pos.eod_review');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Remove a permission and every grant of it at the table level.
     * Permission::delete() fires Spatie's detach of the guard's user model,
     * which cannot resolve a model for the sanctum guard and throws.
     */
    private function dropPermission(string $name): void
    {
        $tables = config('permission.table_names');
        $id = DB::table($tables['permissions'])->where('name', $name)->where('guard_name', 'sanctum')->value('id');
        if ($id) {
            DB::table($tables['role_has_permissions'])->where('permission_id', $id)->delete();
            DB::table($tables['model_has_permissions'])->where('permission_id', $id)->delete();
            DB::table($tables['permissions'])->where('id', $id)->delete();
        }
    }
};
