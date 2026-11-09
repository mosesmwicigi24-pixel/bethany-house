import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import { useNavigate } from "react-router-dom";
import { useQueryClient } from "@tanstack/react-query";
import clsx from "clsx";
import { channelApi, type AttentionChannel } from "@/api/channels";
import {
    useAttention, useActiveChannel, refreshAttention, announceAttention,
    hideNoticeLocally, waited, ago, usePopout,
} from "@/lib/commsAttention";
import { canKeepOnTop, openKeepOnTop } from "@/components/comms/MessagePopout";
import { avatarGradient, initialsOf } from "@/lib/commsAvatar";
import { useToastStore } from "@/store/toast.store";

/**
 * Floating message notices (owner, 2026-11-02): every incoming message is
 * noticed, and can be answered from where you are.
 *
 * - One card per conversation, newest message shown, "+N more" behind it.
 * - Cards stay until you act: Reply (inline), Open, or Dismiss. Nothing
 *   times out.
 * - Dismiss hides the notice only. The message stays unread (the badge stays)
 *   and is still waiting on your reply if it was.
 * - A conversation you have open in front of you gets no card.
 * - Three cards at most; the rest fold into an expandable "N more" row.
 *
 * The cards are the server's answer (GET /channels/attention), so they come
 * back after a reload or a dropped connection, and clear on every tab and
 * device the moment you read, reply, dismiss or mark handled.
 */

const VISIBLE = 3;

function useDocumentVisible(): boolean {
    return useSyncExternalStore(
        (cb) => { document.addEventListener("visibilitychange", cb); return () => document.removeEventListener("visibilitychange", cb); },
        () => document.visibilityState === "visible",
        () => true,
    );
}

/** Re-render once a minute so "4m" and "Waiting 2 h" stay true. */
function useMinuteTick(): number {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => { const t = setInterval(() => setNow(Date.now()), 60_000); return () => clearInterval(t); }, []);
    return now;
}

export default function MessageNotices() {
    const { data } = useAttention();
    const activeId = useActiveChannel((s) => s.id);
    const visible  = useDocumentVisible();
    const now      = useMinuteTick();
    const [expanded, setExpanded] = useState(false);
    const popoutOpen = usePopout((s) => !!s.win);

    const cards = (data?.channels ?? []).filter((c) =>
        c.notice && !(visible && activeId === c.channel_id));

    useEffect(() => { if (cards.length <= VISIBLE) setExpanded(false); }, [cards.length]);

    // The keep-on-top window shows the same cards; one place at a time.
    if (cards.length === 0 || popoutOpen) return null;

    const shown  = expanded ? cards : cards.slice(0, VISIBLE);
    const hidden = cards.length - shown.length;

    return (
        <section
            aria-label="Message notifications"
            aria-live="polite"
            className={clsx(
                "fixed z-[70] flex flex-col gap-2 pointer-events-none",
                "left-2 right-2 top-[calc(env(safe-area-inset-top)+64px)]",
                "sm:left-auto sm:right-4 sm:top-[72px] sm:w-[380px]",
            )}
        >
            <div className={clsx("flex flex-col gap-2", expanded && "max-h-[70vh] overflow-y-auto overscroll-contain pr-0.5")}>
                {shown.map((c) => <NoticeCard key={c.channel_id} item={c} now={now} />)}
            </div>
            {(hidden > 0 || expanded || cards.length > 1 || canKeepOnTop()) && (
                <StackFooter
                    total={cards.length}
                    hidden={hidden}
                    expanded={expanded}
                    onToggle={() => setExpanded((e) => !e)}
                    channels={cards}
                />
            )}
        </section>
    );
}

