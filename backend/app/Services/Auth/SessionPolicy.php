<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Per-role session limits (role plan §12.3), enforced on the TOKEN on every
 * bearer request. Called from the one Sanctum token check in
 * AuthServiceProvider, so no route can be reached around it.
 *
 *   absolute   minutes since sign-in (token created_at)            → revoked, 401
 *   idle       minutes since the person last did something          → revoked, 401
 *              (last_active_at; requests the console marks as
 *              background polling do not move it)
 *   pin_lock   a clerk's idle limit holds the session instead:      → 423 pin_locked
 *              every request but PIN unlock and sign-out is refused
 *              until the clerk's PIN is entered. The sale and the
 *              register are untouched.
 *
 * A person with several roles gets the strictest of each limit. Storefront
 * customers (not admin-capable) are not subject to staff limits.
 */
class SessionPolicy
{
    /** Paths a PIN-locked session may still call. */
    private const ALLOWED_WHILE_LOCKED = [
        'api/v1/admin/auth/pin/unlock',
        'api/v1/admin/auth/logout',
        'api/v1/auth/logout',
    ];

    public function __construct(private TerminalPin $pins) {}

    /**
     * The limits that apply to this person, or null when none do.
     *
     * @return array{absolute:int, sign_out_idle:?int, pin_idle:?int}|null
     */
    public function limitsFor(User $user): ?array
    {
        if (!$user->canAccessAdmin()) {
            return null;
        }

        $configured = (array) config('security.sessions.roles', []);
        $limits = $user->getRoleNames()
            ->filter(fn ($r) => isset($configured[$r]))
            ->map(fn ($r) => $configured[$r])
            ->values();

        if ($limits->isEmpty()) {
            $limits = collect([(array) config('security.sessions.staff_default', ['idle' => 30, 'absolute' => 600])]);
        }

        $pin     = $limits->filter(fn ($l) => ($l['on_idle'] ?? 'sign_out') === 'pin_lock');
        $signOut = $limits->reject(fn ($l) => ($l['on_idle'] ?? 'sign_out') === 'pin_lock');

        return [
            'absolute'      => (int) $limits->min('absolute'),
            'sign_out_idle' => $signOut->isEmpty() ? null : (int) $signOut->min('idle'),
            'pin_idle'      => $pin->isEmpty() ? null : (int) $pin->min('idle'),
        ];
    }

    /**
     * What the console shows and plans around (the idle that bites first).
     *
     * @return array{idle_minutes:int, absolute_minutes:int, on_idle:string}|null
     */
    public function describe(User $user): ?array
    {
        $l = $this->limitsFor($user);
        if ($l === null) {
            return null;
        }
        $pinFirst = $l['pin_idle'] !== null && ($l['sign_out_idle'] === null || $l['pin_idle'] < $l['sign_out_idle']);

        return [
            'idle_minutes'     => $pinFirst ? $l['pin_idle'] : (int) $l['sign_out_idle'],
            'absolute_minutes' => $l['absolute'],
            'on_idle'          => $pinFirst ? 'pin_lock' : 'sign_out',
        ];
    }

    /**
     * The token check. False → Sanctum treats the request as unauthenticated
     * (401). A PIN-locked session throws a 423 response instead.
     */
    public function admit(PersonalAccessToken $token): bool
    {
        $user = $token->tokenable;
        if (!$user instanceof User || !config('security.sessions.enforce', true)) {
            return true;
        }

        $limits = $this->limitsFor($user);
        if ($limits === null) {
            return true;
        }

        $now = now();

        if ($token->created_at && $token->created_at->copy()->addMinutes($limits['absolute'])->lt($now)) {
            $this->end($token, $user, 'absolute_limit');
            return false;
        }

        $lastActive = $token->last_active_at ?? $token->last_used_at ?? $token->created_at;
        $idle       = $lastActive ? max(0, $lastActive->diffInSeconds($now, false)) / 60 : 0;

        $signOutIdle = $limits['sign_out_idle'];
        $pinIdle     = $limits['pin_idle'];
        // No PIN, nothing to unlock with: the clerk's idle limit signs out.
        if ($pinIdle !== null && !$this->pins->isSet($user)) {
            $signOutIdle = $signOutIdle === null ? $pinIdle : min($signOutIdle, $pinIdle);
            $pinIdle     = null;
        }

        if ($signOutIdle !== null && $idle > $signOutIdle) {
            $this->end($token, $user, 'idle_limit');
            return false;
        }

        $request = request();

        if ($pinIdle !== null && $idle > $pinIdle && $token->locked_at === null) {
            $token->forceFill(['locked_at' => $now])->save();
            ActivityLogService::log('session_pin_locked', $user, ['token_id' => $token->id],
                'Session locked after ' . $pinIdle . ' idle minutes', $user);
        }

        if ($token->locked_at !== null) {
            if (!$request->is(...self::ALLOWED_WHILE_LOCKED)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Your session is locked. Enter your PIN to continue.',
                    'reason'  => 'pin_locked',
                ], 423));
            }
            return true;   // unlocking or signing out is not activity
        }

        if (!$this->isBackground($request)) {
            // Saved by Sanctum together with last_used_at, right after this
            // check returns (Guard::updateLastUsedAt) — one write per request.
            $token->forceFill(['last_active_at' => $now]);
        }

        return true;
    }

    private function isBackground(Request $request): bool
    {
        $header = (string) config('security.sessions.background_header', 'X-Background-Request');

        return in_array(strtolower((string) $request->header($header)), ['1', 'true'], true);
    }

    private function end(PersonalAccessToken $token, User $user, string $reason): void
    {
        ActivityLogService::log('session_expired', $user, ['token_id' => $token->id, 'reason' => $reason],
            'Session ended (' . str_replace('_', ' ', $reason) . ')', $user);
        $token->delete();
    }
}
