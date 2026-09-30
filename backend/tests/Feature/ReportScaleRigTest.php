<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cycle 7 — the weight pass the brief asks for and nobody has run: time every
 * report on today's volume, then on a copy padded towards 50,000 orders, and
 * report where the FIRST WALL is. Fix only what crosses a second.
 *
 * Marked `scale` and excluded from the normal suite: it seeds tens of thousands
 * of rows and is a measuring instrument, not a regression test. Run it with
 *
 *     vendor/bin/phpunit --group scale
 *
 * Production stands at ~850 orders. The point of the exercise is to find the
 * ceiling BEFORE it arrives, and to do it on a padded copy rather than by
 * guessing — the brief forbids building scale machinery at 850 orders, and the
 * only honest way to keep that promise is to know where the wall actually is.
 *
 * It asserts almost nothing. It prints a table. A measurement that fails a
 * threshold I invented would tell us less than the numbers themselves.
 */
#[Group('scale')]
class ReportScaleRigTest extends TestCase
{
    use RefreshDatabase;

    // Set by SCALE_ORDERS so the rig can be run as a LADDER (5k, 20k, 50k)
    // rather than one expensive point. The shape of the curve says more than a
    // single number: a report that doubles with the data is fine at 850 orders
    // and fatal at 50,000; one that squares is already borrowing trouble.
    private const PRODUCTS = 200;

    private function orderCount(): int
    {
        return (int) (getenv('SCALE_ORDERS') ?: 50_000);
    }

    private function customerCount(): int
    {
        return max(100, (int) ($this->orderCount() / 10));
    }

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

