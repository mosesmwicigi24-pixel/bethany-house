import { useState, type ReactNode } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, tokenStorage } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

/*
 * Approvals (Phase 3B). One queue — everything the approval engine says you
 * can sign NOW (your band, never your own) — and "My submissions", where a
 * maker sees each request's band, who signed, why it was rejected and when it
 * expires. Purchase orders, stock adjustments and transfers, expenses, imprest
 * top-ups and payment void / move requests all arrive in the one queue.
 *
 * Purchase returns and payment proofs are not on the engine yet; they keep
 * their own tabs, gated by the key their endpoints check.
 */

type ApprovalTab = "inbox" | "mine" | "purchase_returns" | "payment_approvals";

/** Keys that sign a band somewhere (route guard and sidebar use the same list plus the makers' keys). */
export const SIGNING_PERMISSIONS = [
    "procurement.approve", "inventory.approve", "expenses.approve", "approvals.finance_sign",
    "payments.void", "payments.reassign",
];

interface ApprovalBand {
    order: number;
    permission: string;
    up_to_kes: number | null;
    signed?: boolean;
}

interface ApprovalSignatureRow {
    band_order: number;
    covers: number[] | null;
    decision: "approved" | "rejected";
    reason: string | null;
    signed_at: string | null;
    signer: { id: number; name: string } | null;
}

interface ApprovalItem {
    id: number;
    event: string;
    approvable_type: string;
    approvable_id: number;
    version: number;
    status: "pending" | "approved" | "rejected" | "expired" | "cancelled";
    maker: { id: number; name: string } | null;
    counterparty: string | null;
    amount: number | null;
    currency_code: string | null;
    amount_kes: number | null;
    basis_kes: number | null;
    value_unknown: boolean;
    bands: ApprovalBand[];
    current_band: ApprovalBand | null;
    awaiting: ApprovalBand | null;
    escalated: boolean;
    signatures: ApprovalSignatureRow[];
    rejected_reason: string | null;
    supersedes_id: number | null;
    expires_at: string | null;
    decided_at: string | null;
    created_at: string | null;
    summary: { title: string; reference?: string; link?: string; lines?: string[] } | null;
    can_sign: boolean;
    can_resubmit: boolean;
}

const EVENT_LABELS: Record<string, string> = {
    purchase_order:       "Purchase order",
    stock_adjustment:     "Stock adjustment",
    serialized_write_off: "Serialized write-off",
    stock_transfer:       "Stock transfer",
    expense:              "Expense",
    imprest_topup:        "Imprest top-up",
    payment_void:         "Payment void",
    payment_reassign:     "Payment move",
};

/** Who a band's key belongs to, in words. */
const BAND_LABELS: Record<string, string> = {
    "procurement.approve":    "Procurement manager",
    "inventory.approve":      "Procurement manager",
    "approvals.finance_sign": "Finance",
    "expenses.approve":       "Finance",
    "payments.void":          "Finance",
    "payments.reassign":      "Finance",
    "approvals.super_sign":   "Super admin",
};
const bandLabel = (b: ApprovalBand) => BAND_LABELS[b.permission] ?? b.permission;

