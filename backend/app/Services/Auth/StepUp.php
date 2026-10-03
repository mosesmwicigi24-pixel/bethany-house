<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Step-up (role plan §12.3): a privileged action needs the person to have
 * re-confirmed who they are — TOTP code when two-step sign-in is on, the
 * password otherwise — within the last few minutes, on THIS session.
 *
 * The confirmation is stamped on the session's token (stepped_up_at), so a
 * confirmation made on one device never covers another. A session that is
 * not a stored token (the web guard; Sanctum::actingAs in tests) keeps it
 * against the person instead.
 */
class StepUp
{
    public function windowMinutes(): int
    {
        return max(1, (int) config('security.step_up.window_minutes', 5));
    }

    /** @param mixed $token the session's current access token (or null) */
    public function isFresh(User $user, $token): bool
    {
        $at = $this->storedToken($token)
            ? $token->stepped_up_at
            : Cache::get($this->personKey($user));

        if ($at === null) {
            return false;
        }
        $at = $at instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($at) : \Illuminate\Support\Carbon::parse($at);

        return $at->copy()->addMinutes($this->windowMinutes())->gte(now());
    }

    /** @param mixed $token */
    public function confirmFor(User $user, $token): \Illuminate\Support\Carbon
    {
        $now = now();
        if ($this->storedToken($token)) {
            $token->forceFill(['stepped_up_at' => $now])->save();
        } else {
            Cache::put($this->personKey($user), $now->toIso8601String(), now()->addMinutes($this->windowMinutes()));
        }

        return $now->copy()->addMinutes($this->windowMinutes());
    }

    /** Throws the step-up challenge unless the caller's session confirmed recently. */
    public function ensure(Request $request): void
    {
        $user = $request->user();
        if ($user instanceof User && !$this->isFresh($user, $user->currentAccessToken())) {
            throw new HttpResponseException($this->challenge($user));
        }
    }

    public function challenge(User $user): JsonResponse
    {
        return response()->json([
            'message' => 'Confirm it’s you to continue.',
            'code'    => 'step_up_required',
            'reason'  => 'step_up_required',
            'method'  => $user->two_factor_enabled ? 'totp' : 'password',
        ], 403);
    }

    private function storedToken($token): bool
    {
        return $token instanceof PersonalAccessToken && $token->exists;
    }

    private function personKey(User $user): string
    {
        return 'step-up:user:' . $user->id;
    }
}
