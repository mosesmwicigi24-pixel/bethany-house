<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cycle 8 — failure recovery. What does a report do when it is handed rubbish?
 *
 * A report URL is not only typed by the console. It is bookmarked, pasted
 * between colleagues, edited by hand, and replayed months later after the
 * parameter names have changed (D4 was exactly that). So every endpoint must
 * answer malformed input with a 4xx that says what was wrong — never a 500,
 * which reads as "the reports are broken" and, worse, can leak a stack trace.
 *
 * A 500 here is the only failure that matters. A 422 is a correct refusal; a
 * 200 with an empty or default answer is acceptable where the input was merely
 * odd (an outlet with no orders). What is NOT acceptable is the request
 * reaching the database with a value Postgres cannot parse.
 */
class ReportHostileInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial', 'reports.export', 'customers.view', 'customers.insights'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);

        // A little real data, so a query that survives parsing has rows to touch.
        Order::create(['order_number' => 'HI-1', 'status' => 'completed', 'payment_status' => 'paid',
                       'currency_code' => 'KES', 'subtotal' => 1_000, 'total_amount' => 1_000]);
    }

    public static function endpoints(): array
    {
        return [
            'sales/summary', 'sales/ledger', 'sales/by-product', 'sales/by-category',
            'sales/by-customer', 'sales/by-outlet', 'sales/by-payment-method', 'sales/returns',
            'customers/summary', 'customers/analytics', 'customers/lifetime-value', 'customers/retention',
            'inventory/valuation', 'inventory/stock-on-hand',
            'financial/revenue', 'financial/profit-loss',
            'dashboard/kpis', 'executive', 'order-pipeline',
            'financial-intelligence', 'customer-intelligence', 'inventory-intelligence',
        ];
    }

    /** Thirteen ways a real URL goes wrong. */
    public static function hostile(): array
    {
        return [
            'garbage start date'        => 'start_date=yesterday-ish&end_date=2026-09-30',
            'impossible calendar date'  => 'start_date=2026-13-45&end_date=2026-12-31',
            'zero date'                 => 'start_date=0000-00-00&end_date=2026-12-31',
            'garbage end date'          => 'start_date=2026-01-01&end_date=soon',
            'executive spelling broken' => 'from=2026-01-01&to=not-a-date',
            'sql in a date'             => "start_date=2026-01-01';DROP TABLE orders;--&end_date=2026-12-31",
            'unknown currency'          => 'currency_code=XYZ',
            'empty currency'            => 'currency_code=',
            'outlet that does not exist' => 'outlet_id=999999',
            'outlet that is not a number' => 'outlet_id=abc',
            'nonsense period'           => 'period=fortnight-ish',
            'negative limit and days'   => 'limit=-5&days=-5',
            'absurd page'               => 'page=-1',
        ];
    }

    /** Every (endpoint, hostile input) pair as its own case — see below. */
    public static function pairs(): array
    {
        $cases = [];
        foreach (self::endpoints() as $endpoint) {
            foreach (self::hostile() as $label => $query) {
                $cases["{$endpoint} · {$label}"] = [$endpoint, $query];
            }
        }

        return $cases;
    }

    /**
     * ONE request per test, deliberately.
     *
     * The first version sent all 286 requests from a single test, and
     * RefreshDatabase wraps a test in ONE Postgres transaction. The first
     * request that genuinely broke a query left that transaction ABORTED —
     * "commands ignored until end of transaction block" — and every request
     * after it failed too. It reported 120 server errors, most of them
     * cascades from one or two real ones. A probe that lets one failure
     * manufacture a hundred is measuring itself.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pairs')]
    public function test_malformed_input_never_reaches_the_server_as_an_error(string $endpoint, string $query): void
    {
        $status = $this->getJson("/api/v1/admin/reports/{$endpoint}?{$query}")->status();

        $this->assertLessThan(500, $status,
            "{$endpoint} answered malformed input with a server error ({$status})");
    }

    /**
     * "Not a 500" is the floor, not the answer. A malformed date must be
     * REFUSED — a 200 would mean the report quietly answered for some other
     * window — and the refusal must name the field without echoing the payload.
     */
    public function test_a_malformed_date_is_refused_by_name_without_echoing_it(): void
    {
        foreach (['sales/summary', 'executive', 'financial-intelligence'] as $endpoint) {
            foreach (["start_date=2026-01-01';DROP TABLE orders;--", 'start_date=2026-13-45', 'from=0000-00-00'] as $q) {
                $res = $this->getJson("/api/v1/admin/reports/{$endpoint}?{$q}&end_date=2026-12-31")
                    ->assertStatus(422);

                $body = $res->getContent();
                $this->assertStringContainsString('YYYY-MM-DD', $body, "{$endpoint} · {$q}");
                $this->assertStringNotContainsString('DROP', $body, 'the payload is not reflected');
                $this->assertStringNotContainsString('SQLSTATE', $body, 'no database error leaks');
            }
        }
    }

    public function test_an_outlet_that_is_not_a_number_is_refused_but_an_unknown_one_is_an_empty_answer(): void
    {
        $this->getJson('/api/v1/admin/reports/sales/ledger?outlet_id=abc')->assertStatus(422);
        $this->getJson('/api/v1/admin/reports/inventory/stock-on-hand?outlet_id=1;DROP')->assertStatus(422);
        $this->getJson('/api/v1/admin/reports/sales/ledger?outlet_id=999999')->assertOk();
    }

    /**
     * The shapes real callers send keep working, and a timestamp is read as
     * its calendar day: the order placed today is inside "today, 14:00".
     */
    public function test_legitimate_date_shapes_still_answer_for_the_right_window(): void
    {
        $today = now()->format('Y-m-d');

        foreach ([$today, "{$today} 14:00:00", "{$today}T14:00:00.000Z", "{$today}T00:00:00+03:00"] as $end) {
            $res = $this->getJson('/api/v1/admin/reports/sales/summary?' . http_build_query([
                'start_date' => $today, 'end_date' => $end,
            ]))->assertOk();

            $this->assertSame(1, (int) $res->json('summary.total_orders'), "end_date={$end}");
        }
    }
}
