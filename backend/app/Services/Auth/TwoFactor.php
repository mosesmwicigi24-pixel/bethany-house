<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-step sign-in rules shared by sign-in, step-up and administration.
 *
 *  - requiredFor(): the staged rollout (config security.two_factor.required_roles,
 *    all off by default). On for any of the person's roles → they must have 2FA.
 *  - recovery codes: 8 per account, shown once, stored hashed, each usable once
 *    at the second step instead of an authenticator code.
 *  - reset(): an administrator switches a person's 2FA off (they set it up
 *    again at next sign-in if their role requires it) and every session the
 *    person holds ends.
 */
class TwoFactor
{
    public function requiredFor(User $user): bool
    {
        if (!$user->canAccessAdmin()) {
            return false;
        }
        $switches = (array) config('security.two_factor.required_roles', []);

        return $user->getRoleNames()->contains(fn ($r) => !empty($switches[$r]));
    }

    /** The TOTP secret in either stored form (API: encrypted; Livewire: plain base32), or null. */
    public static function readSecret(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        try {
            return decrypt($stored);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return preg_match('/^[A-Z2-7]{16,}=*$/', $stored) ? $stored : null;
        }
    }

    public function verifyCode(User $user, string $code): bool
    {
        $secret = self::readSecret($user->two_factor_secret);

        return $secret !== null && preg_match('/^\d{6}$/', $code) && (new Google2FA())->verifyKey($secret, $code);
    }

    /**
     * New recovery codes for the account, replacing any old ones.
     *
     * @return string[] the plain codes — shown to the person once, never stored
     */
    public function generateRecoveryCodes(User $user): array
    {
        $plain = [];
        for ($i = 0; $i < (int) config('security.two_factor.recovery_codes', 8); $i++) {
            $plain[] = strtolower(Str::random(5) . '-' . Str::random(5));
        }

        $user->forceFill([
            'two_factor_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $plain)),
        ])->save();

        return $plain;
    }

    /** Spends one recovery code. True once per code. */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $code   = strtolower(trim($code));
        $hashes = $this->hashes($user);

        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->save();

                return true;
            }
        }

        return false;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($this->hashes($user));
    }

    /** Switches the person's 2FA off and ends every session they hold. */
    public function reset(User $target, User $actor, ?string $reason = null): void
    {
        $target->forceFill([
            'two_factor_enabled'          => false,
            'two_factor_secret'           => null,
            'two_factor_secret_temp'      => null,
            'two_factor_setup_started_at' => null,
            'two_factor_enabled_at'       => null,
            'two_factor_recovery_codes'   => null,
        ])->save();

        $revoked = $target->tokens()->delete();

        ActivityLogService::log('two_factor_reset', $target, [
            'reason'           => $reason,
            'sessions_revoked' => $revoked,
        ], "Two-step sign-in reset for {$target->email} by {$actor->email}", $actor);
    }

    /** @return string[] */
    private function hashes(User $user): array
    {
        $raw = $user->getAttributes()['two_factor_recovery_codes'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
