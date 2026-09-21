<?php

namespace App\Console\Commands;

use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Weekly retention (bootstrap/app.php → logs:purge-old).
 *
 * It used to delete every activity_log row older than 90 days, the 90 read
 * from a settings row a super admin could lower to 30 — so the trail of what
 * someone did could be made to disappear by changing a number and waiting a
 * week. It had not yet deleted anything (the oldest row was exactly 90 days
 * old when this was rewritten), but the next run would have.
 *
 * Now:
 *  - activity_log is never pruned (config audit.activity_log_prunable). The
 *    database refuses the DELETE anyway.
 *  - request_logs keeps audit.request_log.retention_days (default 365, floor
 *    90, from the server environment — not from a setting). The delete runs
 *    in a transaction that sets audit.allow_prune, the single exception the
 *    append-only trigger makes.
 */
class PurgeOldActivityLogs extends Command
{
    protected $signature   = 'logs:purge-old';
    protected $description = 'Prune request_logs past retention. The activity log itself is never pruned.';

    public function handle(): int
    {
        $days   = (int) config('audit.request_log.retention_days', 365);
        $cutoff = now()->subDays($days);

        $deleted = DB::transaction(function () use ($cutoff) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SET LOCAL audit.allow_prune = 'on'");
            }
            return DB::table('request_logs')->where('occurred_at', '<', $cutoff)->delete();
        });

        ActivityLogService::log('logs_purged', null, [
            'table'          => 'request_logs',
            'deleted_count'  => $deleted,
            'retention_days' => $days,
            'activity_log'   => 'never pruned',
        ], "Retention: pruned {$deleted} request-log rows older than {$days} days (activity log is kept permanently)");

        $this->info("Pruned {$deleted} request_logs rows older than {$days} days. activity_log is never pruned.");

        return self::SUCCESS;
    }
}
