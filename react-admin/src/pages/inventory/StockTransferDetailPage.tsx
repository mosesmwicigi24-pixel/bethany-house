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

interface TransferDetail {
    id: number;
    transfer_number: string;
    status: string;
    notes: string | null;
    requested_at: string;
    approved_at: string | null;
    completed_at: string | null;
    created_at: string;
    from_outlet: { id: number; name: string } | null;
    to_outlet: { id: number; name: string } | null;
    requested_by: { id: number; name: string } | null;
    approved_by: { id: number; name: string } | null;
    completed_by: { id: number; name: string } | null;
    items: Array<{
        id: number;
        product_id: number;
        product_variant_id: number | null;
        quantity_requested: number;
        quantity_received: number;
        source_stock: number | null;
        product: { id: number; sku: string; name: string; image_url?: string } | null;
        variant: { id: number; sku: string; variant_name: string } | null;
    }>;
}

interface AuditEntry {
    id: number; event: string; label: string; description: string;
    properties: Record<string, any>; actor_name: string; created_at: string;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtDateTime = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleString("en-KE", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "-";

const STATUS_CFG: Record<string, { label: string; color: string; bg: string; dot: string }> = {
    pending:    { label: "Pending",    color: "text-amber-700",   bg: "bg-amber-50",   dot: "bg-amber-500" },
    approved:   { label: "Approved",   color: "text-blue-700",    bg: "bg-blue-50",    dot: "bg-blue-500" },
    in_transit: { label: "In Transit", color: "text-indigo-700",  bg: "bg-indigo-50",  dot: "bg-indigo-500" },
    completed:  { label: "Completed",  color: "text-emerald-700", bg: "bg-emerald-50", dot: "bg-emerald-500" },
    cancelled:  { label: "Cancelled",  color: "text-red-700",     bg: "bg-red-50",     dot: "bg-red-500" },
};

const TIMELINE_STEPS = ["pending", "approved", "in_transit", "completed"];

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

// ── Status Timeline ───────────────────────────────────────────────────────────

function StatusTimeline({ status }: { status: string }) {
    const currentIdx = TIMELINE_STEPS.indexOf(status);
    const isCancelled = status === "cancelled";

    return (
        <div className="flex items-center gap-0">
            {TIMELINE_STEPS.map((step, idx) => {
                const passed  = !isCancelled && idx <= currentIdx;
                const current = !isCancelled && idx === currentIdx;
                return (
                    <div key={step} className="flex items-center flex-1 last:flex-none">
                        <div className={clsx("w-6 h-6 rounded-full flex items-center justify-center shrink-0 border-2 transition-all",
                            current  ? "border-brand-500 bg-brand-500" :
                            passed   ? "border-emerald-500 bg-emerald-500" :
                            isCancelled ? "border-red-300 bg-red-100" :
                            "border-surface-200 bg-white")}>
                            {passed && !current ? (
                                <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            ) : current ? (
                                <div className="w-2 h-2 rounded-full bg-white" />
                            ) : null}
                        </div>
                        <div className={clsx("flex-1 h-0.5 last:hidden", passed ? "bg-emerald-400" : "bg-surface-200")} />
                    </div>
                );
            })}
        </div>
    );
}

// ── Audit Trail ───────────────────────────────────────────────────────────────

function AuditTrail({ transferId }: { transferId: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ["transfer-audit", transferId],
        queryFn: () => get<{ logs: AuditEntry[] }>(`/v1/admin/inventory/transfers/${transferId}/audit-log`),
        staleTime: 30_000,
    });
    const logs = data?.logs ?? [];
    if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
    if (!logs.length) return <div className="text-center py-12 text-xs text-surface-400">No audit entries yet.</div>;
    return (
        <div className="divide-y divide-surface-50">
            {logs.map((entry) => (
                <div key={entry.id} className="flex gap-3 py-3.5">
                    <div className="w-7 h-7 rounded-full bg-brand-100 flex items-center justify-center shrink-0 mt-0.5">
                        <svg className="w-3 h-3 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
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

export default function StockTransferDetailPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const [tab, setTab] = useState<"items" | "audit">("items");

    const { data, isLoading } = useQuery({
        queryKey: ["transfer", id],
        queryFn: () => get<{ transfer: TransferDetail }>(`/v1/admin/inventory/transfers/${id}`),
        enabled: !!id,
        staleTime: 30_000,
    });
    const transfer = data?.transfer;
    const refresh = () => qc.invalidateQueries({ queryKey: ["transfer", id] });

    const actionMutation = useMutation({
        mutationFn: ({ action, reason }: { action: string; reason?: string }) =>
            put<any>(`/v1/admin/inventory/transfers/${id}/${action}`, reason ? { reason } : {}),
        onSuccess: (_, { action }) => {
            toast.success(action === "dispatch" ? "Transfer dispatched" : action === "receive" ? "Transfer received" : "Transfer updated");
            refresh();
        },
        onError: (e: any) => toast.error(e?.message ?? "Action failed"),
    });

    // usePermissions reads the auth store — a React hook. It used to be
    // called AFTER the early returns below, so the loading render recorded
    // fewer hooks than the data render and React tore the whole tree down
    // ("Rendered more hooks than during the previous render") — a blank
    // white page on every direct/deep-link load of this page.
    const { can } = usePermissions();

    if (isLoading) return <div className="flex items-center justify-center h-64"><Spinner /></div>;
    if (!transfer) return (
        <div className="text-center py-16 text-surface-400 text-sm">
            Transfer not found.
            <button onClick={() => navigate("/inventory/transfers")} className="block mt-3 btn-secondary mx-auto">Back</button>
        </div>
    );

    const cfg = STATUS_CFG[transfer.status] ?? STATUS_CFG.pending;
    const totalItems    = transfer.items.reduce((s, i) => s + i.quantity_requested, 0);
    const totalReceived = transfer.items.reduce((s, i) => s + i.quantity_received, 0);
    const canApproveTransfer = can("inventory.approve");
    const canDoTransfer = can("inventory.transfer");
    const canApprove  = transfer.status === "pending" && canApproveTransfer;
    const canDispatch = transfer.status === "approved" && canDoTransfer;
    const canReceive  = transfer.status === "in_transit" && canDoTransfer;
    const canCancel   = ["pending", "approved"].includes(transfer.status) && canDoTransfer;

    return (
        <div className="max-w-5xl mx-auto">
            <button onClick={() => navigate("/inventory/transfers")}
                className="flex items-center gap-1.5 text-xs text-surface-500 hover:text-surface-800 mb-4 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Stock Transfers
            </button>

            <div className="bg-white rounded-2xl shadow-sm border border-surface-200 overflow-hidden">

                {/* Header band */}
                <div className="bg-gradient-to-r from-indigo-800 to-indigo-700 px-5 py-5 sm:px-8 sm:py-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-4 sm:flex-wrap">
                        <div>
                            <p className="text-indigo-300 text-xs font-semibold uppercase tracking-widest mb-1">Stock Transfer</p>
                            <h1 className="text-xl font-bold text-white font-mono sm:text-2xl">{transfer.transfer_number}</h1>
                            <div className="flex items-center gap-3 mt-2 flex-wrap">
                                <span className={clsx("px-2.5 py-1 rounded-full text-xs font-semibold", cfg.bg, cfg.color)}>
                                    {cfg.label}
                                </span>
                                <div className="flex items-center gap-1.5 text-white/80 text-xs flex-wrap">
                                    <span className="font-semibold">{transfer.from_outlet?.name ?? "?"}</span>
                                    <svg className="w-3.5 h-3.5 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M17.25 8.25L21 12m0 0l-3.75 3.75M21 12H3" />
                                    </svg>
                                    <span className="font-semibold">{transfer.to_outlet?.name ?? "?"}</span>
                                </div>
                            </div>
                        </div>
                        <div className="sm:text-right">
                            <p className="text-indigo-300 text-2xs uppercase tracking-widest mb-1">Items</p>
                            <p className="text-3xl font-bold text-white tabular-nums">{transfer.items.length}</p>
                            <p className="text-indigo-300 text-xs mt-1">{totalItems} units total</p>
                        </div>
                    </div>
                </div>

                {/* Status timeline */}
                <div className="px-5 py-4 bg-indigo-50 border-b border-indigo-100 sm:px-8">
                    <StatusTimeline status={transfer.status} />
                    <div className="flex justify-between mt-1">
                        {TIMELINE_STEPS.map((s) => (
                            <span key={s} className="text-2xs text-indigo-500 capitalize">{s.replace("_", " ")}</span>
                        ))}
                    </div>
                </div>

                {/* Action bar */}
                {(canApprove || canDispatch || canReceive || canCancel) && (
                    <div className="px-5 py-3 bg-slate-50 border-b border-surface-100 flex flex-wrap items-center gap-2 sm:px-8">
                        {canApprove && (
                            <button onClick={() => actionMutation.mutate({ action: "approve" })}
                                disabled={actionMutation.isPending}
                                className="btn-sm bg-emerald-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700">
                                ✓ Approve Transfer
                            </button>
                        )}
                        {canDispatch && (
                            <button onClick={() => actionMutation.mutate({ action: "dispatch" })}
                                disabled={actionMutation.isPending}
                                className="btn-sm bg-indigo-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-indigo-700">
                                📤 Mark as Dispatched
                            </button>
                        )}
                        {canReceive && (
                            <button onClick={() => actionMutation.mutate({ action: "receive" })}
                                disabled={actionMutation.isPending}
                                className="btn-sm bg-brand-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-brand-700">
                                📦 Confirm Receipt
                            </button>
                        )}
                        {canCancel && (
                            <button onClick={() => {
                                const reason = window.prompt("Reason for cancellation:");
                                if (reason) actionMutation.mutate({ action: "cancel", reason });
                            }}
                                disabled={actionMutation.isPending}
                                className="btn-sm bg-white text-danger border border-danger/30 rounded-xl px-3 py-1.5 text-xs font-semibold sm:ml-auto">
                                Cancel Transfer
                            </button>
                        )}
                    </div>
                )}

                {/* PDF action bar — always visible */}
                <div className="px-5 py-3 bg-white border-b border-surface-100 flex items-center gap-2 sm:px-8">
                    <PdfDownloadButton type="stock-transfers" id={transfer.id} label="Download PDF" />
                </div>

                {/* Body */}
                <div className="px-5 py-5 grid grid-cols-1 lg:grid-cols-[1fr_240px] gap-6 lg:gap-8 sm:px-8 sm:py-6 lg:divide-x divide-surface-100">

                    {/* Left */}
                    <div className="space-y-4 lg:pr-8">
                        <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar">
                            {(["items", "audit"] as const).map((t) => (
                                <button key={t} onClick={() => setTab(t)}
                                    className={clsx("px-4 py-2.5 text-xs font-semibold border-b-2 transition-all",
                                        tab === t ? "border-brand-500 text-brand-600" : "border-transparent text-surface-400 hover:text-surface-700")}>
                                    {t === "items" ? `📦 Items (${transfer.items.length})` : "🕐 Audit Trail"}
                                </button>
                            ))}
                        </div>

                        {tab === "items" && (
                            <div className="overflow-x-auto rounded-xl border border-surface-200">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="bg-surface-50 border-b border-surface-200">
                                            <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Product</th>
                                            <th className="text-center px-3 py-2.5 font-semibold text-surface-600">Source Stock</th>
                                            <th className="text-center px-3 py-2.5 font-semibold text-surface-600">Requested</th>
                                            <th className="text-center px-3 py-2.5 font-semibold text-surface-600">Received</th>
                                            <th className="text-center px-3 py-2.5 font-semibold text-surface-600">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-100">
                                        {transfer.items.map((item) => {
                                            const discrepancy = item.quantity_received - item.quantity_requested;
                                            const isComplete = transfer.status === "completed";
                                            return (
                                                <tr key={item.id}>
                                                    <td className="px-3 py-2.5">
                                                        <p className="font-medium text-surface-800">{item.product?.name ?? "-"}</p>
                                                        <p className="text-2xs text-surface-400 font-mono">
                                                            {item.variant?.sku ?? item.product?.sku ?? ""}
                                                            {item.variant?.variant_name && ` · ${item.variant.variant_name}`}
                                                        </p>
                                                    </td>
                                                    <td className="px-3 py-2.5 text-center tabular-nums text-surface-600">
                                                        {item.source_stock ?? "-"}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-center tabular-nums font-semibold text-surface-800">
                                                        {item.quantity_requested}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-center tabular-nums">
                                                        {isComplete ? (
                                                            <span className={clsx("font-semibold", discrepancy === 0 ? "text-emerald-700" : "text-amber-700")}>
                                                                {item.quantity_received}
                                                            </span>
                                                        ) : "-"}
                                                    </td>
                                                    <td className="px-3 py-2.5 text-center">
                                                        {isComplete ? (
                                                            discrepancy === 0
                                                                ? <span className="text-emerald-700 font-semibold">✓ Full</span>
                                                                : <span className="text-amber-700 font-semibold">{discrepancy > 0 ? `+${discrepancy}` : discrepancy} discrepancy</span>
                                                        ) : (
                                                            <span className="text-surface-400">Pending</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                    <tfoot className="border-t-2 border-surface-200 bg-surface-50">
                                        <tr>
                                            <td className="px-3 py-2 text-xs font-semibold text-surface-700">{transfer.items.length} item(s)</td>
                                            <td />
                                            <td className="px-3 py-2 text-center text-xs font-bold tabular-nums">{totalItems}</td>
                                            <td className="px-3 py-2 text-center text-xs font-bold tabular-nums text-emerald-700">
                                                {transfer.status === "completed" ? totalReceived : "-"}
                                            </td>
                                            <td />
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}

                        {tab === "audit" && <AuditTrail transferId={transfer.id} />}

                        {transfer.notes && (
                            <div className="p-4 bg-surface-50 rounded-xl border border-surface-100">
                                <SectionLabel>Notes</SectionLabel>
                                <p className="text-xs text-surface-700 whitespace-pre-wrap">{transfer.notes}</p>
                            </div>
                        )}
                    </div>

                    {/* Right sidebar */}
                    <div className="lg:pl-8 space-y-6">
                        <div>
                            <SectionLabel>Route</SectionLabel>
                            <div className="space-y-2">
                                <div className="p-3 bg-surface-50 rounded-xl">
                                    <p className="text-2xs text-surface-400 uppercase tracking-widest mb-0.5">From</p>
                                    <p className="text-sm font-semibold text-surface-800">{transfer.from_outlet?.name ?? "-"}</p>
                                </div>
                                <div className="flex justify-center">
                                    <svg className="w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3" />
                                    </svg>
                                </div>
                                <div className="p-3 bg-indigo-50 rounded-xl border border-indigo-100">
                                    <p className="text-2xs text-indigo-500 uppercase tracking-widest mb-0.5">To</p>
                                    <p className="text-sm font-semibold text-indigo-800">{transfer.to_outlet?.name ?? "-"}</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <SectionLabel>Timeline</SectionLabel>
                            <InfoRow label="Initiated" value={fmtDateTime(transfer.requested_at ?? transfer.created_at)} />
                            <InfoRow label="By" value={transfer.requested_by?.name} />
                            {transfer.approved_at && <InfoRow label="Approved" value={fmtDateTime(transfer.approved_at)} />}
                            {transfer.approved_by && <InfoRow label="By" value={transfer.approved_by.name} />}
                            {transfer.completed_at && <InfoRow label="Completed" value={fmtDateTime(transfer.completed_at)} />}
                            {transfer.completed_by && <InfoRow label="By" value={transfer.completed_by.name} />}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}