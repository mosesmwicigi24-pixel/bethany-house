<?php

namespace App\Services\People;

use App\Models\LeaveRequest;
use App\Models\PayGroup;
use App\Models\PayPeriod;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * A person's period in minutes (Bethany People PR 5). Derived from
 * time_entries, approved leave, public holidays and the pay group's rule;
 * never stored until the manager approves, when the controller snapshots
 * exactly this table.
 *
 * Day classes:
 *   holiday   a public holiday            → every minute at holiday_multiplier
 *   rest      not a working day           → every minute at rest_day_multiplier
 *   working   otherwise                   → up to overtime_after_daily_hours
 *             regular, the rest overtime; a weekly threshold, if set, moves
 *             further regular minutes to overtime once the week's regular
 *             total passes it.
 * Leave: an approved request on a working day counts as a paid or unpaid
 * leave day (by its type); it is never minutes.
 * Flags: a time entry still open or still flagged in the period is listed
 * and blocks approval; a published shift with no punch is an absence.
 */
class TimesheetEngine
{
    public static function compute(User $user, PayPeriod $period): array
    {
        $group    = $period->group ?? PayGroup::defaultGroup();
        $rules    = \App\Support\AttendanceRules::all();
        $working  = $rules['working_days'];
        $start    = $period->starts_on->copy()->startOfDay();
        $end      = $period->ends_on->copy()->endOfDay();
        $holidays = PublicHoliday::whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->keyBy(fn ($h) => $h->date->toDateString());
        $entries  = TimeEntry::with('outlet:id,name')->where('user_id', $user->id)->whereBetween('clock_in_at', [$start, $end])->orderBy('clock_in_at')->get();
        $leave    = LeaveRequest::with('type')->where('user_id', $user->id)->approved()
            ->where('starts_on', '<=', $end->toDateString())->where('ends_on', '>=', $start->toDateString())->get();
        $shifts   = Shift::where('user_id', $user->id)->where('status', 'published')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->get()->groupBy(fn ($s) => $s->date->toDateString());

        $round    = max(1, (int) ($group?->round_to_minutes ?? 1));
        $dailyCap = $group && $group->overtime_after_daily_hours !== null ? (int) round((float) $group->overtime_after_daily_hours * 60) : null;
        // C1: hours per weekday (Saturday 6 h at Bethany) set that day's overtime threshold; an unpaid lunch comes off long days.
        $byWeekday = $group && is_array($group->hours_by_weekday) ? $group->hours_by_weekday : null;
        $unpaidBreak = (int) ($group?->unpaid_break_minutes ?? 0);
        $unpaidAfter = $group && $group->unpaid_break_after_hours !== null ? (int) round((float) $group->unpaid_break_after_hours * 60) : null;
        $overMark = (int) $rules['over_scheduled_minutes']; $underMark = (int) $rules['under_scheduled_minutes'];
        $weeklyCap = $group && $group->overtime_after_weekly_hours !== null ? (int) round((float) $group->overtime_after_weekly_hours * 60) : null;

        $byDay = $entries->groupBy(fn ($e) => $e->clock_in_at->toDateString());
        $days = []; $flags = []; $absences = [];
        $t = ['regular' => 0, 'overtime' => 0, 'rest' => 0, 'holiday' => 0, 'paid_leave' => 0.0, 'unpaid_leave' => 0.0, 'days_worked' => 0];
        $weekRegular = []; // ISO week → regular minutes so far

        foreach (CarbonPeriod::create($start, $period->ends_on) as $d) {
            $iso = $d->toDateString();
            $isHoliday = $holidays->has($iso);
            $isWorking = in_array($d->dayOfWeekIso, $working, true);
            $class = $isHoliday ? 'holiday' : ($isWorking ? 'working' : 'rest');

            $worked = 0; $punches = []; $unpaidTaken = 0;
            $capToday = $byWeekday && isset($byWeekday[(string) $d->dayOfWeekIso]) && (float) $byWeekday[(string) $d->dayOfWeekIso] > 0 ? (int) round((float) $byWeekday[(string) $d->dayOfWeekIso] * 60) : $dailyCap;
            foreach ($byDay->get($iso, collect()) as $e) {
                $mins = $e->status === 'active' ? 0 : (int) ($e->worked_minutes ?? 0);
                // Unpaid lunch: if the day is long enough and less than the unpaid minutes were actually taken as breaks, the rest comes off.
                if ($unpaidBreak > 0 && $mins > 0 && ($unpaidAfter === null || $mins + (int) $e->total_break_minutes >= $unpaidAfter)) {
                    $deduct = max(0, $unpaidBreak - (int) $e->total_break_minutes);
                    $mins = max(0, $mins - $deduct); $unpaidTaken += $deduct;
                }
                $mins = (int) (round($mins / $round) * $round);
                $worked += $mins;
                $punches[] = ['id' => $e->id, 'in' => $e->clock_in_at->format('H:i'), 'out' => $e->clock_out_at?->format('H:i'), 'minutes' => $mins, 'status' => $e->status, 'outlet' => $e->outlet?->name, 'flags' => $e->flags ?? []];
                if ($e->status === 'active') $flags[] = ['date' => $iso, 'entry_id' => $e->id, 'why' => 'Still clocked in'];
                elseif ($e->status === 'flagged') $flags[] = ['date' => $iso, 'entry_id' => $e->id, 'why' => $e->flagged_reason ?: 'Flagged punch'];
            }

            $onLeave = $leave->first(fn ($l) => $l->starts_on->lte($d) && $l->ends_on->gte($d));
            $leaveLabel = null;
            if ($onLeave && ($class === 'working' || $onLeave->type?->counts_calendar_days)) {
                $leaveLabel = $onLeave->type?->name;
                if ($class === 'working') {
                    if ($onLeave->type?->is_paid) $t['paid_leave'] += 1; else $t['unpaid_leave'] += 1;
                }
            }

            $regular = $overtime = $rest = $holiday = 0;
            if ($worked > 0) {
                if ($class === 'holiday') $holiday = $worked;
                elseif ($class === 'rest') $rest = $worked;
                else {
                    $regular = $capToday !== null ? min($worked, $capToday) : $worked;
                    $overtime = $worked - $regular;
                    if ($weeklyCap !== null) {
                        $wk = $d->format('o-W');
                        $before = $weekRegular[$wk] ?? 0;
                        $over = max(0, $before + $regular - $weeklyCap);
                        if ($over > 0) { $regular -= $over; $overtime += $over; }
                        $weekRegular[$wk] = $before + $regular;
                    }
                }
                $t['days_worked']++;
            }
            $t['regular'] += $regular; $t['overtime'] += $overtime; $t['rest'] += $rest; $t['holiday'] += $holiday;

            $rostered = $shifts->get($iso, collect());
            if ($rostered->isNotEmpty() && $worked === 0 && !$onLeave && $d->lt(now()->startOfDay())) {
                $absences[] = ['date' => $iso, 'shift' => Shift::hhmm($rostered->first()->starts_at) . '–' . Shift::hhmm($rostered->first()->ends_at)];
            }
            // C1: worked against rostered; amber when over or under by more than the rule.
            $rosteredMinutes = $rostered->sum(fn ($s) => $s->minutes());
            $vs = $rostered->isNotEmpty() && $worked > 0 ? $worked - $rosteredMinutes : null;
            $highlight = null;
            if ($vs !== null && $overMark > 0 && $vs >= $overMark) $highlight = 'over';
            if ($vs !== null && $underMark > 0 && -$vs >= $underMark) $highlight = 'under';

            $days[] = [
                'date' => $iso, 'class' => $class, 'holiday' => $isHoliday ? $holidays[$iso]->name : null,
                'worked_minutes' => $worked, 'regular_minutes' => $regular, 'overtime_minutes' => $overtime,
                'rest_day_minutes' => $rest, 'holiday_minutes' => $holiday, 'leave' => $leaveLabel,
                'rostered' => $rostered->map(fn ($s) => Shift::hhmm($s->starts_at) . '–' . Shift::hhmm($s->ends_at))->values()->all(),
                'rostered_minutes' => $rosteredMinutes, 'vs_scheduled_minutes' => $vs, 'highlight' => $highlight, 'unpaid_break_minutes' => $unpaidTaken,
                'punches' => $punches,
            ];
        }

        return [
            'group'    => $group ? ['id' => $group->id, 'name' => $group->name, 'frequency' => $group->frequency,
                'overtime_after_daily_hours' => $group->overtime_after_daily_hours !== null ? (float) $group->overtime_after_daily_hours : null,
                'overtime_after_weekly_hours' => $group->overtime_after_weekly_hours !== null ? (float) $group->overtime_after_weekly_hours : null,
                'overtime_multiplier' => (float) $group->overtime_multiplier, 'rest_day_multiplier' => (float) $group->rest_day_multiplier,
                'holiday_multiplier' => (float) $group->holiday_multiplier, 'round_to_minutes' => $round] : null,
            'totals'   => [
                'regular_minutes' => $t['regular'], 'overtime_minutes' => $t['overtime'], 'rest_day_minutes' => $t['rest'], 'holiday_minutes' => $t['holiday'],
                'worked_minutes' => $t['regular'] + $t['overtime'] + $t['rest'] + $t['holiday'],
                'paid_leave_days' => $t['paid_leave'], 'unpaid_leave_days' => $t['unpaid_leave'], 'days_worked' => $t['days_worked'],
            ],
            'days'     => $days,
            'flags'    => $flags,
            'absences' => $absences,
        ];
    }
}
