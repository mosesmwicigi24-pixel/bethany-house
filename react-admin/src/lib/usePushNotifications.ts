/**
 * usePushNotifications.ts — Web Push registration hook.
 *
 * Place at: src/lib/usePushNotifications.ts
 *
 * Handles the full subscription lifecycle:
 *   1. After login, fetches the VAPID public key from the server
 *   2. Asks the user for notification permission (only once — browser handles)
 *   3. Subscribes via pushManager.subscribe()
 *   4. POSTs the subscription to /api/v1/admin/push/subscribe
 *   5. On logout, removes the subscription from the server
 *
 * Designed to be called from the auth store after a successful login,
 * and from the logout handler before clearing the token.
 *
 * The push handler in sw.ts is already wired up — this hook is the
 * only frontend change needed to activate Phase 2.
 */

import { useCallback } from 'react'
import { pushApi } from '@/api/push'

// ── URL-safe base64 → Uint8Array ──────────────────────────────────────────────
// The VAPID public key comes back as a URL-safe base64 string.
// pushManager.subscribe() requires it as a Uint8Array (applicationServerKey).

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding  = '='.repeat((4 - (base64String.length % 4)) % 4)
    const base64   = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
    const rawData  = window.atob(base64)
    const output   = new Uint8Array(rawData.length)
    for (let i = 0; i < rawData.length; ++i) {
        output[i] = rawData.charCodeAt(i)
    }
    return output
}

// ── Hook ──────────────────────────────────────────────────────────────────────

export function usePushNotifications() {

    /**
     * Register this device for push notifications.
     * Call after a successful login (token is already in sessionStorage).
     *
     * Silently no-ops if:
     *   - Browser doesn't support push
     *   - User denies permission
     *   - VAPID key isn't configured on the server
     *   - Already subscribed (pushManager returns existing subscription)
     */
    const registerPush = useCallback(async () => {
        try {
            // Guard: browser support
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return

            // Guard: SW must be active before we can subscribe
            const registration = await navigator.serviceWorker.ready

            // Check existing subscription first — avoid redundant re-registration
            const existing = await registration.pushManager.getSubscription()

            // Fetch VAPID public key from server
            const { public_key } = await pushApi.getVapidPublicKey()
            if (!public_key) return // VAPID not configured yet — silently skip

            const applicationServerKey = urlBase64ToUint8Array(public_key)

            let subscription: PushSubscription

            if (existing) {
                // Already subscribed — just re-send to server in case the row
                // was deleted from the DB (e.g. after a DB reset in dev)
                subscription = existing
            } else {
                // Request permission + create subscription
                // Note: on iOS 16.4+ the app MUST be installed (standalone mode)
                // for this to work. On other platforms it works in browser too.
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly:      true,
                    applicationServerKey,
                })
            }

            // Send to server — upserts on endpoint, safe to call every login
            await pushApi.subscribe(subscription.toJSON() as PushSubscriptionJSON)

        } catch (err) {
            // Permission denied, SW not ready, VAPID misconfigured, etc.
            // Push is non-critical — log and continue normally.
            console.warn('[Push] Registration skipped:', err)
        }
    }, [])

    /**
     * Unregister this device's push subscription.
     * Call before clearing the auth token on logout.
     */
    const unregisterPush = useCallback(async () => {
        try {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return

            const registration  = await navigator.serviceWorker.ready
            const subscription  = await registration.pushManager.getSubscription()
            if (!subscription) return

            const endpoint = subscription.endpoint

            // Unsubscribe browser-side
            await subscription.unsubscribe()

            // Remove from server
            await pushApi.unsubscribe(endpoint)

        } catch (err) {
            // Non-critical — server row will be cleaned up on next 410 response
            console.warn('[Push] Unregister skipped:', err)
        }
    }, [])

    return { registerPush, unregisterPush }
}