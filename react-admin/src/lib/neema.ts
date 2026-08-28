// src/lib/neema.ts
//
// Deep links into Neema Agent (the AI sales console). Customer contact on hub
// pages routes staff INTO Neema — the chat thread and the call console — rather
// than popping the staff member's own WhatsApp or the OS dialer, so every
// customer touch happens where the history, CRM profile and agent live.

import { waNumber } from "./whatsapp";

const NEEMA_BASE: string =
    (import.meta.env.VITE_NEEMA_URL as string | undefined) ?? "https://neema.bethanyhouse.co.ke";

/** The apps a conversational order can arrive from. */
export type ChatChannel = "whatsapp" | "messenger" | "instagram";

const CHANNEL_LABEL: Record<ChatChannel, string> = {
    whatsapp: "WhatsApp",
    messenger: "Messenger",
    instagram: "Instagram",
};

/** What to call the customer's chat app on a button. Defaults to a neutral word. */
export function chatChannelLabel(sourceChannel?: string | null): string {
    const c = (sourceChannel ?? "").toLowerCase();
    if (c === "facebook") return CHANNEL_LABEL.messenger;
    return CHANNEL_LABEL[c as ChatChannel] ?? "Chat";
}

/**
 * The customer's chat thread in Neema's inbox.
 *
 * THE ORDER NUMBER IS THE STRONGEST KEY, and this function is built around
 * that. A Meta customer's thread is keyed by their page-scoped id, and the
 * phone on the order may key nothing at all: a real Messenger buyer had a
 * Central African Republic phone on her order and a thread keyed by a 17-digit
 * PSID, so a phone-only link could never reach her. Neema's resolver walks
 * order number -> person -> identities -> thread, which finds her every time.
 *
 * So: a link is produced when we have EITHER a usable phone OR an order
 * number. This function used to return null without a phone, which silently
 * dropped the link for exactly the customers who needed it most.
 */
export function neemaChatUrl(phone?: string | null, orderNumber?: string | null): string | null {
    const num = waNumber(phone);
    const ref = (orderNumber ?? "").trim();
    if (!num && !ref) return null;

    const params = new URLSearchParams();
    // `open` is the lookup key; the resolver accepts a phone or an order number
    // and falls back from one to the other.
    params.set("open", num || ref);
    if (ref) params.set("ref", ref);

    return `${NEEMA_BASE}/dashboard?${params.toString()}`;
}

/**
 * The customer's call history in Neema's call console.
 *
 * Null without a usable number — and callers should not offer it for a chat
 * customer at all: sending someone to an empty call log when they clicked a
 * phone number is the "link doesn't go anywhere" complaint in another costume.
 */
export function neemaCallsUrl(phone?: string | null): string | null {
    const num = waNumber(phone);
    return num ? `${NEEMA_BASE}/dashboard?view=calls&caller=${num}` : null;
}
