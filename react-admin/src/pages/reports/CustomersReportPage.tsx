// src/pages/reports/CustomersReportPage.tsx
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { reportsApi } from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
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
    ProgressBar,
    CHART_COLORS,
    TH,
    TH_R,
} from "./reportShared";

export default function CustomersReportPage() {
    const dr = useDateRange("this_month");
    const [activeTab, setActiveTab] = useState<"overview" | "ltv" | "retention" | "intelligence">("overview");

    const periodDays = Math.max(
        1,
        dayjs(dr.end).diff(dayjs(dr.start), "day") + 1,
    );

    const summaryQuery = useQuery({
        queryKey: ["report-customers-summary", dr.start, dr.end],
        queryFn: () => reportsApi.customerSummary(dr.params),
        enabled: !!dr.start && !!dr.end,
    });
    const ltvQuery = useQuery({
        queryKey: ["report-customers-ltv", dr.start, dr.end],
        queryFn: () =>
            reportsApi.customerLifetimeValue({ ...dr.params, limit: 30 }),
        enabled: !!dr.start && !!dr.end,
    });
    const analyticsQuery = useQuery({
        queryKey: ["report-customers-analytics", periodDays],
        queryFn: () =>
            reportsApi.customerAnalytics({ period: periodDays } as any),
    });
    const retentionQuery = useQuery({
        queryKey: ["report-customers-retention", dr.start, dr.end],
        queryFn: () => reportsApi.customerRetention(dr.params),
        enabled: !!dr.start && !!dr.end && activeTab === "retention",
    });

    if (summaryQuery.isLoading)
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );

    const summary = summaryQuery.data ?? {};
    const analytics = analyticsQuery.data ?? {};

    const segmentData = Object.entries(analytics.segments ?? {}).map(
        ([name, value]) => ({
            name,
            value: Number(value),
        }),
    );
    const spendBrackets = Object.entries(analytics.spend_brackets ?? {}).map(
        ([bracket, count]) => ({
            bracket,
            count: Number(count),
        }),
    );

    const acquisitionTrend = summary.acquisition_trend ?? [];
    const maxLtvSpent = Math.max(
        ...(ltvQuery.data?.customers ?? []).map((c: any) =>
            Number(c.total_spent),
        ),
        1,
    );

    return (
        <div className="space-y-6 animate-fade-in">
            <ReportPageHeader
                title="Customers Report"
                subtitle="Customer growth, segments, lifetime value, and retention."
                reportType="customers"
                exportPath="customers/lifetime-value"
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
                <KpiCard
                    label="Total Customers"
                    value={
                        summary.total_customers ??
                        analytics.stats?.total_customers ??
                        0
                    }
                />
                <KpiCard
                    label="New (Period)"
                    value={summary.new_customers ?? 0}
                    color="text-success"
                    sub="Registered in range"
                />
                <KpiCard
                    label="Unique Buyers"
                    value={summary.unique_buyers ?? 0}
                    sub="Placed ≥ 1 order"
                />
                <KpiCard
                    label="Repeat Purchase Rate"
                    value={`${summary.repeat_purchase_rate ?? 0}%`}
                    sub={`${summary.repeat_buyers ?? 0} repeat buyers`}
                    color="text-brand-600"
                />
            </div>
            <div className={KPI_GRID}>
                <KpiCard
                    label="New Buyers"
                    value={summary.new_buyers ?? 0}
                    color="text-success"
                />
                <KpiCard
                    label="Returning Buyers"
                    value={summary.returning_buyers ?? 0}
                    color="text-info"
                />
                <KpiCard
                    label="Active Customers"
                    value={analytics.stats?.active_customers ?? 0}
                    sub={`Ordered in last ${periodDays}d`}
                />
                <KpiCard
                    label="VIP Customers"
                    value={analytics.segments?.VIP ?? 0}
                    sub="10+ lifetime orders"
                    color="text-brand-600"
                />
            </div>

            {/* Tabs */}
            <div className="border-b border-surface-100 overflow-x-auto no-scrollbar">
                <nav className="flex gap-1 -mb-px">
                    {(["overview", "ltv", "retention", "intelligence"] as const).map((tab) => (
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
                            {tab === "ltv"
                                ? "Lifetime Value"
                                : tab === "retention"
                                  ? "Retention"
                                  : "Overview"}
                        </button>
                    ))}
                </nav>
            </div>

            {/* ── OVERVIEW ── */}
            {activeTab === "intelligence" && (
                <CustomerIntelligence start={dr.start} end={dr.end} />
            )}

            {activeTab === "overview" && (
                <div className="space-y-6">
                    {/* Segments + spend brackets */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {segmentData.length > 0 && (
                            <div className="card p-5">
                                <SectionHeader title="Customer Segments" />
                                <ResponsiveContainer width="100%" height={200}>
                                    <PieChart>
                                        <Pie
                                            data={segmentData}
                                            dataKey="value"
                                            nameKey="name"
                                            cx="50%"
                                            cy="50%"
                                            outerRadius={75}
                                            label={({ name, percent }: any) =>
                                                `${name} ${(percent * 100).toFixed(0)}%`
                                            }
                                            labelLine={false}
                                        >
                                            {segmentData.map(
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
                                <div className="space-y-2 mt-3">
                                    {segmentData.map((seg, i) => {
                                        const total = segmentData.reduce(
                                            (s, x) => s + x.value,
                                            0,
                                        );
                                        const pct =
                                            total > 0
                                                ? Math.round(
                                                      (seg.value / total) * 100,
                                                  )
                                                : 0;
                                        return (
                                            <div key={seg.name}>
                                                <div className="flex justify-between text-sm mb-1">
                                                    <span className="font-medium text-surface-700">
                                                        {seg.name}
                                                    </span>
                                                    <span className="tabular-nums text-surface-500">
                                                        {seg.value} · {pct}%
                                                    </span>
                                                </div>
                                                <ProgressBar
                                                    value={seg.value}
                                                    max={total}
                                                    color={
                                                        CHART_COLORS[
                                                            i %
                                                                CHART_COLORS.length
                                                        ]
                                                    }
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        {spendBrackets.length > 0 && (
                            <div className="card p-5">
                                <SectionHeader title="Lifetime Spend Brackets" />
                                <ResponsiveContainer width="100%" height={200}>
                                    <BarChart
                                        data={spendBrackets}
                                        layout="vertical"
                                    >
                                        <CartesianGrid
                                            strokeDasharray="3 3"
                                            stroke="#F1F5F9"
                                            horizontal={false}
                                        />
                                        <XAxis
                                            type="number"
                                            tick={{ fontSize: 11 }}
                                        />
                                        <YAxis
                                            type="category"
                                            dataKey="bracket"
                                            tick={{ fontSize: 11 }}
                                            width={80}
                                        />
                                        <Tooltip />
                                        <Bar
                                            dataKey="count"
                                            name="Customers"
                                            fill={CHART_COLORS[1]}
                                            radius={[0, 3, 3, 0]}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </div>

                    {/* Acquisition trend */}
                    {acquisitionTrend.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="New Customer Acquisition (Monthly)" />
                            <ResponsiveContainer width="100%" height={220}>
                                <LineChart data={acquisitionTrend}>
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="#F1F5F9"
                                    />
                                    <XAxis
                                        dataKey="month"
                                        tick={{ fontSize: 11 }}
                                    />
                                    <YAxis tick={{ fontSize: 11 }} width={40} />
                                    <Tooltip />
                                    <Line
                                        type="monotone"
                                        dataKey="new_customers"
                                        stroke={CHART_COLORS[0]}
                                        strokeWidth={2}
                                        dot={true}
                                        name="New Customers"
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </div>
            )}

            {/* ── LTV ── */}
            {activeTab === "ltv" && (
                <div className="card overflow-hidden">
                    <div className="px-5 pt-5 pb-4">
                        <SectionHeader title="Top Customers by Lifetime Spend">
                            <ExportCsvButton
                                path="customers/lifetime-value"
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
                                    <th className={TH}>Phone</th>
                                    <th className={TH_R}>Orders</th>
                                    <th className={TH_R}>Avg Order</th>
                                    <th className={TH_R}>Max Order</th>
                                    <th className={TH}>First Order</th>
                                    <th className={TH}>Last Order</th>
                                    <th className={TH_R}>Total Spent</th>
                                    <th className={TH} style={{ width: 100 }}>
                                        Rank
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
                                {ltvQuery.isLoading ? (
                                    <tr>
                                        <td
                                            colSpan={11}
                                            className="px-4 py-8 text-center"
                                        >
                                            <Spinner />
                                        </td>
                                    </tr>
                                ) : (ltvQuery.data?.customers ?? []).length ===
                                  0 ? (
                                    <EmptyRow cols={11} />
                                ) : (
                                    (ltvQuery.data.customers ?? []).map(
                                        (c: any, i: number) => (
                                            <tr
                                                key={c.id}
                                                className="hover:bg-surface-50/50 transition-colors"
                                            >
                                                <td className="px-4 py-3 text-surface-400 text-sm">
                                                    {i + 1}
                                                </td>
                                                <td className="px-4 py-3 font-medium text-surface-900">
                                                    {c.name}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-500">
                                                    {c.email ?? "-"}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-500">
                                                    {c.phone ?? "-"}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {c.order_count}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                    {fmtKes(c.avg_order_value)}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                    {fmtKes(c.max_order_value)}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-500">
                                                    {c.first_order_date
                                                        ? dayjs(
                                                              c.first_order_date,
                                                          ).format("D MMM YY")
                                                        : "-"}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-500">
                                                    {c.last_order_date
                                                        ? dayjs(
                                                              c.last_order_date,
                                                          ).format("D MMM YY")
                                                        : "-"}
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                    {fmtKes(c.total_spent)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <ProgressBar
                                                        value={Number(
                                                            c.total_spent,
                                                        )}
                                                        max={maxLtvSpent}
                                                        color={CHART_COLORS[1]}
                                                    />
                                                </td>
                                            </tr>
                                        ),
                                    )
                                )}
                            </tbody>
                        </table>
                    </TableWrapper>
                </div>
            )}

            {/* ── RETENTION ── */}
            {activeTab === "retention" && (
                <div className="space-y-6">
                    <div className="card p-5">
                        <SectionHeader title="Monthly Cohort Retention" />
                        <p className="text-sm text-surface-500 mb-4">
                            Each row is a cohort of customers acquired in that
                            month. Numbers show how many placed an order in each
                            subsequent month.
                        </p>
                        {retentionQuery.isLoading ? (
                            <div className="flex justify-center py-10">
                                <Spinner />
                            </div>
                        ) : (retentionQuery.data?.retention ?? []).length ===
                          0 ? (
                            <p className="text-sm text-surface-400 text-center py-8">
                                No cohort data for this period.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-surface-100">
                                            <th className={TH}>Cohort</th>
                                            <th className={TH_R}>Size</th>
                                            {Array.from(
                                                { length: 6 },
                                                (_, i) => (
                                                    <th
                                                        key={i}
                                                        className={TH_R}
                                                    >
                                                        M+{i}
                                                    </th>
                                                ),
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {(
                                            retentionQuery.data.retention ?? []
                                        ).map((cohort: any) => {
                                            const months = cohort.months ?? {};
                                            const monthKeys = Object.keys(
                                                months,
                                            )
                                                .sort()
                                                .slice(0, 7);
                                            return (
                                                <tr
                                                    key={cohort.cohort}
                                                    className="hover:bg-surface-50/50"
                                                >
                                                    <td className="px-4 py-2.5 font-medium text-surface-900">
                                                        {cohort.cohort}
                                                    </td>
                                                    <td className="px-4 py-2.5 text-right tabular-nums font-semibold">
                                                        {cohort.size}
                                                    </td>
                                                    {Array.from(
                                                        { length: 6 },
                                                        (_, i) => {
                                                            const mk =
                                                                monthKeys[i];
                                                            const count = mk
                                                                ? (months[mk] ??
                                                                  0)
                                                                : null;
                                                            const rate =
                                                                count !==
                                                                    null &&
                                                                cohort.size > 0
                                                                    ? Math.round(
                                                                          (count /
                                                                              cohort.size) *
                                                                              100,
                                                                      )
                                                                    : null;
                                                            return (
                                                                <td
                                                                    key={i}
                                                                    className="px-4 py-2.5 text-right"
                                                                >
                                                                    {count ===
                                                                    null ? (
                                                                        <span className="text-surface-200">
                                                                            -
                                                                        </span>
                                                                    ) : (
                                                                        <span
                                                                            className={clsx(
                                                                                "px-2 py-0.5 rounded text-xs font-medium tabular-nums",
                                                                                rate ===
                                                                                    null
                                                                                    ? "text-surface-400"
                                                                                    : rate >=
                                                                                        50
                                                                                      ? "bg-success-light text-success"
                                                                                      : rate >=
                                                                                          25
                                                                                        ? "bg-warning-light text-warning"
                                                                                        : "bg-danger-light text-danger",
                                                                            )}
                                                                        >
                                                                            {
                                                                                count
                                                                            }{" "}
                                                                            (
                                                                            {
                                                                                rate
                                                                            }
                                                                            %)
                                                                        </span>
                                                                    )}
                                                                </td>
                                                            );
                                                        },
                                                    )}
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Intelligence tab ─────────────────────────────────────────────────────────
// Identity keyed on customer_id or FULL phone (live POS orders carry only the
// snapshot): segments, new vs returning money, the top table, and the dormant
// list — the customers worth a phone call this week.

function CustomerIntelligence({ start, end }: { start: string; end: string }) {
    const { data, isLoading } = useQuery({
        queryKey: ["customer-intelligence", start, end],
        queryFn: () => reportsApi.customerIntelligence(start, end),
        enabled: !!start && !!end,
        staleTime: 60_000,
    });
    if (isLoading || !data) return <div className="flex justify-center py-16"><Spinner /></div>;
    const { segments, new_vs_returning: nvr, top_customers, dormant } = data;

    return (
        <div className="space-y-6">
            <div className={KPI_GRID}>
                <KpiCard label="Returning Revenue" value={fmtKes(nvr.returning?.revenue ?? 0)}
                    sub={`${nvr.returning?.customers ?? 0} customers came back`} color="text-success" />
                <KpiCard label="New-Customer Revenue" value={fmtKes(nvr.new?.revenue ?? 0)}
                    sub={`${nvr.new?.customers ?? 0} first-time buyers`} />
                <KpiCard label="Walk-in / Anonymous" value={fmtKes(nvr.anonymous?.revenue ?? 0)}
                    sub={`${nvr.anonymous?.orders ?? 0} orders with no identity — capture phones!`} />
            </div>

            {dormant.length > 0 && (
                <div className="card card-body border border-amber-200 bg-amber-50/40">
                    <SectionHeader title="📞 Worth a call — top customers gone quiet (60+ days)" />
                    <div className="space-y-1.5 mt-1">
                        {dormant.map((d: any) => (
                            <div key={d.phone ?? d.name} className="flex items-center gap-3 text-xs">
                                <span className="font-medium text-surface-800 flex-1 truncate">{d.name}</span>
                                {d.phone && <span className="text-surface-500 font-mono text-2xs">{d.phone}</span>}
                                <span className="tabular-nums text-surface-600">{fmtKes(d.revenue_12m)} / yr</span>
                                <span className="font-bold text-amber-700 tabular-nums">{d.days_quiet}d quiet</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div className="card card-body">
                    <SectionHeader title="Segments (period revenue)" />
                    <div className="space-y-1.5 mt-1">
                        {segments.map((sg: any) => (
                            <div key={sg.segment} className="flex items-center gap-3 text-xs">
                                <span className="font-medium text-surface-800 capitalize flex-1">{String(sg.segment).replace(/_/g, " ")}</span>
                                <span className="text-2xs text-surface-400">{sg.orders} orders{Number(sg.customers) > 0 ? ` · ${sg.customers} customers` : ""}</span>
                                <span className="font-bold tabular-nums text-surface-800">{fmtKes(sg.revenue)}</span>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="card card-body">
                    <SectionHeader title="Top customers (period vs lifetime)" />
                    {top_customers.length === 0 ? (
                        <p className="text-xs text-surface-400 py-4">No identified customers in this period.</p>
                    ) : (
                        <div className="space-y-1.5 mt-1">
                            {top_customers.map((c: any, i: number) => (
                                <div key={c.ckey} className="flex items-center gap-2.5 text-xs">
                                    <span className="text-2xs font-bold text-surface-300 w-4">{i + 1}</span>
                                    <div className="flex-1 min-w-0">
                                        <p className="font-medium text-surface-800 truncate">{c.name || c.phone}</p>
                                        <p className="text-2xs text-surface-400">{c.lifetime_orders} orders lifetime · {fmtKes(c.lifetime_revenue)}</p>
                                    </div>
                                    <span className="font-bold tabular-nums text-surface-800">{fmtKes(c.period_revenue)}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
