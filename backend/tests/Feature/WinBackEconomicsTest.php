<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\WinBackOutreach;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Win-back economics — dormancy against the customer's OWN purchase rhythm
 * (not a flat 60-day rule), outreach logging with a 24h dedupe, and 30-day
 * recovery attribution feeding the recovered-revenue summary.
 */
class WinBackEconomicsTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $phone, string $name, float $amount, int $daysAgo): Order
    {
        return Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $amount, 'currency_code' => 'KES',
            'customer_phone' => $phone, 'customer_first_name' => $name,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** An outreach row logged $daysAgo days ago (created_at backdated). */
    private function outreach(string $canonicalPhone, string $name, int $daysAgo, string $channel = 'call'): WinBackOutreach
    {
        $row = WinBackOutreach::create([
            'phone' => $canonicalPhone, 'name' => $name, 'channel' => $channel,
        ]);
        DB::table('win_back_outreach')->where('id', $row->id)->update([
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);

        return $row->refresh();
    }

    public function test_dormancy_is_measured_against_the_customers_own_gap(): void
    {
        // Grace buys monthly (gaps of 30 days) and has now been quiet 70
        // days — 2.3× her usual gap. A flat 60-day rule would only just have
        // noticed; the personal-gap rule flags her as clearly overdue.
        foreach ([190, 160, 130, 100, 70] as $daysAgo) {
            $this->order('0722000111', 'Grace', 10000, $daysAgo);
        }
        // Naomi buys once a year (her previous order predates the window) and
        // is 90 days quiet — MORE quiet than Grace, yet on her own cadence
        // she is on schedule. A flat rule would put her top of the list.
        $this->order('0733000222', 'Naomi', 200000, 90);

        $report = MetricEngine::for(User::factory()->create())->winBackEconomics();

        $this->assertSame(1, $report['summary']['customers_at_risk']);
        $this->assertEqualsWithDelta(50000.0, $report['summary']['annual_value_at_risk'], 0.01);

        $this->assertCount(1, $report['customers']);
        $row = $report['customers'][0];
        $this->assertSame('Grace', $row['name']);
        $this->assertSame('0722000111', $row['phone']);
        $this->assertEqualsWithDelta(50000.0, $row['revenue_365'], 0.01);
        $this->assertSame(5, $row['orders_365']);
        $this->assertEqualsWithDelta(70, $row['days_quiet'], 1);
        $this->assertEqualsWithDelta(30.0, $row['median_gap_days'], 0.5);
        $this->assertEqualsWithDelta(2.3, $row['urgency'], 0.2);
        $this->assertNull($row['last_outreach']);
        $this->assertNull($row['recovered']);
    }

    public function test_recent_and_short_quiet_customers_stay_off_the_list(): void
    {
        // Weekly buyer 40 days quiet: 5.7× her own gap, but under the 45-day
        // noise floor — not dormant yet.
        foreach ([75, 68, 61, 54, 47, 40] as $daysAgo) {
            $this->order('0744000333', 'Wanja', 5000, $daysAgo);
        }

        $report = MetricEngine::for(User::factory()->create())->winBackEconomics();

        $this->assertSame(0, $report['summary']['customers_at_risk']);
        $this->assertSame([], $report['customers']);
    }

    public function test_outreach_post_logs_with_server_snapshot_and_dedupes_24h(): void
    {
        $user = $this->viewer();
        // 80 days quiet, 30k trailing-365 spend.
        $this->order('0722000111', 'Grace', 20000, 140);
        $this->order('0722000111', 'Grace', 10000, 80);

        $res = $this->postJson('/api/v1/admin/reports/win-back/outreach', [
            'phone' => '0722000111', 'name' => 'Grace', 'channel' => 'call',
            // Client-sent snapshot values must LOSE to the server's own look-up.
            'revenue_365' => 1, 'days_quiet' => 1,
        ]);

        $res->assertStatus(201)->assertJsonPath('already_logged', false);
        $this->assertDatabaseCount('win_back_outreach', 1);
        $row = WinBackOutreach::first();
        $this->assertSame('254722000111', $row->phone, 'phone must be stored canonical (no +)');
        $this->assertSame('call', $row->channel);
        $this->assertSame($user->id, $row->contacted_by);
        $this->assertEqualsWithDelta(30000.0, (float) $row->revenue_365_at_contact, 0.01);
        $this->assertEqualsWithDelta(80, $row->days_quiet_at_contact, 1);

        // Same identity again within 24h — different spelling of the same
        // number — must be a no-op.
        $dupe = $this->postJson('/api/v1/admin/reports/win-back/outreach', [
            'phone' => '+254722000111', 'name' => 'Grace', 'channel' => 'whatsapp',
        ]);

        $dupe->assertOk()->assertJsonPath('already_logged', true);
        $this->assertDatabaseCount('win_back_outreach', 1);
    }

    public function test_recovery_attribution_counts_orders_after_outreach_only(): void
    {
        // Grace: contacted 20 days ago, ordered 10 days later → won back.
        $this->outreach('254722000111', 'Grace', 20, 'whatsapp');
        $recovered = $this->order('0722000111', 'Grace', 15000, 10);

        // Beryl: ordered 10 days ago, contacted 5 days ago — the order came
        // BEFORE the outreach and must not be attributed to it.
        $this->order('0733000222', 'Beryl', 50000, 10);
        $this->outreach('254733000222', 'Beryl', 5);

        $report = MetricEngine::for(User::factory()->create())->winBackEconomics();

        $this->assertSame(1, $report['summary']['won_back_90d']);
        $this->assertEqualsWithDelta((float) $recovered->total_amount, $report['summary']['recovered_revenue_90d'], 0.01);
        // 2 customers contacted in the window, 1 recovered.
        $this->assertSame(50.0, $report['summary']['win_back_rate_pct']);
    }

    public function test_list_is_ranked_by_annual_value_at_risk(): void
    {
        // Two dormant monthly buyers, 70 days quiet each; Bigspender must rank first.
        foreach ([190, 160, 130, 100, 70] as $daysAgo) {
            $this->order('0722000111', 'Small', 5000, $daysAgo);
            $this->order('0733000222', 'Big', 30000, $daysAgo);
        }

        $report = MetricEngine::for(User::factory()->create())->winBackEconomics();

        $this->assertSame(2, $report['summary']['customers_at_risk']);
        $this->assertSame(['Big', 'Small'], array_column($report['customers'], 'name'));
    }

    public function test_endpoint_exposes_summary_and_customers(): void
    {
        $this->viewer();
        foreach ([190, 160, 130, 100, 70] as $daysAgo) {
            $this->order('0722000111', 'Grace', 10000, $daysAgo);
        }

        $res = $this->getJson('/api/v1/admin/reports/win-back');

        $res->assertOk();
        $this->assertSame(1, $res->json('summary.customers_at_risk'));
        $this->assertIsArray($res->json('customers'));
        $this->assertSame('Grace', $res->json('customers.0.name'));
    }

    public function test_win_back_requires_reports_view(): void
    {
        Sanctum::actingAs(User::factory()->create()); // no reports.view

        $this->getJson('/api/v1/admin/reports/win-back')->assertForbidden();
        $this->postJson('/api/v1/admin/reports/win-back/outreach', [
            'phone' => '0722000111', 'name' => 'Grace', 'channel' => 'call',
        ])->assertForbidden();
    }
}
