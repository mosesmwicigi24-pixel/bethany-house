<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * International corridor — the diaspora/regional business the KES-only
 * reports exclude. A corridor order is sales truth (non-voided/cancelled)
 * that is either not in KES or is flagged is_international. Native
 * currencies are never summed together; KES equivalents appear only when
 * the currencies table carries real configured rates.
 */
class InternationalCorridorTest extends TestCase
{
    use RefreshDatabase;

    /** A named product with an English translation, like the real catalogue. */
    private function product(string $name): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    /** A sales-truth order in $currency, optionally carrying product lines. */
    private function order(string $currency, float $amount, int $daysAgo, array $attrs = [], array $lines = []): Order
    {
        $when  = now()->subDays($daysAgo);
        $order = Order::factory()->create(array_merge([
            'order_type' => 'online', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $amount, 'currency_code' => $currency,
            'created_at' => $when,
        ], $attrs));

        foreach ($lines as [$product, $qty, $lineTotal]) {
            DB::table('order_items')->insert([
                'order_id' => $order->id, 'product_id' => $product->id,
                'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
                'quantity' => $qty, 'unit_price' => $lineTotal / max($qty, 1), 'total_price' => $lineTotal,
                'created_at' => $when, 'updated_at' => $when,
            ]);
        }

        return $order;
    }

    /** A settled payment on $order, in $currency, net of $refund. */
    private function payment(Order $order, string $currency, float $amount, float $refund = 0): void
    {
        DB::table('payments')->insert([
            'order_id' => $order->id,
            'payment_number' => 'PAY-' . fake()->unique()->numerify('########'),
            'payment_method' => 'card', 'amount' => $amount,
            'currency_code' => $currency, 'status' => 'paid',
            'refund_amount' => $refund, 'paid_at' => $order->created_at,
            'created_at' => $order->created_at, 'updated_at' => $order->created_at,
        ]);
    }

