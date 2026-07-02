<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\DatabaseManagementService;
use Carbon\Carbon;

/**
 * php artisan database:run-scheduled-backups
 *
 * DEPLOY TO: app/Console/Commands/RunScheduledBackups.php
 * REGISTER:  $schedule->command('database:run-scheduled-backups')->everyMinute()->withoutOverlapping();
 *
 * Designed to run every minute via the scheduler. It checks the single
 * backup_schedules config row and only actually fires a backup once per
 * configured slot (guarded by last_run_at so a missed/late cron tick won't
 * double-run, and so changing the schedule mid-day doesn't trigger an
 * immediate extra run).
 */
class RunScheduledBackups extends Command
{
    protected $signature   = 'database:run-scheduled-backups {--force : Run immediately regardless of schedule}';
    protected $description = 'Run a scheduled database backup if one is due according to backup_schedules config.';

    public function handle(DatabaseManagementService $service): int
    {
        $schedule = DB::table('backup_schedules')->first();

        if (!$schedule) {
            $this->info('No backup schedule configured. Skipping.');
            return self::SUCCESS;
        }

        if (!$schedule->is_enabled && !$this->option('force')) {
            $this->info('Scheduled backups are disabled. Skipping.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->isDue($schedule)) {
            return self::SUCCESS; // not an error - most invocations (every minute) will hit this
        }

        $this->info('Running scheduled backup...');

        try {
            $backup = $service->createBackup('scheduled', 'schedule', null, $schedule->disk);

            DB::table('backup_schedules')->where('id', $schedule->id)->update([
                'last_run_at'     => now(),
                'last_run_status' => 'success',
                'last_run_error'  => null,
                'updated_at'      => now(),
            ]);

            $pruned = $service->pruneOldBackups($schedule->disk, (int) $schedule->retain_count);

            $this->info("Scheduled backup #{$backup->id} created successfully ({$backup->filename}). Pruned {$pruned} old backup(s).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::table('backup_schedules')->where('id', $schedule->id)->update([
                'last_run_at'     => now(),
                'last_run_status' => 'failed',
                'last_run_error'  => $e->getMessage(),
                'updated_at'      => now(),
            ]);

            $this->error('Scheduled backup failed: ' . $e->getMessage());
            $this->notifyFailure($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Determine whether "now" falls within the configured run slot AND we
     * haven't already run for this slot (so an every-minute cron doesn't
     * fire 60 times within the target minute, and a schedule change doesn't
     * cause a backup to fire twice in the same day).
     */
    private function isDue(object $schedule): bool
    {
        $now   = Carbon::now();
        $runAt = Carbon::parse($schedule->run_at);

        if ($now->format('H:i') !== $runAt->format('H:i')) {
            return false;
        }

        switch ($schedule->frequency) {
            case 'weekly':
                if ($schedule->day_of_week !== null && (int) $now->dayOfWeek !== (int) $schedule->day_of_week) {
                    return false;
                }
                break;
            case 'monthly':
                if ($schedule->day_of_month !== null && (int) $now->day !== (int) $schedule->day_of_month) {
                    return false;
                }
                break;
            case 'daily':
            default:
                break;
        }

        // Already ran today - don't double-fire within the same day.
        if ($schedule->last_run_at && Carbon::parse($schedule->last_run_at)->isSameDay($now)) {
            return false;
        }

        return true;
    }

    private function notifyFailure(string $error): void
    {
        $email = DB::table('settings')->where('key', 'backup_notify_email')->value('value');
        if (empty($email)) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Mail::raw(
                "Scheduled database backup failed.\n\nError: {$error}\n\nPlease check the Database Management panel.",
                function ($message) use ($email) {
                    $message->to($email)->subject('[Alert] Scheduled database backup failed');
                }
            );
        } catch (\Throwable) {
            // Don't let a notification failure mask the original backup failure in the command's exit code.
        }
    }
}
