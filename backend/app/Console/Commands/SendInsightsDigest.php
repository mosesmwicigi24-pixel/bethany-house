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

        // The settings page stores recipients as comma-separated STRINGS;
        // older test fixtures used arrays. Accept both.
        $split = fn ($v) => is_array($v)
            ? array_values(array_filter($v))
            : array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $v))));

        $recipients = !empty($settings['email_enabled']) ? $split($settings['email_recipients'] ?? '') : [];
        $waNumbers  = !empty($settings['whatsapp_enabled']) ? $split($settings['whatsapp_recipients'] ?? '') : [];

        if (empty($recipients) && empty($waNumbers)) {
            $this->info('No EoD email or WhatsApp recipients configured — digest skipped.');
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
        // The Engine Room strip, compacted: one headline number per revenue
        // engine, only when at least one engine has something to say.
        foreach ($this->engineRoomLines() as $line) {
            $lines[] = $line;
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

        // WhatsApp rail: same brief, *bold* headline per WhatsApp convention.
        if (!empty($waNumbers)) {
            $waBody = "*{$subject}*\n\n" . $body;
            foreach ($waNumbers as $number) {
                if (\App\Services\WhatsAppService::send($number, $waBody)) {
                    $this->info("WhatsApp sent to {$number}");
                } else {
                    $this->error("WhatsApp failed for {$number}");
                }
            }
        }

        DB::table('system_settings')->upsert(
            ['key' => 'insights_digest_last_sent', 'value' => $today, 'updated_at' => now()],
            ['key'],
            ['value', 'updated_at'],
        );

        return self::SUCCESS;
    }

    /**
     * The compact ENGINE ROOM block — the same six summaries the Overview
     * strip renders (GET /reports/engine-room), one headline number each.
     *
     * Every engine is computed independently with the endpoint's try/catch
     * degrade (a broken engine is skipped, never a broken digest), and the
     * engines are all served from their own 10-minute caches — attention()
     * above has usually warmed them already. Returns [] (no block at all)
     * when every engine is null or has nothing to report, so the digest
     * stays noise-free on quiet days.
     *
     * @return string[] block lines, engines paired two-per-line to stay short
     */
    private function engineRoomLines(): array
    {
        $engine = MetricEngine::unscoped();
        $safe = static function (callable $compute) {
            try {
                return $compute();
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        };

        $collections   = $safe(static fn () => $engine->collectionsFunnel()['summary']);
        $stockout      = $safe(static fn () => $engine->stockoutLoss()['summary']);
        $winback       = $safe(static fn () => $engine->winBackEconomics()['summary']);
        $attach        = $safe(static fn () => $engine->attachRates()['summary']);
        $replenishment = $safe(static fn () => $engine->replenishmentRadar()['summary']);
        $seasonal      = $safe(static fn () => $engine->seasonalDemand()['summary']);

        // Nothing to say? No block. (?? swallows null-engine access safely.)
        $hasSignal = (float) ($collections['money_on_table'] ?? 0) > 0
            || (float) ($stockout['est_daily_loss_now'] ?? 0) > 0
            || (float) ($winback['annual_value_at_risk'] ?? 0) > 0
            || (float) ($attach['missed_revenue_estimate_total'] ?? 0) > 0
            || (int) ($replenishment['due_pairs'] ?? 0) > 0
            || (float) ($seasonal['total_gap_value'] ?? 0) > 0
            || (int) ($seasonal['urgent_orders'] ?? 0) > 0;
        if (!$hasSignal) {
            return [];
        }

        $kes = static fn ($v): string => 'KES ' . number_format(round((float) $v));

        $parts = [];
        if ($collections !== null) {
            $parts[] = '💰 On the table: ' . $kes($collections['money_on_table']);
        }
        if ($stockout !== null) {
            $parts[] = '📉 Bleeding: ' . $kes($stockout['est_daily_loss_now']) . '/day';
        }
        if ($winback !== null) {
            $parts[] = '🔄 At risk: ' . $kes($winback['annual_value_at_risk']);
        }
        if ($attach !== null) {
            $parts[] = '🛒 Missed attach: ' . $kes($attach['missed_revenue_estimate_total']);
        }
        if ($replenishment !== null) {
            $parts[] = '🎯 Radar due: ' . (int) $replenishment['due_pairs']
                . ' (' . $kes($replenishment['expected_revenue']) . ')';
        }
        if ($seasonal !== null && ($next = $seasonal['next_season'] ?? null) !== null) {
            $days = max(0, (int) round(now()->startOfDay()->diffInDays(
                \Carbon\Carbon::parse($next['start'])->startOfDay(), false,
            )));
            $gap = (float) $seasonal['total_gap_value'];
            $parts[] = "⛪ {$next['label']} in {$days}d, gap "
                . ($gap > 0 ? $kes($gap) : 'KES —');
        }

        // Two engines per line keeps the block under ~4 short lines.
        $lines = ['ENGINE ROOM'];
        foreach (array_chunk($parts, 2) as $pair) {
            $lines[] = implode(' · ', $pair);
        }
        $lines[] = '';

        return $lines;
    }
}
