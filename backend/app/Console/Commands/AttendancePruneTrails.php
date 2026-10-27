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
        // C2: kiosk punch photos live as long as the trails.
        $photos = \App\Models\TimeEntry::where('clock_in_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNotNull('clock_in_photo_path')->orWhereNotNull('clock_out_photo_path'));
        $p = $photos->count();
        if (!$this->option('dry-run') && $p) {
            foreach ($photos->cursor() as $e) {
                foreach (array_filter([$e->clock_in_photo_path, $e->clock_out_photo_path]) as $path) \Illuminate\Support\Facades\Storage::disk('private')->delete($path);
                $e->forceFill(['clock_in_photo_path' => null, 'clock_out_photo_path' => null])->saveQuietly();
            }
        }
        $this->info(($this->option('dry-run') ? 'Would delete ' : 'Deleted ') . "{$n} point(s) and {$p} photo set(s) older than {$days} days.");
        return self::SUCCESS;
    }
}
