<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two USD rates, and the rule that they must never be swapped.
 *
 *   PRICING    100 KES = 1 USD   what a customer is quoted   (currencies.exchange_rate)
 *   REPORTING  128 KES = 1 USD   what earned dollars are worth (reporting_rate_to_kes)
 *
 * Reporting at the pricing rate understates foreign revenue by 22%. Pricing at
 * the reporting rate would raise every dollar price by 28% overnight. Both are
 * silent failures — the numbers still look like numbers — so the boundary is
 * pinned here rather than left to a comment.
 */
class ReportingExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ReportingCurrency::forget();
        CurrencyPricing::forget();

        DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'Kenyan Shilling', 'symbol' => 'KES', 'exchange_rate' => 1,
             'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true],
        );
        DB::table('currencies')->updateOrInsert(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 0.01,
             'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true],
        );
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach (['reports.view', 'reports.financial'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }

        return $user;
    }

    public function test_the_two_rates_are_different_numbers_and_stay_that_way(): void
    {
        $this->assertSame(128.0, ReportingCurrency::rates()['USD'],
            'reporting a dollar of sales uses 128');

        // The pricing rate is base-relative: 0.01 means 100 KES buys 1 USD.
        $pricingRate = (float) DB::table('currencies')->where('code', 'USD')->value('exchange_rate');
        $this->assertSame(0.01, $pricingRate, 'quoting a customer still uses 100 KES = 1 USD');

        $this->assertNotSame(
            ReportingCurrency::rates()['USD'],
            1 / $pricingRate,
            'if these ever converge, one of them has been overwritten with the other',
        );
    }

    public function test_a_currency_with_no_reporting_rate_is_never_guessed(): void
    {
        DB::table('currencies')->updateOrInsert(
            ['code' => 'ZMW'],
            ['name' => 'Zambian Kwacha', 'symbol' => 'ZK', 'exchange_rate' => 0.14,
             'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true],
        );
        ReportingCurrency::forget();

        $this->assertNull(ReportingCurrency::toKes(7000, 'ZMW'),
            'no reporting rate means no number — not a number derived from the pricing rate');
        $this->assertNotContains('ZMW', ReportingCurrency::convertibleCodes());
    }

    public function test_dollar_sales_reach_the_headline_at_128(): void
    {
        Order::factory()->create([
            'status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'total_amount' => 1000,
        ]);
        Order::factory()->create([
            'status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'USD', 'total_amount' => 100,   // => 12,800 KES
        ]);

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/summary?start='
                . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString());

        $res->assertOk();
        $this->assertSame(13800.0, (float) $res->json('summary.total_revenue'),
            '1,000 KES + (100 USD x 128) = 13,800 — at the pricing rate it would read 11,000');
        $this->assertSame(2, (int) $res->json('summary.total_orders'));
    }

    public function test_the_sales_ledger_counts_dollar_orders_too(): void
    {
        Order::factory()->create([
            'order_type' => 'whatsapp', 'status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'USD', 'total_amount' => 50,     // => 6,400 KES
        ]);

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/ledger?start='
                . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString());

        $res->assertOk();
        $this->assertSame(6400.0, (float) collect($res->json('channels'))->sum('sales'));
    }

    public function test_unconfirmed_dollar_carts_are_counted_in_the_queue(): void
    {
        Order::factory()->create([
            'order_type' => 'whatsapp', 'status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'USD', 'total_amount' => 10,     // => 1,280 KES
        ]);

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/order-pipeline');

        $res->assertOk();
        $this->assertSame(1280.0, (float) $res->json('summary.value'),
            'a USD cart used to be excluded from the queue total entirely');
        $this->assertSame(0, (int) $res->json('summary.excluded_non_kes_orders'));
        $this->assertSame(1280.0, (float) $res->json('orders.0.total_kes'));
        $this->assertSame(10.0, (float) $res->json('orders.0.total_amount'),
            'the native amount is still shown — staff talk to the customer in dollars');
    }

    public function test_reporting_code_never_reads_the_pricing_column(): void
    {
        // The guard that outlives this test file: if someone reaches for
        // exchange_rate inside the reporting layer again, this fails.
        $reportingFiles = [
            app_path('Services/Reporting/MetricEngine.php'),
            app_path('Support/ReportingCurrency.php'),
        ];

        foreach ($reportingFiles as $file) {
            $code = file_get_contents($file);
            // Strip comments — the explanation of WHY may name the column.
            $code = preg_replace('!/\*.*?\*/!s', '', $code);
            $code = preg_replace('!//.*!', '', $code);

            $this->assertStringNotContainsString(
                'exchange_rate',
                $code,
                basename($file) . ' reads the PRICING rate. Reporting uses '
                . 'reporting_rate_to_kes — see ReportingCurrency.',
            );
        }
    }

    public function test_the_kwacha_converts_at_its_own_reporting_rate(): void
    {
        DB::table('currencies')->updateOrInsert(
            ['code' => 'ZMW'],
            ['name' => 'Zambian Kwacha', 'symbol' => 'ZK', 'exchange_rate' => 0.14,
             'reporting_rate_to_kes' => 6.5, 'is_base' => false, 'is_active' => true],
        );
        ReportingCurrency::forget();

        $this->assertSame(6.5, ReportingCurrency::rates()['ZMW']);
        $this->assertSame(45500.0, ReportingCurrency::toKes(7000, 'ZMW'));

        // The direction differs from USD, which is the whole reason these are
        // two columns: reporting is HIGHER than pricing for the dollar
        // (128 vs 100) and LOWER for the kwacha (6.5 vs ~7.14). One cannot be
        // derived from the other.
        $pricingKesPerZmw = 1 / (float) DB::table('currencies')->where('code', 'ZMW')->value('exchange_rate');
        $this->assertGreaterThan(ReportingCurrency::rates()['ZMW'], $pricingKesPerZmw,
            'the kwacha reports LOWER than it prices');
        $this->assertGreaterThan(1 / 0.01, ReportingCurrency::rates()['USD'],
            'the dollar reports HIGHER than it prices — opposite directions, same table');
    }

    public function test_an_admin_can_change_a_reporting_rate_without_a_deploy(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach (['settings.view', 'settings.edit'] as $p) {
            $admin->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }

        $id = DB::table('currencies')->where('code', 'USD')->value('id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/currencies-management/{$id}", ['reporting_rate_to_kes' => 130])
            ->assertOk();

        $this->assertSame(130.0, ReportingCurrency::rates()['USD'],
            'the rate cache must be busted on write, or the change waits out a 5-minute TTL');

        // Changing what a report says must not change what a customer is charged.
        $this->assertSame(0.01, (float) DB::table('currencies')->where('id', $id)->value('exchange_rate'));
    }

    /**
     * The sweep's guard.
     *
     * Every other test in this suite uses KES fixtures, which pass whether or
     * not conversion works — the KES rate is 1. This one seeds a dollar order
     * and walks the reports that used to filter `currency_code = 'KES'` and
     * therefore could not see it at all.
     */
    public function test_every_swept_report_can_see_a_dollar_order(): void
    {
        $usd = Order::factory()->create([
            'order_type'     => 'whatsapp',
            'status'         => 'confirmed',
            'payment_status' => 'paid',
            'currency_code'  => 'USD',
            'total_amount'   => 100,            // => KES 12,800
            'customer_phone' => '254722000999',
            'customer_first_name' => 'Dollar', 'customer_last_name' => 'Customer',
            'created_at'     => now()->subDays(3),
        ]);

        $viewer = $this->viewer();

        // 1. Headline tiles
        $summary = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/summary?start='
                . now()->subDays(10)->toDateString() . '&end=' . now()->addDay()->toDateString())
            ->assertOk();
        $this->assertSame(12800.0, (float) $summary->json('summary.total_revenue'));

        // 2. Ledger + its channel split
        $ledger = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/ledger?start='
                . now()->subDays(10)->toDateString() . '&end=' . now()->addDay()->toDateString())
            ->assertOk();
        $this->assertSame(12800.0, (float) collect($ledger->json('channels'))->sum('sales'));

        // 3. The executive dashboard — one of the engines the sweep reached.
        $exec = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/reports/executive')->assertOk();
        $this->assertSame(12800.0, (float) data_get($exec->json(), 'kpis.sales.revenue.current'),
            'the executive dashboard filtered KES-only and could not see this order');

        // 4. A customer engine reading raw SQL — the half that also still had
        //    the OLD recognition rule until this sweep.
        $topCustomers = MetricEngineProbe::topCustomerRevenue();
        $this->assertSame(12800.0, $topCustomers,
            'customer engines summed native totals behind a KES-only filter');

        $this->assertSame('USD', $usd->fresh()->currency_code, 'the order itself is untouched');
    }
}

/** Thin probe so the test can reach an engine method without an HTTP route. */
class MetricEngineProbe
{
    public static function topCustomerRevenue(): float
    {
        $engine = \App\Services\Reporting\MetricEngine::for(
            \App\Models\User::factory()->create(),
        );
        $rows = $engine->topCustomers(
            \Carbon\Carbon::now()->subDays(10),
            \Carbon\Carbon::now()->addDay(),
        );

        return (float) collect($rows)->sum('period_revenue');
    }
}
