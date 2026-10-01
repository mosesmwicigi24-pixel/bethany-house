// src/pages/reports/ReportsPage.tsx
//
// Main Reports landing page.
// Shows a high-level KPI overview, scheduling summary, and navigation tiles.

import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { DrillPanel, ReportPageHeader, useDateRange, periodParams } from "./reportShared";
import { reportsApi, type EngineRoomSummaries } from "@/api/reports";
import { purchaseOrderApi } from "@/api/procurement";
import { fmtKes } from "@/api/expenses";
import { usePermissions } from "@/hooks/usePermissions";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { clsx } from "clsx";
import { useState } from "react";

// ─── KPI overview ──────────────────────────────────────────────────────────────

// The Executive Dashboard — MetricEngine-backed command centre. Every card is
// {current, previous, series}: value, delta vs the equivalent prior period,
// sparkline of the current window, and a click-through to the report that
// explains it. The attention feed answers "what needs me today?"

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

/**
 * The change against the previous period, in the form that is true for it. A
 * percentage only means something against a positive base: "▲724%" off a
 * negative net read as a boom, and "prev n/a" called a previous zero missing.
 */
function DeltaChip({ current, previous, downIsGood = false, money = false }: {
    current: number; previous: number; downIsGood?: boolean; money?: boolean;
}) {
    const diff = current - previous;
    if (Math.abs(diff) < 0.005) return <span className="text-2xs text-surface-400">± 0</span>;
    const up = diff > 0;
    const good = downIsGood ? !up : up;
    const tone = good ? "text-success-600" : "text-danger-600";
    if (previous === 0) {
        return <span className={clsx("text-2xs font-bold", tone)} title="Nothing in the previous period">{up ? "new" : "▼ from 0"}</span>;
    }
    if (previous < 0) {
        const abs = Math.abs(diff);
        return (
            <span className={clsx("text-2xs font-bold tabular-nums", tone)} title="Change in amount — a percentage of a negative figure means nothing">
                {up ? "▲" : "▼"} {money ? fmtKes(abs) : abs.toLocaleString()}
            </span>
        );
    }
    const pct = (diff / previous) * 100;
    return (
        <span className={clsx("text-2xs font-bold tabular-nums", tone)}>
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
        ? fmtKes(metric?.current ?? 0)
        : Number(metric?.current ?? 0).toLocaleString());
    return (
        <button onClick={() => (onOpen ? onOpen() : to && navigate(to))} disabled={!to && !onOpen}
            className={clsx("card card-body text-left transition-shadow", (to || onOpen) && "hover:shadow-md cursor-pointer")}>
            <div className="flex items-start justify-between gap-2">
                <p className="text-xs text-surface-500">{label}</p>
                {metric && <DeltaChip current={metric.current} previous={metric.previous} downIsGood={downIsGood} money={money} />}
            </div>
            <p className="text-xl font-bold text-surface-900 tabular-nums mt-1">{display}</p>
            <div className="flex items-end justify-between gap-2 mt-1 min-h-[24px]">
                <p className="text-2xs text-surface-400 line-clamp-3">
                    {sub ?? (metric?.previous
                        ? `prev ${money ? fmtKes(metric.previous) : Number(metric.previous).toLocaleString()}`
                        : "")}
                </p>
                {/* The figure leads; the cue that it opens its records sits with
                    the small print, as on every other report's cards. */}
                <span className="flex shrink-0 items-end gap-2">
                    {onOpen && <span className="text-2xs font-medium text-brand-600" aria-hidden="true">records ›</span>}
                    <Sparkline series={metric?.series} />
                </span>
            </div>
        </button>
    );
}

// Every insight key gets a face — the eye finds "the stock one" or "the
// money one" before reading a word.
const ATTN_ICON: Record<string, string> = {
    production_overdue: "⏰",
    balances_aging:     "💰",
    payment_approvals:  "🧾",
    capacity_shortfall: "🏭",
    stockout_risk:      "📦",
    low_stock:          "📦",
    dormant_customers:  "📞",
    material_runway:    "🧵",
    revenue_trend:      "📉",
    price_drift:        "🏷️",
};

