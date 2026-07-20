import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate, Link } from "react-router-dom";
import {
    AreaChart, Area, XAxis, YAxis, Tooltip, ResponsiveContainer,
    BarChart, Bar, Cell, PieChart, Pie,
} from "recharts";
import { get } from "@/api/client";
import { reportsApi } from "@/api/reports";
import { useAuthStore } from "@/store/auth.store";
import { usePermissions } from "@/hooks/usePermissions";
import { clsx } from "clsx";

// ── Types ─────────────────────────────────────────────────────────────────────

interface DashboardStats {
    total_users?: number;
    active_users?: number;
    staff_users?: number;
    customers?: number;
    total_orders?: number;
    pending_orders?: number;
    today_orders?: number;
    today_sales?: number;
    total_products?: number;
    low_stock_products?: number;
    pending_payment_approvals?: number;
    shipments_in_transit?: number;
    shipments_pending_dispatch?: number;
    production_draft?: number;
    production_queue?: number;
    production_in_progress?: number;
    production_qc_pending?: number;
    production_overdue?: number;
    unread_notifications?: number;
}

interface DashboardAlert {
    type: "warning" | "danger" | "info";
    icon: string;
    message: string;
    action_url: string;
    action_label: string;
}

interface ActivityItem {
    type: string;
    description: string;
    user: string;
    time: string;
}

// ── Alert icon SVGs ───────────────────────────────────────────────────────────

const AlertIconSvg = ({ name }: { name: string }) => {
    const cls = "w-4 h-4 shrink-0";
    if (name === "payment")    return <svg className={cls} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>;
    if (name === "production") return <svg className={cls} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>;
    if (name === "stock")      return <svg className={cls} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>;
    if (name === "shipment")   return <svg className={cls} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>;
    return <svg className={cls} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>;
};

// ── Alert bar ─────────────────────────────────────────────────────────────────

function AlertBar({ alerts }: { alerts: DashboardAlert[] }) {
    const navigate = useNavigate();
    if (!alerts.length) return null;
    return (
        <div className="space-y-2 mb-6">
            {alerts.map((a, i) => (
                <div key={i} className={clsx(
                    "flex items-center justify-between gap-3 px-4 py-3 rounded-xl text-sm",
                    a.type === "danger"  ? "bg-danger-light text-danger border border-danger/20"  :
                    a.type === "warning" ? "bg-warning-light text-warning-dark border border-warning/20" :
                                          "bg-info-light text-info border border-info/20",
                )}>
                    <span className="flex items-center gap-2">
                        <AlertIconSvg name={a.icon} />
                        {a.message}
                    </span>
                    <button
                        onClick={() => navigate(a.action_url)}
                        className="text-xs font-semibold underline underline-offset-2 shrink-0 whitespace-nowrap"
                    >
                        {a.action_label} →
                    </button>
                </div>
            ))}
        </div>
    );
}

// ── Period options ────────────────────────────────────────────────────────────

const PERIODS = [
    { label: "Today",   days: 1  },
    { label: "7 days",  days: 7  },
    { label: "30 days", days: 30 },
    { label: "90 days", days: 90 },
] as const;

// ── Compact currency formatter ────────────────────────────────────────────────

function fmtCompact(n?: number | null): string {
    if (n === undefined || n === null) return "—";
    if (n >= 1_000_000) return `KES ${(n / 1_000_000).toFixed(1)}M`;
    if (n >= 1_000)     return `KES ${(n / 1_000).toFixed(1)}K`;
    return `KES ${n.toLocaleString("en-KE", { minimumFractionDigits: 0 })}`;
}

// ── Revenue KPI card with sparkline ──────────────────────────────────────────

function RevenueCard({
    revenue, orders, avgValue, trend, loading, periodLabel,
}: {
    revenue?: number; orders?: number; avgValue?: number;
    trend: { date: string; revenue: number }[];
    loading: boolean; periodLabel: string;
}) {
    const chartData = trend.map(d => ({
        date: d.date.slice(5),
        revenue: Math.round(d.revenue ?? 0),
    }));

    return (
        <div className="card overflow-hidden lg:col-span-2">
            <div className="card-body pb-2">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <p className="text-xs text-surface-500 mb-1">Revenue — {periodLabel}</p>
                        {loading ? (
                            <div className="skeleton h-8 w-32 rounded" />
                        ) : (
                            <p className="text-3xl font-bold text-surface-900 tabular-nums">
                                {fmtCompact(revenue)}
                            </p>
                        )}
                        {!loading && (
                            <div className="flex items-center gap-3 mt-1 flex-wrap">
                                <span className="text-xs text-surface-500">{orders?.toLocaleString() ?? 0} orders</span>
                                <span className="text-xs text-surface-400">·</span>
                                <span className="text-xs text-surface-500">avg {fmtCompact(avgValue)}</span>
                            </div>
                        )}
                    </div>
                    {!loading && chartData.length > 0 && (
                        <Link to="/reports/sales" className="text-xs text-brand-500 hover:underline shrink-0 mt-1">
                            Full report →
                        </Link>
                    )}
                </div>
            </div>
            <div className="h-28 px-1 pb-2">
                {loading ? (
                    <div className="skeleton h-full w-full rounded-xl" />
                ) : chartData.length > 0 ? (
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={chartData} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                            <defs>
                                <linearGradient id="revGrad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%"  stopColor="#6366F1" stopOpacity={0.18}/>
                                    <stop offset="95%" stopColor="#6366F1" stopOpacity={0}/>
                                </linearGradient>
                            </defs>
                            <XAxis dataKey="date" tick={{ fontSize: 10, fill: "var(--color-text-tertiary)" }}
                                axisLine={false} tickLine={false} interval="preserveStartEnd" />
                            <YAxis hide />
                            <Tooltip
                                contentStyle={{ fontSize: 12, borderRadius: 8, border: "1px solid var(--color-border-tertiary)" }}
                                formatter={(v: any) => [fmtCompact(Number(v ?? 0)), "Revenue"]}
                                labelStyle={{ color: "var(--color-text-secondary)" }}
                            />
                            <Area type="monotone" dataKey="revenue" stroke="#6366F1"
                                strokeWidth={2} fill="url(#revGrad)" dot={false} />
                        </AreaChart>
                    </ResponsiveContainer>
                ) : (
                    <div className="h-full flex items-center justify-center text-xs text-surface-400">
                        Not enough data for trend
                    </div>
                )}
            </div>
        </div>
    );
}

