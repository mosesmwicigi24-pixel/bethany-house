/**
 * NotificationsPage - /notifications
 *
 * Full-page view of all in-app notifications.
 * Groups by date, supports unread filter, mark-all-read, and dismiss.
 */

import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, del } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

// ── Types ─────────────────────────────────────────────────────────────────────

interface AppNotification {
    id: string;
    title: string;
    body?: string | null;
    action_url?: string | null;
    icon?: string | null;
    is_read: boolean;
    created_at: string;
}

interface NotifResponse {
    data: AppNotification[];
    meta: { current_page: number; last_page: number; total: number };
    unread_count: number;
}

// ── Constants ─────────────────────────────────────────────────────────────────

const NotifIconSvg = ({ name }: { name: string }) => {
    const cls = "w-5 h-5";
    const s = { fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    if (name === "production") return <svg className={cls} {...s}><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>;
    if (name === "tasks")      return <svg className={cls} {...s}><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>;
    if (name === "payment")    return <svg className={cls} {...s}><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>;
    if (name === "shipment")   return <svg className={cls} {...s}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>;
    if (name === "qc")         return <svg className={cls} {...s}><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>;
    if (name === "orders")     return <svg className={cls} {...s}><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>;
    if (name === "stock")      return <svg className={cls} {...s}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>;
    // bell (default)
    return <svg className={cls} {...s}><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>;
};

const ICON_BG: Record<string, string> = {
    production: "bg-brand-100",
    tasks:      "bg-brand-50",
    payment:    "bg-warning-light",
    shipment:   "bg-purple-50",
    qc:         "bg-info-light",
    orders:     "bg-success-light",
    stock:      "bg-danger-light",
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function groupByDate(notifications: AppNotification[]): [string, AppNotification[]][] {
    const groups: Record<string, AppNotification[]> = {};
    const today     = new Date().toDateString();
    const yesterday = new Date(Date.now() - 86_400_000).toDateString();

    for (const n of notifications) {
        const d   = new Date(n.created_at);
        const key = d.toDateString() === today ? "Today"
            : d.toDateString() === yesterday ? "Yesterday"
            : d.toLocaleDateString("en-KE", { dateStyle: "long" });
        if (!groups[key]) groups[key] = [];
        groups[key].push(n);
    }
    return Object.entries(groups);
}

function timeAgo(dateStr: string): string {
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60)    return "just now";
    if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return new Date(dateStr).toLocaleTimeString("en-KE", { timeStyle: "short" });
}

// ── Notification link validation ──────────────────────────────────────────────
//
// Same guard as Topbar.tsx's NotificationBell: prefixes for every top-level
// route segment actually registered in App.tsx. Without this, a stale or
// mistyped action_url silently falls through React Router's catch-all route
// and bounces the user to /dashboard with no explanation — exactly the bug
// this page exists to let people avoid by giving them a second way in.
const KNOWN_ROUTE_PREFIXES = [
    "/dashboard", "/sales/", "/pos", "/production/", "/inventory/",
    "/procurement/", "/approvals", "/notifications", "/comms",
    "/expenses", "/finance/", "/reports", "/intelligence",
    "/catalogue/", "/settings/",
];

function isKnownRoute(url: string): boolean {
    return KNOWN_ROUTE_PREFIXES.some(prefix => url === prefix || url.startsWith(prefix));
}

// ── Notification row ──────────────────────────────────────────────────────────

function NotifRow({
    n, onRead, onDelete, onBrokenLink,
}: {
    n: AppNotification;
    onRead: (id: string) => void;
    onDelete: (id: string) => void;
    onBrokenLink: (url: string) => void;
}) {
    const navigate = useNavigate();
    const icon     = n.icon ?? "bell";
    const hasValidLink = !!n.action_url && isKnownRoute(n.action_url);

    const handleClick = () => {
        if (!n.is_read) onRead(n.id);
        if (!n.action_url) return;

        if (!isKnownRoute(n.action_url)) {
            // Don't navigate into the catch-all silently — surface it instead
            // of bouncing the user to /dashboard with no explanation.
            onBrokenLink(n.action_url);
            return;
        }
        navigate(n.action_url);
    };

    return (
        <div
            onClick={handleClick}
            className={clsx(
                "group flex items-start gap-4 px-5 py-4 cursor-pointer transition-colors border-b border-surface-50 last:border-0",
                n.is_read ? "hover:bg-surface-50/60" : "bg-brand-50/40 hover:bg-brand-50/70",
            )}
        >
            {/* Icon */}
            <div className={clsx(
                "w-10 h-10 rounded-xl flex items-center justify-center shrink-0 mt-0.5 text-surface-600",
                ICON_BG[icon] ?? "bg-surface-100",
            )}>
                <NotifIconSvg name={icon} />
            </div>

            {/* Content */}
            <div className="flex-1 min-w-0">
                <div className="flex items-start justify-between gap-2">
                    <p className={clsx(
                        "text-sm leading-snug",
                        n.is_read ? "text-surface-700 font-normal" : "text-surface-900 font-semibold",
                    )}>
                        {n.title}
                    </p>
                    <span className="text-2xs text-surface-400 shrink-0 mt-0.5 whitespace-nowrap">
                        {timeAgo(n.created_at)}
                    </span>
                </div>
                {n.body && (
                    <p className="text-xs text-surface-500 mt-1 line-clamp-2">{n.body}</p>
                )}
                {hasValidLink && (
                    <p className="text-2xs text-brand-500 mt-1.5">Tap to view →</p>
                )}
            </div>

            {/* Right controls */}
            <div className="flex flex-col items-center gap-2 shrink-0 self-center">
                {!n.is_read && (
                    <div className="w-2.5 h-2.5 rounded-full bg-brand-500" />
                )}
                <button
                    onClick={e => { e.stopPropagation(); onDelete(n.id); }}
                    className="opacity-0 group-hover:opacity-100 transition-opacity text-surface-300 hover:text-danger p-1 rounded-lg hover:bg-danger-light"
                    title="Dismiss"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function NotificationsPage() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const [filter, setFilter] = useState<"all" | "unread">("all");
    const [page,   setPage]   = useState(1);

    const { data, isLoading, isFetching } = useQuery<NotifResponse>({
        queryKey: ["notifications-page", filter, page],
        queryFn:  () => get<NotifResponse>("/v1/admin/notifications", {
            params: {
                per_page:    "30",
                page:        String(page),
                unread_only: filter === "unread" ? "1" : undefined,
            } as any,
        }),
        placeholderData: prev => prev,
    });

    const invalidate = () => {
        qc.invalidateQueries({ queryKey: ["notifications-page"] });
        qc.invalidateQueries({ queryKey: ["notif-count"] });
        qc.invalidateQueries({ queryKey: ["notif-list"] });
    };

    const readMut = useMutation({
        mutationFn: (id: string) => post(`/v1/admin/notifications/${id}/read`, {}),
        onSuccess:  invalidate,
        onError:    (e: ApiError) => toast.error(e.message),
    });

    const deleteMut = useMutation({
        mutationFn: (id: string) => del(`/v1/admin/notifications/${id}`),
        onSuccess:  invalidate,
        onError:    (e: ApiError) => toast.error(e.message),
    });

    const markAllMut = useMutation({
        mutationFn: () => post("/v1/admin/notifications/read-all", {}),
        onSuccess:  () => { toast.success("All notifications marked as read"); invalidate(); },
        onError:    (e: ApiError) => toast.error(e.message),
    });

    const notifications = data?.data ?? [];
    const meta          = data?.meta;
    const unreadCount   = data?.unread_count ?? 0;
    const groups        = groupByDate(notifications);

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:flex-wrap">
                <div>
                    <h1 className="page-title">Notifications</h1>
                    <p className="page-subtitle">
                        {meta?.total ? `${meta.total.toLocaleString()} total` : ""}
                        {unreadCount > 0 && ` · ${unreadCount} unread`}
                        {isFetching && !isLoading && (
                            <span className="ml-2 text-brand-400 text-xs">Refreshing…</span>
                        )}
                    </p>
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                    {/* Filter tabs */}
                    <div className="flex gap-1 bg-surface-100 p-1 rounded-xl">
                        {(["all", "unread"] as const).map(k => (
                            <button key={k} onClick={() => { setFilter(k); setPage(1); }}
                                className={clsx(
                                    "px-4 py-1.5 rounded-lg text-xs font-semibold transition-all capitalize",
                                    filter === k
                                        ? "bg-white text-surface-900 shadow-sm"
                                        : "text-surface-500 hover:text-surface-700",
                                )}>
                                {k}
                                {k === "unread" && unreadCount > 0 && (
                                    <span className="ml-1.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-brand-500 text-white text-2xs font-bold">
                                        {unreadCount > 99 ? "99+" : unreadCount}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>

                    {unreadCount > 0 && (
                        <button
                            onClick={() => markAllMut.mutate()}
                            disabled={markAllMut.isPending}
                            className="btn-secondary btn-sm text-xs"
                        >
                            {markAllMut.isPending ? "Marking…" : "Mark all read"}
                        </button>
                    )}
                </div>
            </div>

            {/* List */}
            <div className="card overflow-hidden p-0">
                {isLoading ? (
                    <div className="flex items-center justify-center py-20">
                        <Spinner size="lg" />
                    </div>
                ) : notifications.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-20 text-surface-400 gap-3">
                        <div className="w-14 h-14 bg-surface-100 rounded-2xl flex items-center justify-center text-surface-400">
                            <svg className="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                        </div>
                        <p className="text-sm font-medium text-surface-500">
                            {filter === "unread" ? "No unread notifications" : "No notifications yet"}
                        </p>
                        <p className="text-xs text-surface-400">
                            {filter === "unread"
                                ? "You're all caught up!"
                                : "Notifications from production, payments, and shipments will appear here."}
                        </p>
                    </div>
                ) : (
                    <div>
                        {groups.map(([date, items]) => (
                            <div key={date}>
                                <div className="px-5 py-2.5 bg-surface-50 border-b border-surface-100">
                                    <p className="text-2xs font-semibold text-surface-400 uppercase tracking-wide">{date}</p>
                                </div>
                                {items.map(n => (
                                    <NotifRow
                                        key={n.id}
                                        n={n}
                                        onRead={id => readMut.mutate(id)}
                                        onDelete={id => deleteMut.mutate(id)}
                                        onBrokenLink={url => {
                                            console.error(
                                                `[Notifications] action_url "${url}" does not match any known route — not navigating. ` +
                                                `Check the Notification class that generated this notification.`
                                            );
                                            toast.error("This notification's link appears to be broken.");
                                        }}
                                    />
                                ))}
                            </div>
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="px-5 py-3 border-t border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-surface-400">
                            Page {meta.current_page} of {meta.last_page}
                        </p>
                        <div className="flex gap-2">
                            <button onClick={() => setPage(p => Math.max(1, p - 1))}
                                disabled={page <= 1}
                                className="btn-secondary btn-sm">← Prev</button>
                            <button onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
                                disabled={page >= meta.last_page}
                                className="btn-secondary btn-sm">Next →</button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}