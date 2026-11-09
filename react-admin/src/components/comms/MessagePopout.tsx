import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import clsx from "clsx";
import { useQueryClient } from "@tanstack/react-query";
import { useAttention, useActiveChannel, usePopout, refreshAttention, type PipWindow } from "@/lib/commsAttention";
import { registerPush } from "@/lib/pushRegistration";
import { NoticeCard } from "@/components/comms/MessageNotices";
import { useToastStore } from "@/store/toast.store";

/**
 * Messages above other programs (owner, 2026-11-04).
 *
 * Two ways, side by side:
 *
 *  1. System banners (Windows / macOS / Android): shown by the service worker
 *     when the hub is not in front, and they stay until acted on — Open,
 *     Acknowledge, or ✕ (src/sw.ts). This file asks for the permission and
 *     closes a banner once its conversation is dealt with anywhere else.
 *
 *  2. "Keep on top": a small always-on-top window (Document Picture-in-Picture,
 *     Chrome and Edge on a computer) holding the same cards as the in-page
 *     stack — Reply, Open, Dismiss. While it is open the in-page stack and the
 *     banners stand down, so nothing shows twice. It closes with the hub tab.
 */

interface DocumentPip { requestWindow(o?: { width?: number; height?: number }): Promise<PipWindow>; window: PipWindow | null }

const pipApi = (): DocumentPip | null =>
    typeof window !== "undefined" && "documentPictureInPicture" in window
        ? (window as unknown as { documentPictureInPicture: DocumentPip }).documentPictureInPicture
        : null;

export const canKeepOnTop = (): boolean => pipApi() !== null;
const bannersSupported = (): boolean => typeof window !== "undefined" && "Notification" in window && "serviceWorker" in navigator;

/** Copy this page's styles into the floating window so the cards look the same. */
function copyStyles(target: Document): void {
    target.documentElement.className = document.documentElement.className;
    for (const sheet of Array.from(document.styleSheets)) {
        try {
            const style = target.createElement("style");
            style.textContent = Array.from(sheet.cssRules).map((r) => r.cssText).join("\n");
            target.head.appendChild(style);
        } catch {
            if (sheet.href) {
                const link = target.createElement("link");
                link.rel = "stylesheet";
                link.href = sheet.href;
                target.head.appendChild(link);
            }
        }
    }
}

/** Open the keep-on-top window (must run from a click). */
export async function openKeepOnTop(): Promise<boolean> {
    const api = pipApi();
    if (!api) return false;
    const existing = usePopout.getState().win;
    if (existing && !existing.closed) { existing.focus(); return true; }
    const win = await api.requestWindow({ width: 400, height: 560 });
    copyStyles(win.document);
    win.document.title = "Bethany messages";
    win.document.body.className = "bg-surface-canvas m-0";
    win.addEventListener("pagehide", () => usePopout.getState().set(null));
    usePopout.getState().set(win);
    return true;
}

/** Mounted once in the layout: the floating window's contents and the banner housekeeping. */
export function MessagePopoutHost() {
    const win = usePopout((s) => s.win);
    const { data } = useAttention();

    // The service worker asks "is the keep-on-top window open?" before it shows a banner.
    useEffect(() => {
        if (!("serviceWorker" in navigator)) return;
        const onMessage = (e: MessageEvent) => {
            if (e.data?.type === "bh-pip-open?") e.ports[0]?.postMessage({ open: !!usePopout.getState().win });
        };
        navigator.serviceWorker.addEventListener("message", onMessage);
        return () => navigator.serviceWorker.removeEventListener("message", onMessage);
    }, []);

    // A conversation dealt with anywhere (read, replied, handled, dismissed)
    // takes its banner off the screen too.
    useEffect(() => {
        if (!data || !bannersSupported()) return;
        const live = new Set(data.channels.filter((c) => c.notice).map((c) => c.channel_id));
        navigator.serviceWorker.getRegistration().then((reg) => reg?.getNotifications()).then((list) => {
            for (const n of list ?? []) {
                const m = /^bh-chat-(\d+)$/.exec(n.tag);
                if (m && !live.has(Number(m[1]))) n.close();
            }
        }).catch(() => { /* banners are a convenience */ });
    }, [data]);

    // While it is open the hub is usually behind other programs, so it gets
    // no focus refetch: ask every 15 s too, in case a live update is missed.
    const qc = useQueryClient();
    useEffect(() => {
        if (!win) return;
        const t = setInterval(() => refreshAttention(qc), 15_000);
        return () => clearInterval(t);
    }, [win, qc]);

    // Closing the hub tab closes the window with it.
    useEffect(() => {
        const close = () => usePopout.getState().win?.close();
        window.addEventListener("pagehide", close);
        return () => window.removeEventListener("pagehide", close);
    }, []);

    if (!win) return null;
    return createPortal(<PopoutPanel />, win.document.body);
}

