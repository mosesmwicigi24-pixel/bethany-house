<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening, Phase 3A — one permission per report page, and the export
 * split (bh-tools/phase3_spec.md §3A, from the Role Hardening Plan §6).
 *
 * The grants table below is written out by hand from the spec, NOT derived
 * from SyncPermissions or App\Support\ReportPages, so every assertion compares
 * the code with the decision. Each page is checked in both directions: every
 * role the table names may open it, every other role is refused.
 *
 *   view   — a role sees a page only if it holds that page's slug;
 *   drill  — a drill-down inherits the page it is opened from;
 *   export — a file (CSV or PDF) needs view of that page AND reports.export,
 *            or reports.export_supply on Inventory / Procurement only.
 */
class ReportPageAccessTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_03_300004_report_page_permissions.php';

    private const ROLES = [
        'admin', 'finance_manager', 'accountant', 'outlet_manager', 'procurement_manager',
        'procurement_officer', 'pos_clerk', 'tailor', 'system_admin',
    ];

    /** Plan §6, as the spec table states it. */
    private const PAGES = [
        'executive' => [
            'slug'  => 'reports.executive',
            'roles' => ['admin', 'finance_manager'],
            'view'  => ['/api/v1/admin/reports/executive', '/api/v1/admin/reports/engine-room'],
            'csv'   => [],
            'pdf'   => null,
        ],
        'sales' => [
            'slug'  => 'reports.sales',
            'roles' => ['admin', 'finance_manager', 'accountant', 'outlet_manager'],
            'view'  => [
                '/api/v1/admin/reports/sales/summary', '/api/v1/admin/reports/sales/ledger',
                '/api/v1/admin/reports/sales/by-payment-method', '/api/v1/admin/reports/outcomes',
                '/api/v1/admin/reports/collections', '/api/v1/admin/reports/attach-rates',
                '/api/v1/admin/reports/international', '/api/v1/admin/reports/order-pipeline',
            ],
            'csv'   => ['/api/v1/admin/reports/sales/summary', '/api/v1/admin/reports/collections'],
            'pdf'   => 'sales',
        ],
        'customers' => [
            'slug'  => 'reports.customers',
            'roles' => ['admin'],
            'view'  => [
                '/api/v1/admin/reports/customers/summary', '/api/v1/admin/reports/customers/aging',
                '/api/v1/admin/reports/customer-intelligence', '/api/v1/admin/reports/sales/neema',
                '/api/v1/admin/reports/replenishment', '/api/v1/admin/reports/win-back',
                '/api/v1/admin/reports/institutions', '/api/v1/admin/reports/second-purchase',
                '/api/v1/admin/reports/outreach-log',
                // Storefront Insights: visitor and buyer geography, the
                // storefront's half of the customer picture.
                '/api/v1/admin/analytics/overview',
            ],
            'csv'   => ['/api/v1/admin/reports/customers/lifetime-value', '/api/v1/admin/reports/win-back'],
            'pdf'   => 'customers',
        ],
        'financial' => [
            'slug'  => 'reports.financial',
            'roles' => ['finance_manager', 'accountant'],
            'view'  => [
                '/api/v1/admin/reports/financial/profit-loss', '/api/v1/admin/reports/financial/tax',
                '/api/v1/admin/reports/financial-intelligence',
            ],
            'csv'   => ['/api/v1/admin/reports/financial/profit-loss'],
            'pdf'   => 'financial',
        ],
        'production' => [
            'slug'  => 'reports.production',
            'roles' => ['admin', 'finance_manager', 'accountant', 'procurement_manager', 'procurement_officer', 'outlet_manager'],
            'view'  => [
                '/api/v1/admin/reports/production/summary', '/api/v1/admin/reports/production/efficiency',
                '/api/v1/admin/reports/production-intelligence',
            ],
            'csv'   => ['/api/v1/admin/reports/production/summary'],
            'pdf'   => 'production',
        ],
        'inventory' => [
            'slug'  => 'reports.inventory',
            'roles' => ['admin', 'finance_manager', 'accountant', 'procurement_manager', 'procurement_officer', 'outlet_manager'],
            'view'  => [
                '/api/v1/admin/reports/inventory/stock-on-hand', '/api/v1/admin/reports/inventory/aging',
                '/api/v1/admin/reports/inventory/valuation', '/api/v1/admin/reports/inventory-intelligence',
                '/api/v1/admin/reports/stockout-loss',
            ],
            'csv'   => ['/api/v1/admin/reports/inventory/stock-on-hand', '/api/v1/admin/reports/stockout-loss'],
            'pdf'   => 'inventory',
        ],
        'procurement' => [
            'slug'  => 'reports.procurement',
            'roles' => ['admin', 'finance_manager', 'accountant', 'procurement_manager'],
            'view'  => [
                '/api/v1/admin/reports/purchase-orders', '/api/v1/admin/reports/procurement-intelligence',
                '/api/v1/admin/reports/seasonal-demand',
            ],
            'csv'   => ['/api/v1/admin/reports/purchase-orders', '/api/v1/admin/reports/seasonal-demand'],
            'pdf'   => 'procurement',
        ],
        'performance' => [
            'slug'  => 'reports.performance',
            'roles' => ['admin', 'finance_manager', 'accountant', 'outlet_manager'],
            'view'  => ['/api/v1/admin/reports/performance'],
            'csv'   => ['/api/v1/admin/reports/performance'],
            'pdf'   => null,
        ],
        'signals' => [
            'slug'  => 'reports.signals',
            'roles' => ['admin', 'finance_manager'],
            // The Signals page's own feeds. Each still needs its module's
            // view permission as well; both admin and finance hold these.
            'view'  => [
                '/api/v1/admin/intelligence/reorder-suggestions', '/api/v1/admin/intelligence/material-shortages',
                '/api/v1/admin/intelligence/budget-warnings',
            ],
            'csv'   => [],
            'pdf'   => null,
        ],
        'data_quality' => [
            'slug'  => 'reports.data_quality',
            'roles' => ['admin', 'finance_manager'],
            'view'  => ['/api/v1/admin/reports/data-quality'],
            'csv'   => ['/api/v1/admin/reports/data-quality'],
            'pdf'   => null,
        ],
        'explorer' => [
            'slug'  => 'reports.explorer',
            'roles' => ['admin', 'finance_manager'],
            'view'  => ['/api/v1/admin/reports/explorer?by=month', '/api/v1/admin/reports/explorer/orders'],
            'csv'   => ['/api/v1/admin/reports/explorer?by=month'],
            'pdf'   => null,
        ],
    ];

    /** reports.export → admin, finance_manager; reports.export_supply → procurement_manager. */
    private const EXPORTERS = ['admin', 'finance_manager'];

    private const SUPPLY_EXPORTERS = ['procurement_manager'];

    private const SUPPLY_PAGES = ['inventory', 'procurement'];

    /**
     * Which pages offer each drill-down (react-admin: ReportsPage, Sales,
     * Customers, Finance, Performance). A drill is allowed to anyone who holds
     * one of those pages — it inherits the page, it is not a page of its own.
     */
    private const DRILLS = [
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

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function actWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('staff_probe', 'sanctum'));
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function csv(string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'export=csv';
    }

    private function mayView(string $role, string $page): bool
    {
        return in_array($role, self::PAGES[$page]['roles'], true);
    }

    private function mayExport(string $role, string $page): bool
    {
        if (! $this->mayView($role, $page)) {
            return false;
        }

        return in_array($role, self::EXPORTERS, true)
            || (in_array($role, self::SUPPLY_EXPORTERS, true) && in_array($page, self::SUPPLY_PAGES, true));
    }

    private function assertAllowed(string $method, string $url, string $why, array $body = []): void
    {
        $status = $this->json($method, $url, $body)->status();
        $this->assertNotSame(403, $status, "{$why}: {$method} {$url} refused");
        $this->assertLessThan(500, $status, "{$why}: {$method} {$url} answered {$status}");
    }

    private function assertRefused(string $method, string $url, string $why, array $body = []): void
    {
        $this->assertSame(403, $this->json($method, $url, $body)->status(), "{$why}: {$method} {$url} not refused");
    }

    private function checkPage(string $page): void
    {
        $spec = self::PAGES[$page];
        foreach (self::ROLES as $role) {
            $this->actAs($role);
            $view = $this->mayView($role, $page);
            $export = $this->mayExport($role, $page);

            foreach ($spec['view'] as $url) {
                $view
                    ? $this->assertAllowed('GET', $url, "{$role} views {$page}")
                    : $this->assertRefused('GET', $url, "{$role} must not view {$page}");
            }
            foreach ($spec['csv'] as $url) {
                $export
                    ? $this->assertAllowed('GET', $this->csv($url), "{$role} exports {$page}")
                    : $this->assertRefused('GET', $this->csv($url), "{$role} must not export {$page}");
            }
            if ($spec['pdf']) {
                $url = "/api/v1/admin/reports/pdf/{$spec['pdf']}";
                $export
                    ? $this->assertAllowed('GET', $url, "{$role} prints {$page}")
                    : $this->assertRefused('GET', $url, "{$role} must not print {$page}");
            }
        }

        // super_admin passes everything (Gate::before) — the positive control.
        $sa = User::factory()->create();
        $sa->assignRole(Role::findByName('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($sa->fresh());
        foreach ($spec['view'] as $url) {
            $this->assertAllowed('GET', $url, "super_admin views {$page}");
        }
    }

    // ── the page matrix: every role × every page, both directions ───────────

    public function test_executive(): void    { $this->checkPage('executive'); }
    public function test_sales(): void        { $this->checkPage('sales'); }
    public function test_customers(): void    { $this->checkPage('customers'); }
    public function test_financial(): void    { $this->checkPage('financial'); }
    public function test_production(): void   { $this->checkPage('production'); }
    public function test_inventory(): void    { $this->checkPage('inventory'); }
    public function test_procurement(): void  { $this->checkPage('procurement'); }
    public function test_performance(): void  { $this->checkPage('performance'); }
    public function test_signals(): void      { $this->checkPage('signals'); }
    public function test_data_quality(): void { $this->checkPage('data_quality'); }
    public function test_explorer(): void     { $this->checkPage('explorer'); }

    public function test_every_role_holds_exactly_the_report_grants_in_the_table(): void
    {
        foreach (array_merge(self::ROLES) as $role) {
            $want = [];
            foreach (self::PAGES as $spec) {
                if (in_array($role, $spec['roles'], true)) {
                    $want[] = $spec['slug'];
                }
            }
            if (in_array($role, self::EXPORTERS, true)) {
                $want[] = 'reports.export';
            }
            if (in_array($role, self::SUPPLY_EXPORTERS, true)) {
                $want[] = 'reports.export_supply';
            }
            sort($want);

            $have = Role::findByName($role, 'sanctum')->permissions->pluck('name')
                ->filter(fn ($p) => str_starts_with($p, 'reports.'))->sort()->values()->all();
            $this->assertSame($want, $have, "{$role}'s report grants");
        }
        $this->assertNull(Permission::where('name', 'reports.view')->first(), 'reports.view is retired');
    }

    // ── the named cases from the spec ────────────────────────────────────────

    public function test_admin_is_refused_finance_and_cash_everywhere(): void
    {
        $this->actAs('admin');
        foreach (['/api/v1/admin/reports/financial/profit-loss', '/api/v1/admin/reports/financial/cash-flow',
            '/api/v1/admin/reports/financial/revenue', '/api/v1/admin/reports/financial/expenses',
            '/api/v1/admin/reports/financial-intelligence', '/api/v1/admin/reports/pdf/financial',
            '/api/v1/admin/reports/drill/expenses', '/api/v1/admin/reports/production/costing-summary'] as $url) {
            $this->assertRefused('GET', $url, 'admin, Finance & Cash');
        }
        $this->assertRefused('POST', '/api/v1/admin/reports/schedules', 'admin schedules finance', [
            'name' => 'Weekly P&L', 'report_type' => 'financial', 'frequency' => 'weekly',
            'recipients' => ['a@example.test'], 'format' => 'csv',
        ]);
    }

    public function test_accountant_views_six_pages_and_exports_none(): void
    {
        $this->actAs('accountant');
        foreach (['sales', 'production', 'inventory', 'procurement', 'performance', 'financial'] as $page) {
            foreach (self::PAGES[$page]['view'] as $url) {
                $this->assertAllowed('GET', $url, "accountant views {$page}");
            }
            foreach (self::PAGES[$page]['csv'] as $url) {
                $this->assertRefused('GET', $this->csv($url), "accountant exports {$page}");
            }
            if (self::PAGES[$page]['pdf']) {
                $this->assertRefused('GET', '/api/v1/admin/reports/pdf/' . self::PAGES[$page]['pdf'], "accountant prints {$page}");
            }
        }
        foreach (['executive', 'customers', 'signals', 'data_quality', 'explorer'] as $page) {
            $this->assertRefused('GET', self::PAGES[$page]['view'][0], "accountant views {$page}");
        }
    }

    public function test_export_supply_reaches_inventory_and_procurement_only(): void
    {
        // Every page view, plus export_supply and nothing else.
        $this->actWith(array_merge(array_column(self::PAGES, 'slug'), ['reports.export_supply']));

        foreach (self::PAGES as $page => $spec) {
            foreach ($spec['csv'] as $url) {
                in_array($page, self::SUPPLY_PAGES, true)
                    ? $this->assertAllowed('GET', $this->csv($url), "export_supply on {$page}")
                    : $this->assertRefused('GET', $this->csv($url), "export_supply on {$page}");
            }
            if ($spec['pdf']) {
                $url = "/api/v1/admin/reports/pdf/{$spec['pdf']}";
                in_array($page, self::SUPPLY_PAGES, true)
                    ? $this->assertAllowed('GET', $url, "export_supply prints {$page}")
                    : $this->assertRefused('GET', $url, "export_supply prints {$page}");
            }
        }
    }

    public function test_an_export_permission_without_the_page_opens_nothing(): void
    {
        $this->actWith(['reports.export', 'reports.export_supply', 'reports.sales']);
        $this->assertAllowed('GET', '/api/v1/admin/reports/sales/summary?export=csv', 'sales held');
        $this->assertRefused('GET', '/api/v1/admin/reports/inventory/stock-on-hand?export=csv', 'inventory not held');
        $this->assertRefused('GET', '/api/v1/admin/reports/pdf/procurement', 'procurement not held');
    }

    public function test_drills_inherit_the_page_they_open_from(): void
    {
        foreach (self::ROLES as $role) {
            $this->actAs($role);
            foreach (self::DRILLS as $metric => $pages) {
                $allowed = (bool) array_filter($pages, fn ($p) => $this->mayView($role, $p));
                $url = "/api/v1/admin/reports/drill/{$metric}";
                $allowed
                    ? $this->assertAllowed('GET', $url, "{$role} drills {$metric}")
                    : $this->assertRefused('GET', $url, "{$role} must not drill {$metric}");
            }
        }

        // A single page carries its drills and no others.
        $this->actWith(['reports.performance']);
        $this->assertAllowed('GET', '/api/v1/admin/reports/drill/revenue', 'performance → revenue');
        $this->assertRefused('GET', '/api/v1/admin/reports/drill/orders', 'performance → orders');
        $this->assertRefused('GET', '/api/v1/admin/reports/drill/new_customers', 'performance → new customers');
    }

    public function test_the_outlet_list_follows_any_report_page(): void
    {
        foreach (self::ROLES as $role) {
            $this->actAs($role);
            $holdsAny = (bool) array_filter(array_keys(self::PAGES), fn ($p) => $this->mayView($role, $p));
            $holdsAny
                ? $this->assertAllowed('GET', '/api/v1/admin/reports/outlets', "{$role} outlets")
                : $this->assertRefused('GET', '/api/v1/admin/reports/outlets', "{$role} outlets");
        }
    }

    public function test_schedules_need_the_page_and_its_export(): void
    {
        $body = fn (string $type) => [
            'name' => "Weekly {$type}", 'report_type' => $type, 'frequency' => 'weekly',
            'recipients' => ['a@example.test'], 'format' => 'csv',
        ];

        $this->actAs('procurement_manager');
        $this->assertAllowed('POST', '/api/v1/admin/reports/schedules', 'PM schedules inventory', $body('inventory'));
        $this->assertAllowed('POST', '/api/v1/admin/reports/schedules', 'PM schedules procurement', $body('procurement'));
        $this->assertRefused('POST', '/api/v1/admin/reports/schedules', 'PM schedules production (views, no export)', $body('production'));
        $this->assertRefused('POST', '/api/v1/admin/reports/schedules', 'PM schedules sales (no view)', $body('sales'));

        $this->actAs('accountant');
        $this->assertRefused('POST', '/api/v1/admin/reports/schedules', 'accountant schedules sales', $body('sales'));

        $this->actAs('finance_manager');
        $this->assertAllowed('POST', '/api/v1/admin/reports/schedules', 'FM schedules finance', $body('financial'));
        $this->assertRefused('POST', '/api/v1/admin/reports/schedules', 'FM schedules customers (no view)', $body('customers'));

        // Listing shows only schedules for pages the caller can open.
        $this->actAs('procurement_manager');
        $types = collect($this->getJson('/api/v1/admin/reports/schedules')->assertOk()->json('schedules'))
            ->pluck('report_type')->unique()->sort()->values()->all();
        $this->assertSame(['inventory', 'procurement'], $types);

        // Deleting is the same right as creating.
        $this->assertRefused('DELETE', '/api/v1/admin/reports/schedules/financial_weekly-financial', 'PM deletes finance schedule');
        $this->assertAllowed('DELETE', '/api/v1/admin/reports/schedules/inventory_weekly-inventory', 'PM deletes own-page schedule');
    }

    public function test_every_reports_route_names_its_page(): void
    {
        $unguarded = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/reports')) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            if (! collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'report.page:'))) {
                $unguarded[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
            $this->assertNotContains('permission:reports.view,sanctum', $mw, $route->uri());
        }
        $this->assertSame([], $unguarded, 'report routes without a page gate');
    }

    // ── non-report uses of the old reports.view ─────────────────────────────

    // One dashboard request per test: its stat queries run in try/catch, and
    // a caught failure would poison the shared test transaction.
    public function test_dashboard_revenue_follows_the_sales_report(): void
    {
        $this->actAs('outlet_manager');
        $this->assertArrayHasKey('today_sales', $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats'));
    }

    public function test_dashboard_withholds_revenue_without_the_sales_report(): void
    {
        $this->actAs('procurement_manager');
        $this->assertArrayNotHasKey('today_sales', $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats'));
    }

    public function test_dashboard_kpis_follow_the_sales_report(): void
    {
        $this->actAs('outlet_manager');
        $this->assertAllowed('GET', '/api/v1/admin/reports/dashboard/kpis', 'OM dashboard kpis');
        $this->actAs('procurement_manager');
        $this->assertRefused('GET', '/api/v1/admin/reports/dashboard/kpis', 'PM dashboard kpis');
    }

    // ── the migration ────────────────────────────────────────────────────────

    /** Phase 2's report grants — what production holds before this migration. */
    private const BEFORE = [
        'admin'               => ['reports.export', 'reports.financial', 'reports.view'],
        'finance_manager'     => ['reports.export', 'reports.financial', 'reports.view'],
        'accountant'          => [],
        'outlet_manager'      => ['reports.view'],
        'procurement_manager' => ['reports.export', 'reports.view'],
        'procurement_officer' => ['reports.view'],
        'pos_clerk'           => [],
        'tailor'              => [],
        'system_admin'        => [],
    ];

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    /** role => sorted report grants. */
    private function reportGrants(): array
    {
        $out = [];
        foreach (array_merge(self::ROLES, ['legacy_custom']) as $role) {
            $r = Role::where('name', $role)->where('guard_name', 'sanctum')->first();
            if (! $r) {
                continue;
            }
            $out[$role] = DB::table('role_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('role_has_permissions.role_id', $r->id)
                ->where('permissions.name', 'like', 'reports.%')
                ->orderBy('permissions.name')->pluck('permissions.name')->all();
        }

        return $out;
    }

    private function expectedAfter(): array
    {
        $out = [];
        foreach (self::ROLES as $role) {
            $want = [];
            foreach (self::PAGES as $spec) {
                if (in_array($role, $spec['roles'], true)) {
                    $want[] = $spec['slug'];
                }
            }
            if (in_array($role, self::EXPORTERS, true)) {
                $want[] = 'reports.export';
            }
            if (in_array($role, self::SUPPLY_EXPORTERS, true)) {
                $want[] = 'reports.export_supply';
            }
            sort($want);
            $out[$role] = $want;
        }

        return $out;
    }

    /**
     * Production's shape before 3A: Phase 2's report grants, none of the new
     * slugs in existence, and a role made in the Roles screen that holds the
     * old front door.
     */
    private function rewindToPhase2(): void
    {
        $this->migration()->down();

        $new = ['reports.executive', 'reports.sales', 'reports.customers', 'reports.production',
            'reports.inventory', 'reports.procurement', 'reports.performance', 'reports.signals',
            'reports.data_quality', 'reports.explorer', 'reports.export_supply'];
        DB::table('role_has_permissions')->whereIn('permission_id',
            DB::table('permissions')->whereIn('name', $new)->pluck('id'))->delete();
        DB::table('permissions')->whereIn('name', $new)->delete();

        foreach (self::BEFORE as $role => $perms) {
            $r = Role::findByName($role, 'sanctum');
            DB::table('role_has_permissions')->where('role_id', $r->id)->whereIn('permission_id',
                DB::table('permissions')->where('name', 'like', 'reports.%')->pluck('id'))->delete();
            foreach ($perms as $p) {
                DB::table('role_has_permissions')->insert([
                    'role_id' => $r->id, 'permission_id' => Permission::findOrCreate($p, 'sanctum')->id,
                ]);
            }
        }
        $custom = Role::findOrCreate('legacy_custom', 'sanctum');
        DB::table('role_has_permissions')->insert([
            'role_id' => $custom->id, 'permission_id' => Permission::findByName('reports.view', 'sanctum')->id,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function before(): array
    {
        return array_merge(self::BEFORE, ['legacy_custom' => ['reports.view']]);
    }

    public function test_migration_takes_phase_2_to_the_table_and_sync_keeps_it(): void
    {
        $this->rewindToPhase2();
        $this->assertSame($this->before(), $this->reportGrants());

        $this->migration()->up();
        $after = $this->expectedAfter();
        // A role made in the Roles screen keeps exactly the pages the old
        // front door opened — never Finance, never a file.
        $after['legacy_custom'] = ['reports.customers', 'reports.data_quality', 'reports.executive',
            'reports.explorer', 'reports.inventory', 'reports.performance', 'reports.procurement',
            'reports.production', 'reports.sales', 'reports.signals'];
        $this->assertSame($after, $this->reportGrants(), 'after the migration, before sync');
        $this->assertNull(Permission::where('name', 'reports.view')->first(), 'reports.view retired');

        Artisan::call('permission:sync');
        $this->assertSame($after, $this->reportGrants(), 'after the migration and sync');
    }

    public function test_migration_is_idempotent(): void
    {
        $this->rewindToPhase2();
        $this->migration()->up();
        $first = $this->reportGrants();
        $this->migration()->up();
        $this->assertSame($first, $this->reportGrants());
    }

    public function test_down_restores_phase_2_exactly(): void
    {
        $this->rewindToPhase2();
        $migration = $this->migration();
        $migration->up();
        Artisan::call('permission:sync');

        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame($this->before(), $this->reportGrants());
        $this->assertNotNull(Permission::where('name', 'reports.view')->first());
        $this->assertTrue(! Schema::hasTable('role_grant_changes')
            || DB::table('role_grant_changes')->where('migration', '2026_10_03_300004_report_page_permissions')->doesntExist());
    }

    public function test_migration_keeps_reports_view_for_a_person_who_holds_it_directly(): void
    {
        $this->rewindToPhase2();
        $person = User::factory()->create();
        $person->givePermissionTo('reports.view');
        $before = DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray();

        $migration = $this->migration();
        $migration->up();
        $this->assertEquals($before, DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray());
        $this->assertNotNull(Permission::where('name', 'reports.view')->first(), 'kept: someone holds it directly');

        $migration->down();
        $this->assertEquals($before, DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray());
    }
}
