<?php

namespace Tests\Feature;

use App\Models\CashRegister;
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
 * The end-of-day report's detail (finding F4, 2026-11-04) states the
 * cashier's day by the same rules as the list, the email and the Slack
 * digest: sales are confirmed or paid orders, paid is settled money net of
 * refunds, and each currency is converted at its order's reporting rate.
 *
 * The day, by hand (USD reporting rate 128):
 *   A  KES 1,500 confirmed, paid 1,500                → paid 1,500, balance 0
 *   B  USD 40    confirmed, paid 40                   → KES 5,120, balance 0
 *   C  KES 2,000 confirmed, paid 2,000, refunded 500  → paid 1,500, balance 500
 *   D  KES 1,000 confirmed, 1,000 awaiting approval   → paid 0, balance 1,000
 *   E  KES 9,999 cart (pending)                       → listed, not a sale
 *   F  KES 7,777 cancelled                            → not listed
 * Sales   = 1,500 + 5,120 + 2,000 + 1,000 = 9,620   (the old raw sum, cart and dollars in: 14,539)
 * Paid    = 1,500 + 5,120 + 1,500         = 8,120
 * Balance = 500 + 1,000                   = 1,500
 */
class EodReportDetailTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $owner;
    private int $reportId;
    private array $orders = [];

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

        $o = fn (string $key, float $amount, string $cur, string $status = 'confirmed', string $pay = 'paid') =>
            $this->orders[$key] = Order::factory()->create([
                'order_type' => 'pos', 'outlet_id' => $this->outlet->id, 'created_by' => $this->owner->id,
                'status' => $status, 'payment_status' => $pay, 'currency_code' => $cur,
                'total_amount' => $amount, 'subtotal' => $amount, 'payment_method' => 'cash',
            ]);
        $p = fn (string $key, float $amount, array $attrs = []) => Payment::factory()->create($attrs + [
            'order_id' => $this->orders[$key]->id, 'amount' => $amount, 'refund_amount' => 0,
            'payment_method' => 'cash', 'currency_code' => $this->orders[$key]->currency_code,
            'status' => 'paid', 'paid_at' => now(),
        ]);

        $o('A', 1500, 'KES');           $p('A', 1500);
        \App\Models\OrderItem::create(['order_id' => $this->orders['A']->id, 'product_id' => null, 'sku' => 'COPE-1',
            'product_name' => 'Cope', 'quantity' => 1, 'unit_price' => 1500, 'total_price' => 1500]);
        $o('B', 40, 'USD');             $p('B', 40);
        $o('C', 2000, 'KES');           $p('C', 2000, ['refund_amount' => 500]);
        $o('D', 1000, 'KES', 'confirmed', 'partial');
        $p('D', 1000, ['payment_method' => 'bank_transfer', 'requires_approval' => true, 'approval_status' => 'pending_review']);
        $o('E', 9999, 'KES', 'pending', 'pending');
        $o('F', 7777, 'KES', 'cancelled', 'pending');

        $register = CashRegister::create(['outlet_id' => $this->outlet->id, 'register_name' => 'Till 1', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 0, 'expected_cash' => 0, 'opened_by' => $this->owner->id, 'opened_at' => now()]);
        $this->reportId = DB::table('cash_register_eod_reports')->insertGetId([
            'register_id' => $register->id, 'user_id' => $this->owner->id, 'outlet_id' => $this->outlet->id,
            'report_date' => today(), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            'order_notes' => json_encode([(string) $this->orders['E']->id => 'Customer coming back tomorrow']),
        ]);
    }

    private function detail(): array
    {
        return $this->getJson("/api/v1/admin/pos/reports/eod-admin/{$this->reportId}")->assertOk()->json('report');
    }

    public function test_the_detail_states_the_day_in_shillings_at_each_orders_reporting_rate(): void
    {
        $r = $this->detail();

        $this->assertEquals(9620.0, $r['total_sales'], 'never the raw 4,540 nor with the cart');
        $this->assertEquals(8120.0, $r['total_paid'], 'settled money net of refunds; the payment awaiting approval is not paid');
        $this->assertEquals(1500.0, $r['total_balance']);
        $this->assertSame('KES at the reporting rate', $r['money_basis']);
        $this->assertSame(4, $r['order_count'], 'sales only');

        $sales = collect($r['sales_by_currency'])->keyBy('currency_code');
        $this->assertEquals(4500, $sales['KES']['amount']);
        $this->assertEquals(40, $sales['USD']['amount']);
        $this->assertEquals(5120, $sales['USD']['amount_kes']);
        $paid = collect($r['paid_by_currency'])->keyBy('currency_code');
        $this->assertEquals(3000, $paid['KES']['amount']);
        $this->assertEquals(40, $paid['USD']['amount']);
    }

    public function test_each_order_carries_its_own_currency_and_a_cart_is_listed_but_not_counted(): void
    {
        $rows = collect($this->detail()['orders'])->keyBy('id');

        $this->assertFalse($rows->has($this->orders['F']->id), 'a cancelled order is not on the day');
        $this->assertCount(5, $rows);

        $b = $rows[$this->orders['B']->id];
        $this->assertSame('USD', $b['currency_code']);
        $this->assertEquals(40, $b['total_amount']);
        $this->assertTrue($b['counts_as_sale']);

        $c = $rows[$this->orders['C']->id];
        $this->assertEquals(1500, $c['amount_paid']);
        $this->assertEquals(500, $c['balance']);

        $d = $rows[$this->orders['D']->id];
        $this->assertEquals(0, $d['amount_paid'], 'awaiting approval is not money in');
        $this->assertEquals(1000, $d['balance']);

        $e = $rows[$this->orders['E']->id];
        $this->assertFalse($e['counts_as_sale']);
        $this->assertSame('Customer coming back tomorrow', $e['eod_note'], 'the cashier\'s note on a cart stays visible');
    }

    public function test_the_detail_and_the_list_agree_for_the_same_report(): void
    {
        $r   = $this->detail();
        $row = collect($this->getJson('/api/v1/admin/pos/reports/eod-admin')->assertOk()->json('data'))->firstWhere('id', $this->reportId);

        foreach (['total_sales', 'total_paid', 'total_balance', 'order_count', 'sales_by_currency', 'paid_by_currency', 'balance_by_currency', 'money_basis'] as $k) {
            $this->assertEquals($row[$k], $r[$k], "list and detail differ on {$k}");
        }
    }

    /** The cashier's own screen (F5): what she submits is what her supervisor reviews. */
    public function test_the_cashiers_own_screen_states_the_same_day_as_her_supervisor_sees(): void
    {
        $mine = $this->getJson('/api/v1/admin/pos/reports/user-eod?outlet_id=' . $this->outlet->id . '&date=' . today()->toDateString())
            ->assertOk()->json('summary');
        $r = $this->detail();

        $this->assertEquals(9620.0, $mine['total_sales']);
        $this->assertEquals(8120.0, $mine['total_paid']);
        $this->assertEquals(1500.0, $mine['total_balance']);
        foreach (['total_sales', 'total_paid', 'total_balance', 'order_count', 'sales_by_currency', 'paid_by_currency', 'balance_by_currency', 'money_basis'] as $k) {
            $this->assertEquals($r[$k], $mine[$k], "the cashier's screen and the detail differ on {$k}");
        }

        $rows = collect($mine['orders'])->keyBy('id');
        $this->assertCount(5, $rows);
        $this->assertSame('USD', $rows[$this->orders['B']->id]['currency_code']);
        $this->assertFalse($rows[$this->orders['E']->id]['counts_as_sale']);
        $this->assertEquals(0, $rows[$this->orders['D']->id]['amount_paid']);
        $this->assertNotEmpty($rows[$this->orders['A']->id]['items'], 'the lines still come with each order');
    }

    public function test_an_empty_day_is_zero_in_every_currency(): void
    {
        DB::table('payments')->delete();
        Order::query()->delete();

        $r = $this->detail();
        $this->assertEquals(0.0, $r['total_sales']);
        $this->assertEquals(0.0, $r['total_paid']);
        $this->assertEquals(0.0, $r['total_balance']);
        $this->assertSame(0, $r['order_count']);
        $this->assertSame([], $r['orders']);
    }
}
