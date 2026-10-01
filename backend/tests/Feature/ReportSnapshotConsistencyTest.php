<?php

namespace Tests\Feature;

use App\Http\Middleware\ReadsOneSnapshot;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cycle 8 — concurrency. Does one report describe one moment?
 *
 * The sales ledger computes its recognised total from a pass per channel, then
 * its daily series in a separate statement. A till confirming an order between
 * the two puts it in the days and not in the total, and the page contradicts
 * itself. This test makes that happen on purpose: a SECOND database connection
 * commits an order the instant the per-channel pass has finished.
 *
 * NO RefreshDatabase, deliberately. That trait runs every test inside one open
 * transaction, whose isolation was fixed when it began, so ReadsOneSnapshot
 * would (correctly) stand aside and the test would prove nothing. Everything
 * here is committed for real and removed in tearDown.
 *
 * The second test switches the middleware off and REQUIRES the contradiction.
 * Without it, a green first test could simply mean the probe never fired.
 */
class ReportSnapshotConsistencyTest extends TestCase
{
    private const MARK = 'SNAP-PROBE-';

    private ?User $staff = null;

    /** @var list<string> permissions this test created, and so must remove */
    private array $createdPermissions = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Forward-only and idempotent: a no-op on a migrated database.
        $this->artisan('migrate', ['--force' => true]);

        config(['database.connections.writer' => config('database.connections.' . config('database.default'))]);

        $this->staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial'] as $name) {
            if (! Permission::where(['name' => $name, 'guard_name' => 'sanctum'])->exists()) {
                $this->createdPermissions[] = $name;
            }
            $this->staff->givePermissionTo(Permission::findOrCreate($name, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->staff);

        // The staff-request logger writes to request_logs, which is sealed
        // append-only (#364): a row it commits here could never be removed,
        // and would outlive this test into every test after it. Who-looked-at-
        // what is not under test.
        $this->withoutMiddleware(\App\Http\Middleware\AuditStaffRequests::class);

        Order::create($this->order('1', 1_000));
    }

