// src/lib/neema.ts
//
// Deep links into Neema Agent (the AI sales console). Customer contact on hub
// pages routes staff INTO Neema — the chat thread and the call console — rather
// than popping the staff member's own WhatsApp or the OS dialer, so every
// customer touch happens where the history, CRM profile and agent live.

import { waNumber } from "./whatsapp";

const NEEMA_BASE: string =
    (import.meta.env.VITE_NEEMA_URL as string | undefined) ?? "https://neema.bethanyhouse.co.ke";

/**
 * The customer's chat thread in Neema's inbox. Null if the number is unusable.
 *
 * Pass the order number when there is one: a Meta (Messenger/Instagram)
 * customer's thread is keyed by PSID, and the phone the order carries may
 * exist nowhere in Neema's identities — it was captured during ordering. The
 * order number lets Neema walk order → person → thread server-side, so the
 * link lands on the right chat for BOTH WhatsApp and Meta customers.
 */
export function neemaChatUrl(phone?: string | null, orderNumber?: string | null): string | null {
    const num = waNumber(phone);
    if (!num) return null;
    const ref = orderNumber ? `&ref=${encodeURIComponent(orderNumber)}` : "";
    return `${NEEMA_BASE}/dashboard?open=${num}${ref}`;
}

/** The customer's call history in Neema's call console. Null if the number is unusable. */
export function neemaCallsUrl(phone?: string | null): string | null {
    const num = waNumber(phone);
    return num ? `${NEEMA_BASE}/dashboard?view=calls&caller=${num}` : null;
}
