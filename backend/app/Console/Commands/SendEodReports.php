<?php

namespace App\Console\Commands;

use App\Jobs\SendEodReportEmail;
use App\Jobs\SendEodReportSlack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DEPLOY TO: app/Console/Commands/SendEodReports.php
 *
 * REGISTER IN: app/Console/Kernel.php
 *   $schedule->command('eod:send-reports')->everyMinute();
 *
 * The command runs every minute but internally checks whether the
 * configured send time has been reached for each channel. This means
 * the report fires within one minute of the configured time rather than
 * requiring an exact cron match, and avoids duplicate sends by tracking
 * the last-sent date in system_settings.
 *
 * MANUAL RUN:
 *   php artisan eod:send-reports              # uses yesterday's date
 *   php artisan eod:send-reports --date=2026-06-15
 *   php artisan eod:send-reports --force      # ignore time-of-day check
 */
class SendEodReports extends Command
{
    protected $signature = 'eod:send-reports
                            {--date=   : Date to report on (Y-m-d). Defaults to yesterday.}
                            {--force   : Bypass the time-of-day and already-sent checks.}';

    protected $description = 'Send consolidated EoD cashier reports via email and/or Slack';

    public function handle(): int
    {
        $settings = $this->loadSettings();

        if (empty($settings)) {
            $this->info('EoD delivery not configured, skipping.');
            return self::SUCCESS;
        }

        // Determine report date: explicit flag → yesterday (reports are for the previous day)
        $date = $this->option('date')
            ? $this->option('date')
            : now()->subDay()->toDateString();

        $outletIds = $settings['outlet_ids'] ?? [];
        $force     = (bool) $this->option('force');

        $sent = false;

        // ── Email channel ─────────────────────────────────────────────────────

        if (!empty($settings['email_enabled']) && !empty($settings['email_recipients'])) {
            $frequency = $settings['email_frequency'] ?? 'daily';

            if ($force || $this->shouldSend($frequency, $settings['email_time'] ?? '21:00', 'email', $date)) {
                $recipients = array_values(array_filter(
                    array_map('trim', explode(',', $settings['email_recipients'])),
                    fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
                ));

                if (!empty($recipients)) {
                    dispatch(new SendEodReportEmail($recipients, $date, $outletIds));
                    $this->markSent('email', $date);
                    $this->info("EoD email queued for {$date} → " . implode(', ', $recipients));
                    $sent = true;
                } else {
                    $this->warn('Email enabled but no valid recipients configured.');
                }
            }
        }

        // ── Slack channel ─────────────────────────────────────────────────────

        if (!empty($settings['slack_enabled']) && !empty($settings['slack_webhook'])) {
            $frequency = $settings['slack_frequency'] ?? 'daily';

            if ($force || $this->shouldSend($frequency, $settings['slack_time'] ?? '21:00', 'slack', $date)) {
                dispatch(new SendEodReportSlack($settings['slack_webhook'], $date, $outletIds));
                $this->markSent('slack', $date);
                $this->info("EoD Slack message queued for {$date}.");
                $sent = true;
            }
        }

        if (!$sent) {
            $this->line('EoD: no channels triggered at this time.');
        }

        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function loadSettings(): array
    {
        $raw = DB::table('system_settings')
            ->where('key', 'eod_delivery')
            ->value('value');

        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    /**
     * Returns true when:
     * 1. The frequency allows a send today (daily = always, weekly = Mon–Fri)
     * 2. The current time is at or past the configured send time
     * 3. This channel has not already been sent for this date
     */
    private function shouldSend(string $frequency, string $sendTime, string $channel, string $date): bool
    {
        // Frequency check
        if ($frequency === 'off') return false;
        if ($frequency === 'weekly' && now()->isWeekend()) return false;

        // Time-of-day check — only fire once the clock has passed the configured HH:MM
        [$hour, $minute] = array_map('intval', explode(':', $sendTime));
        $configuredAt = now()->copy()->setTime($hour, $minute, 0);
        if (now()->lessThan($configuredAt)) return false;

        // Already-sent check — avoid re-sending on the same date
        $lastSentKey = "eod_last_sent_{$channel}";
        $lastSent    = DB::table('system_settings')
            ->where('key', $lastSentKey)
            ->value('value');

        if ($lastSent && trim($lastSent, '"') === $date) {
            $this->line("EoD {$channel}: already sent for {$date}, skipping.");
            return false;
        }

        return true;
    }

    /**
     * Persist the date we just sent for this channel so we don't double-send.
     */
    private function markSent(string $channel, string $date): void
    {
        $key = "eod_last_sent_{$channel}";

        DB::table('system_settings')->upsert(
            ['key' => $key, 'value' => json_encode($date), 'updated_at' => now()],
            ['key'],
            ['value', 'updated_at'],
        );

        Log::info("EoD {$channel} marked sent for {$date}.");
    }
}