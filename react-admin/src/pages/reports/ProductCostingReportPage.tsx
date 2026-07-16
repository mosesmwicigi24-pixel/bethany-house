// src/pages/reports/ProductCostingReportPage.tsx
// Product Costing & Profitability Report — redesigned

import { useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { get } from "@/api/client";
import { Spinner } from "@/components/ui/Spinner";
import { clsx } from "clsx";

// ─── API ──────────────────────────────────────────────────────────────────────

interface CostingParams {
    selling_price?:      number;
    quantity_sold?:      number;
    labour_cost?:        number;
    packaging_cost?:     number;
    other_costs?:        number;
    delivery_cost?:      number;
    commission?:         number;
    marketing_cost?:     number;
    payment_charges?:    number;
    management_comment?: string;
}

function fetchCostingReport(id: string, params: CostingParams) {
    return get<{ report: CostingReport }>(`/v1/admin/reports/production/costing/${id}`, { params });
}

// ─── Types ────────────────────────────────────────────────────────────────────

interface CostLine {
    cost_item:   string;
    description: string;
    quantity:    number | null;
    unit:        string | null;
    unit_cost:   number | null;
    total_cost:  number;
    type:        string;
}

interface SellingExpense {
    label:  string;
    amount: number;
}

interface CostingReport {
    header: {
        report_name:        string;
        product_name:       string;
        product_code:       string;
        batch_number:       string;
        production_date:    string;
        completion_date:    string | null;
        quantity_produced:  number;
        outlet:             string | null;
        is_customer_order:  boolean;
        customer_order_id:  number | null;
        prepared_by:        string;
        generated_at:       string;
    };
    cost_breakdown: {
        lines:                CostLine[];
        total_production_cost: number;
    };
    cost_summary: {
        total_production_cost: number;
        quantity_produced:     number;
        cost_per_unit:         number;
    };
    sales_summary: {
        product_name:      string;
        quantity_sold:     number;
        selling_price:     number;
        total_sales:       number;
        quantity_produced: number;
        remaining_stock:   number;
    };
    gross_profit: {
        total_sales:  number;
        cogs:         number;
        gross_profit: number;
    };
    net_profit: {
        selling_expenses:       SellingExpense[];
        total_selling_expenses: number;
        gross_profit:           number;
        net_profit:             number;
    };
    margins: {
        gross_margin: number;
        net_margin:   number;
        markup:       number;
    };
    final_summary: {
        quantity_produced:     number;
        quantity_sold:         number;
        remaining_stock:       number;
        total_production_cost: number;
        cost_per_unit:         number;
        total_sales:           number;
        gross_profit:          number;
        net_profit:            number;
        net_margin:            number;
    };
    recommendation: {
        profitability_status:   string;
        pricing_recommendation: string;
        cost_control_note:      string;
        stock_action:           string;
        management_decision:    string;
        management_comment:     string | null;
    };
}

// ─── Formatters ───────────────────────────────────────────────────────────────

function fmt(n: number | null | undefined, decimals = 0): string {
    if (n === null || n === undefined) return "—";
    return Number(n).toLocaleString("en-KE", {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

function fmtK(n: number): string {
    if (Math.abs(n) >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
    if (Math.abs(n) >= 1_000)     return `${(n / 1_000).toFixed(1)}K`;
    return fmt(n);
}

function fmtPct(n: number | null | undefined): string {
    if (n === null || n === undefined) return "—";
    return `${Number(n).toFixed(1)}%`;
}

// ─── Override input ───────────────────────────────────────────────────────────

interface OverrideState {
    selling_price:      string;
    quantity_sold:      string;
    labour_cost:        string;
    packaging_cost:     string;
    other_costs:        string;
    delivery_cost:      string;
    commission:         string;
    marketing_cost:     string;
    payment_charges:    string;
    management_comment: string;
}

function Field({ label, value, onChange }: {
    label: string; value: string; onChange: (v: string) => void;
}) {
    return (
        <div>
            <label className="block text-xs font-medium text-slate-500 mb-1">{label}</label>
            <input
                type="number" min="0" step="any" value={value}
                onChange={e => onChange(e.target.value)}
                placeholder="0"
                className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 placeholder:text-slate-300"
            />
        </div>
    );
}



// ─── Main page ────────────────────────────────────────────────────────────────

export default function ProductCostingReportPage() {
    const { id }   = useParams<{ id: string }>();
    const navigate = useNavigate();
    const [panel, setPanel] = useState(false);
    const [overrides, setOverrides] = useState<OverrideState>({
        selling_price: "", quantity_sold: "", labour_cost: "",
        packaging_cost: "", other_costs: "", delivery_cost: "",
        commission: "", marketing_cost: "", payment_charges: "",
        management_comment: "",
    });

    const params: CostingParams = {};
    if (overrides.selling_price)      params.selling_price     = Number(overrides.selling_price);
    if (overrides.quantity_sold)      params.quantity_sold     = Number(overrides.quantity_sold);
    if (overrides.labour_cost)        params.labour_cost       = Number(overrides.labour_cost);
    if (overrides.packaging_cost)     params.packaging_cost    = Number(overrides.packaging_cost);
    if (overrides.other_costs)        params.other_costs       = Number(overrides.other_costs);
    if (overrides.delivery_cost)      params.delivery_cost     = Number(overrides.delivery_cost);
    if (overrides.commission)         params.commission        = Number(overrides.commission);
    if (overrides.marketing_cost)     params.marketing_cost    = Number(overrides.marketing_cost);
    if (overrides.payment_charges)    params.payment_charges   = Number(overrides.payment_charges);
    if (overrides.management_comment) params.management_comment = overrides.management_comment;

    const set = (k: keyof OverrideState) => (v: string) =>
        setOverrides(prev => ({ ...prev, [k]: v }));

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ["product-costing-report", id, params],
        queryFn:  () => fetchCostingReport(id!, params),
        enabled:  !!id,
    });

    if (isLoading) return (
        <div className="flex justify-center items-center py-32"><Spinner /></div>
    );

    if (isError || !data?.report) return (
        <div className="max-w-md mx-auto mt-24 text-center space-y-3">
            <div className="w-12 h-12 rounded-2xl bg-red-50 flex items-center justify-center mx-auto">
                <svg className="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" />
                </svg>
            </div>
            <p className="text-sm text-slate-500">Could not load report for production order #{id}.</p>
            <button onClick={() => navigate(-1)}
                className="text-xs text-indigo-600 hover:text-indigo-700 font-medium">← Go back</button>
        </div>
    );

    const r  = data.report;
    const nm = r.margins.net_margin;
    const isHealthy = nm >= 30;
    const isWarning = nm >= 10 && nm < 30;
    const heroGradient = isHealthy
        ? "linear-gradient(135deg, #059669 0%, #0d9488 100%)"
        : isWarning
        ? "linear-gradient(135deg, #f59e0b 0%, #ea580c 100%)"
        : "linear-gradient(135deg, #dc2626 0%, #e11d48 100%)";

    const soldPct = r.sales_summary.quantity_produced > 0
        ? Math.round((r.sales_summary.quantity_sold / r.sales_summary.quantity_produced) * 100)
        : 0;

    return (
        <div className="max-w-5xl mx-auto pb-16 animate-fade-in">

            {/* ── Topbar ── */}
            <div className="flex items-center justify-between py-4 px-1 mb-1">
                <button onClick={() => navigate(-1)}
                    className="flex items-center gap-1.5 text-xs text-slate-400 hover:text-slate-700 font-medium transition-colors">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                    Production Orders
                </button>
                <div className="flex items-center gap-2">
                    <button onClick={() => setPanel(v => !v)}
                        className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-indigo-300 hover:text-indigo-600 transition-all">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                        </svg>
                        {panel ? "Close" : "Adjust Values"}
                    </button>
                    <button onClick={() => window.print()}
                        className="flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700 transition-all">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z" />
                        </svg>
                        Print
                    </button>
                </div>
            </div>

            {/* ── Adjust Values panel ── */}
            {panel && (
                <div className="mb-4 rounded-2xl border border-indigo-100 p-5 space-y-4" style={{ backgroundColor: "rgba(238,242,255,0.5)" }}>
                    <p className="text-xs font-bold text-indigo-700 uppercase tracking-widest">Manual Overrides</p>
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                        <Field label="Selling Price / Unit" value={overrides.selling_price} onChange={set("selling_price")} />
                        <Field label="Quantity Sold"        value={overrides.quantity_sold}  onChange={set("quantity_sold")} />
                        <Field label="Labour Cost"          value={overrides.labour_cost}    onChange={set("labour_cost")} />
                        <Field label="Packaging Cost"       value={overrides.packaging_cost} onChange={set("packaging_cost")} />
                        <Field label="Other Costs"          value={overrides.other_costs}    onChange={set("other_costs")} />
                    </div>
                    <div>
                        <p className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Selling Expenses</p>
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <Field label="Delivery"      value={overrides.delivery_cost}   onChange={set("delivery_cost")} />
                            <Field label="Commission"    value={overrides.commission}       onChange={set("commission")} />
                            <Field label="Marketing"     value={overrides.marketing_cost}   onChange={set("marketing_cost")} />
                            <Field label="Payment Fees"  value={overrides.payment_charges}  onChange={set("payment_charges")} />
                        </div>
                    </div>
                    <div className="flex items-end gap-3">
                        <div className="flex-1">
                            <label className="block text-xs font-medium text-slate-500 mb-1">Management Comment</label>
                            <textarea rows={1} value={overrides.management_comment}
                                onChange={e => set("management_comment")(e.target.value)}
                                placeholder="Optional note…"
                                className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 resize-none" />
                        </div>
                        <button onClick={() => refetch()}
                            className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700 transition-colors shrink-0">
                            Recalculate
                        </button>
                    </div>
                </div>
            )}

            {/* ══════════════════════════════════════════════
                HERO — cinematic profit banner
            ══════════════════════════════════════════════ */}
            <div className="rounded-2xl text-white p-6 sm:p-8" style={{ background: heroGradient }}>
                <div className="flex items-start justify-between gap-4 flex-wrap mb-6">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-widest mb-1" style={{ color: "rgba(255,255,255,0.5)" }}>
                            {r.header.batch_number} · {r.header.product_code}
                        </p>
                        <h1 className="text-2xl sm:text-3xl font-bold leading-tight">{r.header.product_name}</h1>
                        <p className="text-sm mt-1" style={{ color: "rgba(255,255,255,0.6)" }}>
                            {r.header.production_date}
                            {r.header.completion_date && ` → ${r.header.completion_date}`}
                            {r.header.outlet && <span className="ml-2" style={{ color: "rgba(255,255,255,0.4)" }}>· {r.header.outlet}</span>}
                        </p>
                    </div>
                    <div className="rounded-xl px-4 py-3 text-center shrink-0" style={{ backgroundColor: "rgba(255,255,255,0.20)" }}>
                        <p className="text-xs font-bold uppercase tracking-wide" style={{ color: "rgba(255,255,255,0.7)" }}>Verdict</p>
                        <p className="text-sm font-bold mt-0.5">{r.recommendation.profitability_status}</p>
                    </div>
                </div>

                {/* 4 hero KPIs */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                    <div className="rounded-xl px-4 py-3" style={{ backgroundColor: "rgba(255,255,255,0.15)" }}>
                        <p className="text-xs" style={{ color: "rgba(255,255,255,0.7)" }}>Net Profit</p>
                        <p className="text-2xl font-bold tabular-nums mt-0.5">KES {fmtK(r.final_summary.net_profit)}</p>
                    </div>
                    <div className="rounded-xl px-4 py-3" style={{ backgroundColor: "rgba(255,255,255,0.15)" }}>
                        <p className="text-xs" style={{ color: "rgba(255,255,255,0.7)" }}>Net Margin</p>
                        <p className="text-2xl font-bold tabular-nums mt-0.5">{fmtPct(nm)}</p>
                    </div>
                    <div className="rounded-xl px-4 py-3" style={{ backgroundColor: "rgba(255,255,255,0.10)" }}>
                        <p className="text-xs" style={{ color: "rgba(255,255,255,0.7)" }}>Gross Margin</p>
                        <p className="text-xl font-bold tabular-nums mt-0.5">{fmtPct(r.margins.gross_margin)}</p>
                    </div>
                    <div className="rounded-xl px-4 py-3" style={{ backgroundColor: "rgba(255,255,255,0.10)" }}>
                        <p className="text-xs" style={{ color: "rgba(255,255,255,0.7)" }}>Markup</p>
                        <p className="text-xl font-bold tabular-nums mt-0.5">{fmtPct(r.margins.markup)}</p>
                    </div>
                </div>

                {/* Margin health bar */}
                <div>
                    <div className="flex justify-between text-xs mb-1.5" style={{ color: "rgba(255,255,255,0.5)" }}>
                        <span>Margin Health</span>
                        <span className="font-semibold text-white">{fmtPct(nm)}</span>
                    </div>
                    <div className="h-2 rounded-full overflow-hidden" style={{ backgroundColor: "rgba(255,255,255,0.20)" }}>
                        <div className="h-full bg-white rounded-full transition-all duration-700"
                            style={{ width: `${Math.min(100, Math.max(0, nm))}%` }} />
                    </div>
                    <div className="flex justify-between text-xs mt-1.5" style={{ color: "rgba(255,255,255,0.35)" }}>
                        <span>Poor  &lt;10%</span>
                        <span>Good  30%+</span>
                    </div>
                </div>
            </div>

            {/* ══════════════════════════════════════════════
                BATCH STRIP
            ══════════════════════════════════════════════ */}
            <div className="mt-3 rounded-2xl border border-slate-100 bg-white px-6 py-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                {[
                    { label: "Qty Produced",  value: `${fmt(r.header.quantity_produced)} pcs` },
                    { label: "Cost / Unit",   value: `KES ${fmt(r.cost_summary.cost_per_unit)}` },
                    { label: "Selling Price", value: `KES ${fmt(r.sales_summary.selling_price)}` },
                    { label: "Prepared By",   value: r.header.prepared_by },
                ].map(item => (
                    <div key={item.label}>
                        <p className="text-xs text-slate-400 font-medium">{item.label}</p>
                        <p className="text-sm font-semibold text-slate-800 mt-0.5">{item.value}</p>
                    </div>
                ))}
            </div>

            {/* ══════════════════════════════════════════════
                BODY — 3-col left / 2-col right
            ══════════════════════════════════════════════ */}
            <div className="mt-4 grid grid-cols-1 lg:grid-cols-5 gap-4">

                {/* ── LEFT (3/5) ── */}
                <div className="lg:col-span-3 space-y-4">

                    {/* Production Costs */}
                    <section className="rounded-2xl border border-slate-100 bg-white overflow-hidden">
                        <div className="flex items-center justify-between px-5 py-4 border-b border-slate-50">
                            <div>
                                <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Step 1</p>
                                <h2 className="text-sm font-bold text-slate-800 mt-0.5">Production Costs</h2>
                            </div>
                            <span className="rounded-lg bg-amber-50 border border-amber-100 px-3 py-1 text-xs font-bold text-amber-700">
                                KES {fmt(r.cost_breakdown.total_production_cost)}
                            </span>
                        </div>

                        {r.cost_breakdown.lines.length === 0 ? (
                            <div className="px-5 py-8 text-center">
                                <p className="text-sm text-slate-400">No materials recorded.</p>
                                <p className="text-xs text-slate-300 mt-1">Use "Adjust Values" to add labour, packaging, and other costs.</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px]">
                                <thead>
                                    <tr className="border-b border-slate-50">
                                        <th className="px-5 py-2.5 text-left text-xs font-bold text-slate-400 uppercase tracking-widest">Item</th>
                                        <th className="px-3 py-2.5 text-right text-xs font-bold text-slate-400 uppercase tracking-widest">Qty</th>
                                        <th className="px-3 py-2.5 text-right text-xs font-bold text-slate-400 uppercase tracking-widest">Unit Cost</th>
                                        <th className="px-5 py-2.5 text-right text-xs font-bold text-slate-400 uppercase tracking-widest">Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {r.cost_breakdown.lines.map((line, i) => {
                                        const pct = r.cost_breakdown.total_production_cost > 0
                                            ? Math.round((line.total_cost / r.cost_breakdown.total_production_cost) * 100) : 0;
                                        return (
                                            <tr key={i} className="hover:bg-slate-50 transition-colors">
                                                <td className="px-5 py-3">
                                                    <p className="text-sm font-semibold text-slate-800">{line.cost_item}</p>
                                                    {line.description !== line.cost_item && (
                                                        <p className="text-xs text-slate-400 mt-0.5">{line.description}</p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-3 text-right text-sm text-slate-500 tabular-nums">
                                                    {line.quantity !== null ? `${fmt(line.quantity)} ${line.unit ?? ""}` : "—"}
                                                </td>
                                                <td className="px-3 py-3 text-right text-sm text-slate-500 tabular-nums">
                                                    {line.unit_cost !== null ? fmt(line.unit_cost) : "—"}
                                                </td>
                                                <td className="px-5 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <div className="w-10 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                                            <div className="h-full bg-amber-400 rounded-full"
                                                                style={{ width: `${pct}%` }} />
                                                        </div>
                                                        <span className="text-xs text-slate-300 tabular-nums w-7 text-right">{pct}%</span>
                                                        <span className="text-sm font-bold text-slate-800 tabular-nums">
                                                            {fmt(line.total_cost)}
                                                        </span>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t-2 border-slate-100 bg-slate-50">
                                        <td colSpan={3} className="px-5 py-3 text-sm font-bold text-slate-700">
                                            Total &nbsp;·&nbsp;
                                            <span className="font-normal text-slate-400">
                                                KES {fmt(r.cost_summary.cost_per_unit)} per unit
                                            </span>
                                        </td>
                                        <td className="px-5 py-3 text-right text-sm font-bold text-slate-900 tabular-nums">
                                            KES {fmt(r.cost_breakdown.total_production_cost)}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                            </div>
                        )}
                    </section>

                    {/* Sales */}
                    <section className="rounded-2xl border border-slate-100 bg-white overflow-hidden">
                        <div className="flex items-center justify-between px-5 py-4 border-b border-slate-50">
                            <div>
                                <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Step 2</p>
                                <h2 className="text-sm font-bold text-slate-800 mt-0.5">Sales</h2>
                            </div>
                            <span className="rounded-lg bg-indigo-50 border border-indigo-100 px-3 py-1 text-xs font-bold text-indigo-700">
                                KES {fmt(r.sales_summary.total_sales)}
                            </span>
                        </div>

                        {/* Sell-through bar */}
                        <div className="px-5 pt-4 pb-3">
                            {/* Only show the manual-entry notice for stock batches (no linked sales order) */}
                            {!r.header.is_customer_order && r.sales_summary.quantity_sold === 0 && (
                                <div className="mb-3 rounded-lg bg-amber-50 border border-amber-100 px-3 py-2.5 flex items-start gap-2">
                                    <svg className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                    </svg>
                                    <p className="text-xs text-amber-700 leading-relaxed">
                                        Stock production batch — sales are not automatically linked.
                                        Use <button onClick={() => setPanel(true)} className="font-semibold underline underline-offset-2">Adjust Values</button> to enter the quantity sold and selling price.
                                    </p>
                                </div>
                            )}
                            <div className="flex items-center justify-between text-xs text-slate-500 mb-1.5">
                                <span className="font-medium">Batch sell-through</span>
                                <span className="font-bold text-slate-700">{soldPct}%</span>
                            </div>
                            <div className="h-2.5 bg-slate-100 rounded-full overflow-hidden">
                                <div className="h-full rounded-full transition-all duration-700"
                                    style={{
                                        width: `${soldPct}%`,
                                        backgroundColor: soldPct >= 80 ? "#059669" : soldPct >= 50 ? "#6366F1" : "#D97706",
                                    }} />
                            </div>
                            <div className="flex justify-between text-xs text-slate-400 mt-1.5">
                                <span>{fmt(r.sales_summary.quantity_sold)} sold</span>
                                {r.sales_summary.remaining_stock > 0 && (
                                    <span className="font-semibold text-amber-500">
                                        {fmt(r.sales_summary.remaining_stock)} remaining
                                    </span>
                                )}
                                <span>{fmt(r.sales_summary.quantity_produced)} produced</span>
                            </div>
                        </div>

                        <div className="px-5 pb-4 grid grid-cols-3 gap-3">
                            {[
                                { label: "Qty Sold",      value: `${fmt(r.sales_summary.quantity_sold)} pcs`, bold: false },
                                { label: "Price / Unit",  value: `KES ${fmt(r.sales_summary.selling_price)}`, bold: false },
                                { label: "Total Revenue", value: `KES ${fmt(r.sales_summary.total_sales)}`,   bold: true  },
                            ].map(item => (
                                <div key={item.label} className="rounded-xl bg-slate-50 px-3 py-2.5">
                                    <p className="text-xs text-slate-400">{item.label}</p>
                                    <p className={clsx("text-sm mt-0.5 tabular-nums",
                                        item.bold ? "font-bold text-slate-900" : "font-semibold text-slate-700")}>
                                        {item.value}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* Selling Expenses */}
                    <section className="rounded-2xl border border-slate-100 bg-white overflow-hidden">
                        <div className="flex items-center justify-between px-5 py-4 border-b border-slate-50">
                            <div>
                                <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">Step 3</p>
                                <h2 className="text-sm font-bold text-slate-800 mt-0.5">Selling Expenses</h2>
                            </div>
                            <span className="rounded-lg bg-red-50 border border-red-100 px-3 py-1 text-xs font-bold text-red-600">
                                −KES {fmt(r.net_profit.total_selling_expenses)}
                            </span>
                        </div>

                        {r.net_profit.selling_expenses.every(e => e.amount === 0) ? (
                            <p className="px-5 py-5 text-sm text-slate-400">
                                No selling expenses recorded.{" "}
                                <button onClick={() => setPanel(true)}
                                    className="text-indigo-500 hover:text-indigo-700 underline underline-offset-2">
                                    Adjust Values
                                </button>{" "}to add them.
                            </p>
                        ) : (
                            <div className="divide-y divide-slate-50">
                                {r.net_profit.selling_expenses.filter(e => e.amount > 0).map(exp => {
                                    const pct = r.net_profit.total_selling_expenses > 0
                                        ? Math.round((exp.amount / r.net_profit.total_selling_expenses) * 100) : 0;
                                    return (
                                        <div key={exp.label}
                                            className="flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
                                            <div className="flex items-center gap-3 flex-1 min-w-0">
                                                <span className="text-sm text-slate-600">{exp.label}</span>
                                                <div className="flex-1 max-w-20 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                                    <div className="h-full bg-red-300 rounded-full" style={{ width: `${pct}%` }} />
                                                </div>
                                                <span className="text-xs text-slate-300 tabular-nums">{pct}%</span>
                                            </div>
                                            <span className="text-sm font-bold text-slate-800 tabular-nums ml-4">
                                                {fmt(exp.amount)}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </section>
                </div>

                {/* ── RIGHT (2/5) ── */}
                <div className="lg:col-span-2 space-y-4">



                    {/* Profit Bridge */}
                    <section className="rounded-2xl border border-slate-100 bg-white overflow-hidden">
                        <div className="px-5 py-4 border-b border-slate-50">
                            <h2 className="text-sm font-bold text-slate-800">Profit Bridge</h2>
                        </div>
                        <div className="divide-y divide-slate-50">
                            {[
                                { label: "Revenue",            value:  r.gross_profit.total_sales,           textColor: "text-slate-800", sign: ""  },
                                { label: "Cost of Goods Sold", value: -r.gross_profit.cogs,                  textColor: "text-amber-600", sign: "−" },
                                { label: "Gross Profit",       value:  r.gross_profit.gross_profit,          textColor: "text-emerald-700", sign: "", bold: true, border: true },
                                { label: "Selling Expenses",   value: -r.net_profit.total_selling_expenses,  textColor: "text-red-500",  sign: "−" },
                                { label: "Net Profit",         value:  r.net_profit.net_profit,              textColor: r.net_profit.net_profit >= 0 ? "text-emerald-700" : "text-red-600", sign: "", bold: true, shaded: true },
                            ].map(row => (
                                <div key={row.label}
                                    className={clsx(
                                        "flex items-center justify-between px-5 py-3",
                                        row.shaded && "bg-slate-50",
                                        row.border && "border-t border-dashed border-slate-200",
                                    )}>
                                    <span className={clsx("text-sm", row.bold ? "font-bold text-slate-900" : "text-slate-500")}>
                                        {row.label}
                                    </span>
                                    <span className={clsx("text-sm tabular-nums font-semibold", row.textColor, row.bold && "font-bold")}>
                                        {row.sign}KES {fmt(Math.abs(row.value))}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* Recommendations */}
                    <section className="rounded-2xl border border-slate-100 bg-white overflow-hidden">
                        <div className="px-5 py-4 border-b border-slate-50">
                            <h2 className="text-sm font-bold text-slate-800">Recommendations</h2>
                        </div>
                        <div className="p-4 space-y-2.5">
                            {[
                                { icon: "💰", label: "Pricing",      value: r.recommendation.pricing_recommendation, cls: "bg-indigo-50 border-indigo-100" },
                                { icon: "⚙️", label: "Cost Control", value: r.recommendation.cost_control_note,      cls: "bg-amber-50 border-amber-100"   },
                                { icon: "📦", label: "Stock",        value: r.recommendation.stock_action,           cls: "bg-slate-50 border-slate-100"   },
                                { icon: "✅", label: "Decision",     value: r.recommendation.management_decision,    cls: "bg-emerald-50 border-emerald-100" },
                            ].map(item => (
                                <div key={item.label}
                                    className={clsx("rounded-xl border px-3.5 py-3 flex items-start gap-2.5", item.cls)}>
                                    <span className="text-sm leading-none mt-0.5 shrink-0">{item.icon}</span>
                                    <div className="min-w-0">
                                        <p className="text-xs font-bold text-slate-400 uppercase tracking-widest">{item.label}</p>
                                        <p className="text-sm font-medium text-slate-800 mt-0.5 leading-snug">{item.value}</p>
                                    </div>
                                </div>
                            ))}

                            {r.recommendation.management_comment && (
                                <div className="rounded-xl border border-slate-200 bg-white px-3.5 py-3">
                                    <p className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-1">Comment</p>
                                    <p className="text-sm text-slate-600 italic leading-relaxed">
                                        "{r.recommendation.management_comment}"
                                    </p>
                                </div>
                            )}
                        </div>
                    </section>
                </div>
            </div>

            {/* Footer */}
            <p className="text-center text-xs text-slate-300 pt-6">
                Generated {r.header.generated_at} · Bethany House
            </p>
        </div>
    );
}