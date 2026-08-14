<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Models\User;

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
 * Every role ships at DataScope::All, so today this resolver changes nothing.
 * That is deliberate: the machinery lands first and is proven inert, and
 * flipping a role to 'own' afterwards is a one-line change whose effect shows
 * up in the scope-matrix test rather than in production.
 */
class DataScopeResolver
{
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

        // The user holds the permission directly rather than through a role —
        // possible via the Roles UI and common in tests. A direct grant carries
        // no scope, so there is no narrower answer to give.
        if ($granting->isEmpty()) {
            return DataScope::All;
        }

        return DataScope::widest(
            $granting->map(fn ($role) => DataScope::tryFromName($role->data_scope ?? null))
        );
    }
}