function StackFooter({ total, hidden, expanded, onToggle, channels }: {
    total: number; hidden: number; expanded: boolean; onToggle: () => void; channels: AttentionChannel[];
}) {
    const qc = useQueryClient();
    const dismissAll = async () => {
        channels.forEach((c) => hideNoticeLocally(qc, c.channel_id));
        await Promise.allSettled(channels.map((c) => channelApi.dismissNotice(c.channel_id, c.notice?.message_id)));
        refreshAttention(qc);
        announceAttention();
    };
    return (
        <div className="pointer-events-auto flex items-center justify-between rounded-xl bg-white/90 backdrop-blur border border-surface-200 shadow-sm px-3 py-1.5 text-xs text-surface-600">
            {hidden > 0 || expanded ? (
                <button type="button" onClick={onToggle} aria-expanded={expanded}
                    className="font-medium text-surface-700 hover:text-surface-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 rounded px-1">
                    {expanded ? "Show fewer" : `${hidden} more conversation${hidden === 1 ? "" : "s"}`}
                </button>
            ) : <span>{total} conversation{total === 1 ? "" : "s"}</span>}
            {canKeepOnTop() && (
                <button type="button" onClick={() => { openKeepOnTop().catch(() => undefined); }}
                    title="Keep messages in a small window above other programs"
                    className="ml-auto mr-2 font-medium text-brand-700 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 rounded px-1">
                    Keep on top
                </button>
            )}
            <button type="button" onClick={dismissAll}
                className="hover:text-surface-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 rounded px-1">
                Dismiss all
            </button>
        </div>
    );
}

