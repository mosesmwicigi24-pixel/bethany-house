<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may be in a record's conversation.
 *
 * A context channel ("PRD · PO-…") hangs off a record, so it is as sensitive
 * as the record: customer first names, measurements, deadlines and whatever
 * the team writes about the job. Membership alone used to be the test, and
 * POST /channels/context handed membership to anyone who asked for any id —
 * a tailor could read the thread of a job she had never been near.
 *
 * The rule is RecordVisibility's: you are in the thread while you could open
 * the record. For a production order that is production.view + visibleTo
 * (managers see the floor; a tailor sees the jobs she has a task on), so
 * when her task moves to someone else the thread closes to her with it.
 */
final class ContextChannels
{
    /** Read/write access to an existing channel, on top of membership. */
    public static function mayUse(User $user, Channel $channel): bool
    {
        if ($channel->context_type === 'production_order' && $channel->context_id) {
            return RecordVisibility::canView($user, ProductionOrder::class, (int) $channel->context_id);
        }

        return true;
    }

    /**
     * Of these production-order ids, the ones $user may see — one query, for
     * the channel list (a manager can be in hundreds of order threads).
     *
     * @param  int[]  $orderIds
     * @return int[]
     */
    public static function visibleProductionOrderIds(User $user, array $orderIds): array
    {
        if (! $orderIds || ! $user->can('production.view')) {
            return [];
        }

        return ProductionOrder::visibleTo($user)
            ->whereIn('production_orders.id', $orderIds)
            ->pluck('production_orders.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Opening (and so joining) a record's thread. $user must be the
     * authenticated caller: Order's ViewerScope reads auth()->user().
     */
    public static function mayJoin(User $user, string $contextType, int $contextId): bool
    {
        return RecordVisibility::canViewType($user, $contextType, $contextId);
    }

    /**
     * Adding SOMEONE ELSE to a thread. Production orders are checked for the
     * person being added (visibleTo takes the user explicitly). Other context
     * types keep today's rule; their scopes read the caller, not the target.
     */
    public static function mayAddMember(User $target, Channel $channel): bool
    {
        if ($channel->context_type === 'production_order' && $channel->context_id) {
            return RecordVisibility::canView($target, ProductionOrder::class, (int) $channel->context_id);
        }

        return true;
    }

    /**
     * Remove everyone who can no longer see the production order — run after
     * a task changes hands. Returns the user ids removed.
     *
     * @return int[]
     */
    public static function pruneProductionOrder(int $orderId): array
    {
        $channel = Channel::where('context_type', 'production_order')
            ->where('context_id', $orderId)
            ->first();
        if (! $channel) {
            return [];
        }

        $removed = [];
        $memberIds = DB::table('channel_members')->where('channel_id', $channel->id)->pluck('user_id');
        foreach (User::whereIn('id', $memberIds)->get() as $member) {
            if (! RecordVisibility::canView($member, ProductionOrder::class, $orderId)) {
                $removed[] = (int) $member->id;
            }
        }

        if ($removed) {
            $channel->members()->detach($removed);
        }

        return $removed;
    }
}
