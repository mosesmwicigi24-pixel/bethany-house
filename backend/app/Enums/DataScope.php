<?php

namespace App\Enums;

/**
 * Whose rows a role may see.
 *
 * The axis Bethany Hub has never had. Permissions answer "may you open this
 * screen"; this answers "which records does it show you", and it is the one
 * that decides whether a cashier sees their own sales or everyone's.
 *
 * It lives on the ROLE rather than in the permission string. Encoding it into
 * names — orders.view.own, orders.view.all — triples the catalogue and lets a
 * role hold two contradictory scopes at once, which, given an editable Roles
 * UI, would eventually happen by accident.
 */
enum DataScope: string
{
    /** Only rows this user created. */
    case Own = 'own';

    /** Rows belonging to any outlet this user is assigned to. */
    case Outlet = 'outlet';

    /** Everything. */
    case All = 'all';

    /**
     * How wide this scope is. Higher sees more.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Own    => 0,
            self::Outlet => 1,
            self::All    => 2,
        };
    }

    /**
     * The widest of several scopes.
     *
     * This is the multi-role rule. Someone holding two roles gets the wider
     * view of the two — but only across the roles that actually grant the
     * capability in question, which is what stops a broad scope in one domain
     * (procurement, say) widening an unrelated one (their own POS sales).
     * DataScopeResolver applies that filter before calling this.
     *
     * @param  iterable<self>  $scopes
     */
    public static function widest(iterable $scopes): self
    {
        $widest = null;

        foreach ($scopes as $scope) {
            if ($widest === null || $scope->rank() > $widest->rank()) {
                $widest = $scope;
            }
        }

        // No scopes at all is not "everything" — the caller decides what an
        // empty set means, because the answer differs between "this user holds
        // no roles" and "no role grants this capability".
        return $widest ?? self::Own;
    }

    public static function tryFromName(?string $value): self
    {
        return $value === null ? self::All : (self::tryFrom($value) ?? self::All);
    }
}
