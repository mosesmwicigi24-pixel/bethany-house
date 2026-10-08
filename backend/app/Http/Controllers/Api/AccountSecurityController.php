<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Auth\StepUp;
use App\Services\Auth\TerminalPin;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in person's own sign-in safety (Phase 4C): step-up, unlocking a
 * PIN-locked session, setting their terminal PIN, new recovery codes.
 */
class AccountSecurityController extends Controller
{
    /**
     * POST /api/v1/admin/auth/step-up — re-confirm identity for a privileged
     * action. TOTP code when two-step sign-in is on, otherwise the password.
     * Covers this session for the step-up window. Own limiter: 5 tries per
     * 5 minutes per account.
     */
    public function stepUp(Request $request, StepUp $stepUp, TwoFactor $twoFactor)
    {
        $user = $request->user();
        $validated = $request->validate([
            'password' => 'nullable|string|max:255',
            'code'     => 'nullable|string|max:10',
        ]);

        $key = 'step-up-attempts:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message' => 'Too many attempts. Wait a few minutes and try again.',
                'retry_after_seconds' => RateLimiter::availableIn($key),
            ], 429);
        }

        $method = $user->two_factor_enabled ? 'totp' : 'password';
        $ok = $method === 'totp'
            ? $twoFactor->verifyCode($user, (string) ($validated['code'] ?? ''))
            : Hash::check((string) ($validated['password'] ?? ''), $user->password);

        if (!$ok) {
            RateLimiter::hit($key, 300);
            ActivityLogService::log('step_up_failed', $user, ['method' => $method], "Step-up refused ({$method})", $user);
            $field = $method === 'totp' ? 'code' : 'password';

            return response()->json([
                'message' => $method === 'totp' ? 'That code is not right.' : 'That password is not right.',
                'errors'  => [$field => [$method === 'totp' ? 'That code is not right.' : 'That password is not right.']],
                'method'  => $method,
            ], 422);
        }

        RateLimiter::clear($key);
        $until = $stepUp->confirmFor($user, $user->currentAccessToken());
        ActivityLogService::log('step_up_confirmed', $user, ['method' => $method], "Step-up confirmed ({$method})", $user);

        return response()->json(['confirmed_until' => $until->toIso8601String()]);
    }

    /**
     * POST /api/v1/admin/auth/pin/unlock — the clerk's PIN releases a
     * PIN-locked session (the one Sanctum lets through while locked). Wrong
     * PINs are limited per account (TerminalPin); at the limit the session
     * ends and the clerk signs in again with their password.
     */
    public function unlock(Request $request, TerminalPin $pins)
    {
        $validated = $request->validate(['pin' => 'required|string|max:12']);
        $user  = $request->user();
        $token = $user->currentAccessToken();

        if (!$token instanceof PersonalAccessToken || !$token->exists || $token->locked_at === null) {
            return response()->json(['message' => 'This session is not locked.', 'unlocked' => true]);
        }

        if ($pins->verify($user, $validated['pin'])) {
            $token->forceFill(['locked_at' => null, 'last_active_at' => now()])->save();
            ActivityLogService::log('session_unlocked', $user, ['token_id' => $token->id], 'Session unlocked with PIN', $user);

            return response()->json(['message' => 'Unlocked.', 'unlocked' => true]);
        }

        if ($pins->lockedOut($user)) {
            ActivityLogService::log('session_ended_wrong_pin', $user, ['token_id' => $token->id],
                'Session ended after too many wrong PINs', $user);
            $token->delete();

            return response()->json(['message' => 'Too many wrong PINs. Sign in again with your password.', 'reason' => 'signed_out'], 401);
        }

        ActivityLogService::log('session_unlock_failed', $user, ['token_id' => $token->id], 'Wrong PIN at session unlock', $user);

        return response()->json([
            'message'       => 'That PIN is not right.',
            'errors'        => ['pin' => ['That PIN is not right.']],
            'attempts_left' => $pins->attemptsLeft($user),
        ], 422);
    }

    /**
     * PUT /api/v1/admin/profile/terminal-pin — the person sets or changes
     * their own terminal PIN, proving it is them with their password.
     */
    public function setTerminalPin(Request $request, TerminalPin $pins)
    {
        $validated = $request->validate([
            'pin'              => 'required|string|confirmed',
            'current_password' => 'required|string',
        ]);
        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Your password is not right.',
                'errors'  => ['current_password' => ['Your password is not right.']],
            ], 422);
        }
        if (($problem = TerminalPin::problem($validated['pin'])) !== null) {
            return response()->json(['message' => $problem, 'errors' => ['pin' => [$problem]]], 422);
        }

        $had = $pins->isSet($user);
        $pins->set($user, $validated['pin']);
        ActivityLogService::log($had ? 'terminal_pin_changed' : 'terminal_pin_set', $user, [],
            $had ? 'Terminal PIN changed' : 'Terminal PIN set', $user);

        return response()->json(['message' => 'Your PIN is saved.', 'terminal_pin_set' => true]);
    }

    /**
     * POST /api/v1/admin/auth/2fa/recovery-codes — replace the recovery codes
     * (old ones stop working). Step-up route.
     */
    public function regenerateRecoveryCodes(Request $request, TwoFactor $twoFactor)
    {
        /** @var User $user */
        $user = $request->user();
        if (!$user->two_factor_enabled) {
            return response()->json(['message' => 'Two-step sign-in is not on for your account.'], 422);
        }

        $codes = $twoFactor->generateRecoveryCodes($user);
        ActivityLogService::log('two_factor_recovery_codes_regenerated', $user, [], 'Recovery codes replaced', $user);

        return response()->json(['recovery_codes' => $codes]);
    }
}