function PopoutPanel() {
    const { data } = useAttention();
    const activeId = useActiveChannel((s) => s.id);
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => { const t = setInterval(() => setNow(Date.now()), 60_000); return () => clearInterval(t); }, []);

    // As on the page: the conversation open and in front of you needs no card.
    const cards = (data?.channels ?? []).filter((c) => c.notice && !(document.visibilityState === "visible" && document.hasFocus() && activeId === c.channel_id));
    const waiting = (data?.channels ?? []).filter((c) => c.pending_count > 0).length;

    return (
        <div className="flex h-screen flex-col">
            <header className="flex items-center justify-between gap-2 border-b border-surface-200 bg-white px-3 py-2">
                <div>
                    <p className="text-sm font-semibold text-surface-900">Messages</p>
                    <p className="text-2xs text-surface-500">
                        {cards.length === 0 ? "Nothing new" : `${cards.length} conversation${cards.length === 1 ? "" : "s"} with new messages`}
                        {waiting > 0 && ` · ${waiting} waiting on your reply`}
                    </p>
                </div>
                <span className="shrink-0 whitespace-nowrap text-2xs text-surface-400">Stays on top</span>
            </header>
            <main className="flex-1 overflow-y-auto overscroll-contain p-2 space-y-2" aria-live="polite" aria-label="Message notifications">
                {cards.length === 0 ? (
                    <div className="flex h-full flex-col items-center justify-center px-6 text-center">
                        <p className="text-sm font-medium text-surface-700">You're all caught up</p>
                        <p className="mt-1 text-2xs text-surface-500">New messages appear here, above your other programs. Reply, open or dismiss them from this window.</p>
                    </div>
                ) : cards.map((c) => <NoticeCard key={c.channel_id} item={c} now={now} inPopout />)}
            </main>
        </div>
    );
}

/** Under "Messages", until banners are allowed on this device. */
export function BannersPrompt() {
    const toast = useToastStore();
    const [permission, setPermission] = useState<NotificationPermission | "unsupported">(() =>
        bannersSupported() ? Notification.permission : "unsupported");
    if (permission === "unsupported" || permission === "granted") return null;

    const turnOn = async () => {
        const answer = await Notification.requestPermission();
        setPermission(answer);
        if (answer === "granted") {
            await registerPush();
            toast.success("Message banners are on for this device. They stay on screen until you act on them.");
        } else {
            toast.error("Banners are blocked for this site. Allow notifications in the browser's site settings (the icon left of the address).");
        }
    };

    return (
        <div className="mt-2 flex items-center justify-between gap-2 rounded-xl bg-brand-50 px-3 py-1.5 text-2xs text-brand-800">
            <span>{permission === "denied" ? "Banners are blocked in this browser's site settings." : "See new messages above your other programs."}</span>
            {permission !== "denied" && (
                <button type="button" onClick={turnOn} className="shrink-0 whitespace-nowrap rounded-full bg-brand-600 px-2.5 py-0.5 font-semibold text-white hover:bg-brand-700">
                    Turn on banners
                </button>
            )}
        </div>
    );
}

/** The keep-on-top switch beside "Messages". */
export function MessageAlertButtons({ className }: { className?: string }) {
    const toast = useToastStore();
    const win = usePopout((s) => s.win);
    const keepOnTop = async () => {
        if (win) { win.close(); return; }
        try {
            if (!(await openKeepOnTop())) toast.error("Keep on top needs Chrome or Edge on a computer.");
        } catch {
            toast.error("The window could not open. Try again from this button.");
        }
    };

    return (
        <div className={clsx("flex items-center gap-1", className)}>
            {canKeepOnTop() && (
                <button type="button" onClick={keepOnTop} aria-pressed={!!win}
                    title={win ? "Close the keep-on-top message window" : "Keep messages in a small window above other programs"}
                    aria-label={win ? "Close keep-on-top window" : "Keep messages on top"}
                    className={clsx("w-8 h-8 rounded-full flex items-center justify-center transition-colors",
                        win ? "bg-brand-600 text-white hover:bg-brand-700" : "text-surface-500 hover:text-brand-600 hover:bg-brand-50")}>
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
                        <rect x="3" y="4" width="18" height="14" rx="2" /><rect x="11" y="10" width="8" height="6" rx="1" fill="currentColor" stroke="none" />
                    </svg>
                </button>
            )}
        </div>
    );
}
