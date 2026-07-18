<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 5 — customers & financial statements.
 *
 * Customer identity is keyed on customer_id when present, else the FULL
 * phone string (live POS orders carry only the snapshot); the P&L follows
 * the earned-revenue rule — an order counts when its final settling payment
 * lands, not when it was rung up.
 */
class CustomerFinancialIntelligenceTest extends TestCase
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

    private function phoneOrder(Outlet $outlet, string $phone, float $total, $when = null, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type' => 'pos', 'outlet_id' => $outlet->id,
            'status' => 'confirmed', 'payment_status' => 'partial',
            'total_amount' => $total, 'currency_code' => 'KES',
            'customer_id' => null, 'customer_phone' => $phone,
            'customer_first_name' => 'Cust', 'customer_last_name' => substr($phone, -4),
            'created_at' => $when ?? now(),
        ], $attrs));
    }

    public function test_segments_key_on_full_phone_and_report_walk_ins_honestly(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        DB::table('customers')->insert([
            'customer_number' => 'CUST-P5-1', 'first_name' => 'St', 'last_name' => 'Marys',
            'email' => 'stmarys@example.test',
            'phone' => '+254700000001', 'customer_type' => 'church', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->phoneOrder($outlet, '+254700000001', 50000);
        Order::factory()->create([
            'order_type' => 'pos', 'outlet_id' => $outlet->id, 'status' => 'confirmed',
            'total_amount' => 3000, 'currency_code' => 'KES',
            'customer_id' => null, 'customer_phone' => null,
        ]);

        $res = $this->getJson('/api/v1/admin/reports/customer-intelligence?period=this_month')->assertOk();
        $segments = collect($res->json('segments'))->keyBy('segment');

        $this->assertSame(50000.0, (float) $segments['church']['revenue']);
        $this->assertSame(3000.0, (float) $segments['walk_in']['revenue']);
    }

    public function test_new_vs_returning_uses_history_before_the_window(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        // Returning: ordered last month AND this month. New: first order this month.
        $this->phoneOrder($outlet, '+254711000001', 4000, now()->subMonthNoOverflow());
        $this->phoneOrder($outlet, '+254711000001', 6000);
        $this->phoneOrder($outlet, '+254711000002', 2500);

        $res = $this->getJson('/api/v1/admin/reports/customer-intelligence?period=this_month')->assertOk();

        $this->assertSame(6000.0, (float) $res->json('new_vs_returning.returning.revenue'));
        $this->assertSame(2500.0, (float) $res->json('new_vs_returning.new.revenue'));
        $this->assertSame(1, $res->json('new_vs_returning.returning.customers'));
    }

    public function test_dormant_top_customers_reach_the_attention_feed(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer();

        // Big spender, quiet for 90 days; small recent buyer stays off the list.
        $this->phoneOrder($outlet, '+254722000001', 120000, now()->subDays(90));
        $this->phoneOrder($outlet, '+254722000002', 1000, now()->subDays(2));

        $res = $this->getJson('/api/v1/admin/reports/customer-intelligence')->assertOk();
        $dormant = collect($res->json('dormant'));

        $this->assertSame(1, $dormant->count());
        $this->assertSame('+254722000001', $dormant->first()['phone']);
        $this->assertSame(90, $dormant->first()['days_quiet']);

        $exec = $this->getJson('/api/v1/admin/reports/executive')->assertOk();
        $item = collect($exec->json('attention'))->firstWhere('key', 'dormant_customers');
        $this->assertNotNull($item);
        $this->assertSame(1, $item['count']);
    }

    public function test_earned_pnl_counts_revenue_when_the_final_payment_lands(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer(['reports.financial']);

        $product = Product::factory()->create();
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'currency_code' => 'KES',
            'regular_price' => 5000, 'cost_price' => 2000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Rung up LAST month, deposit then, balance settled THIS month → earned now.
        $earned = $this->phoneOrder($outlet, '+254733000001', 10000, now()->subMonthNoOverflow(), ['payment_status' => 'paid']);
        DB::table('order_items')->insert([
            'order_id' => $earned->id, 'product_id' => $product->id, 'sku' => 'SKU-PNL',
            'product_name' => 'Cassock', 'quantity' => 2, 'unit_price' => 5000, 'total_price' => 10000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Payment::create(['order_id' => $earned->id, 'amount' => 4000, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()->subMonthNoOverflow()]);
        Payment::create(['order_id' => $earned->id, 'amount' => 6000, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()]);

        // Still part-paid → not earned, whatever the period.
        $open = $this->phoneOrder($outlet, '+254733000002', 8000);
        Payment::create(['order_id' => $open->id, 'amount' => 3000, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()]);

        $categoryId = DB::table('expense_categories')->insertGetId([
            'name' => 'Rent & Utilities', 'code' => 'RENT', 'budget_monthly' => 5000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('expenses')->insert([
            'reference_number' => 'EXP-P5-1', 'title' => 'Rent', 'category_id' => $categoryId,
            'amount' => 1500, 'amount_kes' => 1500,
            'currency_code' => 'KES', 'expense_date' => now()->format('Y-m-d'), 'status' => 'completed',
            'payment_method' => 'cash',
            'outlet_id' => $outlet->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/financial-intelligence?period=this_month')->assertOk();

        $this->assertSame(1, $res->json('pnl.earned_orders'));
        $this->assertSame(10000.0, (float) $res->json('pnl.earned_revenue'));
        // COGS: 2 pieces × 2,000 cost = 4,000 → gross 6,000 → net 4,500 after rent.
        $this->assertSame(4000.0, (float) $res->json('pnl.cogs_estimate'));
        $this->assertSame(6000.0, (float) $res->json('pnl.gross_profit'));
        $this->assertSame(4500.0, (float) $res->json('pnl.net_profit'));
        $this->assertSame(60.0, (float) $res->json('pnl.gross_margin_pct'));
    }

    public function test_method_reconciliation_nets_refunds_per_rail(): void
    {
        $outlet = Outlet::factory()->create();
        $this->viewer(['reports.financial']);

        $o = $this->phoneOrder($outlet, '+254744000001', 20000);
        Payment::create(['order_id' => $o->id, 'amount' => 12000, 'currency_code' => 'KES',
            'payment_method' => 'mpesa', 'status' => 'paid', 'paid_at' => now(), 'refund_amount' => 2000]);
        Payment::create(['order_id' => $o->id, 'amount' => 8000, 'currency_code' => 'KES',
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()]);

        $res = $this->getJson('/api/v1/admin/reports/financial-intelligence')->assertOk();
        $rails = collect($res->json('rails'))->keyBy('method');

        $this->assertSame(12000.0, (float) $rails['mpesa']['gross']);
        $this->assertSame(2000.0, (float) $rails['mpesa']['refunds']);
        $this->assertSame(10000.0, (float) $rails['mpesa']['net']);
        $this->assertSame(8000.0, (float) $rails['cash']['net']);
    }

    public function test_financial_intelligence_requires_reports_financial(): void
    {
        Outlet::factory()->create();
        $this->viewer();
        $this->getJson('/api/v1/admin/reports/financial-intelligence')->assertStatus(403);

        // Customer intelligence needs only reports.view.
        $this->getJson('/api/v1/admin/reports/customer-intelligence')->assertOk();
    }
}
