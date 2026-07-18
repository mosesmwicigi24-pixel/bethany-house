<?php

namespace App\Console\Commands;

use App\Services\Reporting\MetricEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The morning brief: MetricEngine's attention feed — the same deterministic,
 * data-cited detectors the Executive Dashboard shows — delivered by email
 * before the workday starts, using the recipients already configured for
 * EoD delivery (system_settings.eod_delivery).
 *
 * Sends only when something needs attention: an empty feed is logged, not
 * mailed — the digest earns its place in the inbox by never being noise.
 *
 * Schedule: dailyAt 07:30 (routes/console.php). Duplicate sends are guarded
 * by insights_digest_last_sent in system_settings.
 *
 * Manual run: php artisan insights:digest [--force]
 */
class SendInsightsDigest extends Command
{
    protected $signature = 'insights:digest {--force : Ignore the already-sent-today guard}';

    protected $description = 'Email the morning insights digest (attention feed) to EoD recipients';

    public function handle(): int
    {
        $raw = DB::table('system_settings')->where('key', 'eod_delivery')->value('value');
        $settings = $raw ? (json_decode($raw, true) ?: []) : [];
        $recipients = !empty($settings['email_enabled']) ? ($settings['email_recipients'] ?? []) : [];

        if (empty($recipients)) {
            $this->info('No EoD email recipients configured — digest skipped.');
            return self::SUCCESS;
        }

        $today = now()->toDateString();
        $lastSent = DB::table('system_settings')->where('key', 'insights_digest_last_sent')->value('value');
        if (!$this->option('force') && $lastSent === $today) {
            $this->info('Digest already sent today.');
            return self::SUCCESS;
        }

        $items = MetricEngine::unscoped()->attention();

        if (empty($items)) {
            $this->info('Nothing needs attention — no digest sent.');
            Log::info('Insights digest: attention feed empty, skipped.');
            return self::SUCCESS;
        }

        $high = count(array_filter($items, fn ($i) => $i['severity'] === 'high'));
        $subject = sprintf(
            'Morning brief — %d item%s need%s attention%s',
            count($items),
            count($items) === 1 ? '' : 's',
            count($items) === 1 ? 's' : '',
            $high > 0 ? " ({$high} urgent)" : '',
        );

        $lines = ["Good morning,", "", "Here is what the numbers say needs you today:", ""];
        foreach ($items as $i => $item) {
            $flag = $item['severity'] === 'high' ? '[URGENT]' : '[watch]';
            $lines[] = ($i + 1) . ". {$flag} {$item['title']}";
            $lines[] = "   {$item['detail']}";
            $lines[] = '';
        }
        $lines[] = 'Every number above is computed from live records — open Reports in the portal to drill into the rows behind it.';
        $body = implode("\n", $lines);

        foreach ($recipients as $address) {
            try {
                Mail::raw($body, function ($m) use ($address, $subject) {
                    $m->to($address)->subject($subject);
                });
                $this->info("Sent to {$address}");
            } catch (\Exception $e) {
                Log::error("Insights digest failed for {$address}: {$e->getMessage()}");
                $this->error("Failed for {$address}");
            }
        }

        DB::table('system_settings')->upsert(
            ['key' => 'insights_digest_last_sent', 'value' => $today, 'updated_at' => now()],
            ['key'],
            ['value', 'updated_at'],
        );

        return self::SUCCESS;
    }
}
