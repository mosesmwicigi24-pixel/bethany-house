import { useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";

// ── Types ─────────────────────────────────────────────────────────────────────

interface ReturnItem {
    id: number;
    po_item_id: number;
    quantity: number;
    reason: string;
    purchase_order_item: {
        item_type: string;
        description: string;
        unit_price: number;
        product: { name: string; sku: string } | null;
        material: { name: string } | null;
    };
}

interface PurchaseReturn {
    id: number;
    return_number: string;
    purchase_order_id: number;
    status: string;
    notes: string | null;
    reason: string | null;
    return_date: string | null;
    created_at: string;
    purchase_order: {
        id: number;
        po_number: string;
        supplier: { id: number; name: string } | null;
    } | null;
    supplier: { id: number; name: string } | null;
    supplier_name?: string;
    po_number?: string;
    items: ReturnItem[];
    items_count?: number;
    created_by_user: { id: number; first_name: string; last_name: string } | null;
}

interface AuditEntry {
    id: number;
    event: string;
    label: string;
    description: string;
    properties: Record<string, any>;
    actor_name: string;
    created_at: string;
}

// ── Config ────────────────────────────────────────────────────────────────────

const STATUS_CFG: Record<string, { label: string; color: string; bg: string; border: string }> = {
    pending:   { label: "Pending",   color: "text-amber-700",   bg: "bg-amber-50",   border: "border-amber-200" },
    approved:  { label: "Approved",  color: "text-emerald-700", bg: "bg-emerald-50", border: "border-emerald-200" },
    completed: { label: "Completed", color: "text-blue-700",    bg: "bg-blue-50",    border: "border-blue-200" },
    rejected:  { label: "Rejected",  color: "text-red-700",     bg: "bg-red-50",     border: "border-red-200" },
    cancelled: { label: "Cancelled", color: "text-surface-500", bg: "bg-surface-100",border: "border-surface-200" },
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtDate = (d: string | null | undefined) => {
    if (!d) return "-";
    return new Date(d).toLocaleDateString("en-KE", { day: "2-digit", month: "short", year: "numeric" });
};
const fmtDateTime = (d: string | null | undefined) => {
    if (!d) return "-";
    return new Date(d).toLocaleString("en-KE", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
};
const fmtMoney = (n: number | null | undefined) =>
    `KES ${Number(n ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}`;

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

function AuditTrail({ returnId }: { returnId: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ["purchase-return-audit", returnId],
        queryFn: () => get<{ logs: AuditEntry[] }>(`/v1/admin/purchase-returns/${returnId}/audit-log`),
        staleTime: 30_000,
    });
    const logs = data?.logs ?? [];

    if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
    if (!logs.length) return (
        <div className="text-center py-12 text-xs text-surface-400">No audit entries yet.</div>
    );
    return (
        <div className="divide-y divide-surface-50">
            {logs.map((e) => (
                <div key={e.id} className="flex gap-3 py-3.5">
                    <div className="w-7 h-7 rounded-full bg-red-100 flex items-center justify-center shrink-0 mt-0.5">
                        <svg className="w-3 h-3 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-2">
                            <span className="text-xs font-semibold text-surface-800">
                                {e.label}
                                <span className="font-normal text-surface-500 ml-1">· {e.actor_name}</span>
                            </span>
                            <span className="text-2xs text-surface-400 shrink-0">{fmtDateTime(e.created_at)}</span>
                        </div>
                        <p className="text-xs text-surface-600 mt-0.5">{e.description}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function PurchaseReturnDetailPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const [tab, setTab] = useState<"items" | "audit">("items");

    const { data, isLoading } = useQuery({
        queryKey: ["purchase-return", id],
        queryFn: () => get<{ return: PurchaseReturn }>(`/v1/admin/purchase-returns/${id}`),
        enabled: !!id,
        staleTime: 30_000,
    });

    const pr = data?.return;
    const refresh = () => {
        qc.invalidateQueries({ queryKey: ["purchase-return", id] });
        qc.invalidateQueries({ queryKey: ["purchase-returns"] });
    };

    const approveMutation = useMutation({
        mutationFn: () => post(`/v1/admin/purchase-returns/${id}/approve`, {}),
        onSuccess: () => { toast.success("Return approved"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed"),
    });

    const rejectMutation = useMutation({
        mutationFn: (reason: string) => post(`/v1/admin/purchase-returns/${id}/reject`, { reason }),
        onSuccess: () => { toast.success("Return rejected"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed"),
    });

    const completeMutation = useMutation({
        mutationFn: (notes: string) => post(`/v1/admin/purchase-returns/${id}/complete`, { notes }),
        onSuccess: () => { toast.success("Return marked as completed"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed"),
    });

    if (isLoading) return <div className="flex items-center justify-center h-64"><Spinner /></div>;
    if (!pr) return (
        <div className="text-center py-16 text-surface-400 text-sm">
            Purchase return not found.
            <button onClick={() => navigate("/procurement/returns")} className="block mt-3 btn-secondary mx-auto">Back</button>
        </div>
    );

    const statusCfg = STATUS_CFG[pr.status ?? "pending"] ?? STATUS_CFG.pending;
    const supplierName = pr.supplier?.name ?? pr.purchase_order?.supplier?.name ?? pr.supplier_name ?? "-";
    const poNumber     = pr.purchase_order?.po_number ?? pr.po_number ?? "-";
    const poId         = pr.purchase_order?.id ?? pr.purchase_order_id;
    const items        = pr.items ?? [];
    const totalValue   = items.reduce((sum, i) => sum + i.quantity * (i.purchase_order_item?.unit_price ?? 0), 0);
    const { can } = usePermissions();
    const canApprovePR = can("procurement.approve");
    const canReceivePR = can("procurement.receive");
    const canApprove  = pr.status === "pending" && canApprovePR;
    const canReject   = pr.status === "pending" && canApprovePR;
    const canComplete = pr.status === "approved" && canReceivePR;

    return (
        <div className="max-w-5xl mx-auto">
            {/* Back */}
            <button onClick={() => navigate("/procurement/returns")}
                className="flex items-center gap-1.5 text-xs text-surface-500 hover:text-surface-800 mb-4 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Purchase Returns
            </button>

            <div className="bg-white rounded-2xl shadow-sm border border-surface-200 overflow-hidden">

                {/* Header band */}
                <div className="bg-gradient-to-r from-red-800 to-red-700 px-5 py-5 sm:px-8 sm:py-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
                        <div>
                            <p className="text-red-300 text-xs font-semibold uppercase tracking-widest mb-1">
                                Purchase Return
                            </p>
                            <h1 className="text-2xl font-bold text-white font-mono">{pr.return_number}</h1>
                            <div className="flex items-center gap-2 mt-2 flex-wrap">
                                <span className={clsx("px-2.5 py-1 rounded-full text-xs font-semibold capitalize", statusCfg.bg, statusCfg.color)}>
                                    {statusCfg.label}
                                </span>
                                <Link
                                    to={`/procurement/purchase-orders/${poId}`}
                                    className="text-xs text-red-200 hover:text-white font-mono underline-offset-2 hover:underline"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    PO: {poNumber}
                                </Link>
                            </div>
                        </div>
                        <div className="sm:text-right">
                            <p className="text-red-300 text-2xs uppercase tracking-widest mb-1">Return Value</p>
                            <p className="text-3xl font-bold text-white tabular-nums">{fmtMoney(totalValue)}</p>
                            <p className="text-red-300 text-xs mt-1">{fmtDate(pr.return_date ?? pr.created_at)}</p>
                        </div>
                    </div>
                </div>

                {/* Action bar */}
                {(canApprove || canReject || canComplete) && (
                    <div className={clsx(
                        "px-5 py-3 border-b flex flex-col gap-2 sm:px-8 sm:flex-row sm:items-center sm:flex-wrap",
                        canApprove ? "bg-amber-50 border-amber-100" : "bg-slate-50 border-surface-100"
                    )}>
                        {canApprove && (
                            <span className="text-xs text-amber-700 font-semibold flex-1">
                                This return is pending approval
                            </span>
                        )}
                        {canComplete && (
                            <span className="text-xs text-blue-700 font-semibold flex-1">
                                Return approved - mark complete once the supplier has credited or replaced items
                            </span>
                        )}
                        {canApprove && (
                            <button
                                onClick={() => approveMutation.mutate()}
                                disabled={approveMutation.isPending}
                                className="btn-sm bg-emerald-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700"
                            >
                                ✓ Approve Return
                            </button>
                        )}
                        {canReject && (
                            <button
                                onClick={() => {
                                    const reason = window.prompt("Reason for rejection:");
                                    if (reason) rejectMutation.mutate(reason);
                                }}
                                disabled={rejectMutation.isPending}
                                className="btn-sm bg-white text-danger border border-danger/30 rounded-xl px-3 py-1.5 text-xs font-semibold"
                            >
                                ✕ Reject
                            </button>
                        )}
                        {canComplete && (
                            <button
                                onClick={() => {
                                    const notes = window.prompt("Completion notes (e.g. credit note reference):") ?? "";
                                    completeMutation.mutate(notes);
                                }}
                                disabled={completeMutation.isPending}
                                className="btn-sm bg-blue-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-blue-700"
                            >
                                ✓ Mark Completed
                            </button>
                        )}
                    </div>
                )}

                {/* PDF / secondary action bar — always visible */}
                <div className="px-5 py-3 bg-white border-b border-surface-100 flex items-center gap-2 sm:px-8">
                    <PdfDownloadButton type="purchase-returns" id={pr.id} label="Download PDF" />
                </div>

                {/* Body */}
                <div className="px-5 py-5 grid grid-cols-1 lg:grid-cols-[1fr_240px] gap-6 lg:gap-8 sm:px-8 sm:py-6 lg:divide-x divide-surface-100">

                    {/* Left */}
                    <div className="space-y-4 lg:pr-8">

                        {/* Tabs */}
                        <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar">
                            {(["items", "audit"] as const).map((t) => (
                                <button key={t} onClick={() => setTab(t)}
                                    className={clsx("px-4 py-2.5 text-xs font-semibold border-b-2 transition-all whitespace-nowrap shrink-0",
                                        tab === t ? "border-red-500 text-red-600" : "border-transparent text-surface-400 hover:text-surface-700")}>
                                    {t === "items" ? `📦 Returned Items (${items.length})` : "🕐 Audit Trail"}
                                </button>
                            ))}
                        </div>

                        {tab === "items" && (
                            <>
                                <div className="overflow-x-auto rounded-xl border border-surface-200">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="bg-surface-50 border-b border-surface-200">
                                                <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Item</th>
                                                <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Type</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Qty Returned</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Unit Price</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Line Value</th>
                                                <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Reason</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-surface-100">
                                            {items.map((item) => {
                                                const poi = item.purchase_order_item;
                                                const name = poi?.product?.name ?? poi?.material?.name ?? poi?.description ?? `Item #${item.po_item_id}`;
                                                const lineValue = item.quantity * (poi?.unit_price ?? 0);
                                                return (
                                                    <tr key={item.id}>
                                                        <td className="px-3 py-2.5">
                                                            <p className="font-medium text-surface-800">{name}</p>
                                                            {poi?.product?.sku && (
                                                                <p className="text-2xs text-surface-400 font-mono">{poi.product.sku}</p>
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2.5">
                                                            <span className={clsx("px-2 py-0.5 rounded-full text-2xs font-semibold capitalize",
                                                                poi?.item_type === "product" ? "bg-blue-50 text-blue-700" : "bg-purple-50 text-purple-700")}>
                                                                {poi?.item_type ?? "-"}
                                                            </span>
                                                        </td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums font-semibold text-red-700">
                                                            -{item.quantity}
                                                        </td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums text-surface-600">
                                                            {poi?.unit_price ? fmtMoney(poi.unit_price) : "-"}
                                                        </td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums font-semibold text-surface-800">
                                                            {fmtMoney(lineValue)}
                                                        </td>
                                                        <td className="px-3 py-2.5 text-surface-600">{item.reason || "-"}</td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                        <tfoot className="border-t-2 border-surface-200 bg-red-50">
                                            <tr>
                                                <td colSpan={4} className="px-3 py-2.5 text-sm font-bold text-surface-800">
                                                    Total Return Value
                                                </td>
                                                <td className="px-3 py-2.5 text-right text-base font-bold tabular-nums text-red-700">
                                                    {fmtMoney(totalValue)}
                                                </td>
                                                <td />
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                {/* Reason / Notes */}
                                {(pr.reason || pr.notes) && (
                                    <div className="space-y-2">
                                        {pr.reason && (
                                            <div className="p-4 bg-surface-50 rounded-xl">
                                                <SectionLabel>Return Reason</SectionLabel>
                                                <p className="text-xs text-surface-700">{pr.reason}</p>
                                            </div>
                                        )}
                                        {pr.notes && (
                                            <div className="p-4 bg-amber-50 rounded-xl border border-amber-100">
                                                <SectionLabel>Notes</SectionLabel>
                                                <p className="text-xs text-amber-900 whitespace-pre-wrap">{pr.notes}</p>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </>
                        )}

                        {tab === "audit" && <AuditTrail returnId={pr.id} />}
                    </div>

                    {/* Right sidebar */}
                    <div className="lg:pl-8 space-y-6">

                        {/* Supplier */}
                        <div>
                            <SectionLabel>Supplier</SectionLabel>
                            <div className="p-3 bg-surface-50 rounded-xl">
                                <p className="text-sm font-semibold text-surface-800">{supplierName}</p>
                            </div>
                        </div>

                        {/* Linked PO */}
                        <div>
                            <SectionLabel>Purchase Order</SectionLabel>
                            <Link to={`/procurement/purchase-orders/${poId}`}
                                className="flex items-center justify-between p-3 bg-brand-50 rounded-xl border border-brand-100 hover:bg-brand-100 transition-colors">
                                <span className="text-sm font-mono font-semibold text-brand-700">{poNumber}</span>
                                <svg className="w-3.5 h-3.5 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                            </Link>
                        </div>

                        {/* Record info */}
                        <div>
                            <SectionLabel>Record Info</SectionLabel>
                            <InfoRow label="Return #" value={<span className="font-mono">{pr.return_number}</span>} />
                            <InfoRow label="Status" value={
                                <span className={clsx("px-2 py-0.5 rounded-full text-2xs font-semibold capitalize", statusCfg.bg, statusCfg.color)}>
                                    {statusCfg.label}
                                </span>
                            } />
                            <InfoRow label="Return Date" value={fmtDate(pr.return_date ?? pr.created_at)} />
                            <InfoRow label="Created" value={fmtDateTime(pr.created_at)} />
                            {pr.created_by_user && (
                                <InfoRow label="Raised By" value={
                                    `${pr.created_by_user.first_name} ${pr.created_by_user.last_name}`.trim()
                                } />
                            )}
                            <InfoRow label="Items" value={items.length} />
                            <InfoRow label="Total Units" value={items.reduce((s, i) => s + i.quantity, 0)} />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}