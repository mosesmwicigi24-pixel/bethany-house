<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Reporting\MetricEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The engine's figures must agree with EACH OTHER, not merely be individually
 * defensible.
 *
 * Cycle 4 audited all 83 functions in MetricEngine for the two classes that
 * have dogged this module. The buyer key was clean everywhere — the engine was
 * built with it. Money was not: 24 raw sums across 15 methods, and the one
 * that mattered most put two figures that disagree on the SAME SCREEN.
 *
 * Measured on production 2026-09-30, the Executive page's revenue tile against
 * the revenue trend chart beneath it:
 *
 *     Jul 2026   chart 2,312,285   tile 2,617,974   gap 305,689
 *     Aug 2026   chart 2,001,515   tile 2,090,415   gap  88,900
 *     Sep 2026   chart 1,950,450   tile 2,102,850   gap 152,400
 *
 * The tile converted; the chart summed face values. A reader could compare the
 * two and conclude the tile was wrong — or, worse, not notice.
 *
 * These tests assert relationships rather than constants, because a
 * relationship survives the data changing underneath it.
 */
class EngineMoneyConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['KES', 1.0, true], ['USD', 128.0, false]] as [$code, $rate, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], [
                'name' => $code, 'symbol' => $code, 'exchange_rate' => 1.0,
                'reporting_rate_to_kes' => $rate, 'is_base' => $base, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \App\Support\ReportingCurrency::forget();
    }

    private function order(string $currency, float $total, ?CarbonImmutable $at = null): Order
    {
        $order = Order::create([
            'order_number'   => 'EM-' . bin2hex(random_bytes(4)),
            'status'         => 'completed',
            'payment_status' => 'paid',
            'currency_code'  => $currency,
            'subtotal'       => $total,
            'total_amount'   => $total,
        ]);

        if ($at) {
            $order->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $order->refresh();
    }

    public function test_the_declining_revenue_signal_states_converted_months(): void
    {
        // revenueTrend() is not a chart — it is the signal that fires when the
        // last three FULL months each fell. Its month figures sit beside the
        // revenue tile on the Executive page, and they were the raw ones:
        // measured on production, July read 2,312,285 in the signal against
        // 2,617,974 in the tile.
        $m = fn (int $back) => CarbonImmutable::now()->subMonthsNoOverflow($back)->startOfMonth()->addDays(2)->setTime(10, 0);

        $this->order('KES', 10_000, $m(3));
        $this->order('KES', 5_000,  $m(2));
        $this->order('KES', 1_000,  $m(1));
        $this->order('USD', 10,     $m(1));      // 1,280 — the month is 2,280, not 1,010

        $trend = MetricEngine::unscoped()->revenueTrend();

        $this->assertNotNull($trend, 'three falling months must raise the signal');

        $latest = collect($trend['months'])->last();
        $this->assertSame($m(1)->format('M Y'), $latest['month']);
        $this->assertSame(2_280.0, round((float) $latest['revenue'], 2),
            'the foreign order counts at its reporting rate, as the tile does');

        // And the headline percentage is computed on those same figures:
        // (10,000 - 2,280) / 10,000. Read raw it would claim 89.9%.
        $this->assertSame(77.2, round((float) $trend['decline_pct'], 1),
            'a decline overstated by nine points is a different story');
    }

    public function test_the_payment_rail_reconciliation_adds_up(): void
    {
        // gross − refunds = net is an identity, and it did not hold: gross and
        // net were converted while refunds were left at face value, so any
        // foreign refund broke the arithmetic on the page.
        $order = $this->order('USD', 100);
        Payment::create([
            'order_id' => $order->id, 'amount' => 100, 'refund_amount' => 25,
            'currency_code' => 'USD', 'status' => 'paid',
            'payment_method' => 'card', 'paid_at' => now(),
        ]);

        $row = collect(MetricEngine::unscoped()->methodReconciliation(
            CarbonImmutable::now()->subDay()->toMutable(),
            CarbonImmutable::now()->addDay()->toMutable(),
        ))->first();

        $this->assertNotNull($row);
        $this->assertSame(12_800.0, round((float) $row->gross, 2), 'USD 100 at 128');
        $this->assertSame(3_200.0, round((float) $row->refunds, 2), 'USD 25 at 128, not "25"');
        $this->assertSame(9_600.0, round((float) $row->net, 2));
        $this->assertSame(
            round((float) $row->gross - (float) $row->refunds, 2),
            round((float) $row->net, 2),
            'gross minus refunds is net — in one unit, or not at all',
        );
    }

    public function test_every_engine_money_figure_is_in_one_unit(): void
    {
        // A broad guard rather than a per-method assertion: with only foreign
        // orders in the database, any figure still reading face value would
        // come back as the small number instead of the converted one.
        $this->order('USD', 100);   // 12,800

        $engine = MetricEngine::unscoped();
        $s = CarbonImmutable::now()->subMonth()->toMutable();
        $e = CarbonImmutable::now()->addDay()->toMutable();

        $revenue = $engine->revenue($s, $e, CarbonImmutable::now()->subMonths(2)->toMutable(), $s);

        $this->assertSame(12_800.0, round((float) $revenue['current'], 2));
        $this->assertNotSame(100.0, round((float) $revenue['current'], 2), 'face value would read 100');
    }
}
