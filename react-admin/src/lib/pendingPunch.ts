/**
 * A punch made with no signal (Bethany People PR 1).
 *
 * The phone keeps the one punch it could not send, with the phone's time, and
 * sends it the moment the screen is next open online. The server stores the
 * phone time as the punch time and flags it "sent late" for the manager.
 * One slot only: you cannot clock in and out twice while offline.
 */
import type { Punch } from "@/api/attendance";

const KEY = "bh_pending_punch";

export interface PendingPunch {
    kind: "in" | "out";
    outlet_id?: number;
    punch: Punch;
    recorded_at: string;
}

export function readPendingPunch(): PendingPunch | null {
    try { const raw = localStorage.getItem(KEY); return raw ? (JSON.parse(raw) as PendingPunch) : null; } catch { return null; }
}
export function savePendingPunch(p: PendingPunch): void {
    try { localStorage.setItem(KEY, JSON.stringify(p)); } catch { /* private mode: the punch is simply not kept */ }
}
export function clearPendingPunch(): void {
    try { localStorage.removeItem(KEY); } catch { /* nothing to clear */ }
}
