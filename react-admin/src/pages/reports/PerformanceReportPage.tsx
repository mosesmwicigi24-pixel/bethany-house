// src/pages/reports/PerformanceReportPage.tsx
// Staff, Outlets & Performance — "How are our outlets and salespeople doing?"
//
// Every figure comes from the same definitions as the rest of Reports
// (MetricEngine::performance): sold = recognised orders in KES at the reporting
// rate, collected = payments by payment date, buyers = the one buyer rule. Both
// breakdowns partition the same orders, so each sums to the business total.
// Operational sales figures only — nothing here tracks a person's activity.

import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { reportsApi, type PerformanceRow } from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
import {
    KPI_GRID,
    TH,
    TH_R,
    KpiCard,
    ChangeBadge,
    SectionHeader,
    TableWrapper,
    ReportPageHeader,
    DrillPanel,
    useDateRange,
    useReportTab,
} from "./reportShared";

const TABS = ["outlets", "salespeople"] as const;
type PerfTab = (typeof TABS)[number];

const changePct = (now: number, prev: number): number | null =>
    prev > 0 ? Math.round(((now - prev) / prev) * 1000) / 10 : null;

export default function PerformanceReportPage() {
    const dr = useDateRange("this_month");
    const navigate = useNavigate();
    const [tab, setTab] = useReportTab<PerfTab>(TABS, "outlets");
    const [drillFor, setDrillFor] = useState<PerformanceRow | null>(null);

    const { data, isLoading, isError } = useQuery({
        queryKey: ["report-performance", dr.start, dr.end, dr.outlet],
        queryFn: () => reportsApi.performance(dr.params),
    });

    const rows = (tab === "outlets" ? data?.outlets : data?.salespeople) ?? [];
    const topOutlet = data?.outlets.find((r) => r.id !== null);
    const topPerson = data?.salespeople.find((r) => r.id !== null);
    const unconfirmed = (data?.outlets ?? []).reduce((a, r) => a + r.unconfirmed_value, 0);

    return (
        <div className="space-y-6 animate-fade-in">
            <ReportPageHeader
                title="Staff, Outlets & Performance"
                subtitle="How each outlet and salesperson is doing, against the previous period."
                exportPath="performance"
                params={dr.params}
                preset={dr.preset}
                start={dr.start}
                end={dr.end}
                onPresetChange={dr.handlePreset}
                onStartChange={dr.setStart}
                onEndChange={dr.setEnd}
                outlet={dr.outlet}
                onOutletChange={dr.setOutlet}
            />

            {isLoading ? (
                <div className="flex justify-center py-16"><Spinner /></div>
            ) : isError || !data ? (
                <div className="card card-body text-sm text-danger">This report could not be loaded.</div>
            ) : (
                <>
                    <div className={KPI_GRID}>
                        <KpiCard label="Sold (recognised)" value={fmtKes(data.totals.sold)} drill="revenue"
                            sub={`${data.totals.orders} orders`} />
                        <KpiCard label="Collected" value={fmtKes(data.totals.collected)}
                            sub="by payment date" />
                        <KpiCard label="Top outlet" value={topOutlet?.name ?? "—"}
                            sub={topOutlet ? fmtKes(topOutlet.sold) : "no sales in this period"} />
                        <KpiCard label="Top salesperson" value={topPerson?.name ?? "—"}
                            sub={topPerson ? `${fmtKes(topPerson.sold)} · ${topPerson.orders} orders` : "no attributed sales"} />
                        <KpiCard label="Unconfirmed carts" value={fmtKes(unconfirmed)}
                            sub="not sales until someone confirms them" />
                    </div>

                    <div className="border-b border-line overflow-x-auto no-scrollbar">
                        <nav className="flex gap-1 -mb-px">
                            {TABS.map((t) => (
                                <button key={t} onClick={() => setTab(t)}
                                    className={clsx(
                                        "px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors",
                                        tab === t ? "border-brand-500 text-brand-600" : "border-transparent text-surface-500 hover:text-surface-700",
                                    )}>
                                    {t === "outlets" ? "Outlets" : "Salespeople"}
                                </button>
                            ))}
                        </nav>
                    </div>

                    <div className="card overflow-hidden">
                        <div className="px-5 pt-5 pb-3">
                            <SectionHeader title={tab === "outlets" ? "Outlet performance" : "Salesperson performance"} />
                            <p className="text-xs text-surface-500 -mt-2">
                                {tab === "outlets"
                                    ? "Open an outlet to see its sales, products and orders in Sales & Orders."
                                    : "A salesperson is the staff member who raised the order. Open a row for the orders behind it."}
                            </p>
                        </div>
                        <TableWrapper>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr>
                                        <th className={TH}>{tab === "outlets" ? "Outlet" : "Salesperson"}</th>
                                        <th className={TH_R}>Sold</th>
                                        <th className={TH_R}>vs previous</th>
                                        <th className={TH_R}>Orders</th>
                                        <th className={TH_R}>Avg order</th>
                                        <th className={TH_R}>Buyers</th>
                                        <th className={TH_R}>Collected</th>
                                        <th className={TH_R}>Unconfirmed</th>
                                        <th className={TH_R}></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {rows.length === 0 ? (
                                        <tr><td colSpan={9} className="px-5 py-10 text-center text-xs text-surface-400">No sales in this period.</td></tr>
                                    ) : rows.map((r) => (
                                        <tr key={`${tab}-${r.id ?? "none"}`} className="hover:bg-surface-50">
                                            <td className="px-5 py-2.5 font-medium text-surface-800">{r.name}</td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">{fmtKes(r.sold)}</td>
                                            <td className="px-5 py-2.5 text-right">
                                                {/* No sales last period: say so, rather than an empty cell
                                                    that reads as missing data. */}
                                                {changePct(r.sold, r.sold_previous) === null
                                                    ? <span className="text-xs text-surface-400">{r.sold_previous === 0 && r.sold > 0 ? "new" : "—"}</span>
                                                    : <ChangeBadge pct={changePct(r.sold, r.sold_previous)} />}
                                            </td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">{r.orders}</td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">{r.aov != null ? fmtKes(r.aov) : "—"}</td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">{r.buyers}</td>
                                            <td className="px-5 py-2.5 text-right tabular-nums">{fmtKes(r.collected)}</td>
                                            <td className="px-5 py-2.5 text-right tabular-nums text-surface-500">
                                                {r.unconfirmed_carts > 0 ? `${fmtKes(r.unconfirmed_value)} · ${r.unconfirmed_carts}` : "—"}
                                            </td>
                                            <td className="px-5 py-2.5 text-right whitespace-nowrap">
                                                {r.id !== null && (tab === "outlets" ? (
                                                    <button className="text-xs font-medium text-brand-600 hover:underline"
                                                        onClick={() => navigate(`/reports/sales?outlet=${r.id}&preset=custom&from=${dr.start}&to=${dr.end}`)}>
                                                        Sales & Orders →
                                                    </button>
                                                ) : (
                                                    <button className="text-xs font-medium text-brand-600 hover:underline"
                                                        onClick={() => setDrillFor(r)}>
                                                        Orders →
                                                    </button>
                                                ))}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrapper>
                    </div>
                </>
            )}

            {drillFor && (
                <DrillPanel metric="revenue" title={drillFor.name}
                    query={{ period: "custom", from: dr.start, to: dr.end, salesperson: drillFor.id,
                        ...(dr.outlet ? { outlet_id: Number(dr.outlet) } : {}) }}
                    onClose={() => setDrillFor(null)} />
            )}
        </div>
    );
}
