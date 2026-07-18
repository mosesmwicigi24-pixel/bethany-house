// src/pages/reports/ProductionReportPage.tsx
import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { reportsApi } from "@/api/reports";
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
    KpiCard,
    SectionHeader,
    TableWrapper,
    EmptyRow,
    ReportActionBar,
    ExportCsvButton,
    DateRangePicker,
    useDateRange,
    StatusPill,
    ProgressBar,
    CHART_COLORS,
    TH,
    TH_R,
    fmtHours,
} from "./reportShared";

export default function ProductionReportPage() {
    const dr = useDateRange("this_month");
    const [activeTab, setActiveTab] = useState<
        "overview" | "products" | "tailors" | "costing" | "intelligence"
    >("overview");

    const { data, isLoading } = useQuery({
        queryKey: ["report-production", dr.start, dr.end],
        queryFn: () => reportsApi.productionSummary(dr.params),
        enabled: !!dr.start && !!dr.end,
    });

    const { data: costingData, isLoading: costingLoading } = useQuery({
        queryKey: ["report-production-costing", dr.start, dr.end],
        queryFn: () => reportsApi.productionCostingSummary(dr.params),
        enabled: !!dr.start && !!dr.end && activeTab === "costing",
    });

    if (isLoading)
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );

    const s = data?.summary ?? {};
    const byProduct = data?.by_product ?? [];
    const byTailor = data?.by_tailor ?? [];
    const dailyTrend = data?.daily_trend ?? [];
    const byStatus = data?.by_status ?? [];

    const completionRate =
        s.total_units_planned > 0
            ? Math.round(
                  (Number(s.total_units_produced) /
                      Number(s.total_units_planned)) *
                      100,
              )
            : 0;

    const chartData = byProduct.slice(0, 10).map((p: any) => ({
        name:
            p.name_en?.length > 16
                ? p.name_en.substring(0, 14) + "…"
                : p.name_en,
        planned: Number(p.units_planned),
        produced: Number(p.units_produced),
    }));

    const maxTailorUnits = Math.max(
        ...byTailor.map((t: any) => Number(t.units_produced)),
        1,
    );

    return (
        <div className="space-y-6 animate-fade-in">
            {/* Header */}
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="page-title">Production Report</h1>
                    <p className="page-subtitle">
                        Order completion, on-time delivery, and tailor
                        performance.
                    </p>
                </div>
                <DateRangePicker
                    preset={dr.preset}
                    start={dr.start}
                    end={dr.end}
                    onPresetChange={dr.handlePreset}
                    onStartChange={dr.setStart}
                    onEndChange={dr.setEnd}
                />
            </div>

            <ReportActionBar
                reportType="production"
                exportPath="production/summary"
                params={dr.params}
            />

            {/* KPIs row 1 */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                <KpiCard label="Total Orders" value={s.total_orders ?? 0} />
                <KpiCard
                    label="Completed"
                    value={s.completed_count ?? 0}
                    color="text-success"
                />
                <KpiCard
                    label="Active"
                    value={s.active_count ?? 0}
                    color="text-info"
                />
                <KpiCard
                    label="QC Failed"
                    value={s.failed_count ?? 0}
                    color="text-danger"
                />
            </div>

            {/* KPIs row 2 */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                <KpiCard
                    label="Units Planned"
                    value={s.total_units_planned ?? 0}
                />
                <KpiCard
                    label="Units Produced"
                    value={s.total_units_produced ?? 0}
                />
                <KpiCard
                    label="Completion Rate"
                    value={`${completionRate}%`}
                    sub={`${s.total_units_produced ?? 0} / ${s.total_units_planned ?? 0} units`}
                    color={
                        completionRate >= 80
                            ? "text-success"
                            : completionRate >= 50
                              ? "text-warning"
                              : "text-danger"
                    }
                />
                {s.on_time_rate !== null && s.on_time_rate !== undefined ? (
                    <KpiCard
                        label="On-Time Rate"
                        value={`${s.on_time_rate}%`}
                        sub={`${s.on_time_count ?? 0} / ${s.completed_with_due ?? 0} with due date`}
                        color={
                            s.on_time_rate >= 80
                                ? "text-success"
                                : s.on_time_rate >= 60
                                  ? "text-warning"
                                  : "text-danger"
                        }
                    />
                ) : (
                    <KpiCard
                        label="Avg Completion"
                        value={fmtHours(s.avg_completion_hours)}
                        sub="per order"
                    />
                )}
            </div>

            {/* Tabs */}
            <div className="border-b border-surface-100">
                <nav className="flex gap-1 -mb-px">
                    {([
                        { id: "overview", label: "Overview" },
                        { id: "products", label: "Products" },
                        { id: "tailors",  label: "Tailors" },
                        { id: "costing",  label: "Costing & Profitability" },
                        { id: "intelligence", label: "🧠 Intelligence" },
                    ] as const).map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={clsx(
                                "px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap",
                                activeTab === tab.id
                                    ? "border-brand-500 text-brand-600"
                                    : "border-transparent text-surface-500 hover:text-surface-700",
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
            </div>

            {/* ── OVERVIEW ── */}
            {activeTab === "overview" && (
                <div className="space-y-6">
                    {/* Status breakdown */}
                    {byStatus.length > 0 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="card p-5">
                                <SectionHeader title="Status Distribution" />
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
                                        <Tooltip />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="card p-5">
                                <SectionHeader title="Status Breakdown" />
                                <div className="space-y-3 mt-2">
                                    {byStatus.map((s: any, i: number) => (
                                        <div
                                            key={s.status}
                                            className="flex items-center justify-between"
                                        >
                                            <div className="flex items-center gap-2">
                                                <div
                                                    className="w-2.5 h-2.5 rounded-full"
                                                    style={{
                                                        backgroundColor:
                                                            CHART_COLORS[
                                                                i %
                                                                    CHART_COLORS.length
                                                            ],
                                                    }}
                                                />
                                                <StatusPill status={s.status} />
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-semibold tabular-nums">
                                                    {s.count} orders
                                                </p>
                                                <p className="text-xs text-surface-400">
                                                    {s.units} units
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Daily trend */}
                    {dailyTrend.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Daily Production Activity" />
                            <ResponsiveContainer width="100%" height={220}>
                                <BarChart data={dailyTrend}>
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="#F1F5F9"
                                    />
                                    <XAxis
                                        dataKey="date"
                                        tickFormatter={(d: string) =>
                                            dayjs(d).format("D MMM")
                                        }
                                        tick={{ fontSize: 10 }}
                                    />
                                    <YAxis tick={{ fontSize: 11 }} width={36} />
                                    <Tooltip />
                                    <Legend />
                                    <Bar
                                        dataKey="orders_created"
                                        name="Created"
                                        fill={CHART_COLORS[2]}
                                        radius={[2, 2, 0, 0]}
                                    />
                                    <Bar
                                        dataKey="orders_completed"
                                        name="Completed"
                                        fill={CHART_COLORS[0]}
                                        radius={[2, 2, 0, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}

                    {/* Avg time metrics */}
                    <div className="card p-5">
                        <SectionHeader title="Performance Metrics" />
                        <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                            <div>
                                <p className="text-xs text-surface-500">
                                    Avg Completion Time
                                </p>
                                <p className="text-lg font-bold mt-1">
                                    {fmtHours(s.avg_completion_hours)}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-surface-500">
                                    QC Failure Rate
                                </p>
                                <p
                                    className={clsx(
                                        "text-lg font-bold mt-1",
                                        s.failed_count /
                                            Math.max(1, s.total_orders) >
                                            0.1
                                            ? "text-danger"
                                            : "text-success",
                                    )}
                                >
                                    {s.total_orders > 0
                                        ? `${((s.failed_count / s.total_orders) * 100).toFixed(1)}%`
                                        : "—"}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-surface-500">
                                    On-Time Delivery
                                </p>
                                <p
                                    className={clsx(
                                        "text-lg font-bold mt-1",
                                        s.on_time_rate >= 80
                                            ? "text-success"
                                            : "text-warning",
                                    )}
                                >
                                    {s.on_time_rate !== null &&
                                    s.on_time_rate !== undefined
                                        ? `${s.on_time_rate}%`
                                        : "—"}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* ── PRODUCTS ── */}
            {activeTab === "products" && (
                <div className="space-y-6">
                    {chartData.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Planned vs Produced — Top Products" />
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart data={chartData}>
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="#F1F5F9"
                                    />
                                    <XAxis
                                        dataKey="name"
                                        tick={{ fontSize: 10 }}
                                    />
                                    <YAxis tick={{ fontSize: 11 }} width={36} />
                                    <Tooltip />
                                    <Legend />
                                    <Bar
                                        dataKey="planned"
                                        name="Planned"
                                        fill={CHART_COLORS[2]}
                                        radius={[3, 3, 0, 0]}
                                    />
                                    <Bar
                                        dataKey="produced"
                                        name="Produced"
                                        fill={CHART_COLORS[0]}
                                        radius={[3, 3, 0, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                    <div className="card overflow-hidden">
                        <div className="px-5 pt-5 pb-4">
                            <SectionHeader title="Production by Product">
                                <ExportCsvButton
                                    path="production/summary"
                                    params={dr.params}
                                />
                            </SectionHeader>
                        </div>
                        <TableWrapper>
                            <table className="w-full">
                                <thead>
                                    <tr className="border-y border-surface-100 bg-surface-50/50">
                                        <th className={TH}>Product</th>
                                        <th className={TH}>SKU</th>
                                        <th className={TH_R}>Orders</th>
                                        <th className={TH_R}>Planned</th>
                                        <th className={TH_R}>Produced</th>
                                        <th className={TH_R}>QC Failures</th>
                                        <th className={TH_R}>Avg Hours</th>
                                        <th className={TH_R}>Completion</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-50">
                                    {byProduct.length === 0 ? (
                                        <EmptyRow cols={8} />
                                    ) : (
                                        byProduct.map((row: any) => {
                                            const pct =
                                                row.units_planned > 0
                                                    ? Math.round(
                                                          (Number(
                                                              row.units_produced,
                                                          ) /
                                                              Number(
                                                                  row.units_planned,
                                                              )) *
                                                              100,
                                                      )
                                                    : 0;
                                            return (
                                                <tr
                                                    key={row.name_en}
                                                    className="hover:bg-surface-50/50 transition-colors"
                                                >
                                                    <td className="px-4 py-3 font-medium text-surface-900">
                                                        {row.name_en}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className="font-mono text-xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                                            {row.sku}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        {row.order_count}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        {row.units_planned}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        {row.units_produced}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        <span
                                                            className={clsx(
                                                                "text-sm",
                                                                row.qc_failures >
                                                                    0
                                                                    ? "text-danger font-medium"
                                                                    : "text-surface-400",
                                                            )}
                                                        >
                                                            {row.qc_failures}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                        {fmtHours(
                                                            row.avg_hours,
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-2">
                                                            <ProgressBar
                                                                value={pct}
                                                                max={100}
                                                                color={
                                                                    pct === 100
                                                                        ? "#10B981"
                                                                        : pct >
                                                                            50
                                                                          ? "#F59E0B"
                                                                          : "#EF4444"
                                                                }
                                                            />
                                                            <span
                                                                className={clsx(
                                                                    "text-xs font-medium tabular-nums w-8 text-right",
                                                                    pct === 100
                                                                        ? "text-success"
                                                                        : pct >
                                                                            50
                                                                          ? "text-warning"
                                                                          : "text-danger",
                                                                )}
                                                            >
                                                                {pct}%
                                                            </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </TableWrapper>
                    </div>
                </div>
            )}

            {/* ── TAILORS ── */}
            {activeTab === "tailors" && (
                <div className="card overflow-hidden">
                    <div className="px-5 pt-5 pb-4">
                        <SectionHeader title="Tailor Performance">
                            <ExportCsvButton
                                path="production/tailor-productivity"
                                params={dr.params}
                            />
                        </SectionHeader>
                    </div>
                    <TableWrapper>
                        <table className="w-full">
                            <thead>
                                <tr className="border-y border-surface-100 bg-surface-50/50">
                                    <th className={clsx(TH, "w-8")}>#</th>
                                    <th className={TH}>Tailor</th>
                                    <th className={TH_R}>Orders Completed</th>
                                    <th className={TH_R}>Units Produced</th>
                                    <th className={TH_R}>Avg Hours/Order</th>
                                    <th className={TH}>Output</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
                                {byTailor.length === 0 ? (
                                    <EmptyRow cols={6} />
                                ) : (
                                    byTailor.map((t: any, i: number) => (
                                        <tr
                                            key={t.id}
                                            className="hover:bg-surface-50/50 transition-colors"
                                        >
                                            <td className="px-4 py-3 text-surface-400 text-sm">
                                                {i + 1}
                                            </td>
                                            <td className="px-4 py-3 font-medium text-surface-900">
                                                {t.tailor_name}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums font-semibold">
                                                {t.completed_orders}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {t.units_produced}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                {fmtHours(
                                                    t.avg_hours_per_order,
                                                )}
                                            </td>
                                            <td
                                                className="px-4 py-3"
                                                style={{ width: 140 }}
                                            >
                                                <ProgressBar
                                                    value={Number(
                                                        t.units_produced,
                                                    )}
                                                    max={maxTailorUnits}
                                                    color={
                                                        CHART_COLORS[
                                                            i %
                                                                CHART_COLORS.length
                                                        ]
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </TableWrapper>
                </div>
            )}

            {/* ── COSTING & PROFITABILITY ── */}
            {activeTab === "intelligence" && (
                <IntelligenceTab start={dr.start} end={dr.end} />
            )}

            {activeTab === "costing" && (
                <CostingTab
                    data={costingData}
                    isLoading={costingLoading}
                    params={dr.params}
                />
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Costing Tab Component
// ─────────────────────────────────────────────────────────────────────────────

function fmtKes(n: number | null | undefined): string {
    if (n === null || n === undefined) return "—";
    return `KES ${Number(n).toLocaleString("en-KE", { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
}

function fmtPctLocal(n: number | null | undefined): string {
    if (n === null || n === undefined) return "—";
    return `${Number(n).toFixed(1)}%`;
}

function marginColor(m: number | null | undefined): string {
    if (m === null || m === undefined) return "text-surface-400";
    return m >= 30 ? "text-success" : m >= 10 ? "text-warning" : "text-danger";
}

function CostingTab({ data, isLoading, params }: {
    data: any;
    isLoading: boolean;
    params: Record<string, string | undefined>;
}) {
    const navigate = useNavigate();

    if (isLoading) return (
        <div className="flex justify-center py-20"><Spinner /></div>
    );

    if (!data) return (
        <div className="card p-8 text-center text-surface-400 text-sm">
            No data available for the selected period.
        </div>
    );

    const totals    = data.totals    ?? {};
    const orders    = data.orders    ?? [];
    const byProduct = data.by_product ?? [];

    const maxRevenue = Math.max(...byProduct.map((p: any) => Number(p.revenue)), 1);

    return (
        <div className="space-y-6">

            {/* ── Summary KPIs ── */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                <KpiCard
                    label="Total Revenue"
                    value={fmtKes(totals.total_revenue)}
                    color="text-brand-600"
                    sub={`${totals.order_count ?? 0} batches`}
                />
                <KpiCard
                    label="Total Material Cost"
                    value={fmtKes(totals.total_material_cost)}
                    color="text-warning"
                    sub={`${totals.total_qty_produced ?? 0} units produced`}
                />
                <KpiCard
                    label="Total Gross Profit"
                    value={fmtKes(totals.total_gross_profit)}
                    color={totals.total_gross_profit >= 0 ? "text-success" : "text-danger"}
                    sub={`${totals.profitable_count ?? 0} profitable batches`}
                />
                <KpiCard
                    label="Avg Gross Margin"
                    value={fmtPctLocal(totals.avg_gross_margin)}
                    color={marginColor(totals.avg_gross_margin)}
                    sub={`${totals.loss_count ?? 0} loss-making batches`}
                />
            </div>

            {/* ── By Product ── */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Profitability by Product">
                        <ExportCsvButton path="production/costing-summary" params={params} />
                    </SectionHeader>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-surface-100 bg-surface-50/50">
                                <th className={TH}>Product</th>
                                <th className={TH_R}>Batches</th>
                                <th className={TH_R}>Units Produced</th>
                                <th className={TH_R}>Material Cost</th>
                                <th className={TH_R}>Revenue</th>
                                <th className={TH_R}>Gross Profit</th>
                                <th className={TH_R}>Margin</th>
                                <th className={TH}>Revenue Share</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {byProduct.length === 0 ? (
                                <EmptyRow cols={8} />
                            ) : (
                                byProduct.map((p: any) => {
                                    const revShare = maxRevenue > 0
                                        ? Math.round((Number(p.revenue) / maxRevenue) * 100)
                                        : 0;
                                    return (
                                        <tr key={p.product_name}
                                            className="hover:bg-surface-50/50 transition-colors">
                                            <td className="px-4 py-3 font-medium text-surface-900">
                                                {p.product_name}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                {p.batch_count}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                {p.qty_produced}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-warning">
                                                {fmtKes(p.material_cost)}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums font-medium text-surface-900">
                                                {fmtKes(p.revenue)}
                                            </td>
                                            <td className={clsx(
                                                "px-4 py-3 text-right tabular-nums font-semibold",
                                                p.gross_profit >= 0 ? "text-success" : "text-danger",
                                            )}>
                                                {fmtKes(p.gross_profit)}
                                            </td>
                                            <td className={clsx(
                                                "px-4 py-3 text-right tabular-nums font-semibold text-sm",
                                                marginColor(p.gross_margin),
                                            )}>
                                                {fmtPctLocal(p.gross_margin)}
                                            </td>
                                            <td className="px-4 py-3" style={{ width: 120 }}>
                                                <ProgressBar
                                                    value={revShare}
                                                    max={100}
                                                    color={p.gross_profit >= 0 ? "#10B981" : "#EF4444"}
                                                />
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                        {byProduct.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 border-surface-200 bg-surface-50 font-semibold">
                                    <td className="px-4 py-3 text-sm text-surface-700">Totals</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-sm">{totals.order_count}</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-sm">{totals.total_qty_produced}</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-sm text-warning">{fmtKes(totals.total_material_cost)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-sm">{fmtKes(totals.total_revenue)}</td>
                                    <td className={clsx(
                                        "px-4 py-3 text-right tabular-nums text-sm",
                                        totals.total_gross_profit >= 0 ? "text-success" : "text-danger",
                                    )}>
                                        {fmtKes(totals.total_gross_profit)}
                                    </td>
                                    <td className={clsx("px-4 py-3 text-right tabular-nums text-sm", marginColor(totals.avg_gross_margin))}>
                                        {fmtPctLocal(totals.avg_gross_margin)}
                                    </td>
                                    <td />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </TableWrapper>
            </div>

            {/* ── Per-batch orders ── */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="All Batches" />
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-surface-100 bg-surface-50/50">
                                <th className={TH}>Batch</th>
                                <th className={TH}>Product</th>
                                <th className={TH_R}>Produced</th>
                                <th className={TH_R}>Sold</th>
                                <th className={TH_R}>Material Cost</th>
                                <th className={TH_R}>Revenue</th>
                                <th className={TH_R}>Gross Profit</th>
                                <th className={TH_R}>Margin</th>
                                <th className={TH}>Type</th>
                                <th className={TH}></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {orders.length === 0 ? (
                                <EmptyRow cols={10} />
                            ) : (
                                orders.map((row: any) => (
                                    <tr key={row.id}
                                        className="hover:bg-surface-50/50 transition-colors">
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                                {row.batch_number}
                                            </span>
                                            {row.outlet_name && (
                                                <p className="text-xs text-surface-400 mt-0.5">{row.outlet_name}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-surface-900 text-sm">{row.product_name}</p>
                                            {row.variant_name && (
                                                <p className="text-xs text-surface-400">{row.variant_name}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-sm text-surface-600">
                                            {row.qty_produced}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-sm text-surface-600">
                                            {row.qty_sold > 0 ? row.qty_sold : (
                                                <span className="text-surface-300">—</span>
                                            )}
                                            {row.qty_remaining > 0 && (
                                                <span className="ml-1 text-xs text-warning">
                                                    ({row.qty_remaining} left)
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-sm text-warning">
                                            {fmtKes(row.material_cost)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-sm font-medium text-surface-900">
                                            {row.revenue > 0 ? fmtKes(row.revenue) : (
                                                <span className="text-surface-300 font-normal text-xs">No sales linked</span>
                                            )}
                                        </td>
                                        <td className={clsx(
                                            "px-4 py-3 text-right tabular-nums text-sm font-semibold",
                                            row.revenue > 0
                                                ? (row.is_profitable ? "text-success" : "text-danger")
                                                : "text-surface-300",
                                        )}>
                                            {row.revenue > 0 ? fmtKes(row.gross_profit) : "—"}
                                        </td>
                                        <td className={clsx(
                                            "px-4 py-3 text-right tabular-nums text-sm font-semibold",
                                            marginColor(row.gross_margin),
                                        )}>
                                            {row.gross_margin !== null ? fmtPctLocal(row.gross_margin) : "—"}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={clsx(
                                                "text-2xs font-semibold px-1.5 py-0.5 rounded uppercase tracking-wide",
                                                row.is_customer_order
                                                    ? "bg-indigo-50 text-indigo-600"
                                                    : "bg-surface-100 text-surface-500",
                                            )}>
                                                {row.is_customer_order ? "Make-to-Order" : "Stock"}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <button
                                                onClick={() => navigate(`/reports/production/costing/${row.id}`)}
                                                className="text-xs text-brand-600 hover:text-brand-700 font-medium whitespace-nowrap"
                                            >
                                                Full report →
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>
        </div>
    );
}

// ─── Intelligence tab ─────────────────────────────────────────────────────────
// MetricEngine-backed floor intelligence: which stages run over their
// estimates, where pieces pile up right now, whether next week's promises fit
// the floor's actual pace, QC truth, benches, and live material demand.

function IntelligenceTab({ start, end }: { start: string; end: string }) {
    const { data, isLoading } = useQuery({
        queryKey: ["production-intelligence", start, end],
        queryFn: () => reportsApi.productionIntelligence(start, end),
        enabled: !!start && !!end,
        staleTime: 60_000,
    });

    if (isLoading || !data)
        return <div className="flex justify-center py-16"><Spinner /></div>;

    const { cycle_times, bottlenecks, tailors, qc, capacity, materials } = data;
    const maxHeld = Math.max(...bottlenecks.map((b: any) => Number(b.held_pieces)), 1);
    const short = Number(capacity.shortfall) > 0;

    return (
        <div className="space-y-6">
            {/* Capacity: the one sentence that plans next week */}
            <div className={clsx("card card-body border", short ? "border-red-200 bg-red-50/40" : "border-emerald-200 bg-emerald-50/30")}>
                <SectionHeader title="Capacity — next 7 days" />
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-1">
                    <KpiCard label="Pieces Due" value={capacity.due_pieces} sub={`${capacity.due_orders} orders`} />
                    <KpiCard label="Floor Pace" value={`${capacity.daily_throughput}/day`} sub="last 14 days, actual" />
                    <KpiCard label="Week Capacity" value={capacity.week_capacity} sub="at current pace" />
                    <KpiCard label={short ? "Shortfall" : "Headroom"}
                        value={short ? capacity.shortfall : Math.round((capacity.week_capacity - capacity.due_pieces) * 10) / 10}
                        color={short ? "text-danger" : "text-success"}
                        sub={short ? "pieces at risk — add hands or renegotiate dates" : "pieces to spare"} />
                </div>
            </div>

            {/* Bottlenecks now */}
            <div className="card card-body">
                <SectionHeader title="Where pieces are piling up (right now)" />
                {bottlenecks.length === 0 ? (
                    <p className="text-xs text-surface-400 py-4">No held pieces — the pipeline is flowing.</p>
                ) : (
                    <div className="space-y-2 mt-2">
                        {bottlenecks.map((b: any) => (
                            <div key={b.stage} className="flex items-center gap-3">
                                <span className="text-xs font-medium text-surface-700 w-28 truncate shrink-0">{b.stage}</span>
                                <div className="flex-1 h-3 bg-surface-100 rounded-full overflow-hidden">
                                    <div className={clsx("h-full rounded-full",
                                        Number(b.held_pieces) === maxHeld ? "bg-red-500" : "bg-amber-400")}
                                        style={{ width: `${(Number(b.held_pieces) / maxHeld) * 100}%` }} />
                                </div>
                                <span className="text-xs font-bold tabular-nums text-surface-800 w-20 text-right shrink-0">
                                    {b.held_pieces} pcs
                                </span>
                                <span className="text-2xs text-surface-400 w-24 shrink-0">
                                    {b.active_tasks} active / {b.open_tasks} open
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Stage cycle times */}
            <div className="card card-body">
                <SectionHeader title="Stage cycle times (completed in period)" />
                {cycle_times.length === 0 ? (
                    <p className="text-xs text-surface-400 py-4">No stages completed in this period.</p>
                ) : (
                    <TableWrapper>
                        <table className="w-full text-xs">
                            <thead><tr>
                                <th className={TH}>Stage</th>
                                <th className={TH_R}>Tasks</th>
                                <th className={TH_R}>Avg Actual</th>
                                <th className={TH_R}>Avg Estimate</th>
                                <th className={TH_R}>Ran Over</th>
                            </tr></thead>
                            <tbody className="divide-y divide-surface-50">
                                {cycle_times.map((c: any) => {
                                    const overPct = c.with_estimate > 0 ? Math.round((c.over_estimate / c.with_estimate) * 100) : null;
                                    return (
                                        <tr key={c.stage}>
                                            <td className="px-3 py-2 font-medium text-surface-800">{c.stage}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{c.tasks}</td>
                                            <td className="px-3 py-2 text-right tabular-nums font-semibold">{c.avg_hours != null ? fmtHours(Number(c.avg_hours)) : "—"}</td>
                                            <td className="px-3 py-2 text-right tabular-nums text-surface-500">{c.avg_est_hours != null ? fmtHours(Number(c.avg_est_hours)) : "—"}</td>
                                            <td className={clsx("px-3 py-2 text-right tabular-nums font-semibold",
                                                overPct != null && overPct > 50 ? "text-danger" : overPct != null && overPct > 0 ? "text-warning-dark" : "text-surface-400")}>
                                                {overPct != null ? `${overPct}%` : "—"}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </TableWrapper>
                )}
            </div>

            {/* QC + Tailors */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div className="card card-body">
                    <SectionHeader title="Quality control" />
                    {qc.checks === 0 ? (
                        <p className="text-xs text-surface-400 py-4">No QC checks recorded in this period.</p>
                    ) : (
                        <div className="grid grid-cols-2 gap-3 mt-1">
                            <KpiCard label="Pass Rate" value={`${qc.pass_rate}%`}
                                color={Number(qc.pass_rate) >= 90 ? "text-success" : Number(qc.pass_rate) >= 70 ? "text-warning" : "text-danger"}
                                sub={`${qc.checks} checks`} />
                            <KpiCard label="Rework Pieces" value={qc.pieces_failed}
                                color={qc.pieces_failed > 0 ? "text-danger" : "text-success"}
                                sub={`${qc.pieces_passed} passed`} />
                        </div>
                    )}
                </div>
                <div className="card card-body">
                    <SectionHeader title="Bench throughput (period)" />
                    {tailors.length === 0 ? (
                        <p className="text-xs text-surface-400 py-4">No completed assigned tasks in this period.</p>
                    ) : (
                        <div className="space-y-1.5 mt-1">
                            {tailors.map((t: any, i: number) => (
                                <div key={t.tailor} className="flex items-center gap-2.5">
                                    <span className="text-2xs font-bold text-surface-300 w-4">{i + 1}</span>
                                    <span className="text-xs font-medium text-surface-800 flex-1 truncate">{t.tailor}</span>
                                    <span className="text-2xs text-surface-400">{t.tasks} stages{t.avg_hours != null ? ` · ${fmtHours(Number(t.avg_hours))} avg` : ""}</span>
                                    <span className="text-xs font-bold tabular-nums text-surface-800 w-14 text-right">{t.pieces} pcs</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Material demand */}
            <div className="card card-body">
                <SectionHeader title="Live material demand (open orders)" />
                {materials.length === 0 ? (
                    <p className="text-xs text-surface-400 py-4">No material allocations on open orders.</p>
                ) : (
                    <TableWrapper>
                        <table className="w-full text-xs">
                            <thead><tr>
                                <th className={TH}>Material</th>
                                <th className={TH_R}>Required</th>
                                <th className={TH_R}>Allocated</th>
                                <th className={TH_R}>Used</th>
                                <th className={TH_R}>Still Needed</th>
                            </tr></thead>
                            <tbody className="divide-y divide-surface-50">
                                {materials.map((m: any) => {
                                    const gap = Math.max(0, Number(m.required) - Number(m.allocated));
                                    return (
                                        <tr key={m.material}>
                                            <td className="px-3 py-2">
                                                <p className="font-medium text-surface-800">{m.material}</p>
                                                <p className="text-2xs text-surface-400">{m.unit}</p>
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">{Number(m.required).toLocaleString()}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{Number(m.allocated).toLocaleString()}</td>
                                            <td className="px-3 py-2 text-right tabular-nums text-surface-500">{Number(m.used).toLocaleString()}</td>
                                            <td className={clsx("px-3 py-2 text-right tabular-nums font-semibold", gap > 0 ? "text-danger" : "text-success")}>
                                                {gap > 0 ? gap.toLocaleString() : "✓ covered"}
                                            </td>
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
