// src/pages/reports/DataQualityReportPage.tsx
// Audit & Data Quality — "Can I trust these numbers, and where is the data
// incomplete?"
//
// Every check comes from MetricEngine::dataQuality and reuses the rule the
// figure itself uses, so a clean check means that figure is whole on that
// count. Each check says which figures it bends, whether it reads this window
// or the standing records, and lists the rows to fix — with links the backend
// decided from what this viewer may open. Nothing is computed here.

import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { reportsApi, type DataQualityCheck, type DataQualityRow } from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
import {
    KPI_GRID,
    TH,
    TH_R,
    KpiCard,
    TableWrapper,
    ReportPageHeader,
    useDateRange,
    useReportTab,
} from "./reportShared";

const TABS = ["sales", "money", "customers"] as const;
type DqTab = (typeof TABS)[number];
const TAB_LABEL: Record<DqTab, string> = {
    sales: "Sales records",
    money: "Money",
    customers: "Customer records",
};

const SEVERITY_STYLE: Record<DataQualityCheck["severity"], string> = {
    high: "bg-danger-light text-danger-dark",
    medium: "bg-warning-light text-warning-dark",
    low: "bg-surface-100 text-surface-600",
};

const LINK_LABEL: Record<string, string> = {
    order: "Order",
    customer: "Customer",
    payment: "Payment",
    expense: "Expense",
    product: "Product",
    production: "Job",
};

const fmtDate = (d?: string | null) =>
    d ? new Date(d).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "—";

