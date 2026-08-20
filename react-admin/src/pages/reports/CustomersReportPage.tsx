// src/pages/reports/CustomersReportPage.tsx
import { Fragment, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
    reportsApi,
    type InstitutionalAccountRow,
    type OutreachLogRow,
    type ReplenishmentDueRow,
    type WinBackCustomerRow,
    type SecondPurchaseWorklistRow,
} from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { openWhatsApp } from "@/lib/whatsapp";
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

type CustomersTab = "overview" | "secondpurchase" | "ltv" | "retention" | "intelligence" | "replenishment" | "winback" | "institutions" | "outreachlog";
const CUSTOMERS_TABS: readonly CustomersTab[] = ["overview", "secondpurchase", "ltv", "retention", "intelligence", "replenishment", "winback", "institutions", "outreachlog"];

export default function CustomersReportPage() {
    const dr = useDateRange("this_month");
    // Honour deep-links like /reports/customers?tab=replenishment (the
    // attention feed sends users here) — read once on mount, same pattern as
    // ProductionPage's ?status=; after that the tab buttons own the state.
    const [searchParams] = useSearchParams();
    const [activeTab, setActiveTab] = useState<CustomersTab>(() => {
        const t = searchParams.get("tab");
        return CUSTOMERS_TABS.includes(t as CustomersTab) ? (t as CustomersTab) : "overview";
    });

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
            <div className="border-b border-line overflow-x-auto no-scrollbar">
                <nav className="flex gap-1 -mb-px">
                    {CUSTOMERS_TABS.map((tab) => (
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
                            {tab === "secondpurchase"
                                ? "Second Purchase"
                                : tab === "ltv"
                                ? "Lifetime Value"
                                : tab === "replenishment"
                                  ? "Replenishment"
                                  : tab === "winback"
                                    ? "Win-back"
                                    : tab === "institutions"
                                      ? "Institutions"
                                      : tab === "outreachlog"
                                        ? "Outreach log"
                                        : tab === "retention"
                                          ? "Retention"
                                          : tab === "intelligence"
                                            ? "Intelligence"
                                            : "Overview"}
                        </button>
                    ))}
                </nav>
            </div>

            {/* ── OVERVIEW ── */}
            {activeTab === "intelligence" && (
                <CustomerIntelligence
                    start={dr.start}
                    end={dr.end}
                    onOpenWinBack={() => setActiveTab("winback")}
                />
            )}

            {/* ── REPLENISHMENT — "as of now", deliberately ignores the date
                   range (like Inventory's stock tab): due/overdue only means
                   anything against today. ── */}
            {activeTab === "replenishment" && <ReplenishmentRadarTab />}

            {/* ── WIN-BACK — "as of now" like the radar: dormancy is measured
                   against today, not the report date range. ── */}
            {activeTab === "secondpurchase" && <SecondPurchaseTab />}
            {activeTab === "winback" && <WinBackTab />}

            {/* ── INSTITUTIONS — churches/institutions as accounts, "as of
                   now" like the radar (a quiet church only means anything
                   against today). ── */}
            {activeTab === "institutions" && <InstitutionsTab />}

            {/* ── OUTREACH LOG — the unified audit trail behind every
                   proactive contact: automated radar pings + manual win-back
                   outreach, newest first, with outcome attribution. ── */}
            {activeTab === "outreachlog" && <OutreachLogTab />}

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
                                            stroke="#f2f3f2"
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
                                        stroke="#f2f3f2"
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
                                <tr className="border-y border-line bg-surface-50/50">
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
                            <tbody className="divide-y divide-line">
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
                                        <tr className="border-b border-line">
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
                                    <tbody className="divide-y divide-line">
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

function CustomerIntelligence({
    start,
    end,
    onOpenWinBack,
}: {
    start: string;
    end: string;
    onOpenWinBack: () => void;
}) {
    const { data, isLoading } = useQuery({
        queryKey: ["customer-intelligence", start, end],
        queryFn: () => reportsApi.customerIntelligence(start, end),
        enabled: !!start && !!end,
        staleTime: 60_000,
    });
    if (isLoading || !data) return <div className="flex justify-center py-16"><Spinner /></div>;
    const { segments, new_vs_returning: nvr, top_customers, dormant, rfm } = data;

    // Human labels + tone for the RFM segments (order comes from the API).
    const RFM_META: Record<string, { label: string; tone: string }> = {
        champions:       { label: "🏆 Champions",       tone: "text-success" },
        loyal:           { label: "💚 Loyal",           tone: "text-success" },
        promising:       { label: "🌱 Promising",       tone: "text-surface-800" },
        needs_attention: { label: "👀 Needs attention", tone: "text-surface-800" },
        at_risk:         { label: "⚠️ At risk",         tone: "text-amber-700" },
        cant_lose:       { label: "🚨 Can't lose",      tone: "text-danger" },
        hibernating:     { label: "😴 Hibernating",     tone: "text-surface-500" },
    };

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

            {rfm && (
                <div className="card card-body">
                    <SectionHeader title={`RFM segments (trailing ${rfm.window_days} days)`} />
                    <div className={KPI_GRID + " mt-1"}>
                        {rfm.segments.map((sg: any) => (
                            <div key={sg.segment} className="rounded-lg border border-surface-200 p-3 flex flex-col gap-0.5">
                                <p className={"text-xs font-semibold " + (RFM_META[sg.segment]?.tone ?? "text-surface-800")}>
                                    {RFM_META[sg.segment]?.label ?? sg.segment}
                                </p>
                                <p className="text-lg font-bold tabular-nums text-surface-900">{sg.customers}</p>
                                <p className="text-2xs text-surface-500">
                                    {fmtKes(sg.revenue_365)} · {sg.revenue_share}% of revenue
                                </p>
                                <p className="text-2xs text-surface-400">avg {sg.avg_days_quiet}d since last order</p>
                            </div>
                        ))}
                    </div>
                    {rfm.action_list.length > 0 && (
                        <div className="mt-4 rounded-lg border border-danger-200 bg-danger-50/30 p-3">
                            <div className="flex items-center justify-between gap-2 mb-2">
                                <p className="text-xs font-semibold text-danger">
                                    💸 Win-back list — at-risk & can't-lose customers, biggest money first
                                </p>
                                <button
                                    onClick={onOpenWinBack}
                                    className="text-xs font-medium text-brand-600 hover:text-brand-700 whitespace-nowrap shrink-0"
                                >
                                    See full win-back economics →
                                </button>
                            </div>
                            <div className="space-y-1.5">
                                {rfm.action_list.map((c: any) => (
                                    <div key={(c.phone ?? "") + c.name} className="flex items-center gap-3 text-xs">
                                        <span className="font-medium text-surface-800 flex-1 truncate">{c.name}</span>
                                        {c.phone && <span className="text-surface-500 font-mono text-2xs">{c.phone}</span>}
                                        <span className="text-2xs text-surface-400 capitalize">{String(c.segment).replace(/_/g, " ")}</span>
                                        <span className="tabular-nums text-surface-600">{c.orders_365}× · {fmtKes(c.revenue_365)}</span>
                                        <span className="font-bold text-amber-700 tabular-nums">{c.days_quiet}d</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}

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

// ─── Replenishment Radar tab ──────────────────────────────────────────────────
// Consumables churches rebuy on a rhythm (communion bread, altar wine,
// prefilled cups): each row is a (customer, product) pair whose detected
// reorder cycle says they are due or overdue to rebuy — with a one-click
// WhatsApp ping so the shop reaches out BEFORE the customer remembers.

function ReplenishmentRadarTab() {
    const { data, isLoading } = useQuery({
        queryKey: ["replenishment-radar"],
        queryFn: () => reportsApi.replenishmentRadar(),
        staleTime: 60_000,
    });

    if (isLoading || !data)
        return (
            <div className="flex justify-center py-16">
                <Spinner />
            </div>
        );

    const { summary, due } = data;
    const overdueCount = due.filter((d) => d.status === "overdue").length;

    const pingMessage = (row: ReplenishmentDueRow) => {
        const firstName = (row.name || "").trim().split(/\s+/)[0] || "there";
        const days = Math.max(
            1,
            dayjs().diff(dayjs(row.last_purchase_at), "day"),
        );
        return (
            `Hello ${firstName}, greetings from Bethany House! ` +
            `It's been about ${days} days since your last ${row.product_name} order — ` +
            `shall we prepare your usual for you? We deliver.`
        );
    };

    return (
        <div className="space-y-6">
            <div className="flex justify-end">
                <ExportCsvButton path="replenishment" params={{}} />
            </div>

            <div className={KPI_GRID}>
                <KpiCard
                    label="Customers Due"
                    value={summary.due_customers}
                    sub="Reorder window open now"
                />
                <KpiCard
                    label="Expected Revenue"
                    value={fmtKes(summary.expected_revenue)}
                    sub={`Across ${summary.due_pairs} product reorder${summary.due_pairs === 1 ? "" : "s"}`}
                    color="text-brand-600"
                />
                <KpiCard
                    label="Overdue"
                    value={overdueCount}
                    sub="Past the usual cycle + grace"
                    color={overdueCount > 0 ? "text-danger" : "text-success"}
                />
                <KpiCard
                    label="Pings (30d)"
                    value={summary.pings_30d}
                    sub="Automated WhatsApp reminders"
                />
                <KpiCard
                    label="Maturing pairs"
                    value={summary.maturing_pairs}
                    sub="2 purchases — one more to confirm cycle"
                />
            </div>

            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Due & overdue reorders — overdue first, biggest money next" />
                    <p className="text-sm text-surface-500 mt-1">
                        Detected from each customer's own purchase rhythm over
                        the last 18 months (3+ purchases at steady intervals).
                        Muted "provisional" rows rest on a single repeat
                        purchase — the 3rd order confirms them, and automated
                        pings skip them. Lapsed customers (3+ cycles quiet)
                        live on the win-back list instead.
                    </p>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-line bg-surface-50/50">
                                <th className={TH}>Customer</th>
                                <th className={TH}>Phone</th>
                                <th className={TH}>Product</th>
                                <th className={TH}>Cycle</th>
                                <th className={TH}>Last Order</th>
                                <th className={TH}>Due</th>
                                <th className={TH}>Status</th>
                                <th className={TH_R}>Expected</th>
                                <th className={TH}></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {due.length === 0 ? (
                                <EmptyRow cols={9} />
                            ) : (
                                due.map((row) => (
                                    <tr
                                        key={`${row.ckey}-${row.product_id}`}
                                        className={clsx(
                                            "hover:bg-surface-50/50 transition-colors",
                                            row.tier === "provisional" &&
                                                "opacity-60",
                                        )}
                                    >
                                        <td className="px-4 py-3 font-medium text-surface-900">
                                            {row.name}
                                            {row.tier === "provisional" && (
                                                <span
                                                    className="ml-2 px-1.5 py-0.5 rounded text-2xs font-medium bg-surface-100 text-surface-500 border border-line align-middle whitespace-nowrap"
                                                    title="Based on a single repeat purchase — confirms on the 3rd order"
                                                >
                                                    provisional
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-500 font-mono">
                                            {row.phone ?? "-"}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-700">
                                            {row.product_name}
                                            <span className="text-2xs text-surface-400 block">
                                                {row.purchase_events} purchases
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-600 whitespace-nowrap">
                                            every ~{row.cycle_days} days
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-500 whitespace-nowrap">
                                            {dayjs(row.last_purchase_at).format(
                                                "D MMM YY",
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-500 whitespace-nowrap">
                                            {dayjs(row.next_due_at).format(
                                                "D MMM YY",
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={clsx(
                                                    "px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap",
                                                    row.status === "overdue"
                                                        ? "bg-danger-light text-danger"
                                                        : "bg-warning-light text-warning",
                                                )}
                                            >
                                                {row.status === "overdue"
                                                    ? `Overdue ${row.days_over}d`
                                                    : row.days_over >= 0
                                                      ? "Due now"
                                                      : `Due in ${-row.days_over}d`}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                            {fmtKes(row.avg_value)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.phone && (
                                                <button
                                                    onClick={() =>
                                                        openWhatsApp(
                                                            row.phone,
                                                            pingMessage(row),
                                                        )
                                                    }
                                                    className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium bg-success-light text-success hover:opacity-80 transition-opacity whitespace-nowrap"
                                                    title="Open WhatsApp with a prefilled reorder message"
                                                >
                                                    WhatsApp
                                                </button>
                                            )}
                                            {row.last_pinged_at &&
                                                row.last_ping_status ===
                                                    "sent" && (
                                                    <span
                                                        className="text-2xs text-surface-400 block mt-1 whitespace-nowrap"
                                                        title="Automated reorder reminder already sent"
                                                    >
                                                        Pinged{" "}
                                                        {Math.max(
                                                            0,
                                                            dayjs().diff(
                                                                dayjs(
                                                                    row.last_pinged_at,
                                                                ),
                                                                "day",
                                                            ),
                                                        )}
                                                        d ago
                                                    </span>
                                                )}
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

// ─── Win-back economics tab ───────────────────────────────────────────────────
// The RFM win-back list as a measured recovery engine. A customer is dormant
// when their silence is long relative to THEIR OWN median inter-purchase gap
// (min 45 days) — a monthly buyer 70 days quiet is an emergency, an annual
// bulk buyer 70 days quiet is on schedule. Every WhatsApp/call is logged so
// the summary can attribute orders placed within 30 days of an outreach.

function SecondPurchaseTab() {
    const { data, isLoading } = useQuery({
        queryKey: ["second-purchase"],
        queryFn: () => reportsApi.secondPurchase(),
        staleTime: 60_000,
    });

    if (isLoading || !data)
        return (
            <div className="flex justify-center py-16">
                <Spinner />
            </div>
        );

    const { summary, cohorts, worklist } = data;

    const askMessage = (row: SecondPurchaseWorklistRow) => {
        const firstName = (row.name || "").trim().split(/\s+/)[0] || "there";
        return (
            `Hello ${firstName}, it's Bethany House 🙏 Thank you for your recent order ` +
            `${row.order_number} — we hope it's serving you well! Is there anything else ` +
            `we can prepare for you? We deliver.`
        );
    };

    return (
        <div className="space-y-6">
            {/* Why this tab exists, in one line the reader can act on. */}
            <p className="text-sm text-surface-500 max-w-3xl">
                Most of this business&rsquo;s revenue comes from customers who bought once and never
                returned. This is the funnel for the one conversion that changes that — first
                purchase to second — and the list of recent first-time buyers to call.
            </p>

            <div className={KPI_GRID}>
                <KpiCard label="First-time buyers (365d)" value={summary.first_time_buyers} />
                <KpiCard label="Came back" value={summary.returned}
                         sub={`${summary.repeat_rate_pct}% repeat rate`} />
                <KpiCard label="Median days to 2nd order"
                         value={summary.median_days_to_second ?? "—"}
                         sub="among those who returned" />
                <KpiCard label="10% conversion is worth"
                         value={summary.opportunity_10pct_kes !== null ? fmtKes(summary.opportunity_10pct_kes) : "—"}
                         sub={`${summary.one_time_buyers} one-time buyers x avg ${summary.avg_first_order_kes !== null ? fmtKes(summary.avg_first_order_kes) : "—"}`} />
            </div>

            {/* Cohorts */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-3">
                    <p className="font-semibold text-surface-900">Return rate by first-purchase month</p>
                    <p className="text-xs text-surface-500 mt-0.5">
                        Of the customers whose FIRST order was in each month, how many came back.
                        A greyed rate means the cohort hasn&rsquo;t had that long yet — it is a floor,
                        not a verdict.
                    </p>
                </div>
                <TableWrapper>
                    <table className="table">
                        <thead>
                            <tr>
                                <th className={TH}>First purchase in</th>
                                <th className={TH_R}>New customers</th>
                                <th className={TH_R}>Back in 30d</th>
                                <th className={TH_R}>Back in 60d</th>
                                <th className={TH_R}>Back in 90d</th>
                            </tr>
                        </thead>
                        <tbody>
                            {!cohorts.length && <EmptyRow cols={5} text="No first-time buyers in the last 12 months." />}
                            {cohorts.map((c) => (
                                <tr key={c.cohort}>
                                    <td className="font-medium">{dayjs(c.cohort + "-01").format("MMM YYYY")}</td>
                                    <td className="text-right tabular-nums">{c.first_time_buyers}</td>
                                    {([30, 60, 90] as const).map((w) => {
                                        const rate   = c[`rate_${w}_pct` as const];
                                        const count  = c[`returned_${w}` as const];
                                        const mature = c[`mature_${w}` as const];
                                        return (
                                            <td key={w}
                                                className={clsx("text-right tabular-nums", !mature && "text-surface-300")}
                                                title={mature ? undefined : "Cohort hasn't had this long yet"}>
                                                {rate}% <span className="text-2xs">({count})</span>{!mature && "*"}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>

            {/* The worklist */}
            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-3">
                    <p className="font-semibold text-surface-900">
                        One-time buyers to call — last {data.recent_days} days, biggest first
                    </p>
                    <p className="text-xs text-surface-500 mt-0.5">
                        They chose the shop once, recently. A thank-you and a question is usually
                        all the second sale needs.
                    </p>
                </div>
                <TableWrapper>
                    <table className="table">
                        <thead>
                            <tr>
                                <th className={TH}>Customer</th>
                                <th className={TH}>First order</th>
                                <th className={TH_R}>Value</th>
                                <th className={TH_R}>Days ago</th>
                                <th className={TH_R}>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {!worklist.length && <EmptyRow cols={5} text="No recent one-time buyers. Every recent customer has already returned." />}
                            {worklist.map((w) => (
                                <tr key={w.order_id}>
                                    <td>
                                        <p className="text-sm font-medium text-surface-900">{w.name ?? "—"}</p>
                                        {w.phone && <p className="text-xs text-surface-400">{w.phone}</p>}
                                    </td>
                                    <td>
                                        <span className="font-mono text-xs text-brand-600">{w.order_number}</span>
                                    </td>
                                    <td className="text-right tabular-nums font-semibold">
                                        {w.currency_code === "KES"
                                            ? fmtKes(w.total_amount)
                                            : (<>
                                                {w.currency_code} {w.total_amount.toLocaleString()}
                                                <span className="block text-2xs font-normal text-surface-400">
                                                    {w.total_kes !== null ? `≈ ${fmtKes(w.total_kes)}` : "no reporting rate"}
                                                </span>
                                               </>)}
                                    </td>
                                    <td className="text-right tabular-nums">{w.days_since}d</td>
                                    <td className="text-right">
                                        {w.phone ? (
                                            <button className="btn-ghost btn-sm"
                                                    onClick={() => openWhatsApp(w.phone, askMessage(w))}>
                                                WhatsApp
                                            </button>
                                        ) : (
                                            <span className="text-xs text-surface-300">no phone</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>
        </div>
    );
}

function WinBackTab() {
    const queryClient = useQueryClient();
    const [loggingKey, setLoggingKey] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["win-back-economics"],
        queryFn: () => reportsApi.winBack(),
        staleTime: 60_000,
    });

    if (isLoading || !data)
        return (
            <div className="flex justify-center py-16">
                <Spinner />
            </div>
        );

    const { summary, customers } = data;

    const winBackMessage = (row: WinBackCustomerRow) => {
        const firstName = (row.name || "").trim().split(/\s+/)[0] || "there";
        return (
            `Hello ${firstName}, it's Bethany House 🙏 We've missed serving you! ` +
            `It's been a while since your last order — anything we can prepare for you? We deliver.`
        );
    };

    const logOutreach = async (
        row: WinBackCustomerRow,
        channel: "whatsapp" | "call",
    ) => {
        setLoggingKey(row.ckey);
        try {
            await reportsApi.logWinBackOutreach({
                customer_id: row.customer_id ?? undefined,
                phone: row.phone ?? undefined,
                name: row.name,
                channel,
                revenue_365: row.revenue_365,
                days_quiet: row.days_quiet,
            });
            await queryClient.invalidateQueries({
                queryKey: ["win-back-economics"],
            });
        } finally {
            setLoggingKey(null);
        }
    };

    const handleWhatsApp = (row: WinBackCustomerRow) => {
        // Open first (popup blockers dislike opens after an await), then log.
        const opened = openWhatsApp(row.phone, winBackMessage(row));
        if (opened) void logOutreach(row, "whatsapp");
    };

    return (
        <div className="space-y-6">
            <div className="flex justify-end">
                <ExportCsvButton path="win-back" params={{}} />
            </div>

            <div className={KPI_GRID}>
                <KpiCard
                    label="Value at Risk"
                    value={fmtKes(summary.annual_value_at_risk)}
                    sub={`${summary.customers_at_risk} customer${summary.customers_at_risk === 1 ? "" : "s"} gone quiet`}
                    color="text-danger"
                />
                <KpiCard
                    label="Contacted (30d)"
                    value={summary.contacted_30d}
                    sub="Outreach logged"
                />
                <KpiCard
                    label="Won Back (90d)"
                    value={summary.won_back_90d}
                    sub="Ordered within 30d of outreach"
                    color="text-success"
                />
                <KpiCard
                    label="Recovered (90d)"
                    value={fmtKes(summary.recovered_revenue_90d)}
                    sub="First order after outreach"
                    color="text-success"
                />
                <KpiCard
                    label="Win-back Rate"
                    value={`${summary.win_back_rate_pct}%`}
                    sub="Of customers contacted (90d)"
                    color="text-brand-600"
                />
            </div>

            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Dormant customers — biggest annual value first" />
                    <p className="text-sm text-surface-500 mt-1">
                        Each customer is measured against their OWN purchase
                        rhythm: "3.2× overdue" means over three of their usual
                        gaps have passed in silence (minimum 45 days quiet).
                        WhatsApp and Log call both record the outreach, so
                        orders placed within 30 days count as won back.
                    </p>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-line bg-surface-50/50">
                                <th className={TH}>Customer</th>
                                <th className={TH}>Phone</th>
                                <th className={TH_R}>Annual Value</th>
                                <th className={TH_R}>Orders</th>
                                <th className={TH}>Last Order</th>
                                <th className={TH_R}>Days Quiet</th>
                                <th className={TH}>Urgency</th>
                                <th className={TH}>Last Contacted</th>
                                <th className={TH}>Status</th>
                                <th className={TH}></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {customers.length === 0 ? (
                                <EmptyRow cols={10} />
                            ) : (
                                customers.map((row) => (
                                    <tr
                                        key={row.ckey}
                                        className="hover:bg-surface-50/50 transition-colors"
                                    >
                                        <td className="px-4 py-3 font-medium text-surface-900">
                                            {row.name}
                                            <span className="text-2xs text-surface-400 block">
                                                usual gap ~
                                                {Math.round(
                                                    row.median_gap_days,
                                                )}
                                                d
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-500 font-mono">
                                            {row.phone ?? "-"}
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                            {fmtKes(row.revenue_365)}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                            {row.orders_365}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-500 whitespace-nowrap">
                                            {dayjs(row.last_order_at).format(
                                                "D MMM YY",
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums font-bold text-amber-700">
                                            {row.days_quiet}d
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={clsx(
                                                    "px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap",
                                                    row.urgency >= 3
                                                        ? "bg-danger-light text-danger"
                                                        : "bg-warning-light text-warning",
                                                )}
                                                title="How many of this customer's usual purchase gaps have passed in silence"
                                            >
                                                {row.urgency}× overdue
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-500 whitespace-nowrap">
                                            {row.last_outreach ? (
                                                <span
                                                    title={
                                                        row.last_outreach
                                                            .by_name
                                                            ? `By ${row.last_outreach.by_name}`
                                                            : undefined
                                                    }
                                                >
                                                    <span className="capitalize">
                                                        {
                                                            row.last_outreach
                                                                .channel
                                                        }
                                                    </span>{" "}
                                                    ·{" "}
                                                    {Math.max(
                                                        0,
                                                        dayjs().diff(
                                                            dayjs(
                                                                row
                                                                    .last_outreach
                                                                    .at,
                                                            ),
                                                            "day",
                                                        ),
                                                    )}
                                                    d ago
                                                </span>
                                            ) : (
                                                <span className="text-surface-300">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.recovered ? (
                                                <span
                                                    className="px-2 py-0.5 rounded text-xs font-medium bg-success-light text-success whitespace-nowrap"
                                                    title={`Order ${row.recovered.order_number} on ${dayjs(row.recovered.at).format("D MMM YY")}`}
                                                >
                                                    won back +
                                                    {fmtKes(
                                                        row.recovered.amount,
                                                    )}
                                                </span>
                                            ) : (
                                                <span className="text-surface-300 text-sm">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1.5">
                                                {row.phone && (
                                                    <button
                                                        onClick={() =>
                                                            handleWhatsApp(row)
                                                        }
                                                        disabled={
                                                            loggingKey ===
                                                            row.ckey
                                                        }
                                                        className="inline-flex items-center px-2.5 py-1.5 rounded-lg text-xs font-medium bg-success-light text-success hover:opacity-80 transition-opacity whitespace-nowrap disabled:opacity-50"
                                                        title="Open WhatsApp with a prefilled win-back message and log the outreach"
                                                    >
                                                        WhatsApp
                                                    </button>
                                                )}
                                                <button
                                                    onClick={() =>
                                                        void logOutreach(
                                                            row,
                                                            "call",
                                                        )
                                                    }
                                                    disabled={
                                                        loggingKey === row.ckey
                                                    }
                                                    className="inline-flex items-center px-2.5 py-1.5 rounded-lg text-xs font-medium bg-surface-100 text-surface-700 border border-line hover:bg-surface-200 transition-colors whitespace-nowrap disabled:opacity-50"
                                                    title="Record that this customer was called"
                                                >
                                                    Log call
                                                </button>
                                            </div>
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

// ─── Institutional accounts tab ───────────────────────────────────────────────
// Churches and institutions as ACCOUNTS: trailing-365 rollups, buyer-turnover
// risk (the person who orders for the church changes; the church remains), and
// cohort cross-sell ("6 of 9 churches buy prefilled cups"). Identification is
// heuristic — customer_type='business' or an institution keyword in the name.

function InstitutionsTab() {
    const [expandedKey, setExpandedKey] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["institutional-accounts"],
        queryFn: () => reportsApi.institutionalAccounts(),
        staleTime: 60_000,
    });

    if (isLoading || !data)
        return (
            <div className="flex justify-center py-16">
                <Spinner />
            </div>
        );

    const { summary, accounts } = data;

    const institutionMessage = (row: InstitutionalAccountRow) =>
        `Hello! Greetings from Bethany House 🙏 We value serving ${row.name}. ` +
        `May we share what other congregations are finding useful this season?`;

    return (
        <div className="space-y-6">
            <div className="flex justify-end">
                <ExportCsvButton path="institutions" params={{}} />
            </div>

            <div className={KPI_GRID}>
                <KpiCard
                    label="Institutions"
                    value={summary.institutions}
                    sub="Churches & business accounts"
                />
                <KpiCard
                    label="Their Revenue Share"
                    value={`${summary.share_of_total_revenue_pct}%`}
                    sub={`${fmtKes(summary.revenue_365_total)} of the last 365d`}
                    color="text-brand-600"
                />
                <KpiCard
                    label="At Risk"
                    value={summary.at_risk_count}
                    sub="Quiet 60+ days, KES 10k+ value"
                    color={
                        summary.at_risk_count > 0
                            ? "text-danger"
                            : "text-success"
                    }
                />
                <KpiCard
                    label="At-risk Value"
                    value={fmtKes(summary.at_risk_value)}
                    sub="Annual revenue gone quiet"
                    color={
                        summary.at_risk_value > 0
                            ? "text-danger"
                            : "text-success"
                    }
                />
            </div>

            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Institutional accounts — biggest annual value first" />
                    <p className="text-sm text-surface-500 mt-1">
                        Identified from the customer type and church/institution
                        keywords in the name (PCEA, AIC, chapel, ministries, …).
                        More than one buyer contact means a different person has
                        ordered for this institution before — when it goes
                        quiet, the buyer may have changed, not the need. Expand
                        a row for what other congregations buy that this one
                        never has.
                    </p>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-line bg-surface-50/50">
                                <th className={clsx(TH, "w-8")}></th>
                                <th className={TH}>Institution</th>
                                <th className={TH}>Phone</th>
                                <th className={TH_R}>Annual Value</th>
                                <th className={TH_R}>Orders</th>
                                <th className={TH}>Last Order</th>
                                <th className={TH_R}>Buyers</th>
                                <th className={TH}>Top Product</th>
                                <th className={TH}></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {accounts.length === 0 ? (
                                <EmptyRow cols={9} />
                            ) : (
                                accounts.map((row) => {
                                    const expanded = expandedKey === row.ckey;
                                    return (
                                        <Fragment key={row.ckey}>
                                            <tr
                                                className="hover:bg-surface-50/50 transition-colors cursor-pointer"
                                                onClick={() =>
                                                    setExpandedKey(
                                                        expanded
                                                            ? null
                                                            : row.ckey,
                                                    )
                                                }
                                            >
                                                <td className="px-4 py-3 text-surface-400 text-xs">
                                                    {expanded ? "▾" : "▸"}
                                                </td>
                                                <td className="px-4 py-3 font-medium text-surface-900">
                                                    {row.name}
                                                    <span className="text-2xs text-surface-400 block">
                                                        {row.products_bought}{" "}
                                                        product
                                                        {row.products_bought ===
                                                        1
                                                            ? ""
                                                            : "s"}{" "}
                                                        bought
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-500 font-mono">
                                                    {row.phone ?? "-"}
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                    {fmtKes(row.revenue_365)}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums text-surface-600">
                                                    {row.orders_365}
                                                </td>
                                                <td className="px-4 py-3 text-sm whitespace-nowrap">
                                                    <span className="text-surface-500">
                                                        {dayjs(
                                                            row.last_order_at,
                                                        ).format("D MMM YY")}
                                                    </span>{" "}
                                                    <span
                                                        className={clsx(
                                                            "ml-1 px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap",
                                                            row.risk
                                                                ? "bg-danger-light text-danger"
                                                                : "bg-surface-100 text-surface-500",
                                                        )}
                                                        title={
                                                            row.risk
                                                                ? "Quiet 60+ days with KES 10,000+ annual value"
                                                                : undefined
                                                        }
                                                    >
                                                        {row.days_quiet}d quiet
                                                        {row.risk
                                                            ? " · at risk"
                                                            : ""}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right whitespace-nowrap">
                                                    <span className="tabular-nums text-surface-600">
                                                        {row.buyer_contacts}
                                                    </span>
                                                    {row.buyer_contacts > 1 && (
                                                        <span
                                                            className="ml-1.5 px-1.5 py-0.5 rounded text-2xs font-medium bg-warning-light text-warning align-middle"
                                                            title="More than one phone/email has ordered for this institution — the buyer may have changed"
                                                        >
                                                            buyer changed?
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-700">
                                                    {row.top_product ?? "-"}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {row.phone && (
                                                        <button
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                openWhatsApp(
                                                                    row.phone,
                                                                    institutionMessage(
                                                                        row,
                                                                    ),
                                                                );
                                                            }}
                                                            className="inline-flex items-center px-2.5 py-1.5 rounded-lg text-xs font-medium bg-success-light text-success hover:opacity-80 transition-opacity whitespace-nowrap"
                                                            title="Open WhatsApp with a prefilled account-care message"
                                                        >
                                                            WhatsApp
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                            {expanded && (
                                                <tr className="bg-surface-50/50">
                                                    <td
                                                        colSpan={9}
                                                        className="px-4 py-3"
                                                    >
                                                        {row.cross_sell
                                                            .length === 0 ? (
                                                            <p className="text-xs text-surface-400">
                                                                No cross-sell
                                                                gaps — this
                                                                institution
                                                                already buys
                                                                everything
                                                                popular with the
                                                                cohort.
                                                            </p>
                                                        ) : (
                                                            <div className="flex items-center gap-2 flex-wrap">
                                                                <span className="text-xs font-medium text-surface-500">
                                                                    Also popular
                                                                    with
                                                                    churches:
                                                                </span>
                                                                {row.cross_sell.map(
                                                                    (p) => (
                                                                        <span
                                                                            key={
                                                                                p.product_id
                                                                            }
                                                                            className="px-2.5 py-1 rounded-full text-xs font-medium bg-brand-50 text-brand-700 border border-brand-200 whitespace-nowrap"
                                                                            title={`${p.adoption_pct}% of identified institutions buy this`}
                                                                        >
                                                                            {
                                                                                p.name
                                                                            }{" "}
                                                                            (
                                                                            {
                                                                                p.adoption_pct
                                                                            }
                                                                            %)
                                                                        </span>
                                                                    ),
                                                                )}
                                                            </div>
                                                        )}
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </TableWrapper>
            </div>
        </div>
    );
}

// ─── Outreach log tab ─────────────────────────────────────────────────────────
// The unified audit trail behind every proactive contact the Hub makes:
// automated replenishment-radar WhatsApp pings (🎯, by "automation") merged
// with manual win-back outreach (🔄, by whoever logged it), newest first,
// each row carrying its attributed outcome — "reordered" when the pinged
// phone bought the product again within a cycle, "won back +KES X" when a
// contacted customer ordered within 30 days.

function OutreachLogTab() {
    const { data, isLoading } = useQuery({
        queryKey: ["outreach-log"],
        queryFn: () => reportsApi.outreachLog(),
        staleTime: 60_000,
    });

    if (isLoading || !data)
        return (
            <div className="flex justify-center py-16">
                <Spinner />
            </div>
        );

    const { summary, rows } = data;

    const relative = (at: string) => {
        const days = Math.max(0, dayjs().diff(dayjs(at), "day"));
        if (days === 0) return "today";
        if (days === 1) return "yesterday";
        return `${days}d ago`;
    };

    const statusPill = (status: string) =>
        clsx(
            "px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap",
            status === "sent"
                ? "bg-success-light text-success"
                : status === "failed"
                  ? "bg-danger-light text-danger"
                  : status === "logged"
                    ? "bg-brand-50 text-brand-700"
                    : "bg-surface-100 text-surface-500",
        );

    return (
        <div className="space-y-6">
            <div className="flex justify-end">
                <ExportCsvButton path="outreach-log" params={{}} />
            </div>

            <div className={KPI_GRID}>
                <KpiCard
                    label="Pings (30d)"
                    value={summary.pings_30d}
                    sub="Automated WhatsApp reminders sent"
                />
                <KpiCard
                    label="Manual Outreach (30d)"
                    value={summary.outreach_30d}
                    sub="Win-back contacts logged"
                />
                <KpiCard
                    label="Failures (30d)"
                    value={summary.failures_30d}
                    sub="Pings Meta rejected"
                    color={
                        summary.failures_30d > 0
                            ? "text-danger"
                            : "text-success"
                    }
                />
                <KpiCard
                    label="Won Back (30d)"
                    value={summary.won_back_30d}
                    sub="Outreach followed by an order"
                    color="text-success"
                />
            </div>

            <div className="card overflow-hidden">
                <div className="px-5 pt-5 pb-4">
                    <SectionHeader title="Recent outreach — newest first" />
                    <p className="text-sm text-surface-500 mt-1">
                        Every proactive contact in one feed: 🎯 automated
                        replenishment pings and 🔄 manual win-back outreach
                        (latest 100). Outcomes use the engines' own
                        attribution — "reordered" means the pinged phone bought
                        the product again within one cycle; "won back" means an
                        order landed within 30 days of the contact.
                    </p>
                </div>
                <TableWrapper>
                    <table className="w-full">
                        <thead>
                            <tr className="border-y border-line bg-surface-50/50">
                                <th className={TH}>Type</th>
                                <th className={TH}>When</th>
                                <th className={TH}>Who</th>
                                <th className={TH}>Channel</th>
                                <th className={TH}>Product</th>
                                <th className={TH}>Status</th>
                                <th className={TH}>By</th>
                                <th className={TH}>Outcome</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {rows.length === 0 ? (
                                <EmptyRow cols={8} />
                            ) : (
                                rows.map((row: OutreachLogRow, i: number) => (
                                    <tr
                                        key={`${row.type}-${row.at}-${i}`}
                                        className="hover:bg-surface-50/50 transition-colors"
                                    >
                                        <td className="px-4 py-3 text-sm whitespace-nowrap">
                                            {row.type === "radar_ping" ? (
                                                <span title="Automated replenishment-radar ping">
                                                    🎯 Radar ping
                                                </span>
                                            ) : (
                                                <span title="Manual win-back outreach">
                                                    🔄 Win-back
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className="px-4 py-3 text-sm text-surface-500 whitespace-nowrap"
                                            title={dayjs(row.at).format(
                                                "D MMM YY, HH:mm",
                                            )}
                                        >
                                            {relative(row.at)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-medium text-surface-900">
                                                {row.name}
                                            </span>
                                            {row.phone && (
                                                <span className="text-2xs text-surface-400 font-mono block">
                                                    {row.phone}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-600 capitalize">
                                            {row.channel}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-700">
                                            {row.product_name ?? (
                                                <span className="text-surface-300">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={statusPill(
                                                    row.status,
                                                )}
                                            >
                                                {row.status.replace(/_/g, " ")}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-surface-600">
                                            {row.by}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.outcome !== "—" ? (
                                                <span className="px-2 py-0.5 rounded text-xs font-medium bg-success-light text-success whitespace-nowrap">
                                                    {row.outcome}
                                                </span>
                                            ) : (
                                                <span className="text-surface-300 text-sm">
                                                    —
                                                </span>
                                            )}
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
