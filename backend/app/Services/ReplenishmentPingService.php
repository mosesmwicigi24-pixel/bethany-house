<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ReplenishmentPing;
use App\Services\Reporting\MetricEngine;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Automated WhatsApp reorder pings, driven by the Replenishment Radar.
 *
 * The radar (MetricEngine::replenishmentRadar) detects per-(customer, product)
 * reorder rhythms and surfaces who is due to rebuy. This service turns that
 * list into business-initiated WhatsApp template messages ('reorder_reminder',
 * MARKETING) — and when the customer replies, the conversation lands in
 * Neema's webhook (the Hub and Neema share one WhatsApp number), so the AI
 * handles the order from there. The Hub only sends.
 *
 * Safety rails, in order of evaluation per due row:
 *   - no phone           → skipped_no_phone (email-only identities)
 *   - customer opted out → skipped_optout (customers.wa_marketing_opt_out)
 *   - recently pinged    → skipped_cooldown: a SENT ping for the same
 *     (phone, product) within max(cycle_days, 21) days blocks a resend, so
 *     nobody is nagged more than once per cycle.
 *   - hard cap           → at most $limit send attempts per run.
 *
 * Every decision — sent, failed, or skipped — is a replenishment_pings row,
 * so the trail is auditable and the cooldown enforceable. Dry runs write
 * nothing and send nothing; they only report what would happen.
 */
class ReplenishmentPingService
{
    private const MIN_COOLDOWN_DAYS = 21;

    /** How deep into the radar's due list a run will look. */
    private const RADAR_DEPTH = 500;

    /**
     * @return array{
     *   scanned:int, sent:int, failed:int,
     *   skipped_cooldown:int, skipped_optout:int, skipped_no_phone:int,
     *   capped:int, dry_run:bool,
     *   rows:list<array{name:string, phone:?string, product:string, outcome:string, detail:?string}>
     * }
     */
    public function run(bool $dryRun = false, int $limit = 20): array
    {
        $radar = MetricEngine::unscoped()->replenishmentRadar(self::RADAR_DEPTH);

        $counts = [
            'scanned'          => 0,
            'sent'             => 0,
            'failed'           => 0,
            'skipped_cooldown' => 0,
            'skipped_optout'   => 0,
            'skipped_no_phone' => 0,
            'capped'           => 0,
            'dry_run'          => $dryRun,
            'rows'             => [],
        ];

        $attempts = 0;
        $now      = CarbonImmutable::now();

        foreach ($radar['due'] as $row) {
            $counts['scanned']++;
            $canonical = Phone::canonical($row['phone'] ?? null);

            if ($canonical === null) {
                $this->record($counts, $row, null, null, ReplenishmentPing::STATUS_SKIPPED_NO_PHONE, null, null, $dryRun);
                continue;
            }

            $customer = $this->matchCustomer($row['ckey'], $canonical);

            if ($customer && $customer->wa_marketing_opt_out) {
                $this->record($counts, $row, $canonical, $customer, ReplenishmentPing::STATUS_SKIPPED_OPTOUT, null, null, $dryRun);
                continue;
            }

            $cooldownDays = max((int) $row['cycle_days'], self::MIN_COOLDOWN_DAYS);
            $recentlySent = ReplenishmentPing::query()
                ->where('phone', $canonical)
                ->where('product_id', $row['product_id'])
                ->where('status', ReplenishmentPing::STATUS_SENT)
                ->where('created_at', '>=', $now->subDays($cooldownDays))
                ->exists();

            if ($recentlySent) {
                $this->record($counts, $row, $canonical, $customer, ReplenishmentPing::STATUS_SKIPPED_COOLDOWN, null, null, $dryRun);
                continue;
            }

            if ($attempts >= $limit) {
                $counts['capped']++;
                continue;
            }
            $attempts++;

            if ($dryRun) {
                $counts['sent']++;
                $counts['rows'][] = [
                    'name'    => $row['name'],
                    'phone'   => $canonical,
                    'product' => $row['product_name'],
                    'outcome' => 'would_send',
                    'detail'  => null,
                ];
                continue;
            }

            $result = WhatsAppService::sendTemplateDetailed(
                $canonical,
                config('services.whatsapp.reorder_template', 'reorder_reminder'),
                config('services.whatsapp.reorder_template_lang', 'en'),
                $this->components($row),
            );

            $status = $result['ok'] ? ReplenishmentPing::STATUS_SENT : ReplenishmentPing::STATUS_FAILED;
            $this->record($counts, $row, $canonical, $customer, $status, $result['wamid'], $result['error'], false);
        }

        Log::info('Replenishment pings run complete', collect($counts)->except('rows')->all());

        return $counts;
    }

    /**
     * Template body params: {{1}} first name, {{2}} days since the last
     * order, {{3}} product name — plus the quick-reply payload Neema sees
     * in its webhook when the customer taps "Yes, prepare my order".
     */
    private function components(array $row): array
    {
        $firstName = trim(explode(' ', trim((string) $row['name']))[0] ?? '');
        if ($firstName === '' || preg_match('/^\+?\d+$/', $firstName)) {
            $firstName = 'there'; // radar falls back to the phone as the name
        }

        $daysSince = max(1, (int) CarbonImmutable::parse($row['last_purchase_at'])->diffInDays(CarbonImmutable::now()));

        return [
            [
                'type'       => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $firstName],
                    ['type' => 'text', 'text' => (string) $daysSince],
                    ['type' => 'text', 'text' => (string) $row['product_name']],
                ],
            ],
            [
                'type'       => 'button',
                'sub_type'   => 'quick_reply',
                'index'      => '0',
                'parameters' => [['type' => 'payload', 'payload' => 'REORDER_YES']],
            ],
        ];
    }

    /**
     * The radar's ckey is customer_id when the order carried one, else the
     * normalized phone, else a lowercased email — so a numeric ckey IS a
     * customer id. Otherwise fall back to canonical-phone matching against
     * the customers table (full E.164, never last-N digits).
     */
    private function matchCustomer(string $ckey, string $canonical): ?Customer
    {
        if (ctype_digit($ckey) && strlen($ckey) < 10) {
            $byId = Customer::find((int) $ckey);
            if ($byId) {
                return $byId;
            }
        }

        // normalize_phone() returns '+254…' while Phone::canonical() returns
        // bare digits — same E.164 rules, different notation. Bridge it here.
        return Customer::query()
            ->whereNotNull('phone')
            ->whereRaw('normalize_phone(phone) = ?', ['+' . $canonical])
            ->first();
    }

    private function record(
        array &$counts,
        array $row,
        ?string $canonical,
        ?Customer $customer,
        string $status,
        ?string $wamid,
        ?string $error,
        bool $dryRun,
    ): void {
        $counts[$status] = ($counts[$status] ?? 0) + 1;
        $counts['rows'][] = [
            'name'    => $row['name'],
            'phone'   => $canonical,
            'product' => $row['product_name'],
            'outcome' => $status,
            'detail'  => $error,
        ];

        if ($dryRun) {
            return; // dry runs leave no trail — they change nothing.
        }

        ReplenishmentPing::create([
            'phone'        => $canonical ?? '',
            'customer_id'  => $customer?->id,
            'product_id'   => $row['product_id'],
            'product_name' => $row['product_name'],
            'cycle_days'   => $row['cycle_days'],
            'days_over'    => $row['days_over'],
            'avg_value'    => $row['avg_value'],
            'status'       => $status,
            'wamid'        => $wamid,
            'error'        => $error,
        ]);
    }
}
