<?php

namespace App\Support;

use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Maker ≠ checker, enforced on user id (owner's role-hardening plan, Phase 1B):
 * "The role that originates a financial or stock event is never the role that
 * approves it. The same person can never do both, even if they hold both
 * authorities."
 *
 * Why this is code and not a permission. The owner stacks roles on people
 * himself, so one person holding both procurement.create and
 * procurement.approve is normal — a permission cannot tell "approve" from
 * "approve what I raised". And super_admin passes every permission check
 * through AuthServiceProvider's Gate::before, so nothing gate-shaped can stop
 * the owner approving his own purchase order (8 of 48 in production were).
 * This runs inside the controller, after the gates, for every user alike.
 *
 * Call it before any write or transaction: the refusal is an HTTP exception,
 * and the audit entry must not be inside work that is about to roll back.
 */
final class MakerChecker
{
    /** One plain sentence per approval, said to the person who was refused. */
    private const MESSAGES = [
        'purchase_order.approve' => 'You raised or submitted this purchase order, so someone else must approve it.',
        'purchase_order.receive' => 'You approved this purchase order, so someone else must receive the goods against it.',
        'purchase_return.approve' => 'You raised this purchase return, so someone else must approve it.',
        'stock_adjustment.approve' => 'You raised this stock adjustment, so someone else must approve it.',
        'stock_transfer.approve' => 'You raised this stock transfer, so someone else must approve it.',
        'expense.approve' => 'You recorded or submitted this expense, so someone else must approve it.',
        'payment.approve' => 'You recorded this payment, so someone else must approve it.',
        'till.verify' => 'You counted this till, so another manager must verify it.',
        'till.reconcile' => 'You counted or verified this till, so someone independent must reconcile it.',
        'till.correct' => 'You counted this till, so someone else must correct it.',
    ];

    private const FALLBACK = 'You raised this, so someone else must approve it.';

    /**
     * Refuse with 403 SELF_APPROVAL when the actor is any of the makers.
     *
     * @param  string      $action     e.g. 'purchase_order.approve' (also the audit key)
     * @param  mixed       $subject    the record being approved, for the audit trail
     * @param  int|string|null ...$makerIds  every user id that originated it; nulls are ignored
     */
    public static function assertNotMaker(User $actor, string $action, $subject, int|string|null ...$makerIds): void
    {
        $makers = array_values(array_unique(array_map(
            'intval',
            array_filter($makerIds, fn ($id) => $id !== null && $id !== ''),
        )));

        if (! in_array((int) $actor->id, $makers, true)) {
            return;
        }

        $message = self::MESSAGES[$action] ?? self::FALLBACK;

        ActivityLogService::log('self_approval_blocked', $subject, [
            'action'    => $action,
            'actor_id'  => $actor->id,
            'maker_ids' => $makers,
        ], "Self-approval blocked: {$action}", $actor);

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code'    => 'SELF_APPROVAL',
        ], 403));
    }
}
