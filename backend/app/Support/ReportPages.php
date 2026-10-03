<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may open which report page, and who may take a file out of it
 * (Role Hardening Plan §6; bh-tools/phase3_spec.md §3A).
 *
 * One permission per page replaced the single front door `reports.view`,
 * which opened all ten non-financial pages to anyone holding it. Finance &
 * Cash keeps the permission it always had, `reports.financial`.
 *
 * A FILE (CSV, PDF, a scheduled mailing) needs view of the page it comes
 * from and an export permission: `reports.export` for every page the holder
 * can view, or `reports.export_supply` for the Inventory and Procurement pages
 * only — the buyer's lists, without the sales book.
 *
 * Routes name their page with the `report.page:<key>` middleware
 * (App\Http\Middleware\RequiresReportPage), which also records the page on
 * the request so ExportsCsv can apply the export rule for that page.
 */
final class ReportPages
{
    /** Page key => permission slug. The keys are what routes and schedules name. */
    public const PAGES = [
        'executive'    => 'reports.executive',
        'sales'        => 'reports.sales',
        'customers'    => 'reports.customers',
        'financial'    => 'reports.financial',
        'production'   => 'reports.production',
        'inventory'    => 'reports.inventory',
        'procurement'  => 'reports.procurement',
        'performance'  => 'reports.performance',
        'signals'      => 'reports.signals',
        'data_quality' => 'reports.data_quality',
        'explorer'     => 'reports.explorer',
    ];

    public const EXPORT = 'reports.export';

    public const EXPORT_SUPPLY = 'reports.export_supply';

    /** The pages reports.export_supply reaches. */
    public const SUPPLY_PAGES = ['inventory', 'procurement'];

    /**
     * Each drill-down and the pages that offer it. A drill is not a page of
     * its own: it inherits the page it is opened from, so it is open to anyone
     * who holds one of these. Kept in step with the react-admin pages that
     * render a drill (ReportsPage, Sales, Customers, Finance, Performance).
     */
    public const DRILLS = [
        'revenue'              => ['executive', 'sales', 'financial', 'performance'],
        'orders'               => ['executive'],
        'lost'                 => ['sales'],
        'collected'            => ['executive'],
        'outstanding'          => ['executive'],
        'new_customers'        => ['executive', 'customers'],
        'production_completed' => ['executive'],
        'production_overdue'   => ['executive'],
        'expenses'             => ['financial'],
    ];

    public static function slug(string $page): string
    {
        if (! isset(self::PAGES[$page])) {
            throw new \InvalidArgumentException("Unknown report page: {$page}");
        }

        return self::PAGES[$page];
    }

    public static function canView(?User $user, string $page): bool
    {
        return (bool) $user?->can(self::slug($page));
    }

    public static function canViewAny(?User $user, array $pages): bool
    {
        foreach ($pages as $page) {
            if (self::canView($user, $page)) {
                return true;
            }
        }

        return false;
    }

    /** May this user take a file out of this page? View is part of the rule. */
    public static function canExport(?User $user, string $page): bool
    {
        if (! self::canView($user, $page)) {
            return false;
        }

        return $user->can(self::EXPORT)
            || (in_array($page, self::SUPPLY_PAGES, true) && $user->can(self::EXPORT_SUPPLY));
    }

    /** Page keys, for "any report page" gates. */
    public static function keys(): array
    {
        return array_keys(self::PAGES);
    }
}
