/**
 * echo.ts - Phase 2: Laravel Echo + Reverb WebSocket client
 */

import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { tokenStorage } from "@/api/client";

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<any>;
    }
}

let echoInstance: Echo<any> | null = null;

export function getEcho(): Echo<any> {
    // Always create a fresh instance so the auth header uses the current token.
    // Echo is initialised lazily - called only after the user is authenticated -
    // but we still rebuild it each time getEcho() is called to pick up token changes.
    if (echoInstance) return echoInstance;

    window.Pusher = Pusher;

    // Fix 1: authEndpoint must be the full Laravel URL in dev because the
    // frontend (port 3002) and backend (port 8000) are on different ports.
    // A relative path would hit the Vite dev server, not Laravel.
    const laravelBase = import.meta.env.VITE_API_URL ?? "http://localhost:8000";

    // Fix 2: wsPort must match the HOST port Reverb is exposed on.
    // In docker-compose.dev.yml it's mapped as 127.0.0.1:9000->8080, so
    // VITE_REVERB_PORT should be 9000 in your .env.
    const wsPort = parseInt(import.meta.env.VITE_REVERB_PORT ?? "9000");

    echoInstance = new Echo({
        broadcaster:       "reverb",
        key:               import.meta.env.VITE_REVERB_APP_KEY,
        wsHost:            import.meta.env.VITE_REVERB_HOST ?? "localhost",
        wsPort,
        wssPort:           wsPort,
        forceTLS:          (import.meta.env.VITE_REVERB_SCHEME ?? "http") === "https",
        enabledTransports: ["ws", "wss"],

        // Fix 3: full URL so the auth request goes to Laravel, not Vite.
        // Also re-reads the token at connection time (not module load time).
        authEndpoint: `${laravelBase}/broadcasting/auth`,
        auth: {
            headers: {
                Authorization: `Bearer ${tokenStorage.get()}`,
                Accept:        "application/json",
            },
        },
    });

    return echoInstance;
}

/**
 * Call this on logout or when the token changes so the next getEcho()
 * call creates a fresh connection with the new/cleared token.
 */
export function disconnectEcho(): void {
    echoInstance?.disconnect();
    echoInstance = null;
}

/** Subscribe to the current user's private channel for real-time bell updates. */
export function subscribeToUserChannel(
    userId: number,
    onNotification: (data: Record<string, unknown>) => void,
) {
    return getEcho()
        .private(`user.${userId}`)
        .listen(".notification.pushed", onNotification);
}

/** Subscribe to a comment thread for real-time comment updates. */
export function subscribeToThread(
    model: string,
    id: number,
    onComment: (comment: Record<string, unknown>) => void,
) {
    return getEcho()
        .private(`thread.${model}.${id}`)
        .listen(".comment.posted", onComment);
}

/** Subscribe to a messaging channel (Phase 3). */
export function subscribeToChannel(
    channelId: number,
    onMessage: (msg: Record<string, unknown>) => void,
) {
    return getEcho()
        .private(`channel.${channelId}`)
        .listen(".message.sent", onMessage);
}

/**
 * Subscribe to reaction updates on a channel.
 *
 * The backend broadcasts a `reaction.updated` event on the same private channel
 * every time any member toggles a reaction. The payload is:
 *   { message_id: number; channel_id: number; reactions: Record<string, number[]> }
 *
 * Must be called on the same Echo private channel instance as subscribeToChannel
 * so both listeners share one WebSocket connection.
 */
export function subscribeToReaction(
    channelId: number,
    onReaction: (data: { message_id: number; channel_id: number; reactions: Record<string, number[]> }) => void,
) {
    return getEcho()
        .private(`channel.${channelId}`)
        .listen(".reaction.updated", onReaction as (data: Record<string, unknown>) => void);
}

/** Join a presence channel for typing indicators and online status. */
export function joinPresenceChannel(
    channelId: number,
    callbacks: {
        onHere:    (members: unknown[]) => void;
        onJoining: (member: unknown) => void;
        onLeaving: (member: unknown) => void;
        onTyping?:  (member: unknown) => void;
    },
) {
    const channel = getEcho()
        .join(`presence.channel.${channelId}`)
        .here(callbacks.onHere)
        .joining(callbacks.onJoining)
        .leaving(callbacks.onLeaving);

    if (callbacks.onTyping) {
        channel.listenForWhisper("typing", callbacks.onTyping);
    }

    return channel;
}

/** Send a typing whisper (client-to-client, not stored). */
export function whisperTyping(channelId: number, user: { id: number; name: string }) {
    getEcho()
        .join(`presence.channel.${channelId}`)
        .whisper("typing", user);
}