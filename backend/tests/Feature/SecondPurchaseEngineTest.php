<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The second-purchase engine: first purchase → second purchase, measured.
 *
 * 86.5% of the trailing year's production revenue came from one-time buyers,
 * so this is the conversion the customer reports exist to move. The cohort
 * fixtures here are hand-computed — every rate asserted below was worked out
 * on paper first, so the SQL has an external answer to be wrong against.
 */
class SecondPurchaseEngineTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $phone, float $amount, int $daysAgo, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $amount, 'currency_code' => 'KES',
            'customer_phone' => $phone, 'customer_first_name' => 'C' . substr($phone, -3),
            'created_at' => now()->subDays($daysAgo),
        ], $extra));
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));

        return $user;
    }

    public function test_the_cohort_maths_matches_a_hand_computed_answer(): void
    {
        // Cohort: 5 customers whose FIRST order was ~100 days ago.
        //   A returns after 10 days  -> inside 30/60/90
        //   B returns after 45 days  -> inside 60/90 only
        //   C returns after 80 days  -> inside 90 only
        //   D, E never return
        // Hand-computed: rate_30 = 1/5 = 20%, rate_60 = 2/5 = 40%, rate_90 = 3/5 = 60%.
        $this->order('0722000001', 1000, 100); $this->order('0722000001', 1000, 90);
        $this->order('0722000002', 1000, 100); $this->order('0722000002', 1000, 55);
        $this->order('0722000003', 1000, 100); $this->order('0722000003', 1000, 20);
        $this->order('0722000004', 1000, 100);
        $this->order('0722000005', 1000, 100);

        $r = MetricEngine::for(User::factory()->create())->secondPurchase();
        $cohort = collect($r['cohorts'])->firstWhere('cohort', now()->subDays(100)->format('Y-m'));

        $this->assertNotNull($cohort);
        $this->assertSame(5, $cohort['first_time_buyers']);
        $this->assertSame(20.0, $cohort['rate_30_pct']);
        $this->assertSame(40.0, $cohort['rate_60_pct']);
        $this->assertSame(60.0, $cohort['rate_90_pct']);
        $this->assertTrue($cohort['mature_30'], '100 days out, the 30-day window has closed');
        $this->assertTrue($cohort['mature_60']);
    }

    public function test_an_immature_window_is_flagged_not_reported_as_failure(): void
    {
        // First orders 10 days ago: a 0% 90-day rate here is "not yet", and the
        // report must say so rather than let the chart read "nobody returns".
        $this->order('0722000011', 1000, 10);
        $this->order('0722000012', 1000, 10);

        $r = MetricEngine::for(User::factory()->create())->secondPurchase();
        $cohort = collect($r['cohorts'])->firstWhere('cohort', now()->subDays(10)->format('Y-m'));

        $this->assertNotNull($cohort);
        $this->assertFalse($cohort['mature_90'], 'the cohort has not had 90 days yet');
    }

    public function test_the_summary_counts_repeat_rate_and_the_opportunity(): void
    {
        // 4 first-time buyers in-window: 1 returned, 3 one-timers at
        // 1000/2000/3000 KES -> avg 2000, opportunity = 10% x 3 x 2000 = 600.
        $this->order('0722000021', 5000, 50); $this->order('0722000021', 5000, 30);
        $this->order('0722000022', 1000, 40);
        $this->order('0722000023', 2000, 40);
        $this->order('0722000024', 3000, 40);

        $r = MetricEngine::for(User::factory()->create())->secondPurchase();
        $s = $r['summary'];

        $this->assertSame(4, $s['first_time_buyers']);
        $this->assertSame(1, $s['returned']);
        $this->assertSame(25.0, $s['repeat_rate_pct']);
        $this->assertSame(20, $s['median_days_to_second'], 'A returned 50d -> 30d ago: 20 days');
        $this->assertSame(3, $s['one_time_buyers']);
        $this->assertSame(2000.0, $s['avg_first_order_kes']);
        $this->assertSame(600.0, $s['opportunity_10pct_kes']);
    }

    public function test_the_worklist_is_one_time_buyers_only_biggest_first(): void
    {
        $big    = $this->order('0722000031', 90000, 5);
        $this->order('0722000032', 1000, 10);
        // A repeat buyer must NOT appear — they already converted.
        $this->order('0722000033', 50000, 20); $this->order('0722000033', 50000, 8);
        // An unconfirmed cart is not a first purchase.
        $this->order('0722000034', 70000, 3, ['status' => 'pending', 'payment_status' => 'pending']);

        $r = MetricEngine::for(User::factory()->create())->secondPurchase();
        $phones = collect($r['worklist'])->pluck('phone')->all();

        $this->assertSame('0722000031', $r['worklist'][0]['phone'], 'biggest first order leads');
        $this->assertSame($big->id, $r['worklist'][0]['order_id']);
        $this->assertContains('0722000032', $phones);
        $this->assertNotContains('0722000033', $phones, 'repeat buyers are not worklist material');
        $this->assertNotContains('0722000034', $phones, 'an abandoned cart is not a first purchase');
    }

    public function test_a_dollar_first_order_converts_at_the_reporting_rate(): void
    {
        $this->order('0722000041', 100, 5, ['currency_code' => 'USD']);   // => KES 12,800

        $r = MetricEngine::for(User::factory()->create())->secondPurchase();
        $row = collect($r['worklist'])->firstWhere('phone', '0722000041');

        $this->assertSame(100.0, $row['total_amount'], 'native amount kept — staff talk in dollars');
        $this->assertSame(12800.0, $row['total_kes']);
        $this->assertSame(12800.0, $r['summary']['avg_first_order_kes']);
    }

    public function test_outlet_scoping_is_inherited(): void
    {
        $outletA = \App\Models\Outlet::factory()->create();
        $outletB = \App\Models\Outlet::factory()->create();
        $this->order('0722000051', 1000, 5, ['outlet_id' => $outletA->id]);
        $this->order('0722000052', 2000, 5, ['outlet_id' => $outletB->id]);

        $scoped = MetricEngine::for(User::factory()->create(), $outletA->id)->secondPurchase();

        $phones = collect($scoped['worklist'])->pluck('phone')->all();
        $this->assertContains('0722000051', $phones);
        $this->assertNotContains('0722000052', $phones, 'the other outlet\'s customer must be invisible');
    }

    public function test_the_endpoint_answers_and_requires_reports_view(): void
    {
        $this->order('0722000061', 1000, 5);

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/second-purchase');
        $res->assertOk();
        $this->assertArrayHasKey('summary', $res->json());
        $this->assertArrayHasKey('cohorts', $res->json());
        $this->assertArrayHasKey('worklist', $res->json());

        $nobody = User::factory()->create();
        $nobody->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $this->actingAs($nobody, 'sanctum')
            ->getJson('/api/v1/admin/reports/second-purchase')
            ->assertForbidden();
    }

    public function test_the_default_worklist_window_is_90_days(): void
    {
        // Set by the product, not the median: consumables take up to three
        // months to exhaust, so the reorder moment can land ~90 days after the
        // first purchase — and the Replenishment Radar cannot see a one-time
        // buyer (it needs two purchases to detect a rhythm), so until the
        // second purchase exists this list is the only place they appear.
        // Inside the window they are on the list; outside it they still count
        // in the summary — the window shapes the list, not the truth.
        $this->order('0722000071', 1000, 85);    // inside
        $this->order('0722000072', 9000, 100);   // outside

        $r = MetricEngine::for(User::factory()->create())->secondPurchase();
        $phones = collect($r['worklist'])->pluck('phone')->all();

        $this->assertSame(90, $r['recent_days']);
        $this->assertContains('0722000071', $phones);
        $this->assertNotContains('0722000072', $phones,
            'a 100-day-old one-time buyer is outside the default window');
        $this->assertSame(2, $r['summary']['one_time_buyers'],
            'the summary still counts both — the window shapes the list, not the truth');
    }

    public function test_an_empty_database_answers_with_zeros_not_errors(): void
    {
        $r = MetricEngine::for(User::factory()->create())->secondPurchase();

        $this->assertSame(0, $r['summary']['first_time_buyers']);
        $this->assertSame(0.0, $r['summary']['repeat_rate_pct']);
        $this->assertNull($r['summary']['median_days_to_second']);
        $this->assertNull($r['summary']['opportunity_10pct_kes']);
        $this->assertSame([], $r['cohorts']);
        $this->assertSame([], $r['worklist']);
    }
}
