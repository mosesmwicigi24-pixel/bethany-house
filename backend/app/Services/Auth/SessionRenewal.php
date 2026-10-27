<?php

namespace App\Services\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\ActivityLogService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Sliding renewal of a signed-in session (native apps, Step 2 of the native
 * apps programme).
 *
 * A bearer token is minted with a per-token TTL (config sanctum.access_ttl_minutes,
 * 7 days) and, for staff, is also bound by the role's idle and absolute limits
 * (SessionPolicy). Before this, a token that reached its TTL simply died and the
 * person signed in again; a phone that is used every day therefore saw a sign-in
 * screen once a week for no security reason.
 *
 * Renewal re-mints the token for the same session without widening any limit:
 *
 *   - the request itself must pass SessionPolicy::admit() (it runs in the auth
 *     guard before this code), so an idle, absolute-expired or PIN-locked
 *     session can never renew;
 *   - the new token keeps the session's original created_at, so the absolute
 *     limit still counts from the real sign-in;
 *   - the new expiry is the TTL from now, capped at the absolute limit;
 *   - the old token is revoked in the same transaction (one session, one token);
 *   - step-up does not carry over: the new token starts un-stepped-up;
 *   - kiosk tokens (ability "kiosk") and tokens without an expiry are not
 *     renewable — the first is a device token, the second has nothing to renew.
 */
class SessionRenewal
{
    public function __construct(private SessionPolicy $policy) {}

    /** Why a session may not be renewed, or null when it may. */
    public function refusal(PersonalAccessToken $token): ?string
    {
        $abilities = (array) ($token->abilities ?? []);
        if (!in_array('*', $abilities, true) || in_array('kiosk', $abilities, true)) {
            return 'not_renewable';
        }
        if ($token->expires_at === null) {
            return 'no_expiry';
        }
        if ($token->locked_at !== null) {
            return 'pin_locked';
        }

        return null;
    }

    /**
     * Mint the replacement token and revoke the current one.
     *
     * @return array{token: NewAccessToken, expires_at: CarbonInterface, session_started_at: ?CarbonInterface}
     */
    public function renew(User $user, PersonalAccessToken $current): array
    {
        $ttl        = (int) config('sanctum.access_ttl_minutes', 0);
        $startedAt  = $current->created_at;
        $expiresAt  = now()->addMinutes(max(1, $ttl));

        $limits = $this->policy->limitsFor($user);
        if ($limits !== null && $startedAt) {
            $absoluteEnd = $startedAt->copy()->addMinutes($limits['absolute']);
            if ($absoluteEnd->lt($expiresAt)) {
                $expiresAt = $absoluteEnd;
            }
        }

        return DB::transaction(function () use ($user, $current, $startedAt, $expiresAt) {
            $new = $user->createToken($current->name, ['*'], $expiresAt);

            /** @var PersonalAccessToken $row */
            $row = $new->accessToken;
            $row->forceFill([
                // Same session: the absolute clock keeps counting from sign-in.
                'created_at'     => $startedAt,
                'device_id'      => $current->device_id,
                'ip_address'     => $current->ip_address,
                'user_agent'     => $current->user_agent,
                // Renewing is activity; step-up is not inherited.
                'last_active_at' => now(),
                'stepped_up_at'  => null,
            ])->save();

            $current->delete();

            try {
                ActivityLogService::log('session_renewed', $user, [
                    'old_token_id' => $current->id,
                    'token_id'     => $row->id,
                    'expires_at'   => $expiresAt->toIso8601String(),
                ], 'Session renewed', $user);
            } catch (\Throwable) {
                // Audit must never block the renewal itself; the token rows are the record.
            }

            return [
                'token'              => $new,
                'expires_at'         => $expiresAt,
                'session_started_at' => $startedAt,
            ];
        });
    }
}
