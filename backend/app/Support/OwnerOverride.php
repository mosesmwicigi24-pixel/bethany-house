<?php

namespace App\Support;

use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Auth\StepUp;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The owner approving what the owner recorded (owner's decision, 2026-10-21).
 *
 * Maker ≠ checker (App\Support\MakerChecker) refuses everyone alike, the
 * super admin included. The owner records many expenses himself and is at
 * times the only signer, so for the actions listed here — and only these —
 * the OWNER ACCOUNT (audit.owner_account_email, as a super admin) may sign as
 * the maker when all of this holds:
 *   - the request asks for it (owner_override: true) — never by default;
 *   - the session confirmed who it is within the step-up window, on this
 *     device (App\Services\Auth\StepUp: authenticator code, or a passkey);
 *   - the signature, the record's approval row and the audit trail all say
 *     "approved by the maker under owner override", so an accountant sees it
 *     wherever the record is shown or exported.
 * Any other super admin, any other action, or a stale session is refused as
 * before (SELF_APPROVAL).
 */
final class OwnerOverride
{
    public const EVENT = 'self_approval_overridden';

    /** The approvals the owner may sign as their maker. */
    public const ACTIONS = ['expense.approve'];

    public static function isOwner(?User $user): bool
    {
        $email = strtolower((string) config('audit.owner_account_email'));

        return $user !== null && $email !== '' && strtolower((string) $user->email) === $email
            && $user->hasRole('super_admin', 'sanctum');
    }

    /** May this action be overridden by this person at all (before step-up)? */
    public static function available(?User $user, string $action): bool
    {
        return in_array($action, self::ACTIONS, true) && self::isOwner($user);
    }

    /**
     * Let the owner through as the maker, or refuse. Throws the step-up
     * challenge when the session has not confirmed recently (the console
     * prompts and resends), and MakerChecker's SELF_APPROVAL refusal when the
     * person or the action is not eligible. Logs the override before any
     * write, like the refusal it replaces.
     *
     * @param int[] $makerIds
     */
    public static function assertAllowed(User $actor, string $action, $subject, array $makerIds): void
    {
        if (!self::available($actor, $action)) {
            MakerChecker::assertNotMaker($actor, $action, $subject, ...$makerIds);

            return;     // not a maker after all: nothing to override
        }

        $stepUp = app(StepUp::class);
        if (!$stepUp->isFresh($actor, $actor->currentAccessToken())) {
            throw new HttpResponseException($stepUp->challenge($actor));
        }

        ActivityLogService::log(self::EVENT, $subject, [
            'action'    => $action,
            'actor_id'  => $actor->id,
            'maker_ids' => array_values($makerIds),
        ], "Owner override: {$action} signed by its maker", $actor);
    }
}