// ── Channel split — two cards ─────────────────────────────────────────────────
// Split into its own bar card and pie card so each reads clearly on a phone:
// the bar compares the two channels' revenue at a glance, the donut shows their
// share of the whole. Both feed off the same salesSummary numbers.

const CHANNEL_ONLINE = "#6366F1";
const CHANNEL_POS    = "#10B981";

// PostgreSQL returns numeric columns as strings over the wire — cast to numbers.
function channelData(onlineRevenue?: number | string, posRevenue?: number | string) {
    const online = Number(onlineRevenue ?? 0);
    const pos    = Number(posRevenue    ?? 0);
    return {
        online, pos, total: online + pos,
        rows: [
            { name: "Online", value: online, color: CHANNEL_ONLINE },
            { name: "POS",    value: pos,    color: CHANNEL_POS },
        ],
    };
}

function ChannelBarCard({
    onlineRevenue, posRevenue, loading,
}: {
    onlineRevenue?: number | string; posRevenue?: number | string; loading: boolean;
}) {
    const { rows } = channelData(onlineRevenue, posRevenue);
    return (
        <div className="card card-body">
            <p className="text-xs text-surface-500 mb-2">Channel split — revenue</p>
            {loading ? (
                <div className="skeleton h-28 w-full rounded-xl" />
            ) : (
                <div className="h-28">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={rows} margin={{ top: 6, right: 6, left: 0, bottom: 0 }}>
                            <XAxis dataKey="name" tick={{ fontSize: 11, fill: "var(--color-text-tertiary)" }}
                                axisLine={false} tickLine={false} />
                            <YAxis hide />
                            <Tooltip cursor={{ fill: "var(--color-surface-100, #f1f5f9)" }}
                                contentStyle={{ fontSize: 12, borderRadius: 8, border: "1px solid var(--color-border-tertiary)" }}
                                formatter={(v: any) => [fmtCompact(Number(v ?? 0)), "Revenue"]} />
                            <Bar dataKey="value" radius={[6, 6, 0, 0]} maxBarSize={64}>
                                {rows.map((d) => <Cell key={d.name} fill={d.color} />)}
                            </Bar>
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </div>
    );
}

function ChannelPieCard({
    onlineRevenue, posRevenue, onlineCount, posCount, loading,
}: {
    onlineRevenue?: number | string; posRevenue?: number | string;
    onlineCount?: number | string;   posCount?: number | string;
    loading: boolean;
}) {
    const { rows, total } = channelData(onlineRevenue, posRevenue);
    const pct = (v: number) => total > 0 ? Math.round(v / total * 100) : 50;

    return (
        <div className="card card-body">
            <p className="text-xs text-surface-500 mb-2">Channel split — share</p>
            {loading ? (
                <div className="skeleton h-28 w-full rounded-xl" />
            ) : (
                <div className="flex items-center gap-3">
                    <div className="h-24 w-24 shrink-0">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie data={rows} dataKey="value" nameKey="name"
                                    innerRadius="58%" outerRadius="100%" paddingAngle={2} stroke="none">
                                    {rows.map((d) => <Cell key={d.name} fill={d.color} />)}
                                </Pie>
                                <Tooltip contentStyle={{ fontSize: 12, borderRadius: 8, border: "1px solid var(--color-border-tertiary)" }}
                                    formatter={(v: any) => [fmtCompact(Number(v ?? 0)), "Revenue"]} />
                            </PieChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="flex-1 min-w-0 space-y-1.5">
                        {rows.map((d) => (
                            <div key={d.name} className="flex items-center justify-between gap-2">
                                <span className="flex items-center gap-1.5 text-xs text-surface-600">
                                    <span className="w-2 h-2 rounded-full shrink-0" style={{ background: d.color }} />
                                    {d.name}
                                </span>
                                <span className="text-xs font-semibold text-surface-900 tabular-nums">{pct(d.value)}%</span>
                            </div>
                        ))}
                        <p className="text-2xs text-surface-400 pt-0.5">
                            {(Number(onlineCount ?? 0) + Number(posCount ?? 0)).toLocaleString()} orders total
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}

// ── Cash collected card ───────────────────────────────────────────────────────
// Uses GET /v1/admin/payment-transactions (PaymentController::allTransactions)
// filtered to cash payments today. The /v1/admin/payments/cash-report route
// does not exist — this is the correct existing endpoint.

function useCashToday() {
    const today = new Date().toISOString().slice(0, 10);
    const { data, isLoading } = useQuery({
        queryKey: ["cash-today"],
        queryFn: () => get<{ data: { amount: string; payment_method: string; status: string }[]; total: number }>(
            "/v1/admin/payment-transactions",
            { params: { payment_method: "cash", status: "paid", start_date: today, end_date: today, per_page: "200" } } as any
        ),
        staleTime: 2 * 60_000,
        retry: false,
    });
    const transactions = data?.data ?? [];
    const totalCash = transactions.reduce((sum, t) => sum + parseFloat(t.amount ?? "0"), 0);
    return { totalCash, txCount: transactions.length, isLoading };
}

// `compact` renders the small KPI-tile shape so cash can sit in the stat row
// alongside the other numbers; the default is the roomier standalone card that
// the outlet / POS-clerk grids still use.
function CashTodayCard({ compact = false }: { compact?: boolean }) {
    const { totalCash, txCount, isLoading } = useCashToday();

    if (compact) {
        return (
            <KpiTile label="Cash today" value={fmtCompact(totalCash)} tone="text-success"
                href="/approvals" loading={isLoading} />
        );
    }

    return (
        <div className="card card-body">
            <div className="flex items-center gap-2 mb-1">
                <svg className="w-4 h-4 text-success shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round">
                    <rect x="2" y="6" width="20" height="14" rx="2"/>
                    <path d="M2 10h20M6 14h.01M10 14h4"/>
                </svg>
                <p className="text-xs text-surface-500">Cash today</p>
            </div>
            {isLoading ? (
                <div className="skeleton h-7 w-24 rounded mt-1" />
            ) : (
                <>
                    <p className="text-2xl font-bold text-surface-900 tabular-nums mt-1">
                        {fmtCompact(totalCash)}
                    </p>
                    <p className="text-xs text-surface-400 mt-1">
                        {txCount} transaction{txCount !== 1 ? "s" : ""}
                    </p>
                </>
            )}
            <Link to="/approvals" className="mt-2 text-2xs text-brand-500 hover:underline block">
                Cash reconciliation →
            </Link>
        </div>
    );
}

// ── Compact KPI tile ──────────────────────────────────────────────────────────
// A small stat: a bold number over a label, tappable. Deliberately dense so a
// whole row of six fits on a phone without dominating the screen.

function KpiTile({ label, value, tone = "text-surface-900", href, loading, badge }: {
    label: string; value?: number | string; tone?: string;
    href?: string; loading?: boolean; badge?: number;
}) {
    const body = (
        <div className="relative bg-white border border-surface-100 rounded-xl px-2.5 py-2 h-full hover:border-brand-200 transition-colors">
            {loading ? (
                <div className="skeleton h-5 w-12 rounded mb-1" />
            ) : (
                <p className={clsx("text-base sm:text-lg font-bold leading-tight tabular-nums truncate", tone)}>
                    {value ?? "—"}
                </p>
            )}
            <p className="text-2xs text-surface-500 mt-0.5 leading-tight truncate">{label}</p>
            {!!badge && badge > 0 && (
                <span className="absolute top-1 right-1 min-w-[16px] h-4 px-1 rounded-full bg-danger text-white text-2xs font-bold flex items-center justify-center">
                    {badge > 99 ? "99+" : badge}
                </span>
            )}
        </div>
    );
    return href ? <Link to={href} className="block h-full">{body}</Link> : body;
}

// ── Stat card ─────────────────────────────────────────────────────────────────

// ─── Tailor home ──────────────────────────────────────────────────────────────
// The tailor's landing screen answers ONE question: "what should I work on
// next?" Layout order is deliberate: a compact stat strip (numbers, not
// furniture) → My Tasks as the hero, smart-sorted by deadline risk → quick
// actions as an icon grid → recent activity last (rendered by the parent).

function TailorStatTile({ label, value, tone, href, loading }: {
    label: string; value?: number; tone: string; href: string; loading: boolean;
}) {
    return (
        <Link to={href} className="bg-white border border-surface-100 rounded-xl px-3 py-2.5 hover:border-brand-200 transition-colors">
            <p className={clsx("text-xl font-bold leading-tight", tone)}>{loading ? "…" : (value ?? 0)}</p>
            <p className="text-2xs text-surface-500 mt-0.5 leading-tight">{label}</p>
        </Link>
    );
}

function TailorHome({ stats, isLoading, can }: {
    stats?: DashboardStats; isLoading: boolean; can: (p: string) => boolean;
}) {
    // Same smart-sorted feed as My Tasks (IntelligenceService orders it by
    // deadline-miss risk) — the dashboard shows the top of that queue.
    const { data: tasks, isLoading: tasksLoading } = useQuery<any[]>({
        queryKey: ["tailor-home-tasks"],
        queryFn: () => get<any[]>("/v1/tailor/tasks"),
        staleTime: 30_000,
    });
    const top = (tasks ?? []).slice(0, 4);

    const isQc = can("production.submit_qc") || can("production.approve_qc");

    const quickActions = [
        { label: "My Tasks",      href: "/production/my-tasks",   icon: <ClipboardIcon /> },
        { label: "Messages",      href: "/comms",                 icon: <PaymentIcon /> },
        { label: "Notifications", href: "/notifications",         icon: <AlertIcon />, badge: stats?.unread_notifications },
        { label: "Calendar",      href: "/production/calendar",   icon: <ClockIcon /> },
    ];

    const dueChip = (t: any) => {
        if (!t.production_order?.due_date) return null;
        const days = Math.ceil((new Date(t.production_order.due_date).getTime() - Date.now()) / 86_400_000);
        if (days < 0)  return <span className="text-2xs font-bold text-danger bg-danger-light rounded-full px-2 py-0.5">Overdue {-days}d</span>;
        if (days === 0) return <span className="text-2xs font-bold text-warning-dark bg-warning-light rounded-full px-2 py-0.5">Due today</span>;
        return <span className="text-2xs font-semibold text-surface-500 bg-surface-100 rounded-full px-2 py-0.5">Due in {days}d</span>;
    };

    return (
        <div className="space-y-4">
            {/* Compact stat strip — numbers at a glance, no vertical sprawl */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                <TailorStatTile label="Active"   value={stats?.production_in_progress} tone="text-brand-600"  href="/production/my-tasks" loading={isLoading} />
                <TailorStatTile label="In queue" value={stats?.production_queue}       tone="text-info"       href="/production/my-tasks" loading={isLoading} />
                <TailorStatTile label="Overdue"  value={stats?.production_overdue}     tone="text-danger"     href="/production/my-tasks" loading={isLoading} />
                {isQc
                    ? <TailorStatTile label="QC pending" value={stats?.production_qc_pending} tone="text-purple-600" href="/production/qc" loading={isLoading} />
                    : <TailorStatTile label="Alerts"     value={stats?.unread_notifications}  tone="text-warning-dark" href="/notifications" loading={isLoading} />}
            </div>

            {/* THE HERO — what to work on next */}
            <div className="card overflow-hidden">
                <div className="card-header flex items-center justify-between">
                    <h2 className="font-semibold text-sm text-surface-900">My Tasks</h2>
                    <Link to="/production/my-tasks" className="text-xs font-semibold text-brand-600 hover:text-brand-700">Open all →</Link>
                </div>
                <div className="divide-y divide-surface-50">
                    {tasksLoading ? (
                        <div className="py-8 text-center text-sm text-surface-400">Loading…</div>
                    ) : top.length === 0 ? (
                        <div className="py-8 text-center">
                            <p className="text-sm font-medium text-surface-500">Nothing assigned right now</p>
                            <p className="text-xs text-surface-400 mt-1">New work will appear here the moment it's assigned to you.</p>
                        </div>
                    ) : top.map((t: any, i: number) => (
                        <Link key={t.id} to="/production/my-tasks"
                            className={clsx("flex items-center gap-3 px-4 py-3 hover:bg-surface-50 transition-colors", i === 0 && "bg-brand-50/40")}>
                            <span className={clsx("w-7 h-7 rounded-full flex items-center justify-center text-2xs font-bold shrink-0",
                                t.status === "in_progress" ? "bg-brand-500 text-white" : "bg-surface-100 text-surface-500")}>
                                {i + 1}
                            </span>
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-semibold text-surface-900 truncate">
                                    {t.production_order?.product?.translations?.[0]?.name ?? t.production_order?.order_number ?? "Task"}
                                </p>
                                <p className="text-2xs text-surface-400 truncate">
                                    {t.stage?.name}{t.production_order?.order_number ? ` · ${t.production_order.order_number}` : ""}
                                    {t.blocked_by_stage ? ` · 🔒 waiting on ${t.blocked_by_stage}` : ""}
                                </p>
                            </div>
                            <div className="shrink-0 flex items-center gap-2">
                                {dueChip(t)}
                                {t.status === "in_progress" && (
                                    <span className="text-2xs font-bold text-brand-600 bg-brand-50 border border-brand-200 rounded-full px-2 py-0.5">In progress</span>
                                )}
                            </div>
                        </Link>
                    ))}
                </div>
            </div>

            {/* Quick actions — icons with a name, one row, not furniture */}
            <div className="grid grid-cols-4 gap-2.5">
                {quickActions.map((a) => (
                    <Link key={a.href} to={a.href}
                        className="relative bg-white border border-surface-100 rounded-xl py-3 flex flex-col items-center gap-1.5 hover:border-brand-200 hover:bg-brand-50/30 transition-colors">
                        <span className="text-surface-500">{a.icon}</span>
                        <span className="text-2xs font-semibold text-surface-600">{a.label}</span>
                        {!!a.badge && (
                            <span className="absolute top-1.5 right-1.5 min-w-[16px] h-4 px-1 rounded-full bg-danger text-white text-2xs font-bold flex items-center justify-center">{a.badge > 99 ? "99+" : a.badge}</span>
                        )}
                    </Link>
                ))}
            </div>
        </div>
    );
}

function StatCard({
    label, value, icon, color, loading, href, badge,
}: {
    label: string; value?: number | string; icon: React.ReactNode;
    color: string; loading?: boolean; href?: string; badge?: number;
}) {
    const navigate = useNavigate();
    const inner = (
        <div className="card p-5 flex items-center gap-4 group">
            <div className={clsx("w-11 h-11 rounded-xl flex items-center justify-center shrink-0 relative", color)}>
                {icon}
                {badge && badge > 0 ? (
                    <span className="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-danger text-white text-2xs font-bold rounded-full flex items-center justify-center">
                        {badge > 99 ? "99+" : badge}
                    </span>
                ) : null}
            </div>
            <div className="min-w-0 flex-1">
                {loading ? (
                    <>
                        <div className="skeleton h-6 w-16 rounded mb-1" />
                        <div className="skeleton h-3 w-24 rounded" />
                    </>
                ) : (
                    <>
                        <p className="font-display text-2xl font-bold text-surface-900 leading-none">
                            {value?.toLocaleString() ?? "—"}
                        </p>
                        <p className="text-xs text-surface-500 mt-1">{label}</p>
                    </>
                )}
            </div>
            {href && !loading && (
                <svg className="w-4 h-4 text-surface-300 group-hover:text-brand-400 transition-colors shrink-0"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
            )}
        </div>
    );
    if (href) return (
        <button className="text-left w-full" onClick={() => navigate(href)}>{inner}</button>
    );
    return inner;
}

// ── Production mini-board ─────────────────────────────────────────────────────

function ProductionSummaryCard({ stats, loading }: { stats?: DashboardStats; loading: boolean }) {
    const stages = [
        { key: "production_draft",       label: "Draft",       color: "text-surface-400", bg: "bg-surface-50",      href: "/production?status=draft"       },
        { key: "production_queue",       label: "In Queue",    color: "text-brand-600",   bg: "bg-brand-50",         href: "/production?status=pending"     },
        { key: "production_in_progress", label: "In Progress", color: "text-info",        bg: "bg-info-light",       href: "/production?status=in_progress" },
        { key: "production_qc_pending",  label: "QC",          color: "text-purple-600",  bg: "bg-purple-50",        href: "/production?status=qc_pending"  },
    ] as const;

    return (
        <div className="card overflow-hidden">
            <div className="card-header flex items-center justify-between">
                <h2 className="font-semibold text-sm text-surface-900 flex items-center gap-2">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                    Production Queue
                    {(stats?.production_overdue ?? 0) > 0 && (
                        <span className="badge text-2xs badge-danger">
                            {stats!.production_overdue} overdue
                        </span>
                    )}
                </h2>
                <Link to="/production" className="text-xs text-brand-500 hover:underline">View all →</Link>
            </div>
            {/* Four stages, always one row — small enough to sit across a phone */}
            <div className="card-body grid grid-cols-4 gap-2">
                {stages.map(({ key, label, color, bg, href }) => (
                    <Link key={key} to={href}
                        className={clsx("rounded-xl p-2 sm:p-3 text-center hover:opacity-80 transition-opacity", bg)}>
                        {loading ? (
                            <div className="skeleton h-6 w-8 mx-auto rounded mb-1" />
                        ) : (
                            <p className={clsx("text-lg sm:text-2xl font-bold", color)}>
                                {((stats as any)?.[key] ?? 0).toLocaleString()}
                            </p>
                        )}
                        <p className="text-2xs text-surface-500 mt-0.5 leading-tight">{label}</p>
                    </Link>
                ))}
            </div>
        </div>
    );
}

// ── Page ─────────────────────────────────────────────────────────────────────

// ── Role-aware greeting subtitle ──────────────────────────────────────────────

function roleSubtitle(roles: string[]): string {
    if (roles.includes("tailor"))               return "Check your assigned tasks and stay on top of your production work.";
    if (roles.includes("pos_clerk"))            return "Open a register and start serving customers.";
    if (roles.includes("procurement_officer"))  return "Manage purchase orders, suppliers, and incoming stock.";
    if (roles.includes("outlet_manager"))       return "Here's what's happening at your outlet today.";
    return "Here's what's happening across your business today.";
}

// ── Role-aware stat grid ───────────────────────────────────────────────────────

function RoleStatGrid({
    stats, isLoading, roles, can, isAdmin, fmtCurrency, kpis, kpiLoading,
}: {
    stats?: DashboardStats; isLoading: boolean; roles: string[];
    can: (p: string) => boolean; isAdmin: boolean; fmtCurrency: (n?: number) => string;
    kpis: any; kpiLoading: boolean;
}) {
    // ── Tailor view — the full worker home, not just a stat grid ─────────────
    if (roles.includes("tailor")) {
        return <TailorHome stats={stats} isLoading={isLoading} can={can} />;
    }

    // ── POS Clerk view ───────────────────────────────────────────────────────
    if (roles.includes("pos_clerk")) {
        return (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <CashTodayCard />
                <StatCard label="Today's Sales"   value={fmtCurrency(stats?.today_sales)}
                    loading={isLoading} color="bg-success-light text-success"
                    href="/sales/orders" icon={<CoinIcon />} />
                <StatCard label="Today's Orders"  value={stats?.today_orders}
                    loading={isLoading} color="bg-brand-50 text-brand-600"
                    href="/sales/orders" icon={<ClipboardIcon />} />
                <StatCard label="Notifications"   value={stats?.unread_notifications}
                    loading={isLoading} color="bg-warning-light text-warning-dark"
                    href="/notifications" badge={stats?.unread_notifications} icon={<PaymentIcon />} />
            </div>
        );
    }

    // ── Procurement Officer view ─────────────────────────────────────────────
    if (roles.includes("procurement_officer")) {
        return (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <StatCard label="Low Stock Alerts"  value={stats?.low_stock_products}
                    loading={isLoading} color="bg-danger-light text-danger"
                    href="/inventory/low-stock" icon={<AlertIcon />} />
                <StatCard label="Active Products"   value={stats?.total_products}
                    loading={isLoading} color="bg-surface-100 text-surface-600"
                    href="/catalogue/products" icon={<BoxIcon />} />
                <StatCard label="Pending Orders"    value={stats?.pending_orders}
                    loading={isLoading} color="bg-warning-light text-warning-dark"
                    href="/sales/orders?status=pending" icon={<ClockIcon />} />
                <StatCard label="Notifications"     value={stats?.unread_notifications}
                    loading={isLoading} color="bg-info-light text-info"
                    href="/notifications" badge={stats?.unread_notifications} icon={<PaymentIcon />} />
            </div>
        );
    }

    // ── Outlet Manager view ──────────────────────────────────────────────────
    if (roles.includes("outlet_manager")) {
        return (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <CashTodayCard />
                <StatCard label="Today's Sales"   value={fmtCurrency(stats?.today_sales)}
                    loading={isLoading} color="bg-success-light text-success"
                    href="/sales/orders" icon={<CoinIcon />} />
                <StatCard label="Today's Orders"  value={stats?.today_orders}
                    loading={isLoading} color="bg-brand-50 text-brand-600"
                    href="/sales/orders" icon={<ClipboardIcon />} />
                <StatCard label="Pending Orders"  value={stats?.pending_orders}
                    loading={isLoading} color="bg-warning-light text-warning-dark"
                    href="/sales/orders?status=pending" icon={<ClockIcon />} />
                <StatCard label="Low Stock Alerts" value={stats?.low_stock_products}
                    loading={isLoading} color="bg-danger-light text-danger"
                    href="/inventory/low-stock" icon={<AlertIcon />} />
                <StatCard label="Shipments In Transit" value={stats?.shipments_in_transit}
                    loading={isLoading} color="bg-purple-50 text-purple-600"
                    href="/sales/shipments" icon={<TruckIcon />} />
                <StatCard label="Pending Approvals"   value={stats?.pending_payment_approvals}
                    loading={isLoading} color="bg-warning-light text-warning-dark"
                    href="/approvals" badge={stats?.pending_payment_approvals} icon={<PaymentIcon />} />
            </div>
        );
    }

    // ── Admin / Super Admin ──────────────────────────────────────────────────
    // Two dense rows of small tiles instead of nine billboard cards. Row 1 is
    // the day's trading numbers (cash leads); row 2 the operational watch-list.
    // Small on purpose — the whole picture fits a phone above the fold.
    return (
        <div className="space-y-3">
            <div className="grid grid-cols-3 lg:grid-cols-6 gap-2">
                <CashTodayCard compact />
                <KpiTile label="Today's Sales"   value={fmtCompact(stats?.today_sales)}
                    tone="text-success" loading={isLoading} href="/sales/orders" />
                <KpiTile label="Today's Orders"  value={stats?.today_orders}
                    loading={isLoading} href="/sales/orders" />
                <KpiTile label="Pending Orders"  value={stats?.pending_orders}
                    tone="text-warning-dark" loading={isLoading} href="/sales/orders?status=pending" />
                <KpiTile label="Customers"       value={stats?.customers}
                    tone="text-info" loading={isLoading} href="/sales/customers" />
                <KpiTile label="Active Products" value={stats?.total_products}
                    loading={isLoading} href="/catalogue/products" />
            </div>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-2">
                <KpiTile label="Low Stock Alerts"     value={stats?.low_stock_products}
                    tone="text-danger" loading={isLoading} href="/inventory/low-stock" />
                <KpiTile label="Shipments In Transit" value={stats?.shipments_in_transit}
                    tone="text-purple-600" loading={isLoading} href="/sales/shipments" />
                <KpiTile label="Pending Approvals"    value={stats?.pending_payment_approvals}
                    tone="text-warning-dark" loading={isLoading} href="/approvals"
                    badge={stats?.pending_payment_approvals} />
                <KpiTile label="Open Purchase Orders" value={kpis.procurement?.open_pos}
                    loading={kpiLoading} href="/procurement/purchase-orders" />
            </div>
        </div>
    );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function DashboardPage() {
    const { user } = useAuthStore();
    const { can, isAdmin } = usePermissions();

    // Derive roles from the auth store user
    const roles: string[] = user?.roles?.map((r: { name: string }) => r.name) ?? [];

    const [period, setPeriod] = useState<typeof PERIODS[number]>(PERIODS[1]); // 7 days default

    const { data, isLoading } = useQuery({
        queryKey: ["dashboard"],
        queryFn:  () => get<{ stats: DashboardStats; recent_activity: ActivityItem[]; alerts: DashboardAlert[] }>(
            "/v1/admin/dashboard"
        ),
        refetchInterval: 2 * 60_000,
        retry: false,
    });

    // Roles that should not see business-wide financials
    const hideFinancials = roles.some(r => ["tailor", "procurement_officer"].includes(r));

    // Rich KPIs + revenue trend from the reporting engine
    // Skipped for roles that won't see the revenue row.
    const { data: kpiData, isLoading: kpiLoading } = useQuery({
        queryKey: ["dashboard-kpis", period.days],
        queryFn:  () => reportsApi.dashboardKpis(period.days),
        staleTime: 3 * 60_000,
        enabled:  !hideFinancials,
    });

    // Sales summary (channel split) for the same period
    const _today    = new Date();
    const startDate = new Date(_today); startDate.setDate(_today.getDate() - period.days + 1);
    const _fmt      = (d: Date) => d.toISOString().slice(0, 10);

    const { data: salesData, isLoading: salesLoading } = useQuery({
        queryKey: ["dashboard-sales-summary", period.days],
        queryFn:  () => reportsApi.salesSummary({
            start_date: _fmt(startDate),
            end_date:   _fmt(_today),
        }),
        staleTime: 3 * 60_000,
        enabled:  !hideFinancials,
    });

    const stats    = data?.stats;
    const activity = data?.recent_activity ?? [];
    const alerts   = data?.alerts          ?? [];
    const kpis     = (kpiData as any)?.kpis          ?? {};
    // Use salesSummary as the single source of truth for the revenue row.
    // dashboardKpis uses now()->subDays(N) which preserves wall-clock time
    // and can miss same-day orders; salesSummary uses midnight boundaries.
    const summary  = (salesData as any)?.summary     ?? {};
    const trend    = ((salesData as any)?.daily_breakdown ?? []) as Array<{ date: string; revenue: number; orders: number }>;

    const hour     = new Date().getHours();
    const greeting = hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";

    const fmtCurrency = (n?: number) =>
        n !== undefined ? `KES ${n.toLocaleString("en-KE", { minimumFractionDigits: 0 })}` : "—";

    const quickActions = useQuickActions(stats, roles, can);

    return (
        <div className="animate-fade-in space-y-6">
            {/* Header + period toggle */}
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="page-title">{greeting}, {user?.first_name ?? "there"}</h1>
                    <p className="page-subtitle">{roleSubtitle(roles)}</p>
                </div>
    {!hideFinancials && (
                    <div className="flex items-center gap-1 bg-surface-100 rounded-xl p-1">
                        {PERIODS.map(pr => (
                            <button key={pr.days}
                                onClick={() => setPeriod(pr)}
                                className={clsx(
                                    "px-3 py-1.5 rounded-lg text-xs font-medium transition-all",
                                    period.days === pr.days
                                        ? "bg-white text-surface-900 shadow-sm"
                                        : "text-surface-500 hover:text-surface-700",
                                )}>
                                {pr.label}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {/* Alert bar */}
            <AlertBar alerts={alerts} />

            {/* Revenue row: sparkline + channel split + cash today
                 Hidden for roles that have no business seeing financials:
                 tailors work on production tasks, procurement officers manage
                 purchasing, POS clerks see only their own till summary. */}
            {!hideFinancials && (
                <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                    <RevenueCard
                        revenue={Number(summary.total_revenue ?? 0) || undefined}
                        orders={Number(summary.total_orders   ?? 0) || undefined}
                        avgValue={summary.avg_order_value != null ? Number(summary.avg_order_value) : undefined}
                        trend={trend}
                        loading={salesLoading}
                        periodLabel={period.label.toLowerCase()}
                    />
                    {/* Channel split: bar (compare) + pie (share), each its own card */}
                    <ChannelBarCard
                        onlineRevenue={summary.online_revenue}
                        posRevenue={summary.pos_revenue}
                        loading={salesLoading}
                    />
                    <ChannelPieCard
                        onlineRevenue={summary.online_revenue}
                        posRevenue={summary.pos_revenue}
                        onlineCount={summary.online_count}
                        posCount={summary.pos_count}
                        loading={salesLoading}
                    />
                </div>
            )}

            {/* Role-aware stat grid */}
            <RoleStatGrid
                stats={stats} isLoading={isLoading}
                roles={roles} can={can} isAdmin={isAdmin}
                fmtCurrency={fmtCurrency}
                kpis={kpis} kpiLoading={kpiLoading}
            />

            {/* Production queue — shown for admin and users with production access (not the tailor-only view which has its own tasks UI) */}
            {(can('production.view') || isAdmin) && !roles.includes("tailor") && (
                <ProductionSummaryCard stats={stats} loading={isLoading} />
            )}

            {/* Quick actions first — the operational launchpad now sits where the
                activity feed used to, full-width as a tappable grid. Tailors have
                their own icon grid in the hero, so they skip this one. */}
            {!roles.includes("tailor") && <QuickActionsPanel actions={quickActions} />}

            {/* Recent activity LAST — informational, not operational. Full width. */}
            <div className="card overflow-hidden">
                <div className="card-header">
                    <h2 className="font-semibold text-sm text-surface-900">Recent Activity</h2>
                </div>
                <div className="divide-y divide-surface-50">
                    {isLoading ? (
                        Array.from({ length: 5 }).map((_, i) => (
                            <div key={i} className="px-5 py-3.5 flex items-center gap-3">
                                <div className="skeleton w-8 h-8 rounded-full shrink-0" />
                                <div className="flex-1 space-y-1.5">
                                    <div className="skeleton h-3.5 w-48 rounded" />
                                    <div className="skeleton h-3 w-32 rounded" />
                                </div>
                                <div className="skeleton h-3 w-16 rounded" />
                            </div>
                        ))
                    ) : activity.length === 0 ? (
                        <div className="px-5 py-10 text-center text-sm text-surface-400">
                            No recent activity found.
                        </div>
                    ) : (
                        activity.map((item, i) => (
                            <div key={i} className="px-5 py-3.5 flex items-center gap-3 hover:bg-surface-50/50 transition-colors">
                                <div className="w-8 h-8 rounded-full bg-surface-100 flex items-center justify-center shrink-0 text-surface-500 text-xs font-semibold">
                                    {item.user?.[0]?.toUpperCase() ?? "?"}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm text-surface-800 truncate">{item.description}</p>
                                    <p className="text-xs text-surface-400 mt-0.5">{item.user}</p>
                                </div>
                                <span className="text-xs text-surface-400 shrink-0 whitespace-nowrap">{item.time}</span>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}

// ── Quick link icons ──────────────────────────────────────────────────────────

const QuickLinkIcon = ({ name }: { name: string }) => {
    const cls = "w-4 h-4";
    const s = { fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    if (name === "cart")          return <svg className={cls} {...s}><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>;
    if (name === "user")          return <svg className={cls} {...s}><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>;
    if (name === "production")    return <svg className={cls} {...s}><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>;
    if (name === "check")         return <svg className={cls} {...s}><polyline points="20 6 9 17 4 12"/></svg>;
    if (name === "box")           return <svg className={cls} {...s}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>;
    if (name === "clipboard")     return <svg className={cls} {...s}><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>;
    if (name === "tasks")         return <svg className={cls} {...s}><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>;
    if (name === "bell")          return <svg className={cls} {...s}><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>;
    if (name === "message")       return <svg className={cls} {...s}><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>;
    if (name === "scissors")      return <svg className={cls} {...s}><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>;
    if (name === "profile")       return <svg className={cls} {...s}><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>;
    if (name === "pos")           return <svg className={cls} {...s}><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M7 7h.01M11 7h.01M15 7h.01M7 11h.01M11 11h.01M15 11h.01"/></svg>;
    if (name === "transfer")      return <svg className={cls} {...s}><path d="M5 12h14M12 5l7 7-7 7"/></svg>;
    if (name === "purchase")      return <svg className={cls} {...s}><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>;
    if (name === "shipment")      return <svg className={cls} {...s}><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>;
    if (name === "report")        return <svg className={cls} {...s}><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>;
    if (name === "material")      return <svg className={cls} {...s}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>;
    if (name === "grn")           return <svg className={cls} {...s}><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12l2 2 4-4"/></svg>;
    if (name === "customer")      return <svg className={cls} {...s}><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>;
    if (name === "expense")       return <svg className={cls} {...s}><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>;
    return null;
};

// ── Role-aware quick actions ───────────────────────────────────────────────────

interface QuickAction {
    label: string;
    href: string;
    icon: string;
    badge?: number;
    highlight?: boolean; // true = accent colour treatment
}

/**
 * Returns the ordered list of quick actions for the current user based on
 * their role and permissions. The first role match wins, so be specific-first.
 */
function useQuickActions(
    stats: DashboardStats | undefined,
    roles: string[],
    can: (p: string) => boolean,
): QuickAction[] {
    // ── Tailor ───────────────────────────────────────────────────────────────
    if (roles.includes("tailor")) {
        return [
            { label: "My Tasks",          href: "/production/my-tasks", icon: "tasks",   highlight: true },
            { label: "Messages",          href: "/comms",               icon: "message"  },
            { label: "Notifications",     href: "/notifications",       icon: "bell",    badge: stats?.unread_notifications },
            { label: "Production Orders", href: "/production",          icon: "scissors" },
            { label: "My Profile",        href: "/profile",             icon: "profile"  },
        ];
    }

    // ── POS Clerk ────────────────────────────────────────────────────────────
    if (roles.includes("pos_clerk")) {
        return [
            { label: "Open POS",         href: "/pos",              icon: "pos",      highlight: true },
            { label: "New Order",        href: "/sales/orders",     icon: "cart"      },
            { label: "New Customer",     href: "/sales/customers",  icon: "customer"  },
            { label: "Notifications",    href: "/notifications",    icon: "bell",     badge: stats?.unread_notifications },
            { label: "Messages",         href: "/comms",            icon: "message"   },
            { label: "My Profile",       href: "/profile",          icon: "profile"   },
        ];
    }

    // ── Procurement Officer ──────────────────────────────────────────────────
    if (roles.includes("procurement_officer")) {
        return [
            { label: "Purchase Orders",  href: "/procurement/purchase-orders", icon: "purchase",  highlight: true },
            { label: "Receive Goods",    href: "/procurement/grn",             icon: "grn"        },
            { label: "Raw Materials",    href: "/inventory/materials",         icon: "material"   },
            { label: "Suppliers",        href: "/procurement/suppliers",       icon: "customer"   },
            { label: "Notifications",    href: "/notifications",               icon: "bell",      badge: stats?.unread_notifications },
            { label: "Reports",          href: "/reports",                     icon: "report"     },
        ];
    }

    // ── Outlet Manager ───────────────────────────────────────────────────────
    if (roles.includes("outlet_manager")) {
        return [
            { label: "Open POS",         href: "/pos",                     icon: "pos",      highlight: true },
            { label: "New Order",        href: "/sales/orders",            icon: "cart"      },
            { label: "Stock Levels",     href: "/inventory/stock-levels",  icon: "box"       },
            { label: "Stock Transfers",  href: "/inventory/transfers",     icon: "transfer"  },
            { label: "View Approvals",   href: "/approvals",               icon: "check"     },
            { label: "Notifications",    href: "/notifications",           icon: "bell",     badge: stats?.unread_notifications },
        ];
    }

    // ── Admin / Super Admin (default) ────────────────────────────────────────
    return [
        { label: "New Order",            href: "/sales/orders",                icon: "cart",      highlight: true },
        { label: "New Customer",         href: "/sales/customers",             icon: "customer"   },
        { label: "New Production Order", href: "/production",                  icon: "production" },
        { label: "View Approvals",       href: "/approvals",                   icon: "check",     badge: stats?.pending_payment_approvals },
        { label: "Stock Levels",         href: "/inventory/stock-levels",      icon: "box"        },
        { label: "Purchase Orders",      href: "/procurement/purchase-orders", icon: "clipboard"  },
        { label: "Shipments",            href: "/sales/shipments",             icon: "shipment"   },
        { label: "Reports",              href: "/reports",                     icon: "report"     },
    ];
}

// ── Quick-actions panel ───────────────────────────────────────────────────────

function QuickActionsPanel({ actions }: { actions: QuickAction[] }) {
    return (
        <div className="card overflow-hidden">
            <div className="card-header flex items-center justify-between">
                <h2 className="font-semibold text-sm text-surface-900">Quick Actions</h2>
                <Link to="/reports" className="text-xs text-brand-500 hover:underline">Full reports →</Link>
            </div>
            {/* Full-width tappable grid — big touch targets across a phone, more
                columns as the screen grows. */}
            <div className="card-body grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                {actions.map(({ label, href, icon, badge, highlight }) => (
                    <Link key={href} to={href}
                        className={clsx(
                            "relative flex items-center gap-2.5 px-3 py-3 rounded-xl transition-colors text-sm font-medium group border",
                            highlight
                                ? "bg-brand-50 text-brand-700 border-brand-100 hover:bg-brand-100"
                                : "text-surface-700 border-surface-100 hover:bg-surface-50 hover:border-surface-200",
                        )}>
                        <span className={clsx(
                            "w-6 flex items-center justify-center shrink-0 transition-colors",
                            highlight ? "text-brand-500" : "text-surface-400 group-hover:text-brand-500",
                        )}>
                            <QuickLinkIcon name={icon} />
                        </span>
                        <span className="flex-1 truncate">{label}</span>
                        {badge && badge > 0 ? (
                            <span className="min-w-[20px] h-5 px-1.5 bg-danger text-white text-2xs font-bold rounded-full flex items-center justify-center shrink-0">
                                {badge > 99 ? "99+" : badge}
                            </span>
                        ) : null}
                    </Link>
                ))}
            </div>
        </div>
    );
}

// ── Icons ─────────────────────────────────────────────────────────────────────

const p = { className: "w-5 h-5", fill: "none", viewBox: "0 0 24 24",
    stroke: "currentColor", strokeWidth: 1.75,
    strokeLinecap: "round" as const, strokeLinejoin: "round" as const };

const UserGroupIcon = () => <svg {...p}><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>;
const ClipboardIcon = () => <svg {...p}><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>;
const ClockIcon    = () => <svg {...p}><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>;
const CoinIcon     = () => <svg {...p}><circle cx="12" cy="12" r="10"/><path d="M12 8v8M9.5 10.5h4a1.5 1.5 0 010 3h-3a1.5 1.5 0 000 3h4"/></svg>;
const AlertIcon    = () => <svg {...p}><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>;
const BoxIcon      = () => <svg {...p}><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>;
const TruckIcon    = () => <svg {...p}><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>;
const PaymentIcon  = () => <svg {...p}><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>;