import { useQuery, type QueryClient } from "@tanstack/react-query";
import { create } from "zustand";
import { channelApi, type Attention } from "@/api/channels";

/**
 * Shared state for "what in my conversations still needs me" (owner,
 * 2026-11-02). The server is the one source of truth (GET /channels/attention);
 * this file only keeps every surface — the floating notices, the chat list,
 * the sidebar and tab-bar badges — reading the same answer, and keeps every
 * open tab in step:
 *
 *   - this tab:      react-query key ["comms-attention"]
 *   - other tabs:    a BroadcastChannel nudge after any action here
 *   - other devices: the attention.changed / notification.pushed broadcasts
 *   - after offline: refetch on reconnect, on focus, and every 60 s
 */

export const ATTENTION_KEY = ["comms-attention"] as const;

/** The conversation open and in front right now — it gets no floating notice. */
interface ActiveChannelState {
    id: number | null;
    set: (id: number | null) => void;
}
export const useActiveChannel = create<ActiveChannelState>((set) => ({
    id: null,
    set: (id) => set({ id }),
}));

/** The keep-on-top message window, while it is open (components/comms/MessagePopout). */
export type PipWindow = Window & typeof globalThis;
interface PopoutState { win: PipWindow | null; set: (w: PipWindow | null) => void }
export const usePopout = create<PopoutState>((set) => ({ win: null, set: (win) => set({ win }) }));

/** True when this conversation is the one on screen (tab visible). */
export function isViewing(channelId: number | null | undefined): boolean {
    return !!channelId
        && useActiveChannel.getState().id === channelId
        && typeof document !== "undefined"
        && document.visibilityState === "visible";
}

export function useAttention(enabled = true) {
    return useQuery({
        queryKey: ATTENTION_KEY,
        queryFn: channelApi.attention,
        enabled,
        staleTime: 10_000,
        refetchInterval: 60_000,
        refetchOnWindowFocus: true,
        refetchOnReconnect: true,
    });
}

/** Ask again everywhere this tab shows message state. */
export function refreshAttention(qc: QueryClient): void {
    qc.invalidateQueries({ queryKey: ATTENTION_KEY });
    qc.invalidateQueries({ queryKey: ["channels"] });
}

// ── Cross-tab nudges ─────────────────────────────────────────────────────────

const bus: BroadcastChannel | null =
    typeof window !== "undefined" && "BroadcastChannel" in window ? new BroadcastChannel("bh-comms") : null;

/** Tell this browser's other tabs that message state changed here. */
export function announceAttention(): void {
    try { bus?.postMessage({ type: "attention" }); } catch { /* a closed channel is not an error */ }
}

/** Listen for another tab's nudge. Returns the unsubscribe. */
export function onAttentionAnnounced(cb: () => void): () => void {
    if (!bus) return () => {};
    const handler = (e: MessageEvent) => { if (e.data?.type === "attention") cb(); };
    bus.addEventListener("message", handler);
    return () => bus.removeEventListener("message", handler);
}

/** Drop one conversation's notice from the cached answer at once (optimistic). */
export function hideNoticeLocally(qc: QueryClient, channelId: number): void {
    qc.setQueryData<Attention>(ATTENTION_KEY, (old) => old ? {
        ...old,
        channels: old.channels.map((c) => c.channel_id === channelId ? { ...c, notice: null } : c),
        totals: { ...old.totals, notices: Math.max(0, old.totals.notices - (old.channels.find((c) => c.channel_id === channelId)?.notice ? 1 : 0)) },
    } : old);
}

/** "Waiting 2 h", "Waiting 25 min" — how long a message has waited on you. */
export function waited(since: string | null, now = Date.now()): string {
    if (!since) return "";
    const mins = Math.max(0, Math.round((now - new Date(since).getTime()) / 60_000));
    if (mins < 1) return "just now";
    if (mins < 60) return `${mins} min`;
    const h = Math.floor(mins / 60);
    if (h < 24) return `${h} h`;
    return `${Math.floor(h / 24)} d`;
}

/** "now", "4m", "2h", "Mon" — the notice's own time stamp. */
export function ago(iso: string, now = Date.now()): string {
    const mins = Math.max(0, Math.round((now - new Date(iso).getTime()) / 60_000));
    if (mins < 1) return "now";
    if (mins < 60) return `${mins}m`;
    if (mins < 60 * 24) return `${Math.floor(mins / 60)}h`;
    return new Date(iso).toLocaleDateString("en-KE", { weekday: "short" });
}
