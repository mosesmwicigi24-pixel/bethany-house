<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PushController
 *
 * Handles push notification subscription management for both the PWA
 * (Web Push / VAPID) and the React Native mobile app (Expo Push Service).
 *
 * Routes (all under auth:sanctum middleware, prefix: /api/v1/admin/push):
 *
 *   Web Push (PWA):
 *     GET    /vapid-public-key   → Return VAPID public key for ServiceWorker
 *     POST   /subscribe          → Save or update a browser Web Push subscription
 *     DELETE /unsubscribe        → Remove a browser Web Push subscription
 *
 *   Expo Push (React Native mobile app):
 *     POST   /subscribe-expo     → Save or update an Expo push token
 *     POST   /unsubscribe-expo   → Remove an Expo push token
 */
class PushController extends Controller
{
    // ── GET /api/v1/admin/push/vapid-public-key ──────────────────────────────

    /**
     * Return the VAPID public key so the PWA frontend can call
     * pushManager.subscribe({ applicationServerKey: key }).
     *
     * This endpoint is authenticated so the key isn't publicly enumerable,
     * but the VAPID public key itself is not a secret — safe to expose
     * to any authenticated user.
     */
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => config('webpush.vapid.public_key'),
        ]);
    }

    // ── POST /api/v1/admin/push/subscribe ────────────────────────────────────

    /**
     * Save or refresh a Web Push (VAPID) subscription for the authenticated user.
     *
     * The browser sends the full PushSubscriptionJSON object:
     * {
     *   endpoint: "https://fcm.googleapis.com/fcm/send/...",
     *   keys: { p256dh: "BNcR...", auth: "tB3+" }
     * }
     *
     * Upserted on endpoint alone — endpoint is globally unique per browser.
     * Matching on user_id + endpoint causes a unique violation when the same
     * device re-registers under a different user (e.g. after re-login),
     * because PostgreSQL tries to INSERT before it detects the conflict.
     * Instead: match on endpoint, update user_id + keys so the subscription
     * is always associated with the currently logged-in user.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint'    => 'required|string|max:2000',
            'keys'        => 'required|array',
            'keys.p256dh' => 'required|string',
            'keys.auth'   => 'required|string',
        ]);

        PushSubscription::upsert(
            [
                'endpoint'   => $validated['endpoint'],
                'user_id'    => $request->user()->id,
                'p256dh'     => $validated['keys']['p256dh'],
                'auth'       => $validated['keys']['auth'],
                'token_type' => 'web',
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'is_active'  => true,
            ],
            ['endpoint'],
            ['user_id', 'p256dh', 'auth', 'token_type', 'user_agent', 'is_active']
        );

        return response()->json(['message' => 'Push subscription saved.'], 201);
    }

    // ── DELETE /api/v1/admin/push/unsubscribe ────────────────────────────────

    /**
     * Remove a Web Push (VAPID) subscription — called on logout or when the
     * user explicitly disables notifications in their browser.
     *
     * Matches on endpoint so only the specific device is unsubscribed,
     * not all devices for this user.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string|max:2000',
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['message' => 'Push subscription removed.']);
    }

    // ── POST /api/v1/admin/push/subscribe-expo ───────────────────────────────

    /**
     * Save or refresh an Expo push token for the React Native mobile app.
     *
     * The mobile app sends:
     * { "expo_token": "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]" }
     *
     * Expo push tokens are globally unique per device installation.
     * We upsert on expo_token so re-registration after reinstall is safe.
     *
     * p256dh and auth are set to empty strings — they are only meaningful
     * for Web Push (VAPID) and not used for the Expo push path.
     *
     * endpoint is set to "expo:<token>" to satisfy the existing NOT NULL +
     * UNIQUE constraint without requiring a schema change to nullable.
     */
    public function subscribeExpo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expo_token' => [
                'required',
                'string',
                'max:255',
                // Expo push tokens always match this format
                'regex:/^ExponentPushToken\[.+\]$/',
            ],
        ]);

        PushSubscription::upsert(
            [
                'expo_token' => $validated['expo_token'],
                'user_id'    => $request->user()->id,
                'endpoint'   => 'expo:' . $validated['expo_token'],
                'p256dh'     => '',
                'auth'       => '',
                'token_type' => 'expo',
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'is_active'  => true,
            ],
            ['expo_token'],
            ['user_id', 'endpoint', 'token_type', 'user_agent', 'is_active']
        );

        return response()->json(['message' => 'Expo push token registered.'], 201);
    }

    // ── POST /api/v1/admin/push/unsubscribe-expo ─────────────────────────────

    /**
     * Remove an Expo push token — called on logout from the mobile app.
     *
     * Uses POST rather than DELETE because React Native's fetch API handles
     * DELETE requests with a body inconsistently across Android versions.
     */
    public function unsubscribeExpo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'expo_token' => 'required|string|max:255',
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('expo_token', $validated['expo_token'])
            ->delete();

        return response()->json(['message' => 'Expo push token removed.']);
    }

    // ── Static helper: send to Expo Push Service ─────────────────────────────

    /**
     * Send a push notification to one Expo token via the Expo Push API.
     *
     * Called from WebPushService::send() when token_type = 'expo'.
     *
     * Expo Push API: https://docs.expo.dev/push-notifications/sending-notifications/
     *
     * @param  string  $expoToken  e.g. "ExponentPushToken[xxx]"
     * @param  string  $title
     * @param  string  $body
     * @param  string  $url        Deep-link URL opened when notification is tapped
     * @param  string  $icon       Icon tag passed through to the app's notification handler
     * @param  array   $data       Extra data available in the app notification handler
     */
    public static function sendExpo(
        string $expoToken,
        string $title,
        string $body,
        string $url  = '/',
        string $icon = 'bell',
        array  $data = []
    ): void {
        try {
            $response = Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(5)->connectTimeout(3)->post('https://exp.host/--/api/v2/push/send', [
                'to'        => $expoToken,
                'title'     => $title,
                'body'      => $body,
                'sound'     => 'default',
                'channelId' => 'default',   // Android notification channel
                'data'      => array_merge($data, ['url' => $url, 'icon' => $icon]),
            ]);

            $result = $response->json();

            // Expo returns {"data": {"status": "ok"}} on success.
            // On error: {"data": {"status": "error", "message": "...", "details": {...}}}
            if (isset($result['data']['status']) && $result['data']['status'] !== 'ok') {
                Log::warning('[ExpoPush] Delivery failed', [
                    'token_prefix' => substr($expoToken, 0, 40),
                    'result'       => $result['data'] ?? $result,
                ]);

                // DeviceNotRegistered means the token is stale — mark inactive
                // so we stop attempting delivery and avoid wasted API calls.
                if (($result['data']['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    PushSubscription::where('expo_token', $expoToken)
                        ->update(['is_active' => false]);

                    Log::info('[ExpoPush] Token marked inactive (DeviceNotRegistered)', [
                        'token_prefix' => substr($expoToken, 0, 40),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Non-critical — notifications must never break the main request flow
            Log::warning('[ExpoPush] Exception: ' . $e->getMessage(), [
                'token_prefix' => substr($expoToken, 0, 40),
            ]);
        }
    }
}