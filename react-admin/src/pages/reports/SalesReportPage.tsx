// src/pages/reports/SalesReportPage.tsx
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { reportsApi } from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
import {
    LineChart,
    Line,
    BarChart,
    Bar,
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
import { clsx } from "clsx";
import dayjs from "dayjs";
import {
    KpiCard,
    ReportPdfButton,
    SectionHeader,
    TableWrapper,
    EmptyRow,
    ReportActionBar,
    ExportCsvButton,
    DateRangePicker,
    ReportPageHeader,
    useDateRange,
    ComparisonToggle,
    ProgressBar,
    CHART_COLORS,
    TH,
    TH_R,
    fmtPct,
    ChangeBadge,
} from "./reportShared";

const DOW_LABELS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

export default function SalesReportPage() {
    const dr = useDateRange("last_30_days");
    const [compare, setCompare] = useState(false);
    const [activeTab, setActiveTab] = useState<
        "overview" | "products" | "customers" | "channels" | "patterns"
    >("overview");

    const summaryQuery = useQuery({
        queryKey: ["report-sales-summary", dr.start, dr.end, compare],
        queryFn: () =>
            reportsApi.salesSummary({
                ...dr.params,
                compare: compare ? 1 : undefined,
            }),
        enabled: !!dr.start && !!dr.end,
    });
    const byProductQuery = useQuery({
        queryKey: ["report-sales-products", dr.start, dr.end],
        queryFn: () => reportsApi.salesByProduct({ ...dr.params, limit: 30 }),
        enabled: !!dr.start && !!dr.end,
    });
    const byCatQuery = useQuery({
        queryKey: ["report-sales-category", dr.start, dr.end],
        queryFn: () => reportsApi.salesByCategory(dr.params),
        enabled: !!dr.start && !!dr.end,
    });
    const byCustomerQuery = useQuery({
        queryKey: ["report-sales-customers", dr.start, dr.end],
        queryFn: () => reportsApi.salesByCustomer({ ...dr.params, limit: 25 }),
        enabled: !!dr.start && !!dr.end && activeTab === "customers",
    });
    const returnsQuery = useQuery({
        queryKey: ["report-sales-returns", dr.start, dr.end],
        queryFn: () => reportsApi.salesReturns(dr.params),
        enabled: !!dr.start && !!dr.end,
    });

    const s = summaryQuery.data?.summary ?? {};
    const daily = (summaryQuery.data?.daily_breakdown ?? []).map((d: any) => ({
        ...d,
        revenue: Number(d.revenue),
    }));
    const pmData = summaryQuery.data?.by_payment_method ?? [];
    const pmTotal = pmData.reduce(
        (a: number, x: any) => a + Number(x.total),
        0,
    );
    const cmp = summaryQuery.data?.comparison;
    const hourly = summaryQuery.data?.by_hour ?? [];
    const byDow = summaryQuery.data?.by_day_of_week ?? [];

    if (summaryQuery.isLoading)
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );

    const maxRevProduct = Math.max(
        ...(byProductQuery.data?.products ?? []).map((p: any) =>
            Number(p.total_revenue),
        ),
        1,
    );

    return (
        <div className="space-y-6 animate-fade-in">
            <ReportPageHeader
                title="Sales Report"
                subtitle="Revenue, orders, products, channels, and patterns."
                reportType="sales"
                exportPath="sales/summary"
                params={dr.params}
                preset={dr.preset}
                start={dr.start}
                end={dr.end}
                onPresetChange={dr.handlePreset}
                onStartChange={dr.setStart}
                onEndChange={dr.setEnd}
                compare={compare}
                onCompareChange={setCompare}
            />

            {/* KPIs row 1 */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                <KpiCard
                    label="Total Revenue"
                    value={fmtKes(s.total_revenue)}
                    sub={`${s.total_orders ?? 0} paid orders`}
                    comparison={cmp?.revenue_change_pct}
                />
                <KpiCard
                    label="Avg Order Value"
                    value={fmtKes(s.average_order_value)}
                    comparison={cmp?.aov_change_pct}
                />
                <KpiCard
                    label="Unique Customers"
                    value={s.unique_customers ?? 0}
                    sub="Placed ≥ 1 order"
                />
                <KpiCard
                    label="Discount Rate"
                    value={`${Number(s.discount_rate_percent ?? 0).toFixed(1)}%`}
                    sub={`${fmtKes(s.total_discounts)} given`}
                />
            </div>

            {/* KPIs row 2 */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                <KpiCard
                    label="Online Revenue"
                    value={fmtKes(s.online_revenue)}
                    sub={`${s.online_count ?? 0} orders`}
                />
                <KpiCard
                    label="POS Revenue"
                    value={fmtKes(s.pos_revenue)}
                    sub={`${s.pos_count ?? 0} orders`}
                />
                <KpiCard label="Tax Collected" value={fmtKes(s.total_tax)} />
                <KpiCard
                    label="Min / Max Order"
                    value={`${fmtKes(s.min_order_value)} / ${fmtKes(s.max_order_value)}`}
                />
            </div>

            {/* Tabs */}
            <div className="border-b border-surface-100 overflow-x-auto no-scrollbar">
                <nav className="flex gap-1 -mb-px">
                    {(
                        [
                            "overview",
                            "products",
                            "customers",
                            "channels",
                            "patterns",
                        ] as const
                    ).map((tab) => (
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
                    ))}
                </nav>
            </div>

            {/* ── OVERVIEW TAB ── */}
            {activeTab === "overview" && (
                <div className="space-y-6">
                    {/* Daily revenue chart */}
                    {daily.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Daily Revenue">
                                <ChangeBadge pct={cmp?.revenue_change_pct} />
                            </SectionHeader>
                            <ResponsiveContainer width="100%" height={280}>
                                <LineChart data={daily}>
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="#F1F5F9"
                                    />
                                    <XAxis
                                        dataKey="date"
                                        tickFormatter={(d: string) =>
                                            dayjs(d).format("D MMM")
                                        }
                                        tick={{ fontSize: 11 }}
                                    />
                                    <YAxis
                                        yAxisId="revenue"
                                        tickFormatter={(v: number) =>
                                            `${(v / 1000).toFixed(0)}K`
                                        }
                                        tick={{ fontSize: 11 }}
                                        width={48}
                                    />
                                    <YAxis
                                        yAxisId="orders"
                                        orientation="right"
                                        tick={{ fontSize: 11 }}
                                        width={36}
                                    />
                                    <Tooltip
                                        formatter={(v, name) =>
                                            name === "Revenue"
                                                ? fmtKes(v as number)
                                                : v
                                        }
                                        labelFormatter={(l) =>
                                            dayjs(String(l ?? "")).format(
                                                "D MMM YYYY",
                                            )
                                        }
                                    />
                                    <Legend />
                                    <Line
                                        yAxisId="revenue"
                                        type="monotone"
                                        dataKey="revenue"
                                        stroke={CHART_COLORS[0]}
                                        strokeWidth={2}
                                        dot={false}
                                        name="Revenue"
                                    />
                                    <Line
                                        yAxisId="orders"
                                        type="monotone"
                                        dataKey="orders"
                                        stroke={CHART_COLORS[4]}
                                        strokeWidth={1.5}
                                        dot={false}
                                        name="Orders"
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}

                    {/* Category + payment methods */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {(byCatQuery.data?.categories ?? []).length > 0 && (
                            <div className="card p-5">
                                <SectionHeader title="Revenue by Category" />
                                <ResponsiveContainer width="100%" height={220}>
                                    <PieChart>
                                        <Pie
                                            data={byCatQuery.data.categories}
                                            dataKey="total_revenue"
                                            nameKey="category_name"
                                            cx="50%"
                                            cy="50%"
                                            outerRadius={80}
                                            label={({
                                                category_name,
                                                percent,
                                            }: any) =>
                                                `${category_name} ${(percent * 100).toFixed(0)}%`
                                            }
                                            labelLine={false}
                                        >
                                            {byCatQuery.data.categories.map(
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
                                            formatter={(v) =>
                                                fmtKes(v as number)
                                            }
                                        />
                                    </PieChart>
                                </ResponsiveContainer>
                            </div>
                        )}

                        {pmData.length > 0 && (
                            <div className="card p-5">
                                <SectionHeader title="Payment Methods" />
                                <div className="space-y-3 mt-2">
                                    {pmData.map((pm: any, i: number) => {
                                        const pct =
                                            pmTotal > 0
                                                ? Math.round(
                                                      (Number(pm.total) /
                                                          pmTotal) *
                                                          100,
                                                  )
                                                : 0;
                                        return (
                                            <div key={pm.payment_method}>
                                                <div className="flex justify-between text-sm mb-1">
                                                    <span className="capitalize text-surface-700">
                                                        {pm.payment_method?.replace(
                                                            /_/g,
                                                            " ",
                                                        )}
                                                    </span>
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs text-surface-400">
                                                            {pm.count} orders
                                                        </span>
                                                        <span className="font-medium tabular-nums">
                                                            {fmtKes(pm.total)}
                                                        </span>
                                                    </div>
                                                </div>
                                                <ProgressBar
                                                    value={pm.total}
                                                    max={pmTotal}
                                                    color={
                                                        CHART_COLORS[
                                                            i %
                                                                CHART_COLORS.length
                                                        ]
                                                    }
                                                />
                                                <p className="text-xs text-surface-400 mt-0.5">
                                                    {pct}% of total
                                                </p>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Sales by outlet */}
                    <SalesByOutletTable params={dr.params} />

                    {/* Returns summary */}
                    {returnsQuery.data?.summary && (
                        <div className="card p-5">
                            <SectionHeader title="Returns & Refunds">
                                <ExportCsvButton
                                    path="sales/returns"
                                    params={dr.params}
                                />
                            </SectionHeader>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <KpiCard
                                    label="Total Returns"
                                    value={
                                        returnsQuery.data.summary
                                            .total_returns ?? 0
                                    }
                                    color="text-danger"
                                />
                                <KpiCard
                                    label="Total Refunded"
                                    value={fmtKes(
                                        returnsQuery.data.summary
                                            .total_refunded,
                                    )}
                                    color="text-danger"
                                />
                                <KpiCard
                                    label="Avg Refund"
                                    value={fmtKes(
                                        returnsQuery.data.summary.avg_refund,
                                    )}
                                />
                                <KpiCard
                                    label="Customers Affected"
                                    value={
                                        returnsQuery.data.summary
                                            .unique_customers ?? 0
                                    }
                                />
                            </div>
                            {(returnsQuery.data.by_reason ?? []).length > 0 && (
                                <div className="mt-4">
                                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                                        By Reason
                                    </p>
                                    <div className="space-y-2">
                                        {returnsQuery.data.by_reason.map(
                                            (r: any) => (
                                                <div
                                                    key={r.reason}
                                                    className="flex items-center justify-between text-sm"
                                                >
                                                    <span className="text-surface-700 capitalize">
                                                        {r.reason}
                                                    </span>
                                                    <div className="flex items-center gap-3">
                                                        <span className="text-surface-500">
                                                            {r.count} returns
                                                        </span>
                                                        <span className="font-medium text-danger tabular-nums">
                                                            {fmtKes(
                                                                r.total_refunded,
                                                            )}
                                                        </span>
                                                    </div>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* ── PRODUCTS TAB ── */}
            {activeTab === "products" && (
                <div className="space-y-6">
                    <div className="card overflow-hidden">
                        <div className="px-5 pt-5 pb-4">
                            <SectionHeader title="Top Products by Revenue">
                                <ExportCsvButton
                                    path="sales/by-product"
                                    params={dr.params}
                                />
                            </SectionHeader>
                        </div>
                        <TableWrapper>
                            <table className="w-full">
                                <thead>
                                    <tr className="border-y border-surface-100 bg-surface-50/50">
                                        <th className={clsx(TH, "w-8")}>#</th>
                                        <th className={TH}>Product</th>
                                        <th className={TH}>SKU</th>
                                        <th className={TH_R}>Units</th>
                                        <th className={TH_R}>Orders</th>
                                        <th className={TH_R}>Customers</th>
                                        <th className={TH_R}>Discounts</th>
                                        <th className={TH_R}>Revenue</th>
                                        <th
                                            className={TH}
                                            style={{ width: 120 }}
                                        >
                                            Share
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-50">
                                    {byProductQuery.isLoading ? (
                                        <tr>
                                            <td
                                                colSpan={9}
                                                className="px-4 py-8 text-center"
                                            >
                                                <Spinner />
                                            </td>
                                        </tr>
                                    ) : (byProductQuery.data?.products ?? [])
                                          .length === 0 ? (
                                        <EmptyRow cols={9} />
                                    ) : (
                                        (
                                            byProductQuery.data.products ?? []
                                        ).map((p: any, i: number) => (
                                            <tr
                                                key={p.id}
                                                className="hover:bg-surface-50/50 transition-colors"
                                            >
                                                <td className="px-4 py-3 text-surface-400 text-sm">
                                                    {i + 1}
                                                </td>
                                                <td className="px-4 py-3 font-medium text-surface-900">
                                                    {p.name_en}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="font-mono text-xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                                        {p.sku}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {p.units_sold}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {p.order_count}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-500">
                                                    {p.unique_customers}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-400 text-sm">
                                                    {p.total_discounts > 0
                                                        ? `(${fmtKes(p.total_discounts)})`
                                                        : "-"}
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                    {fmtKes(p.total_revenue)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <ProgressBar
                                                        value={Number(
                                                            p.total_revenue,
                                                        )}
                                                        max={maxRevProduct}
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

                    {/* Category table */}
                    {(byCatQuery.data?.categories ?? []).length > 0 && (
                        <div className="card overflow-hidden">
                            <div className="px-5 pt-5 pb-4">
                                <SectionHeader title="Revenue by Category">
                                    <ExportCsvButton
                                        path="sales/by-category"
                                        params={dr.params}
                                    />
                                </SectionHeader>
                            </div>
                            <TableWrapper>
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-y border-surface-100 bg-surface-50/50">
                                            <th className={TH}>Category</th>
                                            <th className={TH_R}>Products</th>
                                            <th className={TH_R}>Units Sold</th>
                                            <th className={TH_R}>Orders</th>
                                            <th className={TH_R}>Avg Price</th>
                                            <th className={TH_R}>Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {byCatQuery.data.categories.map(
                                            (c: any) => (
                                                <tr
                                                    key={c.id}
                                                    className="hover:bg-surface-50/50 transition-colors"
                                                >
                                                    <td className="px-4 py-3 font-medium text-surface-900">
                                                        {c.category_name}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        {c.product_count}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        {c.units_sold}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        {c.order_count}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                        {fmtKes(c.avg_price)}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                        {fmtKes(
                                                            c.total_revenue,
                                                        )}
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </TableWrapper>
                        </div>
                    )}
                </div>
            )}


            {/* ── CUSTOMERS TAB ── */}
            {activeTab === "customers" && (
                <div className="space-y-6">
                    <div className="card overflow-hidden">
                        <div className="px-5 pt-5 pb-4">
                            <SectionHeader title="Top Customers by Revenue">
                                <ExportCsvButton
                                    path="sales/by-customer"
                                    params={dr.params}
                                />
                            </SectionHeader>
                        </div>
                        <TableWrapper>
                            <table className="w-full">
                                <thead>
                                    <tr className="border-y border-surface-100 bg-surface-50/50">
                                        <th className={clsx(TH, "w-8")}>#</th>
                                        <th className={TH}>Customer</th>
                                        <th className={TH}>Email</th>
                                        <th className={TH_R}>Orders</th>
                                        <th className={TH_R}>Avg Order</th>
                                        <th className={TH_R}>Revenue</th>
                                        <th className={TH} style={{ width: 120 }}>Share</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-50">
                                    {byCustomerQuery.isLoading ? (
                                        <tr><td colSpan={7} className="px-4 py-8 text-center"><Spinner /></td></tr>
                                    ) : (byCustomerQuery.data?.customers ?? []).length === 0 ? (
                                        <EmptyRow cols={7} />
                                    ) : (() => {
                                        const customers = byCustomerQuery.data.customers ?? [];
                                        const maxRev = Math.max(...customers.map((c: any) => Number(c.total_revenue ?? c.total_spent ?? 0)), 1);
                                        return customers.map((c: any, i: number) => {
                                            const rev = Number(c.total_revenue ?? c.total_spent ?? 0);
                                            return (
                                                <tr key={c.customer_email ?? i} className="hover:bg-surface-50/50 transition-colors">
                                                    <td className="px-4 py-3 text-surface-400 text-sm">{i + 1}</td>
                                                    <td className="px-4 py-3 font-medium text-surface-900">
                                                        {[c.customer_first_name, c.customer_last_name].filter(Boolean).join(" ") || c.name || "Guest"}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-surface-500">{c.customer_email ?? "—"}</td>
                                                    <td className="px-4 py-3 text-right tabular-nums">{c.order_count}</td>
                                                    <td className="px-4 py-3 text-right tabular-nums text-surface-600">{fmtKes(c.avg_order_value)}</td>
                                                    <td className="px-4 py-3 text-right font-semibold tabular-nums">{fmtKes(rev)}</td>
                                                    <td className="px-4 py-3"><ProgressBar value={rev} max={maxRev} color={CHART_COLORS[0]} /></td>
                                                </tr>
                                            );
                                        });
                                    })()}
                                </tbody>
                            </table>
                        </TableWrapper>
                    </div>
                </div>
            )}

            {/* ── CHANNELS TAB ── */}
            {activeTab === "channels" && (
                <div className="space-y-6">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="card p-5">
                            <p className="text-xs text-surface-500 mb-1">
                                Online
                            </p>
                            <p className="text-2xl font-bold text-surface-900">
                                {fmtKes(s.online_revenue)}
                            </p>
                            <p className="text-sm text-surface-500 mt-1">
                                {s.online_count ?? 0} orders · avg{" "}
                                {fmtKes(
                                    (s.online_revenue ?? 0) /
                                        Math.max(1, s.online_count ?? 1),
                                )}
                            </p>
                        </div>
                        <div className="card p-5">
                            <p className="text-xs text-surface-500 mb-1">
                                Point of Sale
                            </p>
                            <p className="text-2xl font-bold text-surface-900">
                                {fmtKes(s.pos_revenue)}
                            </p>
                            <p className="text-sm text-surface-500 mt-1">
                                {s.pos_count ?? 0} orders · avg{" "}
                                {fmtKes(
                                    (s.pos_revenue ?? 0) /
                                        Math.max(1, s.pos_count ?? 1),
                                )}
                            </p>
                        </div>
                    </div>
                    <SalesByOutletTable params={dr.params} />
                </div>
            )}

            {/* ── PATTERNS TAB ── */}
            {activeTab === "patterns" && (
                <div className="space-y-6">
                    {/* Hourly heatmap */}
                    {hourly.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Orders by Hour of Day" />
                            <div className="flex gap-1 mt-2 flex-wrap">
                                {Array.from({ length: 24 }, (_, h) => {
                                    const d = hourly.find(
                                        (x: any) => x.hour === h,
                                    );
                                    const maxOrders = Math.max(
                                        ...hourly.map((x: any) => x.orders),
                                        1,
                                    );
                                    const intensity = d
                                        ? Math.round(
                                              (d.orders / maxOrders) * 9,
                                          ) + 1
                                        : 0;
                                    return (
                                        <div
                                            key={h}
                                            className="flex flex-col items-center gap-1"
                                        >
                                            <div
                                                className={clsx(
                                                    "w-8 h-8 rounded flex items-center justify-center text-xs font-mono transition-colors",
                                                    intensity === 0
                                                        ? "bg-surface-50 text-surface-300"
                                                        : intensity <= 3
                                                          ? "bg-brand-100 text-brand-600"
                                                          : intensity <= 6
                                                            ? "bg-brand-300 text-white"
                                                            : "bg-brand-600 text-white",
                                                )}
                                                title={`${h}:00 - ${d?.orders ?? 0} orders, ${fmtKes(d?.revenue)}`}
                                            >
                                                {d?.orders ?? 0}
                                            </div>
                                            <span className="text-xs text-surface-400">
                                                {h}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                            <p className="text-xs text-surface-400 mt-2">
                                Darker = more orders. Hover for detail.
                            </p>
                        </div>
                    )}

                    {/* Day of week */}
                    {byDow.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Orders by Day of Week" />
                            <ResponsiveContainer width="100%" height={200}>
                                <BarChart
                                    data={byDow.map((d: any) => ({
                                        ...d,
                                        day:
                                            DOW_LABELS[d.dow] ??
                                            d.day_name?.trim(),
                                    }))}
                                >
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="#F1F5F9"
                                    />
                                    <XAxis
                                        dataKey="day"
                                        tick={{ fontSize: 12 }}
                                    />
                                    <YAxis tick={{ fontSize: 11 }} width={40} />
                                    <Tooltip
                                        formatter={(v: any) => [v, "Orders"]}
                                    />
                                    <Bar
                                        dataKey="orders"
                                        fill={CHART_COLORS[0]}
                                        radius={[3, 3, 0, 0]}
                                        name="Orders"
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ── Sub-component ─────────────────────────────────────────────────────────────

function SalesByOutletTable({ params }: { params: Record<string, any> }) {
    const { data, isLoading } = useQuery({
        queryKey: ["report-sales-outlet", params.start_date, params.end_date],
        queryFn: () => reportsApi.salesByOutlet(params),
        enabled: !!params.start_date && !!params.end_date,
    });

    if (isLoading || !(data?.outlets ?? []).length) return null;

    return (
        <div className="card overflow-hidden">
            <div className="px-5 pt-5 pb-4">
                <SectionHeader title="Sales by Outlet">
                    <ExportCsvButton path="sales/by-outlet" params={params} />
                </SectionHeader>
            </div>
            <TableWrapper>
                <table className="w-full">
                    <thead>
                        <tr className="border-y border-surface-100 bg-surface-50/50">
                            <th className={TH}>Outlet</th>
                            <th className={TH}>City</th>
                            <th className={TH_R}>Orders</th>
                            <th className={TH_R}>Avg Daily Rev</th>
                            <th className={TH_R}>Avg Order</th>
                            <th className={TH_R}>Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-surface-50">
                        {data.outlets.map((o: any) => (
                            <tr
                                key={o.outlet_id}
                                className="hover:bg-surface-50/50 transition-colors"
                            >
                                <td className="px-4 py-3 font-medium text-surface-900">
                                    {o.outlet_name}
                                </td>
                                <td className="px-4 py-3 text-sm text-surface-500">
                                    {o.city ?? "-"}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums">
                                    {o.order_count}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                    {fmtKes(o.avg_daily_revenue)}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                    {fmtKes(o.avg_order_value)}
                                </td>
                                <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                    {fmtKes(o.total_revenue)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableWrapper>
        </div>
    );
}