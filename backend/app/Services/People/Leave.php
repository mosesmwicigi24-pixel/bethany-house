<?php

namespace App\Services\People;

use App\Models\LeaveAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * The leave rules in one place (Bethany People PR 4).
 *
 * Days: a request is measured in working days (config attendance.working_days,
 * public holidays skipped) unless the type counts calendar days.
 *
 * Balance for a type and year, derived at read time, never stored:
 *   entitled   = days_per_year, prorated in the hire year (front) or
 *                earned a twelfth per completed month of service (monthly)
 *   used       = approved requests whose start falls in the year
 *   requested  = pending requests in the year (shown, not deducted)
 *   adjusted   = sum of leave_adjustments for the year (+ grants, − takes)
 *   available  = entitled − used + adjusted
 * No carry-over between years in this version.
 */
class Leave
{
    /** @return array{days: float, holidays: list<string>} */
    public static function measure(LeaveType $type, string $startsOn, string $endsOn): array
    {
        $start = Carbon::parse($startsOn)->startOfDay();
        $end   = Carbon::parse($endsOn)->startOfDay();
        if ($end->lt($start)) {
            return ['days' => 0.0, 'holidays' => []];
        }
        if ($type->counts_calendar_days) {
            return ['days' => (float) ($start->diffInDays($end) + 1), 'holidays' => []];
        }
        $working  = \App\Support\AttendanceRules::get('working_days');
        $holidays = PublicHoliday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn ($h) => $h->date->toDateString());
        $days = 0; $skipped = [];
        foreach (CarbonPeriod::create($start, $end) as $d) {
            if (!in_array($d->dayOfWeekIso, $working, true)) continue;
            if ($holidays->has($d->toDateString())) { $skipped[] = $holidays[$d->toDateString()]->name; continue; }
            $days++;
        }
        return ['days' => (float) $days, 'holidays' => $skipped];
    }

    /** Entitlement earned so far in $year for this person and type. */
    public static function entitled(User $user, LeaveType $type, int $year, ?Carbon $asOf = null): float
    {
        if (!$type->tracksBalance()) return 0.0;
        $perYear = (float) $type->days_per_year;
        $asOf    = ($asOf ?? now())->copy();
        $hire    = $user->employeeProfile?->hire_date;
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd   = Carbon::create($year, 12, 31)->endOfDay();
        $from = $hire && $hire->gt($yearStart) ? $hire->copy()->startOfDay() : $yearStart;
        if ($from->gt($yearEnd)) return 0.0;

        if ($type->accrual === 'monthly') {
            // A twelfth per completed month of service in the year, up to today
            // (a finished year counts to 1 January of the next: twelve months).
            $upTo = $asOf->gt($yearEnd) ? $yearEnd->copy()->addDay()->startOfDay() : $asOf;
            if ($upTo->lt($from)) return 0.0;
            $months = (int) $from->diffInMonths($upTo);
            return round(min($perYear, $perYear * $months / 12), 2);
        }
        // Front-loaded: the whole year's days, prorated by months remaining in the hire year.
        $monthsLeft = 12 - ($from->month - 1);
        return round($perYear * $monthsLeft / 12, 2);
    }

    /** @return array{type: LeaveType, entitled: float, used: float, requested: float, adjusted: float, available: float|null} */
    public static function balance(User $user, LeaveType $type, int $year): array
    {
        $rows = LeaveRequest::where('user_id', $user->id)->where('leave_type_id', $type->id)->whereYear('starts_on', $year)->live()->get();
        $used      = (float) $rows->where('status', 'approved')->sum('days');
        $requested = (float) $rows->where('status', 'pending')->sum('days');
        $adjusted  = (float) LeaveAdjustment::where('user_id', $user->id)->where('leave_type_id', $type->id)->where('year', $year)->sum('days');
        $entitled  = self::entitled($user, $type, $year);
        return [
            'type'      => $type,
            'entitled'  => $entitled,
            'used'      => $used,
            'requested' => $requested,
            'adjusted'  => $adjusted,
            'available' => $type->tracksBalance() ? round($entitled - $used + $adjusted, 2) : null,
        ];
    }

    /** Every active type's balance for the person in the year. */
    public static function balances(User $user, int $year): array
    {
        $user->loadMissing('employeeProfile');
        return LeaveType::where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn ($t) => self::balance($user, $t, $year))->values()->all();
    }

    /** People who may decide this person's leave: the manager they report to, and leave.approve holders sharing an outlet. */
    public static function approversFor(User $person)
    {
        $ids = collect();
        if ($person->reports_to) $ids->push((int) $person->reports_to);
        $outletIds = \DB::table('outlet_user')->where('user_id', $person->id)->pluck('outlet_id');
        $colleagues = User::whereIn('id', \DB::table('outlet_user')->whereIn('outlet_id', $outletIds)->pluck('user_id'))
            ->where('status', 'active')->get()->filter(fn (User $u) => $u->id !== $person->id && $u->can('leave.approve'))->pluck('id');
        $ids = $ids->merge($colleagues)->unique()->reject(fn ($id) => $id === $person->id);
        return User::whereIn('id', $ids)->where('status', 'active')->get();
    }

    public static function mayDecide(User $actor, User $person): bool
    {
        if ($actor->id === $person->id) return false;
        if ($actor->isSuperAdmin()) return true;
        if ((int) $person->reports_to === (int) $actor->id) return true;
        if (!$actor->can('leave.approve')) return false;
        if ($actor->can('outlets.all_access')) return true;
        $mine   = \DB::table('outlet_user')->where('user_id', $actor->id)->pluck('outlet_id');
        return \DB::table('outlet_user')->where('user_id', $person->id)->whereIn('outlet_id', $mine)->exists();
    }
}