const kes = (n: number) => `KES ${n.toLocaleString("en-KE", { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;

const STATUS_BADGE: Record<ApprovalItem["status"], string> = {
    pending:   "badge-warning",
    approved:  "badge-success",
    rejected:  "badge-danger",
    expired:   "badge-neutral",
    cancelled: "badge-neutral",
};

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


// ─── Shared: one request, as a card ───────────────────────────────────────────

function ExpiresIn({ at }: { at: string | null }) {
    if (!at) return null;
    const hours = Math.round((new Date(at).getTime() - Date.now()) / 3_600_000);
    if (hours <= 0) return <span className="text-2xs text-danger font-medium">Expiring now</span>;
    return (
        <span className={clsx("text-2xs font-medium", hours <= 12 ? "text-danger" : hours <= 24 ? "text-warning-dark" : "text-surface-400")}>
            Expires in {hours >= 24 ? `${Math.floor(hours / 24)}d ${hours % 24}h` : `${hours}h`}
        </span>
    );
}

function BandTrail({ item }: { item: ApprovalItem }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {item.bands.map((b, i) => {
                const isCurrent = item.status === "pending" && item.current_band?.order === b.order;
                return (
                    <span key={b.order} className="flex items-center gap-1.5">
                        {i > 0 && <span className="text-surface-300 text-2xs">→</span>}
                        <span className={clsx(
                            "inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-2xs font-medium border",
                            b.signed ? "bg-success-light text-success-dark border-success/30"
                                : isCurrent ? "bg-warning-light text-warning-dark border-warning/40"
                                : "bg-surface-50 text-surface-500 border-line",
                        )}>
                            {b.signed && (
                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            )}
                            {bandLabel(b)}
                        </span>
                    </span>
                );
            })}
            {item.escalated && item.awaiting && (
                <span className="badge text-2xs bg-info-light text-info" title="Nobody but the maker holds this band, so it escalated">
                    Escalated to {bandLabel(item.awaiting)}
                </span>
            )}
        </div>
    );
}

function RequestCard({ item, actions }: { item: ApprovalItem; actions?: ReactNode }) {
    const navigate = useNavigate();
    const amountLine = item.amount !== null && item.currency_code
        ? `${item.currency_code} ${item.amount.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        : null;

    return (
        <div className="px-3.5 py-3 sm:px-4 sm:py-4 hover:bg-surface-50 transition-colors">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div className="flex-1 min-w-0 space-y-1.5">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="badge badge-neutral text-2xs">{EVENT_LABELS[item.event] ?? item.event}</span>
                        {item.summary?.link ? (
                            <button onClick={() => navigate(item.summary!.link!)}
                                className="font-semibold text-sm text-brand-600 hover:underline text-left line-clamp-2">
                                {item.summary?.title ?? `#${item.approvable_id}`}
                            </button>
                        ) : (
                            <span className="font-semibold text-sm text-surface-900 line-clamp-2">{item.summary?.title ?? `#${item.approvable_id}`}</span>
                        )}
                        {item.version > 1 && <span className="badge text-2xs bg-surface-100 text-surface-600">Version {item.version}</span>}
                        <span className={clsx("badge text-2xs capitalize", STATUS_BADGE[item.status])}>{item.status}</span>
                    </div>

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-surface-600">
                        {amountLine && <span className="font-semibold text-surface-900">{amountLine}</span>}
                        {item.amount_kes !== null && item.currency_code !== "KES" && <span>≈ {kes(item.amount_kes)} at the reporting rate</span>}
                        {item.value_unknown && (
                            <span className="text-warning-dark font-medium" title="No reporting rate or no product cost — every band is required">
                                Value unknown — every band required
                            </span>
                        )}
                        {item.basis_kes !== null && item.amount_kes !== null && item.basis_kes > item.amount_kes && (
                            <span className="text-warning-dark" title="The band is judged on the same maker's submissions to the same counterparty in the last 24 hours">
                                24h total: {kes(item.basis_kes)}
                            </span>
                        )}
                        {item.maker && <span>Raised by {item.maker.name}</span>}
                        {item.created_at && <WaitingAge since={item.created_at} />}
                        {item.status === "pending" && <ExpiresIn at={item.expires_at} />}
                    </div>

                    {item.summary?.lines && item.summary.lines.length > 0 && (
                        <ul className="text-xs text-surface-500 space-y-0.5">
                            {item.summary.lines.map((l, i) => <li key={i} className="line-clamp-1">{l}</li>)}
                        </ul>
                    )}

                    <BandTrail item={item} />

                    {item.signatures.length > 0 && (
                        <ul className="text-2xs text-surface-500 space-y-0.5">
                            {item.signatures.map((s, i) => (
                                <li key={i}>
                                    <span className={s.decision === "rejected" ? "text-danger font-medium" : "text-success-dark font-medium"}>
                                        {s.decision === "rejected" ? "Rejected" : "Signed"}
                                    </span>
                                    {" "}by {s.signer?.name ?? "—"}
                                    {s.signed_at && ` · ${new Date(s.signed_at).toLocaleString("en-KE", { dateStyle: "medium", timeStyle: "short" })}`}
                                    {s.reason && <span className="italic"> — “{s.reason}”</span>}
                                </li>
                            ))}
                        </ul>
                    )}
                    {item.status === "rejected" && item.rejected_reason && !item.signatures.some(s => s.decision === "rejected") && (
                        <p className="text-xs text-danger">Rejected: {item.rejected_reason}</p>
                    )}
                    {item.status === "expired" && (
                        <p className="text-xs text-surface-500">Not decided within 72 hours — it came back to the person who raised it.</p>
                    )}
                </div>
                {actions && <div className="flex gap-2 shrink-0">{actions}</div>}
            </div>
        </div>
    );
}