export function NoticeCard({ item, now, inPopout = false }: { item: AttentionChannel; now: number; inPopout?: boolean }) {
    const qc       = useQueryClient();
    const navigate = useNavigate();
    const toast    = useToastStore();
    const notice   = item.notice!;
    const [leaving, setLeaving]   = useState(false);
    const [replying, setReplying] = useState(false);
    const [draft, setDraft]       = useState("");
    const [sending, setSending]   = useState(false);
    const inputRef = useRef<HTMLTextAreaElement>(null);

    const sender  = notice.user?.name ?? "Someone";
    const isDm    = item.type === "dm";
    const where   = isDm ? null : item.context_type ? (item.name ?? "Order thread") : `#${item.name ?? "space"}`;
    const pending = item.pending_count > 0;

    useEffect(() => { if (replying) inputRef.current?.focus(); }, [replying]);

    const leave = (after: () => void) => {
        setLeaving(true);
        setTimeout(() => { hideNoticeLocally(qc, item.channel_id); after(); }, 150);
    };

    const dismiss = () => leave(async () => {
        try { await channelApi.dismissNotice(item.channel_id, notice.message_id); }
        finally { refreshAttention(qc); announceAttention(); }
    });

    const open = () => {
        hideNoticeLocally(qc, item.channel_id);
        navigate(`/comms/${item.channel_id}`);
        // From the keep-on-top window: bring the hub's own window forward.
        if (inPopout) { try { window.focus(); } catch { /* the browser decides */ } }
    };

    const send = async () => {
        const body = draft.trim();
        if (!body || sending) return;
        setSending(true);
        try {
            await channelApi.send(item.channel_id, { body });
            // The server reads the thread on a reply; the card goes everywhere.
            leave(() => { refreshAttention(qc); announceAttention(); });
            qc.invalidateQueries({ queryKey: ["channel-messages", item.channel_id] });
            toast.success(`Reply sent to ${isDm ? sender : where}`);
        } catch {
            toast.error("The reply was not sent. Check your connection and try again.");
            setSending(false);
        }
    };

    return (
        <article
            onKeyDown={(e) => { if (e.key === "Escape" && !replying) { e.stopPropagation(); dismiss(); } }}
            aria-label={`${sender}${where ? ` in ${where}` : ""}: ${notice.body}`}
            className={clsx(
                "pointer-events-auto relative overflow-hidden rounded-2xl bg-white/95 backdrop-blur",
                "border shadow-[0_8px_30px_rgba(15,23,42,0.12)] ring-1 ring-black/[0.02]",
                item.level === "escalated" ? "border-danger-300" : item.level === "warn" ? "border-amber-300" : "border-surface-200",
                leaving ? "animate-notice-out" : "animate-notice-in",
            )}
        >
            {pending && (
                <span aria-hidden="true" className={clsx("absolute inset-y-0 left-0 w-1",
                    item.level === "escalated" ? "bg-danger-500" : item.level === "warn" ? "bg-amber-400" : "bg-brand-400")} />
            )}
            <div className="flex gap-3 p-3 pl-3.5">
                <div className={clsx("w-9 h-9 rounded-full bg-gradient-to-br flex items-center justify-center shrink-0 text-xs font-bold text-white", avatarGradient(sender))}>
                    {notice.user?.initials ?? initialsOf(sender)}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-2">
                        <p className="min-w-0 flex-1 truncate text-[13.5px] leading-tight">
                            <span className="font-semibold text-surface-900">{sender}</span>
                            {where && <span className="text-surface-500"> · {where}</span>}
                        </p>
                        <time dateTime={notice.created_at} className="shrink-0 text-2xs text-surface-400 tabular-nums">{ago(notice.created_at, now)}</time>
                        <button type="button" onClick={dismiss} aria-label="Dismiss notification"
                            className="-mr-1 -mt-1 shrink-0 rounded-md p-1 text-surface-400 hover:bg-surface-100 hover:text-surface-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                            <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" strokeLinecap="round" /></svg>
                        </button>
                    </div>
                    <p className="mt-0.5 text-[13px] leading-snug text-surface-700 line-clamp-2 break-words">{notice.body || "Sent an attachment"}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        {notice.mentions_me && <Chip tone="brand">@ mentioned you</Chip>}
                        {pending && item.pending_since && (item.level === "warn" || item.level === "escalated") && (
                            <Chip tone={item.level === "escalated" ? "danger" : item.level === "warn" ? "amber" : "muted"}>
                                Waiting {waited(item.pending_since, now)}
                            </Chip>
                        )}
                        {notice.count > 1 && <Chip tone="muted">+{notice.count - 1} more</Chip>}
                    </div>

                    {replying ? (
                        <div className="mt-2">
                            <label className="sr-only" htmlFor={`notice-reply-${item.channel_id}`}>Reply to {sender}</label>
                            <textarea
                                id={`notice-reply-${item.channel_id}`}
                                ref={inputRef}
                                rows={2}
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(); }
                                    if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); setReplying(false); }
                                }}
                                placeholder={`Reply to ${isDm ? sender : where}…`}
                                className="w-full resize-none rounded-xl border border-surface-200 bg-surface-50 px-3 py-2 text-[13px] leading-snug focus:border-brand-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-100"
                            />
                            <div className="mt-1.5 flex items-center justify-between">
                                <span className="text-2xs text-surface-400">Enter to send · Shift+Enter new line</span>
                                <div className="flex gap-1.5">
                                    <Action onClick={() => setReplying(false)}>Cancel</Action>
                                    <Action primary disabled={!draft.trim() || sending} onClick={send}>{sending ? "Sending…" : "Send"}</Action>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="mt-2 flex gap-1.5">
                            <Action primary onClick={() => setReplying(true)}>Reply</Action>
                            <Action onClick={open}>Open</Action>
                            <Action onClick={dismiss}>Dismiss</Action>
                        </div>
                    )}
                </div>
            </div>
        </article>
    );
}

function Action({ children, onClick, primary, disabled }: { children: React.ReactNode; onClick: () => void; primary?: boolean; disabled?: boolean }) {
    return (
        <button type="button" onClick={onClick} disabled={disabled}
            className={clsx(
                "rounded-lg px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 disabled:opacity-50",
                primary ? "bg-brand-600 text-white hover:bg-brand-700" : "bg-surface-100 text-surface-700 hover:bg-surface-200",
            )}>
            {children}
        </button>
    );
}

function Chip({ children, tone }: { children: React.ReactNode; tone: "brand" | "danger" | "amber" | "muted" }) {
    return (
        <span className={clsx("inline-flex items-center rounded-full px-1.5 py-px text-2xs font-medium",
            tone === "brand" && "bg-brand-50 text-brand-700",
            tone === "danger" && "bg-danger-50 text-danger-700",
            tone === "amber" && "bg-amber-50 text-amber-800",
            tone === "muted" && "bg-surface-100 text-surface-600",
        )}>
            {children}
        </span>
    );
}
