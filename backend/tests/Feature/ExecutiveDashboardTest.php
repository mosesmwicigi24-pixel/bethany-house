<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Executive Dashboard implements docs/REPORTS_SPEC.md, and these tests
 * pin its two load-bearing promises:
 *
 *  1. TRUTHS DON'T MIX. Revenue is sales truth (a part-paid order counts in
 *     full; a voided one not at all). Collected is money truth (only settled
 *     payments, net of refunds). The July duplicate-payment incident is the
 *     standing reason this distinction is tested, not assumed.
 *  2. SCOPE IS THE QUERY. reports.view opens the door, reports.financial
 *     opens the CFO block, and an outlet-assigned user's numbers are
 *     filtered to their outlets server-side.
 */
class ExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function reportViewer(array $extraPerms = [], ?Outlet $assignedOutlet = null): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_manager', 'sanctum'));
        foreach (array_merge(['reports.view'], $extraPerms) as $perm) {
            $user->givePermissionTo(Permission::findOrCreate($perm, 'sanctum'));
        }
        if ($assignedOutlet) {
            $user->outlets()->attach($assignedOutlet->id);
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

    private function paidPayment(Order $order, float $amount, array $attrs = []): Payment
    {
        return Payment::create(array_merge([
            'order_id'       => $order->id,
            'amount'         => $amount,
            'currency_code'  => 'KES',
            'payment_method' => 'cash',
            'status'         => 'paid',
            'paid_at'        => now(),
        ], $attrs));
    }

    public function test_revenue_is_sales_truth_and_collected_is_money_truth(): void
    {
        $outlet = Outlet::factory()->create();
        $this->reportViewer();

        // A part-paid order: full total in revenue, only the payment in collected.
        $partPaid = $this->order($outlet, ['total_amount' => 10000, 'payment_status' => 'partial']);
        $this->paidPayment($partPaid, 4000);

        // A refunded payment: collected is net.
        $refunded = $this->order($outlet, ['total_amount' => 5000, 'payment_status' => 'paid', 'status' => 'confirmed']);
        $this->paidPayment($refunded, 5000, ['refund_amount' => 2000]);

        // A voided order with a voided payment: in neither truth.
        $voided = $this->order($outlet, ['total_amount' => 99000, 'status' => 'voided']);
        Payment::create([
            'order_id' => $voided->id, 'amount' => 99000, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'status' => 'voided',
        ]);

        // A pending-approval tender: money that is claimed, not collected.
        $pending = $this->order($outlet, ['total_amount' => 7000]);
        Payment::create([
            'order_id' => $pending->id, 'amount' => 7000, 'currency_code' => 'KES',
            'payment_method' => 'bank_transfer', 'status' => 'pending',
            'requires_approval' => true, 'approval_status' => 'pending_review',
        ]);

        $res = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();

        // Sales truth: 10,000 + 5,000 + 7,000 (voided 99,000 excluded).
        $this->assertSame(22000.0, (float) $res->json('kpis.sales.revenue.current'));
        $this->assertSame(3.0, (float) $res->json('kpis.sales.orders.current'));

        // Money truth: 4,000 + (5,000 − 2,000). Pending tender excluded.
        $this->assertSame(7000.0, (float) $res->json('kpis.money.collected.current'));

        // Outstanding: 6,000 on the part-paid + 7,000 unconfirmed = 13,000.
        $this->assertSame(13000.0, (float) $res->json('kpis.money.outstanding.amount'));

        // The pending tender surfaces on the attention feed with its amount.
        $keys = collect($res->json('attention'))->pluck('key');
        $this->assertTrue($keys->contains('payment_approvals'));
    }

    public function test_previous_period_comparison_is_the_equivalent_window(): void
    {
        $outlet = Outlet::factory()->create();
        $this->reportViewer();

        $this->order($outlet, ['total_amount' => 8000, 'created_at' => now()]);
        $this->order($outlet, ['total_amount' => 3000, 'created_at' => now()->subMonthNoOverflow()]);

        $res = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();

        $this->assertSame(8000.0, (float) $res->json('kpis.sales.revenue.current'));
        $this->assertSame(3000.0, (float) $res->json('kpis.sales.revenue.previous'));
    }

    public function test_production_on_time_and_overdue_detection(): void
    {
        $outlet = Outlet::factory()->create();
        $this->reportViewer();
        $product = \App\Models\Product::factory()->create();

        // Completed on time, completed late, and one overdue on the floor.
        ProductionOrder::create([
            'order_number' => 'PRD-EXEC-1', 'product_id' => $product->id, 'quantity' => 1,
            'status' => 'completed', 'due_date' => now()->addDay(), 'completed_at' => now(),
        ]);
        ProductionOrder::create([
            'order_number' => 'PRD-EXEC-2', 'product_id' => $product->id, 'quantity' => 1,
            'status' => 'completed', 'due_date' => now()->subDays(3), 'completed_at' => now(),
        ]);
        ProductionOrder::create([
            'order_number' => 'PRD-EXEC-3', 'product_id' => $product->id, 'quantity' => 1,
            'status' => 'in_progress', 'due_date' => now()->subDays(5),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();

        $this->assertSame(2.0, (float) $res->json('kpis.production.completed.current'));
        $this->assertSame(50.0, (float) $res->json('kpis.production.on_time_pct.current'));
        $this->assertSame(1, $res->json('kpis.production.overdue'));

        $overdueItem = collect($res->json('attention'))->firstWhere('key', 'production_overdue');
        $this->assertNotNull($overdueItem);
        $this->assertSame(1, $overdueItem['count']);
    }

    public function test_financial_block_requires_reports_financial(): void
    {
        Outlet::factory()->create();
        $this->reportViewer();

        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $this->assertNull($res->json('kpis.financial'));

        $this->reportViewer(['reports.financial']);
        $res = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $this->assertNotNull($res->json('kpis.financial'));
    }

    public function test_outlet_assigned_user_sees_only_their_outlet(): void
    {
        $mine   = Outlet::factory()->create();
        $others = Outlet::factory()->create();

        $this->order($mine,   ['total_amount' => 4000]);
        $this->order($others, ['total_amount' => 60000]);

        $this->reportViewer([], $mine);
        $res = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();
        $this->assertSame(4000.0, (float) $res->json('kpis.sales.revenue.current'));

        // And they cannot request their way into the other outlet.
        $this->getJson("/api/v1/admin/reports/executive?outlet_id={$others->id}")->assertStatus(403);
    }

    public function test_reports_view_is_the_front_door(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/reports/executive')->assertStatus(403);
    }
}
