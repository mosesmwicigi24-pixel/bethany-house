/**
 * sw.ts — Bethany House PWA service worker.
 *
 * This is the custom SW entry point for vite-plugin-pwa injectManifest mode.
 * At build time, Workbox replaces __WB_MANIFEST with the actual precache list.
 *
 * Handles:
 *   - Precaching all built assets (JS, CSS, HTML, fonts, images)
 *   - Runtime caching strategies per URL pattern
 *   - Web Push notifications (Phase 2 — handler ready, activates when
 *     VAPID keys + push subscription are wired up)
 *   - Background sync for offline task updates and POS sales (Phase 3/4)
 */

import {
    precacheAndRoute,
    cleanupOutdatedCaches,
    createHandlerBoundToURL,
} from "workbox-precaching";
import { registerRoute, NavigationRoute } from "workbox-routing";
import {
    StaleWhileRevalidate,
    NetworkFirst,
    CacheFirst,
} from "workbox-strategies";
import { ExpirationPlugin } from "workbox-expiration";
import { CacheableResponsePlugin } from "workbox-cacheable-response";

declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: Array<{ url: string; revision: string | null }> };

// ── Workbox precache ──────────────────────────────────────────────────────────
// self.__WB_MANIFEST is the exact token Workbox's injector scans for at build
// time and replaces with the versioned precache list. Must be this exact form.
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// ── Skip waiting ─────────────────────────────────────────────────────────────
// Responds to postMessage({type:"SKIP_WAITING"}) sent by usePWA.applyUpdate().
// Also calls skipWaiting() directly on first install so the SW activates
// immediately without waiting for all tabs to close.
self.addEventListener("message", (event: ExtendableMessageEvent) => {
    if (event.data && event.data.type === "SKIP_WAITING") {
        self.skipWaiting();
    }
});

self.addEventListener("activate", (event) => {
    event.waitUntil(self.clients.claim());
});

// ── SPA navigation fallback ───────────────────────────────────────────────────
// All navigation requests that don't match a precached URL fall back to
// index.html so React Router handles the route client-side.
// Use relative path so it works under any base (e.g. /admin/index.html)
const navHandler = createHandlerBoundToURL("index.html");
const navRoute = new NavigationRoute(navHandler, {
    denylist: [
        /\/api\//,          // API requests
        /\/broadcasting/,   // WebSocket auth
        /\/storage\//,      // Laravel storage
        /\/icons\//,        // PWA icons
        /\/images\//,       // Brand images
        /\.webmanifest$/,   // Manifest file
    ],
});
registerRoute(navRoute);

// ── Runtime caching ───────────────────────────────────────────────────────────

