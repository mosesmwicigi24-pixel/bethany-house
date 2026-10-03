<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-account lockout after failed staff sign-ins (role plan §12.4).
 *
 * A failure is a wrong password, or a wrong second-step code (which means the
 * password was right). Counted per account since the later of its last
 * successful sign-in or unlock, within the last 24 hours:
 *
 *   5th failure    → locked for 15 minutes (sign-in refused, right password or not)
 *   10th failure   → locked until a system_admin / super_admin unlocks it;
 *                    every session the account holds ends.
 *
 * The per-IP `throttle:auth` limiter still applies in front of this; this one
 * follows the ACCOUNT across addresses. Attempts made while locked are
 * refused without checking the password and are not counted.
 */
class AccountLockout
{
    /** @return array{kind:string, until:?Carbon}|null */
    public function lockOf(User $user): ?array
    {
        if ($user->locked_at !== null) {
            return ['kind' => 'held', 'until' => null];
        }
        $until = $user->locked_until ? Carbon::parse($user->locked_until) : null;
        if ($until !== null && $until->isFuture()) {
            return ['kind' => 'temporary', 'until' => $until];
        }

        return null;
    }

    public function message(array $lock): string
    {
        return $lock['kind'] === 'held'
            ? 'This account is locked after too many failed sign-ins. Ask a system administrator to unlock it.'
            : 'Too many failed sign-ins. This account is locked until ' . $lock['until']->timezone(config('app.timezone'))->format('H:i') . '.';
    }

    /** Records one failure; returns the lock it caused, if any. */
    public function recordFailure(User $user, Request $request, string $reason): ?array
    {
        DB::table('login_failures')->insert([
            'user_id'    => $user->id,
            'reason'     => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
            'created_at' => now(),
        ]);

        $count = $this->failuresSinceReset($user);
        $cfg   = (array) config('security.lockout', []);

        if ($count >= (int) ($cfg['hard_after'] ?? 10)) {
            $user->forceFill(['locked_at' => now(), 'lock_reason' => 'too_many_failed_sign_ins', 'locked_until' => null])->save();
            $revoked = $user->tokens()->delete();
            ActivityLogService::log('account_locked', $user, [
                'kind' => 'held', 'failures' => $count, 'sessions_revoked' => $revoked,
            ], "Account locked until unlocked ({$count} failed sign-ins): {$user->email}");

            return $this->lockOf($user->fresh());
        }

        $soft = (int) ($cfg['soft_after'] ?? 5);
        if ($count >= $soft && $count % $soft === 0) {
            $until = now()->addMinutes((int) ($cfg['soft_minutes'] ?? 15));
            $user->forceFill(['locked_until' => $until, 'lock_reason' => 'failed_sign_ins'])->save();
            ActivityLogService::log('account_locked', $user, [
                'kind' => 'temporary', 'failures' => $count, 'until' => $until->toIso8601String(),
            ], "Account locked for {$cfg['soft_minutes']} minutes ({$count} failed sign-ins): {$user->email}");

            return $this->lockOf($user->fresh());
        }

        return null;
    }

    /** A successful sign-in restarts the count. */
    public function recordSuccess(User $user, Request $request): void
    {
        $this->clearFailures($user);
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'locked_until'  => null,
        ])->save();
    }

    /** $actor null = the server console (artisan auth:unlock). */
    public function unlock(User $target, ?User $actor, string $via = 'console'): void
    {
        $was = $this->lockOf($target);
        $this->clearFailures($target);
        $target->forceFill([
            'locked_at'    => null,
            'locked_until' => null,
            'lock_reason'  => null,
            'unlocked_at'  => now(),
        ])->save();

        ActivityLogService::log('account_unlocked', $target, ['was' => $was['kind'] ?? 'not_locked', 'via' => $via],
            "Account unlocked: {$target->email}" . ($actor ? " by {$actor->email}" : ' from the server console'), $actor);
    }

    public function failuresSinceReset(User $user): int
    {
        return DB::table('login_failures')
            ->where('user_id', $user->id)
            ->whereNull('cleared_at')
            ->where('created_at', '>', now()->subHours((int) config('security.lockout.hard_window_hrs', 24)))
            ->count();
    }

    private function clearFailures(User $user): void
    {
        DB::table('login_failures')->where('user_id', $user->id)->whereNull('cleared_at')->update(['cleared_at' => now()]);
    }
}
