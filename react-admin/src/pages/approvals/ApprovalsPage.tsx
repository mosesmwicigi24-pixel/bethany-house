import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, put, tokenStorage } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

// ─── Types ────────────────────────────────────────────────────────────────────

type ApprovalTab =
    | "purchase_orders"
    | "purchase_returns"
    | "stock_adjustments"
    | "stock_transfers"
    | "payment_approvals";

interface PendingPO {
    id: number;
    po_number: string;
    supplier: { id: number; name: string } | null;
    total_amount: number;
    currency_code: string;
    items_count?: number;
    submitted_at?: string;
    created_at: string;
    notes?: string | null;
    created_by?: { first_name: string; last_name: string } | null;
}

interface PendingReturn {
    id: number;
    return_number: string;
    purchase_order?: { po_number: string };
    supplier?: { name: string };
    reason?: string;
    notes?: string;
    created_at: string;
    items_count?: number;
    created_by_user?: { first_name: string; last_name: string };
}

interface PendingAdjustment {
    id: number;
    reference_number?: string;
    reason_code: string;
    reason_label: string;
    quantity_change: number;
    product_name?: string;
    variant_name?: string;
    outlet_name?: string;
    created_at: string;
    created_by?: { first_name: string; last_name: string };
    notes?: string;
}

interface PendingTransfer {
    id: number;
    transfer_number: string;
    from_outlet?: { name: string };
    to_outlet?: { name: string };
    status: string;
    created_at: string;
    total_items?: number;
    requested_by?: { first_name: string; last_name: string };
    notes?: string;
}

// ─── Action Modal ─────────────────────────────────────────────────────────────

