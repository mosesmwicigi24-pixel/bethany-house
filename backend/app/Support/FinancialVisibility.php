<?php

namespace App\Support;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\DataScopeResolver;

/**
 * Who sees the business's money, how widely, and for how long — one rule
 * (owner, 2026-10-15).
 *
 *   "Only super admins will have the dashboard as it is … any other, whether
 *    you're a line manager or an outlet manager, you only see what is within
 *    your range for one day, so that we don't disclose a lot of financial
 *    records to other staff members. The extensive report should be for
 *    super admins. People who do not have any financial work should not be
 *    able to see our financial reports."
 *
 * Decided with the owner the same day:
 *   - Finance roles (holders of reports.financial — accountant, finance
 *     manager) keep the Finance & Cash reports and the payments ledger for
 *     any period: month-end, P&L and tax are their work.
 *   - A line manager's range is herself plus her direct reports
 *     (users.reports_to; User::teamIds()).
 *
 * This REVERSES the 2026-09-30 / 2026-10-03 decision that every report is
 * business-wide for anyone holding its page permission. The multi-day,
 * business-wide report pages that carry money (MONEY_PAGES) are now the
 * owner's, plus Finance & Cash for finance roles. Everyone else sees TODAY's
 * figures for their own range on the dashboard — never a report page that
 * reads raw SQL across the whole business and cannot be bounded by person.
 * Operational pages (production, inventory, procurement, data quality) are
 * unchanged: they are their holders' working tools.
 *
 * Read by: ReportPages::canView (so every report route, PDF, CSV, drill and
 * schedule), AuthController (the console's permission list), the dashboard,
 * and LimitsMoneyWindowToToday (the payments ledger).
 */
final class FinancialVisibility
{
    /**
     * Report pages whose figures are the business's money: takings, revenue,
     * margins, collections, revenue per customer and per salesperson.
     */
    public const MONEY_PAGES = ['executive', 'sales', 'customers', 'financial', 'performance', 'explorer'];

    public static function isOwner(?User $user): bool
    {
        return (bool) $user?->hasRole('super_admin');
    }

    /** A finance role: reads Finance & Cash and the payments ledger, any period. */
    public static function isFinance(?User $user): bool
    {
        return (bool) $user?->can('reports.financial');
    }

    /**
     * May this person open a money report page at all (on top of holding the
     * page's permission)? The owner: every page. A finance role: Finance &
     * Cash only. Anyone else: none — their money is today's, on the dashboard.
     */
    public static function mayOpenMoneyPage(?User $user, string $page): bool
    {
        if (self::isOwner($user)) {
            return true;
        }

        return $page === 'financial' && self::isFinance($user);
    }

    /**
     * May this person read money over a period longer than today on a finance
     * surface (the payments ledger, the cash report)? The owner and finance.
     */
    public static function mayReadMoneyHistory(?User $user): bool
    {
        return self::isOwner($user) || self::isFinance($user);
    }

    /**
     * Whose figures this person's dashboard covers: the whole business, their
     * outlet(s), their team, only their own, or none — read from the order
     * scope (orders.view), the same boundary ViewerScope applies.
     *
     * @return 'business'|'outlet'|'team'|'own'|'none'
     */
    public static function range(User $user): string
    {
        return match (DataScopeResolver::for($user, 'orders.view')) {
            DataScope::All    => 'business',
            DataScope::Outlet => 'outlet',
            DataScope::Own    => count($user->teamIds()) > 1 ? 'team' : 'own',
            DataScope::None   => 'none',
        };
    }

    /**
     * What the console needs to label the dashboard honestly: whose figures
     * and over what period. 'any' only for the owner.
     *
     * @return array{range: string, period: 'any'|'today', label: string}
     */
    public static function describe(User $user): array
    {
        if (self::isOwner($user)) {
            return ['range' => 'business', 'period' => 'any', 'label' => 'across your business'];
        }

        $range = self::range($user);

        return [
            'range'  => $range,
            'period' => 'today',
            'label'  => match ($range) {
                'business' => 'today, across the business',
                'outlet'   => 'today, at your outlet',
                'team'     => 'today, for you and your team',
                'own'      => 'today, your own work',
                default    => 'today',
            },
        ];
    }

    /**
     * The permission names the console should treat as held: money report
     * pages this person cannot open are left out, so the sidebar, the routes
     * and the dashboard follow the server instead of a second copy of this
     * rule. The roles themselves are untouched.
     *
     * @param  iterable<string>  $names
     * @return list<string>
     */
    public static function effectivePermissions(User $user, iterable $names): array
    {
        $blocked = [];
        foreach (self::MONEY_PAGES as $page) {
            if (! self::mayOpenMoneyPage($user, $page)) {
                $blocked[] = ReportPages::PAGES[$page];
            }
        }

        return array_values(array_filter(
            is_array($names) ? $names : iterator_to_array($names),
            fn ($name) => ! in_array($name, $blocked, true),
        ));
    }
}
