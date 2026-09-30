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
 * D4: one date contract, whichever spelling the caller knows.
 *
 * Reports grew three conventions — `from`/`to` on the executive endpoints,
 * `start_date`/`end_date` on the legacy ones, `period` on the intelligence
 * ones — and the wrong pair did not fail. It returned a DIFFERENT window,
 * with a 200 and a plausible figure: the legacy endpoints fell back to the
 * last 30 days, the intelligence endpoints to this month. A link copied from
 * one page of Reports into another therefore answered a question nobody
 * asked, and nothing on screen said so.
 *
 * That is the worst kind of defect in this module: not a number that looks
 * wrong, but a number that looks right and is about a different month.
 *
 * Seeded so the two candidate windows cannot be confused: 1,000,000 long ago
 * (outside any default), 7 in the last few days (inside every default).
 */
class ReportDateContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);

        $this->order(1_000_000, now()->subMonths(8));   // only a wide window sees this

        // NOW, not yesterday. This was `now()->subDay()`, which is in THIS
        // month for all but a few hours a month — and CI ran at 21:08 UTC on
        // 30 September, which is 00:08 on 1 October in Africa/Nairobi, so
        // "yesterday" fell in the previous month and `period=this_month`
        // correctly found nothing. The test was wrong, not the report.
        //
        // `now()` is inside this month, inside the last 30 days and inside
        // every wide window by construction, on every day of every month.
        $this->order(7, now());                        // every default window sees this
    }

    private function order(float $total, \DateTimeInterface $at): void
    {
        $order = Order::create([
            'order_number'   => 'DC-' . bin2hex(random_bytes(4)),
            'status'         => 'completed',
            'payment_status' => 'paid',
            'currency_code'  => 'KES',
            'subtotal'       => $total,
            'total_amount'   => $total,
        ]);
        $order->forceFill(['created_at' => $at])->saveQuietly();
    }

    private function summaryRevenue(string $query): float
    {
        return (float) $this->getJson("/api/v1/admin/reports/sales/summary?{$query}")
            ->assertOk()->json('summary.total_revenue');
    }

    public function test_a_legacy_report_honours_the_executive_spelling(): void
    {
        $wide = 'from=' . now()->subYear()->toDateString() . '&to=' . now()->toDateString();

        // Before: `from`/`to` were ignored here and the last 30 days answered,
        // so this read 7 — a plausible number for the wrong question.
        $this->assertSame(1_000_007.0, $this->summaryRevenue($wide),
            'the window the caller asked for, not the default');
    }

    public function test_a_legacy_report_still_honours_its_own_spelling(): void
    {
        $wide = 'start_date=' . now()->subYear()->toDateString() . '&end_date=' . now()->toDateString();

        $this->assertSame(1_000_007.0, $this->summaryRevenue($wide));
    }

    public function test_when_a_caller_sends_both_the_explicit_legacy_pair_wins(): void
    {
        $mixed = 'start_date=' . now()->subYear()->toDateString() . '&end_date=' . now()->toDateString()
               . '&from=' . now()->subDay()->toDateString() . '&to=' . now()->toDateString();

        $this->assertSame(1_000_007.0, $this->summaryRevenue($mixed), 'one of them must win, predictably');
    }

    public function test_the_default_window_is_unchanged_when_no_dates_are_given(): void
    {
        // Nothing here should widen the default by accident: the last 30 days
        // still means the last 30 days.
        $this->assertSame(7.0, $this->summaryRevenue(''));
    }

    public function test_an_intelligence_report_stops_ignoring_an_explicit_window(): void
    {
        // These endpoints read `period` and ignored from/to unless
        // period=custom was also sent, so a caller who supplied exactly the
        // dates they wanted was answered for this month instead.
        $wide = 'from=' . now()->subYear()->toDateString() . '&to=' . now()->toDateString();

        $revenue = (float) $this->getJson("/api/v1/admin/reports/executive?{$wide}")
            ->assertOk()->json('kpis.sales.revenue.current');

        $this->assertSame(1_000_007.0, $revenue, 'the window asked for, not this month');
    }

    public function test_an_explicit_period_still_wins_over_the_dates(): void
    {
        // period=this_month with dates attached is a caller asking for this
        // month; the normalisation must not overrule them.
        $wide = 'period=this_month&from=' . now()->subYear()->toDateString() . '&to=' . now()->toDateString();

        $revenue = (float) $this->getJson("/api/v1/admin/reports/executive?{$wide}")
            ->assertOk()->json('kpis.sales.revenue.current');

        $this->assertSame(7.0, $revenue, 'this month, as asked');
    }

    public function test_the_window_that_answered_is_stated_in_the_payload(): void
    {
        // Whatever the contract, the reader must be able to see which window
        // produced the number rather than infer it.
        $body = $this->getJson('/api/v1/admin/reports/sales/summary?from=2026-01-01&to=2026-03-31')
            ->assertOk()->json();

        $this->assertSame('2026-01-01', substr($body['period']['start'], 0, 10));
        $this->assertSame('2026-03-31', substr($body['period']['end'], 0, 10));
    }

    public function test_a_timestamp_end_does_not_break_the_query(): void
    {
        // Appending end-of-day to a full timestamp produced an invalid date.
        $this->getJson('/api/v1/admin/reports/sales/summary?start_date=2026-01-01&end_date=2026-03-31 14:00:00')
            ->assertOk();
    }
}
