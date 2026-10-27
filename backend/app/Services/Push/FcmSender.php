<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, HTTP v1 API, authenticated with a service
 * account (RS256 JWT exchanged for a short-lived OAuth2 access token).
 * https://firebase.google.com/docs/cloud-messaging/send-message
 *
 * One POST per registration token. An UNREGISTERED / INVALID_ARGUMENT
 * (stale token) response marks the registration inactive, as the Expo and
 * Web Push paths do.
 *
 * No credentials → disabled: delivery is skipped with one log line.
 */
class FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function enabled(): bool
    {
        return !empty(config('push.fcm.project_id')) && $this->serviceAccount() !== null;
    }

    /**
     * @param  array  $data  Extra keys delivered as the message's data map (strings only).
     */
    public function send(PushSubscription $sub, string $title, string $body, string $url = '/', string $icon = 'bell', array $data = []): void
    {
        if (!$this->enabled()) {
            Log::info('[FCM] Not configured; skipping delivery', ['subscription_id' => $sub->id]);
            return;
        }

        try {
            $dataMap = ['url' => $url, 'icon' => $icon];
            foreach ($data as $k => $v) {
                $dataMap[(string) $k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
            }

            $message = [
                'message' => [
                    'token'        => $sub->device_token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => $dataMap,
                    'android'      => [
                        'priority'     => 'high',
                        'ttl'          => '86400s',
                        'collapse_key' => substr("bh-{$icon}", 0, 64),
                        'notification' => [
                            'channel_id' => (string) config('push.fcm.channel_id', 'default'),
                            'sound'      => 'default',
                            'tag'        => "bh-{$icon}",
                        ],
                    ],
                ],
            ];

            $project  = config('push.fcm.project_id');
            $response = Http::withToken($this->accessToken())
                ->timeout((int) config('push.timeout', 5))
                ->connectTimeout(3)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", $message);

            if ($response->successful()) {
                return;
            }

            $status  = (string) ($response->json('error.status') ?? '');
            $details = $response->json('error.details') ?? [];
            $fcmCode = '';
            foreach ((array) $details as $d) {
                if (isset($d['errorCode'])) {
                    $fcmCode = (string) $d['errorCode'];
                }
            }

            Log::warning('[FCM] Delivery failed', [
                'status'       => $response->status(),
                'error'        => $status,
                'fcm_code'     => $fcmCode,
                'token_prefix' => substr((string) $sub->device_token, 0, 12),
            ]);

            // Only a token FCM itself reports as gone is retired; INVALID_ARGUMENT
            // can also mean a bad payload and must not silence a working device.
            if ($fcmCode === 'UNREGISTERED' || $status === 'NOT_FOUND') {
                PushSubscription::whereKey($sub->id)->update(['is_active' => false]);
                Log::info('[FCM] Registration marked inactive', ['subscription_id' => $sub->id, 'reason' => $fcmCode ?: $status]);
            }
        } catch (\Throwable $e) {
            Log::warning('[FCM] Exception: ' . $e->getMessage(), ['subscription_id' => $sub->id]);
        }
    }

    /** OAuth2 access token for the service account, cached just under its hour. */
    public function accessToken(): string
    {
        return Cache::remember('push.fcm.access_token', now()->addMinutes(50), function () {
            $sa  = $this->serviceAccount();
            $now = time();

            $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->b64(json_encode([
                'iss'   => $sa['client_email'],
                'scope' => self::SCOPE,
                'aud'   => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ]));
            $input = "{$header}.{$claims}";

            $key = openssl_pkey_get_private($sa['private_key']);
            if ($key === false) {
                throw new \RuntimeException('FCM service account private key is not readable');
            }
            $sig = '';
            if (!openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('FCM JWT signing failed');
            }
            $assertion = $input . '.' . $this->b64($sig);

            $response = Http::asForm()
                ->timeout((int) config('push.timeout', 5))
                ->post($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $assertion,
                ]);

            $token = $response->json('access_token');
            if (!$response->successful() || !is_string($token) || $token === '') {
                throw new \RuntimeException('FCM OAuth2 token exchange failed: HTTP ' . $response->status());
            }

            return $token;
        });
    }

    /** The service account JSON, from inline JSON, base64 JSON, or a file path. */
    public function serviceAccount(): ?array
    {
        $raw = (string) config('push.fcm.service_account');
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '/') && is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $decoded = base64_decode($raw, true);
            $json    = $decoded !== false ? json_decode($decoded, true) : null;
        }
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            return null;
        }

        return $json;
    }

    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
