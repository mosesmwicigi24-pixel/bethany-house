<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReportPdfController;
use App\Models\CashRegister;
use App\Models\CashRegisterBalance;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Services\Reporting\Reconciliation;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Currency plan Step 7 (owner, 2026-10-18): the books tie. Every figure is
 * worked by hand from the book below and set against (a) the independent
 * rebuild, (b) the reporting engine, (c) the dashboard, the sales report,
 * its CSV and its PDF — the same figure for the same question everywhere.
 * USD reporting rate 128; GBP has none.
 *
 * The book (all today, one outlet):
 *   till    KES 1,000 confirmed, paid in full (KES 1,000 cash)
 *   web     KES 2,000 confirmed, part-paid (KES 500)
 *   chat    USD 10    confirmed, paid in full (USD 10) → KES 1,280
 *   quoted  KES 4,000 confirmed, nothing paid
 *   cart    KES 9,999 pending, unpaid                      — not a sale
 *   dead    KES 700   cancelled, yet KES 700 settled on it — money on a dead order
 *   over    KES 300   till, paid, KES 350 settled          — overpaid 50
 *   person  KES 1,000 web, marked paid, KES 900 settled    — settled by a person, short 100
 *   GBP     GBP 100   confirmed                            — no rate: reported apart
 *
 *   Sales     = till 1,300 + web 3,000 + chat 1,280 + quoted 4,000 = 9,580
 *   Collected = 1,000 + 500 + 1,280 + 700 + 350 + 900              = 4,730
 *   Owed      = web 1,500 + quoted 4,000                            = 5,500
 *   Sales − Collected − Owed = −650 = person 100 − over 50 − dead 700
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE']);
        $this->owner  = User::factory()->create(['status' => 'active']);
        $this->owner->assignRole(Role::findByName('super_admin', 'sanctum'));
        $this->owner->outlets()->attach($this->outlet->id);
        \Tests\ReportAccess::grantPages($this->owner);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->owner->fresh());

        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], ['name' => 'Pound', 'symbol' => '£',
            'exchange_rate' => 0.0065, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    private function order(string $bucket, float $amount, string $currency, string $status, string $paymentStatus): Order
    {
        return Order::factory()->create([
            'outlet_id' => $this->outlet->id, 'created_by' => $this->owner->id, 'sales_bucket' => $bucket,
            'status' => $status, 'payment_status' => $paymentStatus, 'currency_code' => $currency,
            'subtotal' => $amount, 'total_amount' => $amount, 'order_type' => $bucket === 'till' ? 'pos' : 'online',
        ]);
    }

    private function pay(Order $o, float $amount): Payment
    {
        return Payment::factory()->create(['order_id' => $o->id, 'amount' => $amount, 'currency_code' => $o->currency_code,
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()]);
    }

    private function book(): void
    {
        $this->pay($this->order('till',   1000, 'KES', 'confirmed', 'paid'), 1000);
        $this->pay($this->order('web',    2000, 'KES', 'confirmed', 'partial'), 500);
        $this->pay($this->order('chat',   10,   'USD', 'confirmed', 'paid'), 10);
        $this->order('quoted', 4000, 'KES', 'confirmed', 'pending');
        $this->order('web',    9999, 'KES', 'pending',   'pending');
        $this->pay($this->order('till',   700,  'KES', 'cancelled', 'paid'), 700);
        $this->pay($this->order('till',   300,  'KES', 'confirmed', 'paid'), 350);
        $this->pay($this->order('web',    1000, 'KES', 'confirmed', 'paid'), 900);
        $this->order('web',    100,  'GBP', 'confirmed', 'pending');
    }

    private function window(): array
    {
        return [now()->subDay()->startOfDay(), now()->addDay()->endOfDay()];
    }

    // ── the identities, rebuilt from rows ────────────────────────────────────

    public function test_sales_is_the_sum_of_its_four_buckets_per_currency_and_in_kes(): void
    {
        $this->book();
        [$from, $to] = $this->window();
        $s = (new Reconciliation())->sales($from, $to);

        $this->assertEquals(9580.0, $s['kes_total']);
        $this->assertEquals(['till' => 1300.0, 'web' => 3000.0, 'chat' => 1280.0, 'quoted' => 4000.0], $s['bucket_kes']);
        $this->assertTrue($s['ties']);
        $this->assertSame(['GBP'], $s['unconverted'], 'no rate: reported apart, never added');
        $this->assertEquals(10.0, $s['by_currency']['USD']['native']);
        $this->assertEquals(1280.0, $s['by_currency']['USD']['kes']);
        $this->assertNull($s['by_currency']['GBP']['kes']);
        $this->assertSame(5, $s['by_currency']['KES']['orders'], 'till, web, quoted, over, person; the cart is not a sale, the cancelled order is dead, chat is USD');
    }

    public function test_collected_is_settled_money_net_of_refunds_by_payment_date(): void
    {
        $this->book();
        // A refund on the till sale, and a bank proof still awaiting approval: neither is collected money.
        Payment::where('amount', 1000)->update(['refund_amount' => 100]);
        Payment::factory()->create(['order_id' => Order::where('total_amount', 4000)->value('id'), 'amount' => 4000, 'currency_code' => 'KES',
            'payment_method' => 'bank_transfer', 'status' => 'paid', 'requires_approval' => true, 'approval_status' => 'pending', 'paid_at' => now()]);
        [$from, $to] = $this->window();
        $c = (new Reconciliation())->collected($from, $to);

        $this->assertEquals(4630.0, $c['kes_total'], '4,730 − the 100 refund; the unapproved 4,000 is not money yet');
        $this->assertEquals(100.0, $c['by_currency']['KES']['refunds']);
        $this->assertEquals(1280.0, $c['by_currency']['USD']['kes']);
    }

    public function test_owed_is_sales_minus_collected_with_every_gap_named(): void
    {
        $this->book();
        $o = (new Reconciliation())->owed();

        $this->assertEquals(9580.0, $o['sales_kes']);
        $this->assertEquals(4730.0, $o['collected_kes']);
        $this->assertEquals(5500.0, $o['owed_kes']);
        $this->assertEquals(-650.0, $o['gap_kes']);
        $this->assertEquals(100.0, $o['gaps']['settled_by_person']['kes']);
        $this->assertEquals(50.0, $o['gaps']['overpaid']['kes']);
        $this->assertEquals(700.0, $o['gaps']['money_on_dead']['kes']);
        $this->assertTrue($o['ties'], 'the gap is exactly the three named adjustments');
        $this->assertSame('cancelled', $o['gaps']['money_on_dead']['records'][0]['status']);
        $this->assertEquals(50.0, $o['gaps']['overpaid']['records'][0]['over_kes']);
    }

    public function test_the_rebuilt_figures_tie_to_the_reporting_engine(): void
    {
        $this->book();
        [$from, $to] = $this->window();
        $cmp = (new Reconciliation())->againstEngine($from, $to);

        $this->assertEquals(['independent' => 9580.0, 'engine' => 9580.0, 'ties' => true], $cmp['sales']);
        $this->assertEquals(['independent' => 4730.0, 'engine' => 4730.0, 'ties' => true], $cmp['collected']);
        $this->assertEquals(['independent' => 5500.0, 'engine' => 5500.0, 'ties' => true], $cmp['owed']);

        $scoped = (new Reconciliation([$this->outlet->id + 1000]))->againstEngine($from, $to);
        $this->assertEquals(0.0, $scoped['sales']['independent'], 'another outlet sees nothing');
        $this->assertTrue($scoped['sales']['ties']);
    }

    // ── the same figure everywhere ───────────────────────────────────────────

    public function test_dashboard_sales_report_csv_and_pdf_show_the_same_figure(): void
    {
        $this->book();
        $range = 'start=' . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString();

        $dash = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');
        $this->assertEquals(9580.0, $dash['today_sales']);
        $this->assertEquals(4730.0, $dash['today_collected']);

        $report = $this->getJson("/api/v1/admin/reports/sales/summary?{$range}")->assertOk()->json('summary');
        $this->assertEquals(9580.0, (float) $report['total_revenue']);
        $this->assertEquals([1300.0, 3000.0, 1280.0, 4000.0],
            [(float) $report['till_revenue'], (float) $report['web_revenue'], (float) $report['chat_revenue'], (float) $report['quoted_revenue']]);
        $this->assertEquals(4730.0, (float) $report['total_collected']);

        $csv = $this->get("/api/v1/admin/reports/sales/summary?{$range}&export=csv")->assertOk()->getContent();
        $lines = array_values(array_filter(array_map('trim', explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $csv)))));
        $head  = str_getcsv(array_shift($lines));
        $col   = array_search('Revenue', $head, true);
        $this->assertNotFalse($col, 'the CSV carries a Revenue column');
        $this->assertEquals(9580.0, round(array_sum(array_map(fn ($l) => (float) str_getcsv($l)[$col], $lines)), 2), 'the CSV rows add up to the page');

        $pdf = app(ReportPdfController::class)->salesData(Request::create('/pdf', 'GET', [
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
        ]));
        $this->assertEquals(9580.0, round((float) $pdf['summary']->total_revenue, 2), 'the PDF prints the page figure');
    }

    // ── tills ────────────────────────────────────────────────────────────────

    public function test_expected_till_cash_per_currency_ties_to_the_ledger_and_a_restatement_counts(): void
    {
        $mk = function (string $name, float $kesExpected) {
            $r = CashRegister::create(['outlet_id' => $this->outlet->id, 'register_name' => $name, 'status' => 'open', 'currency_code' => 'KES',
                'opening_balance' => 2000, 'expected_cash' => $kesExpected, 'opened_by' => $this->owner->id, 'opened_at' => now()]);
            foreach ([['KES', 1500], ['USD', 40]] as [$cur, $amt]) {
                DB::table('cash_register_transactions')->insert(['cash_register_id' => $r->id, 'transaction_type' => 'sale', 'payment_method' => 'cash',
                    'amount' => $amt, 'currency_code' => $cur, 'balance_after' => 0, 'created_at' => now()]);
            }
            CashRegisterBalance::create(['cash_register_id' => $r->id, 'currency_code' => 'KES', 'is_home' => true, 'opening_balance' => 2000, 'expected_cash' => $kesExpected]);
            CashRegisterBalance::create(['cash_register_id' => $r->id, 'currency_code' => 'USD', 'is_home' => false, 'opening_balance' => 0, 'expected_cash' => 40]);

            return $r;
        };
        $good = $mk('Good', 3500);
        $bad  = $mk('Bad', 3540);

        $rows = (new Reconciliation())->tills()->keyBy(fn ($t) => $t['register_id'] . $t['currency']);

        $this->assertTrue($rows[$good->id . 'KES']['ties']);
        $this->assertTrue($rows[$good->id . 'USD']['ties']);
        $this->assertEquals(40.0, $rows[$good->id . 'USD']['ledger_expected']);
        $this->assertFalse($rows[$bad->id . 'KES']['ties']);
        $this->assertEquals(40.0, $rows[$bad->id . 'KES']['difference'], 'USD 40 was added as shillings');

        DB::table('till_corrections')->insert(['cash_register_id' => $bad->id, 'currency_code' => 'KES', 'opened_by' => $this->owner->id,
            'reason' => 'History repair (currency plan Step 6): restated', 'original_expected_cash' => 3540, 'corrected_expected_cash' => 3500,
            'status' => 'recorded', 'created_at' => now(), 'updated_at' => now()]);
        $after = (new Reconciliation())->tills()->keyBy(fn ($t) => $t['register_id'] . $t['currency']);
        $this->assertTrue($after[$bad->id . 'KES']['ties'], 'the correction is the restatement');
        $this->assertTrue($after[$bad->id . 'KES']['restated']);
    }

    // ── the command ──────────────────────────────────────────────────────────

    public function test_the_command_says_everything_ties_and_fails_loudly_when_it_does_not(): void
    {
        $this->book();
        $args = ['--from' => now()->subDay()->toDateString(), '--to' => now()->addDay()->toDateString()];

        $this->assertSame(0, Artisan::call('reports:reconcile', $args));
        $out = Artisan::output();
        $this->assertStringContainsString('EVERYTHING TIES.', $out);
        $this->assertStringContainsString('till 1,300.00 + web 3,000.00 + chat 1,280.00 + quoted 4,000.00 = 9,580.00 TIES', $out);
        $this->assertStringContainsString('money_on_dead: 1 record(s), KES 700.00', $out);
        $this->assertStringContainsString('no reporting rate for GBP', $out);

        $r = CashRegister::create(['outlet_id' => $this->outlet->id, 'register_name' => 'Off', 'status' => 'open', 'currency_code' => 'KES',
            'opening_balance' => 100, 'expected_cash' => 150, 'opened_by' => $this->owner->id, 'opened_at' => now()]);
        CashRegisterBalance::create(['cash_register_id' => $r->id, 'currency_code' => 'KES', 'is_home' => true, 'opening_balance' => 100, 'expected_cash' => 150]);

        $this->assertSame(1, Artisan::call('reports:reconcile', $args));
        $this->assertStringContainsString('SOMETHING DOES NOT TIE', Artisan::output());
    }
}
