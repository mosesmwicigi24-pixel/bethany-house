<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give the POS clerk role visibility of the whole sales surface: Orders
 * (POS / Online / WhatsApp / normal, and orders converted from an invoice) and
 * Invoices — both gated by `orders.view` — plus Quotations, gated by
 * `quotations.view` which was previously granted to `admin` only.
 *
 * Deploys run `migrate`, not `permission:sync`, so the live pos_clerk role only
 * has what a migration granted it — this makes the grant explicit and guaranteed.
 * Idempotent: firstOrCreate + givePermissionTo are no-ops if already present.
 */
return new class extends Migration
{
    private array $names = [
        'orders.view',        // POS/Online/WhatsApp/normal Orders + Invoices nav
        'quotations.view',    // Quotations nav
    ];

    public function up(): void
    {
        $posClerk = Role::where('name', 'pos_clerk')->where('guard_name', 'sanctum')->first();
        if (! $posClerk) {
            return; // role not provisioned on this environment — nothing to grant
        }

        foreach ($this->names as $name) {
            $perm = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
            $posClerk->givePermissionTo($perm);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $posClerk = Role::where('name', 'pos_clerk')->where('guard_name', 'sanctum')->first();
        $perm = Permission::where('name', 'quotations.view')->where('guard_name', 'sanctum')->first();
        // Only revoke quotations.view — orders.view is core to the POS clerk and
        // may have predated this migration; leave it in place on rollback.
        if ($posClerk && $perm) {
            $posClerk->revokePermissionTo($perm);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
