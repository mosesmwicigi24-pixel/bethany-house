// src/pages/reports/SalesReportPage.tsx
import React, { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
    reportsApi,
    type SalesLedger,
    type LedgerBucket,
    type NeemaSalesReport,
} from "@/api/reports";
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
    KPI_GRID,
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
        "overview" | "products" | "customers" | "channels" | "patterns" | "neema"
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
    // Sales / paid / balance per channel and per day, week and month. Loaded
    // only on the Channels tab — it is three grouped aggregations and there is
    // no reason to pay for them while the user is reading Overview.
    const ledgerQuery = useQuery({
        queryKey: ["report-sales-ledger", dr.start, dr.end],
        queryFn: () => reportsApi.salesLedger(dr.params),
        enabled: !!dr.start && !!dr.end && activeTab === "channels",
    });

    const returnsQuery = useQuery({
        queryKey: ["report-sales-returns", dr.start, dr.end],
        queryFn: () => reportsApi.salesReturns(dr.params),
        enabled: !!dr.start && !!dr.end,
    });

    // Neema (AI agent) performance — loaded only on its tab.
    const neemaQuery = useQuery({
        queryKey: ["report-sales-neema", dr.start, dr.end],
        queryFn: () => reportsApi.salesNeema(dr.params),
        enabled: !!dr.start && !!dr.end && activeTab === "neema",
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
            <div className={KPI_GRID}>
                <KpiCard
                    label="Total Revenue"
                    value={fmtKes(s.total_revenue)}
                    sub={`${s.total_orders ?? 0} orders (sales truth)`}
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
            <div className={KPI_GRID}>
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
                {/* "Collected" and the ledger's "Paid" are DIFFERENT questions and
                    are not meant to match: this is money that ARRIVED in the
                    period whatever period its order belongs to (treasury), while
                    Paid is how much of THIS period's sales has been settled
                    (receivables). Presenting both without saying so is what made
                    the page look self-contradictory. */}
                <KpiCard
                    label="Collected"
                    value={fmtKes(s.total_collected)}
                    sub="money received in this period, by payment date"
                />
            </div>

            {/* Tabs */}
            <div className="border-b border-line overflow-x-auto no-scrollbar">
                <nav className="flex gap-1 -mb-px">
                    {(
                        [
                            ["overview", "Overview"],
                            ["products", "Products"],
                            ["customers", "Customers"],
                            ["channels", "Channels"],
                            ["patterns", "Patterns"],
                            ["neema", "Neema (AI agent)"],
                        ] as const
                    ).map(([tab, label]) => (
                        <button
                            key={tab}
                            onClick={() => setActiveTab(tab)}
                            className={clsx(
                                "px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap shrink-0 transition-colors",
                                activeTab === tab
                                    ? "border-brand-500 text-brand-600"
                                    : "border-transparent text-surface-500 hover:text-surface-700",
                            )}
                        >
                            {label}
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
                                        stroke="#f2f3f2"
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
                                                    {/* method_name comes from the payment_methods
                                                        table. Title-casing the raw code turned
                                                        "inmpaybill" into "Inmpaybill" while the real
                                                        name, "I&M Paybill", already existed in the
                                                        database. No `capitalize` — it would lower the
                                                        M in I&M. */}
                                                    <span className="text-surface-700">
                                                        {pm.method_name
                                                            ?? pm.payment_method?.replace(/_/g, " ")}
                                                        {/* The paybill and account number were
                                                            already configured on the method in
                                                            Setup -> Payment Methods; showing them
                                                            here puts the reconciliation reference
                                                            beside the figure you reconcile. */}
                                                        {pm.method_description && (
                                                            <span className="block text-2xs text-surface-500 font-normal">
                                                                {pm.method_description}
                                                            </span>
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
                            <div className={KPI_GRID}>
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
                                    <tr className="border-y border-line bg-surface-50/50">
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
                                <tbody className="divide-y divide-line">
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
                                        <tr className="border-y border-line bg-surface-50/50">
                                            <th className={TH}>Category</th>
                                            <th className={TH_R}>Products</th>
                                            <th className={TH_R}>Units Sold</th>
                                            <th className={TH_R}>Orders</th>
                                            <th className={TH_R}>Avg Price</th>
                                            <th className={TH_R}>Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-line">
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
                                    <tr className="border-y border-line bg-surface-50/50">
                                        <th className={clsx(TH, "w-8")}>#</th>
                                        <th className={TH}>Customer</th>
                                        <th className={TH}>Email</th>
                                        <th className={TH_R}>Orders</th>
                                        <th className={TH_R}>Avg Order</th>
                                        <th className={TH_R}>Revenue</th>
                                        <th className={TH} style={{ width: 120 }}>Share</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
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
                    <LedgerTab query={ledgerQuery} />
                    <SalesByOutletTable params={dr.params} />
                </div>
            )}

            {/* ── NEEMA (AI AGENT) TAB ── */}
            {activeTab === "neema" && <NeemaTab query={neemaQuery} />}

            {(summaryQuery.data?.excluded_currencies ?? []).length > 0 && (
                /* A total that quietly omits rows is worse than one that says
                   what it omitted — nothing else on the page lets a reader
                   notice that orders in another currency exist at all. */
                <div className="card p-4 mb-6 border border-amber-200 bg-amber-50">
                    <p className="text-sm font-semibold text-amber-dark">
                        Not included in the totals above
                    </p>
                    <p className="text-xs text-surface-600 mt-1">
                        This report is in {summaryQuery.data?.period?.currency ?? "KES"}.
                        {" "}
                        {(summaryQuery.data?.excluded_currencies ?? [])
                            .map((c: any) => `${c.orders} order${c.orders === 1 ? "" : "s"} in ${c.currency_code}`)
                            .join(", ")}
                        {" "}
                        {(summaryQuery.data?.excluded_currencies ?? []).length === 1 ? "is" : "are"} excluded.
                    </p>
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
                                        stroke="#f2f3f2"
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
                        <tr className="border-y border-line bg-surface-50/50">
                            <th className={TH}>Outlet</th>
                            <th className={TH}>City</th>
                            <th className={TH_R}>Orders</th>
                            <th className={TH_R}>Avg Daily Rev</th>
                            <th className={TH_R}>Avg Order</th>
                            <th className={TH_R}>Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
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
// ── Sales ledger ──────────────────────────────────────────────────────────────
// What we sold, what we actually collected, what we are still owed — per
// channel, and by day, week and month.
//
// Every row satisfies sales = paid + balance. That holds because the API
// attributes paid to the period the ORDER falls in, not the period the payment
// landed in (see ReportController::salesLedger). Read "balance" as "still owed
// on what we sold then", not "money that has not arrived yet".

const CHANNEL_KEYS = ["pos", "online", "whatsapp"] as const;
const CHANNEL_LABEL: Record<string, string> = { pos: "POS", online: "Online", whatsapp: "WhatsApp" };

/** Balance is the number a manager is chasing, so it earns colour; zero does not. */
function Owed({ value }: { value: number }) {
    return (
        <span className={clsx("tabular-nums", value > 0 ? "font-semibold text-danger-700" : "text-surface-500")}>
            {fmtKes(value)}
        </span>
    );
}

function LedgerBucketTable({ title, rows, periodLabel }: {
    title: string;
    rows: LedgerBucket[];
    periodLabel: string;
}) {
    if (!rows.length) {
        return (
            <div className="card p-5">
                <p className="font-semibold text-surface-900 mb-1">{title}</p>
                <p className="text-sm text-surface-500">No orders in this range.</p>
            </div>
        );
    }
    return (
        <div className="card overflow-hidden">
            <div className="px-5 pt-5 pb-3">
                <p className="font-semibold text-surface-900">{title}</p>
                <p className="text-xs text-surface-500 mt-0.5">
                    Sales, amount paid and balance still owed — per channel.
                </p>
            </div>
            <div className="table-wrapper rounded-none border-0">
                <table className="table">
                    <thead>
                        <tr>
                            <th className="sticky left-0 bg-surface-100">{periodLabel}</th>
                            {CHANNEL_KEYS.map(c => (
                                <th key={c} colSpan={3} className="text-center border-l border-line">
                                    {CHANNEL_LABEL[c]}
                                </th>
                            ))}
                            <th colSpan={3} className="text-center border-l border-line">Total</th>
                        </tr>
                        <tr>
                            <th className="sticky left-0 bg-surface-100" />
                            {[...CHANNEL_KEYS, "total"].map(c => (
                                <React.Fragment key={c}>
                                    <th className="text-right border-l border-line font-normal normal-case tracking-normal">Sales</th>
                                    <th className="text-right font-normal normal-case tracking-normal">Paid</th>
                                    <th className="text-right font-normal normal-case tracking-normal">Balance</th>
                                </React.Fragment>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map(r => (
                            <tr key={r.period}>
                                <td className="sticky left-0 bg-white font-mono text-2xs font-bold text-surface-700">{r.period}</td>
                                {CHANNEL_KEYS.map(c => {
                                    const v = r.by_channel[c];
                                    return (
                                        <React.Fragment key={c}>
                                            <td className="text-right border-l border-line tabular-nums">{fmtKes(v.sales)}</td>
                                            <td className="text-right tabular-nums text-success-700">{fmtKes(v.paid)}</td>
                                            <td className="text-right"><Owed value={v.balance} /></td>
                                        </React.Fragment>
                                    );
                                })}
                                <td className="text-right border-l border-line font-semibold tabular-nums">{fmtKes(r.total.sales)}</td>
                                <td className="text-right font-semibold tabular-nums text-success-700">{fmtKes(r.total.paid)}</td>
                                <td className="text-right font-semibold"><Owed value={r.total.balance} /></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function LedgerTab({ query }: { query: { data?: SalesLedger; isLoading: boolean } }) {
    const l = query.data;
    if (query.isLoading) return <div className="card p-6 text-sm text-surface-500">Loading ledger…</div>;
    if (!l) return null;

    const grand = l.channels.reduce(
        (a, c) => ({ orders: a.orders + c.orders, sales: a.sales + c.sales, paid: a.paid + c.paid, balance: a.balance + c.balance }),
        { orders: 0, sales: 0, paid: 0, balance: 0 },
    );

    return (
        <div className="space-y-6">
            {/* Per channel, whole period */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {l.channels.map(c => (
                    <div key={c.channel} className="card p-5">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wide">{c.label}</p>
                        <p className="text-2xl font-bold text-surface-900 mt-1 tabular-nums">{fmtKes(c.sales)}</p>
                        <p className="text-xs text-surface-500 mt-0.5">{c.orders} orders</p>
                        <div className="mt-3 pt-3 border-t border-line space-y-1 text-xs">
                            <div className="flex justify-between">
                                <span className="text-surface-500">Paid</span>
                                <span className="font-semibold tabular-nums text-success-700">{fmtKes(c.paid)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-surface-500">Balance</span>
                                <Owed value={c.balance} />
                            </div>
                        </div>
                    </div>
                ))}
                <div className="card p-5 bg-surface-50">
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wide">All channels</p>
                    <p className="text-2xl font-bold text-surface-900 mt-1 tabular-nums">{fmtKes(grand.sales)}</p>
                    <p className="text-xs text-surface-500 mt-0.5">{grand.orders} orders</p>
                    <div className="mt-3 pt-3 border-t border-line space-y-1 text-xs">
                        <div className="flex justify-between">
                            <span className="text-surface-500">Paid</span>
                            <span className="font-semibold tabular-nums text-success-700">{fmtKes(grand.paid)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-surface-500">Balance</span>
                            <Owed value={grand.balance} />
                        </div>
                    </div>
                </div>
            </div>

            {/* Daily: sales, paid, credit */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-3">
                    <p className="font-semibold text-surface-900">Daily sales, paid and credit</p>
                    <p className="text-xs text-surface-500 mt-0.5">
                        Credit is the unpaid balance on that day&rsquo;s orders.
                    </p>
                </div>
                <div className="table-wrapper rounded-none border-0">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th className="text-right">Orders</th>
                                <th className="text-right">Sales</th>
                                <th className="text-right">Paid</th>
                                <th className="text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {l.daily.length === 0 && (
                                <tr><td colSpan={5} className="text-center text-surface-500 py-6">No orders in this range.</td></tr>
                            )}
                            {l.daily.map(d => (
                                <tr key={d.date}>
                                    <td className="font-mono text-2xs font-bold text-surface-700">{d.date}</td>
                                    <td className="text-right tabular-nums">{d.orders}</td>
                                    <td className="text-right tabular-nums">{fmtKes(d.sales)}</td>
                                    <td className="text-right tabular-nums text-success-700">{fmtKes(d.paid)}</td>
                                    <td className="text-right"><Owed value={d.credit} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <LedgerBucketTable title="Weekly" rows={l.weekly} periodLabel="Week" />
            <LedgerBucketTable title="Monthly" rows={l.monthly} periodLabel="Month" />
        </div>
    );
}

// ── Neema (AI agent) performance ──────────────────────────────────────────────
// The agent's sales funnel for the period: leads captured, how many became an
// order within 14 days (matched by phone — read conversion as a floor, since a
// lead who ordered under a different number is not counted), WhatsApp-channel
// revenue under the SAME rule as the ledger, contact growth and message volume
// per messaging platform.

const PLATFORM_LABEL: Record<string, string> = {
    whatsapp: "WhatsApp",
    messenger: "Messenger",
    instagram: "Instagram",
    facebook: "Facebook",
};

const LEAD_STATUSES = ["new", "assigned", "quoted", "won", "lost"] as const;
const LEAD_STATUS_LABEL: Record<string, string> = {
    new: "New",
    assigned: "Assigned",
    quoted: "Quoted",
    won: "Won",
    lost: "Lost",
};

function NeemaTab({
    query,
}: {
    query: { data?: NeemaSalesReport; isLoading: boolean };
}) {
    const d = query.data;
    if (query.isLoading)
        return (
            <div className="flex justify-center py-12">
                <Spinner />
            </div>
        );
    if (!d) return null;

    const newContacts = d.contacts.reduce((a, c) => a + c.new_contacts, 0);
    const maxStatus = Math.max(
        ...LEAD_STATUSES.map((s) => d.leads.by_status[s] ?? 0),
        1,
    );
    const maxIntent = Math.max(...d.leads.by_intent.map((i) => i.count), 1);

    return (
        <div className="space-y-6">
            {/* KPI row */}
            <div className={KPI_GRID}>
                <KpiCard
                    label="Leads"
                    value={d.leads.total}
                    sub="captured by Neema in this period"
                />
                <KpiCard
                    label="Lead → Order"
                    value={fmtPct(d.lead_conversion.conversion_rate)}
                    sub={`${d.lead_conversion.converted} of ${d.leads.total} ordered within ${d.lead_conversion.window_days} days · ${fmtKes(d.lead_conversion.revenue)}`}
                />
                <KpiCard
                    label="WhatsApp Revenue"
                    value={fmtKes(d.whatsapp_sales.revenue)}
                    sub={`${d.whatsapp_sales.orders} orders · ${fmtKes(d.whatsapp_sales.paid)} paid`}
                />
                <KpiCard
                    label="New Contacts"
                    value={newContacts}
                    sub="first seen this period, all platforms"
                />
            </div>

            {/* Leads funnel: status mix + top intents */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="card p-5">
                    <SectionHeader title="Leads by Status" />
                    {d.leads.total === 0 ? (
                        <p className="text-sm text-surface-500">
                            No leads in this range.
                        </p>
                    ) : (
                        <div className="space-y-3 mt-2">
                            {LEAD_STATUSES.map((s, i) => (
                                <div key={s}>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-surface-700">
                                            {LEAD_STATUS_LABEL[s]}
                                        </span>
                                        <span className="font-semibold tabular-nums">
                                            {d.leads.by_status[s] ?? 0}
                                        </span>
                                    </div>
                                    <ProgressBar
                                        value={d.leads.by_status[s] ?? 0}
                                        max={maxStatus}
                                        color={
                                            CHART_COLORS[
                                                i % CHART_COLORS.length
                                            ]
                                        }
                                    />
                                </div>
                            ))}
                            <p className="text-xs text-surface-500 pt-2 border-t border-line">
                                Won rate: {fmtPct(d.leads.won_rate)} of leads in
                                this period.
                            </p>
                        </div>
                    )}
                </div>

                <div className="card p-5">
                    <SectionHeader title="Top Intents" />
                    {d.leads.by_intent.length === 0 ? (
                        <p className="text-sm text-surface-500">
                            No leads in this range.
                        </p>
                    ) : (
                        <div className="space-y-3 mt-2">
                            {d.leads.by_intent.map((row, i) => (
                                <div key={row.intent}>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-surface-700 capitalize">
                                            {row.intent.replace(/_/g, " ")}
                                        </span>
                                        <span className="font-semibold tabular-nums">
                                            {row.count}
                                        </span>
                                    </div>
                                    <ProgressBar
                                        value={row.count}
                                        max={maxIntent}
                                        color={
                                            CHART_COLORS[
                                                i % CHART_COLORS.length
                                            ]
                                        }
                                    />
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Contacts per platform */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Contacts by Platform" />
                    <p className="text-xs text-surface-500 -mt-2">
                        New = first seen in this period. Active = messaged in
                        this period. Matched = linked to a hub customer by
                        phone.
                    </p>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-line bg-surface-50/50">
                                <th className={TH}>Platform</th>
                                <th className={TH_R}>New</th>
                                <th className={TH_R}>Active</th>
                                <th className={TH_R}>All-time Contacts</th>
                                <th className={TH_R}>Matched</th>
                                <th className={TH_R}>Match Rate</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {d.contacts.map((c) => (
                                <tr
                                    key={c.channel}
                                    className="hover:bg-surface-50/50 transition-colors"
                                >
                                    <td className="px-4 py-3 font-medium text-surface-900">
                                        {PLATFORM_LABEL[c.channel] ?? c.channel}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {c.new_contacts}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {c.active_contacts}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                        {c.contacts}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                        {c.matched}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {c.match_rate == null
                                            ? "—"
                                            : fmtPct(c.match_rate)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>

            {/* Message volume per platform */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Message Volume by Platform" />
                    <p className="text-xs text-surface-500 -mt-2">
                        Period figures come from daily snapshots
                        {d.message_volume.daily_since
                            ? ` collected since ${dayjs(d.message_volume.daily_since).format("D MMM YYYY")}`
                            : ""}
                        . Daily message history collects from deploy onward —
                        the totals on the right are all-time.
                    </p>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-line bg-surface-50/50">
                                <th className={TH}>Platform</th>
                                <th className={TH_R}>Messages (period)</th>
                                <th className={TH_R}>Inbound (period)</th>
                                <th className={TH_R}>Messages (all-time)</th>
                                <th className={TH_R}>Inbound (all-time)</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {d.message_volume.channels.map((c) => (
                                <tr
                                    key={c.channel}
                                    className="hover:bg-surface-50/50 transition-colors"
                                >
                                    <td className="px-4 py-3 font-medium text-surface-900">
                                        {PLATFORM_LABEL[c.channel] ?? c.channel}
                                    </td>
                                    <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                        {c.period.messages}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {c.period.inbound}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-surface-500">
                                        {c.all_time.messages}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-surface-500">
                                        {c.all_time.inbound}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>

            <p className="text-xs text-surface-400">
                Lead → order conversion is matched by phone number and is a
                floor, not an exact figure — a lead who ordered under a
                different number is not counted. WhatsApp revenue follows the
                same channel rule as the Channels tab, so the numbers agree.
            </p>
        </div>
    );
}