    /** Configure a currency row with a base-relative rate (KES base = 1.0). */
    /**
     * Configure a REPORTING rate: how many KES one unit of $code is worth.
     *
     * Read plainly — 128 KES per 1 USD — unlike currencies.exchange_rate,
     * which is the base-relative PRICING rate (0.01 for the same currency) and
     * is what a customer is quoted at, not what earned money is worth.
     */
    private function rate(string $code, float $kesPerUnit, bool $isBase = false): void
    {
        DB::table('currencies')->insert([
            'code' => $code, 'name' => $code, 'symbol' => $code,
            // The pricing rate is left at its default here; this suite is about
            // reporting, and the two must not be conflated.
            'exchange_rate' => 1.0,
            'reporting_rate_to_kes' => $kesPerUnit,
            'is_base' => $isBase, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Support\ReportingCurrency::forget();
    }

    private function reportsViewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function report(int $days = 180): array
    {
        Cache::flush(); // each test seeds its own world — never share the 10-min cache

        return MetricEngine::for(User::factory()->create())->internationalCorridor($days);
    }

    public function test_currency_rollups_stay_native_and_domestic_kes_is_excluded(): void
    {
        // Two USD customers, one ordering twice.
        $this->order('USD', 100, 10, ['customer_phone' => '+12025550101', 'customer_country_code' => 'US']);
        $this->order('USD', 300, 20, ['customer_phone' => '+12025550101', 'customer_country_code' => 'US']);
        $this->order('USD', 200, 30, ['customer_phone' => '+12025550202', 'customer_country_code' => 'US']);
        // One ZMW church.
        $this->order('ZMW', 5000, 15, ['customer_phone' => '+260971112233', 'customer_country_code' => 'ZM']);
        // A KES order shipped abroad — corridor via is_international.
        $this->order('KES', 8000, 5, ['customer_phone' => '0722000111', 'is_international' => true, 'customer_country_code' => 'UG']);
        // Domestic KES — NOT corridor, only a denominator row.
        $this->order('KES', 9999, 5, ['customer_phone' => '0722000222']);
        // A voided USD order — not sales truth, invisible everywhere.
        $this->order('USD', 700, 5, ['status' => 'voided', 'customer_phone' => '+12025550303']);

        $report = $this->report();

        $this->assertSame(5, $report['summary']['corridor_orders']);
        $this->assertSame(4, $report['summary']['corridor_customers']);
        $this->assertSame(3, $report['summary']['currencies_active']);
        // 5 corridor of 6 sales-truth orders.
        $this->assertEqualsWithDelta(83.3, $report['summary']['share_of_all_orders_pct'], 0.1);

        $byCur = collect($report['currencies'])->keyBy('currency');
        $usd = $byCur['USD'];
        $this->assertSame(3, $usd['orders']);
        $this->assertSame(2, $usd['customers']);
        $this->assertEqualsWithDelta(600.0, $usd['revenue_native'], 0.01);
        $this->assertEqualsWithDelta(200.0, $usd['avg_order_native'], 0.01);

        $this->assertEqualsWithDelta(5000.0, $byCur['ZMW']['revenue_native'], 0.01);
        // The KES currency row carries ONLY the international KES order —
        // the domestic 9,999 never leaks in.
        $this->assertEqualsWithDelta(8000.0, $byCur['KES']['revenue_native'], 0.01);
        $this->assertSame(1, $byCur['KES']['orders']);
    }

    public function test_country_grouping_falls_back_to_unknown(): void
    {
        $this->order('USD', 100, 10, ['customer_phone' => '+12025550101', 'customer_country_code' => 'US']);
        $this->order('USD', 150, 12, ['customer_phone' => '+12025550202', 'customer_country_code' => 'US']);
        $this->order('ZMW', 5000, 15, ['customer_phone' => '+260971112233', 'customer_country_code' => 'ZM']);
        // No country on the order → the 'unknown' bucket, never dropped.
        $this->order('USD', 75, 20, ['customer_phone' => '+441234567890', 'customer_country_code' => null]);

        $report = $this->report();

        $byCountry = collect($report['countries'])->keyBy('country');
        $this->assertSame(2, $byCountry['US']['orders']);
        $this->assertSame(2, $byCountry['US']['customers']);
        $this->assertEqualsWithDelta(250.0, $byCountry['US']['revenue']['USD'], 0.01);

        $this->assertEqualsWithDelta(5000.0, $byCountry['ZM']['revenue']['ZMW'], 0.01);

        $this->assertSame(1, $byCountry['unknown']['orders']);
        $this->assertSame('Unknown', $byCountry['unknown']['country_name']);
        $this->assertEqualsWithDelta(75.0, $byCountry['unknown']['revenue']['USD'], 0.01);
    }

    public function test_without_configured_rates_totals_are_native_only(): void
    {
        // currencies table is empty → no rates exist for USD.
        $this->order('USD', 100, 10, ['customer_phone' => '+12025550101']);

        $report = $this->report();

        $this->assertTrue($report['summary']['rates_unavailable']);
        $this->assertNull($report['summary']['kes_equivalent_total']);
        $this->assertNull($report['currencies'][0]['kes_equivalent']);
        foreach ($report['trend'] as $bucket) {
            $this->assertNull($bucket['kes_equivalent']);
        }
    }

    public function test_an_unset_reporting_rate_is_not_a_rate(): void
    {
        // This used to test a workaround: currencies.exchange_rate DEFAULTs to
        // 1.0, so a non-base row sitting at exactly 1.0 could not be told apart
        // from an unconfigured one, and the code had to treat 1.0 as "unset".
        //
        // reporting_rate_to_kes is nullable with NO default, so "nobody has set
        // this" is now expressible directly. The old heuristic is not just
        // unnecessary, it is wrong — it would refuse to honour a currency a
        // human deliberately pegged at 1:1.
        $this->rate('KES', 1.0, isBase: true);
        DB::table('currencies')->insert([
            'code' => 'USD', 'name' => 'USD', 'symbol' => 'USD',
            'exchange_rate' => 1.0, 'reporting_rate_to_kes' => null,
            'is_base' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Support\ReportingCurrency::forget();

        $this->order('USD', 100, 10, ['customer_phone' => '+12025550101']);

        $report = $this->report();

        $this->assertTrue($report['summary']['rates_unavailable']);
        $this->assertNull($report['summary']['kes_equivalent_total']);
    }

    public function test_configured_rates_produce_kes_equivalents(): void
    {
        // Reporting rates: KES per one unit. $100 at 128 = KES 12,800.
        $this->rate('KES', 1.0, isBase: true);
        $this->rate('USD', 128.0);
        $this->rate('ZMW', 5.0);

        $this->order('USD', 100, 10, ['customer_phone' => '+12025550101']);
        $this->order('ZMW', 5000, 15, ['customer_phone' => '+260971112233']);
        $this->order('KES', 8000, 5, ['customer_phone' => '0722000111', 'is_international' => true]);

        $report = $this->report();

        $this->assertFalse($report['summary']['rates_unavailable']);
        $byCur = collect($report['currencies'])->keyBy('currency');
        $this->assertEqualsWithDelta(12800.0, $byCur['USD']['kes_equivalent'], 0.01);
        $this->assertEqualsWithDelta(25000.0, $byCur['ZMW']['kes_equivalent'], 0.01);
        $this->assertEqualsWithDelta(8000.0, $byCur['KES']['kes_equivalent'], 0.01);
        $this->assertEqualsWithDelta(
            12800.0 + 25000.0 + 8000.0,
            $report['summary']['kes_equivalent_total'],
            1.0,
        );

        // Everything landed inside the current 6-month trend window.
        $this->assertCount(6, $report['trend']);
        $trendOrders = array_sum(array_column($report['trend'], 'orders'));
        $this->assertSame(3, $trendOrders);
        $this->assertEqualsWithDelta(
            $report['summary']['kes_equivalent_total'],
            array_sum(array_map(fn ($b) => (float) $b['kes_equivalent'], $report['trend'])),
            1.0,
        );
    }

    public function test_paid_native_counts_only_currency_matched_settled_payments(): void
    {
        $usd = $this->order('USD', 500, 10, ['customer_phone' => '+12025550101']);
        // $300 settled with $50 refunded → 250 net.
        $this->payment($usd, 'USD', 300, 50);
        // A KES payment row on the USD order must NOT leak into "USD paid".
        $this->payment($usd, 'KES', 10000);

        $report = $this->report();

        $byCur = collect($report['currencies'])->keyBy('currency');
        $this->assertEqualsWithDelta(250.0, $byCur['USD']['paid_native'], 0.01);
        $this->assertEqualsWithDelta(500.0, $byCur['USD']['revenue_native'], 0.01);
    }

    public function test_top_products_ranked_by_corridor_order_count(): void
    {
        $stole   = $this->product('Clergy Stole');
        $chalice = $this->product('Silver Chalice');

        $this->order('USD', 100, 10, ['customer_phone' => '+12025550101'], [[$stole, 2, 80]]);
        $this->order('USD', 150, 12, ['customer_phone' => '+12025550202'], [[$stole, 1, 40], [$chalice, 1, 110]]);
        $this->order('ZMW', 5000, 15, ['customer_phone' => '+260971112233'], [[$stole, 3, 4000]]);
        // A domestic KES basket never reaches the corridor ranking.
        $this->order('KES', 9000, 5, ['customer_phone' => '0722000222'], [[$chalice, 5, 9000]]);

        $report = $this->report();

        $this->assertSame('Clergy Stole', $report['top_products'][0]['name']);
        $this->assertSame(3, $report['top_products'][0]['orders']);
        $this->assertSame(6, $report['top_products'][0]['units']);
        $this->assertSame('Silver Chalice', $report['top_products'][1]['name']);
        $this->assertSame(1, $report['top_products'][1]['orders']);
    }

    public function test_days_window_bounds_the_corridor(): void
    {
        $this->order('USD', 100, 10, ['customer_phone' => '+12025550101']);
        $this->order('USD', 900, 200, ['customer_phone' => '+12025550202']); // outside 90d

        $report = $this->report(90);

        $this->assertSame(1, $report['summary']['corridor_orders']);
        $this->assertEqualsWithDelta(
            100.0,
            collect($report['currencies'])->keyBy('currency')['USD']['revenue_native'],
            0.01,
        );
        $this->assertSame(90, $report['window_days']);
    }

    public function test_endpoint_exposes_report_and_requires_reports_view(): void
    {
        $this->order('USD', 100, 10, ['customer_phone' => '+12025550101']);

        // No permission → 403.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/reports/international')->assertForbidden();

        // reports.view → the full payload; days is validated.
        Cache::flush();
        $this->reportsViewer();
        $res = $this->getJson('/api/v1/admin/reports/international?days=90')->assertOk()->json();
        $this->assertSame(1, $res['summary']['corridor_orders']);
        $this->assertSame('USD', $res['currencies'][0]['currency']);
        $this->assertSame(90, $res['window_days']);

        $this->getJson('/api/v1/admin/reports/international?days=10')->assertStatus(422);
    }
}
