/**
 * push.ts - Web Push subscription API client.
 *
 * Place at: src/api/push.ts
 */

import { get, post, del } from './client'

export interface VapidKeyResponse {
    public_key: string
}

export const pushApi = {
    /** Fetch the VAPID public key from the server */
    getVapidPublicKey: () =>
        get<VapidKeyResponse>('/v1/admin/push/vapid-public-key'),

    /** Save a push subscription to the server */
    subscribe: (subscription: PushSubscriptionJSON) =>
        post<{ message: string }>('/v1/admin/push/subscribe', subscription),

    /** Remove a push subscription from the server */
    unsubscribe: (endpoint: string) =>
        del<{ message: string }>('/v1/admin/push/unsubscribe', {
            data: { endpoint },
        }),
}