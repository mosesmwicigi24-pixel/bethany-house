<?php

namespace App\Console\Commands;

use App\Models\LocationPing;
use App\Support\AttendanceRules;
use Illuminate\Console\Command;

/** Delete shift trails older than the retention rule (People C1). Punch positions on the entry stay. */
class AttendancePruneTrails extends Command
{
    protected $signature = 'attendance:prune-trails {--dry-run : Count only}';
    protected $description = 'Delete location trail points older than attendance.trail_retention_days';

    public function handle(): int
    {
        $days = max(7, (int) AttendanceRules::get('trail_retention_days'));
        $q = LocationPing::where('recorded_at', '<', now()->subDays($days));
        $n = $q->count();
        if (!$this->option('dry-run') && $n) $q->delete();
        $this->info(($this->option('dry-run') ? 'Would delete ' : 'Deleted ') . "{$n} point(s) older than {$days} days.");
        return self::SUCCESS;
    }
}
