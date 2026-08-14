<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\DataScopeResolver;
use Illuminate\Database\Query\Builder;

/**
 * Applies the viewer's data scope to a RAW query-builder query on orders.
 *
 * The ViewerScope global scope bounds Eloquent, and only Eloquent. A query
 * written as `DB::table('payments as p')->join('orders as o', …)` never touches
 * the Order model, so it never picks the scope up — the boundary simply is not
 * there.
 *
 * That is a real limit of the global-scope approach and worth stating plainly:
 * "every query inherits the boundary" is true of Eloquent and false of the
 * query builder. There are around 95 raw builder queries against orders and
 * payments in this codebase, most of them in reporting code a cashier cannot
 * reach. The ones a scoped role CAN reach need this by hand.
 *
 * Use it wherever a raw query joins orders and a narrowed role can get to it.
 */
trait ConstrainsRawQueriesToViewer
{
    /**
     * @param  Builder  $query       the raw builder
     * @param  string   $ordersAlias the alias the orders table carries in this
     *                               query, e.g. 'o' for `join('orders as o')`
     * @param  string   $permission  the capability governing the read
     */
    protected function constrainToViewer(
        Builder $query,
        ?User $user,
        string $ordersAlias = 'orders',
        string $permission = 'orders.view',
    ): void {
        $scope = DataScopeResolver::for($user, $permission);

        if ($scope === DataScope::All) {
            return;
        }

        match ($scope) {
            DataScope::Own => $query->where("{$ordersAlias}.created_by", $user->id),
            // An EMPTY assignment means nothing, never everything.
            DataScope::Outlet => $query->whereIn(
                "{$ordersAlias}.outlet_id",
                $user->outlets()->pluck('outlets.id')->all(),
            ),
            default => null,
        };
    }
}
