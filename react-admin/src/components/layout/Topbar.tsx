import { useState, useRef, useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, del } from "@/api/client";
import { CommandPaletteButton } from "@/components/ui/CommandPalette";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";

// ─── Role name formatter ───────────────────────────────────────────────────────
function formatRoleName(raw: string): string {
    if (!raw) return raw;
    return raw
        .replace(/_/g, " ")
        .replace(/-/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

interface TopbarProps {
    collapsed: boolean;
    onToggleCollapse: () => void;
    onMobileMenuToggle?: () => void;
    breadcrumbs?: { label: string; href?: string }[];
}

// ── Notification types ────────────────────────────────────────────────────────

interface AppNotification {
    id: string;
    title: string;
    body?: string | null;
    action_url?: string | null;
    icon?: string | null;
    is_read: boolean;
    created_at: string;
}

// ── Icon map ──────────────────────────────────────────────────────────────────

const NotifIcon = ({ name }: { name: string }) => {
    const s = { className: "w-4 h-4", fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    if (name === "production") return <svg {...s}><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>;
    if (name === "tasks")      return <svg {...s}><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>;
    if (name === "payment")    return <svg {...s}><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>;
    if (name === "shipment")   return <svg {...s}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>;
    if (name === "qc")         return <svg {...s}><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>;
    if (name === "orders")     return <svg {...s}><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>;
    if (name === "stock")      return <svg {...s}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>;
    return <svg {...s}><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>;
};

function timeAgo(dateStr: string): string {
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60) return "just now";
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return new Date(dateStr).toLocaleDateString("en-KE", {
        dateStyle: "medium",
    });
}

// ── Notification link validation ──────────────────────────────────────────────
//
// Prefixes for every top-level route segment actually registered in App.tsx.
// Used to catch dead notification links before they silently fall through
// React Router's catch-all route, which redirects to /dashboard with no
// error — making broken notification links very hard to notice. If a
// Notification class is ever built against a route that doesn't exist (or a
// route later moves), this surfaces it loudly instead of bouncing the user
// to the dashboard with no explanation.
const KNOWN_ROUTE_PREFIXES = [
    "/dashboard", "/sales/", "/pos", "/production/", "/inventory/",
    "/procurement/", "/approvals", "/notifications", "/comms",
    "/expenses", "/finance/", "/reports", "/intelligence",
    "/catalogue/", "/settings/",
];

function isKnownRoute(url: string): boolean {
    return KNOWN_ROUTE_PREFIXES.some(prefix => url === prefix || url.startsWith(prefix));
}

// ── Notification Bell ─────────────────────────────────────────────────────────

function NotificationBell() {
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const [open, setOpen] = useState(false);
    const bellRef = useRef<HTMLDivElement>(null);

    // Close on outside click
    useEffect(() => {
        const h = (e: MouseEvent) => {
            if (bellRef.current && !bellRef.current.contains(e.target as Node))
                setOpen(false);
        };
        document.addEventListener("mousedown", h);
        return () => document.removeEventListener("mousedown", h);
    }, []);

    // Unread count — polls every 60s
    const { data: countData } = useQuery({
        queryKey: ["notif-count"],
        queryFn: () =>
            get<{ count: number }>("/v1/admin/notifications/unread-count"),
        refetchInterval: 30_000,
        staleTime: 30_000,
    });
    const unreadCount = countData?.count ?? 0;

    // Full list — fetched when dropdown opens
    const { data: listData, isLoading } = useQuery({
        queryKey: ["notif-list"],
        queryFn: () =>
            get<{ data: AppNotification[]; unread_count: number }>(
                "/v1/admin/notifications",
                {
                    params: { per_page: "15" },
                },
            ),
        enabled: open,
        staleTime: 15_000,
    });
    const notifications = listData?.data ?? [];

    const markReadMut = useMutation({
        mutationFn: (id: string) =>
            post(`/v1/admin/notifications/${id}/read`, {}),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["notif-count"] });
            qc.invalidateQueries({ queryKey: ["notif-list"] });
        },
    });

    const markAllMut = useMutation({
        mutationFn: () => post("/v1/admin/notifications/read-all", {}),
        onSuccess: (res: any) => {
            toast.success(res.message ?? "All marked as read");
            qc.invalidateQueries({ queryKey: ["notif-count"] });
            qc.invalidateQueries({ queryKey: ["notif-list"] });
        },
    });

    const deleteMut = useMutation({
        mutationFn: (id: string) => del(`/v1/admin/notifications/${id}`),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["notif-count"] });
            qc.invalidateQueries({ queryKey: ["notif-list"] });
        },
    });

    const handleNotifClick = (n: AppNotification) => {
        if (!n.is_read) markReadMut.mutate(n.id);
        if (!n.action_url) return;

        if (!isKnownRoute(n.action_url)) {
            // Don't navigate into the catch-all silently — that's exactly how a
            // "notification takes me to the dashboard" bug can go unnoticed for
            // a long time. Surface it loudly instead so it gets caught immediately.
            console.error(
                `[Notifications] action_url "${n.action_url}" does not match any known route — not navigating. ` +
                `Check the Notification class that generated this notification.`
            );
            toast.error("This notification's link appears to be broken.");
            setOpen(false);
            return;
        }

        navigate(n.action_url);
        setOpen(false);
    };

    return (
        <div className="relative" ref={bellRef}>
            <button
                onClick={() => setOpen((v) => !v)}
                className={clsx(
                    "relative btn-icon btn-ghost text-surface-500 transition-colors",
                    open && "bg-surface-100",
                )}
                aria-label="Notifications"
            >
                <svg
                    className="w-5 h-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"
                    />
                </svg>

                {/* Unread badge */}
                {unreadCount > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 bg-danger text-white text-2xs font-bold rounded-full flex items-center justify-center leading-none">
                        {unreadCount > 99 ? "99+" : unreadCount}
                    </span>
                )}
            </button>

            {/* Dropdown */}
            {open && (
                <div className="absolute right-0 top-full mt-1.5 w-80 bg-white rounded-2xl shadow-card-lg border border-surface-100 z-50 animate-slide-down overflow-hidden">
                    {/* Header */}
                    <div className="flex items-center justify-between px-4 py-3 border-b border-surface-100">
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-semibold text-surface-900">
                                Notifications
                            </h3>
                            {unreadCount > 0 && (
                                <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-brand-500 text-white text-2xs font-bold">
                                    {unreadCount}
                                </span>
                            )}
                        </div>
                        {unreadCount > 0 && (
                            <button
                                onClick={() => markAllMut.mutate()}
                                disabled={markAllMut.isPending}
                                className="text-2xs text-brand-500 hover:underline"
                            >
                                Mark all read
                            </button>
                        )}
                    </div>

                    {/* List */}
                    <div className="max-h-96 overflow-y-auto divide-y divide-surface-50">
                        {isLoading ? (
                            <div className="flex items-center justify-center py-10 text-surface-400">
                                <div className="w-5 h-5 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
                            </div>
                        ) : notifications.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-10 text-surface-400 gap-2">
                                <svg
                                    className="w-8 h-8 opacity-30"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth={1.5}
                                        d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"
                                    />
                                </svg>
                                <p className="text-xs">You're all caught up</p>
                            </div>
                        ) : (
                            notifications.map((n) => (
                                <div
                                    key={n.id}
                                    className={clsx(
                                        "group flex items-start gap-3 px-4 py-3 transition-colors cursor-pointer",
                                        n.is_read
                                            ? "hover:bg-surface-50"
                                            : "bg-brand-50/50 hover:bg-brand-50",
                                    )}
                                    onClick={() => handleNotifClick(n)}
                                >
                                    {/* Icon */}
                                    <div
                                        className={clsx(
                                            "w-8 h-8 rounded-xl flex items-center justify-center shrink-0 mt-0.5 text-surface-600",
                                            n.is_read
                                                ? "bg-surface-100"
                                                : "bg-brand-100",
                                        )}
                                    >
                                        <NotifIcon name={n.icon ?? "bell"} />
                                    </div>

                                    {/* Content */}
                                    <div className="flex-1 min-w-0">
                                        <p
                                            className={clsx(
                                                "text-xs leading-snug",
                                                n.is_read
                                                    ? "font-normal text-surface-700"
                                                    : "font-semibold text-surface-900",
                                            )}
                                        >
                                            {n.title}
                                        </p>
                                        {n.body && (
                                            <p className="text-2xs text-surface-500 mt-0.5 line-clamp-2">
                                                {n.body}
                                            </p>
                                        )}
                                        <p className="text-2xs text-surface-400 mt-1">
                                            {timeAgo(n.created_at)}
                                        </p>
                                    </div>

                                    {/* Unread dot + delete */}
                                    <div className="flex flex-col items-center gap-2 shrink-0">
                                        {!n.is_read && (
                                            <div className="w-2 h-2 rounded-full bg-brand-500 mt-1.5" />
                                        )}
                                        <button
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                deleteMut.mutate(n.id);
                                            }}
                                            className="opacity-0 group-hover:opacity-100 text-surface-300 hover:text-danger transition-opacity"
                                            aria-label="Close"
                                            title="Dismiss"
                                        >
                                            <svg
                                                className="w-3.5 h-3.5"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke="currentColor"
                                                strokeWidth={2}
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M6 18L18 6M6 6l12 12"
                                                />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>

                    {/* Footer */}
                    {notifications.length > 0 && (
                        <div className="px-4 py-2.5 border-t border-surface-100 text-center">
                            <button
                                onClick={() => {
                                    setOpen(false);
                                    navigate("/notifications");
                                }}
                                className="text-xs text-brand-500 hover:underline"
                            >
                                View all notifications →
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ── Messages Button ───────────────────────────────────────────────────────────

interface ChannelPreview {
    id: number;
    name: string;
    type: string;
    unread_count?: number;
    last_message?: { body: string; user_name?: string } | null;
}

function MessagesButton() {
    const navigate = useNavigate();
    const qc = useQueryClient();
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const h = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener("mousedown", h);
        return () => document.removeEventListener("mousedown", h);
    }, []);

    const { data, isLoading } = useQuery({
        queryKey: ["channels"],
        queryFn: () => get<{ channels: ChannelPreview[] }>("/v1/admin/channels"),
        refetchInterval: 30_000,
        staleTime: 30_000,
    });

    const channels = data?.channels ?? [];
    const totalUnread = channels.reduce((n, c) => n + (c.unread_count ?? 0), 0);

    const handleChannelClick = (id: number) => {
        setOpen(false);
        navigate(`/comms/${id}`);
    };

    return (
        <div className="relative" ref={ref}>
            <button
                onClick={() => setOpen((v) => !v)}
                className={clsx(
                    "relative btn-icon btn-ghost text-surface-500 transition-colors",
                    open && "bg-surface-100",
                )}
                aria-label="Messages"
            >
                {/* Chat bubble icon */}
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                </svg>
                {totalUnread > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 bg-danger text-white text-2xs font-bold rounded-full flex items-center justify-center leading-none">
                        {totalUnread > 99 ? "99+" : totalUnread}
                    </span>
                )}
            </button>

            {open && (
                <div className="absolute right-0 top-full mt-1.5 w-72 bg-white rounded-2xl shadow-card-lg border border-surface-100 z-50 animate-slide-down overflow-hidden">
                    {/* Header */}
                    <div className="flex items-center justify-between px-4 py-3 border-b border-surface-100">
                        <div className="flex items-center gap-2">
                            <h3 className="text-sm font-semibold text-surface-900">Messages</h3>
                            {totalUnread > 0 && (
                                <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-brand-500 text-white text-2xs font-bold">
                                    {totalUnread}
                                </span>
                            )}
                        </div>
                        <button
                            onClick={() => { setOpen(false); navigate("/comms"); }}
                            className="text-2xs text-brand-500 hover:underline"
                        >
                            Open all
                        </button>
                    </div>

                    {/* Channel list */}
                    <div className="max-h-80 overflow-y-auto divide-y divide-surface-50">
                        {isLoading ? (
                            <div className="flex items-center justify-center py-10">
                                <div className="w-5 h-5 border-2 border-brand-500 border-t-transparent rounded-full animate-spin" />
                            </div>
                        ) : channels.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-10 text-surface-400 gap-2">
                                <svg className="w-8 h-8 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
                                </svg>
                                <p className="text-xs">No conversations yet</p>
                            </div>
                        ) : (
                            channels.slice(0, 10).map((ch) => (
                                <button
                                    key={ch.id}
                                    onClick={() => handleChannelClick(ch.id)}
                                    className={clsx(
                                        "w-full flex items-start gap-3 px-4 py-3 text-left transition-colors",
                                        (ch.unread_count ?? 0) > 0
                                            ? "bg-brand-50/50 hover:bg-brand-50"
                                            : "hover:bg-surface-50"
                                    )}
                                >
                                    {/* Avatar */}
                                    <div className={clsx(
                                        "w-8 h-8 rounded-xl flex items-center justify-center shrink-0 text-xs font-bold mt-0.5",
                                        (ch.unread_count ?? 0) > 0
                                            ? "bg-brand-100 text-brand-700"
                                            : "bg-surface-100 text-surface-600"
                                    )}>
                                        {ch.type === "dm"
                                            ? (ch.name[0] ?? "D").toUpperCase()
                                            : "#"}
                                    </div>
                                    {/* Content */}
                                    <div className="flex-1 min-w-0">
                                        <p className={clsx(
                                            "text-xs leading-snug truncate",
                                            (ch.unread_count ?? 0) > 0
                                                ? "font-semibold text-surface-900"
                                                : "font-normal text-surface-700"
                                        )}>
                                            {ch.name}
                                        </p>
                                        {ch.last_message && (
                                            <p className="text-2xs text-surface-400 truncate mt-0.5">
                                                {ch.last_message.user_name ? `${ch.last_message.user_name}: ` : ""}{ch.last_message.body}
                                            </p>
                                        )}
                                    </div>
                                    {/* Unread badge */}
                                    {(ch.unread_count ?? 0) > 0 && (
                                        <span className="shrink-0 min-w-[18px] h-[18px] px-1 rounded-full bg-brand-600 text-white text-2xs font-bold flex items-center justify-center mt-0.5">
                                            {(ch.unread_count ?? 0) > 99 ? "99+" : ch.unread_count}
                                        </span>
                                    )}
                                </button>
                            ))
                        )}
                    </div>

                    {/* Footer */}
                    {channels.length > 0 && (
                        <div className="px-4 py-2.5 border-t border-surface-100 text-center">
                            <button
                                onClick={() => { setOpen(false); navigate("/comms"); }}
                                className="text-xs text-brand-500 hover:underline"
                            >
                                View all messages →
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ── Main Topbar ───────────────────────────────────────────────────────────────

export function Topbar({
    collapsed,
    onToggleCollapse,
    onMobileMenuToggle,
    breadcrumbs,
}: TopbarProps) {
    const navigate = useNavigate();
    const { user, logout } = useAuthStore();
    const toast = useToastStore();
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (
                menuRef.current &&
                !menuRef.current.contains(e.target as Node)
            ) {
                setUserMenuOpen(false);
            }
        };
        document.addEventListener("mousedown", handler);
        return () => document.removeEventListener("mousedown", handler);
    }, []);

    const handleLogout = async () => {
        setIsLoggingOut(true);
        try {
            await logout();
        } catch {
            // Swallow — clearAuth always runs inside logout()
        }
        toast.success("Logged out successfully.");
        navigate("/login");
    };

    return (
        <header className="h-[60px] bg-white border-b border-surface-100 flex items-center px-4 gap-4 shrink-0 z-10">
            {/* Collapse toggle (desktop) / Hamburger (mobile) */}
            <button
                onClick={() => {
                    if (window.innerWidth < 768) {
                        onMobileMenuToggle?.();
                    } else {
                        onToggleCollapse();
                    }
                }}
                className="btn-icon btn-ghost text-surface-500"
                aria-label="Toggle menu"
            >
                <svg
                    className="w-5 h-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                >
                    {collapsed ? (
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M4 6h16M4 12h16M4 18h16"
                        />
                    ) : (
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M4 6h16M4 12h10M4 18h16"
                        />
                    )}
                </svg>
            </button>

            {/* Breadcrumbs */}
            {breadcrumbs && breadcrumbs.length > 0 && (
                <nav
                    aria-label="Breadcrumb"
                    className="flex items-center gap-1.5 text-sm"
                >
                    {breadcrumbs.map((crumb, i) => (
                        <span key={i} className="flex items-center gap-1.5">
                            {i > 0 && (
                                <span className="text-surface-300">/</span>
                            )}
                            {crumb.href && i < breadcrumbs.length - 1 ? (
                                <Link
                                    to={crumb.href}
                                    className="text-surface-500 hover:text-surface-700 transition-colors"
                                >
                                    {crumb.label}
                                </Link>
                            ) : (
                                <span
                                    className={clsx(
                                        i === breadcrumbs.length - 1
                                            ? "text-surface-900 font-medium"
                                            : "text-surface-500",
                                    )}
                                >
                                    {crumb.label}
                                </span>
                            )}
                        </span>
                    ))}
                </nav>
            )}

            {/* Right side */}
            <div className="ml-auto flex items-center gap-2">
                {/* Command palette search button */}
                <CommandPaletteButton />

                {/* Messages */}
                <MessagesButton />

                {/* Notification bell */}
                <NotificationBell />

                {/* User menu */}
                <div className="relative" ref={menuRef}>
                    {(() => {
                        const fullName = user?.first_name || user?.last_name
                            ? `${user?.first_name ?? ""} ${user?.last_name ?? ""}`.trim()
                            : (user?.name ?? "");
                        const roleName = formatRoleName(user?.roles?.[0]?.display_name ?? user?.roles?.[0]?.name ?? user?.user_type ?? "");
                        return (
                            <button
                                onClick={() => setUserMenuOpen((v) => !v)}
                                className={clsx(
                                    "flex items-center gap-2.5 px-3 py-1.5 rounded-lg transition-colors",
                                    userMenuOpen ? "bg-surface-100" : "hover:bg-surface-50",
                                )}
                            >
                                <div className="w-7 h-7 rounded-full bg-brand-500/15 flex items-center justify-center">
                                    <span className="text-brand-600 text-xs font-semibold">
                                        {user?.first_name?.[0]}{user?.last_name?.[0]}
                                    </span>
                                </div>
                                <div className="hidden sm:flex flex-col items-start leading-tight">
                                    <span className="text-sm font-medium text-surface-700">{fullName}</span>
                                    {roleName && <span className="text-2xs text-surface-400">{roleName}</span>}
                                </div>
                                <svg
                                    className={clsx("w-4 h-4 text-surface-400 transition-transform hidden sm:block", userMenuOpen && "rotate-180")}
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                        );
                    })()}

                    {userMenuOpen && (
                        <div className="absolute right-0 top-full mt-1.5 w-56 bg-white rounded-xl shadow-card-lg border border-surface-100 py-1.5 animate-slide-down z-50">
                            {(() => {
                                const fullName = user?.first_name || user?.last_name
                                    ? `${user?.first_name ?? ""} ${user?.last_name ?? ""}`.trim()
                                    : (user?.name ?? "");
                                const roleName = formatRoleName(user?.roles?.[0]?.display_name ?? user?.roles?.[0]?.name ?? user?.user_type ?? "");
                                return (
                                    <div className="px-4 py-2.5 border-b border-surface-100 mb-1">
                                        <p className="text-sm font-semibold text-surface-900">{fullName}</p>
                                        <p className="text-xs text-surface-500 mt-0.5">{user?.email}</p>
                                        {roleName && (
                                            <span className="inline-block mt-1.5 px-2 py-0.5 rounded-full bg-brand-50 text-brand-600 text-2xs font-semibold">
                                                {roleName}
                                            </span>
                                        )}
                                    </div>
                                );
                            })()}
                            <MenuLink
                                href="/settings/profile"
                                onClick={() => setUserMenuOpen(false)}
                            >
                                My Profile
                            </MenuLink>
                            <div className="my-1 border-t border-surface-100" />
                            <button
                                onClick={handleLogout}
                                disabled={isLoggingOut}
                                className="w-full flex items-center gap-2 px-4 py-2 text-sm text-danger hover:bg-danger-light transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                {isLoggingOut && (
                                    <div className="w-3.5 h-3.5 border-2 border-danger border-t-transparent rounded-full animate-spin shrink-0" />
                                )}
                                {isLoggingOut ? "Signing out…" : "Sign out"}
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}

function MenuLink({
    href,
    onClick,
    children,
}: {
    href: string;
    onClick?: () => void;
    children: React.ReactNode;
}) {
    return (
        <Link
            to={href}
            onClick={onClick}
            className="block px-4 py-2 text-sm text-surface-700 hover:bg-surface-50 transition-colors"
        >
            {children}
        </Link>
    );
}