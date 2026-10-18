<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Currency plan Step 5 (owner, 2026-10-18): every other screen, email,
 * report and label on the money rules. Figures by hand; USD reporting 128.
 *
 * A KES 1,500 till sale and a USD 40 till sale on the same day, a pending
 * cart of 9,999, a cancelled 7,777: Sales = 1,500 + 5,120 = 6,620, never
 * 1,540 (raw) nor 18,419 (carts in).
 */
class MoneyRulesEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();
        CurrencyPricing::forget();

        $this->outlet = Outlet::factory()->create();
        $this->owner  = User::factory()->create(['status' => 'active']);
        $this->owner->assignRole(Role::findByName('super_admin', 'sanctum'));
        $this->owner->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->owner->fresh());
    }

    private function sale(float $amount, string $currency, array $attrs = []): Order
    {
        return Order::factory()->create($attrs + [
            'order_type' => 'pos', 'outlet_id' => $this->outlet->id, 'created_by' => $this->owner->id,
            'status' => 'confirmed', 'payment_status' => 'paid', 'currency_code' => $currency,
            'total_amount' => $amount, 'subtotal' => $amount, 'payment_method' => 'cash', 'created_by' => $this->owner->id,
        ]);
    }

    private function pay(Order $o, float $amount, string $method = 'cash', float $refund = 0): Payment
    {
        return Payment::factory()->create(['order_id' => $o->id, 'amount' => $amount, 'refund_amount' => $refund,
            'payment_method' => $method, 'currency_code' => $o->currency_code, 'status' => 'paid', 'paid_at' => now()]);
    }

    /** The day's book: two sales in two currencies, a cart, a cancellation. */
    private function day(): array
    {
        $kes = $this->sale(1500, 'KES');
        $usd = $this->sale(40, 'USD');
        $this->sale(9999, 'KES', ['status' => 'pending', 'payment_status' => 'pending']);
        $this->sale(7777, 'KES', ['status' => 'cancelled', 'payment_status' => 'pending']);
        $this->pay($kes, 1500);
        $this->pay($usd, 40);

        return [$kes, $usd];
    }

    // ── POS ──────────────────────────────────────────────────────────────────

    public function test_the_pos_daily_summary_keeps_each_currency_apart_with_a_shilling_total(): void
    {
        [$kes, $usd] = $this->day();
        $s = $this->getJson('/api/v1/admin/pos/reports/daily?outlet_id=' . $this->outlet->id)->assertOk()->json('summary');

        $this->assertEquals(6620.0, $s['total_sales']);
        $this->assertSame(2, $s['total_transactions']);
        $this->assertEquals(6620.0, $s['cash_sales']);
        $by = collect($s['by_currency'])->keyBy('currency_code');
        $this->assertEquals(1500, $by['KES']['amount']);
        $this->assertEquals(40, $by['USD']['amount']);
        $this->assertEquals(5120, $by['USD']['amount_kes']);
        $this->assertSame([], $s['unconverted']);
        $this->assertEquals(3310.0, $s['average_transaction']);
    }

    public function test_the_end_of_day_reports_state_each_cashier_per_currency(): void
    {
        [$kes, $usd] = $this->day();
        $register = \App\Models\CashRegister::create(['outlet_id' => $this->outlet->id, 'register_name' => 'Till 1', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 1000, 'opened_by' => $this->owner->id, 'opened_at' => now()]);
        DB::table('cash_register_eod_reports')->insert([
            'register_id' => $register->id, 'user_id' => $this->owner->id, 'outlet_id' => $this->outlet->id,
            'report_date' => today(), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = collect($this->getJson('/api/v1/admin/pos/reports/eod-admin')->assertOk()->json('data'))->first();
        $this->assertEquals(6620.0, $row['total_sales']);
        $this->assertEquals(6620.0, $row['total_paid']);
        $this->assertEquals(0.0, $row['total_balance']);
        $this->assertSame('USD', collect($row['sales_by_currency'])->firstWhere('currency_code', 'USD')['currency_code']);
    }

    // ── payments ─────────────────────────────────────────────────────────────

    public function test_payment_analytics_state_every_figure_in_shillings_when_no_currency_is_asked_for(): void
    {
        [$kes, $usd] = $this->day();
        $this->pay($kes, 200, 'mpesa', 50);     // refund 50 on this one
        $a = $this->getJson('/api/v1/admin/payment-transactions/analytics')->assertOk()->json();
        $k = $a['kpis'] ?? $a;
        // 1,500 + 5,120 + 200 = 6,820 paid; refunded 50
        $this->assertEquals(6820.0, (float) ($k['paid_volume'] ?? $k['total_volume']));
        $this->assertEquals(50.0, (float) $k['refunded_volume']);
    }

    public function test_the_daily_cash_report_keeps_currencies_apart(): void
    {
        [$kes, $usd] = $this->day();
        $r = $this->getJson('/api/v1/admin/payments/cash-report?start_date=' . today()->toDateString() . '&end_date=' . today()->toDateString())
            ->assertOk()->json('summary');
        $this->assertEquals(6620.0, $r['total_cash_collected']);
        $this->assertSame('KES', $r['currency_code']);
        $by = collect($r['by_currency'])->keyBy('currency_code');
        $this->assertEquals(1500, $by['KES']['amount']);
        $this->assertEquals(40, $by['USD']['amount']);
    }

    // ── customers ────────────────────────────────────────────────────────────

    public function test_a_customers_spend_is_recognised_sales_in_shillings_with_the_native_figures_beside_it(): void
    {
        $customerId = DB::table('customers')->insertGetId(['customer_number' => 'C-1', 'first_name' => 'Ann', 'last_name' => 'Mwangi',
            'email' => 'ann@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->sale(1500, 'KES', ['customer_id' => $customerId]);
        $this->sale(40, 'USD', ['customer_id' => $customerId]);
        $this->sale(9999, 'KES', ['customer_id' => $customerId, 'status' => 'pending', 'payment_status' => 'pending']);

        $stats = $this->getJson("/api/v1/admin/customers/{$customerId}")->assertOk()->json('stats');
        $this->assertEquals(6620.0, $stats['total_spent']);
        $this->assertEquals(3310.0, $stats['average_order_value']);
        $this->assertSame(2, $stats['recognised_orders']);
        $this->assertEquals(40, collect($stats['spent_by_currency'])->firstWhere('currency_code', 'USD')['amount']);
    }

    // ── reports in a chosen currency ─────────────────────────────────────────

    public function test_the_profit_and_loss_refuses_to_set_dollar_revenue_against_shilling_costs(): void
    {
        $window = 'start_date=2020-01-01&end_date=2030-12-31';
        $this->getJson("/api/v1/admin/reports/financial/profit-loss?{$window}&currency_code=USD")
            ->assertStatus(422)->assertJsonPath('code', 'KES_ONLY_REPORT');
        $this->getJson("/api/v1/admin/reports/financial/profit-loss?{$window}")->assertOk();
    }

    public function test_the_expenses_report_total_is_the_sum_of_its_rows(): void
    {
        $cat = DB::table('expense_categories')->insertGetId(['name' => 'Fuel', 'code' => 'FUEL', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1000, 250.5] as $i => $amt) {
            DB::table('expenses')->insert(['reference_number' => 'EXP-' . ($i + 1), 'title' => 'Diesel', 'category_id' => $cat, 'amount' => $amt, 'amount_kes' => $amt, 'currency_code' => 'KES', 'payment_method' => 'cash', 'created_by' => $this->owner->id,
                'exchange_rate' => 1, 'expense_date' => today(), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        }
        $r = $this->getJson('/api/v1/admin/reports/financial/expenses?start_date=' . today()->subDay()->toDateString() . '&end_date=' . today()->addDay()->toDateString())->assertOk();
        $this->assertEquals(1250.5, (float) $r->json('total'), 'it read 0 on every period');
    }

    // ── statistics that read columns that did not exist ─────────────────────

    public function test_outlet_and_user_statistics_are_recognised_till_sales_in_shillings(): void
    {
        [$kes, $usd] = $this->day();
        $o = $this->getJson("/api/v1/admin/outlets/{$this->outlet->id}/statistics")->assertOk()->json();
        $stats = $o['statistics'] ?? $o['stats'] ?? $o;
        $this->assertSame(2, $stats['today_sales_count']);
        $this->assertEquals(6620.0, $stats['today_sales_total']);
        $this->assertEquals(6620.0, $stats['month_sales_total']);

        // The person's own till figures appear for a clerk or outlet manager.
        $this->owner->assignRole(Role::findByName('pos_clerk', 'sanctum'));
        $ustats = $this->getJson("/api/v1/admin/users/{$this->owner->id}")->assertOk()->json('stats');
        $this->assertSame(2, $ustats['sales_count']);
        $this->assertEquals(6620.0, $ustats['sales_total']);
        $this->assertSame(2, $ustats['today_sales']); // a count, as before
    }
}
