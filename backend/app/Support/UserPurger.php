<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Removes a user and their entire notification / auth footprint by email.
 *
 * "Remove completely" here means: strip every role and permission (so the
 * account is excluded from all role-based notifications — see
 * NotificationService::usersWithRole), drop every device push target, API token
 * and pending session, then hard-delete the row. The User model uses
 * SoftDeletes, so deletion goes through the query builder to bypass the soft
 * delete and remove the record outright.
 *
 * If historical records (orders, cash registers, audit trail…) still reference
 * the user via a RESTRICT foreign key, the hard delete is attempted inside a
 * savepoint so an FK error cannot abort the surrounding migration; the row is
 * then left as a fully neutralised, de-identified, de-roled, inactive shell —
 * which still guarantees no notification is ever sent to it.
 *
 * Safe to call when the user does not exist (fresh installs) — it no-ops.
 */
class UserPurger
{
    /**
     * @return array{found:int, deleted:int, neutralized:int}
     */
    public static function byEmail(string $email): array
    {
        $result = ['found' => 0, 'deleted' => 0, 'neutralized' => 0];

        if (!Schema::hasTable('users')) {
            return $result;
        }

        $ids = DB::table('users')->where('email', $email)->pluck('id')->all();
        if (empty($ids)) {
            return $result;
        }
        $result['found'] = count($ids);

        $morph = (new User())->getMorphClass(); // 'App\Models\User' (no morph map)

        // 1. Roles & direct permissions → the account can no longer appear in any
        //    role-based recipient list, so no notification will target it.
        foreach (['model_has_roles', 'model_has_permissions'] as $pivot) {
            if (Schema::hasTable($pivot)) {
                DB::table($pivot)->whereIn('model_id', $ids)->where('model_type', $morph)->delete();
            }
        }

        // 2. Delivery channels & sessions: device push targets, API tokens,
        //    stored notifications, active sessions, pending password resets.
        if (Schema::hasTable('push_subscriptions')) {
            DB::table('push_subscriptions')->whereIn('user_id', $ids)->delete();
        }
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $ids)->where('tokenable_type', $morph)->delete();
        }
        if (Schema::hasTable('notifications')) {
            DB::table('notifications')->whereIn('notifiable_id', $ids)->where('notifiable_type', $morph)->delete();
        }
        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }
        if (Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        }

        // 3. Neutralise the row first, so that even if the hard delete below is
        //    blocked by historical FK references, no identity or access survives.
        $scrub = ['updated_at' => now()];
        if (Schema::hasColumn('users', 'status'))             $scrub['status'] = 'inactive';
        if (Schema::hasColumn('users', 'email'))              $scrub['email'] = 'removed+' . $ids[0] . '@bethanyhouse.invalid';
        if (Schema::hasColumn('users', 'first_name'))         $scrub['first_name'] = 'Removed';
        if (Schema::hasColumn('users', 'last_name'))          $scrub['last_name'] = 'User';
        if (Schema::hasColumn('users', 'phone'))              $scrub['phone'] = null;
        if (Schema::hasColumn('users', 'password'))           $scrub['password'] = Hash::make(Str::random(48));
        if (Schema::hasColumn('users', 'remember_token'))     $scrub['remember_token'] = null;
        if (Schema::hasColumn('users', 'two_factor_enabled')) $scrub['two_factor_enabled'] = false;
        if (Schema::hasColumn('users', 'email_verified_at'))  $scrub['email_verified_at'] = null;
        DB::table('users')->whereIn('id', $ids)->update($scrub);

        // 4. Hard delete (bypasses SoftDeletes). Per-id savepoint so an FK block
        //    on one leaves the others deleted and never poisons the transaction.
        foreach ($ids as $id) {
            try {
                DB::beginTransaction();
                $result['deleted'] += DB::table('users')->where('id', $id)->delete();
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
            }
        }
        $result['neutralized'] = $result['found'] - $result['deleted'];

        return $result;
    }
}
