<?php

namespace Tests\Concerns;

use Illuminate\Support\Carbon;

/**
 * Pins the clock to mid-month so period-filtered report tests cannot straddle a
 * calendar boundary.
 *
 * THE BUG THIS EXISTS TO KILL — observed in CI on 2026-08-01:
 *
 *   ProductionIntelligenceTest seeds tasks at now()->subHours(6) and
 *   now()->subHour(), then asks the report for period=this_month.
 *   CI ran at 2026-07-31 21:37 UTC. APP_TIMEZONE is Africa/Nairobi (UTC+3),
 *   so now() was 2026-08-01 00:37 — August. The fixtures, six and one hours
 *   earlier, landed on 2026-07-31 — July. "This month" therefore matched
 *   nothing, the stage row came back null, and the suite went red on a
 *   frontend-only pull request.
 *
 * It is a time bomb, not a flake: it fires for a ~6 hour window after every
 * month rollover and is invisible for the other ~29 days. Six test classes
 * combine relative now()->sub* fixtures with a period filter, so the same
 * failure was waiting in all of them.
 *
 * The fix keeps the CURRENT month — so "this_month" still means what the test
 * author intended — and only moves the instant to the 15th at noon, buying at
 * least 14 days of headroom on either side. Any fixture offset shorter than a
 * fortnight is now guaranteed to stay inside the period under test.
 */
trait FreezesClockMidMonth
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(
            Carbon::now()->startOfMonth()->addDays(14)->setTime(12, 0, 0)
        );
    }

    protected function tearDown(): void
    {
        // Leaving the clock frozen would leak into every test that runs after
        // this class in the same process.
        Carbon::setTestNow();

        parent::tearDown();
    }
}
