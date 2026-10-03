<?php

namespace App\Services\Pos;

use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Auth\TerminalPin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The approver's PIN, entered on the clerk's terminal (Phase 4B part 2).
 *
 * Checks the PIN through App\Services\Auth\TerminalPin — the same hash, the
 * same per-account wrong-attempt limit as the idle lock (5 in 15 minutes by
 * default, config security.terminal_pin) — and adds what a shared terminal
 * needs on top:
 *
 *   - a per-TERMINAL limit (the signed-in session's user): someone at one
 *     till cannot spray guesses across every approver's account, each staying
 *     under its own limit;
 *   - a refusal that says which case it is (no PIN set / wrong / locked out),
 *     with the attempts left, and an audit entry for every wrong PIN.
 */
class TillApproverPin
{
    /** Wrong PINs accepted from one terminal user, across approvers, per window. */
    public const TERMINAL_MAX_ATTEMPTS = 10;

    public const TERMINAL_DECAY_MINUTES = 15;

    public function __construct(private TerminalPin $pins) {}

    /**
     * Pass silently when $pin is $approver's own PIN; otherwise refuse with an
     * HTTP exception (422 wrong / not set, 429 locked out or throttled).
     */
    public function check(User $terminalUser, User $approver, string $pin, ?Model $subject = null): void
    {
        $terminalKey = $this->terminalKey($terminalUser);

        if (RateLimiter::tooManyAttempts($terminalKey, self::TERMINAL_MAX_ATTEMPTS)) {
            $this->fail(429, 'TILL_PIN_THROTTLED', 'Too many wrong PINs from this till. Wait ' . $this->minutes(RateLimiter::availableIn($terminalKey)) . ' and try again.');
        }
        if (!$this->pins->isSet($approver)) {
            $this->fail(422, 'APPROVER_PIN_NOT_SET', 'This approver has not set a terminal PIN. They can approve from their Approvals inbox, or set a PIN in their profile.');
        }
        if ($this->pins->lockedOut($approver)) {
            $this->fail(429, 'APPROVER_PIN_LOCKED', 'This approver\'s PIN is locked after too many wrong attempts. They can approve from their Approvals inbox, or try again later.');
        }

        if ($this->pins->verify($approver, $pin)) {
            return;
        }

        RateLimiter::hit($terminalKey, 60 * self::TERMINAL_DECAY_MINUTES);
        $left = $this->pins->attemptsLeft($approver);

        ActivityLogService::log('till_approval_pin_failed', $subject, [
            'approver_id'      => $approver->id,
            'terminal_user_id' => $terminalUser->id,
            'attempts_left'    => $left,
            'locked'           => $this->pins->lockedOut($approver),
        ], "Wrong approver PIN on the till for #{$approver->id} (entered at #{$terminalUser->id}'s terminal)", $terminalUser);

        if ($this->pins->lockedOut($approver)) {
            $this->fail(429, 'APPROVER_PIN_LOCKED', 'Wrong PIN. This approver\'s PIN is now locked after too many wrong attempts.');
        }

        throw new HttpResponseException(response()->json([
            'message'       => 'Wrong PIN.',
            'code'          => 'PIN_INCORRECT',
            'attempts_left' => $left,
        ], 422));
    }

    private function terminalKey(User $terminalUser): string
    {
        return 'till-approver-pin:terminal:' . $terminalUser->id;
    }

    private function minutes(int $seconds): string
    {
        $m = max(1, (int) ceil($seconds / 60));

        return $m . ' minute' . ($m === 1 ? '' : 's');
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
