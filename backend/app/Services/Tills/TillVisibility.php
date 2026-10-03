<?php

namespace App\Services\Tills;

use App\Models\CashRegister;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees which tills (Phase 4B, plan §17):
 *
 *   - pos.tills_view_all (admin, accountant, finance_manager; super_admin via
 *     Gate::before) — every till at every outlet;
 *   - pos.till_verify (outlet_manager) — every till at the outlets they are
 *     assigned to, plus their own;
 *   - everyone else (the clerk) — her own tills from the last 7 days, plus any
 *     of hers still open or awaiting verification however old: a register
 *     opened weeks ago and never closed must stay in front of the person who
 *     has to close it.
 *
 * An empty outlet assignment means nothing, never everything.
 */
final class TillVisibility
{
    public const OWN_DAYS = 7;

    public static function scope(Builder $query, User $user): Builder
    {
        if ($user->can('pos.tills_view_all')) {
            return $query;
        }

        if ($user->can('pos.till_verify')) {
            $outletIds = $user->outlets()->pluck('outlets.id')->all();

            return $query->where(function ($q) use ($outletIds, $user) {
                $q->whereIn('outlet_id', $outletIds ?: [0])->orWhere('opened_by', $user->id);
            });
        }

        $since = now()->subDays(self::OWN_DAYS);

        return $query->where('opened_by', $user->id)->where(function ($q) use ($since) {
            $q->where('opened_at', '>=', $since)
                ->orWhere('closed_at', '>=', $since)
                ->orWhereIn('status', ['open', 'counted']);
        });
    }

    public static function canSee(User $user, CashRegister $register): bool
    {
        return self::scope(CashRegister::query()->whereKey($register->id), $user)->exists();
    }

    /** An outlet manager verifies tills at their own outlets only (super_admin anywhere). */
    public static function mayVerifyAt(User $user, int $outletId): bool
    {
        if (!$user->can('pos.till_verify')) {
            return false;
        }
        if ($user->hasRole('super_admin', 'sanctum')) {
            return true;
        }

        return $user->outlets()->where('outlets.id', $outletId)->exists();
    }
}
