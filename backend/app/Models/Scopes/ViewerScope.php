<?php

namespace App\Models\Scopes;

use App\Enums\DataScope;
use App\Services\DataScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Bounds every query on a Restricted model to what the caller may see.
 *
 * A global scope rather than a helper each controller remembers to call. The
 * existing outlet-scoping trait is opt-in and four controllers use it, which is
 * why OrderController::index, exportCsv, show and the search box were all
 * unbounded: the convention held everywhere someone thought of it and nowhere
 * else. Applied here, index, show, the CSV export, search, PDF rendering and
 * every endpoint not yet written inherit the same boundary — and the IDOR on
 * GET /admin/orders/{id} closes without anyone touching that method.
 */
class ViewerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        $scope = DataScopeResolver::for($user, $model::viewPermission());

        if ($scope === DataScope::All) {
            return;
        }

        // Below here a user is guaranteed: the resolver returns All when there
        // is no session, so background work is never narrowed.
        match ($scope) {
            // A staff member none of whose roles grants the capability.
            DataScope::None => $builder->whereRaw('1 = 0'),
            DataScope::Own => $builder->where(
                $builder->getModel()->getTable() . '.' . $model->ownerColumn(),
                $user->id,
            ),
            // An EMPTY assignment means nothing, never everything — the
            // same rule the POS outlet guard had inverted. The model decides
            // HOW a row belongs to an outlet (a task belongs through its
            // production order).
            DataScope::Outlet => $model->constrainToOutlets($builder, DataScopeResolver::outletIds($user)),
            default => null,
        };
    }
}
