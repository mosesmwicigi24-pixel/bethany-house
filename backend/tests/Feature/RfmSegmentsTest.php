<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RFM segmentation (customer intelligence): canonical identity via
 * normalize_phone, percentile scoring, named segments, and the win-back action
 * list.
 */
class RfmSegmentsTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $phone, string $name, float $amount, int $daysAgo): void
    {
        Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $amount, 'currency_code' => 'KES',
            'customer_phone' => $phone, 'customer_first_name' => $name,
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_phone_formats_unify_into_one_customer_and_segments_emit(): void
    {
        // Same human, two phone spellings — must be ONE customer.
        $this->order('0722000111', 'Amos', 10000, 5);
        $this->order('+254722000111', 'Amos', 12000, 40);
        $this->order('254722000111', 'Amos', 9000, 100);
        // A high-value customer gone very quiet → at_risk / cant_lose material.
        $this->order('0733000222', 'Beryl', 80000, 200);
        $this->order('0733000222', 'Beryl', 70000, 250);
        // Anonymous walk-in (no phone, no ids).
        Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => 500, 'currency_code' => 'KES',
            'customer_phone' => null, 'created_at' => now()->subDays(3),
        ]);

        $rfm = MetricEngine::for(User::factory()->create())->rfmSegments();

        $totalCustomers = collect($rfm['segments'])->sum('customers');
        $this->assertSame(2, $totalCustomers, 'Amos (3 spellings) + Beryl = 2 customers');
        $this->assertSame(1, $rfm['anonymous']['orders']);
        $this->assertEqualsWithDelta(500.0, $rfm['anonymous']['revenue'], 0.01);

        // Beryl: 450+ days... 200d quiet, 150k, 2 orders → must land in the
        // win-back list (at_risk or cant_lose), with unified spend.
        $beryl = collect($rfm['action_list'])->firstWhere('phone', '0733000222');
        $this->assertNotNull($beryl, 'high-value quiet customer must be on the win-back list');
        $this->assertContains($beryl['segment'], ['at_risk', 'cant_lose']);
        $this->assertEqualsWithDelta(150000.0, $beryl['revenue_365'], 0.01);

        // Segment rollup revenue must equal identified revenue (31k + 150k).
        $this->assertEqualsWithDelta(181000.0, collect($rfm['segments'])->sum('revenue_365'), 0.01);
    }

    public function test_customer_intelligence_endpoint_exposes_rfm(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->order('0722000333', 'Carol', 5000, 10);

        $res = $this->getJson('/api/v1/admin/reports/customer-intelligence?period=last_30');

        $res->assertOk();
        $this->assertIsArray($res->json('rfm.segments'));
        $this->assertSame(365, $res->json('rfm.window_days'));
        $this->assertIsArray($res->json('rfm.action_list'));
    }

    /**
     * The defect this file exists to prevent recurring.
     *
     * Production had 290 customers and 260 of them had bought exactly once.
     * CUME_DIST hands a tie the UPPER bound of its group, so that bottom 90%
     * scored 260/290 = 0.897 -> f_score 5. The least frequent buyers scored
     * highest on frequency and ALL 290 customers were reported as 'champions',
     * every other segment empty. A segmentation that puts everyone in one
     * bucket is worse than none: it reads as insight and carries none.
     */
    public function test_a_base_of_one_time_buyers_is_not_all_champions(): void
    {
        // 20 customers who each bought once, recently — the real shape.
        for ($i = 1; $i <= 20; $i++) {
            $this->order('07220001' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), "One{$i}", 10000, 5);
        }
        // 2 genuine repeat customers.
        foreach (['0733000111', '0733000222'] as $n => $phone) {
            $this->order($phone, "Repeat{$n}", 20000, 10);
            $this->order($phone, "Repeat{$n}", 25000, 20);
            $this->order($phone, "Repeat{$n}", 30000, 30);
        }

        $rfm = MetricEngine::for(User::factory()->create())->rfmSegments();
        $bySegment = collect($rfm['segments'])->keyBy('segment');
        $total = collect($rfm['segments'])->sum('customers');

        $this->assertSame(22, $total);
        $this->assertLessThan(
            $total,
            (int) $bySegment['champions']['customers'],
            'a base that is 90% one-time buyers must not be 100% champions',
        );
        $this->assertSame(
            2,
            (int) $bySegment['champions']['customers'],
            'only the two genuine repeat buyers belong at the top',
        );
        $this->assertSame(
            20,
            (int) $bySegment['promising']['customers'],
            'recent single-purchase customers are promising, not champions',
        );
    }

    public function test_the_best_customer_still_reaches_the_top_score(): void
    {
        // The property the CUME_DIST change was originally made to guarantee,
        // and which the fix must not give back: on a small cohort the strongest
        // customer must still be reachable as a champion.
        $this->order('0722000001', 'Small', 5000, 5);
        $this->order('0722000002', 'Big', 50000, 5);
        $this->order('0722000002', 'Big', 60000, 15);
        $this->order('0722000002', 'Big', 70000, 25);

        $rfm = MetricEngine::for(User::factory()->create())->rfmSegments();
        $bySegment = collect($rfm['segments'])->keyBy('segment');

        $this->assertSame(1, (int) $bySegment['champions']['customers'],
            'the best customer in a 2-customer cohort must still score 5');
    }

    public function test_a_cohort_with_no_spread_says_so_instead_of_pretending(): void
    {
        // Every customer identical: same order count, same recency. There is no
        // honest way to rank them, so the report must admit it.
        foreach (['0722000011', '0722000012', '0722000013'] as $i => $phone) {
            $this->order($phone, "Same{$i}", 10000, 5);
        }

        $rfm = MetricEngine::for(User::factory()->create())->rfmSegments();

        $this->assertTrue($rfm['diagnostics']['degenerate']);
        $this->assertSame(1, $rfm['diagnostics']['distinct_frequency']);
        $this->assertNotNull($rfm['diagnostics']['note']);
        $this->assertStringContainsString('frequency cannot rank', $rfm['diagnostics']['note']);
    }

    public function test_a_healthy_cohort_is_not_flagged_degenerate(): void
    {
        $this->order('0722000021', 'A', 10000, 5);
        $this->order('0722000022', 'B', 20000, 100);
        $this->order('0722000022', 'B', 20000, 200);

        $rfm = MetricEngine::for(User::factory()->create())->rfmSegments();

        $this->assertFalse($rfm['diagnostics']['degenerate']);
        $this->assertNull($rfm['diagnostics']['note']);
    }
}
