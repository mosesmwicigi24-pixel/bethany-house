<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PayGroup extends Model
{
    protected $fillable = ['name', 'frequency', 'period_end_day', 'pay_date_day', 'standard_hours_per_day', 'hours_by_weekday', 'overtime_after_daily_hours', 'overtime_after_weekly_hours',
        'overtime_multiplier', 'rest_day_multiplier', 'holiday_multiplier', 'round_to_minutes', 'unpaid_break_minutes', 'unpaid_break_after_hours', 'is_default', 'is_active'];

    protected $casts = [
        'standard_hours_per_day'      => 'decimal:2',
        'overtime_after_daily_hours'  => 'decimal:2',
        'overtime_after_weekly_hours' => 'decimal:2',
        'overtime_multiplier'         => 'decimal:2',
        'rest_day_multiplier'         => 'decimal:2',
        'holiday_multiplier'          => 'decimal:2',
        'round_to_minutes'            => 'integer',
        'period_end_day'              => 'integer',
        'pay_date_day'                => 'integer',
        'hours_by_weekday'            => 'array',
        'unpaid_break_minutes'        => 'integer',
        'unpaid_break_after_hours'    => 'decimal:2',
        'is_default'                  => 'boolean',
        'is_active'                   => 'boolean',
    ];

    public static function defaultGroup(): ?self
    {
        return self::where('is_default', true)->first() ?? self::where('is_active', true)->orderBy('id')->first();
    }

    /**
     * [starts_on, ends_on] of the period containing $date.
     * Weekly = Monday to Sunday. Monthly = calendar month, or, with
     * period_end_day set (Bethany: 10), the (day+1)th of one month to the
     * day-th of the next (C1). Days 1–28 only, so every month has the day.
     */
    public function periodBounds(Carbon $date): array
    {
        if ($this->frequency === 'weekly') {
            $s = $date->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
            return [$s, $s->copy()->addDays(6)];
        }
        $endDay = (int) ($this->period_end_day ?? 0);
        if ($endDay >= 1 && $endDay <= 28) {
            $d = $date->copy()->startOfDay();
            $end = $d->day > $endDay ? $d->copy()->addMonthNoOverflow()->setDay($endDay) : $d->copy()->setDay($endDay);
            $start = $end->copy()->subMonthNoOverflow()->setDay($endDay)->addDay();
            return [$start, $end];
        }
        return [$date->copy()->startOfMonth()->startOfDay(), $date->copy()->endOfMonth()->startOfDay()];
    }

    /** Standard hours for a weekday (1 = Monday … 7 = Sunday), from hours_by_weekday or the flat standard. */
    public function hoursFor(int $isoWeekday): float
    {
        $byDay = $this->hours_by_weekday;
        if (is_array($byDay) && isset($byDay[(string) $isoWeekday]) && (float) $byDay[(string) $isoWeekday] > 0) return (float) $byDay[(string) $isoWeekday];
        return (float) $this->standard_hours_per_day;
    }

    public function periodFor(Carbon $date): PayPeriod
    {
        [$s, $e] = $this->periodBounds($date);
        return PayPeriod::firstOrCreate(['pay_group_id' => $this->id, 'starts_on' => $s->toDateString()], ['ends_on' => $e->toDateString()]);
    }
}
