<?php

namespace Tests;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * What the retired `reports.view` used to open, for tests written against it.
 *
 * Phase 3A replaced the single front door with one permission per report
 * page (App\Support\ReportPages). A test whose subject is a report's figures,
 * not who may see it, grants every non-financial page — exactly the access
 * reports.view gave — so it keeps testing what it was written to test.
 * Per-page access itself is asserted in ReportPageAccessTest.
 *
 * Since 2026-10-15 the money pages (executive, sales, customers, financial,
 * performance, explorer) open for the owner only (App\Support\
 * FinancialVisibility), so the same reasoning makes the viewer the owner:
 * the figures are tested as the person who now sees them. Who may open which
 * page is asserted in FinancialVisibilityTest and ReportPageAccessTest.
 */
final class ReportAccess
{
    /** Every report page except Finance & Cash, which keeps reports.financial. */
    public const PAGES = [
        'reports.executive', 'reports.sales', 'reports.customers', 'reports.production',
        'reports.inventory', 'reports.procurement', 'reports.performance', 'reports.signals',
        'reports.data_quality', 'reports.explorer',
    ];

    public static function grantPages(User $user): void
    {
        foreach (self::PAGES as $slug) {
            $user->givePermissionTo(Permission::findOrCreate($slug, 'sanctum'));
        }
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
    }
}
