// src/pages/reports/ExplorerReportPage.tsx
// Business Explorer — "Show me any figure, sliced any way, and the orders
// behind it."
//
// A slice of the same numbers, never a new number: MetricEngine::explorer
// reads Sold, Orders, Buyers and Collected from the bases the Executive tiles
// use, and Units / Line value from the lines Sales by Product sums. The totals
// row is computed over the whole slice by the backend (Buyers are distinct
// people, which no sum of rows can give). Click a row to narrow to it; open a
// row for the orders it counted. Dimension and filters live in the URL, so a
// view is a link that can be shared.

import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import dayjs from "dayjs";
import { reportsApi, type ExplorerMeasure, type ExplorerRow } from "@/api/reports";
import { fmtKes } from "@/api/expenses";
import { Spinner } from "@/components/ui/Spinner";
import {
    TH,
    TH_R,
    SectionHeader,
    TableWrapper,
    ReportPageHeader,
    DrillPanel,
    useDateRange,
    periodParams,
} from "./reportShared";

const DIMENSIONS: { key: string; label: string }[] = [
    { key: "month", label: "Month" },
    { key: "week", label: "Week" },
    { key: "day", label: "Day" },
    { key: "channel", label: "Channel" },
    { key: "outlet", label: "Outlet" },
    { key: "salesperson", label: "Salesperson" },
    { key: "currency", label: "Currency" },
    { key: "category", label: "Category" },
    { key: "product", label: "Product" },
    { key: "method", label: "Payment method" },
];
// Rows of these dimensions can become filters; the outlet is the page's own
// filter, time is the date range, and a method belongs to a payment.
const FILTERABLE = ["channel", "salesperson", "currency", "category", "product"];
const TIME = ["month", "week", "day"];

const MEASURE: Record<ExplorerMeasure, { label: string; money: boolean; hint?: string }> = {
    sold: { label: "Sold", money: true, hint: "Recognised orders, by order date" },
    orders: { label: "Orders", money: false },
    buyers: { label: "Buyers", money: false, hint: "Distinct people — rows do not add up to the total" },
    aov: { label: "Avg order", money: true },
    collected: { label: "Collected", money: true, hint: "Payments, by payment date" },
    line_value: { label: "Line value", money: true, hint: "Value of the matching lines, as in Sales by Product" },
    units: { label: "Units", money: false },
};

const fmt = (m: ExplorerMeasure, v: number | null | undefined) =>
    v == null ? "—" : MEASURE[m].money ? fmtKes(v) : Number(v).toLocaleString();

