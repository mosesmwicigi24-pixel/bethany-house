/**
 * pushRegistration.ts — Plain async functions for push subscription management.
 *
 * Place at: src/lib/pushRegistration.ts
 *
 * These are NOT hooks — they're plain async functions that can be called
 * from anywhere including the Zustand auth store. The hook version
 * (usePushNotifications.ts) wraps these for use in React components.
 *
 * Uses dynamic import for pushApi to avoid circular dependency with the
 * auth store (pushApi → client.ts → tokenStorage, same as authApi).
 */

// ── URL-safe base64 → Uint8Array ──────────────────────────────────────────────

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
    const rawData = window.atob(base64)
    const output  = new Uint8Array(rawData.length)
    for (let i = 0; i < rawData.length; ++i) {
        output[i] = rawData.charCodeAt(i)
    }
    return output
}

// ── Register ──────────────────────────────────────────────────────────────────

export async function registerPush(): Promise<void> {
    try {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return

        // Same timeout guard — serviceWorker.ready hangs if SW not active
        const swTimeout = new Promise<never>((_, reject) =>
            setTimeout(() => reject(new Error('SW not ready')), 3000)
        )
        const registration = await Promise.race([
            navigator.serviceWorker.ready,
            swTimeout,
        ])
        const { pushApi }  = await import('@/api/push')

        // Fetch VAPID key — silently abort if not configured
        const { public_key } = await pushApi.getVapidPublicKey()
        if (!public_key) return

        // Check existing subscription
        const existing = await registration.pushManager.getSubscription()

        const subscription = existing ?? await registration.pushManager.subscribe({
            userVisibleOnly:      true,
            applicationServerKey: urlBase64ToUint8Array(public_key),
        })

        // Upsert on server — safe to call on every login
        await pushApi.subscribe(subscription.toJSON() as PushSubscriptionJSON)

    } catch (err) {
        // Push is non-critical — permission denied, SW not ready, etc.
        console.warn('[Push] Registration skipped:', err)
    }
}

// ── Unregister ────────────────────────────────────────────────────────────────

export async function unregisterPush(): Promise<void> {
    try {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return

        // navigator.serviceWorker.ready never rejects — it hangs forever if
        // no SW is active. Race it against a 2s timeout so logout is never blocked.
        const timeoutPromise = new Promise<never>((_, reject) =>
            setTimeout(() => reject(new Error('SW not ready')), 2000)
        )
        const registration = await Promise.race([
            navigator.serviceWorker.ready,
            timeoutPromise,
        ])

        const subscription = await registration.pushManager.getSubscription()
        if (!subscription) return

        const endpoint    = subscription.endpoint
        const { pushApi } = await import('@/api/push')

        await subscription.unsubscribe()
        await pushApi.unsubscribe(endpoint)

    } catch (err) {
        console.warn('[Push] Unregister skipped:', err)
    }
}