    protected function tearDown(): void
    {
        DB::connection()->rollBack(0);
        DB::table('orders')->where('order_number', 'like', self::MARK . '%')->delete();

        if ($this->staff) {
            DB::table('model_has_permissions')->where('model_id', $this->staff->id)
                ->where('model_type', $this->staff->getMorphClass())->delete();
            DB::table('users')->where('id', $this->staff->id)->delete();
        }
        if ($this->createdPermissions) {
            DB::table('permissions')->whereIn('name', $this->createdPermissions)
                ->where('guard_name', 'sanctum')->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        parent::tearDown();
    }

    private function order(string $suffix, int $amount): array
    {
        return [
            'order_number'   => self::MARK . $suffix . '-' . uniqid(),
            'order_type'     => 'pos',
            'status'         => 'completed',
            'payment_status' => 'paid',
            'currency_code'  => 'KES',
            'subtotal'       => $amount,
            'total_amount'   => $amount,
        ];
    }

    /**
     * Commit an order through the second connection once the ledger's
     * per-channel pass (one orders query per reporting channel) is done.
     */
    private function commitAnOrderMidRequest(): void
    {
        $seen     = 0;
        $channels = count(Order::REPORTING_CHANNELS);
        $fired    = false;

        DB::listen(function (QueryExecuted $q) use (&$seen, &$fired, $channels) {
            if ($fired || $q->connectionName === 'writer' || ! str_contains($q->sql, 'from "orders"')) {
                return;
            }
            if (++$seen === $channels) {
                $fired = true;
                DB::connection('writer')->table('orders')->insert($this->order('LATE', 50_000) + [
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    private function ledger(): array
    {
        $today = now()->format('Y-m-d');

        return $this->getJson("/api/v1/admin/reports/sales/ledger?start_date={$today}&end_date={$today}")
            ->assertOk()->json();
    }

    public function test_every_figure_on_the_ledger_describes_the_same_moment(): void
    {
        $this->commitAnOrderMidRequest();
        $r = $this->ledger();

        $total = (float) $r['reconciliation']['recognised_sales'];
        $days  = (float) collect($r['daily'])->sum('sales');

        $this->assertSame($total, $days, 'the daily series sums to the total on the same page');
        $this->assertSame(1_000.0, $total, 'the order committed mid-request is in neither: it is after the snapshot');
    }

    /**
     * The snapshot's own trap. Production's cache store is the DATABASE, and
     * MetricEngine caches through Cache::remember. If the cache wrote on the
     * request's connection, its upsert would run inside the REPEATABLE READ
     * transaction — and two people opening the same report at once, both
     * missing the cache, would collide: the second upsert meets a row
     * committed after its snapshot, and Postgres refuses it ("could not
     * serialize access"). A 500, from the fix for a silent inconsistency.
     *
     * The suite's cache is the array store, so nothing else here would ever
     * see it. This test switches to the database store, lets the second
     * connection fill the key mid-request, and requires the report to survive.
     */
    public function test_a_cache_filled_by_someone_else_mid_request_does_not_fail_the_report(): void
    {
        config(['cache.default' => 'database']);
        $key = config('cache.prefix') . 'metrics:attach_rates:all:180';
        DB::table('cache')->where('key', $key)->delete();

        $fired = false;
        DB::listen(function (QueryExecuted $q) use (&$fired, $key) {
            // The attach-rates mining pass is raw SQL (FROM orders o), not the
            // query builder's quoted "orders" — the first version of this
            // probe never fired, and only the assertion below said so.
            if ($fired || $q->connectionName === 'writer' || ! preg_match('/from\s+"?orders"?\s/i', $q->sql)) {
                return;
            }
            $fired = true;
            DB::connection('writer')->table('cache')->insert([
                'key' => $key, 'value' => serialize(['someone' => 'else']), 'expiration' => time() + 600,
            ]);
        });

        try {
            $this->getJson('/api/v1/admin/reports/attach-rates')->assertOk();
            $this->assertTrue($fired, 'the competing cache write happened mid-request');
        } finally {
            DB::table('cache')->where('key', $key)->delete();
        }
    }

    /** Cycle 9: no report query may run unbounded — the ceiling is set inside every snapshot. */
    public function test_every_report_request_carries_a_query_ceiling(): void
    {
        $seen = [];
        DB::listen(function (QueryExecuted $q) use (&$seen) {
            if (stripos($q->sql, 'statement_timeout') !== false) {
                $seen[] = $q->sql;
            }
        });

        $this->ledger();

        $this->assertCount(1, $seen, 'set once, for this request');
        $this->assertStringContainsString(\App\Http\Middleware\ReadsOneSnapshot::QUERY_CEILING, $seen[0]);
        $this->assertStringContainsString('SET LOCAL', $seen[0], 'LOCAL: it dies with the transaction');
    }

    /**
     * A query past the ceiling is an answer the reader can act on, not a
     * "Server Error": 503 and "narrow the range". Forced deterministically —
     * on the first report query the ceiling is lowered to 100ms and a 500ms
     * sleep runs inside the same snapshot.
     */
    public function test_a_report_that_runs_past_the_ceiling_says_so_and_cleans_up(): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $q) use (&$fired) {
            if ($fired || ! preg_match('/from\s+"?orders"?\s/i', $q->sql)) {
                return;
            }
            $fired = true;
            DB::statement("SET LOCAL statement_timeout = '100ms'");
            DB::select('SELECT pg_sleep(0.5)');
        });

        $today = now()->format('Y-m-d');
        $res   = $this->getJson("/api/v1/admin/reports/sales/ledger?start_date={$today}&end_date={$today}");

        $this->assertTrue($fired, 'the slow query ran');
        $res->assertStatus(503)->assertJsonPath('reason', 'report_timeout');
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertSame(0, DB::transactionLevel(), 'the snapshot was rolled back, not left open');
    }

    public function test_without_the_snapshot_the_same_race_makes_the_page_contradict_itself(): void
    {
        $this->withoutMiddleware(ReadsOneSnapshot::class);
        $this->commitAnOrderMidRequest();
        $r = $this->ledger();

        $total = (float) $r['reconciliation']['recognised_sales'];
        $days  = (float) collect($r['daily'])->sum('sales');

        $this->assertSame(1_000.0, $total, 'the per-channel pass ran before the late order');
        $this->assertSame(51_000.0, $days, 'the daily pass ran after it — the probe fired');
    }
}
