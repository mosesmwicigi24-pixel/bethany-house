<?php

namespace App\Console\Commands;

use App\Models\ChannelTouchpoint;
use App\Models\Customer;
use App\Services\Neema\NeemaAnalyticsClient;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nightly pull of Neema's per-person × per-channel message rollup into
 * channel_touchpoints, each phone matched to a hub customer (canonical E.164).
 * Idempotent — upserts on (phone, channel). No-ops cleanly when Neema is
 * unconfigured (the client returns []).
 *
 * Alongside the cumulative upsert, each run writes today's DELTA per
 * (phone, channel) into channel_touchpoint_daily, so message volume becomes
 * period-sliceable from deploy day onward (the cumulative totals cannot be
 * sliced retroactively). Delta rules:
 *   - first sight of a (phone, channel): the full incoming count IS the delta
 *     (it is genuinely new to us, whatever its true age upstream);
 *   - normal night: incoming total minus the stored total, floored at 0;
 *   - upstream counter reset (incoming < stored): clamp to the incoming value —
 *     the count restarted, so the incoming total is the best available "since
 *     reset" figure and subtracting would go negative.
 * Re-runs on the same day ACCUMULATE into that day's row (a second run only
 * adds the increment since the first), so running twice never double-counts.
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

            $incomingMessages = (int) ($r['messages'] ?? 0);
            $incomingInbound  = (int) ($r['inbound'] ?? 0);

            // Read the PREVIOUS cumulative totals before overwriting them —
            // they are the only baseline the daily delta can be computed from.
            $tp = ChannelTouchpoint::firstOrNew(['phone' => $phone, 'channel' => $r['channel']]);
            if ($tp->exists) {
                // Normal night: the increment. Counter reset upstream
                // (incoming < stored): clamp to the incoming value.
                $deltaMessages = $incomingMessages >= $tp->messages
                    ? $incomingMessages - $tp->messages : $incomingMessages;
                $deltaInbound = $incomingInbound >= $tp->inbound
                    ? $incomingInbound - $tp->inbound : $incomingInbound;
            } else {
                // First sight: the whole count is new to us.
                $deltaMessages = $incomingMessages;
                $deltaInbound  = $incomingInbound;
            }

            $tp->fill([
                'customer_id' => $customerId,
                'messages'    => $incomingMessages,
                'inbound'     => $incomingInbound,
                'first_seen'  => !empty($r['first_at']) ? Carbon::parse($r['first_at']) : null,
                'last_seen'   => !empty($r['last_at'])  ? Carbon::parse($r['last_at'])  : null,
                'synced_at'   => $now,
            ])->save();

            // Accumulate today's delta. ON CONFLICT adds rather than replaces so
            // a same-day re-run contributes only its increment, never a repeat.
            if ($deltaMessages > 0 || $deltaInbound > 0) {
                DB::statement(
                    'INSERT INTO channel_touchpoint_daily (date, channel, phone, messages, inbound, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (date, channel, phone)
                     DO UPDATE SET messages   = channel_touchpoint_daily.messages + EXCLUDED.messages,
                                   inbound    = channel_touchpoint_daily.inbound  + EXCLUDED.inbound,
                                   updated_at = EXCLUDED.updated_at',
                    [$now->toDateString(), $r['channel'], $phone, $deltaMessages, $deltaInbound, $now, $now],
                );
            }
            $synced++;
        }

        $this->info("Synced {$synced} touchpoints ({$matched} matched to a customer).");
        return self::SUCCESS;
    }
}
