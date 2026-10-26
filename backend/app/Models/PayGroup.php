<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PayGroup extends Model
{
    protected $fillable = ['name', 'frequency', 'standard_hours_per_day', 'overtime_after_daily_hours', 'overtime_after_weekly_hours',
        'overtime_multiplier', 'rest_day_multiplier', 'holiday_multiplier', 'round_to_minutes', 'is_default', 'is_active'];

    protected $casts = [
        'standard_hours_per_day'      => 'decimal:2',
        'overtime_after_daily_hours'  => 'decimal:2',
        'overtime_after_weekly_hours' => 'decimal:2',
        'overtime_multiplier'         => 'decimal:2',
        'rest_day_multiplier'         => 'decimal:2',
        'holiday_multiplier'          => 'decimal:2',
        'round_to_minutes'            => 'integer',
        'is_default'                  => 'boolean',
        'is_active'                   => 'boolean',
    ];

    public static function defaultGroup(): ?self
    {
        return self::where('is_default', true)->first() ?? self::where('is_active', true)->orderBy('id')->first();
    }

    /** [starts_on, ends_on] of the period containing $date. Monthly = calendar month; weekly = Monday to Sunday. */
    public function periodBounds(Carbon $date): array
    {
        if ($this->frequency === 'weekly') {
            $s = $date->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
            return [$s, $s->copy()->addDays(6)];
        }
        return [$date->copy()->startOfMonth()->startOfDay(), $date->copy()->endOfMonth()->startOfDay()];
    }

    public function periodFor(Carbon $date): PayPeriod
    {
        [$s, $e] = $this->periodBounds($date);
        return PayPeriod::firstOrCreate(['pay_group_id' => $this->id, 'starts_on' => $s->toDateString()], ['ends_on' => $e->toDateString()]);
    }
}