// Product catalogue — stale-while-revalidate
// POS grid loads instantly from cache, then updates quietly
registerRoute(
    ({ url }) => url.pathname.startsWith("/api/v1/admin/products"),
    new StaleWhileRevalidate({
        cacheName: "api-products",
        plugins: [
            new ExpirationPlugin({ maxEntries: 200, maxAgeSeconds: 60 * 60 * 2 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Categories — rarely change, cache aggressively
registerRoute(
    ({ url }) => url.pathname.startsWith("/api/v1/admin/categories"),
    new StaleWhileRevalidate({
        cacheName: "api-categories",
        plugins: [
            new ExpirationPlugin({ maxEntries: 100, maxAgeSeconds: 60 * 60 * 6 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Production tasks — network-first (tailors always get fresh assignments)
registerRoute(
    ({ url }) => /\/api\/v1\/(admin|tailor)\/production/.test(url.pathname),
    new NetworkFirst({
        cacheName: "api-production",
        networkTimeoutSeconds: 5,
        plugins: [
            new ExpirationPlugin({ maxEntries: 100, maxAgeSeconds: 60 * 30 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Outlets / POS
registerRoute(
    ({ url }) => url.pathname.startsWith("/api/v1/admin/outlets"),
    new StaleWhileRevalidate({
        cacheName: "api-outlets",
        plugins: [
            new ExpirationPlugin({ maxEntries: 50, maxAgeSeconds: 60 * 60 * 12 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Payment methods
registerRoute(
    ({ url }) => url.pathname.startsWith("/api/v1/admin/payment-methods"),
    new StaleWhileRevalidate({
        cacheName: "api-payment-methods",
        plugins: [
            new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 60 * 60 * 12 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Tax rates
registerRoute(
    ({ url }) => url.pathname.startsWith("/api/v1/admin/tax-rates"),
    new StaleWhileRevalidate({
        cacheName: "api-tax-rates",
        plugins: [
            new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Notifications — network-first, short TTL
registerRoute(
    ({ url }) => url.pathname.startsWith("/api/v1/admin/notifications"),
    new NetworkFirst({
        cacheName: "api-notifications",
        networkTimeoutSeconds: 3,
        plugins: [
            new ExpirationPlugin({ maxEntries: 20, maxAgeSeconds: 60 * 5 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Product images — cache-first (images don't change often)
registerRoute(
    ({ request }) => request.destination === "image",
    new CacheFirst({
        cacheName: "images",
        plugins: [
            new ExpirationPlugin({ maxEntries: 500, maxAgeSeconds: 60 * 60 * 24 * 30 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Google Fonts stylesheets
registerRoute(
    ({ url }) => url.origin === "https://fonts.googleapis.com",
    new CacheFirst({
        cacheName: "google-fonts-stylesheets",
        plugins: [
            new ExpirationPlugin({ maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 365 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// Google Fonts webfonts
registerRoute(
    ({ url }) => url.origin === "https://fonts.gstatic.com",
    new CacheFirst({
        cacheName: "google-fonts-webfonts",
        plugins: [
            new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 365 }),
            new CacheableResponsePlugin({ statuses: [0, 200] }),
        ],
    }),
);

// ── Push event handler (Phase 2) ──────────────────────────────────────────────
// Handler is fully implemented. Activates automatically once Phase 2 wires up
// VAPID keys and push subscriptions — no changes to this file needed then.

self.addEventListener("push", (event: PushEvent) => {
    if (!event.data) return;

    let payload: {
        title: string;
        body?: string;
        icon?: string;
        badge?: string;
        tag?: string;
        url?: string;
        data?: Record<string, unknown>;
    };

    try {
        payload = event.data.json();
    } catch {
        payload = { title: "Bethany House", body: event.data.text() };
    }

    const title = payload.title ?? "Bethany House Operations";
    // Cast to any so we can include `vibrate`, which is a valid browser
    // NotificationOptions property but is missing from the TS lib type.
    const options = {
        body:               payload.body  ?? "",
        icon:               payload.icon  ?? "/icons/icon-192.png",
        badge:              payload.badge ?? "/icons/badge-72.png",
        tag:                payload.tag   ?? "bh-notification",
        vibrate:            [50, 30, 100],
        requireInteraction: false,
        data: {
            url: payload.url ?? "/",
            ...payload.data,
        },
    } as NotificationOptions;

    event.waitUntil(self.registration.showNotification(title, options));
});

// ── Notification click handler ────────────────────────────────────────────────

self.addEventListener("notificationclick", (event: NotificationEvent) => {
    event.notification.close();

    // URL from payload is a path like "/admin/sales/orders/1"
    // Build the full URL using the SW's origin so openWindow works correctly.
    const rawUrl: string = (event.notification.data as { url?: string })?.url ?? "/admin";
    const targetUrl = rawUrl.startsWith("http")
        ? rawUrl
        : self.location.origin + rawUrl;

    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });

            // If any open window is on the same origin, navigate it
            for (const client of clients) {
                if (client.url.startsWith(self.location.origin) && "focus" in client) {
                    await (client as WindowClient).navigate(targetUrl);
                    await (client as WindowClient).focus();
                    return;
                }
            }

            // No open window — open a new one
            if (self.clients.openWindow) {
                await self.clients.openWindow(targetUrl);
            }
        })(),
    );
});

// ── Background sync (Phase 3/4) ───────────────────────────────────────────────

// SyncEvent is not in ServiceWorkerGlobalScopeEventMap — cast to EventListener
self.addEventListener("sync", ((event: SyncEvent) => {
    if (event.tag === "task-status-update") {
        event.waitUntil(replayQueuedTaskUpdates());
    }
    if (event.tag === "pos-sale-sync") {
        event.waitUntil(replayQueuedPosSales());
    }
}) as EventListener);

async function replayQueuedTaskUpdates(): Promise<void> {
    const db = await openOfflineDB();
    const queue = await db.getAll("task-updates");
    for (const item of queue) {
        try {
            const res = await fetch(item.url, {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Authorization: `Bearer ${item.token}`,
                },
                body: JSON.stringify(item.body),
            });
            if (res.ok) await db.delete("task-updates", item.id);
        } catch {
            // Retry on next sync
        }
    }
}

async function replayQueuedPosSales(): Promise<void> {
    const db = await openOfflineDB();
    const queue = await db.getAll("pos-sales");
    for (const item of queue) {
        try {
            const res = await fetch("/api/v1/admin/pos/sales", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Authorization: `Bearer ${item.token}`,
                },
                body: JSON.stringify(item.body),
            });
            if (res.ok) await db.delete("pos-sales", item.id);
        } catch {
            // Retry on next sync
        }
    }
}

// ── Minimal IndexedDB helper ──────────────────────────────────────────────────

interface OfflineDB {
    getAll: (store: string) => Promise<
        Array<{ id: IDBValidKey; url: string; token: string; body: unknown }>
    >;
    delete: (store: string, id: IDBValidKey) => Promise<void>;
}

function openOfflineDB(): Promise<OfflineDB> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open("bh-offline-queue", 1);

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains("task-updates")) {
                db.createObjectStore("task-updates", { autoIncrement: true, keyPath: "id" });
            }
            if (!db.objectStoreNames.contains("pos-sales")) {
                db.createObjectStore("pos-sales", { autoIncrement: true, keyPath: "id" });
            }
        };

        request.onsuccess = () => {
            const db = request.result;
            resolve({
                getAll: (store) =>
                    new Promise((res, rej) => {
                        const tx  = db.transaction(store, "readonly");
                        const req = tx.objectStore(store).getAll();
                        req.onsuccess = () => res(req.result);
                        req.onerror   = () => rej(req.error);
                    }),
                delete: (store, id) =>
                    new Promise((res, rej) => {
                        const tx  = db.transaction(store, "readwrite");
                        const req = tx.objectStore(store).delete(id);
                        req.onsuccess = () => res();
                        req.onerror   = () => rej(req.error);
                    }),
            });
        };

        request.onerror = () => reject(request.error);
    });
}