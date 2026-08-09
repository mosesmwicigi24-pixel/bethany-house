<?php

namespace App\Console\Commands;

use App\Services\ReplenishmentPingService;
use Illuminate\Console\Command;

/**
 * Send automated WhatsApp reorder reminders to customers the Replenishment
 * Radar says are due. Replies land in Neema's webhook (shared WhatsApp
 * number) — the AI takes the conversation from there; the Hub only sends.
 *
 *   php artisan replenishment:send-pings --dry-run    # see who WOULD be pinged
 *   php artisan replenishment:send-pings --limit=2    # careful first live run
 *   php artisan replenishment:send-pings              # normal run (cap 20)
 *
 * Scheduled daily at 10:00 Africa/Nairobi, gated on
 * REPLENISHMENT_PINGS_ENABLED=true (default OFF — marketing sends are a
 * conscious opt-in). Requires the 'reorder_reminder' template to be
 * APPROVED — see `php artisan whatsapp:reorder-template`.
 */
class SendReplenishmentPings extends Command
{
    protected $signature = 'replenishment:send-pings
        {--dry-run : Report who would be pinged without sending or recording anything}
        {--limit=20 : Maximum send attempts this run}';

    protected $description = 'Send WhatsApp reorder reminders to customers due on the Replenishment Radar';

    public function handle(ReplenishmentPingService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = max(0, (int) $this->option('limit'));

        $result = $service->run($dryRun, $limit);

        if ($dryRun) {
            $this->info("DRY RUN — nothing was sent or recorded. {$result['sent']} customer(s) would be pinged.");
        }

        if (!empty($result['rows'])) {
            $this->table(
                ['Customer', 'Phone', 'Product', 'Outcome', 'Detail'],
                array_map(fn ($r) => [
                    $r['name'], $r['phone'] ?? '-', $r['product'], $r['outcome'], $r['detail'] ?? '',
                ], $result['rows']),
            );
        }

        $this->line(sprintf(
            'Scanned %d due pair(s): %s%d, failed %d, cooldown %d, opt-out %d, no-phone %d, over cap %d.',
            $result['scanned'],
            $dryRun ? 'would send ' : 'sent ',
            $result['sent'],
            $result['failed'],
            $result['skipped_cooldown'],
            $result['skipped_optout'],
            $result['skipped_no_phone'],
            $result['capped'],
        ));

        return self::SUCCESS;
    }
}
