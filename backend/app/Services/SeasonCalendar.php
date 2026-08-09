<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * SeasonCalendar — the liturgical year as pure date arithmetic.
 *
 * Bethany House's demand follows the church calendar, not the Gregorian one:
 * communion supplies surge before Easter, vestments before Christmas. This
 * class turns any Gregorian date range into the ordered list of liturgical
 * seasons covering it, entirely computed (no DB, no config):
 *
 *   advent            4th Sunday before Christmas → Dec 24
 *   christmastide     Dec 25 → Jan 5 (the twelve days)
 *   epiphany_ordinary Jan 6 → the day before Ash Wednesday
 *   lent              Ash Wednesday (Easter − 46d) → Holy Saturday
 *   holy_week         Palm Sunday (Easter − 7d) → Holy Saturday — a
 *                     sub-window of lent, exposed separately because it is
 *                     the single hottest sales week of the year
 *   eastertide        Easter Sunday → Pentecost (Easter + 49d)
 *   ordinary_time     the long stretch after Pentecost → the eve of Advent
 *
 * Easter is the anchor for everything movable, computed with the anonymous
 * Gregorian computus (Meeus/Jones/Butcher) — valid for all Gregorian years.
 *
 * NOTE: this is deliberately distinct from the storefront `seasons` CMS table
 * (App\Models\Season), which holds hand-dated marketing campaign windows.
 * This class is the analytical calendar the demand planner reasons over.
 */
class SeasonCalendar
{
    public const LABELS = [
        'advent'            => 'Advent',
        'christmastide'     => 'Christmastide',
        'epiphany_ordinary' => 'Epiphany / Ordinary Time',
        'lent'              => 'Lent',
        'holy_week'         => 'Holy Week',
        'eastertide'        => 'Eastertide',
        'ordinary_time'     => 'Ordinary Time',
    ];

    /** Season keys that represent baseline (non-peak) trading weeks. */
    public const ORDINARY_KEYS = ['epiphany_ordinary', 'ordinary_time'];

    /**
     * Easter Sunday for a Gregorian year — anonymous Gregorian computus
     * (Meeus/Jones/Butcher). Pure integer arithmetic; no DST, no locale.
     */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /** Ash Wednesday: 46 days before Easter (40 fast days + 6 Sundays). */
    public static function ashWednesday(int $year): CarbonImmutable
    {
        return self::easter($year)->subDays(46);
    }

    /**
     * First Sunday of Advent: the 4th Sunday before Christmas — i.e. three
     * weeks before the last Sunday on or before Dec 24.
     */
    public static function adventStart(int $year): CarbonImmutable
    {
        $christmasEve = CarbonImmutable::create($year, 12, 24)->startOfDay();
        // Last Sunday ≤ Dec 24 (if Dec 24 is itself a Sunday, that IS Advent 4).
        $advent4 = $christmasEve->subDays($christmasEve->dayOfWeek); // dayOfWeek: Sunday = 0

        return $advent4->subDays(21);
    }

    /** Pentecost Sunday: the 50th day of Eastertide (Easter + 49d). */
    public static function pentecost(int $year): CarbonImmutable
    {
        return self::easter($year)->addDays(49);
    }

    /**
     * Every season window anchored in Gregorian year $year, keyed windows with
     * inclusive start/end dates. Christmastide and ordinary stretches span
     * into the following year where the calendar does.
     *
     * @return array<int, array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function seasonsForYear(int $year): array
    {
        $easter  = self::easter($year);
        $ashWed  = self::ashWednesday($year);
        $holySat = $easter->subDay();

        $windows = [
            self::window('advent', self::adventStart($year), CarbonImmutable::create($year, 12, 24)),
            self::window('christmastide', CarbonImmutable::create($year, 12, 25), CarbonImmutable::create($year + 1, 1, 5)),
            self::window('epiphany_ordinary', CarbonImmutable::create($year, 1, 6), $ashWed->subDay()),
            self::window('lent', $ashWed, $holySat),
            self::window('holy_week', $easter->subDays(7), $holySat),
            self::window('eastertide', $easter, self::pentecost($year)),
            self::window('ordinary_time', self::pentecost($year)->addDay(), self::adventStart($year)->subDay()),
        ];

        return $windows;
    }

    /**
     * Ordered season windows overlapping [$from, $to] (inclusive), spanning
     * year boundaries. holy_week overlaps lent by design (it is exposed as
     * both); everything else tiles the calendar without gaps.
     *
     * @return array<int, array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function seasonsForRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to   = $to->startOfDay();

        $out = [];
        for ($year = $from->year - 1; $year <= $to->year + 1; $year++) {
            foreach (self::seasonsForYear($year) as $w) {
                if ($w['end']->lt($from) || $w['start']->gt($to)) {
                    continue;
                }
                $out[$w['key'] . ':' . $w['start']->toDateString()] = $w;
            }
        }

        $out = array_values($out);
        usort($out, fn ($a, $b) => [$a['start']->getTimestamp(), $a['key']] <=> [$b['start']->getTimestamp(), $b['key']]);

        return $out;
    }

    /** @return array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable} */
    private static function window(string $key, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'key'   => $key,
            'label' => self::LABELS[$key],
            'start' => $start->startOfDay(),
            'end'   => $end->startOfDay(),
        ];
    }
}
