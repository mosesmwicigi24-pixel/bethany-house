<?php

namespace App\Services\Storefront;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells bethanyhouse.co.ke that the catalogue changed, so a product edit shows
 * up on the storefront immediately.
 *
 * The storefront reads this hub over ISR with a 300s window. Without this ping
 * an owner who replaces a product photo sees nothing change for five minutes
 * and reasonably concludes the upload did not work. With it, the storefront
 * drops its cached copy on the next request.
 *
 * Contract: POST {STOREFRONT_URL}/api/revalidate, HMAC-signed
 * (X-Storefront-Signature: sha256=<hexdigest of the raw body>), body
 * {"tag":"catalog"}. Deliberately the same shape as NeemaEventEmitter — one
 * signing convention across every outbound hub webhook.
 *
 * INERT until STOREFRONT_REVALIDATE_SECRET is configured, and best-effort by
 * construction: an unreachable storefront must NEVER break a product save.
 */
class StorefrontRevalidator
{
    public static function catalogChanged(): void
    {
        self::revalidate('catalog');
    }

    public static function revalidate(string $tag): void
    {
        $secret = (string) config('services.storefront.revalidate_secret');
        $base   = rtrim((string) config('services.storefront.url'), '/');
        if ($secret === '' || $base === '') {
            return;
        }

        try {
            $body = json_encode(['tag' => $tag], JSON_UNESCAPED_UNICODE);
            $sig  = 'sha256=' . hash_hmac('sha256', $body, $secret);
            Http::timeout(4)
                ->withHeaders([
                    'Content-Type'            => 'application/json',
                    'X-Storefront-Signature'  => $sig,
                ])
                ->withBody($body, 'application/json')
                ->post($base . '/api/revalidate');
        } catch (\Throwable $e) {
            Log::info("Storefront revalidation ({$tag}) not delivered: " . $e->getMessage());
        }
    }
}
