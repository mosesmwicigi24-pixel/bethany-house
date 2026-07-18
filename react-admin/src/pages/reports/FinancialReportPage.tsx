// src/pages/reports/FinancialReportPage.tsx
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
    DateRangePicker,
    ReportPageHeader,
    useDateRange,
    ComparisonToggle,
    ProgressBar,
    StatusPill,
    CHART_COLORS,
    TH,
    TH_R,
    ChangeBadge,
} from "./reportShared";

export default function FinancialReportPage() {
    const dr = useDateRange("last_30_days");
    const [compare, setCompare] = useState(false);
    const [activeTab, setActiveTab] = useState<"pl" | "expenses" | "trends" | "tax" | "cashflow" | "intelligence">(
        "pl",
    );
    const [expStatus, setExpStatus] = useState("");

    const plQuery = useQuery({
        queryKey: ["report-pl", dr.start, dr.end, compare],
        queryFn: () =>
            reportsApi.profitLoss({
                ...dr.params,
                compare: compare ? 1 : undefined,
            }),
        enabled: !!dr.start && !!dr.end,
    });
    const expQuery = useQuery({
        queryKey: ["report-expenses", dr.start, dr.end, expStatus],
        queryFn: () =>
            reportsApi.expenses({
                ...dr.params,
                ...(expStatus ? { status: expStatus } : {}),
            }),
        enabled: !!dr.start && !!dr.end,
    });
    const revQuery = useQuery({
        queryKey: ["report-revenue", dr.start, dr.end],
        queryFn: () => reportsApi.revenue(dr.params),
        enabled: !!dr.start && !!dr.end,
    });
    const taxQuery = useQuery({
        queryKey: ["report-tax", dr.start, dr.end],
        queryFn: () => reportsApi.taxReport(dr.params),
        enabled: !!dr.start && !!dr.end && activeTab === "tax",
    });
    const cashFlowQuery = useQuery({
        queryKey: ["report-cashflow", dr.start, dr.end],
        queryFn: () => reportsApi.cashFlow(dr.params),
        enabled: !!dr.start && !!dr.end && activeTab === "cashflow",
    });

    if (plQuery.isLoading)
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );

    const pl = plQuery.data ?? {};
    const cmp = pl.comparison;

    const revenue = Number(pl.revenue ?? 0);
    const cogs = Number(pl.cost_of_goods_sold ?? 0);
    const grossProfit = Number(pl.gross_profit ?? 0);
    const grossMargin = Number(pl.gross_profit_margin_percent ?? 0);
    const opex = Number(pl.operating_expenses ?? 0);
    const netProfit = Number(pl.net_profit ?? 0);
    const netMargin = revenue > 0 ? Math.round((netProfit / revenue) * 100) : 0;

    // Revenue vs expense chart
    const revMonths = revQuery.data?.monthly ?? [];
    const expMonths = expQuery.data?.monthly ?? [];
    const chartData = (() => {
        const months = new Set([
            ...revMonths.map((m: any) => m.month),
            ...expMonths.map((m: any) => m.month),
        ]);
        return Array.from(months)
            .sort()
            .map((m) => ({
                month: m,
                revenue: Number(
                    revMonths.find((x: any) => x.month === m)?.total ?? 0,
                ),
                expenses: Number(
                    expMonths.find((x: any) => x.month === m)?.total ?? 0,
                ),
            }));
    })();

    // Expense categories for pie
    const expCats = pl.expenses_by_category ?? [];
    const totalExpCat = expCats.reduce(
        (s: number, c: any) => s + Number(c.total),
        0,
    );

    const plRows = [
        { label: "Revenue", value: revenue, indent: 0, bold: false },
        {
            label: "(–) Cost of Goods Sold",
            value: cogs,
            indent: 1,
            bold: false,
            neg: true,
        },
        {
            label: "Gross Profit",
            value: grossProfit,
            indent: 0,
            bold: true,
            sub: `${grossMargin}% margin`,
        },
        {
            label: "(–) Operating Expenses",
            value: opex,
            indent: 1,
            bold: false,
            neg: true,
        },
        {
            label: "Net Profit",
            value: netProfit,
            indent: 0,
            bold: true,
            final: true,
            sub: `${netMargin}% net margin`,
        },
    ];

    return (
        <div className="space-y-6 animate-fade-in">
            <ReportPageHeader
                title="Financial Report"
                subtitle="Profit & loss, revenue trends, and operating expenses."
                reportType="financial"
                exportPath="financial/profit-loss"
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

            {/* KPIs */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <KpiCard
                    label="Revenue"
                    value={fmtKes(revenue)}
                    sub="Gross paid sales"
                    comparison={cmp?.revenue_change_pct}
                />
                <KpiCard
                    label="Gross Profit"
                    value={fmtKes(grossProfit)}
                    sub={`${grossMargin}% margin`}
                    color={grossProfit >= 0 ? "text-success" : "text-danger"}
                />
                <KpiCard
                    label="Net Profit"
                    value={fmtKes(netProfit)}
                    sub={`${netMargin}% net margin`}
                    color={netProfit >= 0 ? "text-success" : "text-danger"}
                    comparison={cmp?.net_profit_change_pct}
                />
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <KpiCard
                    label="Opex"
                    value={fmtKes(opex)}
                    color="text-danger"
                    comparison={cmp?.opex_change_pct}
                />
                <KpiCard
                    label="Tax Collected"
                    value={fmtKes(pl.tax_collected)}
                />
                <KpiCard
                    label="Discounts Given"
                    value={fmtKes(pl.discounts_given)}
                    color="text-warning"
                />
                <KpiCard
                    label="Expense Count"
                    value={expQuery.data?.expenses?.length ?? "-"}
                    sub="Approved + paid"
                />
            </div>

            {/* Tabs */}
            <div className="border-b border-surface-100 overflow-x-auto no-scrollbar">
                <nav className="flex gap-1 -mb-px">
                    {(["pl", "expenses", "trends", "tax", "cashflow", "intelligence"] as const).map((tab) => (
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
                            {tab === "pl" ? "P&L Statement"
                                : tab === "expenses" ? "Expenses"
                                : tab === "trends" ? "Trends"
                                : tab === "tax" ? "Tax"
                                : "Cash Flow"}
                        </button>
                    ))}
                </nav>
            </div>

            {/* ── P&L TAB ── */}
            {activeTab === "intelligence" && (
                <FinancialIntelligence start={dr.start} end={dr.end} />
            )}

            {activeTab === "pl" && (
                <div className="space-y-6">
                    <div className="card p-6">
                        <SectionHeader title="Profit & Loss Statement">
                            <ExportCsvButton
                                path="financial/profit-loss"
                                params={dr.params}
                            />
                        </SectionHeader>
                        <div className="divide-y divide-surface-100">
                            {plRows.map((row, i) => (
                                <div
                                    key={i}
                                    className={clsx(
                                        "flex justify-between items-center py-3",
                                        row.indent === 1 ? "pl-6" : "",
                                        row.final
                                            ? "border-t-2 border-surface-900 pt-4 mt-1"
                                            : "",
                                    )}
                                >
                                    <div>
                                        <span
                                            className={clsx(
                                                "text-sm",
                                                row.bold
                                                    ? "font-semibold text-surface-900"
                                                    : "text-surface-600",
                                                row.final
                                                    ? "text-base font-bold"
                                                    : "",
                                            )}
                                        >
                                            {row.label}
                                        </span>
                                        {row.sub && (
                                            <p className="text-xs text-surface-400 mt-0.5">
                                                {row.sub}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        {compare &&
                                            cmp &&
                                            row.label === "Revenue" && (
                                                <ChangeBadge
                                                    pct={cmp.revenue_change_pct}
                                                />
                                            )}
                                        {compare &&
                                            cmp &&
                                            row.label === "Net Profit" && (
                                                <ChangeBadge
                                                    pct={
                                                        cmp.net_profit_change_pct
                                                    }
                                                />
                                            )}
                                        <span
                                            className={clsx(
                                                "tabular-nums text-sm",
                                                row.bold ? "font-semibold" : "",
                                                row.final
                                                    ? "text-base font-bold"
                                                    : "",
                                                row.neg
                                                    ? "text-danger"
                                                    : Number(row.value) < 0
                                                      ? "text-danger"
                                                      : "text-surface-900",
                                            )}
                                        >
                                            {row.neg
                                                ? `(${fmtKes(Math.abs(row.value ?? 0))})`
                                                : fmtKes(row.value)}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Opex by category */}
                    {expCats.length > 0 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="card p-5">
                                <SectionHeader title="Expenses by Category" />
                                <ResponsiveContainer width="100%" height={220}>
                                    <PieChart>
                                        <Pie
                                            data={expCats}
                                            dataKey="total"
                                            nameKey="category"
                                            cx="50%"
                                            cy="50%"
                                            outerRadius={80}
                                            label={({
                                                category,
                                                percent,
                                            }: any) =>
                                                `${category} ${(percent * 100).toFixed(0)}%`
                                            }
                                            labelLine={false}
                                        >
                                            {expCats.map(
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
                            <div className="card p-5">
                                <SectionHeader title="Category Breakdown" />
                                <div className="space-y-3 mt-2">
                                    {expCats.map((c: any, i: number) => {
                                        const pct =
                                            totalExpCat > 0
                                                ? Math.round(
                                                      (Number(c.total) /
                                                          totalExpCat) *
                                                          100,
                                                  )
                                                : 0;
                                        return (
                                            <div key={c.category}>
                                                <div className="flex justify-between text-sm mb-1">
                                                    <span className="text-surface-700">
                                                        {c.category}
                                                    </span>
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs text-surface-400">
                                                            {c.count} expenses
                                                        </span>
                                                        <span className="font-medium tabular-nums text-danger">
                                                            ({fmtKes(c.total)})
                                                        </span>
                                                    </div>
                                                </div>
                                                <ProgressBar
                                                    value={c.total}
                                                    max={totalExpCat}
                                                    color={
                                                        CHART_COLORS[
                                                            i %
                                                                CHART_COLORS.length
                                                        ]
                                                    }
                                                />
                                                <p className="text-xs text-surface-400 mt-0.5">
                                                    {pct}% of total opex
                                                </p>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* ── EXPENSES TAB ── */}
            {activeTab === "expenses" && (
                <div className="space-y-4">
                    <div className="flex items-center gap-3">
                        <select
                            className="input w-40 text-sm"
                            value={expStatus}
                            onChange={(e) => setExpStatus(e.target.value)}
                        >
                            <option value="">All statuses</option>
                            <option value="draft">Draft</option>
                            <option value="pending_approval">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="paid">Paid</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <ExportCsvButton
                            path="financial/expenses"
                            params={{
                                ...dr.params,
                                ...(expStatus ? { status: expStatus } : {}),
                            }}
                        />
                    </div>

                    {/* By status summary */}
                    {(expQuery.data?.by_status ?? []).length > 0 && (
                        <div className="flex gap-3 flex-wrap">
                            {expQuery.data.by_status.map((s: any) => (
                                <div
                                    key={s.status}
                                    className="card card-body flex-1 min-w-32"
                                >
                                    <StatusPill status={s.status} />
                                    <p className="text-lg font-bold tabular-nums mt-2">
                                        {fmtKes(s.total)}
                                    </p>
                                    <p className="text-xs text-surface-400">
                                        {s.count} expenses
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="card overflow-hidden">
                        <TableWrapper>
                            <table className="w-full">
                                <thead>
                                    <tr className="border-y border-surface-100 bg-surface-50/50">
                                        <th className={TH}>Ref #</th>
                                        <th className={TH}>Title</th>
                                        <th className={TH}>Category</th>
                                        <th className={TH}>Outlet</th>
                                        <th className={TH}>Vendor</th>
                                        <th className={TH}>Date</th>
                                        <th className={TH}>Status</th>
                                        <th className={TH_R}>Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-50">
                                    {expQuery.isLoading ? (
                                        <tr>
                                            <td
                                                colSpan={8}
                                                className="px-4 py-8 text-center"
                                            >
                                                <Spinner />
                                            </td>
                                        </tr>
                                    ) : (expQuery.data?.expenses ?? [])
                                          .length === 0 ? (
                                        <EmptyRow cols={8} />
                                    ) : (
                                        (expQuery.data.expenses ?? []).map(
                                            (exp: any) => (
                                                <tr
                                                    key={exp.id}
                                                    className="hover:bg-surface-50/50 transition-colors"
                                                >
                                                    <td className="px-4 py-3 font-mono text-xs text-surface-500">
                                                        {exp.reference_number}
                                                    </td>
                                                    <td className="px-4 py-3 font-medium text-surface-900 max-w-xs truncate">
                                                        {exp.title}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-surface-600">
                                                        {exp.category ?? "-"}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-surface-500">
                                                        {exp.outlet_name ?? "-"}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-surface-500">
                                                        {exp.vendor_name ?? "-"}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-surface-500">
                                                        {exp.date
                                                            ? dayjs(
                                                                  exp.date,
                                                              ).format(
                                                                  "D MMM YYYY",
                                                              )
                                                            : "-"}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <StatusPill
                                                            status={exp.status}
                                                        />
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-semibold tabular-nums text-danger">
                                                        ({fmtKes(exp.amount)})
                                                    </td>
                                                </tr>
                                            ),
                                        )
                                    )}
                                    {expQuery.data?.total !== undefined && (
                                        <tr className="bg-surface-50 border-t border-surface-200">
                                            <td
                                                colSpan={7}
                                                className="px-4 py-3 font-semibold text-surface-900"
                                            >
                                                Total
                                            </td>
                                            <td className="px-4 py-3 text-right font-bold tabular-nums text-danger">
                                                ({fmtKes(expQuery.data.total)})
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </TableWrapper>
                    </div>
                </div>
            )}

            {/* ── TRENDS TAB ── */}
            {activeTab === "trends" && (
                <div className="space-y-6">
                    {chartData.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Revenue vs Expenses (Monthly)" />
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart data={chartData}>
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
                                    <Legend />
                                    <Bar
                                        dataKey="revenue"
                                        name="Revenue"
                                        fill={CHART_COLORS[0]}
                                        radius={[3, 3, 0, 0]}
                                    />
                                    <Bar
                                        dataKey="expenses"
                                        name="Expenses"
                                        fill={CHART_COLORS[3]}
                                        radius={[3, 3, 0, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}

                    {/* Profit trend (derived) */}
                    {chartData.length > 0 && (
                        <div className="card p-5">
                            <SectionHeader title="Net Profit Trend" />
                            <ResponsiveContainer width="100%" height={200}>
                                <LineChart
                                    data={chartData.map((d) => ({
                                        ...d,
                                        profit: d.revenue - d.expenses,
                                    }))}
                                >
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
                                        dataKey="profit"
                                        stroke={CHART_COLORS[4]}
                                        strokeWidth={2}
                                        dot={false}
                                        name="Net Profit"
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </div>
            )}

            {/* ── TAX TAB ── */}
            {activeTab === "tax" && (
                <div className="space-y-6">
                    {taxQuery.isLoading ? (
                        <div className="flex justify-center py-20"><Spinner /></div>
                    ) : (
                        <>
                            {/* Tax KPIs */}
                            {(taxQuery.data?.totals) && (
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                                    <KpiCard label="Total Tax Collected" value={fmtKes(taxQuery.data.totals.total_tax_collected)} color="text-brand-600" />
                                    <KpiCard label="Taxable Revenue" value={fmtKes(taxQuery.data.totals.total_taxable_amount)} />
                                    <KpiCard label="Effective Tax Rate" value={`${taxQuery.data.totals.effective_rate ?? 0}%`} />
                                    <KpiCard label="Tax-Exempt Revenue" value={fmtKes(taxQuery.data.totals.exempt_amount)} />
                                </div>
                            )}

                            {/* Tax by rate breakdown */}
                            {(taxQuery.data?.by_tax_rate ?? []).length > 0 && (
                                <div className="card overflow-hidden">
                                    <div className="px-5 pt-5 pb-4">
                                        <SectionHeader title="Tax by Rate">
                                            <ExportCsvButton path="financial/tax" params={dr.params} />
                                        </SectionHeader>
                                    </div>
                                    <TableWrapper>
                                        <table className="w-full">
                                            <thead>
                                                <tr className="border-y border-surface-100 bg-surface-50/50">
                                                    <th className={TH}>Tax Name</th>
                                                    <th className={TH_R}>Rate</th>
                                                    <th className={TH_R}>Orders</th>
                                                    <th className={TH_R}>Taxable Amount</th>
                                                    <th className={TH_R}>Tax Collected</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-surface-50">
                                                {taxQuery.data.by_tax_rate.map((row: any, i: number) => (
                                                    <tr key={i} className="hover:bg-surface-50/50 transition-colors">
                                                        <td className="px-4 py-3 font-medium text-surface-900">{row.tax_name ?? row.tax_rate + "% VAT"}</td>
                                                        <td className="px-4 py-3 text-right tabular-nums">{row.tax_rate}%</td>
                                                        <td className="px-4 py-3 text-right tabular-nums">{row.order_count}</td>
                                                        <td className="px-4 py-3 text-right tabular-nums text-surface-600">{fmtKes(row.taxable_amount)}</td>
                                                        <td className="px-4 py-3 text-right font-semibold tabular-nums text-brand-600">{fmtKes(row.tax_collected)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </TableWrapper>
                                </div>
                            )}

                            {(taxQuery.data?.by_tax_rate ?? []).length === 0 && !taxQuery.isLoading && (
                                <div className="card p-10 text-center text-sm text-surface-400">No tax data for this period.</div>
                            )}
                        </>
                    )}
                </div>
            )}

            {/* ── CASH FLOW TAB ── */}
            {activeTab === "cashflow" && (
                <div className="space-y-6">
                    {cashFlowQuery.isLoading ? (
                        <div className="flex justify-center py-20"><Spinner /></div>
                    ) : (() => {
                        const inflows = cashFlowQuery.data?.inflows ?? [];
                        const outflows = cashFlowQuery.data?.outflows ?? [];

                        // Merge by month
                        const months = new Set([
                            ...inflows.map((m: any) => m.month),
                            ...outflows.map((m: any) => m.month),
                        ]);
                        const cfData = Array.from(months).sort().map(month => {
                            const inflow = inflows.filter((i: any) => i.month === month).reduce((s: number, i: any) => s + Number(i.inflow), 0);
                            const outflow = Number(outflows.find((o: any) => o.month === month)?.outflow ?? 0);
                            return { month, inflow, outflow, net: inflow - outflow };
                        });

                        const totalIn  = cfData.reduce((s, d) => s + d.inflow, 0);
                        const totalOut = cfData.reduce((s, d) => s + d.outflow, 0);

                        return (
                            <>
                                <div className="grid grid-cols-3 gap-3">
                                    <KpiCard label="Total Inflows"  value={fmtKes(totalIn)}  color="text-success" />
                                    <KpiCard label="Total Outflows" value={fmtKes(totalOut)} color="text-danger" />
                                    <KpiCard label="Net Cash Flow"  value={fmtKes(totalIn - totalOut)} color={totalIn >= totalOut ? "text-success" : "text-danger"} />
                                </div>

                                {cfData.length > 0 && (
                                    <div className="card p-5">
                                        <SectionHeader title="Monthly Cash Flow" />
                                        <ResponsiveContainer width="100%" height={280}>
                                            <BarChart data={cfData}>
                                                <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" />
                                                <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                                                <YAxis tickFormatter={(v: number) => `${(v/1000).toFixed(0)}K`} tick={{ fontSize: 11 }} width={48} />
                                                <Tooltip formatter={(v) => fmtKes(v as number)} />
                                                <Legend />
                                                <Bar dataKey="inflow"  name="Inflows"  fill={CHART_COLORS[4]} radius={[3,3,0,0]} />
                                                <Bar dataKey="outflow" name="Outflows" fill={CHART_COLORS[3]} radius={[3,3,0,0]} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                )}

                                {cfData.length > 0 && (
                                    <div className="card p-5">
                                        <SectionHeader title="Net Cash Flow Trend" />
                                        <ResponsiveContainer width="100%" height={200}>
                                            <LineChart data={cfData}>
                                                <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" />
                                                <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                                                <YAxis tickFormatter={(v: number) => `${(v/1000).toFixed(0)}K`} tick={{ fontSize: 11 }} width={48} />
                                                <Tooltip formatter={(v) => fmtKes(v as number)} />
                                                <Line type="monotone" dataKey="net" stroke={CHART_COLORS[0]} strokeWidth={2} dot={true} name="Net Flow" />
                                            </LineChart>
                                        </ResponsiveContainer>
                                    </div>
                                )}

                                {/* Inflows by payment method */}
                                {inflows.length > 0 && (() => {
                                    const byMethod: Record<string, number> = {};
                                    inflows.forEach((i: any) => {
                                        byMethod[i.payment_method] = (byMethod[i.payment_method] ?? 0) + Number(i.inflow);
                                    });
                                    return (
                                        <div className="card p-5">
                                            <SectionHeader title="Inflows by Payment Method" />
                                            <div className="space-y-3 mt-2">
                                                {Object.entries(byMethod).sort((a,b) => b[1]-a[1]).map(([method, total], i) => (
                                                    <div key={method}>
                                                        <div className="flex justify-between text-sm mb-1">
                                                            <span className="capitalize text-surface-700">{method?.replace(/_/g," ")}</span>
                                                            <span className="font-medium tabular-nums">{fmtKes(total)}</span>
                                                        </div>
                                                        <ProgressBar value={total} max={totalIn} color={CHART_COLORS[i % CHART_COLORS.length]} />
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })()}
                            </>
                        );
                    })()}
                </div>
            )}
        </div>
    );
}

// ─── Intelligence tab ─────────────────────────────────────────────────────────
// The earned-revenue P&L (an order counts when its FINAL payment lands, per
// docs/REPORTS_SPEC.md), expenses against category budgets, weekly cash flow,
// and per-rail reconciliation net of refunds.

function FinancialIntelligence({ start, end }: { start: string; end: string }) {
    const { data, isLoading } = useQuery({
        queryKey: ["financial-intelligence", start, end],
        queryFn: () => reportsApi.financialIntelligence(start, end),
        enabled: !!start && !!end,
        staleTime: 60_000,
    });
    if (isLoading || !data) return <div className="flex justify-center py-16"><Spinner /></div>;
    const { pnl, expenses, cash_flow, rails } = data;
    const maxFlow = Math.max(...cash_flow.map((w: any) => Math.max(Number(w.in), Number(w.out))), 1);

    return (
        <div className="space-y-6">
            {/* Earned P&L: the waterfall in four cards */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                <KpiCard label="Earned Revenue" value={fmtKes(pnl.earned_revenue)}
                    sub={`${pnl.earned_orders} orders fully paid in period`} />
                <KpiCard label="COGS (est.)" value={fmtKes(pnl.cogs_estimate)}
                    sub={pnl.unpriced_lines > 0 ? `${pnl.unpriced_lines} lines missing cost price` : "from the price book"} />
                <KpiCard label="Gross Profit" value={fmtKes(pnl.gross_profit)}
                    sub={pnl.gross_margin_pct != null ? `${pnl.gross_margin_pct}% margin` : ""}
                    color={pnl.gross_profit >= 0 ? "text-success" : "text-danger"} />
                <KpiCard label="Net After Expenses" value={fmtKes(pnl.net_profit)}
                    sub={`${fmtKes(pnl.expenses)} expenses`}
                    color={pnl.net_profit >= 0 ? "text-success" : "text-danger"} />
            </div>
            <p className="text-2xs text-surface-400 -mt-3">
                Earned-revenue rule: an order counts on the day its final payment settles — a deposit is money held, not income.
            </p>

            {/* Weekly cash flow */}
            <div className="card card-body">
                <SectionHeader title="Cash flow by week — in vs out" />
                {cash_flow.length === 0 ? (
                    <p className="text-xs text-surface-400 py-4">No money movement in this period.</p>
                ) : (
                    <div className="space-y-2 mt-1">
                        {cash_flow.map((w: any) => (
                            <div key={w.week} className="flex items-center gap-2 text-2xs">
                                <span className="text-surface-400 w-16 shrink-0 tabular-nums">
                                    {new Date(w.week).toLocaleDateString("en-KE", { day: "2-digit", month: "short" })}
                                </span>
                                <div className="flex-1 space-y-0.5">
                                    <div className="h-2 bg-surface-100 rounded-full overflow-hidden">
                                        <div className="h-full bg-emerald-500 rounded-full" style={{ width: `${(Number(w.in) / maxFlow) * 100}%` }} />
                                    </div>
                                    <div className="h-2 bg-surface-100 rounded-full overflow-hidden">
                                        <div className="h-full bg-red-400 rounded-full" style={{ width: `${(Number(w.out) / maxFlow) * 100}%` }} />
                                    </div>
                                </div>
                                <span className={clsx("w-24 text-right tabular-nums font-bold shrink-0",
                                    Number(w.net) >= 0 ? "text-emerald-700" : "text-red-600")}>
                                    {Number(w.net) >= 0 ? "+" : ""}{fmtKes(w.net)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {/* Expenses vs budget */}
                <div className="card card-body">
                    <SectionHeader title="Expenses by category (vs monthly budget)" />
                    {expenses.length === 0 ? (
                        <p className="text-xs text-surface-400 py-4">No completed expenses in this period.</p>
                    ) : (
                        <div className="space-y-1.5 mt-1">
                            {expenses.map((x: any) => {
                                const over = x.budget_monthly != null && Number(x.spent) > Number(x.budget_monthly);
                                return (
                                    <div key={x.category} className="flex items-center gap-3 text-xs">
                                        <span className="font-medium text-surface-800 flex-1 truncate">{x.category}</span>
                                        {x.budget_monthly != null && (
                                            <span className={clsx("text-2xs", over ? "text-danger font-bold" : "text-surface-400")}>
                                                {over ? "over budget " : "of "}{fmtKes(x.budget_monthly)}
                                            </span>
                                        )}
                                        <span className={clsx("font-bold tabular-nums", over ? "text-danger" : "text-surface-800")}>{fmtKes(x.spent)}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Rails */}
                <div className="card card-body">
                    <SectionHeader title="Payment rails (net of refunds)" />
                    {rails.length === 0 ? (
                        <p className="text-xs text-surface-400 py-4">No settled payments in this period.</p>
                    ) : (
                        <div className="space-y-1.5 mt-1">
                            {rails.map((r: any) => (
                                <div key={r.method} className="flex items-center gap-3 text-xs">
                                    <span className="font-medium text-surface-800 capitalize flex-1">{String(r.method).replace(/_/g, " ")}</span>
                                    <span className="text-2xs text-surface-400">{r.payments}×{Number(r.refunds) > 0 ? ` · −${fmtKes(r.refunds)} refunded` : ""}</span>
                                    <span className="font-bold tabular-nums text-surface-800">{fmtKes(r.net)}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