function ActionModal({
    title,
    action,
    requireReason,
    reasonLabel,
    onConfirm,
    onClose,
    isPending,
}: {
    title: string;
    action: "approve" | "reject";
    requireReason: boolean;
    reasonLabel: string;
    onConfirm: (notes: string) => void;
    onClose: () => void;
    isPending: boolean;
}) {
    const [notes, setNotes] = useState("");
    const isApprove = action === "approve";

    return (
        <Modal open onClose={onClose} title={title} size="sm">
            <div className="p-5 space-y-4">
                {!isApprove && (
                    <div className="flex items-start gap-2 p-3 bg-danger-light rounded-xl text-xs text-danger-dark">
                        <svg className="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                        This action cannot be undone.
                    </div>
                )}
                <div>
                    <label className="label">
                        {reasonLabel}
                        {requireReason && <span className="text-danger ml-1">*</span>}
                        {!requireReason && <span className="text-surface-400 ml-1">(optional)</span>}
                    </label>
                    <textarea value={notes} onChange={e => setNotes(e.target.value)}
                        rows={3} className="input resize-none"
                        placeholder={isApprove ? "Any approval notes…" : "Reason for rejection…"} />
                </div>
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1" disabled={isPending}>Cancel</button>
                    <button
                        onClick={() => onConfirm(notes)}
                        disabled={isPending || (requireReason && !notes.trim())}
                        className={clsx("flex-1 btn gap-2", isApprove ? "btn-primary" : "btn-danger")}>
                        {isPending && <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />}
                        {isApprove ? "Approve" : "Reject"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ─── Stat card ────────────────────────────────────────────────────────────────

function PendingBadge({ count }: { count: number }) {
    if (count === 0) return null;
    return (
        <span className="ml-2 inline-flex items-center justify-center w-5 h-5 rounded-full bg-warning text-white text-2xs font-bold">
            {count > 9 ? "9+" : count}
        </span>
    );
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function WaitingAge({ since }: { since: string }) {
    const hours = Math.floor((Date.now() - new Date(since).getTime()) / 3_600_000);
    const days  = Math.floor(hours / 24);
    if (days >= 3) return (
        <span className="inline-flex items-center gap-1 text-2xs text-danger font-medium">
            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            {days}d waiting
        </span>
    );
    if (days >= 1) return <span className="text-2xs text-warning-dark font-medium">{days}d waiting</span>;
    if (hours >= 1) return <span className="text-2xs text-surface-400">{hours}h ago</span>;
    return <span className="text-2xs text-surface-400">Just submitted</span>;
}

// ─── Purchase Orders Panel ────────────────────────────────────────────────────

function PurchaseOrdersPanel() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const { canAny } = usePermissions();
    const canApprove = canAny("procurement.approve", "admin.all");

    const navigate = useNavigate();
    const [selected, setSelected]   = useState<PendingPO | null>(null);
    const [action,   setAction]     = useState<"approve" | "reject" | null>(null);
    const [bulkSelected, setBulkSelected] = useState<Set<number>>(new Set());

    const { data, isLoading } = useQuery({
        queryKey: ["approvals-pos"],
        queryFn:  () => get<{ data: PendingPO[] }>("/v1/admin/purchase-orders", {
            params: { status: "pending_approval", per_page: "50" },
        }),
        refetchInterval: 30_000,
        staleTime: 0,
    });
    const items = data?.data ?? [];

    const removeFromList = (id: number) =>
        qc.setQueryData(["approvals-pos"], (old: any) =>
            old ? { ...old, data: old.data.filter((p: PendingPO) => p.id !== id) } : old
        );

    const approveMutation = useMutation({
        mutationFn: ({ id, notes }: { id: number; notes: string }) =>
            post(`/v1/admin/purchase-orders/${id}/approve`, { notes }),
        onSuccess: (_, { id }) => {
            removeFromList(id);
            setBulkSelected(prev => { const n = new Set(prev); n.delete(id); return n; });
            toast.success("Purchase order approved");
            qc.invalidateQueries({ queryKey: ["approvals-pos"] });
            qc.invalidateQueries({ queryKey: ["approval-count-po"] });
            setAction(null); setSelected(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const rejectMutation = useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            post(`/v1/admin/purchase-orders/${id}/reject`, { reason }),
        onSuccess: (_, { id }) => {
            removeFromList(id);
            toast.success("Purchase order rejected");
            qc.invalidateQueries({ queryKey: ["approvals-pos"] });
            qc.invalidateQueries({ queryKey: ["approval-count-po"] });
            setAction(null); setSelected(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const bulkApproveMutation = useMutation({
        mutationFn: async (ids: number[]) => {
            await Promise.all(ids.map(id =>
                post(`/v1/admin/purchase-orders/${id}/approve`, { notes: "Bulk approved" })
            ));
        },
        onSuccess: () => {
            toast.success(`${bulkSelected.size} purchase order${bulkSelected.size !== 1 ? "s" : ""} approved`);
            setBulkSelected(new Set());
            qc.invalidateQueries({ queryKey: ["approvals-pos"] });
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const toggleBulk = (id: number) => setBulkSelected(prev => {
        const next = new Set(prev);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
    });
    const allSelected = items.length > 0 && items.every(i => bulkSelected.has(i.id));
    const toggleAll = () => setBulkSelected(allSelected ? new Set() : new Set(items.map(i => i.id)));

    if (isLoading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>;

    if (items.length === 0) return (
        <EmptyState label="No purchase orders awaiting approval" />
    );

    return (
        <>
            {/* Bulk action toolbar */}
            {canApprove && items.length > 1 && (
                <div className="px-5 py-2.5 border-b border-surface-100 flex items-center gap-3 bg-surface-50">
                    <label className="flex items-center gap-2 cursor-pointer text-xs text-surface-600 select-none">
                        <input type="checkbox" checked={allSelected} onChange={toggleAll}
                            className="w-3.5 h-3.5 rounded border-surface-300 cursor-pointer" />
                        Select all
                    </label>
                    {bulkSelected.size > 0 && (
                        <>
                            <span className="text-xs text-surface-500">{bulkSelected.size} selected</span>
                            <button
                                onClick={() => bulkApproveMutation.mutate(Array.from(bulkSelected))}
                                disabled={bulkApproveMutation.isPending}
                                className="btn-primary btn-sm ml-auto gap-1.5">
                                {bulkApproveMutation.isPending
                                    ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                    : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                }
                                Approve {bulkSelected.size}
                            </button>
                        </>
                    )}
                </div>
            )}
            <div className="divide-y divide-surface-50">
                {items.map(po => (
                    <div key={po.id} className={clsx("px-4 py-4 hover:bg-surface-50 transition-colors", bulkSelected.has(po.id) && "bg-brand-50/50")}>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                            <div className="flex items-start gap-3 flex-1 min-w-0">
                                {canApprove && items.length > 1 && (
                                    <input type="checkbox" checked={bulkSelected.has(po.id)}
                                        onChange={() => toggleBulk(po.id)}
                                        className="w-3.5 h-3.5 mt-1 rounded border-surface-300 cursor-pointer shrink-0" />
                                )}
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <button onClick={() => navigate(`/procurement/purchase-orders/${po.id}`)}
                                            className="font-mono font-semibold text-brand-600 text-sm hover:underline">
                                            {po.po_number}
                                        </button>
                                        <span className="badge badge-warning text-2xs">Pending Approval</span>
                                        {po.items_count !== undefined && (
                                            <span className="text-2xs text-surface-400">{po.items_count} item{po.items_count !== 1 ? "s" : ""}</span>
                                        )}
                                    </div>
                                    <p className="text-sm font-medium text-surface-900 mt-0.5">{po.supplier?.name ?? "Unknown Supplier"}</p>
                                    <div className="flex flex-wrap gap-x-4 gap-y-0.5 mt-1 text-xs text-surface-500">
                                        <span>Total: <strong className="text-surface-900">{po.currency_code} {(po.total_amount ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</strong></span>
                                        {po.created_by && <span>By: {po.created_by.first_name} {po.created_by.last_name}</span>}
                                        <span>Submitted: {new Date(po.submitted_at ?? po.created_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}</span>
                                        <WaitingAge since={po.submitted_at ?? po.created_at} />
                                    </div>
                                    {po.notes && <p className="text-xs text-surface-500 mt-1 line-clamp-1 italic">{po.notes}</p>}
                                </div>
                            </div>
                            {canApprove && (
                                <div className="flex gap-2 shrink-0 sm:flex-col sm:gap-1.5 md:flex-row md:gap-2">
                                    <button onClick={() => { setSelected(po); setAction("reject"); }}
                                        className="btn-secondary btn-sm text-danger border-danger/30 hover:bg-danger-light flex-1 sm:flex-none">
                                        Reject
                                    </button>
                                    <button onClick={() => { setSelected(po); setAction("approve"); }}
                                        className="btn-primary btn-sm flex-1 sm:flex-none">
                                        Approve
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {selected && action && (
                <ActionModal
                    title={action === "approve" ? `Approve ${selected.po_number}` : `Reject ${selected.po_number}`}
                    action={action}
                    requireReason={action === "reject"}
                    reasonLabel={action === "approve" ? "Approval Notes" : "Rejection Reason"}
                    isPending={approveMutation.isPending || rejectMutation.isPending}
                    onClose={() => { setAction(null); setSelected(null); }}
                    onConfirm={(notes) => {
                        if (action === "approve") approveMutation.mutate({ id: selected.id, notes });
                        else rejectMutation.mutate({ id: selected.id, reason: notes });
                    }}
                />
            )}
        </>
    );
}

// ─── Purchase Returns Panel ───────────────────────────────────────────────────

function PurchaseReturnsPanel() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const { canAny } = usePermissions();
    const canApprove = canAny("procurement.approve", "admin.all");

    const navigate = useNavigate();
    const [selected, setSelected] = useState<PendingReturn | null>(null);
    const [action,   setAction]   = useState<"approve" | "reject" | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["approvals-returns"],
        queryFn: () => get<{ data: PendingReturn[] }>("/v1/admin/purchase-returns", {
            params: { status: "pending", per_page: "50" },
        }),
        refetchInterval: 30_000,
        staleTime: 0,
    });
    const items = data?.data ?? [];

    const removeFromList = (id: number) =>
        qc.setQueryData(["approvals-returns"], (old: any) =>
            old ? { ...old, data: old.data.filter((r: PendingReturn) => r.id !== id) } : old
        );

    const approveMutation = useMutation({
        mutationFn: ({ id, notes }: { id: number; notes: string }) =>
            post(`/v1/admin/purchase-returns/${id}/approve`, { notes }),
        onSuccess: (_, { id }) => {
            removeFromList(id);
            toast.success("Return approved");
            qc.invalidateQueries({ queryKey: ["approvals-returns"] });
            qc.invalidateQueries({ queryKey: ["approval-count-ret"] });
            setAction(null); setSelected(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const rejectMutation = useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            post(`/v1/admin/purchase-returns/${id}/reject`, { reason }),
        onSuccess: (_, { id }) => {
            removeFromList(id);
            toast.success("Return rejected");
            qc.invalidateQueries({ queryKey: ["approvals-returns"] });
            qc.invalidateQueries({ queryKey: ["approval-count-ret"] });
            setAction(null); setSelected(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>;
    if (items.length === 0) return <EmptyState label="No purchase returns awaiting approval" />;

    return (
        <>
            <div className="divide-y divide-surface-50">
                {items.map(ret => (
                    <div key={ret.id} className="px-4 py-4 hover:bg-surface-50 transition-colors">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <button onClick={() => navigate(`/procurement/purchase-returns`)}
                                        className="font-mono font-semibold text-danger text-sm hover:underline">
                                        {ret.return_number}
                                    </button>
                                    <span className="badge badge-warning text-2xs">Pending</span>
                                </div>
                                <p className="text-sm font-medium text-surface-900 mt-0.5">
                                    {ret.supplier?.name ?? ret.purchase_order?.po_number ?? "-"}
                                    {ret.purchase_order?.po_number && <span className="text-surface-400 ml-1 text-xs">· {ret.purchase_order.po_number}</span>}
                                </p>
                                <div className="flex flex-wrap gap-x-4 mt-1 text-xs text-surface-500">
                                    {ret.created_by_user && <span>By: {ret.created_by_user.first_name} {ret.created_by_user.last_name}</span>}
                                    <span>{new Date(ret.created_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}</span>
                                    <WaitingAge since={ret.created_at} />
                                    {ret.items_count !== undefined && <span>{ret.items_count} item{ret.items_count !== 1 ? "s" : ""}</span>}
                                </div>
                                {ret.reason && <p className="text-xs text-surface-500 mt-1 italic line-clamp-1">{ret.reason}</p>}
                            </div>
                            {canApprove && (
                                <div className="flex gap-2 shrink-0">
                                    <button onClick={() => { setSelected(ret); setAction("reject"); }}
                                        className="btn-secondary btn-sm text-danger border-danger/30 hover:bg-danger-light flex-1 sm:flex-none">
                                        Reject
                                    </button>
                                    <button onClick={() => { setSelected(ret); setAction("approve"); }}
                                        className="btn-primary btn-sm flex-1 sm:flex-none">
                                        Approve
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {selected && action && (
                <ActionModal
                    title={action === "approve" ? `Approve ${selected.return_number}` : `Reject ${selected.return_number}`}
                    action={action}
                    requireReason={action === "reject"}
                    reasonLabel={action === "approve" ? "Approval Notes" : "Rejection Reason"}
                    isPending={approveMutation.isPending || rejectMutation.isPending}
                    onClose={() => { setAction(null); setSelected(null); }}
                    onConfirm={(notes) => {
                        if (action === "approve") approveMutation.mutate({ id: selected.id, notes });
                        else rejectMutation.mutate({ id: selected.id, reason: notes });
                    }}
                />
            )}
        </>
    );
}

// ─── Stock Adjustments Panel ──────────────────────────────────────────────────

function StockAdjustmentsPanel() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const { canAny } = usePermissions();
    const canApprove = canAny("inventory.approve", "admin.all");

    const [selected, setSelected] = useState<PendingAdjustment | null>(null);
    const [action,   setAction]   = useState<"approve" | "reject" | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["approvals-adjustments"],
        queryFn: () => get<{ data: PendingAdjustment[] }>(
            "/v1/admin/inventory/adjustments",
            { params: { status: "pending_approval", per_page: "50" } }
        ),
        refetchInterval: 30_000,
        staleTime: 0,
    });
    const items = data?.data ?? [];

    const removeFromList = (id: number) =>
        qc.setQueryData(["approvals-adjustments"], (old: any) =>
            old ? { ...old, data: old.data.filter((a: PendingAdjustment) => a.id !== id) } : old
        );

    const approveMutation = useMutation({
        mutationFn: ({ id, notes }: { id: number; notes: string }) =>
            put(`/v1/admin/inventory/adjustments/${id}/approve`, { notes }),
        onSuccess: (_, { id }) => {
            removeFromList(id);
            toast.success("Adjustment approved and applied");
            qc.invalidateQueries({ queryKey: ["approvals-adjustments"] });
            qc.invalidateQueries({ queryKey: ["approval-count-adj"] });
            setAction(null); setSelected(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const rejectMutation = useMutation({
        mutationFn: ({ id, notes }: { id: number; notes: string }) =>
            put(`/v1/admin/inventory/adjustments/${id}/reject`, { notes }),
        onSuccess: (_, { id }) => {
            removeFromList(id);
            toast.success("Adjustment rejected");
            qc.invalidateQueries({ queryKey: ["approvals-adjustments"] });
            qc.invalidateQueries({ queryKey: ["approval-count-adj"] });
            setAction(null); setSelected(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>;
    if (items.length === 0) return <EmptyState label="No stock adjustments awaiting approval" />;

    return (
        <>
            <div className="divide-y divide-surface-50">
                {items.map(adj => (
                    <div key={adj.id} className="px-4 py-4 hover:bg-surface-50 transition-colors">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className={clsx("font-bold text-base tabular-nums",
                                        adj.quantity_change > 0 ? "text-success" : "text-danger")}>
                                        {adj.quantity_change > 0 ? "+" : ""}{adj.quantity_change}
                                    </span>
                                    <span className="badge badge-neutral text-2xs capitalize">{adj.reason_label}</span>
                                    <span className="badge badge-warning text-2xs">Pending</span>
                                </div>
                                <p className="text-sm font-medium text-surface-900 mt-0.5">
                                    {adj.product_name ?? "-"}
                                    {adj.variant_name && <span className="text-surface-400 ml-1">· {adj.variant_name}</span>}
                                </p>
                                <div className="flex flex-wrap gap-x-4 mt-1 text-xs text-surface-500">
                                    {adj.outlet_name && <span>Outlet: {adj.outlet_name}</span>}
                                    {adj.created_by && <span>By: {adj.created_by.first_name} {adj.created_by.last_name}</span>}
                                    <span>{new Date(adj.created_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}</span>
                                    <WaitingAge since={adj.created_at} />
                                    {adj.reference_number && <span className="font-mono">Ref: {adj.reference_number}</span>}
                                </div>
                                {adj.notes && <p className="text-xs text-surface-500 mt-1 italic line-clamp-1">{adj.notes}</p>}
                            </div>
                            {canApprove && (
                                <div className="flex gap-2 shrink-0">
                                    <button onClick={() => { setSelected(adj); setAction("reject"); }}
                                        className="btn-secondary btn-sm text-danger border-danger/30 hover:bg-danger-light flex-1 sm:flex-none">
                                        Reject
                                    </button>
                                    <button onClick={() => { setSelected(adj); setAction("approve"); }}
                                        className="btn-primary btn-sm flex-1 sm:flex-none">
                                        Approve
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {selected && action && (
                <ActionModal
                    title={action === "approve" ? "Approve Adjustment" : "Reject Adjustment"}
                    action={action}
                    requireReason={action === "reject"}
                    reasonLabel={action === "approve" ? "Approval Notes" : "Rejection Reason"}
                    isPending={approveMutation.isPending || rejectMutation.isPending}
                    onClose={() => { setAction(null); setSelected(null); }}
                    onConfirm={(notes) => {
                        if (action === "approve") approveMutation.mutate({ id: selected.id, notes });
                        else rejectMutation.mutate({ id: selected.id, notes });
                    }}
                />
            )}
        </>
    );
}

// ─── Stock Transfers Panel ────────────────────────────────────────────────────

function StockTransfersPanel() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const { canAny } = usePermissions();
    const canApprove = canAny("inventory.approve", "admin.all");

    const [selected, setSelected] = useState<PendingTransfer | null>(null);
    const [action,   setAction]   = useState<"approve" | "reject" | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["approvals-transfers"],
        queryFn: () => get<{ data: PendingTransfer[] }>(
            "/v1/admin/inventory/transfers",
            { params: { status: "pending", per_page: "50" } }
        ),
        refetchInterval: 30_000,
        staleTime: 0,
    });
    const items = data?.data ?? [];

    const removeFromList = (id: number) =>
        qc.setQueryData(["approvals-transfers"], (old: any) =>
            old ? { ...old, data: old.data.filter((t: PendingTransfer) => t.id !== id) } : old
        );

    const approveMutation = useMutation({
        mutationFn: (id: number) => put(`/v1/admin/inventory/transfers/${id}/approve`, {}),
        onSuccess: (_, id) => {
            removeFromList(id);
            toast.success("Transfer approved");
            qc.invalidateQueries({ queryKey: ["approvals-transfers"] });
            qc.invalidateQueries({ queryKey: ["approval-count-txf"] });
            setSelected(null); setAction(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const cancelMutation = useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            put(`/v1/admin/inventory/transfers/${id}/cancel`, { reason }),
        onSuccess: (_, { id }) => {
            removeFromList(id);
            toast.success("Transfer cancelled");
            qc.invalidateQueries({ queryKey: ["approvals-transfers"] });
            qc.invalidateQueries({ queryKey: ["approval-count-txf"] });
            setSelected(null); setAction(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>;
    if (items.length === 0) return <EmptyState label="No stock transfers awaiting approval" />;

    return (
        <>
        <div className="divide-y divide-surface-50">
            {items.map(t => (
                <div key={t.id} className="px-4 py-4 hover:bg-surface-50 transition-colors">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <span className="font-mono font-semibold text-brand-600 text-sm">{t.transfer_number}</span>
                                <span className="badge badge-warning text-2xs">Pending</span>
                            </div>
                            <div className="flex items-center gap-2 mt-1 flex-wrap">
                                <span className="text-sm font-medium text-surface-900">{t.from_outlet?.name ?? "-"}</span>
                                <svg className="w-4 h-4 text-surface-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                </svg>
                                <span className="text-sm font-medium text-surface-900">{t.to_outlet?.name ?? "-"}</span>
                            </div>
                            <div className="flex flex-wrap gap-x-4 mt-1 text-xs text-surface-500">
                                {t.requested_by && <span>By: {t.requested_by.first_name} {t.requested_by.last_name}</span>}
                                <span>{new Date(t.created_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}</span>
                                <WaitingAge since={t.created_at} />
                                {t.total_items !== undefined && <span>{t.total_items} items</span>}
                            </div>
                            {t.notes && <p className="text-xs text-surface-500 mt-1 italic line-clamp-1">{t.notes}</p>}
                        </div>
                        {canApprove && (
                            <div className="flex gap-2 shrink-0">
                                <button onClick={() => { setSelected(t); setAction("reject"); }}
                                    className="btn-secondary btn-sm text-danger border-danger/30 hover:bg-danger-light flex-1 sm:flex-none">
                                    Cancel
                                </button>
                                <button onClick={() => approveMutation.mutate(t.id)}
                                    disabled={approveMutation.isPending}
                                    className="btn-primary btn-sm flex-1 sm:flex-none">
                                    Approve
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            ))}
        </div>
        {selected && action === "reject" && (
            <ActionModal
                title={`Cancel Transfer ${selected.transfer_number}`}
                action="reject"
                requireReason={false}
                reasonLabel="Reason for cancellation"
                isPending={cancelMutation.isPending}
                onClose={() => { setAction(null); setSelected(null); }}
                onConfirm={(reason) => cancelMutation.mutate({ id: selected.id, reason })}
            />
        )}
        </>
    );
}


// ─── Payment Approvals Panel (Phase 5 - International Orders) ────────────────

interface PendingPayment {
    id: number;
    payment_number: string;
    payment_method: string;
    amount: number;
    currency_code: string;
    proof_of_payment_path: string | null;
    proof_url: string | null;
    proof_uploaded_at: string | null;
    approval_status: "pending_review" | "approved" | "rejected";
    requires_approval: boolean;
    created_at: string;
    waiting_hours: number;
    order_id: number;
    order_number: string;
    // Resolved server-side - never null
    customer_name: string;
    customer_email: string | null;
    customer_phone: string | null;
    customer_country_code: string | null;
    order_type: string | null;
    order_total: number;
}

const PAYMENT_METHOD_LABELS: Record<string, string> = {
    bank_transfer: "Bank Transfer",
    other:         "Manual / Other",
    card:          "Card",
    mpesa:         "M-Pesa",
    cash:          "Cash",
};

function ProofViewer({ proofUrl, paymentNumber }: { proofUrl: string; paymentNumber: string }) {
    const [loading, setLoading]       = useState(false);
    const [blobUrl, setBlobUrl]       = useState<string | null>(null);
    const [mimeType, setMimeType]     = useState<string>("image/jpeg");
    const [open, setOpen]             = useState(false);
    const [error, setError]           = useState<string | null>(null);

    // Derive the API path from the full URL (strip origin)
    const apiPath = proofUrl.startsWith("http")
        ? proofUrl.replace(/^https?:\/\/[^/]+/, "")   // "/api/v1/admin/payments/3/proof"
        : proofUrl;

    const handleOpen = async () => {
        setLoading(true);
        setError(null);

        try {
            // The endpoint streams the file as binary - use fetch() with the
            // Bearer token so we get the raw bytes, then create a local blob URL.
            const token = tokenStorage.get();
            const base  = (import.meta.env.VITE_API_URL ?? "").replace(/\/api$/, "");
            const fullUrl = apiPath.startsWith("http") ? apiPath : `${base}${apiPath}`;

            const response = await fetch(fullUrl, {
                headers: {
                    Authorization: token ? `Bearer ${token}` : "",
                    Accept: "*/*",
                },
            });

            if (!response.ok) {
                throw new Error(`${response.status} ${response.statusText}`);
            }

            const contentType = response.headers.get("Content-Type") ?? "image/jpeg";
            setMimeType(contentType);

            const blob = await response.blob();
            const url  = URL.createObjectURL(blob);

            // Revoke any previous blob URL to avoid memory leaks
            if (blobUrl) URL.revokeObjectURL(blobUrl);
            setBlobUrl(url);
            setOpen(true);
        } catch (e: any) {
            setError(e.message ?? "Could not load proof");
        } finally {
            setLoading(false);
        }
    };

    const handleClose = () => {
        setOpen(false);
        // Don't revoke yet - user may reopen; it gets revoked on next load or unmount
    };

    const isPdf = mimeType.includes("pdf");

    return (
        <>
            <button
                onClick={handleOpen}
                disabled={loading}
                className="inline-flex items-center gap-1.5 text-xs text-brand-600 hover:underline disabled:opacity-50"
            >
                <svg className="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                {loading ? "Loading…" : "View Proof"}
            </button>

            {error && (
                <p className="text-2xs text-danger mt-0.5">{error}</p>
            )}

            {/* Preview modal - rendered via portal-like fixed overlay */}
            {open && blobUrl && (
                <div
                    className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/75 backdrop-blur-sm p-4"
                    onClick={(e) => { if (e.target === e.currentTarget) handleClose(); }}
                >
                    <div className="bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden w-full max-w-3xl"
                         style={{ maxHeight: "90vh" }}>

                        {/* Header */}
                        <div className="flex items-center justify-between px-5 py-3.5 border-b border-surface-100 shrink-0">
                            <div>
                                <p className="font-semibold text-sm text-surface-900">Proof of Payment</p>
                                <p className="text-2xs text-surface-400 mt-0.5">{paymentNumber}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                {/* Download / open in new tab */}
                                <a
                                    href={blobUrl}
                                    download={`proof-${paymentNumber}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="btn-secondary btn-sm gap-1.5 text-xs"
                                >
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                    Open / Download
                                </a>
                                <button onClick={handleClose} className="btn-ghost btn-icon btn-sm"
aria-label="Close">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {/* Content */}
                        <div className="flex-1 overflow-auto bg-surface-50 flex items-center justify-center"
                             style={{ minHeight: "300px" }}>
                            {isPdf ? (
                                <iframe
                                    src={blobUrl}
                                    title={`Proof - ${paymentNumber}`}
                                    className="w-full"
                                    style={{ height: "70vh", border: "none" }}
                                />
                            ) : (
                                <img
                                    src={blobUrl}
                                    alt={`Proof of payment - ${paymentNumber}`}
                                    className="max-w-full object-contain rounded-lg shadow"
                                    style={{ maxHeight: "72vh" }}
                                />
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function PaymentApprovalsPanel() {
    const toast    = useToastStore();
    const qc       = useQueryClient();
    const navigate = useNavigate();

    const [selected, setSelected] = useState<PendingPayment | null>(null);
    const [action,   setAction]   = useState<"approve" | "reject" | null>(null);
    const [search,   setSearch]   = useState("");

    const { data, isLoading } = useQuery({
        queryKey: ["approvals-payments", search],
        queryFn:  () => get<{ data: PendingPayment[]; meta: { total: number }; pending_count: number }>(
            "/v1/admin/payments/pending-approval",
            { params: { search: search || undefined, per_page: "50" } as any }
        ),
        staleTime: 30_000,
        refetchInterval: 60_000,
    });

    const payments = data?.data ?? [];

    const approveMut = useMutation({
        mutationFn: ({ id, notes }: { id: number; notes: string }) =>
            post(`/v1/admin/payments/${id}/approve`, { notes }),
        onSuccess: () => {
            toast.success("Payment approved - order advanced");
            qc.invalidateQueries({ queryKey: ["approvals-payments"] });
            qc.invalidateQueries({ queryKey: ["approval-count-payments"] });
            setSelected(null); setAction(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const rejectMut = useMutation({
        mutationFn: ({ id, notes }: { id: number; notes: string }) =>
            post(`/v1/admin/payments/${id}/reject`, { notes }),
        onSuccess: () => {
            toast.success("Payment proof rejected - staff notified");
            qc.invalidateQueries({ queryKey: ["approvals-payments"] });
            qc.invalidateQueries({ queryKey: ["approval-count-payments"] });
            setSelected(null); setAction(null);
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const fmt = (n: number, cc = "USD") =>
        `${cc} ${n.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    if (isLoading) return (
        <div className="flex items-center justify-center py-16"><Spinner /></div>
    );

    if (payments.length === 0) return <EmptyState label="No payments awaiting approval" />;

    return (
        <>
            {/* Search */}
            <div className="p-4 border-b border-surface-100">
                <input
                    className="input input-sm w-full sm:w-64"
                    placeholder="Search order, customer…"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                />
            </div>

            <div className="divide-y divide-surface-100">
                {payments.map(p => {
                    const isUrgent    = p.waiting_hours >= 48;
                    const hasProof    = !!p.proof_url;
                    const isIntl      = p.customer_country_code &&
                                        p.customer_country_code.toUpperCase() !== "KE";

                    return (
                        <div key={p.id} className={clsx(
                            "p-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-4 hover:bg-surface-50 transition-colors",
                            isUrgent && "bg-danger-light/30"
                        )}>
                            {/* Left: order + customer info */}
                            <div className="flex-1 min-w-0 space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <button
                                        onClick={() => navigate(`/sales/orders/${p.order_id}`)}
                                        className="font-mono text-xs font-bold text-brand-600 hover:underline">
                                        {p.order_number}
                                    </button>
                                    {/* Only show International badge when customer is genuinely outside KE */}
                                    {isIntl && (
                                        <span className="badge text-2xs bg-info-light text-info flex items-center gap-1">
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 000 20M12 2a14.5 14.5 0 010 20M2 12h20M4.5 7h15M4.5 17h15"/></svg>
                                            International
                                        </span>
                                    )}
                                    <span className="badge text-2xs badge-neutral capitalize">
                                        {PAYMENT_METHOD_LABELS[p.payment_method] ?? p.payment_method}
                                    </span>
                                    <WaitingAge since={p.created_at} />
                                </div>

                                <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-surface-600">
                                    <span className="font-semibold text-surface-900">
                                        {fmt(p.amount, p.currency_code)}
                                    </span>
                                    <span className="text-surface-400">
                                        of {fmt(p.order_total, p.currency_code)} order total
                                    </span>
                                    {/* customer_name is always resolved server-side */}
                                    <span className="font-medium text-surface-800">{p.customer_name}</span>
                                    {p.customer_email && (
                                        <span className="text-surface-400">{p.customer_email}</span>
                                    )}
                                    {p.customer_phone && (
                                        <span className="text-surface-400">{p.customer_phone}</span>
                                    )}
                                    {p.customer_country_code && (
                                        <span className="text-surface-400 uppercase">{p.customer_country_code}</span>
                                    )}
                                </div>

                                {/* Proof */}
                                <div className="flex items-center gap-3 flex-wrap">
                                    {hasProof ? (
                                        <ProofViewer proofUrl={p.proof_url!} paymentNumber={p.payment_number} />
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-xs text-warning-dark">
                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                            </svg>
                                            No proof uploaded yet
                                        </span>
                                    )}
                                    {p.proof_uploaded_at && (
                                        <span className="text-2xs text-surface-400">
                                            Uploaded {new Date(p.proof_uploaded_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}
                                        </span>
                                    )}
                                </div>
                            </div>

                            {/* Right: actions */}
                            <div className="flex gap-2 shrink-0 flex-col items-end">
                                {!hasProof && (
                                    <span className="inline-flex items-center gap-1 text-2xs text-warning-dark bg-amber-50 border border-amber-200 rounded-lg px-2 py-1">
                                        <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                        </svg>
                                        No proof uploaded
                                    </span>
                                )}
                                <div className="flex gap-2">
                                    <button
                                        onClick={() => { setSelected(p); setAction("reject"); }}
                                        className="btn-secondary btn-sm text-danger border-danger/30 hover:bg-danger-light flex-1 sm:flex-none">
                                        Reject
                                    </button>
                                    <button
                                        onClick={() => { setSelected(p); setAction("approve"); }}
                                        className="btn-primary btn-sm flex-1 sm:flex-none">
                                        Approve{!hasProof && " anyway"}
                                    </button>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Modals */}
            {selected && action === "approve" && (
                <ActionModal
                    title={`Approve Payment - ${selected.payment_number}`}
                    action="approve"
                    requireReason={false}
                    reasonLabel="Approval notes"
                    isPending={approveMut.isPending}
                    onClose={() => { setSelected(null); setAction(null); }}
                    onConfirm={notes => approveMut.mutate({ id: selected.id, notes })}
                />
            )}
            {selected && action === "reject" && (
                <ActionModal
                    title={`Reject Proof - ${selected.payment_number}`}
                    action="reject"
                    requireReason={true}
                    reasonLabel="Reason for rejection (shown to staff)"
                    isPending={rejectMut.isPending}
                    onClose={() => { setSelected(null); setAction(null); }}
                    onConfirm={notes => rejectMut.mutate({ id: selected.id, notes })}
                />
            )}
        </>
    );
}

// ─── Empty state ──────────────────────────────────────────────────────────────

function EmptyState({ label }: { label: string }) {
    return (
        <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-2">
            <svg className="w-12 h-12 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={0.8}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p className="text-sm font-medium text-surface-500">{label}</p>
            <p className="text-xs text-surface-400">All clear - nothing to review</p>
        </div>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ApprovalsPage() {
    const [activeTab, setActiveTab] = useState<ApprovalTab>("payment_approvals");
    const qc = useQueryClient();

    // Fetch counts for badges
    const { data: payCount } = useQuery({
        queryKey: ["approval-count-payments"],
        queryFn: () => get<{ pending_count: number }>("/v1/admin/payments/pending-approval", {
            params: { per_page: "1" } as any,
        }).then(r => (r as any).pending_count ?? 0),
        refetchInterval: 60_000,
    });

    const { data: poCount } = useQuery({
        queryKey: ["approval-count-po"],
        queryFn: () => get<{ data: unknown[] }>("/v1/admin/purchase-orders", {
            params: { status: "pending_approval", per_page: "1" },
        }).then(r => (r as any)?.total ?? (r as any)?.meta?.total ?? 0),
        refetchInterval: 60_000,
    });

    const { data: retCount } = useQuery({
        queryKey: ["approval-count-ret"],
        queryFn: () => get<{ data: unknown[] }>("/v1/admin/purchase-returns", {
            params: { status: "pending", per_page: "1" },
        }).then(r => (r as any)?.total ?? (r as any)?.meta?.total ?? 0),
        refetchInterval: 60_000,
    });

    const { data: adjData } = useQuery({
        queryKey: ["approval-count-adj"],
        queryFn: () => get<{ stats: { pending_approval: number } }>(
            "/v1/admin/inventory/adjustments",
            { params: { per_page: "1" } }
        ),
        refetchInterval: 60_000,
    });

    const { data: txfData } = useQuery({
        queryKey: ["approval-count-txf"],
        queryFn: () => get<{ stats: { pending: number } }>(
            "/v1/admin/inventory/transfers",
            { params: { per_page: "1" } }
        ),
        refetchInterval: 60_000,
    });

    const tabs: { key: ApprovalTab; label: string; count: number }[] = [
        { key: "payment_approvals", label: "Payment Approvals", count: typeof payCount === "number" ? payCount : 0 },
        { key: "purchase_orders",   label: "Purchase Orders",  count: typeof poCount  === "number" ? poCount  : 0 },
        { key: "purchase_returns",  label: "Purchase Returns", count: typeof retCount === "number" ? retCount : 0 },
        { key: "stock_adjustments", label: "Stock Adjustments", count: (adjData as any)?.stats?.pending_approval ?? 0 },
        { key: "stock_transfers",   label: "Stock Transfers",   count: (txfData as any)?.stats?.pending ?? 0 },
    ];

    const totalPending = tabs.reduce((s, t) => s + t.count, 0);

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="page-title">Approvals</h1>
                    <p className="page-subtitle">
                        {totalPending > 0
                            ? `${totalPending} item${totalPending !== 1 ? "s" : ""} pending your review`
                            : "All items reviewed - nothing pending"}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    {totalPending > 0 && (
                        <div className="flex items-center gap-2 px-3 py-1.5 bg-warning-light text-warning-dark rounded-xl text-sm font-medium">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {totalPending} pending
                        </div>
                    )}
                    <button onClick={() => {
                        ["approvals-payments","approvals-pos","approvals-returns","approvals-adjustments","approvals-transfers",
                         "approval-count-payments","approval-count-po","approval-count-ret","approval-count-adj","approval-count-txf"]
                        .forEach(key => qc.invalidateQueries({ queryKey: [key] }));
                    }} className="btn-secondary btn-icon btn-sm"
                    aria-label="Refresh" title="Refresh">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                    </button>
                </div>
            </div>

            {/* Tabs + content */}
            <div className="card overflow-hidden">
                {/* Tab bar */}
                <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar">
                    {tabs.map(tab => (
                        <button key={tab.key} onClick={() => setActiveTab(tab.key)}
                            className={clsx(
                                "flex items-center gap-1.5 px-5 py-3.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap shrink-0",
                                activeTab === tab.key
                                    ? "border-brand-500 text-brand-600"
                                    : "border-transparent text-surface-500 hover:text-surface-700",
                            )}>
                            {tab.label}
                            <PendingBadge count={tab.count} />
                        </button>
                    ))}
                </div>

                {/* Panel */}
                <div>
                    {activeTab === "payment_approvals" && <PaymentApprovalsPanel />}
                    {activeTab === "purchase_orders"   && <PurchaseOrdersPanel />}
                    {activeTab === "purchase_returns"  && <PurchaseReturnsPanel />}
                    {activeTab === "stock_adjustments" && <StockAdjustmentsPanel />}
                    {activeTab === "stock_transfers"   && <StockTransfersPanel />}
                </div>
            </div>
        </div>
    );
}