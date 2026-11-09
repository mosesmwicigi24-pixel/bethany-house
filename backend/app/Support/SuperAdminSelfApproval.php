<?php

namespace App\Support;

use App\Models\User;
use App\Services\ActivityLogService;

/**
 * A super admin's own entries need no second person (owner, 2026-11-05:
 * "When you are super admin you don't need someone to approve your
 * entries. Procurement, price change in products etc." — every super
 * admin, every kind of approval).
 *
 * It replaces maker ≠ checker for super admins only:
 *   - App\Support\MakerChecker lets a super admin approve what they raised
 *     (purchase orders, payments, stock, tills, …) instead of refusing;
 *   - App\Services\Approvals\ApprovalEngine::submit approves a super admin's
 *     own submission the moment it is made — every band, signed by them.
 *
 * Nothing becomes silent: every such approval is written to the audit trail
 * as `self_approved_super_admin`, and an engine signature carries
 * owner_override = true, so whoever reads the record or an export sees that
 * the maker approved it. Everyone else is still refused as before.
 */
final class SuperAdminSelfApproval
{
    public const EVENT = 'self_approved_super_admin';

    public static function applies(?User $user): bool
    {
        return $user !== null
            && ($user->status ?? 'active') === 'active'
            && $user->hasRole('super_admin', 'sanctum');
    }

    public static function log(User $actor, string $action, $subject, array $makerIds = []): void
    {
        ActivityLogService::log(self::EVENT, $subject, [
            'action'    => $action,
            'actor_id'  => $actor->id,
            'maker_ids' => array_values($makerIds),
        ], "Approved by its own maker, a super admin: {$action}", $actor);
    }
}
