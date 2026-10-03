<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * How wide a view this caller gets of a given resource.
 *
 * The rule, and the clause that matters:
 *
 *   the WIDEST data_scope among only those roles that actually grant the
 *   capability in question.
 *
 * Without that last clause, giving a cashier a second, unrelated role would
 * silently widen the sales they can already see. With it, someone holding
 * pos_clerk (own) and procurement_officer (all) sees every purchase order and
 * still only their own POS sales — the wide scope stays in its own domain.
 *
 * Phase 4A adds the two answers the rule was missing:
 *
 *   Outlet  outlet_manager ships at 'outlet'. The outlets are the outlet_user
 *           pivot (outletIds()); an EMPTY assignment is nothing.
 *   None    a STAFF member holding roles, none of which grants the capability. This
 *           used to be All, so wherever a route forgot its permission check
 *           the role WITHOUT the permission saw the whole table.
 *
 * The one place the boundary is lifted on purpose is the reports section: the
 * owner kept reports business-wide for everyone who may read them
 * (2026-10-03). Those route groups carry the `report.business_wide`
 * middleware, which marks the request; for that request every scope is All.
 */
class DataScopeResolver
{
    /** Request attribute set by App\Http\Middleware\ReportsAreBusinessWide. */
    public const BUSINESS_WIDE = 'data_scope.business_wide';

    /**
     * @param  string  $permission  The capability that governs reading this
     *                              resource, e.g. 'orders.view'.
     */
    public static function for(?User $user, string $permission): DataScope
    {
        // No authenticated user: queue jobs, webhooks, scheduled commands and
        // the storefront's guest paths. These are not "a user with no rights",
        // they are code running outside any session, and narrowing them would
        // silently break background work. The storefront's own customer
        // endpoints do their own user_id filtering.
        if (!$user) {
            return DataScope::All;
        }

        if (self::isBusinessWide()) {
            return DataScope::All;
        }

        // super_admin bypasses every permission check via Gate::before; scope
        // follows the same rule, or the bypass would be half-honoured.
        if ($user->hasRole('super_admin')) {
            return DataScope::All;
        }

        // Membership is tested against the loaded relation rather than with
        // hasPermissionTo(), which THROWS PermissionDoesNotExist when the
        // permission is absent from the database. A resolver that runs inside
        // every query must not raise on a database where a permission has not
        // been seeded — it answers "no role grants it", which is true.
        $granting = $user->roles->filter(
            fn ($role) => $role->permissions->contains('name', $permission)
        );

        if ($granting->isEmpty()) {
            // Held directly on the user rather than through a role — a
            // deliberate grant in the Roles screen. It carries no scope, so
            // there is no narrower answer to give.
            if ($user->permissions->contains('name', $permission)) {
                return DataScope::All;
            }

            // "Someone without the right" is a STAFF member who holds roles,
            // none of which grants this. That is the hole: the role WITHOUT
            // orders.view saw every order wherever a route forgot its check.
            //
            // Not narrowed, as before: a storefront customer (no roles; its
            // endpoints filter by user_id themselves), a system account, and
            // a staff login with no role at all — whose every right is a
            // hand grant on the user and so carries no data_scope to read.
            return ($user->isStaff() && $user->roles->isNotEmpty()) ? DataScope::None : DataScope::All;
        }

        return DataScope::widest(
            $granting->map(fn ($role) => DataScope::tryFromName($role->data_scope ?? null))
        );
    }

    /**
     * The outlets this user is assigned to — the outlet_user pivot, and
     * nothing else (users carry no outlet_id column; reading one compiled to
     * `IS NULL` and scoped nothing, #20). An empty array means NO outlets.
     *
     * @return int[]
     */
    public static function outletIds(User $user): array
    {
        return $user->outlets()->pluck('outlets.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Outlet ids the caller is bounded to for this capability, or null when
     * the caller is not bounded by outlet (All). Own and None both answer []
     * here — callers that also honour Own (a cashier's own records) check
     * for() first; for a table with no owner column, an Own-scoped caller is
     * bounded to the outlets they work at (outletIdsForUnowned()).
     *
     * @return int[]|null
     */
    public static function outletIdsOrNull(?User $user, string $permission): ?array
    {
        return match (self::for($user, $permission)) {
            DataScope::All    => null,
            DataScope::Outlet => self::outletIds($user),
            default           => [],
        };
    }

    /**
     * For a table that has an outlet but no owner (stock levels, the pending
     * queue, interest carts): Own resolves to the outlets the cashier is
     * assigned to — "assigned outlet" in the plan's scope table.
     *
     * @return int[]|null null = unbounded
     */
    public static function outletIdsForUnowned(?User $user, string $permission): ?array
    {
        return match (self::for($user, $permission)) {
            DataScope::All                     => null,
            DataScope::Outlet, DataScope::Own  => self::outletIds($user),
            DataScope::None                    => [],
        };
    }

    /**
     * Bound any query (Eloquent or raw builder) on a table with an outlet but
     * no owner to the caller's outlets for this capability. Several columns
     * are OR-ed: a stock transfer is in scope when EITHER side is. Unbounded
     * callers are left alone; an empty assignment matches nothing (Laravel
     * compiles whereIn([]) to a false predicate).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  string|string[]  $columns
     */
    public static function boundToOutlets($query, ?User $user, string $permission, string|array $columns): void
    {
        $ids = self::outletIdsForUnowned($user, $permission);
        if ($ids === null) {
            return;
        }

        $query->where(function ($w) use ($columns, $ids) {
            foreach ((array) $columns as $column) {
                $w->orWhereIn($column, $ids);
            }
        });
    }

    /**
     * May this caller act at this outlet (a write naming an outlet)? null
     * outlet = head office, which a bounded caller does not reach.
     */
    public static function allowsOutlet(?User $user, string $permission, ?int $outletId): bool
    {
        $ids = self::outletIdsForUnowned($user, $permission);

        return $ids === null || ($outletId !== null && in_array($outletId, $ids, true));
    }

    /** True while serving a route group the owner kept business-wide. */
    public static function isBusinessWide(): bool
    {
        if (!app()->bound('request')) {
            return false;
        }

        $request = app('request');

        return $request instanceof Request && (bool) $request->attributes->get(self::BUSINESS_WIDE, false);
    }
}
