<?php

namespace Tests\Feature;

use App\Services\Reporting\MetricEngine;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A period still in progress is compared like for like: the same stretch of
 * the previous period, never the whole of it. On 2 October, "this month vs
 * last month" compared two days with thirty and read as a 77% collapse.
 */
class ReportPeriodComparisonTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function prev(string $key): array
    {
        [, , $ps, $pe] = MetricEngine::resolvePeriod($key);

        return [$ps->format('Y-m-d H:i:s'), $pe->format('Y-m-d H:i:s')];
    }

    public function test_in_progress_periods_compare_the_same_stretch_of_the_previous_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00', 'Africa/Nairobi'));
        $this->assertSame(['2026-09-01 00:00:00', '2026-09-02 23:59:59'], $this->prev('this_month'));
        $this->assertSame(['2026-07-01 00:00:00', '2026-07-02 23:59:59'], $this->prev('this_quarter'));
        $this->assertSame(['2025-01-01 00:00:00', '2025-10-02 23:59:59'], $this->prev('this_year'));
    }

    public function test_a_month_end_never_overflows_into_the_current_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 18:00', 'Africa/Nairobi'));
        $this->assertSame(['2026-02-01 00:00:00', '2026-02-28 23:59:59'], $this->prev('this_month'));
    }

    public function test_complete_periods_still_compare_whole_with_whole(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00', 'Africa/Nairobi'));
        $this->assertSame(['2026-08-01 00:00:00', '2026-08-31 23:59:59'], $this->prev('last_month'));
    }
}
