<?php

namespace Tests\Unit;

use App\Services\SeasonCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pins the liturgical computus. Every projection in seasonal demand planning
 * hangs off these dates, so they are asserted against published calendars —
 * not against the implementation.
 */
class SeasonCalendarTest extends TestCase
{
    public function test_easter_dates_match_the_published_calendar(): void
    {
        $this->assertSame('2024-03-31', SeasonCalendar::easter(2024)->toDateString());
        $this->assertSame('2025-04-20', SeasonCalendar::easter(2025)->toDateString());
        $this->assertSame('2026-04-05', SeasonCalendar::easter(2026)->toDateString());
        $this->assertSame('2027-03-28', SeasonCalendar::easter(2027)->toDateString());
    }

    public function test_ash_wednesday_is_46_days_before_easter(): void
    {
        $this->assertSame('2026-02-18', SeasonCalendar::ashWednesday(2026)->toDateString());
    }

    public function test_advent_begins_on_the_fourth_sunday_before_christmas(): void
    {
        $this->assertSame('2025-11-30', SeasonCalendar::adventStart(2025)->toDateString());
        $this->assertSame('2026-11-29', SeasonCalendar::adventStart(2026)->toDateString());

        // Both are Sundays by construction.
        $this->assertSame(0, SeasonCalendar::adventStart(2025)->dayOfWeek);
        $this->assertSame(0, SeasonCalendar::adventStart(2026)->dayOfWeek);
    }

    public function test_lent_and_holy_week_windows_for_2026(): void
    {
        $seasons = collect(SeasonCalendar::seasonsForRange(
            CarbonImmutable::create(2026, 2, 1),
            CarbonImmutable::create(2026, 4, 10),
        ));

        $lent = $seasons->firstWhere('key', 'lent');
        $this->assertSame('2026-02-18', $lent['start']->toDateString()); // Ash Wednesday
        $this->assertSame('2026-04-04', $lent['end']->toDateString());   // Holy Saturday

        $holyWeek = $seasons->firstWhere('key', 'holy_week');
        $this->assertSame('2026-03-29', $holyWeek['start']->toDateString()); // Palm Sunday
        $this->assertSame('2026-04-04', $holyWeek['end']->toDateString());

        $eastertide = $seasons->firstWhere('key', 'eastertide');
        $this->assertSame('2026-04-05', $eastertide['start']->toDateString()); // Easter
    }

    public function test_range_spanning_a_year_boundary_orders_seasons_correctly(): void
    {
        $seasons = SeasonCalendar::seasonsForRange(
            CarbonImmutable::create(2026, 11, 1),
            CarbonImmutable::create(2027, 3, 1),
        );

        $keys = array_map(fn ($s) => $s['key'], $seasons);
        $this->assertSame(
            ['ordinary_time', 'advent', 'christmastide', 'epiphany_ordinary', 'lent'],
            $keys,
        );

        $byKey = collect($seasons)->keyBy('key');
        $this->assertSame('2026-11-29', $byKey['advent']['start']->toDateString());
        $this->assertSame('2026-12-24', $byKey['advent']['end']->toDateString());
        $this->assertSame('2026-12-25', $byKey['christmastide']['start']->toDateString());
        $this->assertSame('2027-01-05', $byKey['christmastide']['end']->toDateString());
        $this->assertSame('2027-01-06', $byKey['epiphany_ordinary']['start']->toDateString());
        // Ash Wednesday 2027 = Easter (2027-03-28) − 46d = 2027-02-10.
        $this->assertSame('2027-02-09', $byKey['epiphany_ordinary']['end']->toDateString());
        $this->assertSame('2027-02-10', $byKey['lent']['start']->toDateString());
    }

    public function test_calendar_tiles_without_gaps_outside_holy_week(): void
    {
        // Walk two full years day by day: every date must be covered by
        // exactly one season (holy_week excluded — it doubles lent).
        $from = CarbonImmutable::create(2025, 1, 1);
        $to   = CarbonImmutable::create(2026, 12, 31);

        $windows = array_values(array_filter(
            SeasonCalendar::seasonsForRange($from, $to),
            fn ($w) => $w['key'] !== 'holy_week',
        ));

        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $covering = array_filter(
                $windows,
                fn ($w) => $d->betweenIncluded($w['start'], $w['end']),
            );
            $this->assertCount(1, $covering, "Exactly one season must cover {$d->toDateString()}");
        }
    }
}