export default function ExplorerReportPage() {
    const dr = useDateRange("this_month");
    const [sp, setSp] = useSearchParams();
    const by = DIMENSIONS.some((d) => d.key === sp.get("by")) ? sp.get("by")! : "channel";
    const filters = Object.fromEntries(FILTERABLE.flatMap((f) => (sp.get(`f_${f}`) ? [[f, sp.get(`f_${f}`)!]] : [])));
    // Display names for the active filters — from the URL, so never trusted to parse.
    const filterLabels: Record<string, string> = (() => {
        try { const v = JSON.parse(sp.get("fl") ?? "{}"); return v && typeof v === "object" ? v : {}; } catch { return {}; }
    })();
    const [openRow, setOpenRow] = useState<ExplorerRow | null>(null);

    const query = {
        ...periodParams(dr.preset, dr.start, dr.end, dr.outlet),
        ...Object.fromEntries(Object.entries(filters).map(([k, v]) => [`f_${k}`, v])),
    };

    const { data, isLoading, isError, error } = useQuery({
        queryKey: ["report-explorer", by, dr.start, dr.end, dr.outlet, JSON.stringify(filters)],
        queryFn: () => reportsApi.explorer({ ...query, by }),
        retry: false,
    });

    const update = (fn: (p: URLSearchParams) => void) => {
        const next = new URLSearchParams(sp);
        fn(next);
        setSp(next, { replace: false });
    };
    const setBy = (key: string) => update((p) => p.set("by", key));
    const addFilter = (row: ExplorerRow) => update((p) => {
        p.set(`f_${by}`, row.key);
        p.set("fl", JSON.stringify({ ...filterLabels, [by]: row.label }));
        // Narrowed to one value, the same dimension has nothing left to split.
        p.set("by", by === "category" ? "product" : by === "product" ? "month" : "category");
    });
    const removeFilter = (f: string) => update((p) => {
        p.delete(`f_${f}`);
        const { [f]: _gone, ...rest } = filterLabels;
        p.set("fl", JSON.stringify(rest));
    });
    const zoomTo = (row: ExplorerRow) => {
        const start = dayjs(row.key);
        const end = by === "month" ? start.endOf("month") : by === "week" ? start.add(6, "day") : start;
        update((p) => {
            p.set("preset", "custom");
            p.set("from", start.format("YYYY-MM-DD"));
            p.set("to", end.format("YYYY-MM-DD"));
            p.set("by", by === "month" ? "week" : "day");
        });
    };

    const measures = data?.measures ?? [];
    const lead = measures[0];
    const max = Math.max(1, ...(data?.rows ?? []).map((r) => Number(r[lead] ?? 0)));
    const dimLabel = DIMENSIONS.find((d) => d.key === by)?.label ?? by;
    const apiError = (error as any)?.response?.data?.message;

    return (
        <div className="space-y-6 animate-fade-in">
            <ReportPageHeader
                title="Business Explorer"
                subtitle="Any figure, sliced any way — and the orders behind it."
                exportPath="explorer"
                params={{ ...query, by }}
                preset={dr.preset}
                start={dr.start}
                end={dr.end}
                onPresetChange={dr.handlePreset}
                onStartChange={dr.setStart}
                onEndChange={dr.setEnd}
                outlet={dr.outlet}
                onOutletChange={dr.setOutlet}
            />

            <div className="card card-body space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-surface-500">Show by</span>
                    {DIMENSIONS.map((d) => (
                        <button key={d.key} onClick={() => setBy(d.key)}
                            className={by === d.key
                                ? "rounded-full bg-brand-600 px-3 py-1 text-xs font-medium text-white"
                                : "rounded-full bg-surface-100 px-3 py-1 text-xs font-medium text-surface-600 hover:bg-surface-200"}>
                            {d.label}
                        </button>
                    ))}
                </div>
                {Object.keys(filters).length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-xs font-medium text-surface-500">Only</span>
                        {Object.entries(filters).map(([f, v]) => (
                            <span key={f} className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-3 py-1 text-xs text-brand-700">
                                {DIMENSIONS.find((d) => d.key === f)?.label}: {filterLabels[f] ?? v}
                                <button onClick={() => removeFilter(f)} aria-label={`Remove ${f} filter`} className="ml-1 text-brand-500 hover:text-brand-800">✕</button>
                            </span>
                        ))}
                    </div>
                )}
            </div>

            {isLoading ? (
                <div className="flex justify-center py-16"><Spinner /></div>
            ) : isError || !data ? (
                <div className="card card-body text-sm text-danger">{apiError ?? "This view could not be loaded."}</div>
            ) : (
                <div className="card overflow-hidden">
                    <div className="px-5 pt-5 pb-3">
                        <SectionHeader title={`By ${dimLabel.toLowerCase()}`} />
                        <p className="text-xs text-surface-500 -mt-2">
                            {data.level === "item"
                                ? "Read by line: a category or product is part of a basket, so the money here is the value of the matching lines, not whole orders."
                                : data.level === "payment"
                                    ? "Read by payment: a method belongs to a payment, so only Collected applies."
                                    : TIME.includes(by)
                                        ? "Sold by order date; Collected by payment date — the same dates as the tiles on Executive Overview."
                                        : "The totals row is the business's figure for this slice; Buyers are distinct people, so rows can add up to more."}
                            {data.row_count > data.rows.length && ` Showing the largest ${data.rows.length} of ${data.row_count}; the totals cover all of them.`}
                        </p>
                    </div>
                    <TableWrapper>
                        <table className="w-full text-sm">
                            <thead>
                                <tr>
                                    <th className={TH}>{dimLabel}</th>
                                    {measures.map((m) => (
                                        <th key={m} className={TH_R} title={MEASURE[m].hint}>{MEASURE[m].label}</th>
                                    ))}
                                    <th className={TH_R}></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {data.rows.length === 0 ? (
                                    <tr><td colSpan={measures.length + 2} className="px-5 py-10 text-center text-xs text-surface-400">Nothing in this slice.</td></tr>
                                ) : data.rows.map((r) => (
                                    <tr key={r.key} className="hover:bg-surface-50">
                                        <td className="px-5 py-2.5 min-w-[10rem]">
                                            <div className="font-medium text-surface-800">{TIME.includes(by) ? formatTime(by, r.key) : r.label}</div>
                                            <div className="mt-1 h-1.5 rounded-full bg-surface-100">
                                                <div className="h-1.5 rounded-full bg-brand-400" style={{ width: `${(Number(r[lead] ?? 0) / max) * 100}%` }} />
                                            </div>
                                        </td>
                                        {measures.map((m) => (
                                            <td key={m} className="px-5 py-2.5 text-right tabular-nums">{fmt(m, r[m])}</td>
                                        ))}
                                        <td className="px-5 py-2.5 text-right whitespace-nowrap space-x-3">
                                            {FILTERABLE.includes(by) && (
                                                <button onClick={() => addFilter(r)} className="text-xs font-medium text-brand-600 hover:underline">Narrow to this</button>
                                            )}
                                            {(by === "month" || by === "week") && (
                                                <button onClick={() => zoomTo(r)} className="text-xs font-medium text-brand-600 hover:underline">Zoom in</button>
                                            )}
                                            <button onClick={() => setOpenRow(r)} className="text-xs font-medium text-brand-600 hover:underline">
                                                {by === "method" ? "Payments →" : "Orders →"}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            {data.rows.length > 0 && (
                                <tfoot>
                                    <tr className="border-t-2 border-line bg-surface-50 font-semibold">
                                        <td className="px-5 py-2.5 text-surface-800">Total</td>
                                        {measures.map((m) => (
                                            <td key={m} className="px-5 py-2.5 text-right tabular-nums">{fmt(m, data.totals[m])}</td>
                                        ))}
                                        <td />
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </TableWrapper>
                </div>
            )}

            {openRow && (
                <DrillPanel
                    metric="explorer"
                    title={TIME.includes(by) ? formatTime(by, openRow.key) : openRow.label}
                    query={{ ...query, by, key: openRow.key }}
                    load={(q) => reportsApi.explorerOrders(q)}
                    onClose={() => setOpenRow(null)}
                />
            )}
        </div>
    );
}

function formatTime(by: string, key: string) {
    const d = dayjs(key);
    return by === "month" ? d.format("MMMM YYYY") : by === "week" ? `Week of ${d.format("D MMM YYYY")}` : d.format("ddd D MMM YYYY");
}
