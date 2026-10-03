<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The clerk's terminal PIN (role plan §12.3).
 *
 * One place that sets, checks and clears a PIN, so every caller checks a PIN
 * the same way and shares the same wrong-attempt limit:
 *   - the idle lock on a clerk's session (SessionPolicy / pin unlock), and
 *   - from Phase 4B, the approver's PIN entered on the clerk's terminal.
 *
 * Stored hashed in `terminal_pins` (a table of its own, so the hash never
 * passes through the users-row audit observer). Nobody can read a PIN back;
 * an outlet manager can only clear it so the clerk sets a new one.
 */
class TerminalPin
{
    /** Why a PIN is refused, or null when it is acceptable. */
    public static function problem(string $pin): ?string
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            return 'The PIN must be 4 to 6 digits.';
        }
        if (preg_match('/^(\d)\1+$/', $pin)) {
            return 'The PIN cannot be one digit repeated.';
        }
        $ascending  = '01234567890123';
        $descending = '98765432109876';
        if (str_contains($ascending, $pin) || str_contains($descending, $pin)) {
            return 'The PIN cannot be a run of consecutive digits.';
        }

        return null;
    }

    public function set(User $user, string $pin): void
    {
        if (($problem = self::problem($pin)) !== null) {
            throw new \InvalidArgumentException($problem);
        }

        DB::table('terminal_pins')->updateOrInsert(
            ['user_id' => $user->id],
            ['pin_hash' => Hash::make($pin), 'set_at' => now()],
        );
        RateLimiter::clear($this->key($user));
    }

    public function isSet(User $user): bool
    {
        return DB::table('terminal_pins')->where('user_id', $user->id)->exists();
    }

    public function clear(User $user): void
    {
        DB::table('terminal_pins')->where('user_id', $user->id)->delete();
        RateLimiter::clear($this->key($user));
    }

    /**
     * True only for the account's own PIN, and only while it is not locked
     * out by wrong attempts (max_attempts within decay_minutes). A wrong PIN
     * counts against the account; the right one resets the count.
     */
    public function verify(User $user, string $pin): bool
    {
        if ($this->lockedOut($user)) {
            return false;
        }

        $hash = DB::table('terminal_pins')->where('user_id', $user->id)->value('pin_hash');
        if ($hash !== null && Hash::check($pin, $hash)) {
            RateLimiter::clear($this->key($user));
            return true;
        }

        RateLimiter::hit($this->key($user), 60 * (int) config('security.terminal_pin.decay_minutes', 15));

        return false;
    }

    public function lockedOut(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->key($user), (int) config('security.terminal_pin.max_attempts', 5));
    }

    public function attemptsLeft(User $user): int
    {
        return RateLimiter::remaining($this->key($user), (int) config('security.terminal_pin.max_attempts', 5));
    }

    private function key(User $user): string
    {
        return 'terminal-pin:' . $user->id;
    }
}
