import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { posApi } from "@/api/pos";
import { Spinner } from "@/components/ui/Spinner";

interface Props {
    outletId: number;
    outletName: string;
    onClose: () => void;
}

export default function EndOfDayModal({ outletId, outletName, onClose }: Props) {
    const [date, setDate] = useState(new Date().toISOString().split("T")[0]);

    const { data, isLoading } = useQuery({
        queryKey: ["pos-eod", outletId, date],
        queryFn: () => posApi.dailySummary(outletId, date),
    });
    const summary = data?.summary;

    const fmt = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

    const handlePrint = () => window.print();

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col animate-slide-up">
                {/* Header */}
                <div className="px-5 py-4 border-b border-surface-100 flex flex-col gap-3 shrink-0 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="font-bold text-surface-900">End of Day Report</h2>
                        <p className="text-xs text-surface-500">{outletName}</p>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                        <input
                            type="date"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                            className="input text-xs flex-1 sm:flex-none"
                        />
                        <button onClick={handlePrint} className="btn-secondary btn-sm">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0" />
                            </svg>
                            Print
                        </button>
                        <button onClick={onClose} className="btn-ghost btn-icon btn-sm"
              aria-label="Close">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-5 space-y-5">
                    {isLoading ? (
                        <div className="flex items-center justify-center h-40">
                            <Spinner size="lg" />
                        </div>
                    ) : !summary ? (
                        <div className="flex flex-col items-center justify-center h-40 gap-2 text-surface-400">
                            <p className="text-sm">No data available for this date.</p>
                        </div>
                    ) : (
                        <>
                            {/* KPI row */}
                            <div className="grid grid-cols-1 gap-3 xs:grid-cols-3 sm:grid-cols-3">
                                {[
                                    { label: "Net Sales", value: `KES ${fmt(summary.net_sales)}`, color: "text-brand-600", bg: "bg-brand-50" },
                                    { label: "Transactions", value: summary.total_transactions, color: "text-surface-900", bg: "bg-surface-50" },
                                    { label: "Avg. Transaction", value: `KES ${fmt(summary.average_transaction)}`, color: "text-surface-700", bg: "bg-surface-50" },
                                ].map((kpi) => (
                                    <div key={kpi.label} className={clsx("rounded-xl p-3 text-center", kpi.bg)}>
                                        <p className="text-2xs text-surface-400">{kpi.label}</p>
                                        <p className={clsx("font-bold mt-0.5 text-sm", kpi.color)}>{kpi.value}</p>
                                    </div>
                                ))}
                            </div>

                            {/* Payment breakdown */}
                            <div>
                                <h3 className="text-xs font-semibold text-surface-700 mb-2">Payment Breakdown</h3>
                                <div className="space-y-2">
                                    {[
                                        { label: "Cash", amount: summary.cash_sales, color: "bg-success" },
                                        { label: "M-Pesa", amount: summary.mpesa_sales, color: "bg-brand-500" },
                                        { label: "Card", amount: summary.card_sales, color: "bg-info" },
                                        { label: "Other", amount: summary.other_sales, color: "bg-surface-400" },
                                    ].map((p) => {
                                        const pct = summary.total_sales > 0 ? (p.amount / summary.total_sales) * 100 : 0;
                                        return (
                                            <div key={p.label} className="flex items-center gap-3">
                                                <span className="w-14 text-xs text-surface-600 text-right">{p.label}</span>
                                                <div className="flex-1 bg-surface-100 rounded-full h-2 overflow-hidden">
                                                    <div
                                                        className={clsx("h-full rounded-full transition-all", p.color)}
                                                        style={{ width: `${pct}%` }}
                                                    />
                                                </div>
                                                <span className="text-xs font-medium text-surface-700 w-28 text-right">
                                                    KES {fmt(p.amount)}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Totals reconciliation */}
                            <div>
                                <h3 className="text-xs font-semibold text-surface-700 mb-2">Sales Reconciliation</h3>
                                <div className="bg-surface-50 rounded-xl p-3 space-y-1.5 text-xs">
                                    <div className="flex justify-between text-surface-600">
                                        <span>Gross Sales</span><span>KES {fmt(summary.total_sales)}</span>
                                    </div>
                                    <div className="flex justify-between text-danger">
                                        <span>Returns</span><span>-KES {fmt(summary.total_returns)}</span>
                                    </div>
                                    <div className="flex justify-between font-bold text-surface-900 border-t border-surface-200 pt-1.5">
                                        <span>Net Sales</span><span>KES {fmt(summary.net_sales)}</span>
                                    </div>
                                </div>
                            </div>

                            {/* Hourly breakdown */}
                            {summary.hourly_breakdown?.length > 0 && (
                                <div>
                                    <h3 className="text-xs font-semibold text-surface-700 mb-2">Hourly Activity</h3>
                                    <div className="flex items-end gap-1 h-16">
                                        {summary.hourly_breakdown.map((h) => {
                                            const maxSales = Math.max(...summary.hourly_breakdown.map((x) => x.sales));
                                            const pct = maxSales > 0 ? (h.sales / maxSales) * 100 : 0;
                                            return (
                                                <div key={h.hour} className="flex-1 flex flex-col items-center gap-1" title={`${h.hour}:00 · KES ${fmt(h.sales)}`}>
                                                    <div className="w-full bg-surface-100 rounded-sm overflow-hidden" style={{ height: 48 }}>
                                                        <div
                                                            className="w-full bg-brand-400 rounded-sm transition-all"
                                                            style={{ height: `${pct}%`, marginTop: `${100 - pct}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-2xs text-surface-300">{h.hour}</span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            {/* Top products */}
                            {summary.top_products?.length > 0 && (
                                <div>
                                    <h3 className="text-xs font-semibold text-surface-700 mb-2">Top Products</h3>
                                    <div className="space-y-2">
                                        {summary.top_products.slice(0, 5).map((p, i) => (
                                            <div key={i} className="flex items-center gap-3 text-xs">
                                                <span className="w-5 h-5 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center text-2xs font-bold shrink-0">
                                                    {i + 1}
                                                </span>
                                                <span className="flex-1 text-surface-700 truncate">{p.name}</span>
                                                <span className="text-surface-400">{p.qty} sold</span>
                                                <span className="font-medium text-surface-900">KES {fmt(p.revenue)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>

                <div className="p-4 border-t border-surface-100 shrink-0">
                    <button onClick={onClose} className="btn-secondary w-full">Close</button>
                </div>
            </div>
        </div>
    );
}