<?php

namespace App\Console\Commands;

use App\Models\TimeEntry;
use App\Services\ActivityLogService;
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
        [$h, $m] = array_map('intval', explode(':', (string) config('attendance.auto_clock_out_time', '22:00')));
        $now = now();
        $closed = 0;

        foreach (TimeEntry::active()->orderBy('clock_in_at')->cursor() as $entry) {
            $cutoff = $entry->clock_in_at->copy()->setTime($h, $m, 0);
            if ($cutoff->lte($entry->clock_in_at)) {
                // Clocked in after the cut-off (a night shift): close at the next day's cut-off.
                $cutoff->addDay();
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
