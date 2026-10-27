<?php

namespace App\Support;

use App\Models\PayGroup;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\User;
use App\Services\People\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Labour cost on the roster (People C4 part 2) — an ESTIMATE for planning,
 * never a figure that enters any ledger. Each shift costs its hours at the
 * person's hourly equivalent from the pay structure in force on the day:
 * hourly = base; daily = base ÷ the group's standard hours per day;
 * monthly = base ÷ (working days in that month, holidays out) ÷ standard
 * hours. Overtime multipliers, allowances, breaks and statutory employer
 * costs are not in it; the payslip is the truth (Payroll::computeLine).
 */
class LabourCost
{
    public static function forShifts(Collection $shifts, Collection $members): array
    {
        $byUser = $members->keyBy('id');
        $rates = []; $perShift = []; $byDay = []; $byPerson = []; $uncosted = 0; $total = 0.0;
        foreach ($shifts as $s) {
            /** @var Shift $s */
            if (!$s->user_id || !($u = $byUser->get($s->user_id))) { $perShift[$s->id] = null; continue; }
            $key = $u->id . ':' . $s->date->format('Y-m');
            if (!array_key_exists($key, $rates)) $rates[$key] = self::hourlyRate($u, $s->date);
            $rate = $rates[$key];
            if ($rate === null) { $perShift[$s->id] = null; $uncosted++; continue; }
            $cost = round($rate * $s->minutes() / 60, 2);
            $perShift[$s->id] = $cost;
            $byDay[$s->date->toDateString()] = round(($byDay[$s->date->toDateString()] ?? 0) + $cost, 2);
            $byPerson[$u->id] = round(($byPerson[$u->id] ?? 0) + $cost, 2);
            $total = round($total + $cost, 2);
        }
        return ['currency' => 'KES', 'total' => $total, 'by_day' => $byDay, 'by_person' => $byPerson, 'per_shift' => $perShift, 'uncosted_shifts' => $uncosted,
            'basis' => 'Estimate: shift hours at each person\'s hourly equivalent from their pay structure (monthly ÷ working days ÷ standard hours). No overtime, allowances, breaks or employer costs.'];
    }

    /** KES per hour for $user on $date, or null without a pay structure. */
    public static function hourlyRate(User $user, Carbon $date): ?float
    {
        $s = Payroll::structureFor($user, $date);
        if (!$s) return null;
        $group = PayGroup::forUser($user);
        $std = max(1.0, (float) ($group?->standard_hours_per_day ?? 8));
        $base = (float) $s->base_amount;
        return match ($s->kind) {
            'hourly'  => $base,
            'daily'   => round($base / $std, 4),
            default   => round($base / self::workingDaysInMonth($date) / $std, 4),
        };
    }

    public static function workingDaysInMonth(Carbon $date): int
    {
        $working = array_map('intval', (array) AttendanceRules::get('working_days'));
        $from = $date->copy()->startOfMonth(); $to = $date->copy()->endOfMonth();
        $holidays = PublicHoliday::whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $n = 0;
        foreach (\Carbon\CarbonPeriod::create($from, $to) as $d) if (in_array($d->dayOfWeekIso, $working, true) && !in_array($d->toDateString(), $holidays, true)) $n++;
        return max(1, $n);
    }
}
