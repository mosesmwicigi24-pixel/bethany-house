<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may read a colleague's email address.
 *
 * Names are how staff find each other — assignee lists, the @mention picker,
 * task history. An email address is a contact detail: it belongs to people who
 * may already list staff accounts (UserPolicy::viewAny, `users.view`), not to
 * everyone with a login. A tailor (data scope "own") used to receive every
 * colleague's email through the production assignee lists and the mention
 * search (Tailor View Cycle 2, owner decision 2026-10-05).
 */
final class StaffContacts
{
    public static function mayReadEmail(?User $viewer): bool
    {
        return $viewer !== null && $viewer->can('viewAny', User::class);
    }

    /**
     * Hide `email` on each loaded staff record unless the viewer may read it.
     *
     * @param  iterable<User|null>  $users
     */
    public static function hideEmailsFrom(?User $viewer, iterable $users): void
    {
        if (self::mayReadEmail($viewer)) return;

        foreach ($users as $user) {
            $user?->makeHidden('email');
        }
    }
}
