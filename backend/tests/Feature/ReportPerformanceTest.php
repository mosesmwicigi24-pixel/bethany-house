<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Staff, Outlets & Performance — on the same definitions as every other page.
 * Outlets and salespeople each partition the same orders, so each breakdown
 * sums to the business's revenue for the window, and a salesperson's drill
 * lists exactly that person's orders.
 */
class ReportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;
    private User $ben;
    private Outlet $town;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true,
        ]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], [
            'name' => 'USD', 'symbol' => '$', 'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true,
        ]);
        \App\Support\ReportingCurrency::forget();

        $this->town = Outlet::factory()->create(['name' => 'Town']);
        $mall       = Outlet::factory()->create(['name' => 'Mall']);
        $this->ann  = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'K']);
        $this->ben  = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'O']);

        $sale = function (Outlet $o, ?User $by, float $amount, string $cur = 'KES', array $extra = []) {
            $order = Order::create($extra + [
                'order_number' => 'PF-' . uniqid(), 'order_type' => 'pos', 'status' => 'completed', 'payment_status' => 'paid',
                'currency_code' => $cur, 'subtotal' => $amount, 'total_amount' => $amount, 'outlet_id' => $o->id,
                'created_by' => $by?->id, 'customer_phone' => '07' . random_int(10000000, 99999999),
            ]);
            if (($extra['payment_status'] ?? 'paid') === 'paid') {
                Payment::create(['order_id' => $order->id, 'amount' => $amount, 'currency_code' => $cur,
                    'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()]);
            }

            return $order;
        };
        $sale($this->town, $this->ann, 10_000);
        $sale($this->town, $this->ann, 100, 'USD');          // 12,800 KES
        $sale($mall, $this->ben, 5_000);
        $sale($mall, null, 2_000);                            // a web checkout: no salesperson
        $sale($this->town, $this->ben, 9_999, 'KES', ['status' => 'pending', 'payment_status' => 'pending']); // a cart

        $u = User::factory()->create();
        foreach (['reports.view', 'orders.view'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);
    }

    public function test_both_breakdowns_partition_the_business_and_agree_with_the_overview(): void
    {
        $r = $this->getJson('/api/v1/admin/reports/performance?period=this_month')->assertOk()->json();
        $revenue = (float) $this->getJson('/api/v1/admin/reports/executive?period=this_month')->json('kpis.sales.revenue.current');

        $this->assertSame(29_800.0, $revenue, '10,000 + USD 100 x 128 + 5,000 + 2,000; the cart is not revenue');
        $this->assertSame($revenue, round(array_sum(array_column($r['outlets'], 'sold')), 2), 'outlets sum to the overview');
        $this->assertSame($revenue, round(array_sum(array_column($r['salespeople'], 'sold')), 2), 'salespeople sum to the overview');

        $people = collect($r['salespeople'])->keyBy('name');
        $this->assertSame(22_800.0, (float) $people['Ann K']['sold']);
        $this->assertSame(2, $people['Ann K']['orders']);
        $this->assertSame(11_400.0, (float) $people['Ann K']['aov']);
        $this->assertSame(2_000.0, (float) $people['No salesperson (web checkout)']['sold'], 'never dropped');
        $this->assertSame(9_999.0, (float) $people['Ben O']['unconfirmed_value'], 'the cart is shown — as unconfirmed, not sold');
        $this->assertSame(5_000.0, (float) $people['Ben O']['sold']);
    }

    public function test_the_outlet_filter_narrows_both_breakdowns(): void
    {
        $r = $this->getJson("/api/v1/admin/reports/performance?period=this_month&outlet_id={$this->town->id}")->assertOk()->json();

        $this->assertSame(['Town'], array_column($r['outlets'], 'name'));
        $this->assertSame(22_800.0, (float) $r['totals']['sold']);
        $this->assertSame(['Ann K', 'Ben O'], collect($r['salespeople'])->pluck('name')->sort()->values()->all(),
            'Ben appears only for his Town cart');
    }

    public function test_a_salesperson_drill_lists_exactly_their_orders(): void
    {
        $rows = $this->getJson("/api/v1/admin/reports/drill/revenue?period=this_month&salesperson={$this->ann->id}")
            ->assertOk()->json('rows');

        $this->assertCount(2, $rows);
        $this->assertSame(22_800.0, round(array_sum(array_column($rows, 'amount')), 2), 'the rows sum to her figure');
    }
}
