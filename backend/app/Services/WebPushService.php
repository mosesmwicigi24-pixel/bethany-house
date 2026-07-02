<?php

namespace App\Services;

use App\Http\Controllers\Api\PushController;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * WebPushService
 *
 * Sends push notifications to a user's registered devices.
 * Supports two delivery channels:
 *
 *   • Web Push (VAPID) — browser PWA subscriptions (token_type = 'web')
 *   • Expo Push Service — React Native mobile app (token_type = 'expo')
 *
 * Called from NotificationService::send() alongside the existing
 * database + Reverb broadcast channels.
 *
 * Install dependency:
 *   composer require minishlink/web-push
 *
 * Config: config/webpush.php
 *
 * VAPID keys are generated once via:
 *   php artisan webpush:vapid
 * Then stored in .env:
 *   VAPID_PUBLIC_KEY=...
 *   VAPID_PRIVATE_KEY=...
 *   VAPID_SUBJECT=mailto:admin@bethanyhouse.com
 */
class WebPushService
{
    /**
     * Send a push notification to all active subscriptions for a user.
     *
     * Automatically routes each subscription to the correct delivery channel:
     *   - token_type = 'expo' → Expo Push Service (mobile app)
     *   - token_type = 'web'  → VAPID Web Push (PWA)
     *
     * @param  int    $userId
     * @param  string $title
     * @param  string $body
     * @param  string $url      Deep-link URL opened when notification is tapped
     * @param  string $icon     Icon name (matches NotifIcon map - used as tag)
     * @param  array  $data     Extra data merged into the push payload
     */
    public static function send(
        int    $userId,
        string $title,
        string $body,
        string $url   = '/',
        string $icon  = 'bell',
        array  $data  = []
    ): void {
        $allSubscriptions = PushSubscription::where('user_id', $userId)
            ->active()
            ->get();

        if ($allSubscriptions->isEmpty()) return;

        // ── Expo Push (React Native mobile app) ───────────────────────────────
        // Route first so a failure here never blocks the VAPID path below.

        $expoSubscriptions = $allSubscriptions->where('token_type', 'expo');

        foreach ($expoSubscriptions as $sub) {
            PushController::sendExpo(
                expoToken: $sub->expo_token,
                title:     $title,
                body:      $body,
                url:       $url,
                icon:      $icon,
                data:      $data,
            );
        }

        // ── VAPID Web Push (PWA) ──────────────────────────────────────────────

        $webSubscriptions = $allSubscriptions->where('token_type', 'web');

        if ($webSubscriptions->isEmpty()) return;

        $auth = [
            'VAPID' => [
                'subject'    => config('webpush.vapid.subject'),
                'publicKey'  => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ];

        // TTL 24 hours, normal urgency for staff alerts
        $webPush = new WebPush($auth, [
            'TTL'     => 86400,
            'urgency' => 'normal',
        ]);

        // Ensure the URL always includes the /admin prefix so the PWA
        // deep-link opens the correct route (app is served at /admin/).
        // Handles: null, "/", "/sales/orders/1" → "/admin/sales/orders/1"
        // Already-prefixed URLs ("/admin/...") are left unchanged.
        $adminUrl = $url ?: '/admin';
        if ($adminUrl !== '/admin' && !str_starts_with($adminUrl, '/admin')) {
            $adminUrl = '/admin' . $adminUrl;
        }

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => $adminUrl,
            'icon'  => '/admin/icons/icon-192.png',
            'badge' => '/admin/icons/badge-72.png',
            'tag'   => "bh-{$icon}",
            'data'  => $data,
        ]);

        foreach ($webSubscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create($sub->toWebPushArray()),
                $payload
            );
        }

        // Flush all queued notifications and handle responses
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                continue;
            }

            // 410 Gone = subscription has been revoked by the browser.
            // Mark as inactive so we stop sending to it.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $endpoint)
                    ->update(['is_active' => false]);

                Log::info('[WebPush] Subscription expired, marked inactive', [
                    'endpoint_prefix' => substr($endpoint, 0, 60),
                ]);
                continue;
            }

            Log::warning('[WebPush] Delivery failed', [
                'reason'          => $report->getReason(),
                'endpoint_prefix' => substr($endpoint, 0, 60),
            ]);
        }
    }
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * Create config/webpush.php with this content:
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * <?php
 * return [
 *     'vapid' => [
 *         'subject'     => env('VAPID_SUBJECT', 'mailto:admin@bethanyhouse.com'),
 *         'public_key'  => env('VAPID_PUBLIC_KEY', ''),
 *         'private_key' => env('VAPID_PRIVATE_KEY', ''),
 *     ],
 * ];
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Add to .env:
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * VAPID_SUBJECT=mailto:admin@bethanyhouse.com
 * VAPID_PUBLIC_KEY=           # filled by: php artisan webpush:vapid
 * VAPID_PRIVATE_KEY=          # filled by: php artisan webpush:vapid
 * VITE_VAPID_PUBLIC_KEY=      # same value as VAPID_PUBLIC_KEY, for the frontend
 *
 */