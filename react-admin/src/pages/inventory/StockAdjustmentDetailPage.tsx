import { useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, put } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";

// ── Types ─────────────────────────────────────────────────────────────────────

interface AdjustmentDetail {
    id: number;
    transaction_type: string;
    reason_code: string;
    reason_label: string;
    reference_number: string | null;
    quantity_change: number;
    quantity_before: number;
    quantity_after: number;
    notes: string | null;
    status: string;
    approval_notes: string | null;
    created_at: string;
    approved_at: string | null;
    created_by: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    inventory_item: {
        id: number;
        product: { id: number; sku: string; name: string; image_url?: string } | null;
        variant: { id: number; sku: string; variant_name: string } | null;
        outlet: { id: number; name: string } | null;
        quantity_on_hand: number;
    } | null;
}

interface AuditEntry {
    id: number; event: string; label: string; description: string;
    properties: Record<string, any>; actor_name: string; created_at: string;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtDateTime = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleString("en-KE", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "-";

const STATUS_CFG: Record<string, { label: string; color: string; bg: string }> = {
    approved:         { label: "Approved",          color: "text-emerald-700", bg: "bg-emerald-50" },
    pending_approval: { label: "Pending Approval",  color: "text-amber-700",   bg: "bg-amber-50" },
    rejected:         { label: "Rejected",          color: "text-red-700",     bg: "bg-red-50" },
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

function AuditTrail({ adjId }: { adjId: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ["adjustment-audit", adjId],
        queryFn: () => get<{ logs: AuditEntry[] }>(`/v1/admin/inventory/adjustments/${adjId}/audit-log`),
        staleTime: 30_000,
    });
    const logs = data?.logs ?? [];
    if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
    if (!logs.length) return <div className="text-center py-12 text-xs text-surface-400">No audit entries yet.</div>;
    return (
        <div className="divide-y divide-surface-50">
            {logs.map((entry) => (
                <div key={entry.id} className="flex gap-3 py-3.5">
                    <div className="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center shrink-0 mt-0.5">
                        <svg className="w-3 h-3 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-2">
                            <span className="text-xs font-semibold text-surface-800">{entry.label} <span className="font-normal text-surface-500">· {entry.actor_name}</span></span>
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

export default function StockAdjustmentDetailPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const [tab, setTab] = useState<"details" | "audit">("details");

    const { data, isLoading } = useQuery({
        queryKey: ["adjustment", id],
        queryFn: () => get<{ adjustment: AdjustmentDetail }>(`/v1/admin/inventory/adjustments/${id}`),
        enabled: !!id,
        staleTime: 30_000,
    });
    const adj = data?.adjustment;
    const refresh = () => qc.invalidateQueries({ queryKey: ["adjustment", id] });

    const approveMutation = useMutation({
        mutationFn: (notes: string) => put<any>(`/v1/admin/inventory/adjustments/${id}/approve`, { notes }),
        onSuccess: () => { toast.success("Adjustment approved"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Approval failed"),
    });

    const rejectMutation = useMutation({
        mutationFn: (reason: string) => put<any>(`/v1/admin/inventory/adjustments/${id}/reject`, { reason }),
        onSuccess: () => { toast.success("Adjustment rejected"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Rejection failed"),
    });

    const reverseMutation = useMutation({
        mutationFn: (notes: string) => put<any>(`/v1/admin/inventory/adjustments/${id}/reverse`, { notes }),
        onSuccess: () => { toast.success("Adjustment reversed - compensating transaction created"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Reversal failed"),
    });

    if (isLoading) return <div className="flex items-center justify-center h-64"><Spinner /></div>;
    if (!adj) return (
        <div className="text-center py-16 text-surface-400 text-sm">
            Adjustment not found.
            <button onClick={() => navigate("/inventory/adjustments")} className="block mt-3 btn-secondary mx-auto">Back</button>
        </div>
    );

    const delta = adj.quantity_change;
    const isPositive = delta > 0;
    const statusCfg  = STATUS_CFG[adj.status] ?? STATUS_CFG.approved;
    const item       = adj.inventory_item;
    const { can } = usePermissions();
    const canApprove = can("inventory.approve");
    const isPending  = adj.status === "pending_approval" && canApprove;
    const isApproved = adj.status === "approved";
    const isReversal = (adj.notes ?? "").startsWith("[REVERSAL") || (adj.notes ?? "").startsWith("REVERSAL");
    const canReverse = isApproved && !isReversal && canApprove;

    return (
        <div className="max-w-4xl mx-auto">
            <button onClick={() => navigate("/inventory/adjustments")}
                className="flex items-center gap-1.5 text-xs text-surface-500 hover:text-surface-800 mb-4 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Stock Adjustments
            </button>

            <div className="bg-white rounded-2xl shadow-sm border border-surface-200 overflow-hidden">

                {/* Header band */}
                <div className={clsx("px-5 py-5 sm:px-8 sm:py-6", isPositive ? "bg-gradient-to-r from-emerald-800 to-emerald-700" : "bg-gradient-to-r from-red-800 to-red-700")}>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-4 sm:flex-wrap">
                        <div>
                            <p className={clsx("text-xs font-semibold uppercase tracking-widest mb-1", isPositive ? "text-emerald-300" : "text-red-300")}>
                                Stock Adjustment #{adj.id}
                            </p>
                            <h1 className="text-xl font-bold text-white sm:text-2xl">
                                {item?.product?.name ?? "Unknown Product"}
                            </h1>
                            {item?.variant && (
                                <p className="text-white/70 text-sm mt-0.5">{item.variant.variant_name} · {item.variant.sku}</p>
                            )}
                            <div className="flex items-center gap-2 mt-2 flex-wrap">
                                <span className={clsx("px-2.5 py-1 rounded-full text-xs font-semibold", statusCfg.bg, statusCfg.color)}>
                                    {statusCfg.label}
                                </span>
                                <span className={clsx("px-2.5 py-1 rounded-full text-xs font-semibold",
                                    isPositive ? "bg-emerald-100 text-emerald-800" : "bg-red-100 text-red-800")}>
                                    {adj.reason_label}
                                </span>
                            </div>
                        </div>
                        <div className="sm:text-right">
                            <p className={clsx("text-xs uppercase tracking-widest mb-1", isPositive ? "text-emerald-300" : "text-red-300")}>
                                Quantity Change
                            </p>
                            <p className="text-4xl font-bold text-white tabular-nums">
                                {isPositive ? "+" : ""}{delta}
                            </p>
                            <p className="text-white/60 text-xs mt-1">
                                {adj.quantity_before} → {adj.quantity_after} units
                            </p>
                        </div>
                    </div>
                </div>

                {/* Action bar */}
                {isPending && (
                    <div className="px-5 py-3 bg-amber-50 border-b border-amber-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-2 sm:flex-wrap">
                        <span className="text-xs text-amber-700 font-semibold flex-1">This adjustment is awaiting your approval</span>
                        <div className="flex gap-2">
                        <button onClick={() => {
                            const notes = window.prompt("Approval notes (optional):") ?? "";
                            approveMutation.mutate(notes);
                        }} disabled={approveMutation.isPending}
                            className="btn-sm bg-emerald-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700">
                            ✓ Approve
                        </button>
                        <button onClick={() => {
                            const reason = window.prompt("Reason for rejection:");
                            if (reason) rejectMutation.mutate(reason);
                        }} disabled={rejectMutation.isPending}
                            className="btn-sm bg-white text-danger border border-danger/30 rounded-xl px-3 py-1.5 text-xs font-semibold">
                            ✕ Reject
                        </button>
                        </div>
                    </div>
                )}
                {canReverse && (
                    <div className="px-5 py-3 bg-slate-50 border-b border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-2">
                        <button onClick={() => {
                            const notes = window.prompt("Reason for reversal (required):");
                            if (notes) reverseMutation.mutate(notes);
                        }} disabled={reverseMutation.isPending}
                            className="btn-sm bg-white border border-amber-300 text-amber-700 rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-amber-50 self-start">
                            ↩ Reverse Adjustment
                        </button>
                        <span className="text-2xs text-surface-400">Creates a compensating transaction to undo this adjustment</span>
                    </div>
                )}

                {/* PDF action bar — always visible */}
                <div className="px-5 py-3 bg-white border-b border-surface-100 flex items-center gap-2 sm:px-8">
                    <PdfDownloadButton type="stock-adjustments" id={adj.id} label="Download PDF" />
                </div>

                {/* Body */}
                <div className="px-5 py-5 grid grid-cols-1 lg:grid-cols-[1fr_240px] gap-6 lg:gap-8 sm:px-8 sm:py-6 lg:divide-x divide-surface-100">

                    {/* Left */}
                    <div className="space-y-6 lg:pr-8">

                        {/* Tabs */}
                        <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar">
                            {(["details", "audit"] as const).map((t) => (
                                <button key={t} onClick={() => setTab(t)}
                                    className={clsx("px-4 py-2.5 text-xs font-semibold border-b-2 transition-all",
                                        tab === t ? "border-brand-500 text-brand-600" : "border-transparent text-surface-400 hover:text-surface-700")}>
                                    {t === "details" ? "📋 Details" : "🕐 Audit Trail"}
                                </button>
                            ))}
                        </div>

                        {tab === "details" && (
                            <div className="space-y-4">
                                {/* Quantity visualization */}
                                <div className="grid grid-cols-3 gap-3">
                                    {[
                                        { label: "Before", value: adj.quantity_before, color: "bg-surface-100 text-surface-700" },
                                        { label: "Change", value: (isPositive ? "+" : "") + delta, color: isPositive ? "bg-emerald-50 text-emerald-800" : "bg-red-50 text-red-800" },
                                        { label: "After",  value: adj.quantity_after,  color: isPositive ? "bg-emerald-100 text-emerald-900 font-bold" : "bg-red-100 text-red-900 font-bold" },
                                    ].map((cell) => (
                                        <div key={cell.label} className={clsx("rounded-xl p-4 text-center", cell.color)}>
                                            <p className="text-2xs uppercase tracking-widest opacity-60 mb-1">{cell.label}</p>
                                            <p className="text-2xl font-bold tabular-nums">{cell.value}</p>
                                            <p className="text-2xs opacity-60 mt-0.5">units</p>
                                        </div>
                                    ))}
                                </div>

                                {/* Notes */}
                                {adj.notes && (
                                    <div className="p-4 bg-surface-50 rounded-xl">
                                        <SectionLabel>Notes</SectionLabel>
                                        <p className="text-xs text-surface-700 whitespace-pre-wrap">{adj.notes}</p>
                                    </div>
                                )}
                                {adj.approval_notes && (
                                    <div className={clsx("p-4 rounded-xl", adj.status === "rejected" ? "bg-red-50" : "bg-emerald-50")}>
                                        <SectionLabel>Approval Notes</SectionLabel>
                                        <p className="text-xs text-surface-700 whitespace-pre-wrap">{adj.approval_notes}</p>
                                    </div>
                                )}
                                {adj.reference_number && (
                                    <div className="p-3 bg-surface-50 rounded-xl">
                                        <SectionLabel>Reference Number</SectionLabel>
                                        <p className="text-sm font-mono text-surface-800">{adj.reference_number}</p>
                                    </div>
                                )}
                            </div>
                        )}

                        {tab === "audit" && <AuditTrail adjId={adj.id} />}
                    </div>

                    {/* Right sidebar */}
                    <div className="lg:pl-8 space-y-6">
                        <div>
                            <SectionLabel>Product</SectionLabel>
                            <div className="p-3 bg-surface-50 rounded-xl">
                                {item?.product?.image_url && (
                                    <img src={item.product.image_url} alt="" className="w-full h-24 object-cover rounded-lg mb-2" />
                                )}
                                <p className="text-sm font-semibold text-surface-800">{item?.product?.name ?? "-"}</p>
                                {item?.product?.sku && <p className="text-xs text-surface-500 font-mono mt-0.5">SKU: {item.product.sku}</p>}
                                {item?.outlet && <p className="text-xs text-surface-500 mt-1">📍 {item.outlet.name}</p>}
                                <div className="mt-2 pt-2 border-t border-surface-200">
                                    <p className="text-2xs text-surface-400">Current stock</p>
                                    <p className="text-lg font-bold text-surface-800 tabular-nums">{item?.quantity_on_hand ?? "-"} <span className="text-xs font-normal text-surface-500">units</span></p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <SectionLabel>Record Info</SectionLabel>
                            <InfoRow label="Created" value={fmtDateTime(adj.created_at)} />
                            <InfoRow label="By" value={adj.created_by?.name} />
                            {adj.approved_at && <InfoRow label="Approved" value={fmtDateTime(adj.approved_at)} />}
                            {adj.approved_by && <InfoRow label="By" value={adj.approved_by.name} />}
                            <InfoRow label="Reason" value={adj.reason_label} />
                            <InfoRow label="Status" value={
                                <span className={clsx("px-2 py-0.5 rounded-full text-2xs font-semibold", statusCfg.bg, statusCfg.color)}>
                                    {statusCfg.label}
                                </span>
                            } />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}