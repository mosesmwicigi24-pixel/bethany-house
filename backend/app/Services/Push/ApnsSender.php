<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Apple Push Notification service, token-based (JWT ES256) authentication.
 * https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns
 *
 * One HTTP/2 POST per device token. The provider JWT is cached for 50
 * minutes (Apple accepts a token for an hour and refuses one refreshed more
 * than once every 20 minutes). A 410 or a BadDeviceToken / Unregistered
 * reason marks the registration inactive, as the Expo and Web Push paths do.
 *
 * No credentials → disabled: delivery is skipped with one log line.
 */
class ApnsSender
{
    public function enabled(): bool
    {
        $c = config('push.apns');

        return !empty($c['key_id']) && !empty($c['team_id']) && !empty($c['private_key']) && !empty($c['bundle_id']);
    }

    public function host(): string
    {
        return config('push.apns.environment') === 'sandbox'
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';
    }

    /**
     * @param  array  $data  Extra keys delivered in the notification payload.
     */
    public function send(PushSubscription $sub, string $title, string $body, string $url = '/', string $icon = 'bell', array $data = []): void
    {
        if (!$this->enabled()) {
            Log::info('[APNs] Not configured; skipping delivery', ['subscription_id' => $sub->id]);
            return;
        }

        try {
            $payload = [
                'aps' => [
                    'alert'           => ['title' => $title, 'body' => $body],
                    'sound'           => 'default',
                    'thread-id'       => "bh-{$icon}",
                    'mutable-content' => 1,
                ],
                // Same keys the Expo and Web Push paths deliver: the app opens `url`.
                'url'  => $url,
                'icon' => $icon,
                'data' => $data,
            ];

            $response = Http::withToken($this->providerJwt(), 'bearer')
                ->withHeaders([
                    'apns-topic'      => config('push.apns.bundle_id'),
                    'apns-push-type'  => 'alert',
                    'apns-priority'   => '10',
                    'apns-expiration' => (string) (time() + 86400),
                    'apns-collapse-id'=> substr("bh-{$icon}", 0, 64),
                ])
                ->withOptions(['version' => 2.0])
                ->timeout((int) config('push.timeout', 5))
                ->connectTimeout(3)
                ->post($this->host() . '/3/device/' . $sub->device_token, $payload);

            if ($response->successful()) {
                return;
            }

            $reason = (string) ($response->json('reason') ?? '');
            Log::warning('[APNs] Delivery failed', [
                'status'       => $response->status(),
                'reason'       => $reason,
                'token_prefix' => substr((string) $sub->device_token, 0, 12),
            ]);

            if ($response->status() === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
                PushSubscription::whereKey($sub->id)->update(['is_active' => false]);
                Log::info('[APNs] Registration marked inactive', ['subscription_id' => $sub->id, 'reason' => $reason]);
            }
        } catch (\Throwable $e) {
            // Notifications never break the request that caused them.
            Log::warning('[APNs] Exception: ' . $e->getMessage(), ['subscription_id' => $sub->id]);
        }
    }

    /** The provider authentication token: ES256 JWT with the team's key. */
    public function providerJwt(): string
    {
        return Cache::remember('push.apns.provider_jwt', now()->addMinutes(50), function () {
            $header = $this->b64(json_encode(['alg' => 'ES256', 'kid' => config('push.apns.key_id')]));
            $claims = $this->b64(json_encode(['iss' => config('push.apns.team_id'), 'iat' => time()]));
            $input  = "{$header}.{$claims}";

            $key = openssl_pkey_get_private($this->privateKeyPem());
            if ($key === false) {
                throw new \RuntimeException('APNS_PRIVATE_KEY is not a readable EC private key');
            }
            $der = '';
            if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('APNs JWT signing failed');
            }

            return $input . '.' . $this->b64(self::derToRawSignature($der, 32));
        });
    }

    private function privateKeyPem(): string
    {
        $raw = (string) config('push.apns.private_key');
        if (str_starts_with($raw, '/') && is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        }

        return str_replace('\\n', "\n", $raw);
    }

    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /**
     * OpenSSL returns an ASN.1 DER ECDSA signature; JOSE wants r||s, each
     * left-padded to the curve size (32 bytes for P-256).
     */
    public static function derToRawSignature(string $der, int $partLength): string
    {
        $offset = 2; // SEQUENCE tag + length (short form is enough for P-256)
        if (ord($der[1]) & 0x80) {
            $offset = 2 + (ord($der[1]) & 0x7F);
        }

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new \RuntimeException('Unexpected DER signature structure');
            }
            $len    = ord($der[$offset + 1]);
            $value  = substr($der, $offset + 2, $len);
            $offset += 2 + $len;
            $value  = ltrim($value, "\x00");
            $parts[] = str_pad($value, $partLength, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }
}