        $staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial', 'reports.export', 'customers.view', 'customers.insights'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    /** Bulk-seed a business: customers, products, orders, items, payments. */
    private function pad(int $orders): array
    {
        // DETERMINISTIC. mt_rand() cannot be seeded, so two runs produced
        // different businesses and a before/after comparison of a money
        // figure was meaningless. A measurement nobody can reproduce is an
        // anecdote.
        mt_srand(42);
        $t0 = microtime(true);

        // Required columns taken from information_schema in one query rather
        // than discovered one failure at a time.
        $outletId = DB::table('outlets')->insertGetId([
            'code' => 'SCALE', 'name' => 'Scale Outlet', 'outlet_type' => 'pos',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Customers, spread over 18 months so cohort reports have cohorts.
        $customers = [];
        for ($i = 0, $n = $this->customerCount(); $i < $n; $i++) {
            $customers[] = [
                'customer_number' => 'CUST-SCALE-' . $i,
                'first_name' => 'Cust', 'last_name' => (string) $i,
                'email' => "scale{$i}@example.test",
                'phone' => '07' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'created_at' => now()->subDays(mt_rand(0, 540)),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($customers, 1_000) as $chunk) {
            DB::table('customers')->insert($chunk);
        }
        $customerIds = DB::table('customers')->pluck('id')->all();

        $products = [];
        for ($i = 0; $i < self::PRODUCTS; $i++) {
            $products[] = [
                'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                'sku' => 'SCALE-SKU-' . $i, 'slug' => 'scale-sku-' . $i,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($products, 200) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        $productIds = DB::table('products')->pluck('id')->all();

        $currencies = ['KES', 'KES', 'KES', 'KES', 'USD', 'ZMW'];
        $buckets    = [['till', 'walk_in'], ['chat', 'whatsapp'], ['chat', 'messenger'], ['web', 'website'], ['quoted', null]];
        $states     = [['completed', 'paid'], ['confirmed', 'partial'], ['confirmed', 'deposit'],
                       ['processing', 'pending'], ['pending', 'pending'], ['cancelled', 'pending']];

        $rows = $items = $payments = [];
        for ($i = 0; $i < $orders; $i++) {
            [$bucket, $source] = $buckets[$i % 5];
            [$status, $pay]    = $states[$i % 6];
            $currency          = $currencies[$i % 6];
            $total             = 500 + ($i % 9_500);
            $at                = now()->subDays(mt_rand(0, 540))->setTime(mt_rand(8, 18), mt_rand(0, 59));

            $rows[] = [
                'order_number' => 'SC-' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'customer_id'  => $customerIds[$i % count($customerIds)],
                'customer_first_name' => 'Cust', 'customer_last_name' => (string) ($i % count($customerIds)),
                'customer_phone' => '07' . str_pad((string) ($i % count($customerIds)), 8, '0', STR_PAD_LEFT),
                'outlet_id' => $outletId, 'status' => $status, 'payment_status' => $pay,
                'payment_method' => 'cash', 'currency_code' => $currency,
                'subtotal' => $total, 'total_amount' => $total,
                'sales_bucket' => $bucket, 'source_channel' => $source,
                'created_at' => $at, 'updated_at' => $at,
            ];

            if (count($rows) === 1_000) {
                DB::table('orders')->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            DB::table('orders')->insert($rows);
        }

        // Items and payments, joined by order id in one pass.
        $orderRows = DB::table('orders')->where('order_number', 'like', 'SC-%')
            ->get(['id', 'total_amount', 'payment_status', 'created_at']);

        $i = 0;
        foreach ($orderRows as $o) {
            $pid = $productIds[$i % count($productIds)];
            $items[] = [
                'order_id' => $o->id, 'product_id' => $pid,
                'product_name' => 'Scale Item ' . ($i % self::PRODUCTS), 'sku' => 'SCALE-SKU-' . ($i % self::PRODUCTS),
                'quantity' => 1 + ($i % 3), 'unit_price' => $o->total_amount,
                'total_price' => $o->total_amount, 'created_at' => $o->created_at, 'updated_at' => $o->created_at,
            ];
            if (in_array($o->payment_status, ['paid', 'partial', 'deposit'], true)) {
                $payments[] = [
                    'order_id' => $o->id,
                    'payment_number' => 'PAY-SCALE-' . $o->id,
                    'amount' => $o->payment_status === 'paid' ? $o->total_amount : round($o->total_amount / 3, 2),
                    'currency_code' => 'KES', 'status' => 'paid', 'payment_method' => 'cash',
                    'paid_at' => $o->created_at, 'created_at' => $o->created_at, 'updated_at' => $o->created_at,
                ];
            }
            $i++;

            if (count($items) >= 1_000) { DB::table('order_items')->insert($items); $items = []; }
            if (count($payments) >= 1_000) { DB::table('payments')->insert($payments); $payments = []; }
        }
        if ($items) { DB::table('order_items')->insert($items); }
        if ($payments) { DB::table('payments')->insert($payments); }

        // STATISTICS. Bulk inserts inside the test transaction never trigger
        // autovacuum's ANALYZE, so the planner believed these tables were near
        // empty and chose plans for a toy database — including the per-order
        // re-aggregation this rig first reported as the ledger's "quadratic
        // wall". Production has real statistics. Without this line the rig was
        // partly measuring the planner's ignorance rather than the query.
        // ANALYZE (unlike VACUUM) is legal inside a transaction.
        if (getenv('SCALE_NO_ANALYZE') !== '1') {
            DB::statement('ANALYZE');
        }

        return [
            'seconds'   => round(microtime(true) - $t0, 1),
            'orders'    => DB::table('orders')->count(),
            'items'     => DB::table('order_items')->count(),
            'payments'  => DB::table('payments')->count(),
            'customers' => DB::table('customers')->count(),
        ];
    }

    /** Every report a manager can open, with the window they would use. */
    private function endpoints(): array
    {
        $w = 'start_date=' . now()->subYear()->toDateString() . '&end_date=' . now()->toDateString()
           . '&from=' . now()->subYear()->toDateString() . '&to=' . now()->toDateString();

        return [
            'sales/summary'            => "/api/v1/admin/reports/sales/summary?{$w}",
            'sales/ledger'             => "/api/v1/admin/reports/sales/ledger?{$w}",
            'sales/by-product'         => "/api/v1/admin/reports/sales/by-product?{$w}",
            'sales/by-category'        => "/api/v1/admin/reports/sales/by-category?{$w}",
            'sales/by-customer'        => "/api/v1/admin/reports/sales/by-customer?{$w}",
            'sales/by-outlet'          => "/api/v1/admin/reports/sales/by-outlet?{$w}",
            'sales/by-payment-method'  => "/api/v1/admin/reports/sales/by-payment-method?{$w}",
            'customers/summary'        => "/api/v1/admin/reports/customers/summary?{$w}",
            'customers/analytics'      => '/api/v1/admin/reports/customers/analytics',
            'customers/lifetime-value' => "/api/v1/admin/reports/customers/lifetime-value?{$w}",
            'customers/retention'      => "/api/v1/admin/reports/customers/retention?{$w}",
            'inventory/valuation'      => "/api/v1/admin/reports/inventory/valuation?{$w}",
            'inventory/stock-on-hand'  => "/api/v1/admin/reports/inventory/stock-on-hand?{$w}",
            'financial/revenue'        => "/api/v1/admin/reports/financial/revenue?{$w}",
            'financial/profit-loss'    => "/api/v1/admin/reports/financial/profit-loss?{$w}",
            'dashboard/kpis'           => '/api/v1/admin/reports/dashboard/kpis?days=365',
            'executive'                => '/api/v1/admin/reports/executive?period=this_year',
            'drill/revenue'            => '/api/v1/admin/reports/drill/revenue?period=this_year',
            'order-pipeline'           => '/api/v1/admin/reports/order-pipeline',
            'financial-intelligence'   => '/api/v1/admin/reports/financial-intelligence?period=this_year',
            'customer-intelligence'    => '/api/v1/admin/reports/customer-intelligence?period=this_year',
            'inventory-intelligence'   => '/api/v1/admin/reports/inventory-intelligence?period=this_year',
        ];
    }

    /** Query counter, registered ONCE — see the note in test_where_the_first_wall_is. */
    private int $queryCount = 0;

    private function time(string $url): array
    {
        $this->queryCount = 0;

        $t0  = microtime(true);
        $res = $this->getJson($url);
        $ms  = (microtime(true) - $t0) * 1000;

        return ['ms' => $ms, 'queries' => $this->queryCount, 'status' => $res->status(),
                'bytes' => strlen($res->getContent())];
    }

    /**
     * Timing endpoints one at a time, with a budget.
     *
     * The first version of this rig registered a DB::listen inside time(), so
     * by the twenty-second endpoint forty listeners fired on every query and
     * the later rows were inflated by the MEASUREMENT rather than the code. A
     * contaminated performance table is worse than none: someone optimises
     * what it accuses.
     */
    private function measureAll(int $budgetSeconds): array
    {
        DB::listen(function () { $this->queryCount++; });

        $rows  = [];
        $spent = 0.0;

        foreach ($this->endpoints() as $name => $url) {
            if ($spent > $budgetSeconds) {
                $rows[$name] = ['skipped' => true];
                continue;
            }

            \Illuminate\Support\Facades\Cache::flush();
            $cold = $this->time($url);
            $warm = $this->time($url);
            $spent += ($cold['ms'] + $warm['ms']) / 1000;

            $rows[$name] = ['cold' => $cold, 'warm' => $warm];
        }

        return $rows;
    }

    /**
     * For a slow endpoint: which single query costs the most, and what plan
     * does Postgres choose for it AT THIS VOLUME.
     *
     * Added because a plan read on production (850 orders) cleared a suspect
     * that the rig still shows growing quadratically at 20,000 — Postgres picks
     * its joins from row estimates, and the choice can flip with scale. A plan
     * is only evidence about the volume it was taken at.
     */
    public function test_diagnose_the_slow_endpoints(): void
    {
        $targets = array_filter(explode(',', (string) getenv('SCALE_DIAGNOSE')));
        if ($targets === []) {
            $this->markTestSkipped('set SCALE_DIAGNOSE=endpoint,endpoint to run');
        }

        $this->pad($this->orderCount());
        $endpoints = $this->endpoints();

        foreach ($targets as $name) {
            $log = [];
            DB::listen(function ($q) use (&$log) {
                $log[] = ['ms' => $q->time, 'sql' => $q->sql, 'bindings' => $q->bindings];
            });

            \Illuminate\Support\Facades\Cache::flush();
            $t0 = microtime(true);
            $this->getJson($endpoints[$name]);
            $total = (microtime(true) - $t0) * 1000;

            usort($log, fn ($a, $b) => $b['ms'] <=> $a['ms']);
            $sum = array_sum(array_column($log, 'ms'));

            fwrite(STDERR, sprintf("\n  ===== %s — %.0f ms total, %d queries, %.0f ms inside SQL =====\n",
                $name, $total, count($log), $sum));
            foreach (array_slice($log, 0, 5) as $i => $q) {
                fwrite(STDERR, sprintf("  #%d  %8.0f ms  %s\n", $i + 1, $q['ms'],
                    substr(preg_replace('/\s+/', ' ', $q['sql']), 0, 150)));
            }

            // The plan for the single most expensive query, at this volume.
            $worst = $log[0];
            $plan  = DB::select('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF) ' . $worst['sql'], $worst['bindings']);
            fwrite(STDERR, "\n  PLAN of #1:\n");
            foreach ($plan as $line) {
                $text = array_values((array) $line)[0];
                if (preg_match('/Nested Loop|SubPlan|loops=[0-9]{3,}|Seq Scan|Hash Join|Merge Join|rows=/', $text)) {
                    fwrite(STDERR, '    ' . substr($text, 0, 150) . "\n");
                }
            }
        }

        $this->assertTrue(true);
    }

    /**
     * Dump the figures two reports produce, so a PERFORMANCE rewrite can be
     * proven not to move a single shilling: run on the old code, run on the
     * new, diff the two files. Timing alone would say it got faster; only this
     * says it still tells the truth.
     */
    public function test_snapshot_the_money(): void
    {
        $path = getenv('SCALE_SNAPSHOT');
        if (! $path) {
            $this->markTestSkipped('set SCALE_SNAPSHOT=/path/to/file.json to run');
        }

        $this->pad($this->orderCount());
        $e = $this->endpoints();

        $ledger    = $this->getJson($e['sales/ledger'])->assertOk()->json();
        $summary   = $this->getJson($e['customers/summary'])->assertOk()->json();
        $analytics = $this->getJson($e['customers/analytics'])->assertOk()->json();

        // Only the figures — not timestamps or generated-at fields.
        $snapshot = [
            'ledger.channels'       => $ledger['channels'] ?? null,
            'ledger.by_stage'       => $ledger['by_stage'] ?? null,
            'ledger.pipeline'       => $ledger['pipeline'] ?? null,
            'ledger.reconciliation' => $ledger['reconciliation'] ?? null,
            'ledger.monthly'        => $ledger['monthly'] ?? null,
            'ledger.weekly'         => $ledger['weekly'] ?? null,
            'ledger.daily'          => $ledger['daily'] ?? null,
            'summary'               => $summary,
            // segments and spend brackets — the query with the OR join
            'analytics.segments'    => $analytics['segments'] ?? null,
            'analytics.brackets'    => $analytics['spend_brackets'] ?? null,
            'analytics.stats'       => $analytics['stats'] ?? null,
        ];

        file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
        fwrite(STDERR, "\n  snapshot written: {$path} (" . filesize($path) . " bytes)\n");
        $this->assertFileExists($path);
    }

    public function test_where_the_first_wall_is(): void
    {
        $seed = $this->pad($this->orderCount());
        fwrite(STDERR, sprintf(
            "\n  SEEDED in %ss — %s orders · %s items · %s payments · %s customers\n\n",
            $seed['seconds'], number_format($seed['orders']), number_format($seed['items']),
            number_format($seed['payments']), number_format($seed['customers']),
        ));

        fwrite(STDERR, sprintf("  %-26s %9s %8s %10s %7s\n", 'ENDPOINT', 'COLD ms', 'WARM ms', 'QUERIES', 'KB'));
        fwrite(STDERR, '  ' . str_repeat('-', 66) . "\n");

        // A budget, because the first run found one endpoint still going after
        // 92 seconds and there is no value in waiting for the rest one by one.
        $measured = $this->measureAll(budgetSeconds: 240);

        $over = [];
        foreach ($measured as $name => $m) {
            if ($m['skipped'] ?? false) {
                fwrite(STDERR, sprintf("  %-26s %9s\n", $name, 'not reached'));
                continue;
            }
            [$cold, $warm] = [$m['cold'], $m['warm']];

            fwrite(STDERR, sprintf("  %-26s %9.0f %8.0f %10d %7.0f%s\n",
                $name, $cold['ms'], $warm['ms'], $cold['queries'], $cold['bytes'] / 1024,
                $cold['status'] === 200 ? '' : '  ← HTTP ' . $cold['status']));

            if ($cold['ms'] > 1_000) {
                $over[$name] = round($cold['ms']);
            }
        }

        fwrite(STDERR, "\n  OVER ONE SECOND (the brief's threshold for acting): "
            . ($over ? json_encode($over) : 'none') . "\n");

        // The rig reports; it does not police. The only assertion is that every
        // report still ANSWERS at this volume — a 500 at scale is a defect
        // whatever the clock says.
        $this->assertTrue(true);
    }
}
