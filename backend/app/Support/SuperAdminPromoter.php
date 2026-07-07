<?php

namespace App\Support;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Makes an account THE super admin, idempotently.
 *
 * A working super admin needs three things in this app:
 *   1. the `super_admin` role (guard `sanctum`) — drives the Gate::before bypass
 *      (AuthServiceProvider) so every ability passes;
 *   2. user_type SYSTEM — canAccessAdmin() requires isSystem()/isStaff(), so a
 *      customer-typed account cannot reach the admin console;
 *   3. status 'active' — inactive accounts are refused at login and excluded
 *      from notifications.
 *
 * If the account does not exist yet it is created (email verified, password
 * setup + 2FA setup required) so the owner sets their own password via the
 * "forgot password" flow. Safe to run repeatedly.
 */
class SuperAdminPromoter
{
    /**
     * @return array{created:bool, user_id:int}
     */
    public static function ensure(string $email): array
    {
        $created = false;
        $user    = User::withTrashed()->where('email', $email)->first();

        if ($user && $user->trashed()) {
            $user->restore();
        }

        if (!$user) {
            $user = User::create([
                'first_name'        => 'Super',
                'last_name'         => 'Admin',
                'email'             => $email,
                'user_type'         => UserType::SYSTEM,
                'password'          => Hash::make(Str::password(20)),
                'email_verified_at' => now(),
                'status'            => 'active',
                'two_factor_enabled' => false,
                'must_setup_2fa'    => true,
            ]);
            $created = true;
        } else {
            // Promote an existing account without disturbing its name/password.
            $user->forceFill([
                'user_type' => UserType::SYSTEM,
                'status'    => 'active',
            ])->save();
        }

        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return ['created' => $created, 'user_id' => $user->id];
    }
}
