<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReportController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * priorPeriod() must return a comparison window the SAME length as the current
 * one. Carbon 3 returns diffIn*() as a float and dateRange() appends
 * ' 23:59:59' to the end, so the old code computed 30.99998 days and subDays()
 * cascaded the fraction — pushing priorStart a whole day early and making every
 * "vs previous period" badge compare against a window one day too long. On the
 * "today" option that was two days against one, so a flat day read as a ~50%
 * collapse.
 *
 * The expected prior windows below are worked out BY HAND (not read back from
 * the implementation), per the repo's reconciliation-fixture rule.
 */
class PriorPeriodComparisonTest extends TestCase
{
    /** @return array{0:string,1:array} */
    private function prior(string $start, string $end): array
    {
        $m = new ReflectionMethod(ReportController::class, 'priorPeriod');
        $m->setAccessible(true);

        return $m->invoke(new ReportController(), $start, $end);
    }

    public function test_today_compares_against_exactly_one_prior_day(): void
    {
        // The bug's sharpest case: a single-day range must look back ONE day,
        // not two. dateRange() hands priorPeriod the ' 23:59:59' end.
        [$ps, $pe] = $this->prior('2026-08-08', '2026-08-08 23:59:59');

        $this->assertSame('2026-08-07', $ps);
        $this->assertSame('2026-08-07 23:59:59', $pe);
    }

    public function test_a_seven_day_range_looks_back_seven_days(): void
    {
        // Current 2026-08-02 .. 2026-08-08 (7 days) → prior 2026-07-26 .. 2026-08-01.
        [$ps, $pe] = $this->prior('2026-08-02', '2026-08-08 23:59:59');

        $this->assertSame('2026-07-26', $ps);
        $this->assertSame('2026-08-01 23:59:59', $pe);
    }

    public function test_a_thirty_day_range_looks_back_thirty_days_across_a_month_boundary(): void
    {
        // Current 2026-07-10 .. 2026-08-08 (30 days) → prior 2026-06-10 .. 2026-07-09.
        [$ps, $pe] = $this->prior('2026-07-10', '2026-08-08 23:59:59');

        $this->assertSame('2026-06-10', $ps);
        $this->assertSame('2026-07-09 23:59:59', $pe);
    }

    public function test_the_prior_window_abuts_the_current_start_with_no_gap_or_overlap(): void
    {
        // Whatever the length, prior must END the day before the current START.
        foreach ([
            ['2026-08-08', '2026-08-08 23:59:59'],
            ['2026-08-02', '2026-08-08 23:59:59'],
            ['2026-07-10', '2026-08-08 23:59:59'],
        ] as [$start, $end]) {
            [, $pe] = $this->prior($start, $end);
            $dayBeforeStart = \Carbon\Carbon::parse($start)->subDay()->format('Y-m-d');
            $this->assertSame($dayBeforeStart, substr($pe, 0, 10), "prior end abuts start for {$start}");
        }
    }
}
