<?php

namespace App\Console\Commands;

use App\Models\ChannelTouchpoint;
use App\Models\Customer;
use App\Services\Neema\NeemaAnalyticsClient;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nightly pull of Neema's per-person × per-channel message rollup into
 * channel_touchpoints, each phone matched to a hub customer (canonical E.164).
 * Idempotent — upserts on (phone, channel). No-ops cleanly when Neema is
 * unconfigured (the client returns []).
 */
class SyncChannelTouchpoints extends Command
{
    protected $signature   = 'channels:sync-touchpoints {--since-days=365}';
    protected $description = 'Pull cross-channel message engagement from Neema into channel_touchpoints';

    public function handle(NeemaAnalyticsClient $neema): int
    {
        $rows = $neema->messageRollup((int) $this->option('since-days'));
        if (empty($rows)) {
            $this->info('No rollup rows (Neema unconfigured or empty) — nothing to sync.');
            return self::SUCCESS;
        }

        // phone (canonical) → customer_id, from every customer phone we hold.
        $phoneToCustomer = [];
        Customer::query()->whereNotNull('phone')->select('id', 'phone')->chunk(500, function ($chunk) use (&$phoneToCustomer) {
            foreach ($chunk as $c) {
                if ($key = Phone::canonical($c->phone)) {
                    $phoneToCustomer[$key] ??= $c->id;
                }
            }
        });

        $now = Carbon::now();
        $synced = 0;
        $matched = 0;

        foreach ($rows as $r) {
            $phone = Phone::canonical($r['phone'] ?? null);
            if (!$phone || empty($r['channel'])) {
                continue;
            }
            $customerId = $phoneToCustomer[$phone] ?? null;
            if ($customerId) {
                $matched++;
            }

            ChannelTouchpoint::updateOrCreate(
                ['phone' => $phone, 'channel' => $r['channel']],
                [
                    'customer_id' => $customerId,
                    'messages'    => (int) ($r['messages'] ?? 0),
                    'inbound'     => (int) ($r['inbound'] ?? 0),
                    'first_seen'  => !empty($r['first_at']) ? Carbon::parse($r['first_at']) : null,
                    'last_seen'   => !empty($r['last_at'])  ? Carbon::parse($r['last_at'])  : null,
                    'synced_at'   => $now,
                ],
            );
            $synced++;
        }

        $this->info("Synced {$synced} touchpoints ({$matched} matched to a customer).");
        return self::SUCCESS;
    }
}