// ─── To sign: the one queue ───────────────────────────────────────────────────

function InboxPanel() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const [selected, setSelected] = useState<ApprovalItem | null>(null);
    const [action,   setAction]   = useState<"approve" | "reject" | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["approvals-inbox"],
        queryFn:  () => get<{ data: ApprovalItem[]; count: number }>("/v1/admin/approvals/inbox"),
        refetchInterval: 30_000,
        staleTime: 0,
    });
    const items = data?.data ?? [];

    const done = (message: string) => {
        toast.success(message);
        qc.invalidateQueries({ queryKey: ["approvals-inbox"] });
        qc.invalidateQueries({ queryKey: ["approvals-mine"] });
        setAction(null); setSelected(null);
    };

    // Every signature carries the record id and version the signer was
    // shown: the server refuses a mismatch (422) rather than sign something else.
    const signMutation = useMutation({
        mutationFn: ({ item, notes }: { item: ApprovalItem; notes: string }) =>
            post<{ message: string }>(`/v1/admin/approvals/${item.id}/sign`, {
                approvable_id: item.approvable_id, version: item.version, notes: notes || undefined,
            }),
        onSuccess: (res: any) => done(res?.message ?? "Signed"),
        onError: (e: ApiError) => toast.error(e.message),
    });

    const rejectMutation = useMutation({
        mutationFn: ({ item, reason }: { item: ApprovalItem; reason: string }) =>
            post<{ message: string }>(`/v1/admin/approvals/${item.id}/reject`, {
                approvable_id: item.approvable_id, version: item.version, reason,
            }),
        onSuccess: () => done("Rejected — it went back to the person who raised it"),
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>;
    if (items.length === 0) return <EmptyState label="Nothing is waiting for your signature" />;

    return (
        <>
            <div className="divide-y divide-line">
                {items.map(item => (
                    <RequestCard key={item.id} item={item} actions={item.can_sign && (
                        <>
                            <button onClick={() => { setSelected(item); setAction("reject"); }}
                                className="btn-secondary btn-sm text-danger border-danger/30 hover:bg-danger-light flex-1 sm:flex-none">
                                Reject
                            </button>
                            <button onClick={() => { setSelected(item); setAction("approve"); }}
                                className="btn-primary btn-sm flex-1 sm:flex-none">
                                {item.awaiting ? `Sign as ${bandLabel(item.awaiting)}` : "Approve"}
                            </button>
                        </>
                    )} />
                ))}
            </div>

            {selected && action && (
                <ActionModal
                    title={`${action === "approve" ? "Sign" : "Reject"} — ${selected.summary?.title ?? EVENT_LABELS[selected.event]}`}
                    action={action}
                    requireReason={action === "reject"}
                    reasonLabel={action === "approve" ? "Notes" : "Reason (the maker sees this)"}
                    isPending={signMutation.isPending || rejectMutation.isPending}
                    onClose={() => { setAction(null); setSelected(null); }}
                    onConfirm={(notes) => {
                        if (action === "approve") signMutation.mutate({ item: selected, notes });
                        else rejectMutation.mutate({ item: selected, reason: notes });
                    }}
                />
            )}
        </>
    );
}

// ─── My submissions ───────────────────────────────────────────────────────────

function MinePanel() {
    const toast = useToastStore();
    const qc    = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ["approvals-mine"],
        queryFn:  () => get<{ data: ApprovalItem[] }>("/v1/admin/approvals/mine"),
        refetchInterval: 60_000,
        staleTime: 0,
    });
    const items = data?.data ?? [];

    const resubmit = useMutation({
        mutationFn: (item: ApprovalItem) => post(`/v1/admin/approvals/${item.id}/resubmit`, {}),
        onSuccess: () => {
            toast.success("Resubmitted as a new version");
            qc.invalidateQueries({ queryKey: ["approvals-mine"] });
            qc.invalidateQueries({ queryKey: ["approvals-inbox"] });
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading) return <div className="flex justify-center py-12"><Spinner size="lg" /></div>;
    if (items.length === 0) return <EmptyState label="You have not submitted anything for approval" />;

    return (
        <div className="divide-y divide-line">
            {items.map(item => (
                <RequestCard key={item.id} item={item} actions={item.can_resubmit && (
                    <button onClick={() => resubmit.mutate(item)} disabled={resubmit.isPending}
                        className="btn-secondary btn-sm flex-1 sm:flex-none">
                        Resubmit
                    </button>
                )} />
            ))}
        </div>
    );
}

// ─── Purchase returns (not on the engine yet) ─────────────────────────────────

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

function PurchaseReturnsPanel() {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const { canAny } = usePermissions();
    const canApprove = canAny("procurement.approve");

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
            <div className="divide-y divide-line">
                {items.map(ret => (
                    <div key={ret.id} className="px-3.5 py-3 sm:px-4 sm:py-4 hover:bg-surface-50 transition-colors">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                            <div className="flex-1 min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    {/* Canonical route is /procurement/returns/:id (see App.tsx and
                                        PurchaseReturnsPage). `/procurement/purchase-returns` is not a
                                        registered path — it fell through /procurement/* to the
                                        ModulePlaceholder, so this link went nowhere. */}
                                    <button onClick={() => navigate(`/procurement/returns/${ret.id}`)}
                                        className="font-mono font-semibold text-danger text-sm hover:underline">
                                        {ret.return_number}
                                    </button>
                                    <span className="badge badge-warning text-2xs">Pending</span>
                                </div>
                                {/* Two-part line (supplier · PO ref): clamp to 2 lines rather than
                                    `truncate`, or a long supplier name eats the PO ref on a phone. */}
                                <p className="text-sm font-medium text-surface-900 mt-0.5 line-clamp-2">
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
                        <div className="flex items-center justify-between px-5 py-3.5 border-b border-line shrink-0">
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
    const { canAny } = usePermissions();
    // Mirrors the server: POST /v1/admin/payments/{id}/{approve,reject} is behind
    // `permission:payments.approve_international`. Without this gate the buttons
    // render for every user who can reach the page and 403 on click.
    const canApprove = canAny("payments.approve_international");

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
            <div className="p-4 border-b border-line">
                <input
                    className="input input-sm w-full sm:w-64"
                    placeholder="Search order, customer…"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                />
            </div>

            <div className="divide-y divide-line">
                {payments.map(p => {
                    const isUrgent    = p.waiting_hours >= 48;
                    const hasProof    = !!p.proof_url;
                    const isIntl      = p.customer_country_code &&
                                        p.customer_country_code.toUpperCase() !== "KE";

                    return (
                        <div key={p.id} className={clsx(
                            "p-3.5 sm:p-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-4 hover:bg-surface-50 transition-colors",
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
                                    <span className="font-medium text-surface-800 max-w-full truncate">{p.customer_name}</span>
                                    {/* Contact detail is desk work — on a phone the reviewer only needs
                                        amount + who + proof to decide, so these stay ≥sm. */}
                                    {p.customer_email && (
                                        <span className="hidden sm:inline text-surface-400 max-w-full truncate">{p.customer_email}</span>
                                    )}
                                    {p.customer_phone && (
                                        <span className="hidden sm:inline text-surface-400">{p.customer_phone}</span>
                                    )}
                                    {p.customer_country_code && (
                                        <span className="hidden sm:inline text-surface-400 uppercase">{p.customer_country_code}</span>
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

                            {/* Right: actions — gated to match the server. The left column
                                already states the no-proof warning at every breakpoint, so a
                                read-only reviewer loses no information when this column goes. */}
                            {canApprove && (
                            <div className="flex gap-2 shrink-0 flex-col items-stretch sm:items-end">
                                {!hasProof && (
                                    /* The left column already says "No proof uploaded yet"; on a phone
                                       the two stack directly on top of each other. Keep one. */
                                    <span className="hidden sm:inline-flex items-center gap-1 text-2xs text-warning-dark bg-amber-50 border border-amber-200 rounded-lg px-2 py-1">
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
                            )}
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
    const qc = useQueryClient();
    const { canAny } = usePermissions();
    const [params, setParams] = useSearchParams();

    const canSign      = canAny(...SIGNING_PERMISSIONS);
    const canReturns   = canAny("procurement.approve");
    const canProofs    = canAny("payments.approve_international");

    const initial = (params.get("tab") as ApprovalTab | null) ?? (canSign ? "inbox" : "mine");
    const [activeTab, setActiveTab] = useState<ApprovalTab>(initial);
    const choose = (t: ApprovalTab) => { setActiveTab(t); setParams(t === "inbox" ? {} : { tab: t }, { replace: true }); };

    const { data: inboxData } = useQuery({
        queryKey: ["approvals-inbox"],
        queryFn:  () => get<{ data: ApprovalItem[]; count: number }>("/v1/admin/approvals/inbox"),
        refetchInterval: 30_000,
        enabled: canSign,
    });

    const { data: payCount } = useQuery({
        queryKey: ["approval-count-payments"],
        queryFn: () => get<{ pending_count: number }>("/v1/admin/payments/pending-approval", {
            params: { per_page: "1" } as any,
        }).then(r => (r as any).pending_count ?? 0),
        refetchInterval: 60_000,
        enabled: canProofs,
    });

    const { data: retCount } = useQuery({
        queryKey: ["approval-count-ret"],
        queryFn: () => get<{ data: unknown[] }>("/v1/admin/purchase-returns", {
            params: { status: "pending", per_page: "1" },
        }).then(r => (r as any)?.total ?? (r as any)?.meta?.total ?? 0),
        refetchInterval: 60_000,
        enabled: canReturns,
    });

    const inboxCount = inboxData?.count ?? 0;
    const tabs: { key: ApprovalTab; label: string; count: number; show: boolean }[] = [
        { key: "inbox",             label: "To sign",          count: inboxCount, show: canSign },
        { key: "mine",              label: "My submissions",   count: 0,          show: true },
        { key: "purchase_returns",  label: "Purchase returns", count: typeof retCount === "number" ? retCount : 0, show: canReturns },
        { key: "payment_approvals", label: "Payment proofs",   count: typeof payCount === "number" ? payCount : 0, show: canProofs },
    ];
    const visible = tabs.filter(t => t.show);
    const current = visible.some(t => t.key === activeTab) ? activeTab : visible[0]?.key ?? "mine";
    const totalPending = visible.reduce((s, t) => s + t.count, 0);

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="page-title">Approvals</h1>
                    <p className="page-subtitle">
                        {totalPending > 0
                            ? `${totalPending} item${totalPending !== 1 ? "s" : ""} waiting for you`
                            : "Nothing is waiting for you"}
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
                        ["approvals-inbox", "approvals-mine", "approvals-payments", "approvals-returns",
                         "approval-count-payments", "approval-count-ret"]
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
                <div className="flex border-b border-line overflow-x-auto no-scrollbar">
                    {visible.map(tab => (
                        <button key={tab.key} onClick={() => choose(tab.key)}
                            className={clsx(
                                "flex items-center gap-1.5 px-5 py-3.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap shrink-0",
                                current === tab.key
                                    ? "border-brand-500 text-brand-600"
                                    : "border-transparent text-surface-500 hover:text-surface-700",
                            )}>
                            {tab.label}
                            <PendingBadge count={tab.count} />
                        </button>
                    ))}
                </div>

                <div>
                    {current === "inbox"             && <InboxPanel />}
                    {current === "mine"              && <MinePanel />}
                    {current === "purchase_returns"  && <PurchaseReturnsPanel />}
                    {current === "payment_approvals" && <PaymentApprovalsPanel />}
                </div>
            </div>
        </div>
    );
}
