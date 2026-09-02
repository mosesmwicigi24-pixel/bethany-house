<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lets the sales agent carry an owner-declared campaign discount into an order.
 *
 * PosDiscountPolicy's 5% ceiling is about CLERK DISCRETION — a person at a till
 * deciding, in the moment, to let a sale go for less. The agent is not
 * exercising discretion: it is executing a campaign the owner declared, at a
 * price computed by code. Holding it to the clerk ceiling meant a 10% campaign
 * refused every order it touched, which is why the discount travelled as a NOTE
 * for a human to apply by hand — and the customer could pay the undiscounted
 * link before anyone read it.
 *
 * Granted to the agent's SERVICE ACCOUNT and to no role, so no person acquires
 * it, and it is still bounded by pos.agent_discount_cap_percent.
 *
 * Declaring it in SyncPermissions::PERMISSIONS is not enough — deploys run
 * `migrate --force` and never `permission:sync`, so a permission that lives
 * only in the catalogue never reaches production. Same lesson as
 * 2026_08_14_090000.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'pos.discount_campaign', 'guard_name' => 'sanctum'],
        );

        $email = (string) config('pos.agent_user_email');
        if ($email !== '') {
            // No such user in a fresh environment — then there is no agent yet,
            // and the permission simply waits for one.
            User::where('email', $email)->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'pos.discount_campaign')
            ->where('guard_name', 'sanctum')
            ->first()?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
