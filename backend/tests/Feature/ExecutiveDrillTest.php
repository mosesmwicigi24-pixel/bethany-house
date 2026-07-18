<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 2 — "every number opens" (spec rule 3) and the money aging view.
 *
 * A drill-down is the SAME query as its aggregate with the aggregation
 * removed, so these tests assert the two agree: the rows a drill returns
 * must sum to the number on the card that opened it.
 */
class ExecutiveDrillTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(array $extraPerms = []): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_manager', 'sanctum'));
        foreach (array_merge(['reports.view'], $extraPerms) as $perm) {
            $user->givePermissionTo(Permission::findOrCreate($perm, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function order(Outlet $outlet, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type'     => 'pos',
            'outlet_id'      => $outlet->id,
            'status'         => 'processing',
            'payment_status' => 'partial',
            'total_amount'   => 10000,
            'currency_code'  => 'KES',
        ], $attrs));
    }

    private function paid(Order $order, float $amount, array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'order_id' => $order->id, 'amount' => $amount, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now(),
        ], $attrs));
    }

    public function test_revenue_drill_rows_sum_to_the_revenue_kpi(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $this->order($outlet, ['total_amount' => 10000]);
        $this->order($outlet, ['total_amount' => 5000]);
        $this->order($outlet, ['total_amount' => 99000, 'status' => 'voided']);

        $kpi   = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();
        $drill = $this->getJson('/api/v1/admin/reports/drill/revenue?period=this_month')->assertOk();

        $rows = collect($drill->json('rows'));
        $this->assertSame(2, $drill->json('total'));
        $this->assertSame(
            (float) $kpi->json('kpis.sales.revenue.current'),
            (float) $rows->sum('amount'),
        );
        $this->assertFalse($rows->pluck('detail')->contains('voided'));
    }

    public function test_collected_drill_is_settled_money_net_of_refunds(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $o = $this->order($outlet);
        $this->paid($o, 5000, ['refund_amount' => 2000]);
        Payment::create([
            'order_id' => $o->id, 'amount' => 7000, 'currency_code' => 'KES',
            'payment_method' => 'bank_transfer', 'status' => 'pending',
            'requires_approval' => true, 'approval_status' => 'pending_review',
        ]);

        $drill = $this->getJson('/api/v1/admin/reports/drill/collected?period=this_month')->assertOk();

        $this->assertSame(1, $drill->json('total'));
        $this->assertSame(3000.0, (float) $drill->json('rows.0.amount'));
        $this->assertSame($o->order_number, $drill->json('rows.0.ref'));
    }

    public function test_aging_buckets_partition_the_outstanding_balance(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        // Fresh 7,000 owed; 45 days old with 6,000 owed; 100 days old with 2,000 owed.
        $this->order($outlet, ['total_amount' => 7000, 'payment_status' => 'pending']);
        $mid = $this->order($outlet, ['total_amount' => 10000, 'created_at' => now()->subDays(45)]);
        $this->paid($mid, 4000);
        $old = $this->order($outlet, ['total_amount' => 2000, 'payment_status' => 'pending', 'created_at' => now()->subDays(100)]);

        $res = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();
        $buckets = collect($res->json('kpis.money.aging.buckets'))->keyBy('key');

        $this->assertSame(7000.0, (float) $buckets['0_30']['amount']);
        $this->assertSame(6000.0, (float) $buckets['31_60']['amount']);
        $this->assertSame(0.0,    (float) $buckets['61_90']['amount']);
        $this->assertSame(2000.0, (float) $buckets['90_plus']['amount']);

        // The bucket total equals the headline outstanding number.
        $this->assertSame(
            (float) $res->json('kpis.money.outstanding.amount'),
            (float) collect($res->json('kpis.money.aging.buckets'))->sum('amount'),
        );

        // Bucket drill returns exactly that bucket's orders.
        $drill = $this->getJson('/api/v1/admin/reports/drill/outstanding?period=this_month&bucket=31_60')->assertOk();
        $this->assertSame(1, $drill->json('total'));
        $this->assertSame($mid->order_number, $drill->json('rows.0.ref'));
        $this->assertSame(6000.0, (float) $drill->json('rows.0.amount'));

        $oldDrill = $this->getJson('/api/v1/admin/reports/drill/outstanding?period=this_month&bucket=90_plus')->assertOk();
        $this->assertSame($old->order_number, $oldDrill->json('rows.0.ref'));
    }

    public function test_deposits_held_is_collected_money_on_undelivered_orders(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $dep = $this->order($outlet, ['total_amount' => 20000, 'payment_status' => 'deposit', 'deposit_amount' => 8000]);
        $this->paid($dep, 8000);

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $this->assertSame(8000.0, (float) $res->json('kpis.money.aging.deposits_held.amount'));
        $this->assertSame(1, $res->json('kpis.money.aging.deposits_held.orders'));

        $drill = $this->getJson('/api/v1/admin/reports/drill/outstanding?bucket=deposits')->assertOk();
        $this->assertSame($dep->order_number, $drill->json('rows.0.ref'));
    }

    public function test_expenses_drill_requires_reports_financial(): void
    {
        Outlet::factory()->create();
        $this->viewer();
        $this->getJson('/api/v1/admin/reports/drill/expenses')->assertStatus(403);

        $this->viewer(['reports.financial']);
        $this->getJson('/api/v1/admin/reports/drill/expenses')->assertOk();
    }

    public function test_unknown_metric_is_rejected(): void
    {
        Outlet::factory()->create();
        $this->viewer();
        $this->getJson('/api/v1/admin/reports/drill/nonsense')->assertStatus(422);
    }

    public function test_sales_summary_now_speaks_sales_truth_with_collected_beside_it(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        $part = $this->order($outlet, ['total_amount' => 10000, 'payment_status' => 'partial']);
        $this->paid($part, 4000);
        $this->order($outlet, ['total_amount' => 50000, 'status' => 'voided']);

        $res = $this->getJson('/api/v1/admin/reports/sales/summary')->assertOk();

        // The part-paid order now counts in revenue; the voided one never does.
        $this->assertSame(10000.0, (float) $res->json('summary.total_revenue'));
        // And money truth sits beside it, clearly labelled.
        $this->assertSame(4000.0, (float) $res->json('summary.total_collected'));
    }
}
