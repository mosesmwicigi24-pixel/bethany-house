<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Who may act on another person's sign-in (role plan §2, §12.4, C-05):
 * resetting their two-step sign-in, unlocking their account, ending their
 * sessions.
 *
 *   super_admin   anyone but themselves (a super_admin target therefore needs
 *                 a SECOND super_admin)
 *   system_admin  Tier 2–3 only (managers and operators)
 *   anyone else   no one
 *
 * Roles, not permissions: super_admin passes every permission (Gate::before),
 * so a permission could not express "system_admin, but only for Tier 2–3".
 */
class StaffAuthority
{
    /** The person's highest authority: the lowest tier number among their roles. */
    public function tierOf(User $user): int
    {
        $tiers = (array) config('security.tiers', []);
        $roles = $user->getRoleNames();
        if ($roles->isEmpty()) {
            return 3;
        }

        // A role not in the map (custom) is treated as Tier 1: only the owner acts on it.
        return (int) $roles->map(fn ($r) => $tiers[$r] ?? 1)->min();
    }

    public function mayAdminister(User $actor, User $target): bool
    {
        if ((int) $actor->id === (int) $target->id) {
            return false;
        }
        if ($actor->hasRole('super_admin')) {
            return true;
        }
        if ($actor->hasRole('system_admin')) {
            return $this->tierOf($target) >= 2;
        }

        return false;
    }

    public function refusal(User $actor, User $target): string
    {
        return match (true) {
            (int) $actor->id === (int) $target->id => 'Use your own profile for your own account.',
            $actor->hasRole('system_admin')        => 'A system administrator acts on managers and operators only; a super administrator handles this account.',
            default                                => 'Only a system administrator or a super administrator can do this.',
        };
    }
}
