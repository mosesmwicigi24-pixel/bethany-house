// src/pages/reports/ProcurementReportPage.tsx
import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import {
    reportsApi,
    type SeasonalDemandProductRow,
} from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
import { clsx } from "clsx";
import dayjs from "dayjs";
import {
    BarChart,
    Bar,
    LineChart,
    Line,
    PieChart,
    Pie,
    Cell,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    Legend,
    ResponsiveContainer,
} from "recharts";
import {
    KPI_GRID,
    KpiCard,
    ReportPdfButton,
    SectionHeader,
    TableWrapper,
    EmptyRow,
    ReportActionBar,
    ExportCsvButton,
    StatusPill,
    DateRangePicker,
    ReportPageHeader,
    useDateRange,
    ProgressBar,
    CHART_COLORS,
    TH,
    TH_R,
} from "./reportShared";

type ProcurementTab =
    | "overview"
    | "suppliers"
    | "items"
    | "intelligence"
    | "seasonal";
const PROCUREMENT_TABS: readonly ProcurementTab[] = [
    "overview",
    "suppliers",
    "items",
    "intelligence",
    "seasonal",
];

export default function ProcurementReportPage() {
    const dr = useDateRange("this_month");
    // Honour deep-links like /reports/procurement?tab=seasonal (the attention
    // feed sends users here) — read once on mount, same pattern as
    // CustomersReportPage; after that the tab buttons own the state.
    const [searchParams] = useSearchParams();
    const [activeTab, setActiveTab] = useState<ProcurementTab>(() => {
        const t = searchParams.get("tab");
        return PROCUREMENT_TABS.includes(t as ProcurementTab)
            ? (t as ProcurementTab)
            : "overview";
    });

    const { data, isLoading } = useQuery({
        queryKey: ["report-procurement", dr.start, dr.end],
        queryFn: () => reportsApi.purchaseOrders(dr.params),
        enabled: !!dr.start && !!dr.end,
    });

    if (isLoading)
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );

    const summary = data?.summary ?? {};
    const bySupplier = data?.by_supplier ?? [];
    const monthlyTrend = data?.monthly_trend ?? [];
    const byStatus = data?.by_status ?? [];
    const topItems = data?.top_items ?? [];

    const maxSupplierSpend = Math.max(
        ...bySupplier.map((s: any) => Number(s.total_value)),
        1,
    );

    const chartData = [...bySupplier]
        .sort((a: any, b: any) => b.total_value - a.total_value)
        .slice(0, 8)
        .map((s: any) => ({
            name: s.name?.length > 18 ? s.name.substring(0, 16) + "…" : s.name,
            total_value: Number(s.total_value),
        }));

    const fulfillmentRate =
        summary.total_orders > 0
            ? Math.round(
                  ((summary.received_count ?? 0) / summary.total_orders) * 100,
              )
            : 0;

    return (
        <div className="space-y-6 animate-fade-in">
            <ReportPageHeader
                title="Procurement Report"
                subtitle="Purchase orders, supplier spend, and fulfilment."
                reportType="procurement"
                exportPath="purchase-orders"
                params={dr.params}
                preset={dr.preset}
                start={dr.start}
                end={dr.end}
                onPresetChange={dr.handlePreset}
                onStartChange={dr.setStart}
                onEndChange={dr.setEnd}
            />

            {/* KPIs */}
            <div className={KPI_GRID}>
                <KpiCard label="Total POs" value={summary.total_orders ?? 0} />
                <KpiCard
                    label="Total Spend"
                    value={fmtKes(summary.total_value)}
                />
                <KpiCard
                    label="Received Value"
                    value={fmtKes(summary.received_value)}
                    color="text-success"
                />
                <KpiCard
                    label="Pending POs"
                    value={summary.pending_count ?? 0}
                    color="text-warning"
                />
            </div>
            <div className={KPI_GRID}>
                <KpiCard
                    label="Avg PO Value"
                    value={fmtKes(summary.avg_po_value)}
                />
                <KpiCard
                    label="Fulfillment Rate"
                    value={`${fulfillmentRate}%`}
                    color={
                        fulfillmentRate >= 80 ? "text-success" : "text-warning"
                    }
                />
                <KpiCard
                    label="Avg Lead Time"
                    value={
                        summary.avg_lead_days
                            ? `${Math.round(summary.avg_lead_days)} days`
                            : "-"
                    }
                />
                <KpiCard
                    label="Partial Received"
                    value={fmtKes(summary.partial_value)}
                    color="text-info"
                />
            </div>

            {/* Tabs */}
            <div className="border-b border-line overflow-x-auto no-scrollbar">
                <nav className="flex gap-1 -mb-px">
                    {PROCUREMENT_TABS.map(
                        (tab) => (
                            <button
                                key={tab}
                                onClick={() => setActiveTab(tab)}
                                className={clsx(
                                    "px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap shrink-0 transition-colors capitalize",
                                    activeTab === tab
                                        ? "border-brand-500 text-brand-600"
                                        : "border-transparent text-surface-500 hover:text-surface-700",
                                )}
                            >
                                {tab}
                            </button>
                        ),
                    )}
                </nav>
            </div>

            {activeTab === "intelligence" && (
                <ProcurementIntelligence start={dr.start} end={dr.end} />
            )}

            {activeTab === "seasonal" && <SeasonalDemandTab />}

            {/* ── OVERVIEW ── */}
            {activeTab === "overview" && (
                <div className="space-y-6">
                    {/* Monthly spend trend */}
                    {monthlyTrend.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Monthly Spend Trend" />
                            <ResponsiveContainer width="100%" height={240}>
                                <LineChart data={monthlyTrend}>
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="#f2f3f2"
                                    />
                                    <XAxis
                                        dataKey="month"
                                        tick={{ fontSize: 11 }}
                                    />
                                    <YAxis
                                        tickFormatter={(v: number) =>
                                            `${(v / 1000).toFixed(0)}K`
                                        }
                                        tick={{ fontSize: 11 }}
                                        width={48}
                                    />
                                    <Tooltip
                                        formatter={(v) => fmtKes(v as number)}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="total_value"
                                        stroke={CHART_COLORS[0]}
                                        strokeWidth={2}
                                        dot={true}
                                        name="Spend"
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}

                    {/* Status breakdown */}
                    {byStatus.length > 0 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="card p-5">
                                <SectionHeader title="PO Status Distribution" />
                                <ResponsiveContainer width="100%" height={200}>
                                    <PieChart>
                                        <Pie
                                            data={byStatus}
                                            dataKey="count"
                                            nameKey="status"
                                            cx="50%"
                                            cy="50%"
                                            outerRadius={75}
                                            label={({ status, percent }: any) =>
                                                `${status?.replace(/_/g, " ")} ${(percent * 100).toFixed(0)}%`
                                            }
                                            labelLine={false}
                                        >
                                            {byStatus.map(
                                                (_: any, i: number) => (
                                                    <Cell
                                                        key={i}
                                                        fill={
                                                            CHART_COLORS[
                                                                i %
                                                                    CHART_COLORS.length
                                                            ]
                                                        }
                                                    />
                                                ),
                                            )}
                                        </Pie>
                                        <Tooltip
                                            formatter={(v, name) => [v, name]}
                                        />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="card p-5">
                                <SectionHeader title="By Status" />
                                <div className="space-y-3 mt-2">
                                    {byStatus.map((s: any, i: number) => (
                                        <div
                                            key={s.status}
                                            className="flex items-center justify-between"
                                        >
                                            <StatusPill status={s.status} />
                                            <div className="text-right">
                                                <p className="text-sm font-semibold tabular-nums">
                                                    {fmtKes(s.total)}
                                                </p>
                                                <p className="text-xs text-surface-400">
                                                    {s.count} orders
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* ── SUPPLIERS ── */}
            {activeTab === "suppliers" && (
                <div className="space-y-6">
                    {chartData.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Spend by Supplier" />
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart data={chartData} layout="vertical">
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="#f2f3f2"
                                        horizontal={false}
                                    />
                                    <XAxis
                                        type="number"
                                        tickFormatter={(v: number) =>
                                            `${(v / 1000).toFixed(0)}K`
                                        }
                                        tick={{ fontSize: 11 }}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="name"
                                        tick={{ fontSize: 11 }}
                                        width={130}
                                    />
                                    <Tooltip
                                        formatter={(v) => fmtKes(v as number)}
                                    />
                                    <Bar
                                        dataKey="total_value"
                                        name="Total Spend"
                                        fill={CHART_COLORS[0]}
                                        radius={[0, 3, 3, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}

                    <div className="card overflow-hidden">
                        <div className="px-5 pt-5 pb-4">
                            <SectionHeader title="Supplier Summary">
                                <ExportCsvButton
                                    path="purchase-orders"
                                    params={dr.params}
                                />
                            </SectionHeader>
                        </div>
                        <TableWrapper>
                            <table className="w-full">
                                <thead>
                                    <tr className="border-y border-line bg-surface-50/50">
                                        <th className={TH}>Supplier</th>
                                        <th className={TH}>Email</th>
                                        <th className={TH_R}>POs</th>
                                        <th className={TH_R}>Received</th>
                                        <th className={TH_R}>Pending</th>
                                        <th className={TH_R}>Avg PO Value</th>
                                        <th className={TH_R}>Total Spend</th>
                                        <th
                                            className={TH}
                                            style={{ width: 100 }}
                                        >
                                            Share
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {bySupplier.length === 0 ? (
                                        <EmptyRow cols={8} />
                                    ) : (
                                        bySupplier.map((s: any) => (
                                            <tr
                                                key={s.id}
                                                className="hover:bg-surface-50/50 transition-colors"
                                            >
                                                <td className="px-4 py-3 font-medium text-surface-900">
                                                    {s.name}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-500">
                                                    {s.email ?? "-"}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {s.order_count}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-success">
                                                    {s.received_count}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-warning">
                                                    {s.pending_count}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                    {fmtKes(s.avg_value)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                    {fmtKes(s.total_value)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <ProgressBar
                                                        value={Number(
                                                            s.total_value,
                                                        )}
                                                        max={maxSupplierSpend}
                                                        color={CHART_COLORS[0]}
                                                    />
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </TableWrapper>
                    </div>
                </div>
            )}

            {/* ── ITEMS ── */}
            {activeTab === "items" && (
                <div className="card overflow-hidden">
                    <div className="px-5 pt-5 pb-4">
                        <SectionHeader title="Top Purchased Items" />
                    </div>
                    <TableWrapper>
                        <table className="w-full">
                            <thead>
                                <tr className="border-y border-line bg-surface-50/50">
                                    <th className={clsx(TH, "w-8")}>#</th>
                                    <th className={TH}>Product</th>
                                    <th className={TH}>SKU</th>
                                    <th className={TH_R}>POs</th>
                                    <th className={TH_R}>Total Qty</th>
                                    <th className={TH_R}>Total Spend</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {topItems.length === 0 ? (
                                    <EmptyRow cols={6} />
                                ) : (
                                    topItems.map((item: any, i: number) => (
                                        <tr
                                            key={i}
                                            className="hover:bg-surface-50/50 transition-colors"
                                        >
                                            <td className="px-4 py-3 text-surface-400 text-sm">
                                                {i + 1}
                                            </td>
                                            <td className="px-4 py-3 font-medium text-surface-900">
                                                {item.product_name}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="font-mono text-xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                                    {item.sku ?? "-"}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {item.po_count}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {item.total_quantity}
                                            </td>
                                            <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                {fmtKes(item.total_spend)}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </TableWrapper>
                </div>
            )}
        </div>
    );
}

// ─── Intelligence tab ─────────────────────────────────────────────────────────
// Supplier scorecard from POs + goods-received notes (actual delivery days vs
// the promise, rejections at the door) and a grounded buy list: reorder
// buffer + open production demand − available, priced at the last real price.

// ─── Seasonal demand tab ──────────────────────────────────────────────────────
// The liturgical year as a demand plan: upcoming seasons (Advent, Lent, Holy
// Week…), each product's historical seasonal lift, the projected units vs
// stock on hand, and the date every PO must leave by given supplier lead
// times. Pre-import (no history) it invites the legacy import instead.

function SeasonalDemandTab() {
    const { data, isLoading } = useQuery({
        queryKey: ["seasonal-demand"],
        queryFn: () => reportsApi.seasonalDemand(),
        staleTime: 60_000,
    });
    if (isLoading || !data)
        return (
            <div className="flex justify-center py-16">
                <Spinner />
            </div>
        );

    const { seasons, summary } = data;

    if (summary.history_depth_days === 0) {
        return (
            <div className="card p-8 text-center space-y-3">
                <p className="text-base font-semibold text-surface-800">
                    Import legacy history to unlock projections
                </p>
                <p className="text-sm text-surface-500 max-w-xl mx-auto">
                    Seasonal demand planning learns each product's Advent,
                    Lent and Holy Week surge from past liturgical years — and
                    the hub's own orders are still too young to have seen one.
                    Export daily sales from the legacy POS and run{" "}
                    <code className="font-mono text-xs bg-surface-100 px-1.5 py-0.5 rounded">
                        php artisan seasonal:import-legacy export.json
                    </code>{" "}
                    to light this tab up.
                </p>
                {seasons.length > 0 && (
                    <p className="text-xs text-surface-400">
                        Coming up:{" "}
                        {seasons
                            .map(
                                (s) =>
                                    `${s.label} (${dayjs(s.start).format("D MMM")})`,
                            )
                            .join(" · ")}
                    </p>
                )}
            </div>
        );
    }

    const urgencyClass = (days: number) =>
        days <= 7
            ? "text-danger font-semibold"
            : days <= 14
              ? "text-warning font-semibold"
              : "text-surface-600";

    return (
        <div className="space-y-6">
            <div className="flex justify-end">
                <ExportCsvButton path="seasonal-demand" params={{}} />
            </div>

            {/* Season timeline strip */}
            <div className="flex gap-3 overflow-x-auto no-scrollbar pb-1">
                {seasons.map((s) => (
                    <div
                        key={`${s.key}-${s.start}`}
                        className={clsx(
                            "card px-4 py-3 min-w-[170px] shrink-0",
                            s.sub_of && "border-dashed",
                        )}
                    >
                        <p className="text-sm font-semibold text-surface-800">
                            {s.label}
                        </p>
                        <p className="text-xs text-surface-500">
                            {dayjs(s.start).format("D MMM")} –{" "}
                            {dayjs(s.end).format("D MMM YYYY")}
                        </p>
                        <p className="text-xs mt-1">
                            {s.days_until_start === 0 ? (
                                <span className="text-success font-medium">
                                    underway
                                </span>
                            ) : (
                                <span className="text-surface-500">
                                    in{" "}
                                    <span className="font-semibold text-surface-700 tabular-nums">
                                        {s.days_until_start}d
                                    </span>
                                </span>
                            )}
                            {s.sub_of && (
                                <span className="text-surface-400">
                                    {" "}
                                    · within Lent
                                </span>
                            )}
                        </p>
                    </div>
                ))}
            </div>

            {/* KPI row */}
            <div className={KPI_GRID}>
                <KpiCard
                    label="Next Season"
                    value={summary.next_season?.label ?? "—"}
                    sub={
                        summary.next_season
                            ? `starts ${dayjs(summary.next_season.start).format("D MMM YYYY")}`
                            : undefined
                    }
                />
                <KpiCard
                    label="Gap Value (est.)"
                    value={fmtKes(summary.total_gap_value)}
                    color={
                        summary.total_gap_value > 0
                            ? "text-warning"
                            : "text-success"
                    }
                />
                <KpiCard
                    label="Urgent Order-bys"
                    value={summary.urgent_orders}
                    color={
                        summary.urgent_orders > 0
                            ? "text-danger"
                            : "text-success"
                    }
                    sub="order window within 14 days"
                />
            </div>

            {/* Per-season product tables */}
            {seasons.map((s) => (
                <div key={`${s.key}-${s.start}-table`} className="card card-body">
                    <SectionHeader
                        title={`${s.label} — ${dayjs(s.start).format("D MMM")} to ${dayjs(s.end).format("D MMM YYYY")}`}
                    />
                    {s.products.length === 0 ? (
                        <p className="text-xs text-surface-400 py-4">
                            No seasonal sales history for {s.label} yet —
                            projections appear once a prior {s.label} exists in
                            hub or imported legacy history.
                        </p>
                    ) : (
                        <TableWrapper>
                            <table className="w-full text-xs">
                                <thead>
                                    <tr>
                                        <th className={TH}>Product</th>
                                        <th className={TH_R}>Lift</th>
                                        <th className={TH_R}>Projected</th>
                                        <th className={TH_R}>Stock</th>
                                        <th className={TH_R}>Gap</th>
                                        <th className={TH}>Order By</th>
                                        <th className={TH_R}>Est. Gap KES</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {s.products.map(
                                        (r: SeasonalDemandProductRow) => (
                                            <tr key={r.product_id}>
                                                <td className="px-3 py-2">
                                                    <p className="font-medium text-surface-800">
                                                        {r.name}
                                                    </p>
                                                    {r.basis ===
                                                        "historical" && (
                                                        <p className="text-2xs text-surface-400">
                                                            from legacy rate —
                                                            no recent hub sales
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums font-semibold text-brand-700">
                                                    {Number(r.lift).toFixed(1)}
                                                    ×
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {r.projected_units.toLocaleString()}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums text-surface-500">
                                                    {r.stock.toLocaleString()}
                                                </td>
                                                <td
                                                    className={clsx(
                                                        "px-3 py-2 text-right tabular-nums",
                                                        r.gap > 0
                                                            ? "font-bold text-warning"
                                                            : "text-surface-400",
                                                    )}
                                                >
                                                    {r.gap.toLocaleString()}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {r.gap > 0 ? (
                                                        <span
                                                            className={urgencyClass(
                                                                r.days_until_order_by,
                                                            )}
                                                        >
                                                            {dayjs(
                                                                r.order_by,
                                                            ).format("D MMM")}
                                                            {r.days_until_order_by <
                                                            0
                                                                ? " · overdue"
                                                                : ` · ${r.days_until_order_by}d`}
                                                        </span>
                                                    ) : (
                                                        <span className="text-surface-500">
                                                            covered
                                                        </span>
                                                    )}
                                                    <p className="text-2xs text-surface-400">
                                                        lead {r.lead_days}d
                                                    </p>
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums font-semibold">
                                                    {r.gap > 0
                                                        ? fmtKes(
                                                              r.est_gap_value,
                                                          )
                                                        : "—"}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </TableWrapper>
                    )}
                </div>
            ))}
        </div>
    );
}

function ProcurementIntelligence({ start, end }: { start: string; end: string }) {
    const { data, isLoading } = useQuery({
        queryKey: ["procurement-intelligence", start, end],
        queryFn: () => reportsApi.procurementIntelligence(start, end),
        enabled: !!start && !!end,
        staleTime: 60_000,
    });
    if (isLoading || !data) return <div className="flex justify-center py-16"><Spinner /></div>;
    const { suppliers, suggestions, open_pos } = data;
    const totalSuggested = suggestions.reduce((n: number, r: any) => n + Number(r.est_cost), 0);

    return (
        <div className="space-y-6">
            <div className={KPI_GRID}>
                <KpiCard label="Open POs" value={open_pos.count}
                    sub={open_pos.oldest_days != null ? `oldest ${open_pos.oldest_days}d` : "none in flight"} />
                <KpiCard label="In-flight Value" value={fmtKes(open_pos.value)} />
                <KpiCard label="Suggested Buys" value={fmtKes(totalSuggested)}
                    color={totalSuggested > 0 ? "text-warning" : "text-success"}
                    sub={`${suggestions.length} materials`} />
            </div>

            <div className="card card-body">
                <SectionHeader title="What to buy — buffer + committed demand − stock" />
                {suggestions.length === 0 ? (
                    <p className="text-xs text-surface-400 py-4">Nothing to buy: every material covers its reorder point and open production demand.</p>
                ) : (
                    <TableWrapper>
                        <table className="w-full text-xs">
                            <thead><tr>
                                <th className={TH}>Material</th>
                                <th className={TH_R}>Available</th>
                                <th className={TH_R}>Buffer</th>
                                <th className={TH_R}>Open Demand</th>
                                <th className={TH_R}>Suggest</th>
                                <th className={TH_R}>Est. Cost</th>
                                <th className={TH}>Last Bought</th>
                            </tr></thead>
                            <tbody className="divide-y divide-line">
                                {suggestions.map((r: any) => (
                                    <tr key={r.code}>
                                        <td className="px-3 py-2">
                                            <p className="font-medium text-surface-800">{r.material}</p>
                                            <p className="text-2xs text-surface-400 font-mono">{r.code} · {r.unit}</p>
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">{Number(r.available).toLocaleString()}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-surface-500">{Number(r.reorder_point).toLocaleString()}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-surface-500">{Number(r.open_demand).toLocaleString()}</td>
                                        <td className="px-3 py-2 text-right tabular-nums font-bold text-brand-700">{Number(r.suggested).toLocaleString()}</td>
                                        <td className="px-3 py-2 text-right tabular-nums font-semibold">{fmtKes(r.est_cost)}</td>
                                        <td className="px-3 py-2 text-2xs text-surface-500">
                                            {r.last_supplier
                                                ? <>{r.last_supplier}{r.last_price != null && <> · {fmtKes(r.last_price)}/{r.unit}</>}</>
                                                : <span className="italic text-surface-500">no purchase history</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableWrapper>
                )}
            </div>

            <div className="card card-body">
                <SectionHeader title="Supplier scorecard (period)" />
                {suppliers.length === 0 ? (
                    <p className="text-xs text-surface-400 py-4">No purchase orders in this period.</p>
                ) : (
                    <TableWrapper>
                        <table className="w-full text-xs">
                            <thead><tr>
                                <th className={TH}>Supplier</th>
                                <th className={TH_R}>Orders</th>
                                <th className={TH_R}>Spend</th>
                                <th className={TH_R}>Avg Delivery</th>
                                <th className={TH_R}>Late</th>
                                <th className={TH_R}>Rejected</th>
                            </tr></thead>
                            <tbody className="divide-y divide-line">
                                {suppliers.map((sup: any) => {
                                    const rejPct = Number(sup.qty_received) > 0
                                        ? Math.round((Number(sup.qty_rejected) / Number(sup.qty_received)) * 100) : null;
                                    return (
                                        <tr key={sup.supplier}>
                                            <td className="px-3 py-2 font-medium text-surface-800">{sup.supplier}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{sup.orders}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{fmtKes(sup.spend)}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{sup.avg_delivery_days != null ? `${sup.avg_delivery_days}d` : "—"}</td>
                                            <td className={clsx("px-3 py-2 text-right tabular-nums font-semibold", Number(sup.late) > 0 ? "text-danger" : "text-surface-400")}>{sup.late}</td>
                                            <td className={clsx("px-3 py-2 text-right tabular-nums", rejPct != null && rejPct > 0 ? "text-danger font-semibold" : "text-surface-400")}>{rejPct != null ? `${rejPct}%` : "—"}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </TableWrapper>
                )}
            </div>
        </div>
    );
}
