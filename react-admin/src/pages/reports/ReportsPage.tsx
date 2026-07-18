// src/pages/reports/ReportsPage.tsx
//
// Main Reports landing page.
// Shows a high-level KPI overview, scheduling summary, and navigation tiles.

import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { reportsApi } from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { clsx } from "clsx";
import { useState } from "react";

// ─── KPI overview ──────────────────────────────────────────────────────────────

// The Executive Dashboard — MetricEngine-backed command centre. Every card is
// {current, previous, series}: value, delta vs the equivalent prior period,
// sparkline of the current window, and a click-through to the report that
// explains it. The attention feed answers "what needs me today?"

const PERIODS = [
    { key: "today",        label: "Today" },
    { key: "last_7",       label: "7 Days" },
    { key: "last_30",      label: "30 Days" },
    { key: "this_month",   label: "This Month" },
    { key: "last_month",   label: "Last Month" },
    { key: "this_quarter", label: "Quarter" },
    { key: "this_year",    label: "Year" },
];

function Sparkline({ series }: { series?: Record<string, number> }) {
    const values = Object.values(series ?? {}).map(Number);
    if (values.length < 2) return null;
    const max = Math.max(...values), min = Math.min(...values);
    const range = max - min || 1;
    const pts = values.map((v, i) =>
        `${(i / (values.length - 1)) * 76 + 2},${20 - ((v - min) / range) * 16 + 2}`).join(" ");
    return (
        <svg viewBox="0 0 80 24" className="w-20 h-6 text-brand-400" aria-hidden="true">
            <polyline points={pts} fill="none" stroke="currentColor" strokeWidth="1.5"
                strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

function DeltaChip({ current, previous, downIsGood = false }: {
    current: number; previous: number; downIsGood?: boolean;
}) {
    if (!previous) return <span className="text-2xs text-surface-300">— prev n/a</span>;
    const pct = ((current - previous) / Math.abs(previous)) * 100;
    if (Math.abs(pct) < 0.05) return <span className="text-2xs text-surface-400">± 0%</span>;
    const up = pct > 0;
    const good = downIsGood ? !up : up;
    return (
        <span className={clsx("text-2xs font-bold tabular-nums", good ? "text-emerald-600" : "text-red-600")}>
            {up ? "▲" : "▼"} {Math.abs(pct).toFixed(1)}%
        </span>
    );
}

function MetricCard({ label, value, sub, metric, to, money = false, downIsGood = false, onOpen }: {
    label: string; value?: string; sub?: string;
    metric?: { current: number; previous: number; series?: Record<string, number> };
    to?: string; money?: boolean; downIsGood?: boolean; onOpen?: () => void;
}) {
    const navigate = useNavigate();
    const display = value ?? (money
        ? `KES ${Number(metric?.current ?? 0).toLocaleString()}`
        : Number(metric?.current ?? 0).toLocaleString());
    return (
        <button onClick={() => (onOpen ? onOpen() : to && navigate(to))} disabled={!to && !onOpen}
            className={clsx("card card-body text-left transition-shadow", (to || onOpen) && "hover:shadow-md cursor-pointer")}>
            <div className="flex items-start justify-between gap-2">
                <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">{label}</p>
                {metric && <DeltaChip current={metric.current} previous={metric.previous} downIsGood={downIsGood} />}
            </div>
            <p className="text-lg sm:text-xl font-bold text-surface-900 tabular-nums mt-1 truncate">{display}</p>
            <div className="flex items-end justify-between gap-2 mt-1 min-h-[24px]">
                <p className="text-2xs text-surface-400 truncate">
                    {sub ?? (metric?.previous
                        ? `prev ${money ? "KES " : ""}${Number(metric.previous).toLocaleString()}`
                        : "")}
                </p>
                <Sparkline series={metric?.series} />
            </div>
        </button>
    );
}

function AttentionPanel({ items }: { items: any[] }) {
    const navigate = useNavigate();
    if (!items.length) return (
        <div className="card card-body flex items-center gap-3 border-emerald-100 bg-emerald-50/40">
            <span className="text-lg" aria-hidden="true">✅</span>
            <p className="text-sm text-emerald-800 font-medium">Nothing needs your attention right now.</p>
        </div>
    );
    return (
        <div className="card overflow-hidden">
            <div className="px-4 py-2.5 bg-amber-50 border-b border-amber-100 flex items-center gap-2">
                <span aria-hidden="true">⚠️</span>
                <p className="text-xs font-bold text-amber-800 uppercase tracking-widest">Needs your attention</p>
                <span className="ml-auto text-2xs font-bold text-amber-700">{items.length}</span>
            </div>
            <div className="divide-y divide-surface-50">
                {items.map(it => (
                    <button key={it.key} onClick={() => navigate(it.link)}
                        className="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-surface-50 transition-colors">
                        <span className={clsx("w-2 h-2 rounded-full shrink-0",
                            it.severity === "high" ? "bg-red-500" : "bg-amber-400")} />
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-surface-800">{it.title}</p>
                            <p className="text-2xs text-surface-400 truncate">{it.detail}</p>
                        </div>
                        <svg className="w-3.5 h-3.5 text-surface-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                ))}
            </div>
        </div>
    );
}

// ─── Drill-down modal: the rows behind the number ─────────────────────────────
// Spec rule 3: a figure with no drill-down is a rumour. The modal lists the
// exact source rows the KPI summed, paginated; tapping a row opens the record.

const KIND_PATH: Record<string, (r: any) => string | null> = {
    order:      r => `/sales/orders/${r.id}`,
    payment:    r => (r.order_id ? `/sales/orders/${r.order_id}` : null),
    production: r => `/production/orders/${r.id}`,
    customer:   () => "/customers",
    expense:    () => "/expenses",
};

function DrillModal({ metric, label, money, bucket, period, reportPath, onClose }: {
    metric: string; label: string; money?: boolean; bucket?: string; period: string;
    reportPath?: string; onClose: () => void;
}) {
    const navigate = useNavigate();
    const [page, setPage] = useState(1);
    const { data, isLoading } = useQuery({
        queryKey: ["drill", metric, bucket, period, page],
        queryFn: () => reportsApi.drill(metric, period, { page, bucket }),
        staleTime: 60_000,
    });
    const rows = data?.rows ?? [];
    const pages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-6"
            onClick={onClose}>
            <div className="bg-white w-full sm:max-w-2xl sm:rounded-2xl rounded-t-2xl shadow-xl max-h-[85vh] flex flex-col"
                onClick={e => e.stopPropagation()}>
                <div className="px-4 py-3 border-b border-surface-100 flex items-center gap-3">
                    <div className="min-w-0">
                        <p className="text-sm font-bold text-surface-900">{label}</p>
                        <p className="text-2xs text-surface-400">
                            {data ? `${data.total.toLocaleString()} source record${data.total === 1 ? "" : "s"}` : "Loading…"}
                            {" · every row is part of the number you tapped"}
                        </p>
                    </div>
                    <button onClick={onClose} aria-label="Close"
                        className="ml-auto w-7 h-7 rounded-lg flex items-center justify-center text-surface-400 hover:bg-surface-100">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div className="flex-1 overflow-y-auto">
                    {isLoading ? (
                        <div className="flex justify-center py-12"><Spinner /></div>
                    ) : rows.length === 0 ? (
                        <p className="text-center text-xs text-surface-400 py-12">No records in this period.</p>
                    ) : (
                        <div className="divide-y divide-surface-50">
                            {rows.map((r: any) => {
                                const path = KIND_PATH[r.kind]?.(r) ?? null;
                                return (
                                    <button key={`${r.kind}-${r.id}`} disabled={!path}
                                        onClick={() => path && navigate(path)}
                                        className="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-surface-50 transition-colors disabled:cursor-default">
                                        <div className="flex-1 min-w-0">
                                            <p className="text-xs font-semibold text-surface-800 font-mono truncate">{r.ref}</p>
                                            <p className="text-2xs text-surface-400 truncate">
                                                {new Date(r.at).toLocaleDateString("en-KE", { day: "2-digit", month: "short" })}
                                                {r.who ? ` · ${r.who}` : ""}{r.detail ? ` · ${r.detail}` : ""}
                                            </p>
                                        </div>
                                        {r.amount != null && (
                                            <span className="text-xs font-bold tabular-nums text-surface-800 shrink-0">
                                                {money ? `KES ${Number(r.amount).toLocaleString()}` : Number(r.amount).toLocaleString()}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>
                <div className="px-4 py-2.5 border-t border-surface-100 flex items-center gap-2">
                    {pages > 1 && (
                        <>
                            <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}
                                className="btn-secondary text-2xs px-2.5 py-1 disabled:opacity-40">← Prev</button>
                            <span className="text-2xs text-surface-400 tabular-nums">{page} / {pages}</span>
                            <button disabled={page >= pages} onClick={() => setPage(p => p + 1)}
                                className="btn-secondary text-2xs px-2.5 py-1 disabled:opacity-40">Next →</button>
                        </>
                    )}
                    {reportPath && (
                        <button onClick={() => navigate(reportPath)}
                            className="ml-auto text-2xs font-semibold text-brand-600 hover:text-brand-700">
                            Open full report →
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

function AgingCard({ aging, onBucket }: { aging: any; onBucket: (bucket: string, label: string) => void }) {
    const buckets = aging?.buckets ?? [];
    const max = Math.max(...buckets.map((b: any) => Number(b.amount)), 1);
    return (
        <div className="card card-body">
            <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">Balance Aging</p>
            <div className="space-y-1.5 mt-2">
                {buckets.map((b: any) => (
                    <button key={b.key} onClick={() => Number(b.amount) > 0 && onBucket(b.key, `Owed ${b.label}`)}
                        className="w-full flex items-center gap-2 group" disabled={Number(b.amount) === 0}>
                        <span className="text-2xs text-surface-400 w-11 text-left shrink-0">{b.label}</span>
                        <div className="flex-1 h-2 bg-surface-100 rounded-full overflow-hidden">
                            <div className={clsx("h-full rounded-full",
                                b.key === "90_plus" ? "bg-red-500" : b.key === "61_90" ? "bg-amber-500" : "bg-brand-400")}
                                style={{ width: `${(Number(b.amount) / max) * 100}%` }} />
                        </div>
                        <span className={clsx("text-2xs font-bold tabular-nums w-16 text-right shrink-0",
                            Number(b.amount) > 0 ? "text-surface-700 group-hover:text-brand-600" : "text-surface-300")}>
                            {Number(b.amount) >= 1000 ? `${Math.round(Number(b.amount) / 1000)}k` : Number(b.amount)}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}

function ExecutiveOverview() {
    const [period, setPeriod] = useState("this_month");
    const [drill, setDrill] = useState<{ metric: string; label: string; money?: boolean; bucket?: string; reportPath?: string } | null>(null);
    const { data, isLoading } = useQuery({
        queryKey: ["executive-dashboard", period],
        queryFn: () => reportsApi.executive(period),
        staleTime: 60_000,
    });

    const k = data?.kpis;

    return (
        <div className="space-y-4">
            {/* Period selector */}
            <div className="flex gap-1.5 overflow-x-auto no-scrollbar -mx-1 px-1">
                {PERIODS.map(pd => (
                    <button key={pd.key} onClick={() => setPeriod(pd.key)}
                        className={clsx("shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold transition-colors",
                            period === pd.key
                                ? "bg-surface-900 text-white"
                                : "bg-white border border-surface-200 text-surface-500 hover:border-brand-300 hover:text-brand-600")}>
                        {pd.label}
                    </button>
                ))}
            </div>

            {isLoading || !k ? (
                <div className="flex justify-center py-12"><Spinner /></div>
            ) : (
                <>
                    <AttentionPanel items={data.attention ?? []} />

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <MetricCard label="Revenue" metric={k.sales.revenue} money
                            onOpen={() => setDrill({ metric: "revenue", label: "Revenue — source orders", money: true, reportPath: "/reports/sales" })} />
                        <MetricCard label="Collected" metric={k.money.collected} money
                            onOpen={() => setDrill({ metric: "collected", label: "Collected — settled payments", money: true, reportPath: can_financial_path(k) })} />
                        <MetricCard label="Orders" metric={k.sales.orders}
                            onOpen={() => setDrill({ metric: "orders", label: "Orders in period", reportPath: "/reports/sales" })} />
                        <MetricCard label="Avg Order Value" metric={k.sales.aov} money to="/reports/sales" />
                    </div>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <MetricCard label="Outstanding"
                            value={`KES ${Number(k.money.outstanding.amount).toLocaleString()}`}
                            sub={`${k.money.outstanding.orders} open orders`}
                            onOpen={() => setDrill({ metric: "outstanding", label: "Outstanding balances", money: true, reportPath: "/pos/balances" })} />
                        <MetricCard label="Production Done" metric={k.production.completed}
                            onOpen={() => setDrill({ metric: "production_completed", label: "Completed production orders", reportPath: "/reports/production" })} />
                        <MetricCard label="On-time %"
                            value={k.production.on_time_pct.current != null ? `${k.production.on_time_pct.current}%` : "—"}
                            sub={k.production.on_time_pct.previous != null ? `prev ${k.production.on_time_pct.previous}%` : "no prior data"}
                            to="/reports/production" />
                        <MetricCard label="WIP / Overdue"
                            value={`${k.production.wip}${k.production.overdue > 0 ? ` · ${k.production.overdue} late` : ""}`}
                            sub={k.production.overdue > 0 ? "overdue orders on the floor" : "nothing overdue"}
                            onOpen={k.production.overdue > 0
                                ? () => setDrill({ metric: "production_overdue", label: "Overdue production orders", reportPath: "/production/wip" })
                                : undefined}
                            to="/production/wip" />
                    </div>

                    {/* Money aging + deposits: how old the debt is, and whose money we hold */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <AgingCard aging={k.money.aging}
                            onBucket={(bucket, label) => setDrill({ metric: "outstanding", bucket, label, money: true, reportPath: "/pos/balances" })} />
                        <MetricCard label="Deposits Held"
                            value={`KES ${Number(k.money.aging?.deposits_held?.amount ?? 0).toLocaleString()}`}
                            sub={`${k.money.aging?.deposits_held?.orders ?? 0} undelivered orders — customer money, not income`}
                            onOpen={() => setDrill({ metric: "outstanding", bucket: "deposits", label: "Deposits held (undelivered)", money: true })} />
                    </div>

                    {k.financial && (
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                            <MetricCard label="Expenses" metric={k.financial.expenses} money downIsGood
                                onOpen={() => setDrill({ metric: "expenses", label: "Expenses in period", money: true, reportPath: "/expenses" })} />
                            <MetricCard label="Net (Collected − Exp.)" metric={k.financial.net_collected} money to="/reports/financial" />
                            <MetricCard label="New Customers" metric={k.sales.new_customers}
                                onOpen={() => setDrill({ metric: "new_customers", label: "New customers", reportPath: "/reports/customers" })} />
                            <MetricCard label="Low Stock"
                                value={String(k.inventory.low_stock)}
                                sub={k.inventory.low_stock > 0 ? "items at reorder point" : "all healthy"}
                                to="/reports/inventory" />
                        </div>
                    )}
                    {!k.financial && (
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                            <MetricCard label="New Customers" metric={k.sales.new_customers}
                                onOpen={() => setDrill({ metric: "new_customers", label: "New customers", reportPath: "/reports/customers" })} />
                            <MetricCard label="Low Stock"
                                value={String(k.inventory.low_stock)}
                                sub={k.inventory.low_stock > 0 ? "items at reorder point" : "all healthy"}
                                to="/reports/inventory" />
                        </div>
                    )}
                </>
            )}
            {drill && <DrillModal {...drill} period={period} onClose={() => setDrill(null)} />}
        </div>
    );
}

// Collected drills to financial for those who may enter; sales otherwise.
function can_financial_path(k: any): string {
    return k?.financial ? "/reports/financial" : "/reports/sales";
}

// ─── Scheduled reports summary ─────────────────────────────────────────────────

function SchedulesSummary() {
    const { data } = useQuery({
        queryKey: ["report-schedules"],
        queryFn: () => reportsApi.listSchedules(),
        staleTime: 60 * 1000,
    });

    const schedules = (data?.schedules ?? []) as any[];
    if (schedules.length === 0) return null;

    const active = schedules.filter((s) => s.is_active);
    const byReport = Object.entries(
        schedules.reduce((acc: Record<string, number>, s: any) => {
            acc[s.report_type] = (acc[s.report_type] ?? 0) + 1;
            return acc;
        }, {}),
    );

    return (
        <div className="card card-body">
            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-2">
                    <svg
                        className="w-4 h-4 text-brand-500"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.75}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                        />
                    </svg>
                    <p className="text-sm font-semibold text-surface-900">
                        Scheduled Reports
                    </p>
                </div>
                <span className="text-xs text-surface-400">
                    {active.length} active
                </span>
            </div>
            <div className="flex flex-wrap gap-2">
                {byReport.map(([type, count]) => (
                    <span
                        key={type}
                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-brand-50 text-brand-700 text-xs font-medium capitalize"
                    >
                        {type} · {count as number}
                    </span>
                ))}
            </div>
            <p className="text-xs text-surface-400 mt-2">
                Go to any report section to manage its schedules.
            </p>
        </div>
    );
}

// ─── Category tiles ────────────────────────────────────────────────────────────

interface ReportCategory {
    id: string;
    label: string;
    description: string;
    icon: React.ReactNode;
    path: string;
    color: string;
}

const CATEGORIES: ReportCategory[] = [
    {
        id: "sales",
        label: "Sales",
        description: "Revenue, orders, products, channels, patterns & returns",
        path: "/reports/sales",
        color: "text-indigo-600 bg-indigo-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941"
                />
            </svg>
        ),
    },
    {
        id: "customers",
        label: "Customers",
        description: "Growth, segments, lifetime value, retention cohorts",
        path: "/reports/customers",
        color: "text-violet-600 bg-violet-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
                />
            </svg>
        ),
    },
    {
        id: "inventory",
        label: "Inventory",
        description:
            "Stock health, outlet distribution, critical alerts, movements",
        path: "/reports/inventory",
        color: "text-amber-600 bg-amber-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"
                />
            </svg>
        ),
    },
    {
        id: "production",
        label: "Production",
        description:
            "Completion, on-time rate, tailor performance, QC failures",
        path: "/reports/production",
        color: "text-pink-600 bg-pink-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z"
                />
            </svg>
        ),
    },
    {
        id: "procurement",
        label: "Procurement",
        description:
            "Purchase orders, supplier spend, top items, fulfilment status",
        path: "/reports/procurement",
        color: "text-emerald-600 bg-emerald-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"
                />
            </svg>
        ),
    },
    {
        id: "financial",
        label: "Financial",
        description: "P&L statement, revenue vs expenses, tax, discounts",
        path: "/reports/financial",
        color: "text-blue-600 bg-blue-50",
        icon: (
            <svg
                className="w-5 h-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                />
            </svg>
        ),
    },
];

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ReportsPage() {
    const navigate = useNavigate();
    const { can } = usePermissions();
    // Every /reports/* sub-page requires reports.view, already implied by
    // reaching this page - except /reports/financial, which requires the
    // more restricted reports.financial (see routes/api.php and
    // SyncPermissions.php: outlet_manager and procurement_officer/manager
    // deliberately get reports.view but not reports.financial). Filtering
    // the tile here so it doesn't link to a page that will 403.
    const visibleCategories = CATEGORIES.filter(
        (cat) => cat.id !== "financial" || can("reports.financial"),
    );

    return (
        <div className="space-y-8 animate-fade-in">
            <div>
                <h1 className="page-title">Reports & Analytics</h1>
                <p className="page-subtitle">
                    Business intelligence overview. Select a category to drill
                    down with custom date ranges, CSV export, and scheduling.
                </p>
            </div>

            <ExecutiveOverview />
            <SchedulesSummary />

            <div>
                <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                    Report Categories
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {visibleCategories.map((cat) => (
                        <button
                            key={cat.id}
                            onClick={() => navigate(cat.path)}
                            className="card card-body text-left hover:shadow-md transition-shadow group flex items-start gap-4"
                        >
                            <div
                                className={clsx(
                                    "w-10 h-10 rounded-xl flex items-center justify-center shrink-0",
                                    cat.color,
                                )}
                            >
                                {cat.icon}
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="font-semibold text-surface-900 group-hover:text-brand-600 transition-colors">
                                    {cat.label}
                                </p>
                                <p className="text-sm text-surface-500 mt-0.5 leading-snug">
                                    {cat.description}
                                </p>
                                <div className="flex items-center gap-3 mt-2 text-xs text-surface-400">
                                    <span className="flex items-center gap-0.5">
                                        <svg
                                            className="w-3 h-3"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            strokeWidth={2}
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
                                            />
                                        </svg>
                                        CSV export
                                    </span>
                                    <span className="flex items-center gap-0.5">
                                        <svg
                                            className="w-3 h-3"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            strokeWidth={2}
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                                            />
                                        </svg>
                                        Schedulable
                                    </span>
                                </div>
                            </div>
                            <svg
                                className="w-4 h-4 text-surface-300 group-hover:text-brand-500 transition-colors shrink-0 mt-0.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={2}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M8.25 4.5l7.5 7.5-7.5 7.5"
                                />
                            </svg>
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}