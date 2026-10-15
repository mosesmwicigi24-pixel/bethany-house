<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use App\Support\FinancialVisibility;
use App\Support\ReportPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Who sees the business's money, how widely and for how long (owner,
 * 2026-10-15): the multi-day money reports are the owner's (Finance & Cash
 * also finance's); everyone else sees TODAY, within their range — business,
 * outlet, team or their own. Real roles from permission:sync, real Postgres.
 */
class FinancialVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private Outlet $north;
    private Outlet $south;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->north = Outlet::factory()->create(['name' => 'North']);
        $this->south = Outlet::factory()->create(['name' => 'South']);
    }

    private function person(string $role, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        if ($outlet) {
            $u->outlets()->attach($outlet->id, ['is_primary' => true]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function as(User $u): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u->fresh();
    }

    /** A paid sale, made by $by at $outlet, $daysAgo days back. */
    private function sale(float $total, Outlet $outlet, ?User $by = null, int $daysAgo = 0): Order
    {
        $at = now()->subDays($daysAgo);
        $order = Order::factory()->create([
            'order_type' => 'pos', 'outlet_id' => $outlet->id, 'created_by' => $by?->id,
            'status' => 'completed', 'payment_status' => 'paid', 'total_amount' => $total,
            'currency_code' => 'KES', 'created_at' => $at, 'updated_at' => $at,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id, 'amount' => $total, 'status' => 'paid',
            'payment_method' => 'cash', 'currency_code' => 'KES', 'created_at' => $at,
        ]);

        return $order;
    }

    private function permissionsSeenBy(User $u): array
    {
        $this->as($u);

        return $this->getJson('/api/v1/admin/auth/me')->assertOk()->json('user.permissions');
    }

    // ── Report pages ─────────────────────────────────────────────────────────

    public function test_money_report_pages_are_the_owners(): void
    {
        $owner = $this->person('super_admin');
        $admin = $this->person('admin');

        foreach (FinancialVisibility::MONEY_PAGES as $page) {
            $this->assertTrue(ReportPages::canView($owner, $page), "owner lost {$page}");
            $this->assertFalse(ReportPages::canView($admin, $page), "admin still opens {$page}");
        }
        // Operational pages are the holder's working tools, unchanged.
        foreach (['production', 'inventory', 'procurement', 'data_quality'] as $page) {
            $this->assertTrue(ReportPages::canView($admin, $page), "admin lost {$page}");
        }

        // Every door: the JSON report, its PDF, and the drill.
        $this->as($admin);
        $this->getJson('/api/v1/admin/reports/sales/summary')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/executive')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/performance')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/drill/revenue')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/production/summary')->assertOk();

        $this->as($owner);
        $this->getJson('/api/v1/admin/reports/sales/summary?start_date=2026-01-01&end_date=' . today()->toDateString())->assertOk();
    }

    public function test_finance_keeps_finance_and_cash_for_any_period_and_nothing_else(): void
    {
        $accountant = $this->as($this->person('accountant'));

        $this->assertTrue(ReportPages::canView($accountant, 'financial'));
        $this->getJson('/api/v1/admin/reports/financial/profit-loss?start_date=2026-01-01&end_date=' . today()->toDateString())
            ->assertOk();
        $this->getJson('/api/v1/admin/reports/sales/summary')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/performance')->assertForbidden();

        $perms = $this->permissionsSeenBy($accountant);
        $this->assertContains('reports.financial', $perms);
        $this->assertNotContains('reports.sales', $perms);
    }

    public function test_the_console_is_told_the_effective_permissions_and_the_period(): void
    {
        $admin = $this->person('admin');
        $perms = $this->permissionsSeenBy($admin);
        foreach (['reports.executive', 'reports.sales', 'reports.customers', 'reports.performance', 'reports.explorer'] as $p) {
            $this->assertNotContains($p, $perms, "the console still offers {$p}");
        }
        $this->assertContains('reports.production', $perms);
        // The role itself is untouched — this is visibility, not a revocation.
        $this->assertTrue(Role::findByName('admin', 'sanctum')->hasPermissionTo('reports.sales'));

        $this->getJson('/api/v1/admin/auth/me')
            ->assertJsonPath('user.money_view.period', 'today')
            ->assertJsonPath('user.money_view.range', 'business');

        $this->as($this->person('super_admin'));
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('user.money_view.period', 'any');
    }

    // ── The payments ledger ──────────────────────────────────────────────────

    public function test_the_payments_ledger_answers_admin_for_today_only(): void
    {
        $this->sale(1000, $this->north);                 // today
        $this->sale(7000, $this->north, null, 3);        // three days ago
        $from = today()->subDays(10)->toDateString();
        $to   = today()->toDateString();

        $this->as($this->person('admin'));
        $res = $this->getJson("/api/v1/admin/payment-transactions?start_date={$from}&end_date={$to}")->assertOk();
        $res->assertHeader('X-Money-Window', 'today');
        $this->assertEqualsCanonicalizing([1000.0], array_map('floatval', array_column($res->json('data'), 'amount')));

        // The same window for finance: both payments.
        $this->as($this->person('finance_manager'));
        $res = $this->getJson("/api/v1/admin/payment-transactions?start_date={$from}&end_date={$to}")->assertOk();
        $this->assertFalse($res->headers->has('X-Money-Window'));
        $this->assertEqualsCanonicalizing([1000.0, 7000.0], array_map('floatval', array_column($res->json('data'), 'amount')));
    }

    // ── Range: outlet, team, own — and the reconciliation ────────────────────

    public function test_todays_sales_by_range_reconcile_to_the_business_total(): void
    {
        $northClerk = $this->person('pos_clerk', $this->north);
        $southClerk = $this->person('pos_clerk', $this->south);
        $this->sale(1200, $this->north, $northClerk);
        $this->sale(800,  $this->north, $northClerk);
        $this->sale(500,  $this->south, $southClerk);
        $this->sale(9000, $this->north, $northClerk, 1);   // yesterday: in no "today"

        $today = function (User $u): float {
            $this->as($u);

            return (float) $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats.today_sales');
        };

        $owner = $today($this->person('super_admin'));
        $north = $today($this->person('outlet_manager', $this->north));
        $south = $today($this->person('outlet_manager', $this->south));

        // Independent arithmetic, not the implementation's: 1200 + 800 + 500.
        $this->assertEquals(2500.0, $owner);
        $this->assertEquals(2000.0, $north);
        $this->assertEquals(500.0, $south);
        $this->assertEquals($owner, $north + $south, 'Outlet views do not add up to the business.');
        $this->assertEquals(2000.0, $today($northClerk), 'A clerk sees her own sales.');
    }

    public function test_a_line_manager_sees_herself_and_her_direct_reports_only(): void
    {
        $lead   = $this->person('pos_clerk', $this->north);
        $report = $this->person('pos_clerk', $this->north);
        $deeper = $this->person('pos_clerk', $this->north);
        $other  = $this->person('pos_clerk', $this->north);
        $report->update(['reports_to' => $lead->id]);
        $deeper->update(['reports_to' => $report->id]);   // reports to her report, not to her

        $this->sale(100, $this->north, $lead);
        $this->sale(200, $this->north, $report);
        $this->sale(400, $this->north, $deeper);
        $this->sale(800, $this->north, $other);

        $this->as($lead);
        $this->assertEquals(300.0, (float) $this->getJson('/api/v1/admin/dashboard')->json('stats.today_sales'));
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('user.money_view.range', 'team');

        $creators = Order::query()->pluck('created_by')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([$lead->id, $report->id], $creators, 'Orders visible to a line manager');

        // Without reports, the old single-owner view, unchanged.
        $this->as($other);
        $this->assertEquals(800.0, (float) $this->getJson('/api/v1/admin/dashboard')->json('stats.today_sales'));
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('user.money_view.range', 'own');
    }

    public function test_pending_approvals_count_follows_the_outlet(): void
    {
        foreach ([$this->north, $this->north, $this->south] as $outlet) {
            $o = Order::factory()->create(['outlet_id' => $outlet->id, 'payment_status' => 'pending', 'total_amount' => 100]);
            Payment::factory()->create(['order_id' => $o->id, 'amount' => 100, 'status' => 'pending',
                'requires_approval' => true, 'approval_status' => 'pending_review']);
        }

        $this->as($this->person('outlet_manager', $this->south));
        $this->assertSame(1, $this->getJson('/api/v1/admin/dashboard')->json('stats.pending_payment_approvals'));
        $this->as($this->person('super_admin'));
        $this->assertSame(3, $this->getJson('/api/v1/admin/dashboard')->json('stats.pending_payment_approvals'));
    }

    // ── Cashiers' end-of-day reports ─────────────────────────────────────────

    public function test_an_outlet_manager_reviews_only_her_outlets_cashier_reports(): void
    {
        $ids = [];
        foreach (['north' => $this->north, 'south' => $this->south] as $key => $outlet) {
            $clerk = $this->person('pos_clerk', $outlet);
            $reg = DB::table('cash_registers')->insertGetId([
                'outlet_id' => $outlet->id, 'register_number' => 'R-' . $key, 'register_name' => 'T', 'status' => 'counted', 'currency_code' => 'KES',
                'opening_balance' => 0, 'expected_cash' => 0, 'opened_by' => $clerk->id, 'opened_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $ids[$key] = DB::table('cash_register_eod_reports')->insertGetId([
                'register_id' => $reg, 'user_id' => $clerk->id, 'outlet_id' => $outlet->id,
                'report_date' => today(), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->as($this->person('outlet_manager', $this->north));
        $listed = array_column($this->getJson('/api/v1/admin/pos/reports/eod-admin')->assertOk()->json('data'), 'id');
        $this->assertSame([$ids['north']], array_map('intval', $listed));
        $this->getJson("/api/v1/admin/pos/reports/eod-admin/{$ids['south']}")->assertNotFound();
        $this->postJson("/api/v1/admin/pos/reports/eod-admin/{$ids['south']}/acknowledge")->assertNotFound();
        $this->getJson("/api/v1/admin/pos/reports/eod-admin/{$ids['north']}")->assertOk();

        // The owner still reads both.
        $this->as($this->person('super_admin'));
        $this->assertCount(2, $this->getJson('/api/v1/admin/pos/reports/eod-admin')->json('data'));
    }

    // ── Who reports to whom ──────────────────────────────────────────────────

    public function test_reports_to_is_guarded_like_a_role_change(): void
    {
        $owner = $this->as($this->person('super_admin'));
        $this->stepUp($owner);
        $a = $this->person('pos_clerk', $this->north);
        $b = $this->person('pos_clerk', $this->north);

        $this->putJson("/api/v1/admin/users/{$b->id}", ['reports_to' => $a->id])->assertOk();
        $this->assertSame($a->id, (int) $b->fresh()->reports_to);

        // No loop, no self, no managing yourself.
        $this->putJson("/api/v1/admin/users/{$a->id}", ['reports_to' => $b->id])->assertStatus(422);
        $this->putJson("/api/v1/admin/users/{$a->id}", ['reports_to' => $a->id])->assertStatus(422);
        $this->putJson("/api/v1/admin/users/{$owner->id}", ['reports_to' => $a->id])->assertForbidden();

        // Cleared with null.
        $this->putJson("/api/v1/admin/users/{$b->id}", ['reports_to' => null])->assertOk();
        $this->assertNull($b->fresh()->reports_to);
    }
}
