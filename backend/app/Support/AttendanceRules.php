<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The attendance rules, in one place, editable on the Hub (People C1).
 *
 * Stored in `settings` under `attendance.<key>`; anything unset falls back
 * to the default here (and, for the two that pre-date this, to the old
 * config/env value so a running box keeps its behaviour). Read through
 * get()/all(); write through save() from the settings controller only.
 */
class AttendanceRules
{
    public const CACHE_KEY = 'attendance_rules';

    /** key => [default, type] — types: int | bool | string | ints */
    public static function schema(): array
    {
        return [
            // Working time
            'working_days'               => [config('attendance.working_days', [1, 2, 3, 4, 5, 6]), 'ints'],
            'auto_clock_out_time'        => [config('attendance.auto_clock_out_time', '22:00'), 'string'],
            'auto_clock_out_after_hours' => [12, 'int'],       // 0 = off
            'daily_limit_hours'          => [11, 'int'],       // alert the manager; 0 = off
            'over_scheduled_minutes'     => [60, 'int'],       // amber on the timesheet; 0 = off
            'under_scheduled_minutes'    => [0, 'int'],        // 0 = off
            // Clock-in
            'clock_in_without_shift'     => ['flag', 'string'], // allow | flag | refuse
            'earliest_clock_in_minutes'  => [30, 'int'],       // before a rostered shift; 0 = off
            'remind_clock_in'            => [true, 'bool'],
            'remind_clock_out'           => [true, 'bool'],
            // Location on shift (owner, 26 Oct 2026: trail every 5 min, flag on leaving, 90 days)
            'location_mode'              => ['trail', 'string'], // off | punch | trail
            'trail_interval_minutes'     => [5, 'int'],
            'trail_distance_meters'      => [100, 'int'],
            'leave_site_action'          => ['flag', 'string'],  // none | flag | clock_out
            'leave_site_grace_minutes'   => [15, 'int'],
            'leave_site_distance_meters' => [200, 'int'],
            'trail_retention_days'       => [90, 'int'],
        ];
    }

    public static function all(): array
    {
        // Tests change rules between cases; the cache would hand back the last case's.
        if (app()->runningUnitTests()) return self::load();
        return Cache::remember(self::CACHE_KEY, 300, fn () => self::load());
    }

    private static function load(): array
    {
        return (function () {
            $rows = DB::table('settings')->where('key', 'like', 'attendance.%')->pluck('value', 'key');
            $out = [];
            foreach (self::schema() as $key => [$default, $type]) {
                $raw = $rows->get("attendance.{$key}");
                $out[$key] = $raw === null ? $default : self::cast($raw, $type);
            }
            return $out;
        })();
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** Persist validated values (already of the right type). */
    public static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::schema())) continue;
            $stored = is_bool($value) ? ($value ? '1' : '0') : (is_array($value) ? implode(',', $value) : (string) $value);
            $exists = DB::table('settings')->where('key', "attendance.{$key}")->exists();
            if ($exists) DB::table('settings')->where('key', "attendance.{$key}")->update(['value' => $stored, 'updated_at' => now()]);
            else DB::table('settings')->insert(['key' => "attendance.{$key}", 'value' => $stored, 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    private static function cast(string $raw, string $type): mixed
    {
        return match ($type) {
            'int'  => (int) $raw,
            'bool' => $raw === '1' || $raw === 'true',
            'ints' => array_values(array_filter(array_map('intval', explode(',', $raw)), fn ($v) => $v >= 1 && $v <= 7)),
            default => $raw,
        };
    }
}
