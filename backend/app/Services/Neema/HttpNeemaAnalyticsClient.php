<?php

namespace App\Services\Neema;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live HTTP client for Neema's message rollup. Sends the shared X-Analytics-Key.
 * Never throws — a rollup failure must not break the nightly sync; it returns []
 * and logs, leaving existing touchpoints untouched.
 */
class HttpNeemaAnalyticsClient implements NeemaAnalyticsClient
{
    public function messageRollup(int $sinceDays = 365): array
    {
        $url = rtrim((string) config('services.neema.url'), '/');
        $key = (string) config('services.neema.analytics_key');

        if ($url === '' || $key === '') {
            return [];   // not configured → no-op
        }

        try {
            $res = Http::withHeaders(['X-Analytics-Key' => $key])
                ->timeout(20)
                ->get("{$url}/api/admin/analytics/message-rollup", ['since_days' => $sinceDays]);

            if (!$res->successful()) {
                Log::warning('Neema message-rollup failed', ['status' => $res->status()]);
                return [];
            }
            return $res->json('rows', []);
        } catch (\Throwable $e) {
            Log::warning('Neema message-rollup error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
