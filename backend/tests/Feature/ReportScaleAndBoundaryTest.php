<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The scenarios cycle 2 owed and had not run: scale, boundaries, empty and
 * degenerate windows, a warm cache serving a different question, and the
 * reconciliation invariants holding when the data is not tidy.
 *
 * Every figure Reports shows is a sum over the order book, so the failures
 * worth hunting here are the ones that only appear in volume: a row counted
 * twice by a join, a boundary order landing in two windows or neither, a
 * cached answer handed to the wrong filter, a query count that grows per row.
 *
 * The timing assertions are deliberately loose — they exist to catch an
 * accidental N+1 or a cartesian join, not to police milliseconds on shared CI.
 * The measured numbers go in the cycle's evidence table.
 */
class ReportScaleAndBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['KES', 1.0, true], ['USD', 128.0, false], ['ZMW', 6.5, false]] as [$c, $r, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $c], [
                'name' => $c, 'symbol' => $c, 'exchange_rate' => 1.0,
                'reporting_rate_to_kes' => $r, 'is_base' => $base, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \App\Support\ReportingCurrency::forget();

        $this->outlet = Outlet::factory()->create(['name' => 'Sonalux']);

        $staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial', 'customers.view', 'customers.insights'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    /**
     * Orders in bulk, spread over currencies, channels, statuses and a year of
     * dates. created_at is not mass-assignable, so it is stamped afterwards —
     * without that every row lands on today and a window test passes by
     * accident of the calendar.
     */
    private function seedOrders(int $count): void
    {
        $currencies = ['KES', 'KES', 'KES', 'USD', 'ZMW'];
        $channels   = [['till', 'walk_in'], ['chat', 'whatsapp'], ['chat', 'messenger'],
                       ['web', 'website'], ['quoted', null]];
        $statuses   = [['completed', 'paid'], ['confirmed', 'partial'], ['processing', 'pending'],
                       ['pending', 'pending'], ['cancelled', 'pending']];

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            [$bucket, $source] = $channels[$i % 5];
            [$status, $pay]    = $statuses[$i % 5];

            $rows[] = [
                'order_number'   => 'SC-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'outlet_id'      => $this->outlet->id,
                'status'         => $status,
                'payment_status' => $pay,
                'currency_code'  => $currencies[$i % 5],
                'subtotal'       => 100 + $i,
                'total_amount'   => 100 + $i,
                'sales_bucket'   => $bucket,
                'source_channel' => $source,
                'created_at'     => now()->subDays($i % 300)->setTime(9, 0),
                'updated_at'     => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
    }

    /** Wall time and query count for one request. */
    private function measure(string $url): array
    {
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $started  = microtime(true);
        $response = $this->getJson($url);
        $ms       = (microtime(true) - $started) * 1000;

        $response->assertOk();

        return ['ms' => $ms, 'queries' => $queries, 'bytes' => strlen($response->getContent()),
                'json' => $response->json()];
    }

    // ── Scale ────────────────────────────────────────────────────────────────

    public function test_the_reports_hold_their_shape_at_two_thousand_orders(): void
    {
        $this->seedOrders(2_000);

        $window  = 'start_date=' . now()->subYears(2)->toDateString() . '&end_date=' . now()->addDay()->toDateString();
        $summary = $this->measure("/api/v1/admin/reports/sales/summary?{$window}");
        $exec    = $this->measure('/api/v1/admin/reports/executive?period=this_year');
        $pipe    = $this->measure('/api/v1/admin/reports/order-pipeline');

        fwrite(STDERR, sprintf(
            "\n  MEASURED at 2,000 orders — summary %.0fms/%dq/%dB · executive %.0fms/%dq/%dB · pipeline %.0fms/%dq/%dB\n",
            $summary['ms'], $summary['queries'], $summary['bytes'],
            $exec['ms'], $exec['queries'], $exec['bytes'],
            $pipe['ms'], $pipe['queries'], $pipe['bytes'],
        ));

        // A per-row query is the failure that matters; a constant is fine.
        $this->assertLessThan(120, $summary['queries'], 'the summary must not query per order');
        $this->assertLessThan(200, $exec['queries'], 'the executive page must not query per order');
        $this->assertLessThan(120, $pipe['queries']);
        $this->assertLessThan(20_000, $summary['ms'], 'no runaway join');
    }

    public function test_the_channel_lines_still_sum_to_total_revenue_at_scale(): void
    {
        $this->seedOrders(1_000);

        $s = $this->measure('/api/v1/admin/reports/sales/summary?start_date='
            . now()->subYears(2)->toDateString() . '&end_date=' . now()->addDay()->toDateString())['json']['summary'];

        $lines = (float) $s['till_revenue'] + (float) $s['web_revenue']
               + (float) $s['whatsapp_revenue'] + (float) $s['messenger_revenue']
               + (float) $s['other_chat_revenue'] + (float) $s['quoted_revenue'];

        $this->assertSame(round((float) $s['total_revenue'], 2), round($lines, 2),
            'mixed currencies, five channels, 1,000 orders — the lines must still foot');
        $this->assertSame(round((float) $s['chat_revenue'], 2),
            round((float) $s['whatsapp_revenue'] + (float) $s['messenger_revenue'] + (float) $s['other_chat_revenue'], 2));
    }

    public function test_the_drill_total_matches_the_order_count_it_opened_from(): void
    {
        $this->seedOrders(200);

        $exec  = $this->measure('/api/v1/admin/reports/executive?period=this_year')['json'];
        $drill = $this->measure('/api/v1/admin/reports/drill/orders?period=this_year')['json'];

        $this->assertSame(
            (int) $exec['kpis']['sales']['orders']['current'], (int) $drill['total'],
            'the drill must list exactly the orders the tile counted',
        );
    }

    // ── Boundaries ───────────────────────────────────────────────────────────

    public function test_an_order_on_the_first_second_of_the_window_is_counted_once(): void
    {
        $start = now()->startOfMonth();
        $order = Order::create([
            'order_number' => 'BD-first', 'status' => 'completed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'subtotal' => 1_000, 'total_amount' => 1_000,
        ]);
        $order->forceFill(['created_at' => $start])->saveQuietly();

        $inside = $this->measure('/api/v1/admin/reports/sales/summary?start_date='
            . $start->toDateString() . '&end_date=' . now()->toDateString())['json']['summary'];
        $this->assertSame(1, (int) $inside['total_orders']);

        // And not again in the month before it.
        $before = $this->measure('/api/v1/admin/reports/sales/summary?start_date='
            . $start->copy()->subMonth()->toDateString() . '&end_date='
            . $start->copy()->subDay()->toDateString())['json']['summary'];
        $this->assertSame(0, (int) $before['total_orders'], 'no order belongs to two windows');
    }

    public function test_an_order_at_the_last_second_of_the_window_is_not_lost(): void
    {
        $end   = now()->endOfDay();
        $order = Order::create([
            'order_number' => 'BD-last', 'status' => 'completed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'subtotal' => 500, 'total_amount' => 500,
        ]);
        $order->forceFill(['created_at' => $end->copy()->subSecond()])->saveQuietly();

        $s = $this->measure('/api/v1/admin/reports/sales/summary?start_date='
            . now()->toDateString() . '&end_date=' . now()->toDateString())['json']['summary'];

        $this->assertSame(1, (int) $s['total_orders'], 'the last second of the day is inside the day');
    }

    public function test_a_window_with_nothing_in_it_reports_zero_rather_than_failing(): void
    {
        $this->seedOrders(50);

        $s = $this->measure('/api/v1/admin/reports/sales/summary?start_date=2015-01-01&end_date=2015-01-31')
            ['json']['summary'];

        $this->assertSame(0, (int) $s['total_orders']);
        $this->assertSame(0.0, (float) $s['total_revenue']);
        $this->assertSame(0.0, (float) $s['whatsapp_revenue'], 'a new tile must be zero, not absent');
        $this->assertSame(0.0, (float) $s['messenger_revenue']);
    }

    public function test_an_inverted_window_does_not_invent_figures(): void
    {
        $this->seedOrders(20);

        // end before start: an empty range, not an error and not everything.
        $s = $this->measure('/api/v1/admin/reports/sales/summary?start_date='
            . now()->toDateString() . '&end_date=' . now()->subYear()->toDateString())['json']['summary'];

        $this->assertSame(0, (int) $s['total_orders']);
    }

    public function test_a_ten_year_window_is_answered(): void
    {
        $this->seedOrders(300);

        $s = $this->measure('/api/v1/admin/reports/sales/summary?start_date=2020-01-01&end_date=2030-12-31')
            ['json']['summary'];

        $this->assertGreaterThan(0, (int) $s['total_orders']);
    }

    // ── Degenerate and missing data ──────────────────────────────────────────

    public function test_an_order_in_a_currency_with_no_reporting_rate_is_excluded_not_miscounted(): void
    {
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], [
            'name' => 'GBP', 'symbol' => '£', 'exchange_rate' => 1.0,
            'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Support\ReportingCurrency::forget();

        Order::create(['order_number' => 'GB-1', 'status' => 'completed', 'payment_status' => 'paid',
                       'currency_code' => 'GBP', 'subtotal' => 1_000, 'total_amount' => 1_000]);
        Order::create(['order_number' => 'KE-1', 'status' => 'completed', 'payment_status' => 'paid',
                       'currency_code' => 'KES', 'subtotal' => 400, 'total_amount' => 400]);

        $s = $this->measure('/api/v1/admin/reports/sales/summary?start_date='
            . now()->subDay()->toDateString() . '&end_date=' . now()->addDay()->toDateString())['json']['summary'];

        // 1,000 GBP must not be added as 1,000 shillings just because nobody
        // set a rate. The order is left out, and the report says so elsewhere.
        $this->assertSame(400.0, (float) $s['total_revenue']);
        $this->assertSame(1, (int) $s['total_orders']);
    }

    public function test_an_order_with_no_outlet_is_still_in_the_business_wide_figure(): void
    {
        // 15 production orders (KES 747,238 of staff-raised quotes) have no
        // outlet. Business-wide means they are counted.
        Order::create(['order_number' => 'NO-OUT', 'status' => 'completed', 'payment_status' => 'paid',
                       'currency_code' => 'KES', 'subtotal' => 900, 'total_amount' => 900,
                       'sales_bucket' => 'quoted']);

        $s = $this->measure('/api/v1/admin/reports/sales/summary?start_date='
            . now()->subDay()->toDateString() . '&end_date=' . now()->addDay()->toDateString())['json']['summary'];

        $this->assertSame(900.0, (float) $s['total_revenue']);
    }

    public function test_a_refunded_order_is_in_no_revenue_figure(): void
    {
        Order::create(['order_number' => 'RF-1', 'status' => 'refunded', 'payment_status' => 'paid',
                       'currency_code' => 'KES', 'subtotal' => 5_000, 'total_amount' => 5_000]);

        $s = $this->measure('/api/v1/admin/reports/sales/summary?start_date='
            . now()->subDay()->toDateString() . '&end_date=' . now()->addDay()->toDateString())['json']['summary'];

        $this->assertSame(0.0, (float) $s['total_revenue'], 'dead money is not revenue');
    }

    // ── Caching under a different question ───────────────────────────────────

    public function test_a_warm_cache_is_not_served_to_a_different_outlet_filter(): void
    {
        $other = Outlet::factory()->create(['name' => 'Second Shop']);
        $this->seedOrders(40);
        Order::create(['order_number' => 'OT-1', 'status' => 'completed', 'payment_status' => 'paid',
                       'currency_code' => 'KES', 'subtotal' => 777_000, 'total_amount' => 777_000,
                       'outlet_id' => $other->id, 'sales_bucket' => 'till']);

        Cache::flush();

        $all   = $this->measure('/api/v1/admin/reports/executive?period=this_year')['json'];
        $first = $this->measure("/api/v1/admin/reports/executive?period=this_year&outlet_id={$this->outlet->id}")['json'];
        $second = $this->measure("/api/v1/admin/reports/executive?period=this_year&outlet_id={$other->id}")['json'];

        $revenue = fn (array $p) => (float) $p['kpis']['sales']['revenue']['current'];

        $this->assertGreaterThan($revenue($first), $revenue($all), 'the whole business is more than one shop');
        $this->assertSame(777_000.0, $revenue($second), 'the second shop gets its own answer, not the first one back');
    }

    public function test_repeating_a_request_returns_the_same_figures(): void
    {
        $this->seedOrders(100);

        $one = $this->measure('/api/v1/admin/reports/executive?period=this_year');
        $two = $this->measure('/api/v1/admin/reports/executive?period=this_year');

        $this->assertSame(
            $one['json']['kpis']['sales']['revenue']['current'],
            $two['json']['kpis']['sales']['revenue']['current'],
            'a warm read must agree with the cold one that filled it',
        );

        fwrite(STDERR, sprintf("  MEASURED cache — cold %.0fms/%dq, warm %.0fms/%dq\n",
            $one['ms'], $one['queries'], $two['ms'], $two['queries']));
    }

    // ── Money at scale ───────────────────────────────────────────────────────

    public function test_outstanding_at_scale_is_the_sum_of_its_own_drill(): void
    {
        // 40 open orders across three currencies, some part-paid.
        for ($i = 0; $i < 40; $i++) {
            $currency = ['KES', 'USD', 'ZMW'][$i % 3];
            $order = Order::create([
                'order_number' => 'OS-' . $i, 'status' => 'confirmed',
                'payment_status' => $i % 4 === 0 ? 'partial' : 'pending',
                'currency_code' => $currency, 'subtotal' => 100 + $i, 'total_amount' => 100 + $i,
            ]);
            if ($i % 4 === 0) {
                Payment::create(['order_id' => $order->id, 'amount' => 10, 'currency_code' => $currency,
                                 'status' => 'paid', 'payment_method' => 'cash', 'paid_at' => now()]);
            }
        }

        $headline = (float) $this->measure('/api/v1/admin/reports/executive?period=this_year')
            ['json']['kpis']['money']['outstanding']['amount'];

        // Walk every page of the drill and add the rows up.
        $sum = 0.0;
        $page = 1;
        do {
            $d = $this->measure("/api/v1/admin/reports/drill/outstanding?period=this_year&page={$page}")['json'];
            $sum += collect($d['rows'])->sum('amount');
            $seen = $page * $d['per_page'];
            $page++;
        } while ($seen < $d['total']);

        $this->assertSame(round($headline, 2), round($sum, 2),
            'every page of the drill, added up, is the tile');
        $this->assertGreaterThan(0.0, $headline);
    }

    public function test_the_aging_buckets_add_up_to_outstanding(): void
    {
        foreach ([5, 45, 75, 200] as $i => $age) {
            $order = Order::create([
                'order_number' => 'AG-' . $i, 'status' => 'confirmed', 'payment_status' => 'pending',
                'currency_code' => $i % 2 ? 'USD' : 'KES', 'subtotal' => 1_000, 'total_amount' => 1_000,
            ]);
            $order->forceFill(['created_at' => now()->subDays($age)])->saveQuietly();
        }

        // Both figures come off the same page, which is the point: a reader
        // sees the total and the buckets side by side and must be able to add
        // the second up to the first. They share openBalances(), so the
        // currency defect made both wrong together — and would again.
        $money = $this->measure('/api/v1/admin/reports/executive?period=this_year')['json']['kpis']['money'];
        $aging = collect($money['aging']['buckets']);
        $owed  = (float) $money['outstanding']['amount'];

        $this->assertCount(4, $aging, '0-30, 31-60, 61-90, 90+');
        $this->assertSame(round($owed, 2), round((float) $aging->sum('amount'), 2),
            'the four buckets are the whole of what is owed');
        // Two of the four orders are USD 1,000; at 128 they dominate the total,
        // which is the arithmetic the old expression got wrong in both places.
        $this->assertSame(258_000.0, round($owed, 2), '2 × 1,000 KES + 2 × 1,000 USD at 128');
    }

    public function test_a_customer_with_orders_in_three_currencies_is_one_customer(): void
    {
        $customer = Customer::create(['first_name' => 'Multi', 'last_name' => 'Currency',
                                      'email' => 'multi@example.test', 'phone' => '0799000111']);

        foreach (['KES', 'USD', 'ZMW'] as $c) {
            Order::create(['order_number' => 'MC-' . $c, 'customer_id' => $customer->id,
                           'status' => 'completed', 'payment_status' => 'paid',
                           'currency_code' => $c, 'subtotal' => 100, 'total_amount' => 100]);
        }

        $s = $this->measure('/api/v1/admin/reports/customers/summary?start_date='
            . now()->subDay()->toDateString() . '&end_date=' . now()->addDay()->toDateString())['json'];

        $this->assertSame(1, (int) $s['unique_buyers'], 'three currencies, one person');
    }
}