// One card of the attention feed. The card body still navigates to the
// item's report link; the action row underneath offers the one-click next
// steps the backend attached ({type:'navigate'|'create_po'}). The card is a
// div (role=button) because HTML forbids nesting the action <button>s
// inside another <button>.
function AttentionCard({ it }: { it: any }) {
    const navigate = useNavigate();
    const toast = useToastStore();
    const { can } = usePermissions();
    const [creating, setCreating] = useState(false);
    const high = it.severity === "high";

    // create_po hits an endpoint gated by procurement.create — hide the
    // button from users who would only collect a 403.
    const actions: any[] = (it.actions ?? []).filter(
        (a: any) => a.type !== "create_po" || can("procurement.create"),
    );

    const runAction = async (a: any) => {
        if (a.type === "navigate" && a.to) {
            navigate(a.to);
            return;
        }
        if (a.type === "create_po" && a.material_ids?.length && !creating) {
            setCreating(true);
            try {
                const res = await purchaseOrderApi.createFromSuggestions(a.material_ids);
                const pos = res.purchase_orders ?? [];
                toast.success(
                    pos.length === 1
                        ? `Draft ${pos[0].po_number} created — review and submit it.`
                        : `${pos.length} draft POs created (one per supplier) — review and submit them.`,
                );
                if (pos[0]) navigate(`/procurement/purchase-orders/${pos[0].id}`);
            } catch (e: any) {
                toast.error(e?.message ?? "Could not create the draft purchase order.");
            } finally {
                setCreating(false);
            }
        }
    };

    return (
        <div role="button" tabIndex={0}
            onClick={() => navigate(it.link)}
            onKeyDown={e => { if (e.key === "Enter") navigate(it.link); }}
            className={clsx(
                "text-left rounded-xl border-l-4 border border-line p-3 flex flex-col gap-1 transition-shadow hover:shadow-md cursor-pointer",
                high ? "border-l-danger-500 bg-danger-50/50" : "border-l-amber-400 bg-amber-50/40",
            )}>
            <div className="flex items-center justify-between gap-2">
                <span className="text-base leading-none" aria-hidden="true">{ATTN_ICON[it.key] ?? "⚠️"}</span>
                <span className={clsx("text-2xs font-bold uppercase tracking-wide",
                    high ? "text-danger-600" : "text-amber-600")}>
                    {high ? "urgent" : "watch"}
                </span>
            </div>
            <p className="text-xs font-bold text-surface-900 leading-snug line-clamp-2">{it.title}</p>
            <p className="text-2xs text-surface-500 leading-snug line-clamp-2">{it.detail}</p>
            {actions.length > 0 && (
                <div className="flex flex-wrap gap-1 mt-auto pt-1">
                    {actions.map((a: any, i: number) => (
                        <button key={i}
                            disabled={creating && a.type === "create_po"}
                            onClick={e => { e.stopPropagation(); runAction(a); }}
                            className={clsx(
                                "text-2xs font-semibold rounded-md px-1.5 py-0.5 border transition-colors",
                                a.type === "create_po"
                                    ? "border-brand-300 bg-white text-brand-700 hover:bg-brand-50"
                                    : "border-line bg-white/70 text-surface-600 hover:text-brand-600 hover:border-brand-300",
                                creating && a.type === "create_po" && "opacity-50 cursor-wait",
                            )}>
                            {a.type === "create_po" && creating ? "Creating…" : a.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function AttentionPanel({ items }: { items: any[] }) {
    if (!items.length) return (
        <div className="card card-body flex items-center gap-3 border-success-100 bg-success-50/40 py-2.5">
            <span aria-hidden="true">✅</span>
            <p className="text-xs text-success-800 font-medium">Nothing needs your attention right now.</p>
        </div>
    );
    return (
        <div>
            <div className="flex items-center gap-2 mb-2">
                <span aria-hidden="true">⚠️</span>
                <h2 className="text-sm font-semibold text-amber-800">Needs your attention</h2>
                <span className="text-2xs font-bold text-amber-700 bg-amber-100 rounded-full px-1.5 py-0.5">{items.length}</span>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                {items.map(it => <AttentionCard key={it.key} it={it} />)}
            </div>
        </div>
    );
}

// ─── Engine room strip ────────────────────────────────────────────────────────
// The revenue engines, always on the Overview: one compact card per engine
// with its headline KES number and a click-through to the tab that explains
// it. Data comes from GET /reports/engine-room — six summaries in one call;
// an engine that failed server-side arrives as null and renders a "—" card.

/** "KES 1.2M" instead of fmtKes's full "KES 1,234,567" — strip cards are small. */
function kesCompact(amount: number | null | undefined): string {
    if (amount == null) return "—";
    return (
        "KES " +
        new Intl.NumberFormat("en-KE", {
            notation: "compact",
            maximumFractionDigits: 1,
        }).format(Number(amount))
    );
}

function daysUntil(date: string): number {
    return Math.max(
        0,
        Math.ceil((new Date(date).getTime() - Date.now()) / 86_400_000),
    );
}

function EngineCard({ label, value, sub, to, zero = false }: {
    label: string; value: string; sub: string; to: string; zero?: boolean;
}) {
    const navigate = useNavigate();
    return (
        <button
            onClick={() => navigate(to)}
            className="card card-body text-left transition-shadow hover:shadow-md cursor-pointer"
        >
            {/* Labels say what the money is in plain words and are never cut
                off — a truncated "MONEY ON TH…" told a manager nothing. */}
            <p className="text-xs text-surface-500 leading-snug">{label}</p>
            <p className={clsx(
                "text-lg font-bold tabular-nums mt-1 truncate",
                zero || value === "—" ? "text-surface-400" : "text-surface-900",
            )}>
                {value}
            </p>
            <p className="text-2xs text-surface-400 mt-0.5 line-clamp-2">{sub}</p>
        </button>
    );
}

function EngineRoomStrip() {
    const { data, isLoading } = useQuery<EngineRoomSummaries>({
        queryKey: ["engine-room"],
        queryFn: () => reportsApi.engineRoom(),
        staleTime: 5 * 60_000,
    });

    const collections = data?.collections ?? null;
    const stockout = data?.stockout ?? null;
    const winback = data?.winback ?? null;
    const attach = data?.attach ?? null;
    const radar = data?.replenishment ?? null;
    const seasonal = data?.seasonal ?? null;

    return (
        <div>
            <div className="flex items-center gap-2 mb-2">
                <h2 className="text-sm font-semibold text-surface-900">Opportunities</h2>
                <p className="text-2xs text-surface-400">— money waiting to be won, each one a click from the list behind it</p>
            </div>
            {isLoading || !data ? (
                <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                    {Array.from({ length: 6 }).map((_, i) => (
                        <div key={i} className="card card-body animate-pulse">
                            <div className="h-3 w-2/3 bg-surface-100 rounded" />
                            <div className="h-5 w-1/2 bg-surface-100 rounded mt-2" />
                            <div className="h-3 w-full bg-surface-100 rounded mt-1.5" />
                        </div>
                    ))}
                </div>
            ) : (
                <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                    <EngineCard
                        label="Open quotes & balances"
                        value={collections ? kesCompact(collections.money_on_table) : "—"}
                        sub={collections
                            ? `${collections.open_quotes.count} quotes · ${collections.unpaid_balances.count} unpaid balances`
                            : "unavailable right now"}
                        zero={!collections || collections.money_on_table <= 0}
                        to="/reports/sales?tab=collections"
                    />
                    <EngineCard
                        label="Lost to empty shelves"
                        value={stockout ? `${kesCompact(stockout.est_daily_loss_now)}/day` : "—"}
                        sub={stockout
                            ? `${stockout.products_currently_out} product${stockout.products_currently_out === 1 ? "" : "s"} out now`
                            : "unavailable right now"}
                        zero={!stockout || stockout.est_daily_loss_now <= 0}
                        to="/reports/inventory?tab=intelligence"
                    />
                    <EngineCard
                        label="Regulars gone quiet"
                        value={winback ? kesCompact(winback.annual_value_at_risk) : "—"}
                        sub={winback
                            ? `${winback.customers_at_risk} customers · ${kesCompact(winback.recovered_revenue_90d)} recovered 90d`
                            : "unavailable right now"}
                        zero={!winback || winback.annual_value_at_risk <= 0}
                        to="/reports/customers?tab=winback"
                    />
                    <EngineCard
                        label="Add-ons not sold"
                        value={attach ? kesCompact(attach.missed_revenue_estimate_total) : "—"}
                        sub={attach?.top_pair
                            ? `best pair: ${attach.top_pair.anchor} → ${attach.top_pair.companion} ${attach.top_pair.attach_rate}%`
                            : attach ? "not enough basket history yet" : "unavailable right now"}
                        zero={!attach || attach.missed_revenue_estimate_total <= 0}
                        to="/reports/sales?tab=basket"
                    />
                    <EngineCard
                        label="Due to buy again"
                        value={radar ? kesCompact(radar.expected_revenue) : "—"}
                        sub={radar
                            ? `${radar.due_pairs} due · pings 30d: ${radar.pings_30d}`
                            : "unavailable right now"}
                        zero={!radar || radar.expected_revenue <= 0}
                        to="/reports/customers?tab=replenishment"
                    />
                    <EngineCard
                        label="Season ahead"
                        value={seasonal
                            ? seasonal.history_depth_days === 0
                                ? "—"
                                : kesCompact(seasonal.total_gap_value)
                            : "—"}
                        sub={seasonal
                            ? seasonal.history_depth_days === 0
                                ? "import legacy history to unlock"
                                : seasonal.next_season
                                  ? `${seasonal.next_season.label} in ${daysUntil(seasonal.next_season.start)}d · stock gap`
                                  : "no season in the next 120 days"
                            : "unavailable right now"}
                        zero={!seasonal || seasonal.history_depth_days === 0 || seasonal.total_gap_value <= 0}
                        to="/reports/procurement?tab=seasonal"
                    />
                </div>
            )}
        </div>
    );
}


function AgingCard({ aging, onBucket }: { aging: any; onBucket: (bucket: string, label: string) => void }) {
    const buckets = aging?.buckets ?? [];
    const max = Math.max(...buckets.map((b: any) => Number(b.amount)), 1);
    return (
        <div className="card card-body h-full">
            <h3 className="text-sm font-semibold text-surface-900">Balance aging</h3>
            <div className="space-y-1.5 mt-2">
                {buckets.map((b: any) => (
                    <button key={b.key} onClick={() => Number(b.amount) > 0 && onBucket(b.key, `Owed ${b.label}`)}
                        className="w-full flex items-center gap-2 group" disabled={Number(b.amount) === 0}>
                        <span className="text-2xs text-surface-400 w-11 text-left shrink-0">{b.label}</span>
                        <div className="flex-1 h-2 bg-surface-100 rounded-full overflow-hidden">
                            <div className={clsx("h-full rounded-full",
                                b.key === "90_plus" ? "bg-danger-500" : b.key === "61_90" ? "bg-amber-500" : "bg-brand-400")}
                                style={{ width: `${(Number(b.amount) / max) * 100}%` }} />
                        </div>
                        <span className={clsx("text-2xs font-bold tabular-nums w-16 text-right shrink-0",
                            Number(b.amount) > 0 ? "text-surface-700 group-hover:text-brand-600" : "text-surface-500")}>
                            {Number(b.amount) >= 1000 ? `${Math.round(Number(b.amount) / 1000)}k` : Number(b.amount)}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}

function ExecutiveOverview() {
    // The same header, period and outlet as every report — the period follows
    // the reader in from (and out to) the other pages.
    const dr = useDateRange("this_month");
    const query = periodParams(dr.preset, dr.start, dr.end, dr.outlet);
    const [drill, setDrill] = useState<{ metric: string; label: string; money?: boolean; bucket?: string; reportPath?: string } | null>(null);
    const { data, isLoading } = useQuery({
        queryKey: ["executive-dashboard", query],
        queryFn: () => reportsApi.executive(query),
        staleTime: 60_000,
    });

    const k = data?.kpis;

    return (
        <div className="space-y-4">
            <ReportPageHeader
                title="Executive Overview"
                subtitle="How the business is doing: what changed, where the pressure is, and where to look next. Tap a figure for the records behind it."
                preset={dr.preset}
                start={dr.start}
                end={dr.end}
                onPresetChange={dr.handlePreset}
                onStartChange={dr.setStart}
                onEndChange={dr.setEnd}
                outlet={dr.outlet}
                onOutletChange={dr.setOutlet}
            />

            {isLoading || !k ? (
                <div className="flex justify-center py-12"><Spinner /></div>
            ) : (
                <>
                    <AttentionPanel items={data.attention ?? []} />

                    <EngineRoomStrip />

                    {/* One continuous grid: the whole screen is the dashboard.
                        2-up on phones, 4-up on laptops, 8-up on big displays. */}
                    <div className="grid grid-cols-2 md:grid-cols-4 2xl:grid-cols-8 gap-3">
                        <MetricCard label="Sold" metric={k.sales.revenue} money
                            onOpen={() => setDrill({ metric: "revenue", label: "Sold — the orders", money: true, reportPath: "/reports/sales" })} />
                        <MetricCard label="Collected" metric={k.money.collected} money
                            onOpen={() => setDrill({ metric: "collected", label: "Collected — settled payments", money: true, reportPath: can_financial_path(k) })} />
                        <MetricCard label="Outstanding"
                            value={fmtKes(k.money.outstanding.amount)}
                            sub={`${k.money.outstanding.orders} open orders`}
                            onOpen={() => setDrill({ metric: "outstanding", label: "Outstanding balances", money: true, reportPath: "/pos/outstanding-balances" })} />
                        <MetricCard label="Deposits Held"
                            value={fmtKes(k.money.aging?.deposits_held?.amount ?? 0)}
                            sub={`${k.money.aging?.deposits_held?.orders ?? 0} undelivered — not income`}
                            onOpen={() => setDrill({ metric: "outstanding", bucket: "deposits", label: "Deposits held (undelivered)", money: true })} />
                        <MetricCard label="Orders" metric={k.sales.orders}
                            onOpen={() => setDrill({ metric: "orders", label: "Orders in period", reportPath: "/reports/sales" })} />
                        <MetricCard label="Avg Order Value" metric={k.sales.aov} money to="/reports/sales" />
                        <MetricCard label="New Customers" metric={k.sales.new_customers}
                            onOpen={() => setDrill({ metric: "new_customers", label: "New customers", reportPath: "/reports/customers" })} />
                        <MetricCard label="Low Stock"
                            value={String(k.inventory.low_stock)}
                            sub={k.inventory.low_stock > 0 ? "items at reorder point" : "all healthy"}
                            to="/reports/inventory" />

                        <MetricCard label="Production Done" metric={k.production.completed}
                            onOpen={() => setDrill({ metric: "production_completed", label: "Completed production orders", reportPath: "/reports/production" })} />
                        <MetricCard label="On-time %"
                            value={k.production.on_time_pct.current != null ? `${k.production.on_time_pct.current}%` : "—"}
                            sub={k.production.on_time_pct.previous != null ? `prev ${k.production.on_time_pct.previous}%` : "no prior data"}
                            to="/reports/production" />
                        <MetricCard label="WIP / Overdue"
                            value={`${k.production.wip}${k.production.overdue > 0 ? ` · ${k.production.overdue} late` : ""}`}
                            sub={k.production.overdue > 0 ? "overdue on the floor" : "nothing overdue"}
                            onOpen={k.production.overdue > 0
                                ? () => setDrill({ metric: "production_overdue", label: "Overdue production orders", reportPath: "/production/wip" })
                                : undefined}
                            to="/production/wip" />
                        {k.financial && (
                            <>
                                <MetricCard label="Expenses" metric={k.financial.expenses} money downIsGood
                                    onOpen={() => setDrill({ metric: "expenses", label: "Expenses in period", money: true, reportPath: "/expenses" })} />
                                <MetricCard label="Net (Coll. − Exp.)" metric={k.financial.net_collected} money to="/reports/finance" />
                                {/* Profit, not cash — the earned P&L from Finance & Cash,
                                    with what it leaves out stated, never a bare margin. */}
                                {k.financial.earned && (
                                    <MetricCard label="Earned profit"
                                        value={fmtKes(k.financial.earned.net_profit)}
                                        sub={k.financial.earned.limits?.length
                                            ? `Limited: ${k.financial.earned.limits.join("; ")}`
                                            : k.financial.earned.gross_margin_pct != null
                                                ? `${k.financial.earned.gross_margin_pct}% gross margin · every cost in`
                                                : "no fully-paid orders yet"}
                                        to="/reports/finance?tab=intelligence" />
                                )}
                            </>
                        )}
                        <div className={clsx(k.financial ? "col-span-2 md:col-span-2 2xl:col-span-2" : "col-span-2 md:col-span-3 2xl:col-span-5")}>
                            <AgingCard aging={k.money.aging}
                                onBucket={(bucket, label) => setDrill({ metric: "outstanding", bucket, label, money: true, reportPath: "/pos/outstanding-balances" })} />
                        </div>
                    </div>
                </>
            )}
            {/* The shared drill panel: the backend says what each number is and
                where each row may lead (permission-checked); nothing is guessed here. */}
            {drill && (
                <DrillPanel metric={drill.metric} title={drill.label}
                    query={{ ...query, ...(drill.bucket ? { bucket: drill.bucket } : {}) }}
                    onClose={() => setDrill(null)} />
            )}
        </div>
    );
}

// Collected drills to financial for those who may enter; sales otherwise.
function can_financial_path(k: any): string {
    return k?.financial ? "/reports/finance" : "/reports/sales";
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
    /** What the page offers — the tags must be true for each page. */
    csv?: boolean;
    schedulable?: boolean;
}

const tileIcon = (d: string) => (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
        <path strokeLinecap="round" strokeLinejoin="round" d={d} />
    </svg>
);

const CATEGORIES: ReportCategory[] = [
    {
        id: "sales",
        label: "Sales & Orders",
        description: "Revenue, orders, products, channels, patterns & returns",
        path: "/reports/sales",
        color: "text-info-600 bg-info-50",
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
        label: "Customers & Neema",
        description: "Growth, segments, lifetime value, retention cohorts",
        path: "/reports/customers",
        color: "text-accent-600 bg-accent-50",
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
        label: "Production & Fulfilment",
        description:
            "Completion, on-time rate, tailor performance, QC failures",
        path: "/reports/production",
        color: "text-accent-600 bg-accent-50",
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
        label: "Procurement & Suppliers",
        description:
            "Purchase orders, supplier spend, top items, fulfilment status",
        path: "/reports/procurement",
        color: "text-success-600 bg-success-50",
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
        label: "Finance & Cash",
        description: "P&L statement, revenue vs expenses, tax, discounts",
        path: "/reports/finance",
        color: "text-info-600 bg-info-50",
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
    {
        id: "performance",
        label: "Staff, Outlets & Performance",
        description: "Each outlet and salesperson against the previous period",
        path: "/reports/performance",
        color: "text-brand-600 bg-brand-50",
        csv: true, schedulable: false,
        icon: tileIcon("M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"),
    },
    {
        id: "signals",
        label: "Business Intelligence & Signals",
        description: "Reorder suggestions, channel engagement, customer geography",
        path: "/reports/signals",
        color: "text-info-600 bg-info-50",
        csv: false, schedulable: false,
        icon: tileIcon("M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"),
    },
    {
        id: "explorer",
        label: "Business Explorer",
        description: "Any figure by month, channel, outlet, product or person — and the orders behind it",
        path: "/reports/explorer",
        color: "text-accent-600 bg-accent-50",
        csv: true, schedulable: false,
        icon: tileIcon("M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"),
    },
    {
        id: "data-quality",
        label: "Audit & Data Quality",
        description: "Can these numbers be trusted — and where the records are incomplete",
        path: "/reports/data-quality",
        color: "text-success-600 bg-success-50",
        csv: true, schedulable: false,
        icon: tileIcon("M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"),
    },
];

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ReportsPage() {
    const navigate = useNavigate();
    const { can } = usePermissions();
    // Every /reports/* sub-page requires reports.view, already implied by
    // reaching this page - except /reports/finance, which requires the
    // more restricted reports.financial (see routes/api.php and
    // SyncPermissions.php: outlet_manager and procurement_officer/manager
    // deliberately get reports.view but not reports.financial). Filtering
    // the tile here so it doesn't link to a page that will 403.
    const visibleCategories = CATEGORIES.filter(
        (cat) => cat.id !== "financial" || can("reports.financial"),
    );

    return (
        <div className="space-y-8 animate-fade-in">
            <ExecutiveOverview />
            <SchedulesSummary />

            <div>
                <h2 className="text-sm font-semibold text-surface-900 mb-3">
                    All reports
                </h2>
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
                                    {cat.csv !== false && <span className="flex items-center gap-0.5">
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
                                    </span>}
                                    {cat.schedulable !== false && <span className="flex items-center gap-0.5">
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
                                    </span>}
                                </div>
                            </div>
                            <svg
                                className="w-4 h-4 text-surface-500 group-hover:text-brand-500 transition-colors shrink-0 mt-0.5"
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