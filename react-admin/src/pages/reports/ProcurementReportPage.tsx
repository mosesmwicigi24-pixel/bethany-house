// src/pages/reports/ProcurementReportPage.tsx
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { reportsApi } from "@/api/reports";
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

export default function ProcurementReportPage() {
    const dr = useDateRange("this_month");
    const [activeTab, setActiveTab] = useState<
        "overview" | "suppliers" | "items"
    >("overview");

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
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
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
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
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
            <div className="border-b border-surface-100 overflow-x-auto no-scrollbar">
                <nav className="flex gap-1 -mb-px">
                    {(["overview", "suppliers", "items"] as const).map(
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
                                        stroke="#F1F5F9"
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
                                        stroke="#F1F5F9"
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
                                    <tr className="border-y border-surface-100 bg-surface-50/50">
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
                                <tbody className="divide-y divide-surface-50">
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
                                <tr className="border-y border-surface-100 bg-surface-50/50">
                                    <th className={clsx(TH, "w-8")}>#</th>
                                    <th className={TH}>Product</th>
                                    <th className={TH}>SKU</th>
                                    <th className={TH_R}>POs</th>
                                    <th className={TH_R}>Total Qty</th>
                                    <th className={TH_R}>Total Spend</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
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