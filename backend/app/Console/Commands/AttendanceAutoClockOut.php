<?php

namespace App\Console\Commands;

use App\Models\TimeEntry;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Support\AttendanceAlerts;
use App\Support\AttendanceRules;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * attendance:auto-clock-out — close shifts nobody clocked out of.
 *
 * A shift still open once the cut-off time (config attendance.auto_clock_out_time,
 * Nairobi wall clock) has passed on the day it began is closed AT the cut-off,
 * not at the moment the job runs, so a forgotten 17:00 departure never grows
 * into a 24-hour shift. The entry is flagged "auto_clock_out" for the manager
 * to correct to the real time. Runs every fifteen minutes; idempotent.
 */
class AttendanceAutoClockOut extends Command
{
    protected $signature = 'attendance:auto-clock-out {--dry-run : List what would close, change nothing}';
    protected $description = 'Close time entries still open after the cut-off time and flag them for review';

    public function handle(): int
    {
        $rules = AttendanceRules::all();
        [$h, $m] = array_map('intval', explode(':', (string) $rules['auto_clock_out_time']));
        $afterHours = (int) $rules['auto_clock_out_after_hours'];
        $limitHours = (int) $rules['daily_limit_hours'];
        $now = now();
        $closed = 0;
        $overtime = \App\Support\Automations::active('overtime_soon');

        foreach (TimeEntry::with('user')->active()->orderBy('clock_in_at')->cursor() as $entry) {
            $cutoff = $entry->clock_in_at->copy()->setTime($h, $m, 0);
            if ($cutoff->lte($entry->clock_in_at)) {
                // Clocked in after the cut-off (a night shift): close at the next day's cut-off.
                $cutoff->addDay();
            }
            // C1: a maximum shift length too; whichever comes first.
            if ($afterHours > 0 && $entry->clock_in_at->copy()->addHours($afterHours)->lt($cutoff)) {
                $cutoff = $entry->clock_in_at->copy()->addHours($afterHours);
            }
            // C1: daily limit — tell the manager once, before the shift is closed.
            if ($limitHours > 0 && $now->gte($entry->clock_in_at->copy()->addHours($limitHours)) && !in_array('daily_limit', $entry->flags ?? [], true) && !$this->option('dry-run')) {
                $flags = array_values(array_unique(array_merge($entry->flags ?? [], ['daily_limit'])));
                $entry->forceFill(['flags' => $flags, 'flagged_reason' => TimeEntry::describeFlags($flags)])->save();
                if ($entry->user) {
                    $name = trim("{$entry->user->first_name} {$entry->user->last_name}");
                    NotificationService::deliver(AttendanceAlerts::managersFor($entry->user), new InAppNotification(
                        title: "{$name} has worked {$limitHours} h today", body: "Still clocked in since {$entry->clock_in_at->format('H:i')}. The shift closes on its own at {$cutoff->format('H:i')}.",
                        actionUrl: '/settings/attendance', icon: 'clock', data: ['entry_id' => $entry->id]));
                }
            }
            // C2 automation: N minutes before today's regular hours run out → the manager, once per entry.
            if ($overtime && $entry->user && !$entry->overtime_alerted_at && !$this->option('dry-run')) {
                $group = \App\Models\PayGroup::forUser($entry->user);
                $capMinutes = $group ? (int) round($group->hoursFor($entry->clock_in_at->dayOfWeekIso) * 60) : 0;
                if ($capMinutes > 0 && $entry->elapsedMinutes() >= $capMinutes - (int) $overtime['threshold']) {
                    $entry->forceFill(['overtime_alerted_at' => $now])->save();
                    $name = trim("{$entry->user->first_name} {$entry->user->last_name}");
                    $left = max(0, $capMinutes - $entry->elapsedMinutes());
                    NotificationService::deliver(\App\Support\Automations::recipientsFor('overtime_soon', $entry->user), new InAppNotification(
                        title: $left > 0 ? "{$name} reaches overtime in {$left} min" : "{$name} is in overtime",
                        body:  'Clocked in since ' . $entry->clock_in_at->format('H:i') . '; regular hours today are ' . rtrim(rtrim(number_format($capMinutes / 60, 1), '0'), '.') . ' h.',
                        actionUrl: '/people/overview', icon: 'clock', data: ['entry_id' => $entry->id, 'automation' => 'overtime_soon']));
                }
            }
            if ($now->lt($cutoff)) {
                continue;
            }

            $this->line(sprintf('#%d user %d in %s → close at %s', $entry->id, $entry->user_id, $entry->clock_in_at, $cutoff));
            if ($this->option('dry-run')) {
                continue;
            }

            $breaks = $entry->breaks ?? [];
            foreach ($breaks as &$b) {
                if (!empty($b['started_at']) && empty($b['ended_at'])) {
                    $b['ended_at'] = $cutoff->toIso8601String();
                }
            }
            unset($b);
            $entry->breaks = $breaks;
            $breakMinutes = $entry->calculateBreakMinutes();
            $flags = array_values(array_unique(array_merge($entry->flags ?? [], ['auto_clock_out'])));

            $entry->forceFill([
                'clock_out_at'        => $cutoff,
                'total_break_minutes' => $breakMinutes,
                'worked_minutes'      => max(0, (int) $entry->clock_in_at->diffInMinutes($cutoff) - $breakMinutes),
                'flags'               => $flags,
                'flagged_reason'      => TimeEntry::describeFlags($flags),
                'status'              => 'flagged',
                'auto_closed_at'      => $now,
            ])->save();

            ActivityLogService::log('attendance_auto_clock_out', $entry, [
                'cutoff' => $cutoff->toIso8601String(),
            ], "Time entry #{$entry->id} closed automatically at {$cutoff->format('H:i')}");
            $closed++;
        }

        $this->info($this->option('dry-run') ? 'Dry run; nothing changed.' : "Closed {$closed} entries.");
        return self::SUCCESS;
    }
}
