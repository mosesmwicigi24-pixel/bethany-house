<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Currency plan Step 4 (owner, 2026-10-18): the Home dashboard on the money
 * rules. Figures by hand; USD reporting rate 128.
 *
 *   Sales today      = confirmed-or-paid orders made today (Nairobi), KES at
 *                      each order's own rate: KES 1,500 + USD 40 (5,120) = 6,620.
 *                      A pending unpaid cart (9,999), yesterday's order and a
 *                      cancelled one are not in it.
 *   Collected today  = settled money that arrived today, net of refunds:
 *                      cash 1,000 − 200 refund + M-Pesa 500 + USD cash 10 (1,280) = 2,580.
 *   Cash today       = KES 800 and USD 10 apart; KES 2,080 together.
 */
class DashboardMoneyRulesTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function owner(): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->assignRole(Role::findByName('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u->fresh());

        return $u;
    }

    private function order(array $attrs): Order
    {
        return Order::factory()->create($attrs + ['status' => 'confirmed', 'payment_status' => 'pending', 'currency_code' => 'KES']);
    }

    private function pay(Order $o, float $amount, string $method, string $currency = 'KES', float $refund = 0): Payment
    {
        return Payment::factory()->create([
            'order_id' => $o->id, 'amount' => $amount, 'refund_amount' => $refund, 'payment_method' => $method,
            'currency_code' => $currency, 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    public function test_the_home_figures_follow_the_sales_and_money_rules(): void
    {
        $this->owner();
        $kes  = $this->order(['total_amount' => 1500]);
        $usd  = $this->order(['total_amount' => 40, 'currency_code' => 'USD']);
        $cart = $this->order(['total_amount' => 9999, 'status' => 'pending']);                    // a cart: not a sale
        $this->order(['total_amount' => 7777, 'status' => 'cancelled']);
        $this->order(['total_amount' => 5555, 'created_at' => now()->subDays(2)]);                // yesterday's business
        $this->order(['total_amount' => 300, 'status' => 'processing']);
        $old = $this->order(['total_amount' => 2000, 'created_at' => now()->subDays(10)]);       // balance paid today

        $this->pay($kes, 1000, 'cash', 'KES', 200);
        $this->pay($kes, 500, 'mpesa');
        $this->pay($usd, 10, 'cash', 'USD');
        $this->pay($old, 2000, 'bank_transfer');                                                   // counts today, in Collected
        foreach ([1, 2, 3] as $i) {
            DB::table('customers')->insert(['customer_number' => "C-{$i}", 'first_name' => 'Ann', 'last_name' => "Mwangi {$i}",
                'email' => "ann{$i}@example.test", 'created_at' => now(), 'updated_at' => now()]);
        }

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');

        $this->assertSame(now(config('app.timezone'))->toDateString(), $stats['business_date']);
        // Sales: 1,500 + 40×128 + 300 (processing is confirmed work) — never the cart, the cancelled, or yesterday's.
        $this->assertEquals(6920.0, $stats['today_sales']);
        $this->assertSame(3, $stats['today_orders']);
        $by = collect($stats['today_sales_by_currency'])->keyBy('currency_code');
        $this->assertEquals(1800, $by['KES']['amount']);
        $this->assertEquals(40, $by['USD']['amount']);
        $this->assertEquals(5120, $by['USD']['amount_kes']);
        // Carts waiting vs work in progress.
        $this->assertSame(1, $stats['carts_waiting']);
        $this->assertSame(5, $stats['in_progress_orders'], 'confirmed/processing orders of any age: kes, usd, yesterday, processing, old — not the cart or the cancelled');
        // Collected: 800 + 500 + 1,280 + 2,000 — the old order's balance shows today.
        $this->assertEquals(4580.0, $stats['today_collected']);
        // Cash, per currency, net of the refund.
        $this->assertEquals(2080.0, $stats['cash_today']['kes_total']);
        $cash = collect($stats['cash_today']['by_currency'])->keyBy('currency_code');
        $this->assertEquals(800, $cash['KES']['amount']);
        $this->assertEquals(10, $cash['USD']['amount']);
        $this->assertEquals(1280, $cash['USD']['amount_kes']);
        $this->assertSame([], $stats['cash_today']['unconverted']);
        // Customers: the list.
        $this->assertSame(3, $stats['customers']);
        $this->assertSame(0, $stats['open_pos']['count']);
        $this->assertTrue($cart->fresh()->status === 'pending');
    }

    public function test_the_business_date_is_midnight_in_nairobi(): void
    {
        // 21:30 UTC on the 17th is 00:30 on the 18th in Nairobi: an order
        // made then is the 18th's business, whatever the phone's clock says.
        Carbon::setTestNow(Carbon::parse('2026-10-17 21:30:00', 'UTC'));
        $this->owner();
        $this->order(['total_amount' => 100]);
        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');
        $this->assertSame('2026-10-18', $stats['business_date']);
        $this->assertSame(1, $stats['today_orders']);
        $this->assertEquals(100.0, $stats['today_sales']);
        Carbon::setTestNow();
    }

    public function test_a_sale_in_a_currency_with_no_rate_is_counted_but_not_added_to_shillings(): void
    {
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], ['name' => 'Pound', 'symbol' => '£',
            'exchange_rate' => 0.0065, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();
        $this->owner();
        $this->order(['total_amount' => 1000]);
        $this->order(['total_amount' => 50, 'currency_code' => 'GBP']);

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');
        $this->assertEquals(1000.0, $stats['today_sales']);
        $this->assertSame(2, $stats['today_orders']);
        $gbp = collect($stats['today_sales_by_currency'])->firstWhere('currency_code', 'GBP');
        $this->assertEquals(50, $gbp['amount']);
        $this->assertNull($gbp['amount_kes']);
    }
}
