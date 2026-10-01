<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cycle 10 — the final re-break. Every fix from cycles 1–9, attacked with an
 * input its own tests never used: a SECOND foreign currency (ZMW at 6.5, not
 * USD at 128), refunds on foreign payments, array-shaped and conflicting
 * parameters, international phones typed without a plus.
 *
 * A test here that fails is a fix that did not hold.
 */
class ReportFinalRebreakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['KES', 1, 1, true], ['USD', 0.01, 128, false], ['ZMW', 0.14, 6.5, false]] as [$code, $fx, $rate, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], [
                'name' => $code, 'symbol' => $code, 'exchange_rate' => $fx,
                'reporting_rate_to_kes' => $rate, 'is_base' => $base, 'is_active' => true,
            ]);
        }
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    private function actAs(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    private function order(array $a): Order
    {
        static $n = 0;

        return Order::create($a + [
            'order_number' => 'RB-' . (++$n), 'order_type' => 'pos', 'status' => 'confirmed',
            'currency_code' => 'KES', 'payment_status' => 'partial',
        ] + ['subtotal' => $a['total_amount']]);
    }

    private function pay(Order $o, float $amount, array $extra = []): Payment
    {
        return Payment::create($extra + [
            'order_id' => $o->id, 'amount' => $amount, 'currency_code' => $o->currency_code,
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    private function today(): string
    {
        return now()->format('Y-m-d');
    }

    // ── cycles 2/9: money in one unit, with a currency no test used ─────────

    /** ZMW 1,000 order, ZMW 400 paid: owed is 600 × 6.5 = 3,900 KES — not 600, not 2,600. */
    public function test_a_kwacha_balance_is_owed_in_shillings_everywhere(): void
    {
        $this->actAs(['reports.view', 'reports.financial']);
        $o = $this->order(['currency_code' => 'ZMW', 'total_amount' => 1_000]);
        $this->pay($o, 400);

        $exec = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();
        $this->assertSame(3_900.0, (float) $exec->json('kpis.money.outstanding.amount'), 'outstanding');
        $this->assertSame(6_500.0, (float) $exec->json('kpis.sales.revenue.current'), 'revenue');
        $this->assertSame(2_600.0, (float) $exec->json('kpis.money.collected.current'), 'collected');

        $t = $this->today();
        $pnl = $this->getJson("/api/v1/admin/reports/financial-intelligence?period=custom&from={$t}&to={$t}")->json('pnl');
        $this->assertSame(0, (int) $pnl['earned_orders'], 'part-paid kwacha is not earned (cycle 9 rule, second currency)');
    }

    /** A refund on a DOLLAR payment: collected is (100 − 20) × 128, on every page that states it. */
    public function test_a_refunded_dollar_payment_is_collected_net_everywhere(): void
    {
        $this->actAs(['reports.view', 'reports.financial']);
        $o = $this->order(['currency_code' => 'USD', 'total_amount' => 100, 'payment_status' => 'paid']);
        $this->pay($o, 100, ['refund_amount' => 20]);

        $t = $this->today();
        $q = "start_date={$t}&end_date={$t}";
        $summary = (float) $this->getJson("/api/v1/admin/reports/sales/summary?{$q}")->json('summary.total_collected');
        $methods = (float) $this->getJson("/api/v1/admin/reports/sales/by-payment-method?{$q}")->json('total');
        $revenue = (float) $this->getJson("/api/v1/admin/reports/financial/revenue?{$q}")->json('collected.total');
        $exec    = (float) $this->getJson('/api/v1/admin/reports/executive?period=this_month')->json('kpis.money.collected.current');

        foreach (['summary' => $summary, 'methods' => $methods, 'financial/revenue' => $revenue, 'executive' => $exec] as $page => $v) {
            $this->assertSame(10_240.0, round($v, 2), "{$page} collected");
        }
    }

    /** Overpaid in dollars: the attention item states the excess in shillings; a refund back to the total clears it. */
    public function test_a_dollar_overpayment_is_stated_in_shillings_and_cleared_by_a_refund(): void
    {
        $this->actAs(['reports.view']);
        $over = $this->order(['currency_code' => 'USD', 'total_amount' => 100, 'payment_status' => 'paid']);
        $this->pay($over, 120);
        $fixed = $this->order(['currency_code' => 'USD', 'total_amount' => 50, 'payment_status' => 'paid']);
        $this->pay($fixed, 60, ['refund_amount' => 10]);

        $item = collect($this->getJson('/api/v1/admin/reports/executive?period=this_month')->json('attention'))
            ->firstWhere('key', 'overpaid_orders');

        $this->assertNotNull($item);
        $this->assertSame(1, $item['count'], 'the refunded one is not overpaid');
        $this->assertSame(2_560.0, (float) $item['entities'][0]['excess'], 'USD 20 × 128');
    }

    // ── cycle 8: hostile input, new shapes ──────────────────────────────────

    public static function arrayShapedInputs(): array
    {
        return [
            'start_date as array' => ['start_date[]=2026-09-01'],
            'from as array'       => ['from[]=2026-09-01&to=2026-09-30'],
            'outlet_id as array'  => ['outlet_id[]=1'],
            'nested end_date'     => ['end_date[a][b]=x'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('arrayShapedInputs')]
    public function test_array_shaped_parameters_are_refused_not_crashed(string $query): void
    {
        $this->actAs(['reports.view']);
        foreach (['sales/summary', 'executive', 'sales/ledger'] as $endpoint) {
            $status = $this->getJson("/api/v1/admin/reports/{$endpoint}?{$query}")->status();
            $this->assertSame(422, $status, "{$endpoint}?{$query} → {$status}");
        }
    }

    /**
     * Both spellings, DIFFERENT dates. The legacy pages read start_date, the
     * executive reads from — so the same URL answered for two windows on two
     * pages. Refuse the contradiction rather than pick one silently.
     */
    public function test_contradictory_date_spellings_are_refused(): void
    {
        $this->actAs(['reports.view']);
        $q = 'start_date=2026-09-01&from=2026-08-01&end_date=2026-09-30&to=2026-09-30';
        foreach (['sales/summary', 'executive'] as $endpoint) {
            $this->getJson("/api/v1/admin/reports/{$endpoint}?{$q}")->assertStatus(422);
        }
        // The same date under both names is not a contradiction.
        $this->getJson('/api/v1/admin/reports/sales/summary?start_date=2026-09-01&from=2026-09-01')->assertOk();
    }

    // ── cycle 9: contacts, with the phone shapes production actually holds ──

    /** International numbers typed without a plus, in every field a report might carry them. */
    public function test_international_contacts_do_not_reach_a_viewer_without_customer_access(): void
    {
        foreach ([['2348012345678', 'ada@example.ng'], ['27761234567', 'thabo@example.za']] as $i => [$phone, $email]) {
            $o = $this->order([
                'total_amount' => 5_000, 'payment_status' => 'paid', 'status' => 'completed',
                'customer_first_name' => "Intl{$i}", 'customer_phone' => $phone, 'customer_email' => $email,
                'created_at' => now()->subDays(10),
            ]);
            $this->pay($o, 5_000, ['paid_at' => now()->subDays(10)]);
        }
        $this->actAs(['reports.view', 'reports.export']);

        foreach (['sales/by-customer', 'second-purchase', 'customers/lifetime-value', 'drill/new_customers?period=last_30',
                  'drill/revenue?period=last_30', 'sales/by-customer?export=csv'] as $route) {
            $body = (string) $this->get("/api/v1/admin/reports/{$route}", ['Accept' => 'application/json'])->getContent();
            foreach (['8012345678', '761234567', 'ada@example.ng', 'thabo@example.za'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "{$route} carries {$leak}");
            }
        }
    }

    // ── cycle 10: one buyer, one definition, every page ─────────────────────

    /**
     * September on production: 173, 184, 187 and 192 buyers for the same 207
     * orders, by page. One person here buys three ways — as a registered
     * customer, at the till with the phone typed internationally, and again
     * typed with spaces — plus an email-only buyer and an anonymous till sale.
     * Two buyers, on every page (owner, 2026-10-01: phone first).
     */
    public function test_one_person_is_one_buyer_on_every_page(): void
    {
        $this->actAs(['reports.view', 'customers.view']);
        $grace = \App\Models\Customer::create([
            'customer_number' => 'C-RB-1', 'first_name' => 'Grace', 'last_name' => 'W', 'phone' => '0711000111',
        ]);
        $product = \App\Models\Product::factory()->create();
        $sale = function (array $a) use ($product) {
            $o = $this->order($a + ['total_amount' => 1_000, 'payment_status' => 'paid', 'status' => 'completed']);
            DB::table('order_items')->insert([
                'order_id' => $o->id, 'product_id' => $product->id, 'sku' => 'RB', 'product_name' => 'Stole',
                'quantity' => 1, 'unit_price' => 1_000, 'total_price' => 1_000, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $sale(['customer_id' => $grace->id]);                                   // registered, no phone typed
        $sale(['customer_phone' => '+254711000111']);                          // walk-in, international
        $sale(['customer_phone' => '0711 000 111']);                           // walk-in, spaced
        $sale(['customer_email' => 'Mary@Example.com ']);                      // email only
        $sale([]);                                                             // anonymous

        $t = $this->today();
        $q = "start_date={$t}&end_date={$t}";
        $summary  = (int) $this->getJson("/api/v1/admin/reports/sales/summary?{$q}")->json('summary.unique_customers');
        $byCust   = count($this->getJson("/api/v1/admin/reports/sales/by-customer?{$q}")->json('customers'));
        $products = collect($this->getJson("/api/v1/admin/reports/sales/by-product?{$q}")->json('products'));
        $product1 = (int) ($products->first()['unique_customers'] ?? -1);

        $this->assertSame(2, $summary, 'sales summary');
        $this->assertSame(2, $byCust, 'sales by customer');
        $this->assertSame(2, $product1, 'sales by product — counted logins only before');
        // Missed in cycle 10, found building the outlet filter: the customer
        // summary still pre-filtered to "record or login".
        $this->assertSame(2, (int) $this->getJson("/api/v1/admin/reports/customers/summary?{$q}")->json('unique_buyers'),
            'customers summary');
    }

    /**
     * The retention cohort keyed members as 'c<id>'. Once buyers became
     * phone-first, a member WITH a phone never matched their own orders —
     * zero retention again, the programme's worst figure, re-created by the
     * fix for something else. A customer with a phone who comes back as a
     * walk-in the next month is retained; one who never returns is not.
     */
    public function test_a_customer_with_a_phone_who_returns_is_retained(): void
    {
        $this->actAs(['reports.view']);
        $joined = now()->startOfMonth()->subMonth()->addDays(2);
        $back   = \App\Models\Customer::create(['customer_number' => 'C-RET-1', 'first_name' => 'Ruth', 'phone' => '0722000222']);
        $gone   = \App\Models\Customer::create(['customer_number' => 'C-RET-2', 'first_name' => 'Gone', 'phone' => '0733000333']);
        DB::table('customers')->whereIn('id', [$back->id, $gone->id])->update(['created_at' => $joined]);

        // created_at is not mass-assignable on Order, so the first-month orders
        // are dated directly — otherwise all three land in this month.
        $first = [
            $this->order(['customer_id' => $back->id, 'total_amount' => 1_000, 'payment_status' => 'paid'])->id,
            $this->order(['customer_id' => $gone->id, 'total_amount' => 1_000, 'payment_status' => 'paid'])->id,
        ];
        DB::table('orders')->whereIn('id', $first)->update(['created_at' => $joined]);
        $this->order(['customer_phone' => '+254722000222', 'total_amount' => 1_000, 'payment_status' => 'paid']);

        $from = $joined->format('Y-m-d');
        $to   = now()->format('Y-m-d');
        $cohort = collect($this->getJson("/api/v1/admin/reports/customers/retention?start_date={$from}&end_date={$to}")
            ->assertOk()->json('retention'))->firstWhere('cohort', $joined->format('Y-m'));

        $this->assertNotNull($cohort);
        $this->assertSame(2, (int) $cohort['size']);
        $this->assertSame(2, (int) ($cohort['months'][$joined->format('Y-m')] ?? 0), 'both bought in their first month');
        $this->assertSame(1, (int) ($cohort['months'][now()->format('Y-m')] ?? 0), 'Ruth came back — as a walk-in, by phone');
    }

    // ── cycle 10: a currency created without a reporting rate ───────────────

    /**
     * What happened on 2026-10-01: GBP was created in the hub with a pricing
     * rate and no reporting rate (the screen had no field for one). The first
     * pound sale would have left every report without a word. Now the
     * executive feed names it; once the rate is set, it counts at that rate.
     */
    public function test_a_sale_in_a_currency_without_a_reporting_rate_is_named_then_counted(): void
    {
        $this->actAs(['reports.view']);
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], [
            'name' => 'Sterling Pounds', 'symbol' => '£', 'exchange_rate' => 0.006667,
            'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true,
        ]);
        ReportingCurrency::forget();
        $this->order(['currency_code' => 'GBP', 'total_amount' => 100, 'payment_status' => 'paid', 'status' => 'completed']);

        $item = collect($this->getJson('/api/v1/admin/reports/executive?period=this_month')->json('attention'))
            ->firstWhere('key', 'unrated_currency_sales');
        $this->assertNotNull($item, 'the unratable sale is named');
        $this->assertSame('GBP', $item['entities'][0]['currency']);
        $this->assertSame('/settings/currencies', $item['link'], 'the link goes to a page that exists');

        DB::table('currencies')->where('code', 'GBP')->update(['reporting_rate_to_kes' => 165]);
        ReportingCurrency::forget();

        $res = $this->getJson('/api/v1/admin/reports/executive?period=this_month');
        $this->assertNull(collect($res->json('attention'))->firstWhere('key', 'unrated_currency_sales'), 'cleared once rated');
        $this->assertSame(16_500.0, (float) $res->json('kpis.sales.revenue.current'), '£100 × 165');
    }

    // ── cycle 9: the Markets panel outside the Reports group ────────────────

    /**
     * analytics/overview sits behind reports.view but outside the Reports
     * route group. Its per-country revenue counted unconfirmed carts and added
     * dollars as shillings — measured live: 168,130 shown, 6,000 recognised.
     */
    public function test_markets_revenue_is_recognised_and_in_shillings(): void
    {
        $this->actAs(['reports.view']);
        $base = ['order_type' => 'online', 'customer_country_code' => 'KE'];
        $this->order($base + ['total_amount' => 6_000, 'status' => 'processing', 'payment_status' => 'paid']);
        $this->order($base + ['total_amount' => 100, 'currency_code' => 'USD', 'status' => 'confirmed', 'payment_status' => 'paid']);
        $this->order($base + ['total_amount' => 162_000, 'status' => 'pending', 'payment_status' => 'pending']);   // a cart

        $ke = collect($this->getJson('/api/v1/admin/analytics/overview?days=30')->assertOk()->json('buyers_by_country'))
            ->firstWhere('country_code', 'KE');

        $this->assertNotNull($ke);
        $this->assertSame(18_800.0, round((float) $ke['revenue'], 2), '6,000 + USD 100 × 128; the cart is not revenue');
        $this->assertSame(2, (int) $ke['orders']);
    }
}
