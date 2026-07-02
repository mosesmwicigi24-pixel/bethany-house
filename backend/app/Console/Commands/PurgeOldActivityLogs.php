<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DEPLOY TO: app/Console/Commands/PurgeOldActivityLogs.php
 * REGISTER: $schedule->command('logs:purge-old')->weekly();
 */
class PurgeOldActivityLogs extends Command
{
    protected $signature   = 'logs:purge-old {--days= : Override retention days}';
    protected $description = 'Delete activity logs older than the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days')
            ?? DB::table('settings')->where('key', 'audit_log_retention_days')->value('value')
            ?? 90);

        if ($days < 30) {
            $this->warn("Retention days must be at least 30. Defaulting to 90.");
            $days = 90;
        }

        $cutoff  = now()->subDays($days);
        $deleted = DB::table('activity_log')
            ->where('created_at', '<', $cutoff)
            ->delete();

        // Log the purge itself (so there's a record of it)
        DB::table('activity_log')->insert([
            'log_name'    => 'default',
            'description' => "Automated purge: deleted {$deleted} log entries older than {$days} days",
            'event'       => 'logs_purged',
            'properties'  => json_encode(['deleted_count' => $deleted, 'retention_days' => $days]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->info("Purged {$deleted} log entries older than {$days} days.");
        return self::SUCCESS;
    }
}