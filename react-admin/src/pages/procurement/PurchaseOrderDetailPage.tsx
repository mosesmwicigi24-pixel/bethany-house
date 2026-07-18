import { useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post } from "@/api/client";
import { purchaseOrderApi, type PurchaseOrder, type POStatus } from "@/api/procurement";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";

// ── Status config ─────────────────────────────────────────────────────────────

const STATUS_CFG: Record<POStatus, { label: string; color: string; bg: string }> = {
    draft:               { label: "Draft",              color: "text-surface-600",  bg: "bg-surface-100" },
    pending_approval:    { label: "Pending Approval",   color: "text-amber-700",    bg: "bg-amber-50" },
    approved:            { label: "Approved",           color: "text-blue-700",     bg: "bg-blue-50" },
    ordered:             { label: "Ordered",            color: "text-indigo-700",   bg: "bg-indigo-50" },
    partially_received:  { label: "Partial Receipt",    color: "text-orange-700",   bg: "bg-orange-50" },
    received:            { label: "Fully Received",     color: "text-emerald-700",  bg: "bg-emerald-50" },
    cancelled:           { label: "Cancelled",          color: "text-red-700",      bg: "bg-red-50" },
};

const fmt = (n: number | string | null | undefined, currency = "KES") => {
    const v = Number(n ?? 0);
    return `${currency} ${v.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};
const fmtDate = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleDateString("en-KE", { day: "2-digit", month: "short", year: "numeric" }) : "-";
const fmtDateTime = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleString("en-KE", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "-";

// ── Audit trail log entry type ────────────────────────────────────────────────

interface AuditEntry {
    id: number;
    event: string;
    label: string;
    description: string;
    properties: Record<string, any>;
    actor_name: string;
    actor_email: string;
    ip_address: string;
    created_at: string;
}

// ── Tiny reusable components ──────────────────────────────────────────────────

function SectionLabel({ children }: { children: React.ReactNode }) {
    return (
        <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">
            {children}
        </p>
    );
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-2 py-1.5 border-b border-surface-50 last:border-0">
            <span className="text-xs text-surface-400 shrink-0">{label}</span>
            <span className="text-xs text-surface-800 font-medium text-right">{value ?? "-"}</span>
        </div>
    );
}

function StatusBadge({ status }: { status: POStatus }) {
    const cfg = STATUS_CFG[status] ?? STATUS_CFG.draft;
    return (
        <span className={clsx("inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold", cfg.bg, cfg.color)}>
            {cfg.label}
        </span>
    );
}

// ── Audit Trail Tab ───────────────────────────────────────────────────────────

function AuditTrail({ poId }: { poId: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ["po-audit", poId],
        queryFn: () => get<{ logs: AuditEntry[] }>(`/v1/admin/purchase-orders/${poId}/audit-log`),
        staleTime: 30_000,
    });
    const logs = data?.logs ?? [];

    if (isLoading) return <div className="flex justify-center py-12"><Spinner /></div>;
    if (!logs.length) return (
        <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-2">
            <svg className="w-8 h-8 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                    d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" />
            </svg>
            <p className="text-xs">No audit entries yet</p>
        </div>
    );

    return (
        <div className="divide-y divide-surface-50">
            {logs.map((entry) => (
                <div key={entry.id} className="flex gap-3 py-3.5 px-1">
                    <div className="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center shrink-0 mt-0.5">
                        <svg className="w-3.5 h-3.5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-2">
                            <div>
                                <span className="text-xs font-semibold text-surface-800">{entry.label}</span>
                                <span className="ml-2 text-xs text-surface-500">{entry.actor_name}</span>
                            </div>
                            <span className="text-2xs text-surface-400 shrink-0 tabular-nums">
                                {fmtDateTime(entry.created_at)}
                            </span>
                        </div>
                        <p className="text-xs text-surface-600 mt-0.5">{entry.description}</p>
                        {Object.keys(entry.properties ?? {}).length > 0 && (
                            <details className="mt-1">
                                <summary className="text-2xs text-brand-500 cursor-pointer hover:text-brand-700">
                                    View details
                                </summary>
                                <pre className="mt-1 text-2xs bg-surface-50 rounded-lg p-2 overflow-x-auto text-surface-600 font-mono">
                                    {JSON.stringify(entry.properties, null, 2)}
                                </pre>
                            </details>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Receive Goods Modal ───────────────────────────────────────────────────────

function ReceiveModal({ po, onClose, onDone }: {
    po: PurchaseOrder;
    onClose: () => void;
    onDone: () => void;
}) {
    const toast = useToastStore();
    const [notes, setNotes] = useState("");
    const [locationType, setLocationType] = useState<"warehouse" | "outlet">("warehouse");
    const [outletId, setOutletId] = useState<number | null>(null);
    const [items, setItems] = useState<Array<{ po_item_id: number; quantity_received: number; quality_status: "passed" | "rejected"; notes: string }>>(
        (po.items ?? []).map((i: any) => ({
            po_item_id: i.id,
            quantity_received: Math.max(0, (i.quantity - i.quantity_received)),
            quality_status: "passed" as const,
            notes: "",
        }))
    );

    const { data: outletsData } = useQuery({
        queryKey: ["outlets-list"],
        queryFn: () => get<{ data: Array<{ id: number; name: string }> }>("/v1/admin/outlets"),
        staleTime: 60_000,
    });
    const outlets = outletsData?.data ?? [];

    const outletRequired = locationType === "outlet" && !outletId;

    const mutation = useMutation({
        mutationFn: () => purchaseOrderApi.receive(po.id, {
            items,
            location_type: locationType,
            outlet_id: locationType === "outlet" ? outletId! : undefined,
            notes: notes || undefined,
        }),
        onSuccess: () => { toast.success("Goods received successfully"); onDone(); onClose(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed to receive goods"),
    });


    return (
        <Modal open title={`Receive Goods - ${po.po_number}`} onClose={onClose} size="lg">
            <div className="p-5 space-y-4">
                <div className="flex gap-2">
                    {(["warehouse", "outlet"] as const).map((t) => (
                        <button key={t} onClick={() => { setLocationType(t); setOutletId(null); }}
                            className={clsx("flex-1 py-2 rounded-xl text-xs font-semibold border transition-all",
                                locationType === t ? "bg-brand-600 text-white border-brand-600" : "bg-white text-surface-600 border-surface-200 hover:border-brand-300")}>
                            {t === "warehouse" ? "Main Warehouse" : "Outlet"}
                        </button>
                    ))}
                </div>

                {locationType === "outlet" && (
                    <div>
                        <label className="label text-xs">
                            Destination Outlet <span className="text-danger">*</span>
                        </label>
                        <select
                            className="input"
                            value={outletId ?? ""}
                            onChange={e => setOutletId(e.target.value ? Number(e.target.value) : null)}
                        >
                            <option value="">- Select outlet -</option>
                            {outlets.map(o => (
                                <option key={o.id} value={o.id}>{o.name}</option>
                            ))}
                        </select>
                    </div>
                )}
                <div className="overflow-x-auto rounded-xl border border-surface-200">
                    <table className="w-full text-xs">
                        <thead>
                            <tr className="bg-surface-50 border-b border-surface-200">
                                <th className="text-left px-3 py-2 font-semibold text-surface-600">Item</th>
                                <th className="text-center px-3 py-2 font-semibold text-surface-600">Ordered</th>
                                <th className="text-center px-3 py-2 font-semibold text-surface-600">Previously Received</th>
                                <th className="text-center px-3 py-2 font-semibold text-surface-600">Receiving Now</th>
                                <th className="text-center px-3 py-2 font-semibold text-surface-600">Quality</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-100">
                            {(po.items ?? []).map((poItem: any, idx: number) => {
                                const remaining = poItem.quantity - poItem.quantity_received;
                                const entry = items[idx];
                                if (!entry) return null;
                                return (
                                    <tr key={poItem.id}>
                                        <td className="px-3 py-2 font-medium text-surface-800">{poItem.description}</td>
                                        <td className="px-3 py-2 text-center text-surface-600">{poItem.quantity}</td>
                                        <td className="px-3 py-2 text-center text-surface-600">{poItem.quantity_received}</td>
                                        <td className="px-3 py-2">
                                            <input
                                                type="number" min={0} max={remaining} step={0.001}
                                                value={entry.quantity_received}
                                                onChange={(e) => setItems(prev => prev.map((it, i) => i === idx ? { ...it, quantity_received: parseFloat(e.target.value) || 0 } : it))}
                                                className="input text-center w-20 py-1 text-xs"
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <select value={entry.quality_status}
                                                onChange={(e) => setItems(prev => prev.map((it, i) => i === idx ? { ...it, quality_status: e.target.value as any } : it))}
                                                className="input py-1 text-xs">
                                                <option value="passed">✅ Passed</option>
                                                <option value="rejected">❌ Rejected</option>
                                            </select>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div>
                    <label className="label text-xs">Receipt Notes</label>
                    <textarea className="input" rows={2} value={notes} onChange={e => setNotes(e.target.value)} placeholder="Any notes about this receipt…" />
                </div>

                {outletRequired && (
                    <p className="text-xs text-danger font-medium">
                        Please select a destination outlet before confirming.
                    </p>
                )}

                <div className="flex flex-col gap-3 sm:flex-row">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || outletRequired}
                        className={clsx(
                            "btn-primary flex-1",
                            outletRequired && "opacity-50 cursor-not-allowed"
                        )}
                    >
                        {mutation.isPending ? "Saving…" : "Confirm Receipt"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function PurchaseOrderDetailPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const [tab, setTab] = useState<"details" | "grn" | "audit">("details");
    const [showReceive, setShowReceive] = useState(false);

    const { data, isLoading, error } = useQuery({
        queryKey: ["purchase-order", id],
        queryFn: () => purchaseOrderApi.get(Number(id)),
        enabled: !!id,
        staleTime: 30_000,
    });

    const po = data?.purchase_order;
    const grnHistory = data?.receiving_history ?? [];

    const refresh = () => qc.invalidateQueries({ queryKey: ["purchase-order", id] });

    const actionMutation = useMutation({
        mutationFn: ({ action, notes }: { action: string; notes?: string }) =>
            post<any>(`/v1/admin/purchase-orders/${id}/${action}`, notes ? { notes, reason: notes } : {}),
        onSuccess: (_, { action }) => {
            const labels: Record<string, string> = {
                submit:  "PO submitted for approval",
                approve: "PO approved",
                reject:  "PO rejected and returned to draft",
                cancel:  "PO cancelled",
            };
            toast.success(labels[action] ?? "Action completed");
            refresh();
        },
        onError: (e: any) => toast.error(e?.message ?? "Action failed"),
    });

    const markOrderedMutation = useMutation({
        mutationFn: () => post<any>(`/v1/admin/purchase-orders/${id}/status`, { status: "ordered" }),
        onSuccess: () => { toast.success("PO marked as ordered - sent to supplier"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed to update status"),
    });

    // usePermissions reads the auth store — a React hook. It used to be
    // called AFTER the early returns below, so the loading render recorded
    // fewer hooks than the data render and React tore the whole tree down
    // ("Rendered more hooks than during the previous render") — a blank
    // white page on every direct/deep-link load of this page.
    const { can } = usePermissions();

    if (isLoading) return (
        <div className="flex items-center justify-center h-64"><Spinner /></div>
    );
    if (!po) return (
        <div className="text-center py-16 text-surface-400">
            <p className="text-sm">Purchase order not found.</p>
            <button onClick={() => navigate("/procurement/purchase-orders")} className="btn-secondary mt-4">Back to list</button>
        </div>
    );

    const currency = po.currency_code ?? "KES";
    const canCreatePO = can("procurement.create");
    const canApprovePO = can("procurement.approve");
    const canReceivePO = can("procurement.receive");
    const canReceive     = ["approved", "ordered", "partially_received"].includes(po.status) && canReceivePO;
    const canApprove     = po.status === "pending_approval" && canApprovePO;
    const canReject      = po.status === "pending_approval" && canApprovePO;
    const canMarkOrdered = po.status === "approved" && canApprovePO;
    const canSubmit      = po.status === "draft" && canCreatePO;
    const canCancel      = !["received", "cancelled"].includes(po.status) && canCreatePO;

    return (
        <div className="max-w-6xl mx-auto">
            {/* Back */}
            <button onClick={() => navigate("/procurement/purchase-orders")}
                className="flex items-center gap-1.5 text-xs text-surface-500 hover:text-surface-800 mb-4 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Purchase Orders
            </button>

            {/* Document card */}
            <div className="bg-white rounded-2xl shadow-sm border border-surface-200 overflow-hidden">

                {/* ── Header band ── */}
                <div className="bg-gradient-to-r from-slate-800 to-slate-700 px-5 py-5 sm:px-8 sm:py-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
                        <div>
                            <p className="text-slate-400 text-xs font-semibold uppercase tracking-widest mb-1">Purchase Order</p>
                            <h1 className="text-2xl font-bold text-white font-mono">{po.po_number}</h1>
                            <div className="flex items-center gap-2 mt-2 flex-wrap">
                                <StatusBadge status={po.status} />
                                {po.invoice_number && (
                                    <span className="text-xs bg-white/10 text-slate-300 px-2 py-0.5 rounded-full">
                                        Invoice: {po.invoice_number}
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="sm:text-right">
                            <p className="text-slate-400 text-2xs uppercase tracking-widest mb-1">Order Value</p>
                            <p className="text-3xl font-bold text-white tabular-nums">
                                {fmt(po.total_amount, currency)}
                            </p>
                            <p className="text-slate-400 text-xs mt-1">
                                Order date: {fmtDate(po.order_date)}
                            </p>
                            <p className="text-slate-400 text-xs">
                                Expected: {fmtDate(po.expected_delivery_date)}
                            </p>
                        </div>
                    </div>
                </div>

                {/* ── Action bar ── */}
                <div className="px-5 py-3 bg-slate-50 border-b border-surface-100 flex flex-wrap items-center gap-2 sm:px-8">
                    {canSubmit && (
                        <button onClick={() => actionMutation.mutate({ action: "submit" })}
                            disabled={actionMutation.isPending}
                            className="btn-primary btn-sm">
                            Submit for Approval
                        </button>
                    )}
                    {canApprove && (
                        <button onClick={() => actionMutation.mutate({ action: "approve" })}
                            disabled={actionMutation.isPending}
                            className="btn-sm bg-emerald-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700 transition-colors">
                            ✓ Approve PO
                        </button>
                    )}
                    {canReject && (
                        <button onClick={() => {
                            const reason = window.prompt("Reason for rejection:");
                            if (reason) actionMutation.mutate({ action: "reject", notes: reason });
                        }}
                            disabled={actionMutation.isPending}
                            className="btn-sm bg-white text-amber-700 border border-amber-300 rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-amber-50 transition-colors">
                            ✕ Reject
                        </button>
                    )}
                    {canMarkOrdered && (
                        <button onClick={() => markOrderedMutation.mutate()}
                            disabled={markOrderedMutation.isPending}
                            className="btn-sm bg-blue-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-blue-700 transition-colors">
                            📤 Mark as Ordered
                        </button>
                    )}
                    {canReceive && (
                        <button onClick={() => setShowReceive(true)}
                            className="btn-sm bg-brand-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-brand-700 transition-colors">
                            📦 Receive Goods
                        </button>
                    )}
                    <PdfDownloadButton type="purchase-orders" id={po.id} label="Download PDF" />
                    {canCancel && (
                        <button onClick={() => {
                            const reason = window.prompt("Reason for cancellation:");
                            if (reason) actionMutation.mutate({ action: "cancel", notes: reason });
                        }}
                            disabled={actionMutation.isPending}
                            className="btn-sm bg-white text-danger border border-danger/30 rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-danger-light transition-colors ml-auto">
                            Cancel PO
                        </button>
                    )}
                </div>

                {/* ── Body: two-column ── */}
                <div className="px-5 py-5 grid grid-cols-1 lg:grid-cols-[1fr_260px] gap-6 lg:gap-8 sm:px-8 sm:py-6 lg:divide-x divide-surface-100">

                    {/* LEFT */}
                    <div className="space-y-8 lg:pr-8">

                        {/* Tabs */}
                        <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar">
                            {(["details", "grn", "audit"] as const).map((t) => (
                                <button key={t} onClick={() => setTab(t)}
                                    className={clsx("px-4 py-2.5 text-xs font-semibold border-b-2 transition-all whitespace-nowrap shrink-0",
                                        tab === t ? "border-brand-500 text-brand-600" : "border-transparent text-surface-400 hover:text-surface-700")}>
                                    {t === "details" ? "📋 Items" : t === "grn" ? `📦 Receipts (${grnHistory.length})` : "🕐 Audit Trail"}
                                </button>
                            ))}
                        </div>

                        {/* Items tab */}
                        {tab === "details" && (
                            <div>
                                <div className="overflow-x-auto rounded-xl border border-surface-200">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="bg-surface-50 border-b border-surface-200">
                                                <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Item</th>
                                                <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Type</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Ordered</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Received</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Outstanding</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Unit Cost</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Line Total</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-surface-100">
                                            {(po.items ?? []).map((item: any) => {
                                                const outstanding = item.quantity - item.quantity_received;
                                                const fullyReceived = outstanding <= 0;
                                                return (
                                                    <tr key={item.id} className={clsx(fullyReceived && "opacity-60")}>
                                                        <td className="px-3 py-2.5">
                                                            <p className="font-medium text-surface-800">{item.description}</p>
                                                            {item.product?.sku && <p className="text-2xs text-surface-400 mt-0.5">SKU: {item.product.sku}</p>}
                                                        </td>
                                                        <td className="px-3 py-2.5">
                                                            <span className={clsx("px-2 py-0.5 rounded-full text-2xs font-semibold",
                                                                item.item_type === "product" ? "bg-blue-50 text-blue-700" : "bg-purple-50 text-purple-700")}>
                                                                {item.item_type}
                                                            </span>
                                                        </td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums">{item.quantity}</td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums text-emerald-700">{item.quantity_received}</td>
                                                        <td className={clsx("px-3 py-2.5 text-right tabular-nums font-semibold", outstanding > 0 ? "text-amber-700" : "text-surface-400")}>
                                                            {outstanding > 0 ? outstanding : "✓"}
                                                        </td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums text-surface-600">{fmt(item.unit_price, currency)}</td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums font-semibold text-surface-800">{fmt(item.total_price, currency)}</td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                        <tfoot className="border-t-2 border-surface-200 bg-surface-50">
                                            <tr>
                                                <td colSpan={5} className="px-3 py-2 text-xs text-surface-500">Subtotal</td>
                                                <td colSpan={2} className="px-3 py-2 text-right text-xs font-semibold tabular-nums">{fmt(po.subtotal, currency)}</td>
                                            </tr>
                                            {Number(po.shipping_amount) > 0 && (
                                                <tr>
                                                    <td colSpan={5} className="px-3 py-1.5 text-xs text-surface-500">Shipping</td>
                                                    <td colSpan={2} className="px-3 py-1.5 text-right text-xs tabular-nums">{fmt(po.shipping_amount, currency)}</td>
                                                </tr>
                                            )}
                                            {Number(po.tax_amount) > 0 && (
                                                <tr>
                                                    <td colSpan={5} className="px-3 py-1.5 text-xs text-surface-500">Tax</td>
                                                    <td colSpan={2} className="px-3 py-1.5 text-right text-xs tabular-nums">{fmt(po.tax_amount, currency)}</td>
                                                </tr>
                                            )}
                                            <tr className="border-t border-surface-200">
                                                <td colSpan={5} className="px-3 py-2.5 text-sm font-bold text-surface-800">Total</td>
                                                <td colSpan={2} className="px-3 py-2.5 text-right text-base font-bold tabular-nums text-surface-900">{fmt(po.total_amount, currency)}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                {po.notes && (
                                    <div className="mt-4 p-4 bg-amber-50 rounded-xl border border-amber-100">
                                        <p className="text-2xs font-bold text-amber-700 uppercase tracking-widest mb-1">Notes</p>
                                        <p className="text-xs text-amber-900 whitespace-pre-wrap">{po.notes}</p>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* GRN History tab */}
                        {tab === "grn" && (
                            <div className="space-y-3">
                                {grnHistory.length === 0 ? (
                                    <div className="text-center py-12 text-surface-400 text-xs">No goods received yet.</div>
                                ) : (
                                    grnHistory.map((grn: any) => (
                                        <div key={grn.id} className="border border-surface-200 rounded-xl overflow-hidden">
                                            <div className="bg-surface-50 px-4 py-2.5 flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xs font-bold text-surface-800 font-mono">{grn.grn_number}</span>
                                                    <span className="text-2xs text-surface-500">Received {fmtDate(grn.received_date)}</span>
                                                </div>
                                                <span className="text-2xs text-surface-500">
                                                    by {grn.received_by?.first_name} {grn.received_by?.last_name}
                                                </span>
                                            </div>
                                            {grn.notes && (
                                                <div className="px-4 py-2 border-b border-surface-100 bg-white">
                                                    <p className="text-xs text-surface-600 italic">{grn.notes}</p>
                                                </div>
                                            )}
                                        </div>
                                    ))
                                )}
                            </div>
                        )}

                        {/* Audit Trail tab */}
                        {tab === "audit" && <AuditTrail poId={po.id} />}
                    </div>

                    {/* RIGHT - sidebar */}
                    <div className="lg:pl-8 space-y-6">

                        {/* Supplier */}
                        <div>
                            <SectionLabel>Supplier</SectionLabel>
                            <div className="p-3 bg-surface-50 rounded-xl">
                                <p className="text-sm font-semibold text-surface-800">{po.supplier?.name ?? "-"}</p>
                                {po.supplier?.company_code && <p className="text-xs text-surface-500 mt-0.5">{po.supplier.company_code}</p>}
                                {po.supplier?.email && <p className="text-xs text-surface-500">{po.supplier.email}</p>}
                                {po.supplier?.phone && <p className="text-xs text-surface-500">{po.supplier.phone}</p>}
                                {po.supplier?.city && <p className="text-xs text-surface-500">{po.supplier.city}</p>}
                            </div>
                        </div>

                        {/* Quick stats */}
                        <div>
                            <SectionLabel>Summary</SectionLabel>
                            <div className="space-y-0">
                                <InfoRow label="Items" value={(po.items ?? []).length} />
                                <InfoRow label="Total Ordered" value={(po.items ?? []).reduce((s: number, i: any) => s + i.quantity, 0)} />
                                <InfoRow label="Total Received" value={(po.items ?? []).reduce((s: number, i: any) => s + i.quantity_received, 0)} />
                                <InfoRow label="Currency" value={currency} />
                                <InfoRow label="Payment Terms" value={po.payment_terms} />
                                <InfoRow label="Payment Status" value={
                                    <span className={clsx("capitalize", po.payment_status === "paid" ? "text-emerald-600 font-semibold" : "text-amber-600")}>
                                        {po.payment_status ?? "unpaid"}
                                    </span>
                                } />
                            </div>
                        </div>

                        {/* Timeline */}
                        <div>
                            <SectionLabel>Timeline</SectionLabel>
                            <div className="space-y-0">
                                <InfoRow label="Created" value={fmtDateTime(po.created_at)} />
                                {po.created_by && <InfoRow label="Created By" value={`${po.created_by.first_name ?? ""} ${po.created_by.last_name ?? ""}`.trim()} />}
                                {po.approved_at && <InfoRow label="Approved" value={fmtDateTime(po.approved_at)} />}
                                {po.approved_by && <InfoRow label="Approved By" value={`${po.approved_by.first_name ?? ""} ${po.approved_by.last_name ?? ""}`.trim()} />}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Receive Modal */}
            {showReceive && (
                <ReceiveModal po={po} onClose={() => setShowReceive(false)} onDone={refresh} />
            )}
        </div>
    );
}