export default function DataQualityReportPage() {
    const dr = useDateRange("this_month");
    const [tab, setTab] = useReportTab<DqTab>(TABS, "sales");

    const { data, isLoading, isError } = useQuery({
        queryKey: ["report-data-quality", dr.start, dr.end, dr.outlet],
        queryFn: () => reportsApi.dataQuality(dr.params),
    });

    const checks = data?.checks ?? [];
    const flagged = checks.filter((c) => c.count > 0);
    const visible = checks
        .filter((c) => c.group === tab)
        // Problems first, worst first; clean checks after, so the page reads
        // as a to-do list and still shows what was checked.
        .sort((a, b) => Number(b.count > 0) - Number(a.count > 0)
            || ["high", "medium", "low"].indexOf(a.severity) - ["high", "medium", "low"].indexOf(b.severity));

    return (
        <div className="space-y-6 animate-fade-in">
            <ReportPageHeader
                title="Audit & Data Quality"
                subtitle="Can these numbers be trusted, and where is the data incomplete?"
                exportPath="data-quality"
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
                        <KpiCard label="Checks clean" value={`${checks.length - flagged.length} of ${checks.length}`}
                            sub={flagged.length ? `${flagged.length} need attention` : "nothing to fix"} />
                        <KpiCard label="Buyer known" value={data.coverage.buyer_identified != null ? `${data.coverage.buyer_identified}%` : "—"}
                            sub={`of ${data.coverage.orders} sales this period`} />
                        <KpiCard label="Cost known" value={data.coverage.lines_costed != null ? `${data.coverage.lines_costed}%` : "—"}
                            sub={`of ${data.coverage.lines} lines sold — the rest are costed at zero in the P&L`} />
                        <KpiCard label="High-impact issues" value={String(flagged.filter((c) => c.severity === "high").length)}
                            sub="bend a money figure directly" />
                    </div>

                    {data.gaps.length > 0 && (
                        <div className="card card-body space-y-2 border-l-4 border-warning">
                            <p className="text-sm font-semibold text-surface-800">Figures that read zero because the work behind them never happens</p>
                            {data.gaps.map((g) => (
                                <div key={g.key} className="text-sm">
                                    <span className="font-medium text-surface-800">{g.title}.</span>{" "}
                                    <span className="text-surface-600">{g.detail}</span>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="border-b border-line">
                        <nav className="flex gap-1 -mb-px overflow-x-auto">
                            {TABS.map((t) => {
                                const n = checks.filter((c) => c.group === t && c.count > 0).length;
                                return (
                                    <button key={t} onClick={() => setTab(t)}
                                        className={clsx(
                                            "px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors",
                                            tab === t ? "border-brand-500 text-brand-600" : "border-transparent text-surface-500 hover:text-surface-700",
                                        )}>
                                        {TAB_LABEL[t]}
                                        {n > 0 && <span className="ml-1.5 rounded-full bg-surface-100 px-1.5 text-xs text-surface-600">{n}</span>}
                                    </button>
                                );
                            })}
                        </nav>
                    </div>

                    <div className="space-y-4">
                        {visible.map((c) => (
                            <CheckCard key={c.key} check={c} rowLimit={data.row_limit} outletChosen={!!dr.outlet} />
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

function CheckCard({ check: c, rowLimit, outletChosen }: { check: DataQualityCheck; rowLimit: number; outletChosen: boolean }) {
    const [open, setOpen] = useState(false);
    const clean = c.count === 0;

    return (
        <div className="card overflow-hidden">
            <div className="px-5 py-4 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div className="space-y-1 min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        {clean
                            ? <span className="rounded-full bg-success-light px-2 py-0.5 text-xs font-medium text-success-dark">Clean</span>
                            : <span className={clsx("rounded-full px-2 py-0.5 text-xs font-medium capitalize", SEVERITY_STYLE[c.severity])}>{c.severity}</span>}
                        <h3 className="text-sm font-semibold text-surface-800">{c.title}</h3>
                    </div>
                    <p className="text-xs text-surface-500">{c.affects}</p>
                    <p className="text-xs text-surface-400">
                        {c.scope === "period" ? "This period" : "All records, whatever the period"}
                        {outletChosen && !c.outlet && " · all outlets"}
                    </p>
                </div>
                <div className="text-left sm:text-right shrink-0">
                    <div className="text-lg font-semibold tabular-nums text-surface-800">{c.count.toLocaleString()}</div>
                    {c.value != null && c.value > 0 && <div className="text-xs text-surface-500 tabular-nums">{fmtKes(c.value)}</div>}
                </div>
            </div>

            {!clean && (
                <div className="border-t border-line px-5 py-2.5 flex flex-wrap items-center justify-between gap-2 bg-surface-50">
                    <span className="text-xs text-surface-600">
                        To fix: {c.fix.to ? <Link to={c.fix.to} className="font-medium text-brand-600 hover:underline">{c.fix.label}</Link> : c.fix.label}
                    </span>
                    {c.rows.length > 0 && (
                        <button onClick={() => setOpen((o) => !o)} className="text-xs font-medium text-brand-600 hover:underline">
                            {open ? "Hide records" : `Show records${c.count > c.rows.length ? ` (first ${c.rows.length} of ${c.count})` : ""}`}
                        </button>
                    )}
                </div>
            )}

            {open && <RowsTable rows={c.rows} limited={c.count > rowLimit} />}
        </div>
    );
}

function RowsTable({ rows, limited }: { rows: DataQualityRow[]; limited: boolean }) {
    const navigate = useNavigate();
    const has = (k: keyof DataQualityRow) => rows.some((r) => r[k] != null && r[k] !== "");

    return (
        <div className="border-t border-line">
            <TableWrapper>
                <table className="w-full text-sm">
                    <thead>
                        <tr>
                            <th className={TH}>Record</th>
                            {has("customer") && <th className={TH}>Customer</th>}
                            {has("detail") && <th className={TH}>Detail</th>}
                            {has("phone_field") && <th className={TH}>Phone field</th>}
                            {has("records") && <th className={TH_R}>Records</th>}
                            {has("lines") && <th className={TH_R}>Lines</th>}
                            {has("date") && <th className={TH}>Date</th>}
                            {has("amount") && <th className={TH_R}>KES</th>}
                            <th className={TH_R}></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {rows.map((r, i) => (
                            <tr key={`${r.kind}-${r.id ?? r.ref ?? i}`} className="hover:bg-surface-50">
                                <td className="px-5 py-2 font-medium text-surface-800">{r.ref ?? (r.id != null ? `#${r.id}` : "—")}</td>
                                {has("customer") && <td className="px-5 py-2 text-surface-700">{r.customer || "—"}</td>}
                                {has("detail") && <td className="px-5 py-2 text-surface-600">{r.detail || "—"}</td>}
                                {has("phone_field") && <td className="px-5 py-2 text-surface-600">{r.phone_field || "—"}</td>}
                                {has("records") && <td className="px-5 py-2 text-right tabular-nums">{r.records ?? "—"}</td>}
                                {has("lines") && <td className="px-5 py-2 text-right tabular-nums">{r.lines ?? "—"}</td>}
                                {has("date") && <td className="px-5 py-2 text-surface-600 whitespace-nowrap">{fmtDate(r.date)}</td>}
                                {has("amount") && <td className="px-5 py-2 text-right tabular-nums">{r.amount != null ? fmtKes(Number(r.amount)) : "—"}</td>}
                                <td className="px-5 py-2 text-right whitespace-nowrap space-x-3">
                                    {Object.entries(r.links).map(([k, to]) => (
                                        <button key={k} onClick={() => navigate(to!)} className="text-xs font-medium text-brand-600 hover:underline">
                                            {LINK_LABEL[k] ?? k} →
                                        </button>
                                    ))}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </TableWrapper>
            {limited && (
                <p className="px-5 py-2 text-xs text-surface-400 border-t border-line">
                    Largest or most recent first. The count above is the full number; the open screens behind each link list the rest.
                </p>
            )}
        </div>
    );
}
