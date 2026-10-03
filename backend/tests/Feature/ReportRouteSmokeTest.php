<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cycle 9 — the report routes no other test names.
 *
 * Measured 2026-10-01 against `php artisan route:list --json`: 63 report
 * routes (62 under api/v1/admin/reports + analytics/overview), of which 20
 * were referenced by no file under tests/ (path segment after `reports/`,
 * route params as wildcards). Each gets one smoke request here against the
 * shared seeded fixture: never a 5xx, and the response carries a key the
 * controller actually returns.
 *
 * One request per test case (data provider) — see ReportHostileInputTest for
 * why a shared transaction must not carry more than one.
 */
class ReportRouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** [method, path, body, expected JSON key or 'pdf'] */
    public static function untested(): array
    {
        return [
            'GET customers/aging'              => ['GET', 'customers/aging', [], 'aging'],
            'GET financial/tax'                => ['GET', 'financial/tax', [], 'by_tax_rate'],
            'GET inventory/aging'              => ['GET', 'inventory/aging', [], 'buckets'],
            'GET inventory/low-stock'          => ['GET', 'inventory/low-stock', [], 'low_stock_items'],
            'GET inventory/movement'           => ['GET', 'inventory/movement', [], 'transactions'],
            'GET production/costing/{id}'      => ['GET', 'production/costing/{po}', [], 'report.header'],
            'GET production/costing/{missing}' => ['GET', 'production/costing/999999', [], null],
            'GET production/efficiency'        => ['GET', 'production/efficiency', [], 'efficiency'],
            'GET production/summary'           => ['GET', 'production/summary', [], 'summary'],
            'GET production/tailor-productivity' => ['GET', 'production/tailor-productivity', [], 'tailors'],
            'GET schedules'                    => ['GET', 'schedules', [], 'schedules'],
            'POST schedules'                   => ['POST', 'schedules', [
                'name' => 'Weekly sales', 'report_type' => 'sales', 'frequency' => 'weekly',
                'recipients' => ['owner@example.test'], 'format' => 'csv',
            ], 'schedule'],
            'DELETE schedules/{id}'            => ['DELETE', 'schedules/sales_weekly-sales', [], 'message'],
            'POST export/excel'                => ['POST', 'export/excel', [], 'message'],
            'POST export/pdf'                  => ['POST', 'export/pdf', [], 'message'],
            'GET pdf/sales'                    => ['GET', 'pdf/sales', [], 'pdf'],
            'GET pdf/financial'                => ['GET', 'pdf/financial', [], 'pdf'],
            'GET pdf/inventory'                => ['GET', 'pdf/inventory', [], 'pdf'],
            'GET pdf/procurement'              => ['GET', 'pdf/procurement', [], 'pdf'],
            'GET pdf/production'               => ['GET', 'pdf/production', [], 'pdf'],
            'GET pdf/customers'                => ['GET', 'pdf/customers', [], 'pdf'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untested')]
    public function test_untested_route_answers_without_a_server_error(string $method, string $path, array $body, ?string $key): void
    {
        $ids  = ReportPermissionAttackTest::seedReportFixture();
        $user = User::factory()->create();
        foreach ([...\Tests\ReportAccess::PAGES, 'reports.financial', 'reports.export', 'customers.view', 'customers.insights'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $res = $method === 'GET' && $key === 'pdf'
            ? $this->get(ReportPermissionAttackTest::uri($path, $ids))
            : $this->json($method, ReportPermissionAttackTest::uri($path, $ids), $body);

        $status = $res->getStatusCode();
        $this->assertLessThan(500, $status, "{$method} {$path}: {$status} " . ReportPermissionAttackTest::excerpt($res));

        if ($key === null) {
            $this->assertSame(404, $status, "{$method} {$path}: a missing id should be a 404");
            return;
        }

        if ($key === 'pdf') {
            $this->assertSame(200, $status);
            $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', (string) $res->getContent());
            return;
        }

        $this->assertNotNull($res->json($key), "{$method} {$path}: missing '{$key}' — " . ReportPermissionAttackTest::excerpt($res));
    }

    /** Reports-gated, but not under /reports: the visitor analytics overview. */
    public function test_analytics_overview_answers(): void
    {
        ReportPermissionAttackTest::seedReportFixture();
        $user = User::factory()->create();
        \Tests\ReportAccess::grantPages($user);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/v1/admin/analytics/overview');
        $this->assertLessThan(500, $res->getStatusCode(), ReportPermissionAttackTest::excerpt($res));
    }
}
