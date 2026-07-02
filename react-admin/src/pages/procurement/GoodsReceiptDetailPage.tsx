import { useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useQuery, useMutation } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post } from "@/api/client";
import { grnApi, type GRNDetail } from "@/api/procurement";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";

// ── Types ─────────────────────────────────────────────────────────────────────

// GRNDetail type is imported from @/api/procurement

interface AuditEntry {
    id: number; event: string; label: string; description: string;
    properties: Record<string, any>; actor_name: string; created_at: string;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtDate = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleDateString("en-KE", { day: "2-digit", month: "short", year: "numeric" }) : "-";

const fmtDateTime = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleString("en-KE", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "-";

const fmt = (n: number | string | null | undefined) => {
    const v = Number(n ?? 0);
    return `KES ${v.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

function SectionLabel({ children }: { children: React.ReactNode }) {
    return <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">{children}</p>;
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-2 py-1.5 border-b border-surface-50 last:border-0">
            <span className="text-xs text-surface-400 shrink-0">{label}</span>
            <span className="text-xs text-surface-800 font-medium text-right">{value ?? "-"}</span>
        </div>
    );
}

// ── Audit Trail ───────────────────────────────────────────────────────────────

function AuditTrail({ grnId }: { grnId: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ["grn-audit", grnId],
        queryFn: () => get<{ logs: AuditEntry[] }>(`/v1/admin/grn/${grnId}/audit-log`),
        staleTime: 30_000,
    });
    const logs = data?.logs ?? [];
    if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
    if (!logs.length) return <div className="text-center py-12 text-xs text-surface-400">No audit entries yet.</div>;
    return (
        <div className="divide-y divide-surface-50">
            {logs.map((entry) => (
                <div key={entry.id} className="flex gap-3 py-3.5">
                    <div className="w-7 h-7 rounded-full bg-emerald-100 flex items-center justify-center shrink-0 mt-0.5">
                        <svg className="w-3 h-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-2">
                            <span className="text-xs font-semibold text-surface-800">
                                {entry.label}
                                <span className="font-normal text-surface-500 ml-1">· {entry.actor_name}</span>
                            </span>
                            <span className="text-2xs text-surface-400 shrink-0">{fmtDateTime(entry.created_at)}</span>
                        </div>
                        <p className="text-xs text-surface-600 mt-0.5">{entry.description}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function GoodsReceiptDetailPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const { can } = usePermissions();
    const canReceive = can("procurement.receive");
    const [tab, setTab] = useState<"items" | "audit">("items");

    const { data, isLoading } = useQuery({
        queryKey: ["grn", id],
        queryFn: () => grnApi.get(Number(id)),
        enabled: !!id,
        staleTime: 30_000,
    });

    const printMutation = useMutation({
        mutationFn: () => post<any>(`/v1/admin/grn/${id}/print`, {}),
        onSuccess: () => toast.success("GRN sent to print"),
        onError: (e: any) => toast.error(e?.message ?? "Print failed"),
    });

    if (isLoading) return <div className="flex items-center justify-center h-64"><Spinner /></div>;
    if (!data?.grn) return (
        <div className="text-center py-16 text-surface-400 text-sm">
            GRN not found.
            <button onClick={() => navigate("/procurement/goods-receipt")} className="block mt-3 btn-secondary mx-auto">Back</button>
        </div>
    );

    const grn = data.grn;
    const items = grn.items ?? [];
    const totalAccepted = items.reduce((s, i) => s + i.quantity_received - i.quantity_rejected, 0);
    const totalRejected = items.reduce((s, i) => s + i.quantity_rejected, 0);
    const totalValue    = items.reduce((s, i) => s + (i.quantity_received - i.quantity_rejected) * (i.purchase_order_item?.unit_price ?? 0), 0);
    const receivedByName = grn.received_by
        ? `${grn.received_by.first_name} ${grn.received_by.last_name}`.trim()
        : "-";

    return (
        <div className="max-w-5xl mx-auto">
            <button onClick={() => navigate("/procurement/goods-receipt")}
                className="flex items-center gap-1.5 text-xs text-surface-500 hover:text-surface-800 mb-4 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Goods Receipts
            </button>

            <div className="bg-white rounded-2xl shadow-sm border border-surface-200 overflow-hidden">

                {/* Header band */}
                <div className="bg-gradient-to-r from-emerald-800 to-teal-700 px-5 py-5 sm:px-8 sm:py-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
                        <div>
                            <p className="text-emerald-300 text-xs font-semibold uppercase tracking-widest mb-1">
                                Goods Received Note
                            </p>
                            <h1 className="text-2xl font-bold text-white font-mono">{grn.grn_number}</h1>
                            <div className="flex items-center gap-2 mt-2 flex-wrap">
                                <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
                                    Received
                                </span>
                                {grn.purchase_order && (
                                    <Link
                                        to={`/procurement/purchase-orders/${grn.purchase_order.id}`}
                                        className="text-xs text-emerald-200 hover:text-white font-mono underline-offset-2 hover:underline">
                                        PO: {grn.purchase_order.po_number}
                                    </Link>
                                )}
                            </div>
                        </div>
                        <div className="sm:text-right">
                            <p className="text-emerald-300 text-2xs uppercase tracking-widest mb-1">Total Value Received</p>
                            <p className="text-3xl font-bold text-white tabular-nums">{fmt(totalValue)}</p>
                            <p className="text-emerald-300 text-xs mt-1">
                                {fmtDate(grn.received_date)}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Stats row */}
                <div className="px-5 py-4 bg-emerald-50 border-b border-emerald-100 grid grid-cols-1 gap-4 sm:px-8 sm:grid-cols-3">
                    {[
                        { label: "Items", value: items.length, color: "text-surface-800" },
                        { label: "Accepted Units", value: totalAccepted, color: "text-emerald-700" },
                        { label: "Rejected Units", value: totalRejected, color: totalRejected > 0 ? "text-red-700" : "text-surface-400" },
                    ].map((stat) => (
                        <div key={stat.label} className="text-center">
                            <p className={clsx("text-2xl font-bold tabular-nums", stat.color)}>{stat.value}</p>
                            <p className="text-2xs text-emerald-600 uppercase tracking-widest">{stat.label}</p>
                        </div>
                    ))}
                </div>

                {/* Action bar */}
                <div className="px-5 py-3 bg-slate-50 border-b border-surface-100 flex flex-wrap items-center gap-2 sm:px-8">
                    {canReceive && (
                    <button onClick={() => printMutation.mutate()} disabled={printMutation.isPending}
                        className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-surface-300">
                        🖨 Print GRN
                    </button>
                    )}
                    <PdfDownloadButton type="grn" id={Number(id)} label="Download PDF" />
                    {grn.purchase_order && (
                        <Link to={`/procurement/purchase-orders/${grn.purchase_order.id}`}
                            className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-surface-300">
                            View Purchase Order ↗
                        </Link>
                    )}
                    {totalRejected > 0 && (
                        <Link to="/procurement/returns"
                            className="btn-sm bg-red-50 border border-red-200 text-red-700 rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-red-100 ml-auto">
                            ↩ Create Purchase Return for Rejected Items
                        </Link>
                    )}
                </div>

                {/* Body */}
                <div className="px-5 py-5 grid grid-cols-1 lg:grid-cols-[1fr_240px] gap-6 lg:gap-8 sm:px-8 sm:py-6 lg:divide-x divide-surface-100">

                    {/* Left */}
                    <div className="space-y-4 lg:pr-8">
                        <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar">
                            {(["items", "audit"] as const).map((t) => (
                                <button key={t} onClick={() => setTab(t)}
                                    className={clsx("px-4 py-2.5 text-xs font-semibold border-b-2 transition-all whitespace-nowrap shrink-0",
                                        tab === t ? "border-emerald-500 text-emerald-600" : "border-transparent text-surface-400 hover:text-surface-700")}>
                                    {t === "items" ? `📦 Items Received (${items.length})` : "🕐 Audit Trail"}
                                </button>
                            ))}
                        </div>

                        {tab === "items" && (
                            <div className="overflow-x-auto rounded-xl border border-surface-200">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="bg-surface-50 border-b border-surface-200">
                                            <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Item</th>
                                            <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Type</th>
                                            <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Received</th>
                                            <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Rejected</th>
                                            <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Accepted</th>
                                            <th className="text-center px-3 py-2.5 font-semibold text-surface-600">Condition</th>
                                            <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Unit Cost</th>
                                            <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Line Value</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-100">
                                        {items.map((item) => {
                                            const accepted = item.quantity_received - item.quantity_rejected;
                                            const lineValue = accepted * (item.purchase_order_item?.unit_price ?? 0);
                                            const hasRejection = item.quantity_rejected > 0;
                                            return (
                                                <tr key={item.id} className={clsx(hasRejection && "bg-red-50/30")}>
                                                    <td className="px-3 py-2.5">
                                                        <p className="font-medium text-surface-800">
                                                            {item.purchase_order_item?.description}
                                                        </p>
                                                        {item.purchase_order_item?.product?.sku && (
                                                            <p className="text-2xs text-surface-400 font-mono">
                                                                SKU: {item.purchase_order_item.product.sku}
                                                            </p>
                                                        )}
                                                        {item.notes && (
                                                            <p className="text-2xs text-amber-600 italic mt-0.5">{item.notes}</p>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5">
                                                        <span className={clsx("px-2 py-0.5 rounded-full text-2xs font-semibold",
                                                            item.purchase_order_item?.item_type === "product"
                                                                ? "bg-blue-50 text-blue-700"
                                                                : "bg-purple-50 text-purple-700")}>
                                                            {item.purchase_order_item?.item_type ?? "-"}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right tabular-nums">{item.quantity_received}</td>
                                                    <td className="px-3 py-2.5 text-right tabular-nums">
                                                        {hasRejection ? (
                                                            <span className="text-red-700 font-semibold">{item.quantity_rejected}</span>
                                                        ) : (
                                                            <span className="text-surface-300">-</span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right tabular-nums font-semibold text-emerald-700">{accepted}</td>
                                                    <td className="px-3 py-2.5 text-center">
                                                        <span className={clsx("px-2 py-0.5 rounded-full text-2xs font-semibold",
                                                            item.condition === "passed" ? "bg-emerald-50 text-emerald-700" : "bg-red-50 text-red-700")}>
                                                            {item.condition === "passed" ? "✅ Passed" : "❌ Rejected"}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right tabular-nums text-surface-600">
                                                        {fmt(item.purchase_order_item?.unit_price ?? 0)}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-right tabular-nums font-semibold">{fmt(lineValue)}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                    <tfoot className="border-t-2 border-surface-200 bg-surface-50">
                                        <tr>
                                            <td colSpan={7} className="px-3 py-2.5 text-sm font-bold text-surface-800">
                                                Total Value Added to Stock
                                            </td>
                                            <td className="px-3 py-2.5 text-right text-base font-bold tabular-nums text-surface-900">
                                                {fmt(totalValue)}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}

                        {tab === "audit" && <AuditTrail grnId={grn.id} />}

                        {grn.notes && (
                            <div className="p-4 bg-amber-50 rounded-xl border border-amber-100">
                                <SectionLabel>Receipt Notes</SectionLabel>
                                <p className="text-xs text-amber-900 whitespace-pre-wrap">{grn.notes}</p>
                            </div>
                        )}

                        {totalRejected > 0 && (
                            <div className="p-4 bg-red-50 rounded-xl border border-red-100">
                                <p className="text-xs font-semibold text-red-800">
                                    ⚠️ {totalRejected} unit(s) were rejected during this receipt.
                                    Consider creating a Purchase Return to send them back to the supplier.
                                </p>
                                {grn.purchase_order && (
                                    <Link
                                        to={`/procurement/purchase-orders/${grn.purchase_order.id}`}
                                        className="text-xs text-red-700 hover:underline mt-1 block">
                                        Open PO to process return →
                                    </Link>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Right sidebar */}
                    <div className="lg:pl-8 space-y-6">
                        <div>
                            <SectionLabel>Supplier</SectionLabel>
                            <div className="p-3 bg-surface-50 rounded-xl">
                                <p className="text-sm font-semibold text-surface-800">
                                    {grn.purchase_order?.supplier?.name ?? "-"}
                                </p>
                                {grn.purchase_order?.supplier?.company_code && (
                                    <p className="text-xs text-surface-500 mt-0.5">
                                        {grn.purchase_order.supplier.company_code}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div>
                            <SectionLabel>Purchase Order</SectionLabel>
                            {grn.purchase_order ? (
                                <Link to={`/procurement/purchase-orders/${grn.purchase_order.id}`}
                                    className="flex items-center justify-between p-3 bg-brand-50 rounded-xl border border-brand-100 hover:bg-brand-100 transition-colors">
                                    <span className="text-sm font-mono font-semibold text-brand-700">
                                        {grn.purchase_order.po_number}
                                    </span>
                                    <svg className="w-3.5 h-3.5 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                    </svg>
                                </Link>
                            ) : (
                                <p className="text-sm text-surface-400 p-3">No purchase order linked.</p>
                            )}
                        </div>

                        <div>
                            <SectionLabel>Record Info</SectionLabel>
                            <InfoRow label="GRN Number" value={<span className="font-mono">{grn.grn_number}</span>} />
                            <InfoRow label="Received On" value={fmtDate(grn.received_date)} />
                            <InfoRow label="Received By" value={receivedByName} />
                            <InfoRow label="Created" value={fmtDateTime(grn.created_at)} />
                        </div>

                        <div>
                            <SectionLabel>Receipt Summary</SectionLabel>
                            <InfoRow label="Line Items" value={items.length} />
                            <InfoRow label="Units Received" value={
                                <span className="tabular-nums">
                                    {items.reduce((s, i) => s + i.quantity_received, 0)}
                                </span>
                            } />
                            <InfoRow label="Units Accepted" value={
                                <span className="text-emerald-700 font-semibold tabular-nums">{totalAccepted}</span>
                            } />
                            <InfoRow label="Units Rejected" value={
                                <span className={clsx("tabular-nums font-semibold", totalRejected > 0 ? "text-red-700" : "text-surface-400")}>
                                    {totalRejected}
                                </span>
                            } />
                            <InfoRow label="Total Value" value={fmt(totalValue)} />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}