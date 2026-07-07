import { useState, useRef, useEffect, useCallback } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { ordersApi } from "@/api/orders";
import { get, post } from "@/api/client";
import type { Order, OrderStatus, OrderPayment } from "@/api/orders";
import { shippingApi, paymentMethodsApi } from "@/api/setup";
import type { ShippingMethod, PaymentMethodSetup } from "@/types/setup";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import type { ApiError } from "@/types";
import { ShipmentSection } from "@/components/orders/ShipmentSection";
import { usePdfDownload } from "@/hooks/usePdfDownload";
import { channelApi, type Channel, type ChannelMessage, type LinkedEntity, type EntitySearchResult } from "@/api/channels";
import { parseBodyToNodes } from "@/pages/comms/CommsHub";
import { commentApi, type MentionUser } from "@/api/comments";
import { subscribeToChannel, getEcho } from "@/lib/echo";
import { useAuthStore } from "@/store/auth.store";

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUS_FLOW: Record<string, { label: string; next: OrderStatus[]; color: string; description?: string }> = {
    // ── Status definitions ───────────────────────────────────────────────────
    //
    // pending     - order exists, no payment received yet.
    //
    // processing  - order is partially paid, has a deposit, or has a payment
    //               awaiting admin approval. Not fully confirmed yet.
    //               Shipments CANNOT be created; the order is not fully sorted.
    //
    // confirmed   - order is FULLY PAID and all payments have been approved.
    //               Staff have verified the order is good to fulfil.
    //               Shipments CAN be created from this status.
    //               This is the normal automatic state after full payment clears.
    //
    // shipped     - a shipment record has been created and goods are dispatched.
    //
    // delivered   - customer has received the goods (courier confirms delivery).
    //
    // completed   - the full lifecycle is done: paid + fulfilled + delivered/
    //               handed over. Set EXPLICITLY by staff - never auto-assigned
    //               by a payment event. For POS walk-in sales this is set once
    //               the cashier hands over the item. For delivery orders it
    //               follows "delivered".
    //
    // cancelled   - voided before fulfilment. Inventory is restocked.
    // refunded    - money returned to customer post-payment.
    pending:    {
        label:       "Pending",
        next:        ["processing", "confirmed", "cancelled"],
        color:       "text-warning",
        description: "Awaiting payment",
    },
    processing: {
        label:       "Processing",
        next:        ["confirmed", "cancelled"],
        color:       "text-brand-600",
        description: "Partial payment / deposit received - not yet fully confirmed",
    },
    confirmed:  {
        label:       "Confirmed",
        next:        ["shipped", "completed", "cancelled"],
        color:       "text-blue-600",
        description: "Fully paid - ready to ship or hand over",
    },
    shipped:    {
        label:       "Shipped",
        next:        ["delivered", "completed"],
        color:       "text-purple-600",
        description: "Goods dispatched to customer",
    },
    delivered:  {
        label:       "Delivered",
        next:        ["completed"],
        color:       "text-teal-600",
        description: "Customer received the order",
    },
    completed:  {
        label:       "Completed",
        next:        ["refunded"],
        color:       "text-success",
        description: "Order fully closed - paid and fulfilled",
    },
    cancelled:  { label: "Cancelled", next: [],           color: "text-danger"      },
    refunded:   { label: "Refunded",  next: [],           color: "text-surface-500" },
    voided:     { label: "Voided",    next: [],           color: "text-surface-500" },
};

// Only confirmed (and already-shipped) orders can have shipments created.
// processing = not fully paid yet; cannot ship until payment is fully sorted.
const SHIPPABLE_STATUSES = ["confirmed", "shipped"];

const STATUS_COLORS: Record<string, string> = {
    pending:    "bg-warning-light text-warning-dark",
    processing: "bg-brand-50 text-brand-700",
    confirmed:  "bg-blue-50 text-blue-700",
    shipped:    "bg-purple-50 text-purple-700",
    delivered:  "bg-teal-50 text-teal-700",
    completed:  "bg-success-light text-success-dark",
    cancelled:  "bg-danger-light text-danger",
    refunded:   "bg-surface-100 text-surface-500",
    voided:     "bg-surface-100 text-surface-500",
};

const PAYMENT_METHODS: Record<string, { label: string; icon: string }> = {
    cash:          { label: "Cash",          icon: "cash"     },
    mpesa:         { label: "M-Pesa",        icon: "phone"    },
    card:          { label: "Card",          icon: "card"     },
    bank_transfer: { label: "Bank Transfer", icon: "bank"     },
    other:         { label: "Other",         icon: "money"    },
};

const fmt = (n: number | null | undefined, cc = "KES") =>
    `${cc} ${(n ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}`;

const PaymentMethodIcon = ({ method, className = "w-4 h-4" }: { method?: string; className?: string }) => {
    const s = { className, fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    const icon = PAYMENT_METHODS[method ?? ""]?.icon ?? "money";
    if (icon === "cash")  return <svg {...s}><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>;
    if (icon === "phone") return <svg {...s}><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>;
    if (icon === "card")  return <svg {...s}><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>;
    if (icon === "bank")  return <svg {...s}><path d="M3 22v-8m6 8V10m6 12V8m6 14V4"/><path d="M2 22h20"/></svg>;
    return <svg {...s}><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>;
};

// ── More Actions Dropdown ─────────────────────────────────────────────────────

interface MoreActionItem {
    icon: React.ReactNode;
    label: string;
    onClick: () => void;
    danger?: boolean;
    disabled?: boolean;
}

function MoreActionsMenu({ items }: { items: MoreActionItem[] }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    // Close on outside click
    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener("mousedown", handler);
        return () => document.removeEventListener("mousedown", handler);
    }, []);

    if (items.length === 0) return null;

    return (
        <div className="relative" ref={ref}>
            <button
                onClick={() => setOpen(v => !v)}
                className={clsx(
                    "btn-secondary btn-sm gap-1.5",
                    open && "bg-surface-100 border-surface-300"
                )}
                title="More actions"
            >
                More
                <svg className={clsx("w-3.5 h-3.5 transition-transform", open && "rotate-180")} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                </svg>
            </button>

            {open && (
                <div className="absolute right-0 top-full mt-1.5 z-50 w-52 bg-white rounded-xl shadow-lg border border-surface-200 py-1 animate-fade-in">
                    {items.map((item, i) => (
                        <button
                            key={i}
                            disabled={item.disabled}
                            onClick={() => { item.onClick(); setOpen(false); }}
                            className={clsx(
                                "w-full flex items-center gap-2.5 px-3 py-2 text-xs font-medium transition-colors text-left disabled:opacity-40",
                                item.danger
                                    ? "text-danger hover:bg-danger-light"
                                    : "text-surface-700 hover:bg-surface-50"
                            )}
                        >
                            <span className={clsx("shrink-0", item.danger ? "text-danger" : "text-surface-400")}>
                                {item.icon}
                            </span>
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function Section({ title, children, action }: {
    title: string; children: React.ReactNode; action?: React.ReactNode;
}) {
    return (
        <div className="card overflow-hidden">
            <div className="card-header flex items-center justify-between">
                <h3 className="font-semibold text-sm text-surface-900">{title}</h3>
                {action}
            </div>
            <div className="card-body">{children}</div>
        </div>
    );
}

function InfoRow({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
    return (
        <div className="flex items-start justify-between py-1.5 text-xs border-b border-surface-50 last:border-0">
            <span className="text-surface-500 shrink-0 w-36">{label}</span>
            <span className={clsx("font-medium text-surface-900 text-right", mono && "font-mono")}>{value}</span>
        </div>
    );
}

// ── Status Update Modal ───────────────────────────────────────────────────────

function StatusUpdateModal({ order, onClose, onUpdated }: {
    order: Order; onClose: () => void; onUpdated: () => void;
}) {
    const toast = useToastStore();
    const nextStatuses = STATUS_FLOW[order.status]?.next ?? [];
    const [status, setStatus]   = useState<OrderStatus>(nextStatuses[0] ?? order.status as OrderStatus);
    const [tracking, setTracking] = useState(order.tracking_number ?? "");
    const [notes, setNotes]     = useState("");

    // Derived warning flags
    const hasPendingProduction = (order as any).production_orders?.some(
        (po: any) => !["completed", "cancelled"].includes(po.status)
    );
    const totalPaid = (order as any).payments
        ?.filter((p: any) => p.status === "paid")
        .reduce((s: number, p: any) => s + Number(p.amount), 0) ?? 0;
    const hasUnpaidBalance = totalPaid < (order.total_amount ?? 0) - 0.01;

    // Payments that are pending admin approval block all order progression
    const pendingApprovalPayments = ((order as any).payments ?? []).filter(
        (p: any) => p.requires_approval && p.approval_status === "pending_review"
    );
    const hasPendingApproval = pendingApprovalPayments.length > 0;

    const progressingStatuses = ["completed", "shipped", "delivered", "processing", "confirmed"];
    const blockedByApproval    = hasPendingApproval && progressingStatuses.includes(status);
    const blockedByProduction  = hasPendingProduction && ["completed", "shipped", "delivered"].includes(status);
    const blockedByPayment     = hasUnpaidBalance && !hasPendingApproval && ["completed", "shipped", "delivered"].includes(status);
    const isBlocked = blockedByApproval || blockedByProduction || blockedByPayment;

    const mutation = useMutation({
        mutationFn: () => ordersApi.updateStatus(order.id, {
            status, tracking_number: tracking || undefined, notes: notes || undefined,
        }),
        onSuccess: () => { toast.success("Status updated"); onUpdated(); onClose(); },
        onError:   (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title="Update Order Status" onClose={onClose}>
            <div className="space-y-4 p-5">
                <div>
                    <label className="label">New Status</label>
                    <select value={status} onChange={(e) => setStatus(e.target.value as OrderStatus)} className="input">
                        {nextStatuses.map((s) => (
                            <option key={s} value={s}>{STATUS_FLOW[s]?.label ?? s}</option>
                        ))}
                    </select>
                </div>

                {/* Contextual warnings */}
                {blockedByApproval && (
                    <div className="rounded-xl bg-amber-50 border border-amber-300 px-4 py-3 text-xs text-amber-900">
                        <p className="font-semibold">⏳ Payments awaiting approval</p>
                        <p className="mt-0.5">
                            This order has {pendingApprovalPayments.length} payment{pendingApprovalPayments.length > 1 ? "s" : ""} pending admin approval.
                            The order cannot be advanced until all payments are reviewed and approved or rejected.
                        </p>
                        <Link to="/approvals" className="mt-1.5 inline-block text-amber-800 font-semibold underline underline-offset-2">
                            Go to Approvals queue →
                        </Link>
                    </div>
                )}
                {blockedByProduction && !blockedByApproval && (
                    <div className="rounded-xl bg-warning-light border border-warning/30 px-4 py-3 text-xs text-warning-dark">
                        <p className="font-semibold">⚠ Pending production orders</p>
                        <p className="mt-0.5">This order has production orders that are not yet completed.
                        You cannot mark it as {STATUS_FLOW[status]?.label} until all production is finished.</p>
                    </div>
                )}
                {blockedByPayment && !blockedByProduction && (
                    <div className="rounded-xl bg-warning-light border border-warning/30 px-4 py-3 text-xs text-warning-dark">
                        <p className="font-semibold">⚠ Outstanding balance</p>
                        <p className="mt-0.5">This order has not been fully paid.
                        Collect the remaining balance before marking it as {STATUS_FLOW[status]?.label}.</p>
                    </div>
                )}

                {status === "shipped" && (
                    <div>
                        <label className="label">Tracking Number</label>
                        <input type="text" value={tracking} onChange={(e) => setTracking(e.target.value)}
                            className="input" placeholder="e.g. KE123456789" />
                    </div>
                )}
                <div>
                    <label className="label">Note <span className="text-surface-400">(optional)</span></label>
                    <textarea value={notes} onChange={(e) => setNotes(e.target.value)}
                        className="input resize-none" rows={2} placeholder="Reason for status change…" />
                </div>
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || isBlocked}
                        className="btn-primary flex-1 disabled:opacity-50 disabled:cursor-not-allowed">
                        {mutation.isPending ? "Updating…" : "Update Status"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Refund Modal ──────────────────────────────────────────────────────────────

// ── Void Order Modal ─────────────────────────────────────────────────────────

function VoidOrderModal({ order, onClose, onDone }: {
    order: Order; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();
    const [reason, setReason] = useState("");

    const mutation = useMutation({
        mutationFn: () => ordersApi.voidOrder(order.id, reason),
        onSuccess: () => {
            toast.success("Order voided - inventory restocked.");
            onDone();
            onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const fmt = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2 });
    const cc   = order.currency_code ?? "KES";

    return (
        <Modal open onClose={onClose} title="Void Order" size="sm">
            <div className="p-5 space-y-4">
                <div className="rounded-xl bg-danger-light border border-danger/20 px-4 py-3 text-xs text-danger-dark space-y-1">
                    <p className="font-bold flex items-center gap-1.5">
                        <svg className="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                        This action cannot be undone
                    </p>
                    <p>Voiding order <strong>{order.order_number}</strong> will permanently cancel it, restock all inventory, and void any pending payments.</p>
                    <p className="font-medium">Order total: {cc} {fmt(order.total_amount)}</p>
                </div>

                <div>
                    <label className="label">Reason for voiding <span className="text-danger">*</span></label>
                    <textarea
                        value={reason}
                        onChange={e => setReason(e.target.value)}
                        className="input resize-none"
                        rows={3}
                        placeholder="e.g. Duplicate order, test order, customer request before payment…"
                        autoFocus
                    />
                    {!reason.trim() && (
                        <p className="mt-1 text-2xs text-warning-dark">A reason is required for audit purposes.</p>
                    )}
                </div>

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1" disabled={mutation.isPending}>Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || !reason.trim()}
                        className="flex-1 bg-danger text-white rounded-xl px-4 py-2.5 text-sm font-semibold hover:bg-danger/90 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                        {mutation.isPending ? "Voiding…" : "Void Order"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

function RefundModal({ order, onClose, onDone }: {
    order: Order; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();
    const paidAmount = order.payments
        ?.filter((p: any) => p.status === "paid")
        .reduce((s: number, p: any) => s + Number(p.amount), 0) ?? order.total_amount;
    const alreadyRefunded = order.payments
        ?.reduce((s: number, p: any) => s + Number(p.refund_amount ?? 0), 0) ?? 0;
    const maxRefund = paidAmount - alreadyRefunded;

    const [amount, setAmount]           = useState(maxRefund);
    const [reason, setReason]           = useState("");
    const [refundShipping, setRefundShipping] = useState(false);

    const mutation = useMutation({
        mutationFn: () => ordersApi.refund(order.id, { amount, reason, refund_shipping: refundShipping }),
        onSuccess: () => { toast.success("Refund processed"); onDone(); onClose(); },
        onError:   (e: ApiError) => toast.error(e.message),
    });

    const isPartial = amount < maxRefund && amount > 0;

    return (
        <Modal open title="Process Refund" onClose={onClose}>
            <div className="space-y-4 p-5">
                {/* Summary */}
                <div className="bg-surface-50 rounded-xl p-3 grid grid-cols-1 gap-3 text-center text-xs sm:grid-cols-3">
                    <div>
                        <p className="text-surface-400">Paid</p>
                        <p className="font-bold text-surface-900 mt-0.5">{fmt(paidAmount, order.currency_code)}</p>
                    </div>
                    <div>
                        <p className="text-surface-400">Already Refunded</p>
                        <p className="font-bold text-warning-dark mt-0.5">{fmt(alreadyRefunded, order.currency_code)}</p>
                    </div>
                    <div>
                        <p className="text-surface-400">Max Refundable</p>
                        <p className="font-bold text-brand-600 mt-0.5">{fmt(maxRefund, order.currency_code)}</p>
                    </div>
                </div>

                {alreadyRefunded > 0 && (
                    <div className="bg-warning-light rounded-xl p-3 text-xs text-warning-dark">
                        ⚠ A refund of {fmt(alreadyRefunded, order.currency_code)} has already been issued for this order.
                    </div>
                )}

                <div>
                    <label className="label">
                        Refund Amount ({order.currency_code})
                        {isPartial && <span className="ml-2 text-warning-dark font-normal">Partial refund</span>}
                    </label>
                    <input type="number" min={0.01} max={maxRefund} step={0.01} value={amount}
                        onChange={(e) => setAmount(Math.min(maxRefund, parseFloat(e.target.value) || 0))}
                        className="input text-lg font-semibold" autoFocus />
                    <div className="flex gap-2 mt-2">
                        {[25, 50, 75, 100].map((pct) => (
                            <button key={pct} onClick={() => setAmount(Math.round(maxRefund * pct / 100 * 100) / 100)}
                                className={clsx("flex-1 text-2xs py-1 rounded-lg border transition-colors",
                                    amount === Math.round(maxRefund * pct / 100 * 100) / 100
                                        ? "bg-brand-500 text-white border-brand-500"
                                        : "border-surface-200 text-surface-600 hover:border-brand-300")}>
                                {pct}%
                            </button>
                        ))}
                    </div>
                </div>

                <div>
                    <label className="label">Reason <span className="text-danger">*</span></label>
                    <textarea value={reason} onChange={(e) => setReason(e.target.value)}
                        className="input resize-none" rows={2} placeholder="Why is this being refunded?" />
                </div>

                {order.shipping_amount > 0 && (
                    <label className="flex items-center gap-2 cursor-pointer text-xs text-surface-700">
                        <input type="checkbox" checked={refundShipping}
                            onChange={(e) => {
                                setRefundShipping(e.target.checked);
                                setAmount(e.target.checked
                                    ? Math.min(maxRefund, amount + order.shipping_amount)
                                    : Math.max(0, amount - order.shipping_amount));
                            }}
                            className="w-4 h-4 rounded border-surface-300" />
                        Include shipping ({fmt(order.shipping_amount, order.currency_code)}) in refund
                    </label>
                )}

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || !reason.trim() || amount <= 0 || amount > maxRefund}
                        className="btn-danger flex-1">
                        {mutation.isPending ? "Processing…" : isPartial
                            ? `Refund ${fmt(amount, order.currency_code)}`
                            : "Full Refund"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Set Shipping Modal ────────────────────────────────────────────────────────
// Lets staff either pick a preconfigured shipping method (fee auto-set)
// or enter a custom amount manually.

function SetShippingFeeModal({ order, onClose, onDone }: {
    order: Order; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();
    const [selectedMethodId, setSelectedMethodId] = useState<number | null>(null);
    const [amount, setAmount]  = useState(order.shipping_amount ?? 0);
    const [note, setNote]      = useState(order.shipping_fee_note ?? "");
    const [mode, setMode]      = useState<"pick" | "custom">("pick");

    const { data: methodsData, isLoading: loadingMethods } = useQuery({
        queryKey: ["shipping-methods"],
        queryFn:  () => shippingApi.methods(),
        staleTime: 5 * 60_000,
    });
    const methods: ShippingMethod[] = methodsData ?? [];

    const selectedMethod = methods.find((m) => m.id === selectedMethodId) ?? null;
    const effectiveAmount = mode === "pick" && selectedMethod
        ? (selectedMethod.cost_type === "free" ? 0 : selectedMethod.flat_rate)
        : amount;

    const mutation = useMutation({
        mutationFn: () => ordersApi.setShippingFee(order.id, {
            amount:            effectiveAmount,
            note:              note || selectedMethod?.name || undefined,
            shipping_method_id: mode === "pick" && selectedMethodId ? selectedMethodId : undefined,
        }),
        onSuccess: () => { toast.success("Shipping fee updated"); onDone(); onClose(); },
        onError:   (e: ApiError) => toast.error(e.message),
    });

    const fmt2 = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

    return (
        <Modal open title="Set Shipping" onClose={onClose}>
            <div className="space-y-4 p-5">
                <p className="text-xs text-surface-500">
                    Select a preconfigured shipping method or enter a custom fee. This updates the order total.
                </p>

                {/* Mode toggle */}
                <div className="flex gap-0 bg-surface-100 rounded-xl p-1">
                    {(["pick", "custom"] as const).map((m) => (
                        <button key={m} onClick={() => setMode(m)}
                            className={clsx("flex-1 py-1.5 text-xs font-semibold rounded-lg transition-all",
                                mode === m ? "bg-white text-surface-900 shadow-sm" : "text-surface-500")}>
                            {m === "pick" ? "Select Method" : "Custom Amount"}
                        </button>
                    ))}
                </div>

                {/* Pick mode - shipping methods list */}
                {mode === "pick" && (
                    <div className="space-y-1.5">
                        {loadingMethods ? (
                            <div className="flex justify-center py-4"><div className="w-5 h-5 border-2 border-brand-400 border-t-transparent rounded-full animate-spin" /></div>
                        ) : methods.length === 0 ? (
                            <div className="text-center py-6 text-xs text-surface-400">
                                <p>No shipping methods configured.</p>
                                <button onClick={() => setMode("custom")} className="mt-1 text-brand-500 hover:underline">
                                    Enter custom amount instead
                                </button>
                            </div>
                        ) : (
                            <>
                                {/* No shipping option */}
                                <button
                                    onClick={() => { setSelectedMethodId(null); setAmount(0); }}
                                    className={clsx(
                                        "w-full flex items-center gap-3 px-3 py-2.5 rounded-xl border text-left transition-all text-xs",
                                        selectedMethodId === null
                                            ? "border-brand-300 bg-brand-50 text-brand-700"
                                            : "border-surface-200 hover:border-surface-300 text-surface-600",
                                    )}>
                                    <span className="flex-1">No shipping / collect in-store</span>
                                    <span className="font-bold text-success">Free</span>
                                    {selectedMethodId === null && (
                                        <svg className="w-3.5 h-3.5 text-brand-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    )}
                                </button>

                                {methods.map((m) => {
                                    const fee = m.cost_type === "free" ? 0 : m.flat_rate;
                                    const isSelected = selectedMethodId === m.id;
                                    return (
                                        <button key={m.id}
                                            onClick={() => { setSelectedMethodId(m.id); setAmount(fee); }}
                                            className={clsx(
                                                "w-full flex items-center gap-3 px-3 py-2.5 rounded-xl border text-left transition-all text-xs",
                                                isSelected
                                                    ? "border-brand-300 bg-brand-50 text-brand-700"
                                                    : "border-surface-200 hover:border-surface-300 text-surface-600",
                                            )}>
                                            <div className="flex-1 min-w-0">
                                                <p className="font-medium truncate">{m.name}</p>
                                                {m.delivery_time && <p className="text-2xs text-surface-400 mt-0.5">{m.delivery_time}</p>}
                                                {m.zone_name && <p className="text-2xs text-surface-400">{m.zone_name}</p>}
                                            </div>
                                            <span className={clsx("font-bold shrink-0", m.cost_type === "free" ? "text-success" : "text-surface-900")}>
                                                {m.cost_type === "free" ? "Free" : `${order.currency_code} ${fmt2(m.flat_rate)}`}
                                            </span>
                                            {isSelected && (
                                                <svg className="w-3.5 h-3.5 text-brand-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                </svg>
                                            )}
                                        </button>
                                    );
                                })}
                            </>
                        )}
                    </div>
                )}

                {/* Custom mode - free-text amount */}
                {mode === "custom" && (
                    <div>
                        <label className="label">Shipping Amount ({order.currency_code})</label>
                        <input type="number" min={0} step={0.01} value={amount}
                            onChange={(e) => setAmount(parseFloat(e.target.value) || 0)}
                            className="input text-lg font-semibold" autoFocus />
                    </div>
                )}

                {/* Fee preview */}
                {(effectiveAmount > 0 || (mode === "pick" && selectedMethod)) && (
                    <div className="flex items-center justify-between bg-surface-50 rounded-xl px-3 py-2.5 text-xs">
                        <span className="text-surface-500">Fee to apply</span>
                        <span className="font-bold text-surface-900">
                            {effectiveAmount === 0 ? "Free" : `${order.currency_code} ${fmt2(effectiveAmount)}`}
                        </span>
                    </div>
                )}

                {/* Note */}
                <div>
                    <label className="label">Note <span className="text-surface-400">(optional)</span></label>
                    <input type="text" value={note} onChange={(e) => setNote(e.target.value)}
                        className="input" placeholder={selectedMethod?.name ?? "e.g. DHL express, 2-3 days"} />
                </div>

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Saving…" : "Apply"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Change Country / Currency Modal ──────────────────────────────────────────
//
// Shown on orders whose payment_status is still "pending" (no payment started).
// Lets staff pick the customer's country; the backend derives the currency and
// sets the is_international flag automatically.

function ChangeCurrencyModal({ order, onClose, onDone }: {
    order: Order; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();

    // Load all active currencies and countries in parallel
    const { data: currData } = useQuery({
        queryKey: ["currencies-active"],
        queryFn:  () => get<{ data: Array<{ id: number; code: string; name: string; is_active: boolean }> }>(
            "/v1/admin/currencies-management"
        ),
        staleTime: 300_000,
    });
    const { data: countryData } = useQuery({
        queryKey:  ["countries-all"],
        queryFn:   () => get<{ data: Array<{ code: string; name: string; flag: string | null; default_currency_code: string | null; is_active: boolean }> }>(
            "/v1/admin/countries"
        ),
        staleTime: 300_000,
    });

    const activeCurrencies = (currData?.data ?? []).filter((c: any) => c.is_active);
    const countries        = (countryData?.data ?? []).filter((c: any) => c.is_active);

    // Derive the currently-stored country code from the order (may be null on old orders)
    const currentCountry   = (order as any).customer_country_code ?? "";
    const [countryCode, setCountryCode] = useState<string>(currentCountry);
    const [search, setSearch] = useState("");

    // Derive the preview currency from the selected country using the same logic
    // as the backend (default_currency_code on the country row; fallback to KES).
    const selectedCountryObj = countries.find((c: any) => c.code === countryCode);
    const previewCurrency = selectedCountryObj?.default_currency_code
        ?? activeCurrencies[0]?.code
        ?? order.currency_code;

    const homeCountry = "KE"; // matches app_country setting default
    const willBeInternational = countryCode !== "" && countryCode !== homeCountry;

    const filteredCountries = search.trim()
        ? countries.filter((c: any) =>
            c.name.toLowerCase().includes(search.toLowerCase()) ||
            c.code.toLowerCase().includes(search.toLowerCase())
          )
        : countries;

    const mutation = useMutation({
        mutationFn: () => ordersApi.updateCurrency(order.id, countryCode),
        onSuccess: (res: any) => {
            if (res.changed) {
                const intlNote = res.is_international ? " - marked as international" : "";
                const totalNote = res.new_total != null
                    ? ` · New total: ${res.currency_code} ${(res.new_total as number).toLocaleString("en-KE", { minimumFractionDigits: 2 })}`
                    : "";
                toast.success(`Currency updated to ${res.currency_code}${intlNote}${totalNote}`);
            } else {
                toast.info("No change - country already matches.");
            }
            onDone();
            onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const canSave = countryCode.trim().length === 2 && countryCode !== currentCountry;

    return (
        <Modal open title="Change Country / Currency" onClose={onClose}>
            <div className="space-y-4 p-5">
                <p className="text-xs text-surface-500">
                    Select the country the customer is paying from. The currency will be set
                    automatically based on that country's default, and the order will be flagged
                    as international if the country differs from the store's home country.
                </p>
                <div className="flex items-start gap-2 bg-teal-50 border border-teal-200 rounded-xl px-3 py-2.5 text-2xs text-teal-800">
                    <svg className="w-3.5 h-3.5 text-teal-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                    </svg>
                    <span>All item prices will be <strong>repriced</strong> to the new currency - either from a direct price entry or by converting from the base currency rate. The order total will be recalculated and logged in the audit trail.</span>
                </div>

                {/* Current values */}
                <div className="flex items-center gap-3 bg-surface-50 rounded-xl px-3 py-2.5 text-xs">
                    <div className="flex-1">
                        <p className="text-surface-400">Current</p>
                        <p className="font-semibold text-surface-900">
                            {currentCountry
                                ? `${countries.find((c: any) => c.code === currentCountry)?.name ?? currentCountry} · ${order.currency_code}`
                                : `${order.currency_code} (country not set)`}
                        </p>
                    </div>
                    {canSave && (
                        <>
                            <svg className="w-4 h-4 text-surface-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                            </svg>
                            <div className="flex-1 text-right">
                                <p className="text-surface-400">New</p>
                                <p className="font-semibold text-surface-900">
                                    {selectedCountryObj?.name ?? countryCode} · {previewCurrency}
                                    {willBeInternational && (
                                        <span className="ml-1.5 text-blue-600">🌐</span>
                                    )}
                                </p>
                            </div>
                        </>
                    )}
                </div>

                {/* International warning */}
                {canSave && willBeInternational && (
                    <div className="flex items-start gap-2.5 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
                        <svg className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                        <p className="text-2xs text-amber-800 leading-snug">
                            <span className="font-bold">International order.</span>{" "}
                            Any payment made via a non-integrated method (bank transfer, cheque, etc.)
                            will require admin approval before the order can proceed.
                        </p>
                    </div>
                )}

                {/* Country search */}
                <div>
                    <label className="label">Country</label>
                    <input
                        type="search"
                        placeholder="Search country…"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="input mb-2"
                        autoFocus
                    />
                    <div className="max-h-52 overflow-y-auto border border-surface-200 rounded-xl divide-y divide-surface-100">
                        {filteredCountries.length === 0 ? (
                            <p className="text-xs text-surface-400 italic px-3 py-4 text-center">No countries found.</p>
                        ) : filteredCountries.map((c: any) => (
                            <button
                                key={c.code}
                                onClick={() => setCountryCode(c.code)}
                                className={clsx(
                                    "w-full flex items-center gap-2.5 px-3 py-2 text-left text-xs transition-colors",
                                    countryCode === c.code
                                        ? "bg-brand-50 text-brand-700 font-semibold"
                                        : "text-surface-700 hover:bg-surface-50"
                                )}
                            >
                                {c.flag && <span className="text-base shrink-0">{c.flag}</span>}
                                <span className="flex-1 truncate">{c.name}</span>
                                <span className="text-2xs font-mono text-surface-400 shrink-0">{c.code}</span>
                                {c.default_currency_code && (
                                    <span className="text-2xs font-semibold text-surface-500 shrink-0">{c.default_currency_code}</span>
                                )}
                                {countryCode === c.code && (
                                    <svg className="w-3.5 h-3.5 text-brand-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                )}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Active currencies note */}
                {activeCurrencies.length > 0 && (
                    <p className="text-2xs text-surface-400">
                        Active currencies: {activeCurrencies.map((c: any) => c.code).join(", ")}
                    </p>
                )}

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || !canSave}
                        className="btn-primary flex-1 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {mutation.isPending ? "Saving…" : `Apply - ${previewCurrency}`}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Set Deposit Modal ─────────────────────────────────────────────────────────

function SetDepositModal({ order, onClose, onDone }: {
    order: Order; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();
    const [depositAmount, setDepositAmount] = useState(
        order.deposit_amount ?? Math.round(order.total_amount * 0.5 * 100) / 100
    );
    const [balanceDueDate, setBalanceDueDate] = useState(order.balance_due_date ?? "");

    const mutation = useMutation({
        mutationFn: () => ordersApi.setDeposit(order.id, {
            deposit_amount: depositAmount,
            balance_due_date: balanceDueDate || undefined,
        }),
        onSuccess: () => { toast.success("Deposit terms saved"); onDone(); onClose(); },
        onError:   (e: ApiError) => toast.error(e.message),
    });

    const cc = order.currency_code;
    const presets = [25, 30, 50].map(pct => ({
        pct,
        val: Math.round(order.total_amount * pct / 100 * 100) / 100,
    }));

    return (
        <Modal open title="Set Deposit Terms" onClose={onClose}>
            <div className="space-y-4 p-5">
                <p className="text-xs text-surface-500">
                    The customer will pay this deposit now and settle the balance later.
                </p>
                <div>
                    <label className="label">Minimum Deposit ({cc})</label>
                    <input type="number" min={0.01} step={0.01}
                        max={order.total_amount - 0.01}
                        value={depositAmount}
                        onChange={(e) => setDepositAmount(parseFloat(e.target.value) || 0)}
                        className="input text-lg font-semibold" autoFocus />
                    <div className="flex gap-2 mt-2">
                        {presets.map(({ pct, val }) => (
                            <button key={pct} onClick={() => setDepositAmount(val)}
                                className={clsx("flex-1 text-2xs py-1.5 rounded-lg border transition-colors",
                                    depositAmount === val
                                        ? "bg-brand-500 text-white border-brand-500"
                                        : "border-surface-200 text-surface-600 hover:border-brand-300")}>
                                {pct}% ({fmt(val, cc)})
                            </button>
                        ))}
                    </div>
                </div>
                <div>
                    <label className="label">Balance Due Date <span className="text-surface-400">(optional)</span></label>
                    <input type="date" value={balanceDueDate}
                        onChange={(e) => setBalanceDueDate(e.target.value)}
                        className="input" />
                </div>
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || depositAmount <= 0 || depositAmount >= order.total_amount}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Saving…" : "Save Deposit Terms"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Add Payment Modal ─────────────────────────────────────────────────────────

function AddPaymentModal({ order, onClose, onDone }: {
    order: Order; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();
    const totalPaid = order.payments
        ?.filter((p: any) => p.status === "paid")
        .reduce((s: number, p: any) => s + Number(p.amount), 0) ?? 0;
    const outstanding = Math.max(0, order.total_amount - totalPaid);

    const [method, setMethod]                     = useState("");
    const [customMethodName, setCustomMethodName] = useState("");
    const [amount, setAmount]                     = useState(outstanding);
    const [reference, setReference]               = useState("");
    const [phone, setPhone]                       = useState(order.customer_phone ?? "");
    const taxInclusive = order.prices_include_tax ?? true; // derived from order - not user-editable
    const [payNotes, setPayNotes]                 = useState("");
    const [proofFile, setProofFile]               = useState<File | null>(null);
    const [proofOriginalSize, setProofOriginalSize] = useState<number>(0);
    const [proofCompressing,  setProofCompressing]  = useState(false);
    const proofInputRef = useRef<HTMLInputElement>(null);

    const handleProofFileSelect = async (f: File) => {
        setProofOriginalSize(f.size);
        if (f.type.startsWith("image/")) {
            setProofCompressing(true);
            try {
                const { compressImage } = await import("@/utils/compressImage");
                const compressed = await compressImage(f);
                setProofFile(compressed);
            } catch {
                setProofFile(f);
            } finally {
                setProofCompressing(false);
            }
        } else {
            setProofFile(f);
        }
    };

    // ── M-Pesa STK state ──────────────────────────────────────────────────────
    type StkStep = "idle" | "waiting" | "confirmed" | "failed";
    const [stkStep, setStkStep]     = useState<StkStep>("idle");
    const [stkError, setStkError]   = useState("");
    const [pushing, setPushing]     = useState(false);
    const [manualRef, setManualRef] = useState("");
    const [verifying, setVerifying] = useState(false);
    const [verifyMsg, setVerifyMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const pollRef   = useRef<ReturnType<typeof setInterval>>();
    const pollCount = useRef(0);
    const MAX_POLLS = 18;
    const stopPolling = () => { if (pollRef.current) clearInterval(pollRef.current); };
    useEffect(() => () => stopPolling(), []);

    // ── Paystack state ────────────────────────────────────────────────────────
    const [paystackEmail, setPaystackEmail]   = useState(order.customer_email ?? "");
    const [paystackUrl, setPaystackUrl]       = useState<string | null>(null);
    const [paystackRef, setPaystackRef]       = useState("");
    const [paystackInit, setPaystackInit]     = useState(false);
    const [paystackError, setPaystackError]   = useState("");

    // Fetch active configured payment methods from the database
    const { data: methodsData, isLoading: methodsLoading } = useQuery({
        queryKey: ["payment-methods"],
        queryFn:  () => paymentMethodsApi.list(),
        staleTime: 60_000,
    });

    const configuredMethods: PaymentMethodSetup[] = (methodsData?.data ?? []).filter(
        (m: PaymentMethodSetup) => {
            if (!m.is_active) return false;
            // Only offer methods that support the order's currency.
            // An empty supported_currencies array means "all currencies".
            const supported = (m as any).supported_currencies as string[] | null | undefined;
            return !supported || supported.length === 0 || supported.includes(order.currency_code);
        }
    );

    const OTHER_CODE = "__other__";
    const allMethods = [
        ...configuredMethods,
        { code: OTHER_CODE, name: "Other", type: "other", is_active: true, is_default: false, requires_approval: true } as any,
    ];

    useEffect(() => {
        if (method === "" && configuredMethods.length > 0) {
            setMethod(configuredMethods[0].code);
        }
    }, [configuredMethods, method]);

    const cc              = order.currency_code;
    const effectiveMethod = method === OTHER_CODE ? "other" : method;
    // Approval policy comes from the backend's effective `requires_approval` flag
    // on the selected method — the single source of truth shared with the server,
    // so I&M (settles instantly) and manual rails (cheque/bank/WU/MoneyGram) are
    // classified identically here and server-side. Fall back to the legacy list
    // only if the flag is missing (older API).
    const selectedMethodObj = allMethods.find((m: any) => m.code === method);
    const methodRequiresApproval = selectedMethodObj?.requires_approval
        ?? !["cash", "mpesa", "card", "card_paystack", "card_flutterwave", "paystack"].includes(effectiveMethod);
    const isAutomated     = !methodRequiresApproval;
    const isManualMethod  = methodRequiresApproval;
    const isOtherMethod   = method === OTHER_CODE;
    const isBankMethod    = effectiveMethod === "bank_transfer";
    const isMpesaMethod   = effectiveMethod === "mpesa";
    const isPaystackMethod = method === "paystack" || effectiveMethod === "card_paystack";
    const needsApproval   = methodRequiresApproval;
    const showProofUpload = isManualMethod;
    const referenceRequired = isBankMethod || isOtherMethod;
    const canSubmit = amount > 0
        && (!referenceRequired || reference.trim().length > 0)
        && (!isOtherMethod || customMethodName.trim().length > 0)
        // For Paystack after page opened, require a reference
        && (!isPaystackMethod || !paystackUrl || paystackRef.trim().length > 0);

    // Tax on this payment is proportional to the order's tax share of the total.
    // We use the stored tax_amount/total_amount ratio so it works with mixed per-product rates.
    const orderTaxRatio  = order.total_amount > 0 ? (order.tax_amount ?? 0) / order.total_amount : 0;
    const taxOnAmount    = !taxInclusive && orderTaxRatio > 0 ? Math.round(amount * orderTaxRatio * 100) / 100 : 0;
    const fmt = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

    // ── M-Pesa STK Push ───────────────────────────────────────────────────────
    const initiateStkPush = async () => {
        setPushing(true); setStkError("");
        try {
            await post(`/v1/admin/orders/${order.id}/payment`, {
                payment_method: "mpesa",
                phone: phone.trim(),
            });
            setStkStep("waiting");
            pollCount.current = 0;
            pollRef.current = setInterval(async () => {
                pollCount.current += 1;
                if (pollCount.current >= MAX_POLLS) {
                    stopPolling(); setStkStep("failed");
                    setStkError("No confirmation received within 3 minutes. Use the transaction code below to complete manually.");
                    return;
                }
                try {
                    const res = await get<any>(`/v1/admin/orders/${order.id}`);
                    const status = res?.order?.payment_status ?? res?.payment_status;
                    if (status === "paid" || status === "partial") {
                        stopPolling(); setStkStep("confirmed");
                        toast.success("M-Pesa payment confirmed!");
                        setTimeout(() => { onDone(); onClose(); }, 1200);
                    }
                } catch { /* ignore */ }
            }, 10_000);
        } catch (e: any) {
            setStkError(e.message ?? "Failed to send STK push. Check the phone number and try again.");
            setStkStep("idle");
        } finally {
            setPushing(false);
        }
    };

    // ── M-Pesa manual Daraja verify ───────────────────────────────────────────
    const verifyManual = async () => {
        if (!manualRef.trim()) return;
        setVerifying(true); setVerifyMsg(null);
        try {
            const orderRes = await get<any>(`/v1/admin/orders/${order.id}`);
            const pendingPayment = orderRes?.order?.payments?.find(
                (p: any) => p.status === "pending" && p.payment_method === "mpesa"
            );
            if (pendingPayment) {
                await post(
                    `/v1/admin/orders/${order.id}/payments/${pendingPayment.id}/verify-mpesa`,
                    { code: manualRef.trim() }
                );
                stopPolling(); setStkStep("confirmed");
                setVerifyMsg({ ok: true, text: "Verified with Daraja - payment confirmed!" });
                toast.success("Payment verified via Daraja!");
                setTimeout(() => { onDone(); onClose(); }, 1200);
                return;
            }
            // No pending payment - fall back to recording with the code as reference
            recordMpesaManual();
        } catch {
            setVerifyMsg({ ok: false, text: "Daraja verification failed. Recording as manual confirmation." });
            recordMpesaManual();
        } finally {
            setVerifying(false);
        }
    };

    const recordMpesaManual = () => {
        setReference(manualRef);
        manualMutation.mutate({ ref: manualRef });
    };

    // ── Record payment mutation (manual/cash/bank/other) ─────────────────────
    const manualMutation = useMutation({
        mutationFn: async ({ ref }: { ref?: string } = {}) => {
            const res = await ordersApi.addPayment(order.id, {
                method:             effectiveMethod,
                amount,
                reference:          (ref ?? reference) || undefined,
                phone:              phone || undefined,
                tax_inclusive:      taxInclusive,
                notes:              payNotes || undefined,
                custom_method_name: isOtherMethod ? customMethodName : undefined,
            });
            if (proofFile && res.payment?.id) {
                const { compressImage } = await import("@/utils/compressImage");
                const fileToUpload = await compressImage(proofFile).catch(() => proofFile);
                const form = new FormData();
                form.append("proof", fileToUpload);
                await import("@/api/client").then(({ api }) =>
                    api.post(`/v1/admin/payments/${res.payment.id}/upload-proof`, form, {
                        headers: { "Content-Type": "multipart/form-data" },
                    })
                );
            }
            return res;
        },
        onSuccess: (res: any) => {
            // Trust the server's classification, not the local guess, so the
            // message can never contradict how the payment was actually recorded.
            const serverNeedsApproval = res?.requires_approval ?? needsApproval;
            toast.success(serverNeedsApproval
                ? "Payment submitted - awaiting admin approval before it takes effect"
                : "Payment recorded"
            );
            onDone(); onClose();
        },
        onError: (e: ApiError) => {
            // 'overpayment' means the webhook already paid it — treat as success
            if ((e as any)?.reason === "overpayment" || e.message?.includes("already been fully paid")) {
                toast.success("Payment already confirmed by Paystack ✓");
                onDone(); onClose();
            } else {
                toast.error(e.message);
            }
        },
    });

    // ── Paystack initiation ───────────────────────────────────────────────────
    const initiatePaystack = async () => {
        if (!paystackEmail.trim()) return;
        setPaystackInit(true); setPaystackError("");
        try {
            const res = await post<{ authorization_url: string }>(`/v1/admin/orders/${order.id}/payment`, {
                payment_method: effectiveMethod,
                email: paystackEmail.trim(),
            });
            if ((res as any).authorization_url) {
                setPaystackUrl((res as any).authorization_url);
                window.open((res as any).authorization_url, "_blank", "noopener");
                startPaystackPoll();
            }
        } catch (e: any) {
            setPaystackError(e.message ?? "Could not initiate Paystack payment");
        } finally {
            setPaystackInit(false);
        }
    };

    const paystackPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const startPaystackPoll = () => {
        if (paystackPollRef.current) clearInterval(paystackPollRef.current);
        let polls = 0;
        paystackPollRef.current = setInterval(async () => {
            polls++;
            try {
                const latest = await get<any>(`/v1/admin/orders/${order.id}`);
                const status = latest?.order?.payment_status ?? latest?.payment_status;
                if (status === "paid") {
                    clearInterval(paystackPollRef.current!);
                    paystackPollRef.current = null;
                    toast.success("Paystack payment confirmed ✓");
                    onDone(); onClose();
                }
            } catch { /* ignore */ }
            if (polls >= 24) {
                clearInterval(paystackPollRef.current!);
                paystackPollRef.current = null;
            }
        }, 5000);
    };

    // ── Reset per-method state on switch ─────────────────────────────────────
    const switchMethod = (code: string) => {
        setMethod(code);
        setReference(""); setManualRef(""); setCustomMethodName("");
        setStkStep("idle"); setStkError(""); setVerifyMsg(null);
        setPaystackUrl(null); setPaystackRef(""); setPaystackError("");
        stopPolling();
        if (paystackPollRef.current) { clearInterval(paystackPollRef.current); paystackPollRef.current = null; }
    };

    const handleSubmit = async () => {
        if (isPaystackMethod && paystackUrl) {
            // Before recording, check if webhook already paid this order
            try {
                const latest = await get<any>(`/v1/admin/orders/${order.id}`);
                const currentStatus = latest?.order?.payment_status ?? latest?.payment_status;
                if (currentStatus === "paid") {
                    toast.success("Payment already confirmed via Paystack ✓");
                    onDone(); onClose();
                    return;
                }
            } catch { /* ignore — proceed to manual record */ }
            manualMutation.mutate({ ref: paystackRef });
        } else {
            manualMutation.mutate({});
        }
    };

    return (
        <Modal open title="Record Payment" onClose={onClose}>
            <div className="space-y-4 p-5">

                {/* Balance summary */}
                <div className="bg-surface-50 rounded-xl p-3 flex items-center justify-between text-xs">
                    <span className="text-surface-500">Outstanding balance</span>
                    <span className={clsx("font-bold text-base", outstanding > 0 ? "text-danger" : "text-success")}>
                        {cc} {fmt(outstanding)}
                    </span>
                </div>

                {/* Deposit reminder */}
                {order.deposit_amount && order.payment_status === "pending" && (
                    <div className="bg-info-light rounded-xl px-3 py-2 text-xs text-info flex items-start gap-1.5">
                        <svg className="w-3.5 h-3.5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <span>Minimum deposit: <strong>{cc} {fmt(order.deposit_amount)}</strong>
                        {order.balance_due_date && (
                            <> · Balance due {new Date(order.balance_due_date).toLocaleDateString("en-KE", { dateStyle: "medium" })}</>
                        )}
                        </span>
                    </div>
                )}

                {/* Method grid - configured methods + Other */}
                <div>
                    <label className="label">Payment Method</label>
                    {methodsLoading ? (
                        <div className="flex items-center gap-2 py-3 text-xs text-surface-400">
                            <Spinner size="xs" /> Loading configured methods…
                        </div>
                    ) : configuredMethods.length === 0 ? (
                        <p className="text-xs text-warning-dark bg-warning-light rounded-xl px-3 py-2">
                            No active payment methods configured.{" "}
                            <Link to="/settings/payment-methods" className="font-semibold underline">Set them up →</Link>
                        </p>
                    ) : (
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {allMethods.map((pm: any) => {
                                const isOther = pm.code === OTHER_CODE;
                                const icon    = isOther                    ? "💰"
                                    : pm.type === "mobile_money"           ? "📱"
                                    : pm.type === "card"                   ? "💳"
                                    : pm.type === "cash"                   ? "💵"
                                    : pm.type === "bank_transfer"          ? "🏦" : "💰";
                                const isSelected = method === pm.code;
                                const pmIsManual = pm.requires_approval
                                    ?? !["cash","mpesa","card","card_paystack","card_flutterwave","paystack"]
                                        .includes(pm.code === OTHER_CODE ? "other" : pm.code);
                                return (
                                    <button key={pm.code} onClick={() => switchMethod(pm.code)}
                                        className={clsx(
                                            "flex flex-col items-center gap-1 py-3 rounded-xl border text-xs font-medium transition-all",
                                            isSelected
                                                ? "border-brand-500 bg-brand-50 text-brand-700"
                                                : "border-surface-200 text-surface-500 hover:border-brand-300"
                                        )}>
                                        <span className="text-lg">{icon}</span>
                                        <span className="truncate max-w-full px-1 text-center">{pm.name}</span>
                                        {isSelected && pmIsManual && (
                                            <span className="text-2xs text-amber-600 font-semibold">Needs approval</span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Approval notice - ALL manual methods */}
                {needsApproval && method !== "" && (
                    <div className="flex items-start gap-2.5 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
                        <svg className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                        <p className="text-2xs text-amber-800 leading-snug">
                            <span className="font-bold">Requires admin approval.</span>{" "}
                            This payment will stay pending until an administrator reviews and approves it.
                            The order cannot be completed until all payments are approved.
                        </p>
                    </div>
                )}

                {/* Amount */}
                <div>
                    <label className="label">Amount ({cc})</label>
                    <input type="number" min={0.01} step={0.01} value={amount}
                        onChange={(e) => setAmount(parseFloat(e.target.value) || 0)}
                        className="input text-lg font-semibold" autoFocus />
                    {outstanding > 0 && amount < outstanding && amount > 0 && (
                        <p className="text-2xs text-warning-dark mt-1">
                            Partial payment - {cc} {fmt(outstanding - amount)} will remain outstanding
                        </p>
                    )}
                </div>

                {/* Tax note - read-only, derived from order settings */}
                {order.tax_amount > 0 && (
                    <div className="rounded-xl bg-surface-50 border border-surface-100 px-3 py-2.5 text-2xs text-surface-500">
                        {taxInclusive
                            ? <>Tax ({cc} {fmt(order.tax_amount)}) is <strong>included</strong> in the prices above.</>
                            : <>Tax ({cc} {fmt(taxOnAmount > 0 ? taxOnAmount : order.tax_amount)}) is <strong>added on top</strong> of the net price.</>
                        }
                    </div>
                )}

                {/* ── M-PESA: STK Push + Transaction Code (same UI as POS) ─── */}
                {isMpesaMethod && (
                    <div className="space-y-0">

                        {/* Section 1: STK Push */}
                        <div className="rounded-xl border border-green-200 bg-green-50/60 overflow-hidden">
                            <div className="flex items-center gap-2.5 px-4 py-2.5 border-b border-green-200/70 bg-green-100/50">
                                <div className="w-6 h-6 rounded-lg bg-green-600 flex items-center justify-center shrink-0">
                                    <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 8.25h3m-3 4.5h3"/>
                                    </svg>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-bold text-green-900">STK Push</p>
                                    <p className="text-2xs text-green-700">Send a payment prompt directly to the customer's phone</p>
                                </div>
                                {stkStep === "waiting" && (
                                    <span className="flex items-center gap-1 text-2xs text-green-700 font-semibold shrink-0">
                                        <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse" />Waiting…
                                    </span>
                                )}
                                {stkStep === "confirmed" && (
                                    <span className="flex items-center gap-1 text-2xs text-success font-semibold shrink-0">
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                        Confirmed
                                    </span>
                                )}
                            </div>

                            <div className="px-4 py-3 space-y-3">
                                {stkStep === "confirmed" && (
                                    <div className="flex items-center gap-3 py-2">
                                        <div className="w-9 h-9 rounded-full bg-success flex items-center justify-center shrink-0">
                                            <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                        </div>
                                        <div>
                                            <p className="text-sm font-bold text-success-dark">M-Pesa Payment Confirmed!</p>
                                            <p className="text-2xs text-success-dark/70">Refreshing order…</p>
                                        </div>
                                    </div>
                                )}
                                {stkStep === "waiting" && (
                                    <div className="space-y-2.5">
                                        <div className="flex items-center gap-3 bg-green-100 rounded-xl px-3 py-2.5">
                                            <div className="w-7 h-7 border-[2.5px] border-green-500 border-t-transparent rounded-full animate-spin shrink-0" />
                                            <div className="flex-1 min-w-0">
                                                <p className="text-xs font-semibold text-green-800">Prompt sent to {phone}</p>
                                                <p className="text-2xs text-green-600">Ask the customer to enter their M-Pesa PIN now.</p>
                                            </div>
                                        </div>
                                        <button onClick={() => { stopPolling(); setStkStep("idle"); setStkError(""); }}
                                            className="w-full text-xs text-surface-400 hover:text-danger transition-colors py-1 underline underline-offset-2">
                                            Cancel STK push
                                        </button>
                                    </div>
                                )}
                                {(stkStep === "idle" || stkStep === "failed") && (
                                    <>
                                        {stkError && (
                                            <div className="bg-danger-light rounded-lg px-3 py-2 text-2xs text-danger leading-snug">{stkError}</div>
                                        )}
                                        <div className="flex gap-2">
                                            <input type="tel" value={phone} onChange={e => setPhone(e.target.value)}
                                                placeholder="+254 700 000 000"
                                                className="input text-sm font-mono flex-1 min-w-0" />
                                            <button onClick={initiateStkPush} disabled={pushing || !phone.trim()}
                                                className="shrink-0 px-4 py-2 rounded-xl text-xs font-bold text-white bg-green-600 hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex items-center gap-1.5 whitespace-nowrap">
                                                {pushing
                                                    ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                                    : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                                }
                                                {pushing ? "Sending…" : "Send Push"}
                                            </button>
                                        </div>
                                        <p className="text-2xs text-green-700/70">Customer will receive a prompt on their phone to enter their PIN.</p>
                                    </>
                                )}
                            </div>
                        </div>

                        {/* Divider */}
                        <div className="relative flex items-center py-3">
                            <div className="flex-1 border-t border-surface-150" />
                            <span className="mx-3 text-2xs font-semibold text-surface-400 uppercase tracking-widest bg-white px-1">or confirm with transaction code</span>
                            <div className="flex-1 border-t border-surface-150" />
                        </div>

                        {/* Section 2: Manual transaction code + Daraja verify */}
                        <div className="rounded-xl border border-surface-200 bg-white overflow-hidden">
                            <div className="flex items-center gap-2.5 px-4 py-2.5 border-b border-surface-100 bg-surface-50">
                                <div className="w-6 h-6 rounded-lg bg-surface-700 flex items-center justify-center shrink-0">
                                    <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/>
                                    </svg>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-bold text-surface-900">Transaction Code</p>
                                    <p className="text-2xs text-surface-500">Enter code from customer's SMS - verified with Daraja before confirming</p>
                                </div>
                            </div>
                            <div className="px-4 py-3 space-y-3">
                                {verifyMsg && (
                                    <div className={clsx("rounded-lg px-3 py-2 text-2xs leading-snug font-medium",
                                        verifyMsg.ok ? "bg-success-light text-success-dark" : "bg-warning-light text-warning-dark")}>
                                        {verifyMsg.text}
                                    </div>
                                )}
                                <div className="flex gap-2">
                                    <input type="text" value={manualRef}
                                        onChange={e => setManualRef(e.target.value.toUpperCase())}
                                        placeholder="e.g. QHK7XXXXXYZ"
                                        className="input font-mono tracking-widest text-sm flex-1 min-w-0"
                                        disabled={stkStep === "confirmed"} />
                                    <button onClick={verifyManual}
                                        disabled={verifying || !manualRef.trim() || stkStep === "confirmed"}
                                        className="shrink-0 px-4 py-2 rounded-xl text-xs font-bold text-white bg-surface-800 hover:bg-surface-900 disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex items-center gap-1.5 whitespace-nowrap">
                                        {verifying
                                            ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                            : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                        }
                                        {verifying ? "Checking…" : "Verify & Confirm"}
                                    </button>
                                </div>
                                <p className="text-2xs text-surface-400">Code will be validated with Safaricom Daraja before the payment is recorded.</p>
                            </div>
                        </div>
                    </div>
                )}

                {/* ── PAYSTACK: Email → open page → confirm with reference ─── */}
                {isPaystackMethod && (
                    <div className="space-y-3">
                        {!paystackUrl ? (
                            <>
                                <div className="bg-blue-50 border border-blue-200 rounded-xl px-3 py-2.5 text-xs text-blue-700">
                                    <p className="font-semibold">Paystack card payment</p>
                                    <p className="mt-0.5 opacity-80">Enter the customer's email to open a secure Paystack payment page. The customer completes payment there - no card details pass through this system.</p>
                                </div>
                                <div>
                                    <label className="label">Customer Email <span className="text-danger">*</span></label>
                                    <input type="email" value={paystackEmail}
                                        onChange={e => setPaystackEmail(e.target.value)}
                                        placeholder="customer@example.com" className="input" autoFocus />
                                </div>
                                {paystackError && (
                                    <div className="bg-danger-light rounded-xl px-3 py-2 text-xs text-danger">{paystackError}</div>
                                )}
                                <button onClick={initiatePaystack} disabled={paystackInit || !paystackEmail.trim()}
                                    className="btn-primary w-full gap-2 disabled:opacity-50">
                                    {paystackInit
                                        ? <><div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />Opening Paystack…</>
                                        : <><svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>Open Paystack Payment Page →</>}
                                </button>
                            </>
                        ) : (
                            <>
                                <div className="bg-info-light border border-info/30 rounded-xl px-4 py-3 text-xs text-info">
                                    <p className="font-semibold">Paystack payment page opened</p>
                                    <p className="mt-0.5 opacity-80">Ask the customer to complete payment on the Paystack page. Once done, enter the Paystack reference code below to confirm.</p>
                                </div>
                                <button onClick={() => window.open(paystackUrl, "_blank", "noopener")}
                                    className="btn-secondary w-full text-xs gap-2">
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    Re-open Paystack page
                                </button>
                                <div>
                                    <label className="label">Paystack Reference <span className="text-danger">*</span></label>
                                    <input type="text" value={paystackRef} onChange={e => setPaystackRef(e.target.value)}
                                        className="input font-mono" placeholder="e.g. T123456789" autoFocus />
                                    <p className="mt-1 text-2xs text-surface-400">Copy the transaction reference from the Paystack confirmation page or SMS.</p>
                                </div>
                            </>
                        )}
                    </div>
                )}

                {/* Card reference (optional, non-Paystack card) */}
                {effectiveMethod === "card" && !isPaystackMethod && (
                    <div>
                        <label className="label">Approval Code <span className="text-surface-400">(optional)</span></label>
                        <input type="text" value={reference} onChange={(e) => setReference(e.target.value)}
                            className="input" placeholder="Terminal approval code" />
                    </div>
                )}

                {/* Cash - no extra fields needed */}

                {/* Bank transfer - required reference */}
                {isBankMethod && (
                    <div>
                        <label className="label">Transfer Reference <span className="text-danger">*</span></label>
                        <input type="text" value={reference} onChange={(e) => setReference(e.target.value)}
                            className={clsx("input", !reference.trim() && "border-warning")}
                            placeholder="Bank transfer / RTGS reference number" />
                        {!reference.trim() && (
                            <p className="text-2xs text-warning-dark mt-1">Required - needed for the approval request.</p>
                        )}
                    </div>
                )}

                {/* Other method - custom name (required) + reference (required) */}
                {isOtherMethod && (
                    <>
                        <div>
                            <label className="label">Payment Method Name <span className="text-danger">*</span></label>
                            <input type="text" value={customMethodName} onChange={(e) => setCustomMethodName(e.target.value)}
                                className={clsx("input", !customMethodName.trim() && "border-warning")}
                                placeholder="e.g. Cheque, Wire Transfer, PayPal, RTGS…" />
                            {!customMethodName.trim() && (
                                <p className="text-2xs text-warning-dark mt-1">Specify exactly how the customer is paying.</p>
                            )}
                        </div>
                        <div>
                            <label className="label">Payment Reference <span className="text-danger">*</span></label>
                            <input type="text" value={reference} onChange={(e) => setReference(e.target.value)}
                                className={clsx("input", !reference.trim() && "border-warning")}
                                placeholder="Transaction ID, cheque number, or confirmation code…" />
                            {!reference.trim() && (
                                <p className="text-2xs text-warning-dark mt-1">Required - needed for the approval request.</p>
                            )}
                        </div>
                    </>
                )}

                {/* Proof of payment - shown for all manual methods */}
                {showProofUpload && (
                    <div className={clsx(
                        "rounded-xl border-2 border-dashed p-4 space-y-2 transition-colors",
                        proofFile ? "border-success bg-success-light/30" : "border-surface-200 bg-surface-50"
                    )}>
                        <div className="flex items-start gap-3">
                            <div className={clsx("w-9 h-9 rounded-lg flex items-center justify-center shrink-0",
                                proofFile ? "bg-success text-white" : "bg-surface-200 text-surface-500")}>
                                {proofFile
                                    ? <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                                }
                            </div>
                            <div className="flex-1 min-w-0">
                                <p className="text-xs font-semibold text-surface-900">
                                    Proof of Payment
                                    <span className="ml-1.5 text-2xs font-normal text-surface-400">(PDF or image, max 10 MB)</span>
                                    <span className="ml-1.5 text-2xs font-semibold text-amber-700">- required for approval</span>
                                </p>
                                {proofCompressing ? (
                                    <div className="flex items-center gap-1.5 mt-1">
                                        <svg className="w-3 h-3 animate-spin text-brand-500" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                                        <p className="text-2xs text-brand-600 font-medium">Compressing image…</p>
                                    </div>
                                ) : proofFile ? (
                                    <div className="flex items-center gap-2 mt-1">
                                        <p className="text-2xs text-success-dark font-medium truncate">{proofFile.name}</p>
                                        {proofOriginalSize > proofFile.size && (
                                            <span className="text-2xs text-surface-400 shrink-0">
                                                {Math.round(proofOriginalSize / 1024)}KB → {Math.round(proofFile.size / 1024)}KB
                                            </span>
                                        )}
                                        <button onClick={() => { setProofFile(null); setProofOriginalSize(0); }} className="text-2xs text-danger hover:underline shrink-0">Remove</button>
                                    </div>
                                ) : (
                                    <p className="text-2xs text-surface-400 mt-0.5">Attach a bank receipt, wire confirmation, or screenshot. The admin cannot approve without proof.</p>
                                )}
                            </div>
                        </div>
                        <input ref={proofInputRef} type="file" accept=".pdf,image/*" className="hidden"
                            onChange={e => { const f = e.target.files?.[0]; if (f) handleProofFileSelect(f); e.target.value = ""; }} />
                        {!proofFile && (
                            <button onClick={() => proofInputRef.current?.click()} className="w-full btn-secondary btn-sm">Choose File</button>
                        )}
                    </div>
                )}

                {/* Optional note */}
                <div>
                    <label className="label">Note <span className="text-surface-400">(optional)</span></label>
                    <input type="text" value={payNotes} onChange={(e) => setPayNotes(e.target.value)}
                        className="input" placeholder="e.g. Cash received at counter" />
                </div>

                {/* Submit button - hidden for M-Pesa (handled by panel buttons) */}
                {!isMpesaMethod && (
                    <div className="flex gap-3">
                        <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                        <button onClick={handleSubmit}
                            disabled={manualMutation.isPending || !canSubmit}
                            className="btn-primary flex-1 disabled:opacity-40 disabled:cursor-not-allowed">
                            {manualMutation.isPending
                                ? "Recording…"
                                : isPaystackMethod && paystackUrl ? "Confirm Paystack Payment"
                                : needsApproval ? "Submit for Approval" : "Record Payment"}
                        </button>
                    </div>
                )}
                {isMpesaMethod && (
                    <button onClick={onClose} className="btn-secondary w-full">Cancel</button>
                )}
            </div>
        </Modal>
    );
}



// ── Receipt / Invoice Modal ───────────────────────────────────────────────────
// Supports two modes:
//   "order"   - full thermal receipt showing all items, totals, tax breakdown,
//               payment details (incl. M-PESA TRN, cash tendered/change),
//               amount in words, savings, QR code - matches POS receipt format.
//   "payment" - individual payment receipt for a single payment record.

type ReceiptMode = "order" | "payment";

// ── Receipt helpers (shared with POS ReceiptModal) ────────────────────────────

function receiptNumberToWords(amount: number): string {
    const ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine",
        "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen",
        "Eighteen", "Nineteen"];
    const tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];
    function below1000(n: number): string {
        if (n === 0) return "";
        if (n < 20) return ones[n];
        if (n < 100) return tens[Math.floor(n / 10)] + (n % 10 ? " " + ones[n % 10] : "");
        return ones[Math.floor(n / 100)] + " Hundred" + (n % 100 ? " And " + below1000(n % 100) : "");
    }
    const intPart = Math.floor(amount);
    const centsPart = Math.round((amount - intPart) * 100);
    if (intPart === 0 && centsPart === 0) return "Zero Shillings Only";
    let words = "";
    if (intPart >= 1_000_000) words += below1000(Math.floor(intPart / 1_000_000)) + " Million ";
    if (intPart >= 1_000)     words += below1000(Math.floor((intPart % 1_000_000) / 1_000)) + " Thousand ";
    words += below1000(intPart % 1_000);
    words = words.trim() + " Shilling" + (intPart !== 1 ? "s" : "");
    if (centsPart > 0) words += " And " + below1000(centsPart) + " Cent" + (centsPart !== 1 ? "s" : "");
    return words.trim() + " Only";
}

function receiptBuildTaxBreakdown(items: any[], taxInclusive: boolean) {
    const groups: Record<string, { label: string; vatable: number; vat: number }> = {};
    for (const item of items) {
        const rate = item.tax_rate ?? 0;
        const code = rate > 0 ? "S" : "Z";
        if (!groups[code]) groups[code] = { label: rate > 0 ? `Standard Rated (${rate}%)` : "Zero Rated", vatable: 0, vat: 0 };
        const taxAmt = item.tax_amount ?? 0;
        const gross  = (item.unit_price ?? 0) * (item.quantity ?? 1)
                       - (item.discount_amount ?? 0)
                       + (taxInclusive ? 0 : taxAmt);
        groups[code].vatable += gross - taxAmt;
        groups[code].vat += taxAmt;
    }
    return Object.entries(groups).map(([code, g]) => ({ code, ...g }));
}

function receiptMaskPhone(phone?: string | null): string {
    if (!phone) return "";
    const digits = phone.replace(/\D/g, "");
    if (digits.length < 6) return digits;
    return digits.slice(0, 4) + "XXXX" + digits.slice(-3);
}

function ReceiptQRCanvas({ value, size = 80 }: { value: string; size?: number }) {
    // Uses the QR Server API - no npm package required.
    const src = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&margin=1&data=${encodeURIComponent(value)}`;
    return (
        <img
            src={src}
            alt="QR Code"
            width={size}
            height={size}
            style={{ imageRendering: "pixelated" }}
            onError={e => { (e.target as HTMLImageElement).style.display = "none"; }}
        />
    );
}

function ReceiptModal({ order, onClose, initialMode = "order", initialPaymentId }: {
    order: Order;
    onClose: () => void;
    initialMode?: ReceiptMode;
    initialPaymentId?: number;
}) {
    const [mode, setMode] = useState<ReceiptMode>(initialMode);
    const [selectedPaymentId, setSelectedPaymentId] = useState<number | null>(initialPaymentId ?? null);

    const payments = (order.payments ?? []) as any[];
    const paidPayments = payments.filter((p: any) => p.status === "paid");
    const selectedPayment = selectedPaymentId ? payments.find((p: any) => p.id === selectedPaymentId) : null;
    const cc = order.currency_code ?? "KES";

    const totalPaid = paidPayments.reduce((s: number, p: any) => s + Number(p.amount), 0);
    const outstanding = Math.max(0, Number(order.total_amount ?? 0) - totalPaid);

    // Derived receipt values
    const totalItems = (order.items ?? []).reduce((s: number, i: any) => s + (i.quantity ?? 0), 0);
    const taxBreakdown = receiptBuildTaxBreakdown(order.items ?? [], order.prices_include_tax ?? true);
    const totalSavings = (order.items ?? []).reduce((s: number, i: any) => s + Number(i.discount_amount ?? 0), 0)
        + Number(order.discount_amount ?? 0);
    const mpesaPayments = paidPayments.filter((p: any) => p.payment_method === "mpesa" || p.payment_method === "m_pesa");
    const cashPayment   = paidPayments.find((p: any) => p.payment_method === "cash");
    const hasSplit      = paidPayments.length > 1;
    const qrValue = `${order.order_number}|${order.outlet_name ?? "Bethany House"}|${Number(order.total_amount ?? 0).toFixed(2)}|${order.created_at}`;

    const handlePrint = () => {
        const style = document.createElement("style");
        style.innerHTML = `
            @page { size: ${mode === "order" ? "80mm auto" : "A4"}; margin: ${mode === "order" ? "4mm" : "12mm"}; }
            @media print {
                html, body { width: ${mode === "order" ? "80mm" : "210mm"}; height: auto !important; background: white !important; }
                body * { visibility: hidden !important; }
                #order-receipt, #order-receipt * { visibility: visible !important; }
                #order-receipt {
                    position: fixed; left: 0; top: 0;
                    ${mode === "order"
                        ? "width: 72mm; font-size: 11px !important; padding: 0 !important; margin: 0 !important; max-width: 72mm !important;"
                        : "width: 100%; padding: 24px;"}
                }
                .no-print { display: none !important; }
                * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        `;
        document.head.appendChild(style);
        window.print();
        document.head.removeChild(style);
    };

    // Inline style helpers to keep the receipt self-contained for printing
    const monoStyle: React.CSSProperties = {
        fontFamily: "'Courier New', Courier, monospace",
        fontSize: "11px",
        lineHeight: "1.4",
        color: "#000",
    };
    const centerStyle: React.CSSProperties = { textAlign: "center" };
    const rowStyle: React.CSSProperties = { display: "flex", justifyContent: "space-between" };
    const dashedBorder: React.CSSProperties = { borderTop: "1px dashed #000", marginTop: "3px", paddingTop: "3px" };
    const dividerStyle: React.CSSProperties = { borderBottom: "1px dashed #000", margin: "4px 0" };

    return (
        <Modal open title={mode === "order" ? "Receipt / Invoice" : "Payment Receipt"} onClose={onClose} size="lg">
            {/* ── Toolbar ── */}
            <div className="no-print px-5 py-3 border-b border-surface-100 flex items-center justify-between gap-3 flex-wrap">
                <div className="flex gap-1 bg-surface-100 rounded-lg p-1">
                    <button onClick={() => setMode("order")}
                        className={clsx("px-3 py-1 rounded-md text-xs font-semibold transition-all",
                            mode === "order" ? "bg-white shadow-sm text-surface-900" : "text-surface-500 hover:text-surface-700")}>
                        🧾 Receipt
                    </button>
                    <button onClick={() => setMode("payment")}
                        className={clsx("px-3 py-1 rounded-md text-xs font-semibold transition-all",
                            mode === "payment" ? "bg-white shadow-sm text-surface-900" : "text-surface-500 hover:text-surface-700")}>
                        📄 Payment Receipt
                    </button>
                </div>
                {mode === "payment" && paidPayments.length > 0 && (
                    <select value={selectedPaymentId ?? ""} onChange={e => setSelectedPaymentId(Number(e.target.value) || null)}
                        className="input text-xs py-1 w-auto min-w-[200px]">
                        <option value="">Select a payment…</option>
                        {paidPayments.map((p: any) => (
                            <option key={p.id} value={p.id}>
                                {PAYMENT_METHODS[p.payment_method]?.label ?? p.payment_method} - {fmt(p.amount, cc)}
                                {p.paid_at ? ` (${new Date(p.paid_at).toLocaleDateString("en-KE")})` : ""}
                            </option>
                        ))}
                    </select>
                )}
                <button onClick={handlePrint} className="btn-secondary btn-sm gap-1.5 ml-auto">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0" />
                    </svg>
                    Print
                </button>
            </div>

            <div className="overflow-y-auto max-h-[70vh]">
                <div id="order-receipt" style={{ ...monoStyle, padding: "16px", maxWidth: "80mm", margin: "0 auto" }}>

                    {/* ══════════════════════════════════════════════════
                        MODE: FULL THERMAL RECEIPT
                    ══════════════════════════════════════════════════ */}
                    {mode === "order" && (
                        <>
                            {/* Header */}
                            <div style={centerStyle}>
                                <p style={{ fontSize: "14px", fontWeight: "bold", letterSpacing: "0.1em", textTransform: "uppercase", margin: 0 }}>
                                    Bethany House
                                </p>
                                {order.outlet_name && <p style={{ fontWeight: "bold", margin: "2px 0 0" }}>{order.outlet_name}</p>}
                                <p style={{ margin: "1px 0 0", fontSize: "10px" }}>
                                    {new Date(order.created_at).toLocaleString("en-KE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false })}
                                </p>
                            </div>

                            <p style={{ ...centerStyle, ...dividerStyle, borderTop: "1px dashed #000", borderBottom: "1px dashed #000", padding: "2px 0", marginTop: "4px", fontSize: "10px" }}>
                                Sales Receipt
                            </p>

                            {/* Transaction info */}
                            <div style={{ fontSize: "10px", marginBottom: "4px" }}>
                                <div style={rowStyle}><span>Receipt #:</span><span style={{ fontWeight: "bold" }}>{order.order_number}</span></div>
                                {(order as any).register_number && (
                                    <div style={rowStyle}><span>Register:</span><span>{(order as any).register_number}</span></div>
                                )}
                                {order.order_type && (
                                    <div style={rowStyle}><span>Type:</span><span style={{ textTransform: "uppercase" }}>{order.order_type}</span></div>
                                )}
                                {order.customer_name && <div style={rowStyle}><span>Customer:</span><span>{order.customer_name}</span></div>}
                                {order.customer_phone && <div style={rowStyle}><span>Phone:</span><span>{order.customer_phone}</span></div>}
                                {(order as any).cashier_name && <div style={rowStyle}><span>Cashier:</span><span>{(order as any).cashier_name}</span></div>}
                            </div>

                            <p style={dividerStyle} />

                            {/* Items - CODE / DESCRIPTION / QTY / PRC / EXTENDED */}
                            <div style={{ fontSize: "9px", fontWeight: "bold", display: "flex", borderBottom: "1px solid #000", paddingBottom: "2px", marginBottom: "2px" }}>
                                <span style={{ flex: "0 0 28px" }}>CODE</span>
                                <span style={{ flex: 1 }}>DESCRIPTION</span>
                                <span style={{ flex: "0 0 24px", textAlign: "center" }}>QTY</span>
                                <span style={{ flex: "0 0 18px", textAlign: "center" }}>PRC</span>
                                <span style={{ flex: "0 0 52px", textAlign: "right" }}>EXTENDED</span>
                            </div>

                            <div style={{ marginBottom: "4px" }}>
                                {(order.items ?? []).map((item: any, i: number) => {
                                    const sku = item.sku ?? String(i + 1).padStart(3, "0");
                                    const taxCode = (item.tax_rate ?? 0) > 0 ? "S" : "Z";
                                    const subtotal = (item.unit_price ?? 0) * (item.quantity ?? 1)
                                        - (item.discount_amount ?? 0)
                                        + ((order.prices_include_tax ?? true) ? 0 : (item.tax_amount ?? 0));
                                    return (
                                        <div key={i} style={{ marginBottom: "4px" }}>
                                            <div style={{ display: "flex", alignItems: "flex-start" }}>
                                                <span style={{ flex: "0 0 28px", fontSize: "9px", color: "#555" }}>{String(sku).slice(-6)}</span>
                                                <span style={{ flex: 1, wordBreak: "break-word" }}>
                                                    {item.product_name}{item.variant_name ? ` (${item.variant_name})` : ""}
                                                </span>
                                                <span style={{ flex: "0 0 24px", textAlign: "center" }}>{item.quantity}</span>
                                                <span style={{ flex: "0 0 18px", textAlign: "center" }}>{taxCode}</span>
                                                <span style={{ flex: "0 0 52px", textAlign: "right" }}>{subtotal.toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                            </div>
                                            <div style={{ paddingLeft: "28px", fontSize: "9px", color: "#555" }}>
                                                {item.quantity} × {(item.unit_price ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}
                                                {item.discount_amount > 0 && ` · DISC: -${(item.discount_amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}`}
                                                                        {(item as any).price_adjusted && (item as any).original_price && ` · ADJ from ${((item as any).original_price).toLocaleString("en-KE", { minimumFractionDigits: 2 })}`}
                                                {item.is_production_item && " · Made-to-Order"}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            <p style={dividerStyle} />

                            {/* Totals */}
                            <div style={{ fontSize: "11px" }}>
                                <div style={rowStyle}><span>Subtotal</span><span>{(order.subtotal ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                {(order.discount_amount ?? 0) > 0 && (
                                    <div style={rowStyle}><span>Discount</span><span>-{(order.discount_amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                )}
                                {(order.shipping_amount ?? 0) > 0 && (
                                    <div style={rowStyle}>
                                        <span>Shipping{order.shipping_method ? ` (${order.shipping_method})` : ""}</span>
                                        <span>{(order.shipping_amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                    </div>
                                )}
                                {(order.tax_amount ?? 0) > 0 && (
                                    <div style={rowStyle}>
                                        <span>Tax{order.prices_include_tax ? " (incl.)" : ""}</span>
                                        <span>{(order.tax_amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                    </div>
                                )}
                                <div style={{ ...rowStyle, ...dashedBorder, fontWeight: "bold", fontSize: "13px" }}>
                                    <span>Totals</span>
                                    <span>{(order.total_amount ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                </div>
                            </div>

                            <p style={dividerStyle} />

                            {/* Payments */}
                            <div style={{ fontSize: "11px" }}>
                                {hasSplit ? (
                                    paidPayments.map((p: any, i: number) => (
                                        <div key={i}>
                                            <div style={rowStyle}>
                                                <span style={{ textTransform: "uppercase" }}>{(p.payment_method ?? "other").replace(/_/g, " ")}</span>
                                                <span>{Number(p.amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                            </div>
                                            {(p.payment_method === "mpesa" || p.payment_method === "m_pesa") && p.provider_reference && (
                                                <div style={{ paddingLeft: "8px", fontSize: "9px", color: "#555" }}>
                                                    <div>TRN #: {p.provider_reference}</div>
                                                    {order.customer_phone && <div>Cell: {receiptMaskPhone(order.customer_phone)}</div>}
                                                    <div>Amount: {Number(p.amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</div>
                                                </div>
                                            )}
                                            {p.payment_method === "cash" && p.cash_received > 0 && (
                                                <div style={{ paddingLeft: "8px", fontSize: "10px" }}>
                                                    <div style={rowStyle}><span>Tendered</span><span>{Number(p.cash_received).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                                    <div style={{ ...rowStyle, fontWeight: "bold" }}><span>Change</span><span>{Number(p.change_given ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                                </div>
                                            )}
                                        </div>
                                    ))
                                ) : paidPayments[0] ? (
                                    <>
                                        <div style={rowStyle}><span>Tendered</span><span>{(order.total_amount ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                        <div style={rowStyle}><span>Change</span><span>{(cashPayment?.change_given ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                        <div style={{ ...rowStyle, marginTop: "2px" }}>
                                            <span style={{ textTransform: "uppercase" }}>{(paidPayments[0].payment_method ?? "").replace(/_/g, " ")}</span>
                                            <span>{(order.total_amount ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                        </div>
                                    </>
                                ) : (
                                    <p style={{ color: "#888", fontStyle: "italic" }}>No payments recorded yet.</p>
                                )}

                                {/* M-PESA block (single mpesa payment) */}
                                {!hasSplit && mpesaPayments.length === 1 && mpesaPayments[0].provider_reference && (
                                    <>
                                        <p style={{ ...centerStyle, ...dividerStyle, fontSize: "10px" }} />
                                        <p style={{ ...centerStyle, fontSize: "10px", margin: "2px 0" }}>----Mobile Payment Information----</p>
                                        <div style={{ fontSize: "10px" }}>
                                            <div style={rowStyle}><span>TRN #</span><span style={{ fontWeight: "bold" }}>{mpesaPayments[0].provider_reference}</span></div>
                                            {order.customer_phone && <div style={rowStyle}><span>Cell</span><span>{receiptMaskPhone(order.customer_phone)}</span></div>}
                                            <div style={rowStyle}><span>Amount</span><span>{(order.total_amount ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                        </div>
                                    </>
                                )}

                                {/* Cash tendered / change (single cash) */}
                                {!hasSplit && cashPayment && (cashPayment.cash_received ?? 0) > 0 && (
                                    <>
                                        <p style={dividerStyle} />
                                        <div style={rowStyle}><span>Cash Received</span><span>{cc} {Number(cashPayment.cash_received).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                        <div style={{ ...rowStyle, fontWeight: "bold" }}><span>Change</span><span>{cc} {Number(cashPayment.change_given ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                    </>
                                )}

                                {outstanding > 0.01 && (
                                    <>
                                        <p style={dividerStyle} />
                                        <div style={{ ...rowStyle, fontWeight: "bold" }}><span>BALANCE DUE</span><span>{cc} {outstanding.toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                    </>
                                )}
                                {outstanding <= 0.01 && paidPayments.length > 0 && (
                                    <p style={{ ...centerStyle, fontWeight: "bold", color: "#16a34a", margin: "4px 0" }}>✓ FULLY PAID</p>
                                )}
                            </div>

                            <p style={dividerStyle} />

                            {/* Item count */}
                            <div style={{ fontSize: "10px" }}>
                                <div style={rowStyle}>
                                    <span>TOTAL ITEMS: {totalItems}</span>
                                    <span>Prices incl. taxes where applicable</span>
                                </div>
                            </div>

                            {/* Tax breakdown */}
                            {taxBreakdown.length > 0 && (
                                <div style={{ fontSize: "10px", marginTop: "4px" }}>
                                    <div style={{ fontWeight: "bold", marginBottom: "2px" }}>TAX DETAILS</div>
                                    <div style={{ display: "flex", gap: "4px", fontWeight: "bold", borderBottom: "1px solid #000", paddingBottom: "1px", marginBottom: "2px" }}>
                                        <span style={{ flex: "0 0 20px" }}>CODE</span>
                                        <span style={{ flex: 1, textAlign: "right" }}>VATABLE AMT</span>
                                        <span style={{ flex: "0 0 60px", textAlign: "right" }}>VAT AMT</span>
                                    </div>
                                    {taxBreakdown.map(({ code, vatable, vat }) => (
                                        <div key={code} style={{ display: "flex", gap: "4px" }}>
                                            <span style={{ flex: "0 0 20px" }}>{code}</span>
                                            <span style={{ flex: 1, textAlign: "right" }}>{vatable.toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                            <span style={{ flex: "0 0 60px", textAlign: "right" }}>{vat.toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* Savings */}
                            {totalSavings > 0 && (
                                <>
                                    <p style={{ ...centerStyle, borderTop: "1px dashed #000", borderBottom: "1px dashed #000", margin: "4px 0", padding: "2px 0", fontSize: "10px" }}>
                                        =========== PROMOTION ===========
                                    </p>
                                    <p style={{ ...centerStyle, fontSize: "10px", fontWeight: "bold", margin: "2px 0" }}>
                                        You have SAVED {cc} {totalSavings.toLocaleString("en-KE", { minimumFractionDigits: 2 })}
                                    </p>
                                    <p style={{ ...centerStyle, borderBottom: "1px dashed #000", margin: "4px 0", fontSize: "10px" }}>
                                        =========== PROMOTION ===========
                                    </p>
                                </>
                            )}

                            {/* Amount in words */}
                            <p style={{ fontSize: "10px", fontWeight: "bold", ...centerStyle, margin: "4px 0", textTransform: "uppercase" }}>
                                {receiptNumberToWords(order.total_amount ?? 0)}
                            </p>

                            {/* Cashier */}
                            {(order as any).cashier_name && (
                                <p style={{ ...centerStyle, fontSize: "10px", margin: "2px 0" }}>
                                    You were served by: <strong>{((order as any).cashier_name as string).toUpperCase()}</strong>
                                </p>
                            )}

                            <p style={{ ...centerStyle, fontSize: "10px", fontStyle: "italic", margin: "2px 0" }}>
                                ---MORE SAVINGS. BETTER LIVING---
                            </p>

                            <p style={dividerStyle} />

                            {/* Footer */}
                            <p style={{ ...centerStyle, fontSize: "10px", lineHeight: "1.5", margin: "2px 0" }}>
                                Thank you for shopping at Bethany House!<br />
                                Goods once sold are not exchangeable<br />
                                unless within 7 days with receipt.
                            </p>

                            {/* QR Code */}
                            <div style={{ ...centerStyle, marginTop: "8px" }}>
                                <ReceiptQRCanvas value={qrValue} size={80} />
                                <div style={{ fontSize: "9px", marginTop: "4px", color: "#555" }}>
                                    <div>Receipt No: {order.order_number}</div>
                                    {order.outlet_name && <div>Outlet: {order.outlet_name}</div>}
                                </div>
                            </div>
                        </>
                    )}

                    {/* ══════════════════════════════════════════════════
                        MODE: INDIVIDUAL PAYMENT RECEIPT
                    ══════════════════════════════════════════════════ */}
                    {mode === "payment" && (
                        <>
                            {/* Header */}
                            <div style={centerStyle}>
                                <p style={{ fontSize: "14px", fontWeight: "bold", letterSpacing: "0.1em", textTransform: "uppercase", margin: 0 }}>Bethany House</p>
                                {order.outlet_name && <p style={{ margin: "2px 0 0", fontWeight: "bold" }}>{order.outlet_name}</p>}
                                <p style={{ margin: "1px 0 0", fontSize: "10px" }}>
                                    {new Date(order.created_at).toLocaleString("en-KE", { dateStyle: "medium", timeStyle: "short" })}
                                </p>
                            </div>
                            <p style={dividerStyle} />

                            {!selectedPayment ? (
                                <p style={{ ...centerStyle, color: "#888", fontStyle: "italic", padding: "16px 0" }}>
                                    {paidPayments.length === 0
                                        ? "No payments recorded for this order yet."
                                        : "Select a payment above to generate its receipt."}
                                </p>
                            ) : (
                                <>
                                    <div style={{ fontSize: "10px", marginBottom: "4px" }}>
                                        <div style={rowStyle}><span>Order #:</span><span style={{ fontWeight: "bold" }}>{order.order_number}</span></div>
                                        {order.customer_name && <div style={rowStyle}><span>Customer:</span><span>{order.customer_name}</span></div>}
                                        {order.customer_phone && <div style={rowStyle}><span>Phone:</span><span>{order.customer_phone}</span></div>}
                                    </div>
                                    <p style={dividerStyle} />

                                    <div style={{ fontSize: "11px" }}>
                                        <p style={{ fontWeight: "bold", fontSize: "9px", textTransform: "uppercase", letterSpacing: "0.05em", margin: "0 0 3px" }}>Payment Details</p>
                                        <div style={rowStyle}><span>Method</span><span style={{ textTransform: "uppercase" }}>{(selectedPayment.payment_method ?? "").replace(/_/g, " ")}</span></div>
                                        {selectedPayment.paid_at && (
                                            <div style={rowStyle}>
                                                <span>Date</span>
                                                <span>{new Date(selectedPayment.paid_at).toLocaleString("en-KE", { dateStyle: "medium", timeStyle: "short" })}</span>
                                            </div>
                                        )}
                                        {selectedPayment.provider_reference && (
                                            <div style={rowStyle}><span>Reference</span><span style={{ fontFamily: "monospace" }}>{selectedPayment.provider_reference}</span></div>
                                        )}
                                        {selectedPayment.phone_number && (
                                            <div style={rowStyle}><span>Phone</span><span>{receiptMaskPhone(selectedPayment.phone_number)}</span></div>
                                        )}
                                        {selectedPayment.cash_received > 0 && (
                                            <>
                                                <div style={rowStyle}><span>Cash Received</span><span>{cc} {Number(selectedPayment.cash_received).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                                <div style={rowStyle}><span>Change Given</span><span>{cc} {Number(selectedPayment.change_given ?? 0).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span></div>
                                            </>
                                        )}
                                        <div style={{ ...rowStyle, ...dashedBorder, fontWeight: "bold" }}>
                                            <span>AMOUNT PAID</span>
                                            <span>{cc} {Number(selectedPayment.amount).toLocaleString("en-KE", { minimumFractionDigits: 2 })}</span>
                                        </div>
                                    </div>
                                    <p style={dividerStyle} />

                                    <div style={{ fontSize: "11px" }}>
                                        <div style={rowStyle}><span>Order Total</span><span>{fmt(order.total_amount, cc)}</span></div>
                                        <div style={rowStyle}><span>Total Paid</span><span>{fmt(totalPaid, cc)}</span></div>
                                        {outstanding > 0.01 && <div style={{ ...rowStyle, fontWeight: "bold" }}><span>Balance Due</span><span>{fmt(outstanding, cc)}</span></div>}
                                        {outstanding <= 0.01 && (
                                            <p style={{ ...centerStyle, fontWeight: "bold", color: "#16a34a", margin: "4px 0" }}>✓ FULLY PAID</p>
                                        )}
                                    </div>

                                    {/* QR for individual payment */}
                                    <p style={dividerStyle} />
                                    <div style={{ ...centerStyle, marginTop: "8px" }}>
                                        <ReceiptQRCanvas value={`${order.order_number}|PMT-${selectedPayment.id}|${Number(selectedPayment.amount ?? 0).toFixed(2)}`} size={72} />
                                    </div>
                                </>
                            )}

                            <p style={dividerStyle} />
                            <p style={{ ...centerStyle, fontSize: "10px", lineHeight: "1.5" }}>
                                Thank you for choosing Bethany House!<br />
                                Goods sold are not exchangeable<br />
                                unless within 7 days with original receipt.
                            </p>
                        </>
                    )}
                </div>
            </div>

            <div className="no-print p-4 border-t border-surface-100 flex gap-2">
                <button onClick={onClose} className="btn-secondary flex-1">Close</button>
            </div>
        </Modal>
    );
}

// ── Proof of Payment Upload Button ───────────────────────────────────────────
// Used inline on payment rows for international orders pending admin approval.

function ProofUploadButton({ paymentId, onDone }: { paymentId: number; onDone: () => void }) {
    const toast    = useToastStore();
    const inputRef = useRef<HTMLInputElement>(null);

    const mutation = useMutation({
        mutationFn: async (file: File) => {
            let fileToUpload = file;
            if (file.type.startsWith("image/")) {
                const { compressImage } = await import("@/utils/compressImage");
                fileToUpload = await compressImage(file).catch(() => file);
            }
            const form = new FormData();
            form.append("proof", fileToUpload);
            return import("@/api/client").then(({ api }) =>
                api.post(`/v1/admin/payments/${paymentId}/upload-proof`, form, {
                    headers: { "Content-Type": "multipart/form-data" },
                }).then(r => r.data)
            );
        },
        onSuccess: () => { toast.success("Proof uploaded - awaiting admin review"); onDone(); },
        onError:   (e: any) => toast.error(e.message ?? "Upload failed"),
    });

    return (
        <>
            <input ref={inputRef} type="file" accept=".pdf,image/*" className="hidden"
                onChange={e => {
                    const f = e.target.files?.[0];
                    if (f) mutation.mutate(f);
                    e.target.value = "";
                }} />
            <button
                onClick={() => inputRef.current?.click()}
                disabled={mutation.isPending}
                className="inline-flex items-center gap-1 text-2xs font-medium text-brand-600 hover:underline disabled:opacity-50">
                {mutation.isPending ? "Uploading…" : (
                    <>
                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                        </svg>
                        Upload Proof
                    </>
                )}
            </button>
        </>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

// ── Production Orders Section ─────────────────────────────────────────────────
// Expandable list of production orders with inline drawer for details + chat

function ProductionOrdersSection({ productionOrders, PROD_CFG }: {
    productionOrders: any[];
    PROD_CFG: Record<string, { label: string; cls: string; dot: string }>;
}) {
    const [expandedId, setExpandedId] = useState<number | null>(null);

    return (
        <div>
            <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-3">Production Orders</p>
            <div className="space-y-2">
                {productionOrders.map((po) => {
                    const sc = PROD_CFG[po.status] ?? PROD_CFG.draft;
                    const isOverdue = po.due_date && new Date(po.due_date) < new Date()
                        && !["completed", "cancelled"].includes(po.status);
                    const isExpanded = expandedId === po.id;
                    return (
                        <div key={po.id}>
                            {/* Row */}
                            <button
                                onClick={() => setExpandedId(isExpanded ? null : po.id)}
                                className={clsx(
                                    "w-full flex items-center gap-4 py-3 px-2 -mx-2 rounded-xl transition-colors text-left",
                                    isExpanded ? "bg-brand-50" : "hover:bg-surface-50"
                                )}>
                                {/* Status dot */}
                                <div className={clsx("w-2 h-2 rounded-full shrink-0", sc.dot)} />

                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-mono text-sm font-bold text-surface-900">{po.order_number}</span>
                                        <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", sc.cls)}>{sc.label}</span>
                                        {isOverdue && <span className="text-2xs font-bold text-danger">⚠ Overdue</span>}
                                    </div>
                                    <p className="text-xs text-surface-500 mt-0.5">
                                        {po.product_name} · Qty: {po.quantity}
                                        {po.due_date && !isOverdue && ` · Due ${new Date(po.due_date).toLocaleDateString("en-KE", { dateStyle: "medium" })}`}
                                    </p>
                                </div>

                                {/* Expand indicator */}
                                <div className="flex items-center gap-2 shrink-0">
                                    {(po.measurements || po.specifications || po.notes) && (
                                        <span className="text-2xs text-brand-500 font-medium">Has details</span>
                                    )}
                                    <svg className={clsx("w-4 h-4 text-surface-400 transition-transform", isExpanded && "rotate-180")}
                                        fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                    </svg>
                                </div>
                            </button>

                            {/* Inline drawer */}
                            {isExpanded && (
                                <div className="mt-2 mb-1">
                                    <ProductionOrderDrawer po={po} onClose={() => setExpandedId(null)} />
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
            {productionOrders.some(po => po.status === "draft") && (
                <p className="mt-2 text-2xs text-warning-dark bg-warning-light rounded-lg px-3 py-2">
                    ⚠ Draft production orders must be confirmed in the Production module before work begins.
                </p>
            )}
        </div>
    );
}

// ── Production Order Detail Drawer ───────────────────────────────────────────
// Shows full specs, measurements, notes + real-time activity/chat thread for a
// single production order. The chat is identical to ProductionOrderDetailPage —
// it uses the same channel API, Reverb real-time updates, @mention and # entity
// tagging, and CommsHub deep-link.

// ── Mini hooks for mention + entity search ────────────────────────────────────

function useThreadStaffSearch(query: string) {
    return useQuery({
        queryKey: ["thread-staff-search", query],
        queryFn: () => commentApi.searchUsers(query).then(r => r.users),
        staleTime: 15_000,
        placeholderData: [] as MentionUser[],
    });
}

function useThreadEntitySearch(query: string, enabled: boolean) {
    return useQuery({
        queryKey: ["thread-entity-search", query],
        queryFn: () => channelApi.entitySearch(query),
        staleTime: 10_000,
        placeholderData: { results: [] as EntitySearchResult[] },
        enabled: enabled && query.length >= 1,
    });
}

// ── Mention popup ─────────────────────────────────────────────────────────────

function ThreadMentionPopup({ query, onSelect }: { query: string; onSelect: (u: MentionUser) => void }) {
    const { data: users = [] } = useThreadStaffSearch(query);
    if (!users.length) return null;
    return (
        <div className="absolute bottom-full left-0 mb-1 w-56 bg-white rounded-xl border border-surface-200 shadow-xl py-1 z-50 max-h-40 overflow-y-auto">
            {users.map(u => (
                <button key={u.id} onMouseDown={e => { e.preventDefault(); onSelect(u); }}
                    className="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-surface-50 text-left">
                    <div className="w-6 h-6 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 text-2xs font-bold shrink-0">
                        {u.initials}
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs font-semibold text-surface-800 truncate">{u.name}</p>
                        <p className="text-2xs text-surface-400 truncate">{u.email}</p>
                    </div>
                </button>
            ))}
        </div>
    );
}

// ── Entity popup ──────────────────────────────────────────────────────────────

const ENTITY_STATUS_COLOURS: Record<string, string> = {
    pending: "bg-surface-100 text-surface-500", processing: "bg-brand-50 text-brand-700",
    completed: "bg-emerald-50 text-emerald-700", shipped: "bg-blue-50 text-blue-700",
    delivered: "bg-emerald-50 text-emerald-700", cancelled: "bg-red-50 text-red-700",
    draft: "bg-surface-100 text-surface-500", in_progress: "bg-brand-50 text-brand-700",
};
const entityStatusCls = (s: string) => ENTITY_STATUS_COLOURS[s] ?? "bg-surface-100 text-surface-500";

function ThreadEntityPopup({ query, onSelect, onDismiss }: {
    query: string; onSelect: (e: EntitySearchResult) => void; onDismiss: () => void;
}) {
    const { data } = useThreadEntitySearch(query, true);
    const results  = data?.results ?? [];
    return (
        <div className="absolute bottom-full left-0 mb-1 w-72 bg-white rounded-xl border border-surface-200 shadow-xl py-1 z-50 max-h-60 overflow-y-auto">
            <div className="flex items-center justify-between px-3 pt-1.5 pb-1">
                <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">Tag order or production</p>
                <button onMouseDown={e => { e.preventDefault(); onDismiss(); }}
                    className="text-surface-300 hover:text-surface-500 p-0.5 rounded">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            {results.length === 0 ? (
                <p className="text-xs text-surface-400 px-3 py-2">{query.length < 1 ? "Type to search…" : "No results"}</p>
            ) : results.map(r => (
                <button key={`${r.type}:${r.id}`}
                    onMouseDown={e => { e.preventDefault(); onSelect(r); }}
                    className="w-full flex items-start gap-2.5 px-3 py-2 hover:bg-surface-50 text-left transition-colors">
                    <div className={clsx("mt-0.5 w-6 h-6 rounded-md flex items-center justify-center shrink-0",
                        r.type === "order" ? "bg-brand-50" : "bg-purple-50")}>
                        {r.type === "order"
                            ? <svg className="w-3.5 h-3.5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            : <svg className="w-3.5 h-3.5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                        }
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="text-xs font-bold text-surface-900 font-mono">{r.label}</span>
                            <span className={clsx("text-2xs px-1.5 py-0.5 rounded-full font-medium", entityStatusCls(r.status))}>
                                {r.status.replace(/_/g, " ")}
                            </span>
                        </div>
                        <p className="text-2xs text-surface-500 truncate mt-0.5">{r.subtitle}</p>
                    </div>
                </button>
            ))}
        </div>
    );
}

// ── Message body renderer (mentions + entity chips) ───────────────────────────

function ThreadMessageBody({ body, linkedEntities, isOwn }: {
    body: string; linkedEntities?: LinkedEntity[]; isOwn: boolean;
}) {
    // Render @[Name](user:id) and #[Label](entity:type:id) tokens as chips
    const parts: React.ReactNode[] = [];
    const re = /(@\[([^\]]+)\]\(user:\d+\)|#\[([^\]]+)\]\(entity:[^)]+\))/g;
    let last = 0; let m: RegExpExecArray | null;
    while ((m = re.exec(body)) !== null) {
        if (m.index > last) parts.push(body.slice(last, m.index));
        const raw = m[0];
        if (raw.startsWith("@")) {
            const name = m[2];
            parts.push(<span key={m.index} className={clsx("inline-flex items-center gap-0.5 font-semibold rounded px-0.5", isOwn ? "text-white/90" : "text-brand-700")}>@{name}</span>);
        } else {
            const label = m[3];
            const entity = linkedEntities?.find(e => body.includes(`entity:${e.type}:${e.id}`));
            const href   = entity?.type === "order" ? `/sales/orders/${entity.id}` : entity ? `/production/orders/${entity.id}` : undefined;
            const chip = (
                <span key={m.index} className={clsx("inline-flex items-center gap-1 text-2xs font-semibold rounded-full px-1.5 py-0.5 border",
                    isOwn ? "bg-white/20 border-white/30 text-white" : "bg-purple-50 border-purple-200 text-purple-700")}>
                    #{label}
                    {href && <svg className="w-2.5 h-2.5 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>}
                </span>
            );
            parts.push(href ? <a key={m.index} href={href}>{chip}</a> : chip);
        }
        last = m.index + raw.length;
    }
    if (last < body.length) parts.push(body.slice(last));
    return <span className="text-xs whitespace-pre-wrap leading-relaxed">{parts}</span>;
}

// ── Real-time channel thread (per production order) ───────────────────────────

function ProductionOrderChannelThread({ poId }: { poId: number }) {
    const auth  = useAuthStore();
    const toast = useToastStore();
    const qc    = useQueryClient();
    const [body, setBody]           = useState("");
    const [channel, setChannel]     = useState<Channel | null>(null);
    const [messages, setMessages]   = useState<ChannelMessage[]>([]);
    const [loadingCh, setLoadingCh] = useState(true);
    const [sending, setSending]     = useState(false);
    const [mentionQ, setMentionQ]   = useState<string | null>(null);
    const [mentionStart, setMentionStart] = useState(0);
    const [entityQ, setEntityQ]     = useState<string | null>(null);
    const [entityStart, setEntityStart]   = useState(0);
    // Non-member mention guard
    const [pendingMention, setPendingMention] = useState<MentionUser | null>(null);
    const [channelMemberIds, setChannelMemberIds] = useState<Set<number>>(new Set());
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const bottomRef   = useRef<HTMLDivElement>(null);

    const currentUserId = (auth.user as any)?.id;
    const AVATAR_COLORS = ["bg-blue-500","bg-purple-500","bg-pink-500","bg-orange-500","bg-teal-500","bg-indigo-500","bg-rose-500","bg-amber-500"];
    const avatarColor = (id: number) => AVATAR_COLORS[id % AVATAR_COLORS.length];
    const fmtTime = (ts: string) => new Date(ts).toLocaleString("en-KE", { dateStyle: "short", timeStyle: "short" });
    const scrollBottom = (behavior: ScrollBehavior = "smooth") =>
        setTimeout(() => bottomRef.current?.scrollIntoView({ behavior }), 80);

    // 1. Find-or-create context channel
    useEffect(() => {
        let cancelled = false;
        setLoadingCh(true);
        channelApi.findOrCreateContext("production_order", poId)
            .then(res => {
                if (cancelled) return;
                const ch = res.channel;
                setChannel(ch);
                channelApi.get(ch.id).then(d => {
                    if (cancelled) return;
                    const members = Array.isArray(d.channel.members)
                        ? (d.channel.members as any[]).map((m: any) => m.id as number)
                        : [];
                    setChannelMemberIds(new Set(members));
                }).catch(() => {});
                return channelApi.messages(ch.id).then(r => {
                    if (cancelled) return;
                    setMessages(r.messages);
                    scrollBottom("auto");
                });
            })
            .catch(() => { if (!cancelled) toast.error("Could not load activity thread"); })
            .finally(() => { if (!cancelled) setLoadingCh(false); });
        return () => { cancelled = true; };
    }, [poId]);

    // 2. Subscribe to real-time messages
    useEffect(() => {
        if (!channel) return;
        subscribeToChannel(channel.id, (raw) => {
            const msg = raw as unknown as ChannelMessage;
            setMessages(prev => prev.find(m => m.id === msg.id) ? prev : [...prev, msg]);
            scrollBottom();
        });
        return () => {
            try { getEcho().leave(`channel.${channel.id}`); } catch { /* ignore */ }
        };
    }, [channel?.id]);

    // ── Composer handlers ────────────────────────────────────────────────────

    const handleChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
        const val = e.target.value;
        setBody(val);
        const cursor = e.target.selectionStart;
        const before = val.slice(0, cursor);
        const at = before.match(/@([^@\n]*)$/);
        if (at) { setMentionQ(at[1]); setMentionStart(cursor - at[0].length); setEntityQ(null); }
        else {
            setMentionQ(null);
            const hash = before.match(/#([^#\n]*)$/);
            if (hash) { setEntityQ(hash[1]); setEntityStart(cursor - hash[0].length); }
            else setEntityQ(null);
        }
    };

    const doInsertMention = (u: MentionUser) => {
        const before = body.slice(0, mentionStart);
        const after  = body.slice(textareaRef.current?.selectionStart ?? mentionStart);
        setBody(before + `@[${u.name}](user:${u.id})` + (after && !after.startsWith(" ") ? " " : "") + after);
        setMentionQ(null);
        setTimeout(() => {
            textareaRef.current?.focus();
            if (textareaRef.current) {
                textareaRef.current.style.height = "auto";
                textareaRef.current.style.height = textareaRef.current.scrollHeight + "px";
            }
        }, 0);
    };

    const insertMention = (u: MentionUser) => {
        if (channelMemberIds.size > 0 && !channelMemberIds.has(u.id)) {
            setMentionQ(null);
            setPendingMention(u);
            return;
        }
        doInsertMention(u);
    };

    const insertEntity = (entity: EntitySearchResult) => {
        const token  = `#[${entity.label}](entity:${entity.type}:${entity.id})`;
        const before = body.slice(0, entityStart);
        const after  = body.slice(textareaRef.current?.selectionStart ?? entityStart);
        setBody(before + token + (after && !after.startsWith(" ") ? " " : "") + after);
        setEntityQ(null);
        setTimeout(() => {
            textareaRef.current?.focus();
            if (textareaRef.current) {
                textareaRef.current.style.height = "auto";
                textareaRef.current.style.height = textareaRef.current.scrollHeight + "px";
            }
        }, 0);
    };

    const handleSend = async () => {
        if (!channel || !body.trim() || sending) return;
        setSending(true);
        try {
            const res = await channelApi.send(channel.id, { body: body.trim() });
            setMessages(prev => [...prev, res.message]);
            setBody("");
            if (textareaRef.current) {
                textareaRef.current.style.height = "auto";
                textareaRef.current.style.height = "20px";
            }
            scrollBottom();
            qc.invalidateQueries({ queryKey: ["channels"] });
        } catch (e: any) {
            toast.error(e?.message ?? "Failed to send");
        } finally {
            setSending(false);
        }
    };

    const handleKey = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === "Escape") { setMentionQ(null); setEntityQ(null); return; }
        if (e.key === "Enter" && !e.shiftKey && mentionQ === null && entityQ === null && body.trim()) {
            e.preventDefault(); handleSend();
        }
    };

    if (loadingCh) return <div className="flex justify-center py-8"><Spinner /></div>;

    return (
        <>
        <div className="flex flex-col" style={{ minHeight: 360 }}>
            {/* CommsHub deep-link */}
            {channel && (
                <div className="pb-2 shrink-0">
                    <p className="text-2xs text-surface-400">
                        Messages here also appear in{" "}
                        <a href={`/comms/${channel.id}`} className="text-brand-500 hover:underline font-medium">
                            CommsHub → {channel.name}
                        </a>
                    </p>
                </div>
            )}

            {/* Messages */}
            <div className="flex-1 overflow-y-auto px-1 py-2 space-y-3" style={{ maxHeight: 380 }}>
                {messages.length === 0 ? (
                    <div className="text-center py-10 text-surface-300">
                        <svg className="w-10 h-10 mx-auto mb-2 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <p className="text-sm font-medium text-surface-400">No messages yet</p>
                        <p className="text-xs text-surface-300 mt-1">Start the conversation below</p>
                    </div>
                ) : messages.map(msg => {
                    if (msg.type === "system") return (
                        <div key={msg.id} className="flex items-center gap-2 text-2xs text-surface-400 py-1">
                            <div className="flex-1 h-px bg-surface-100" />
                            <span>{msg.body}</span>
                            <div className="flex-1 h-px bg-surface-100" />
                        </div>
                    );
                    const isOwn = msg.user?.id === currentUserId;
                    return (
                        <div key={msg.id} className={clsx("flex gap-2.5", isOwn && "flex-row-reverse")}>
                            {msg.user ? (
                                <div className={clsx("w-7 h-7 rounded-full flex items-center justify-center text-white text-2xs font-bold shrink-0 mt-0.5", avatarColor(msg.user.id))}>
                                    {msg.user.initials}
                                </div>
                            ) : (
                                <div className="w-7 h-7 rounded-full bg-surface-200 shrink-0 mt-0.5" />
                            )}
                            <div className={clsx("flex flex-col gap-0.5 max-w-[78%]", isOwn && "items-end")}>
                                <div className={clsx("flex items-center gap-1.5 px-0.5", isOwn && "flex-row-reverse")}>
                                    <span className="text-2xs font-semibold text-surface-700">{msg.user?.name ?? "System"}</span>
                                    <span className="text-2xs text-surface-300">{fmtTime(msg.created_at)}</span>
                                </div>
                                <div className={clsx(
                                    "px-3 py-2 rounded-2xl",
                                    isOwn ? "bg-brand-500 text-white rounded-tr-sm" : "bg-surface-100 text-surface-900 rounded-tl-sm"
                                )}>
                                    <ThreadMessageBody body={msg.body} linkedEntities={msg.linked_entities} isOwn={isOwn} />
                                </div>
                            </div>
                        </div>
                    );
                })}
                <div ref={bottomRef} />
            </div>

            {/* Composer */}
            <div className="border-t border-surface-100 p-3 bg-surface-50 rounded-b-xl shrink-0">
                <div className="relative">
                    {mentionQ !== null && <ThreadMentionPopup query={mentionQ} onSelect={insertMention} />}
                    {entityQ  !== null && <ThreadEntityPopup  query={entityQ}  onSelect={insertEntity}  onDismiss={() => setEntityQ(null)} />}
                    <div className="flex gap-2 items-end bg-white rounded-xl border border-surface-200 focus-within:border-brand-400 focus-within:ring-1 focus-within:ring-brand-200 px-3 py-2">
                        {/* Rich composer: transparent textarea + visual mirror overlay */}
                        <div className="relative flex-1 self-center min-w-0 max-h-28 overflow-y-auto">
                            {/* Mirror drives wrapper height; textarea is absolute overlay */}
                            <div aria-hidden="true"
                                className="pointer-events-none text-xs leading-5 whitespace-pre-wrap break-words select-none w-full"
                                style={{ wordBreak: "break-word", minHeight: "20px" }}>
                                {body
                                    ? parseBodyToNodes(body)
                                    : <span className="text-surface-400">Message… (Enter to send · @ mention · # tag order)</span>
                                }
                                <span className="select-none">{"​"}</span>
                            </div>
                            <textarea
                                ref={textareaRef}
                                value={body}
                                onChange={handleChange}
                                onKeyDown={handleKey}
                                rows={1}
                                className="absolute inset-0 w-full text-xs leading-5 bg-transparent resize-none outline-none focus:outline-none focus:ring-0 border-0 shadow-none overflow-hidden"
                                style={{ color: "transparent", caretColor: "rgb(15 23 42)", height: "100%" }}
                                onInput={e => {
                                    const t = e.currentTarget;
                                    t.style.height = t.parentElement ? t.parentElement.offsetHeight + "px" : "auto";
                                }}
                            />
                        </div>
                        <button onClick={handleSend} disabled={!body.trim() || sending}
                            className="shrink-0 w-8 h-8 rounded-xl bg-brand-600 text-white flex items-center justify-center hover:bg-brand-700 disabled:opacity-40 transition-colors self-end">
                            {sending
                                ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                : <svg className="w-3.5 h-3.5 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            }
                        </button>
                    </div>
                </div>
                {!body && (
                    <div className="flex items-center gap-3 px-1 pt-1.5">
                        <span className="flex items-center gap-1 text-2xs text-surface-300">
                            <kbd className="px-1 py-0.5 rounded bg-surface-100 text-surface-400 font-mono text-2xs border border-surface-200 leading-none">@</kbd>
                            mention people
                        </span>
                        <span className="text-surface-200 text-2xs select-none">·</span>
                        <span className="flex items-center gap-1 text-2xs text-surface-300">
                            <kbd className="px-1 py-0.5 rounded bg-surface-100 text-surface-400 font-mono text-2xs border border-surface-200 leading-none">#</kbd>
                            tag an order
                        </span>
                        <span className="text-surface-200 text-2xs select-none">·</span>
                        <span className="text-2xs text-surface-300">visible in CommsHub</span>
                    </div>
                )}
            </div>
        </div>

        {/* Non-member @mention prompt */}
        {pendingMention && channel && (
            <div className="fixed inset-0 z-[70] flex items-end sm:items-center justify-center bg-black/40 p-4"
                onMouseDown={e => { if (e.target === e.currentTarget) setPendingMention(null); }}>
                <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-5 flex flex-col gap-4">
                    <div className="flex items-start gap-3">
                        <div className="w-9 h-9 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                            <svg className="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 className="text-sm font-bold text-surface-900">{pendingMention.name} isn't a member</h3>
                            <p className="text-xs text-surface-500 mt-1">
                                <span className="font-semibold text-surface-700">{pendingMention.name}</span> is not in this thread.
                                They won't be notified and won't see this message unless added.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col gap-2">
                        <button
                            onClick={async () => {
                                try {
                                    await channelApi.addMember(channel.id, pendingMention!.id);
                                    setChannelMemberIds(prev => new Set([...prev, pendingMention!.id]));
                                    qc.invalidateQueries({ queryKey: ["channels"] });
                                } catch { /* continue even if add fails */ }
                                doInsertMention(pendingMention!);
                                setPendingMention(null);
                            }}
                            className="w-full py-2.5 rounded-xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 transition-colors flex items-center justify-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            Add {pendingMention.name} and mention
                        </button>
                        <button onClick={() => { doInsertMention(pendingMention!); setPendingMention(null); }}
                            className="w-full py-2 rounded-xl border border-surface-200 text-surface-600 text-sm hover:bg-surface-50 transition-colors">
                            Mention anyway (they won't see it)
                        </button>
                        <button onClick={() => setPendingMention(null)}
                            className="w-full py-2 rounded-xl text-surface-400 text-sm hover:text-surface-600 transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        )}
        </>
    );
}

function ProductionOrderDrawer({ po, onClose }: { po: any; onClose: () => void }) {
    const [tab, setTab] = useState<"details" | "activity">("details");
    const hasDetails = po.measurements || po.specifications || po.customer_preferences || po.notes;

    return (
        <div className="border border-surface-200 rounded-2xl bg-white shadow-lg overflow-hidden">
            {/* Drawer header */}
            <div className="flex items-center justify-between px-4 py-3 bg-surface-50 border-b border-surface-100">
                <div>
                    <span className="font-mono text-sm font-bold text-surface-900">{po.order_number}</span>
                    <span className="ml-2 text-xs text-surface-500">{po.product_name} · Qty {po.quantity}</span>
                </div>
                <button onClick={onClose} className="w-7 h-7 rounded-full bg-surface-200 flex items-center justify-center text-surface-500 hover:bg-surface-300 transition-colors"
aria-label="Close">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            {/* Tab bar */}
            <div className="flex border-b border-surface-100">
                {(["details", "activity"] as const).map(t => (
                    <button key={t} onClick={() => setTab(t)}
                        className={clsx("flex-1 py-2.5 text-xs font-semibold transition-colors border-b-2 -mb-px",
                            tab === t ? "border-brand-500 text-brand-600" : "border-transparent text-surface-400 hover:text-surface-700")}>
                        {t === "details" ? "📋 Details & Specs" : "💬 Activity & Chat"}
                    </button>
                ))}
            </div>

            {/* Details tab */}
            {tab === "details" && (
                <div className="p-4 space-y-4 max-h-96 overflow-y-auto">
                    {!hasDetails ? (
                        <p className="text-center text-xs text-surface-400 py-8">No specifications or notes recorded for this production order.</p>
                    ) : (
                        <>
                            {po.measurements && Object.keys(po.measurements).length > 0 && (
                                <div>
                                    <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">Measurements</p>
                                    <div className="bg-purple-50 rounded-xl p-3 grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-1.5">
                                        {Object.entries(po.measurements).map(([k, v]) => (
                                            <div key={k} className="text-xs">
                                                <span className="text-purple-400 capitalize block text-2xs">{k.replace(/_/g, " ")}</span>
                                                <span className="font-bold text-purple-900">{v as string}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {po.specifications && Object.keys(po.specifications).length > 0 && (
                                <div>
                                    <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">Specifications</p>
                                    <div className="bg-surface-50 rounded-xl p-3 space-y-1.5">
                                        {Object.entries(po.specifications).map(([k, v]) => (
                                            <div key={k} className="flex gap-3 text-xs">
                                                <span className="text-surface-400 w-32 shrink-0 capitalize">{k.replace(/_/g, " ")}</span>
                                                <span className="font-medium text-surface-900">{v as string}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {po.customer_preferences && Object.keys(po.customer_preferences).length > 0 && (
                                <div>
                                    <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">Customer Preferences</p>
                                    <div className="bg-indigo-50 rounded-xl p-3 space-y-1.5">
                                        {Object.entries(po.customer_preferences).map(([k, v]) => (
                                            <div key={k} className="flex gap-3 text-xs">
                                                <span className="text-indigo-400 w-32 shrink-0 capitalize">{k.replace(/_/g, " ")}</span>
                                                <span className="font-medium text-indigo-900">{v as string}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {po.notes && (
                                <div>
                                    <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">Notes</p>
                                    <p className="text-xs text-surface-700 bg-surface-50 rounded-xl p-3 whitespace-pre-wrap leading-relaxed">{po.notes}</p>
                                </div>
                            )}
                        </>
                    )}
                    {/* Link to full production order */}
                    <a href={`/production/orders/${po.id}`} className="flex items-center gap-2 text-xs text-brand-600 hover:text-brand-800 font-medium mt-2">
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                        Open in Production module
                    </a>
                </div>
            )}

            {/* Activity tab — real-time channel thread identical to ProductionOrderDetailPage */}
            {tab === "activity" && (
                <div className="p-4">
                    <ProductionOrderChannelThread poId={po.id} />
                </div>
            )}
        </div>
    );
}

// ── Attach Customer Modal ─────────────────────────────────────────────────────

function AttachCustomerModal({ order, onClose, onDone }: {
    order: Order; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();
    const [mode, setMode]       = useState<"existing" | "new">("existing");
    const [search, setSearch]   = useState("");
    const [selectedCustomer, setSelectedCustomer] = useState<any | null>(null);
    // New customer fields
    const [firstName, setFirstName] = useState(order.customer_name?.split(" ")[0] ?? "");
    const [lastName,  setLastName]  = useState(order.customer_name?.split(" ").slice(1).join(" ") ?? "");
    const [phone,     setPhone]     = useState(order.customer_phone ?? "");
    const [email,     setEmail]     = useState(order.customer_email ?? "");

    const { data: searchData } = useQuery({
        queryKey: ["customer-search", search],
        queryFn:  () => search.length >= 2 ? get<{ data: any[] }>(`/v1/admin/customers?search=${encodeURIComponent(search)}&per_page=8`) : Promise.resolve({ data: [] }),
        enabled:  mode === "existing",
    });
    const customers = (searchData as any)?.data ?? [];

    const mutation = useMutation({
        mutationFn: () => ordersApi.attachCustomer(order.id, mode === "existing"
            ? { customer_id: selectedCustomer?.id }
            : { new_customer: { first_name: firstName, last_name: lastName || undefined, phone, email: email || undefined } }
        ),
        onSuccess: () => { toast.success("Customer attached"); onDone(); onClose(); },
        onError:   (e: ApiError) => toast.error(e.message),
    });

    const canSave = mode === "existing" ? !!selectedCustomer : firstName.trim().length > 0 && phone.trim().length > 0;

    return (
        <Modal open title={order.customer_name ? "Edit Customer" : "Attach Customer"} onClose={onClose}>
            <div className="space-y-4 p-5">
                <div className="flex gap-1 bg-surface-100 rounded-xl p-1">
                    {(["existing", "new"] as const).map(m => (
                        <button key={m} onClick={() => setMode(m)}
                            className={clsx("flex-1 py-1.5 rounded-lg text-xs font-semibold transition-all",
                                mode === m ? "bg-white text-surface-900 shadow-sm" : "text-surface-500 hover:text-surface-700")}>
                            {m === "existing" ? "Existing Customer" : "New Customer"}
                        </button>
                    ))}
                </div>

                {mode === "existing" && (
                    <>
                        <div>
                            <label className="label">Search customer</label>
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)}
                                placeholder="Name, phone, or email…" className="input" autoFocus />
                        </div>
                        {customers.length > 0 && (
                            <div className="border border-surface-200 rounded-xl divide-y divide-surface-100 max-h-52 overflow-y-auto">
                                {customers.map((c: any) => (
                                    <button key={c.id} onClick={() => setSelectedCustomer(c)}
                                        className={clsx("w-full text-left px-4 py-2.5 text-xs hover:bg-surface-50 transition-colors",
                                            selectedCustomer?.id === c.id && "bg-brand-50 border-l-2 border-brand-500")}>
                                        <p className="font-semibold">{c.first_name} {c.last_name}</p>
                                        <p className="text-surface-400">{c.phone}{c.email ? ` · ${c.email}` : ""}</p>
                                    </button>
                                ))}
                            </div>
                        )}
                        {selectedCustomer && (
                            <div className="bg-brand-50 rounded-xl px-4 py-3 text-xs">
                                <p className="font-semibold text-brand-800">Selected: {selectedCustomer.first_name} {selectedCustomer.last_name}</p>
                                <p className="text-brand-600">{selectedCustomer.phone}</p>
                            </div>
                        )}
                    </>
                )}

                {mode === "new" && (
                    <>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="label">First Name <span className="text-danger">*</span></label>
                                <input type="text" value={firstName} onChange={e => setFirstName(e.target.value)} className="input" autoFocus />
                            </div>
                            <div>
                                <label className="label">Last Name</label>
                                <input type="text" value={lastName} onChange={e => setLastName(e.target.value)} className="input" />
                            </div>
                        </div>
                        <div>
                            <label className="label">Phone <span className="text-danger">*</span></label>
                            <input type="tel" value={phone} onChange={e => setPhone(e.target.value)} className="input" placeholder="+254…" />
                        </div>
                        <div>
                            <label className="label">Email <span className="text-surface-400">(optional)</span></label>
                            <input type="email" value={email} onChange={e => setEmail(e.target.value)} className="input" />
                        </div>
                    </>
                )}

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending || !canSave}
                        className="btn-primary flex-1 disabled:opacity-40">
                        {mutation.isPending ? "Saving…" : "Attach Customer"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Order Audit Log ───────────────────────────────────────────────────────────

function OrderAuditLog({ orderId, onClose }: { orderId: number; onClose: () => void }) {
    const { data, isLoading, refetch } = useQuery({
        queryKey: ["order-audit", orderId],
        queryFn:  () => ordersApi.auditLog(orderId),
        staleTime: 0,          // always fresh - audit logs change on every action
        refetchOnWindowFocus: true,
    });

    const logs = (data as any)?.data ?? [];

    // Icon by event category
    const actionIcon = (event: string) => {
        const s = { fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 2, strokeLinecap: "round" as const, strokeLinejoin: "round" as const, className: "w-3.5 h-3.5" };
        if (event.includes("payment") || event.includes("mpesa") || event.includes("paystack") || event.includes("flutterwave"))
            return <svg {...s}><path d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>;
        if (event.includes("currency"))
            return <svg {...s}><path d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253M3 12a8.96 8.96 0 01.284-2.253"/></svg>;
        if (event.includes("status") || event.includes("cancel"))
            return <svg {...s}><path d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>;
        if (event.includes("customer"))
            return <svg {...s}><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>;
        if (event.includes("refund"))
            return <svg {...s}><path d="M6 18L18 6M6 6l12 12"/></svg>;
        if (event.includes("ship"))
            return <svg {...s}><path d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>;
        if (event.includes("note"))
            return <svg {...s}><path d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>;
        if (event.includes("production"))
            return <svg {...s}><path d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z"/></svg>;
        return <svg {...s}><path d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>;
    };

    const actionColor = (event: string) => {
        if (event === "created")                          return "bg-success-light text-success";
        if (event.includes("approved"))                  return "bg-success-light text-success";
        if (event.includes("payment") || event.includes("mpesa") || event.includes("paystack") || event.includes("flutterwave"))
                                                          return "bg-brand-50 text-brand-600";
        if (event.includes("currency"))                  return "bg-teal-50 text-teal-600";
        if (event.includes("status"))                    return "bg-blue-50 text-blue-600";
        if (event.includes("ship"))                      return "bg-purple-50 text-purple-600";
        if (event.includes("production"))                return "bg-indigo-50 text-indigo-600";
        if (event.includes("cancel") || event.includes("refund") || event.includes("rejected"))
                                                          return "bg-danger-light text-danger";
        if (event.includes("note"))                      return "bg-amber-50 text-amber-600";
        return "bg-surface-100 text-surface-500";
    };

    return (
        <div className="fixed inset-0 z-40 flex justify-end">
            <div className="flex-1 bg-black/20" onClick={onClose} />
            <div className="w-full max-w-md bg-white shadow-2xl flex flex-col animate-slide-left">
                <div className="flex items-center justify-between px-5 py-4 border-b border-surface-100 shrink-0">
                    <div>
                        <h2 className="font-bold text-surface-900">Audit Trail</h2>
                        <p className="text-2xs text-surface-400 mt-0.5">All activity on this order</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <button onClick={() => refetch()} className="btn-ghost btn-icon btn-sm"
aria-label="Refresh" title="Refresh">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                        </button>
                        <button onClick={onClose} className="btn-ghost btn-icon btn-sm"
aria-label="Close">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
                <div className="flex-1 overflow-y-auto p-4">
                    {isLoading ? (
                        <div className="flex justify-center py-12"><Spinner /></div>
                    ) : logs.length === 0 ? (
                        <div className="text-center py-12 space-y-2">
                            <svg className="w-8 h-8 text-surface-300 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/></svg>
                            <p className="text-surface-400 text-xs">No activity recorded yet.</p>
                            <p className="text-surface-300 text-2xs">Actions on this order will appear here.</p>
                        </div>
                    ) : (
                        <div className="relative">
                            <div className="absolute left-[18px] top-4 bottom-4 w-px bg-surface-100" />
                            <div className="space-y-5">
                                {logs.map((log: any) => {
                                    // Backend returns event + label (human-readable) + summary (rich detail)
                                    const event   = log.event ?? log.action ?? "";
                                    const label   = log.label  ?? event.replace(/_/g, " ");
                                    // Prefer the backend-computed summary; fall back to description
                                    const summary = log.summary ?? log.description ?? null;
                                    // Actor: backend returns nested {id, name, email} or flat actor_name
                                    const actorName = log.actor?.name ?? log.actor_name ?? "System";
                                    return (
                                    <div key={log.id} className="flex items-start gap-3">
                                        <div className={clsx("w-9 h-9 rounded-full flex items-center justify-center shrink-0 relative z-10", actionColor(event))}>
                                            {actionIcon(event)}
                                        </div>
                                        <div className="flex-1 min-w-0 pt-1">
                                            <div className="flex items-baseline justify-between gap-2">
                                                <p className="text-xs font-semibold text-surface-900 capitalize">{label}</p>
                                                <time className="text-2xs text-surface-400 shrink-0">
                                                    {new Date(log.created_at).toLocaleString("en-KE", { dateStyle: "short", timeStyle: "short" })}
                                                </time>
                                            </div>
                                            {summary && summary !== label && (
                                                <p className="text-2xs text-surface-600 mt-0.5 leading-relaxed">{summary}</p>
                                            )}
                                            <p className="text-2xs text-surface-400 mt-1 flex items-center gap-1">
                                                <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                                                <span className="font-medium text-surface-500">{actorName}</span>
                                                {log.ip_address && <span className="text-surface-300 ml-1">· {log.ip_address}</span>}
                                            </p>
                                        </div>
                                    </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function OrderDetailPage() {
    const { download: downloadPdf, loading: pdfLoading } = usePdfDownload();
    const { id }   = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast    = useToastStore();
    const qc       = useQueryClient();

    const [showStatusModal,   setShowStatusModal]   = useState(false);
    const [showRefundModal,   setShowRefundModal]    = useState(false);
    const [showVoidModal,     setShowVoidModal]      = useState(false);
    const [showPaymentModal,  setShowPaymentModal]   = useState(false);
    const [showReceiptModal,  setShowReceiptModal]   = useState(false);
    const [showShippingModal, setShowShippingModal]  = useState(false);
    const [showDepositModal,  setShowDepositModal]   = useState(false);
    const [showCustomerModal, setShowCustomerModal]  = useState(false);
    const [showAuditLog,      setShowAuditLog]       = useState(false);
    const [showCurrencyModal, setShowCurrencyModal]  = useState(false);
    const [noteText,          setNoteText]           = useState("");
    const [noteInternal,      setNoteInternal]       = useState(true);

    // Phase 4 - payment link + M-Pesa verify
    const [paymentLink,       setPaymentLink]        = useState<string | null>(null);
    const [paymentLinkLoading, setPaymentLinkLoading] = useState(false);
    const [verifyingPaymentId,         setVerifyingPaymentId]         = useState<number | null>(null);
    const [mpesaCode,                  setMpesaCode]                  = useState("");
    const [verifyingPaystackPaymentId, setVerifyingPaystackPaymentId] = useState<number | null>(null);
    const [paystackVerifyRef,          setPaystackVerifyRef]          = useState("");

    const { data, isLoading } = useQuery({
        queryKey: ["order", id],
        queryFn:  () => ordersApi.get(Number(id)),
        enabled:  !!id,
    });
    // ordersApi.get returns { order: Order } - unwrap
    const order: Order | undefined = (data as any)?.order ?? data as any;

    const refresh = () => qc.invalidateQueries({ queryKey: ["order", id] });

    // ── Price adjustment ──────────────────────────────────────────────────────
    const [adjustingItemId, setAdjustingItemId] = useState<number | null>(null);
    const [priceInput, setPriceInput] = useState<string>("");
    const adjustPriceMutation = useMutation({
        mutationFn: ({ itemId, price }: { itemId: number; price: number }) =>
            ordersApi.adjustItemPrice(order!.id, itemId, price),
        onSuccess: () => { refresh(); setAdjustingItemId(null); toast.success("Price updated"); },
        onError:   (e: any) => toast.error(e?.message ?? "Failed to update price"),
    });

    const noteMutation = useMutation({
        mutationFn: () => ordersApi.addNote(order!.id, { note: noteText, is_internal: noteInternal }),
        onSuccess:  () => { toast.success("Note added"); setNoteText(""); refresh(); },
        onError:    (e: ApiError) => toast.error(e.message),
    });

    const resendMutation = useMutation({
        mutationFn: () => ordersApi.resendConfirmation(order!.id),
        onSuccess:  () => toast.success("Confirmation email sent"),
        onError:    (e: ApiError) => toast.error(e.message),
    });

    // Phase 4 - fetch/generate payment link
    const handleGetPaymentLink = async () => {
        if (!order) return;
        setPaymentLinkLoading(true);
        try {
            const res = await ordersApi.getPaymentLink(order.id);
            const url = res.payment_url ?? res.url;
            setPaymentLink(url);
            await navigator.clipboard.writeText(url).catch(() => {});
            toast.success("Payment link copied to clipboard!");
        } catch (e: any) {
            toast.error(e.message ?? "Could not generate payment link");
        } finally {
            setPaymentLinkLoading(false);
        }
    };

    // Phase 4 - M-Pesa offline verification mutation
    const verifyMpesaMutation = useMutation({
        mutationFn: ({ paymentId, code }: { paymentId: number; code: string }) =>
            ordersApi.verifyMpesa(order!.id, paymentId, code || undefined),
        onSuccess: (res) => {
            toast.success(res.message);
            setVerifyingPaymentId(null);
            setMpesaCode("");
            refresh();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const verifyPaystackMutation = useMutation({
        mutationFn: ({ paymentId, reference }: { paymentId: number; reference: string }) =>
            ordersApi.verifyPaystack(order!.id, paymentId, reference),
        onSuccess: (res) => {
            toast.success(res.message ?? "Paystack payment confirmed ✓");
            setVerifyingPaystackPaymentId(null);
            setPaystackVerifyRef("");
            refresh();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    if (!order)   return <div className="text-center text-surface-400 py-20">Order not found.</div>;

    const nextStatuses   = STATUS_FLOW[order.status]?.next ?? [];
    const canUpdateStatus = nextStatuses.length > 0;
    const canRefund = ["completed"].includes(order.status);
    // Void is admin-only: allowed before shipping/delivery, not after payment
    const canVoid = ["pending", "processing", "confirmed"].includes(order.status);
    const cc = order.currency_code ?? "KES";

    // Customer can be attached/updated while order is still open
    const canAttachCustomer = ["pending", "pending_payment", "processing", "confirmed"].includes(order.status);

    // Country/currency can only be changed while no payment has been started
    const canChangeCurrency = order.payment_status === "pending" &&
        !["cancelled", "voided", "refunded"].includes(order.status);

    // Payment summary
    const totalPaid = order.payments
        ?.filter((p: any) => p.status === "paid")
        .reduce((s: number, p: any) => s + Number(p.amount), 0) ?? 0;
    const outstanding = Math.max(0, order.total_amount - totalPaid);
    const canAddPayment = outstanding > 0 && !["cancelled", "voided", "refunded"].includes(order.status);

    // Payments awaiting admin approval - blocks order progression
    const pendingApprovalPayments = ((order as any).payments ?? []).filter(
        (p: any) => p.requires_approval && p.approval_status === "pending_review"
    );
    const hasPendingApproval = pendingApprovalPayments.length > 0;

    return (
        <div className="animate-fade-in max-w-5xl mx-auto pb-12 space-y-4">

            {/* ── Pending approval banner ───────────────────────────────────── */}
            {hasPendingApproval && (
                <div className="flex items-start gap-3 bg-amber-50 border border-amber-300 rounded-2xl px-5 py-4">
                    <div className="w-8 h-8 rounded-xl bg-amber-100 flex items-center justify-center shrink-0">
                        <svg className="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <p className="text-sm font-bold text-amber-900">
                            {pendingApprovalPayments.length} payment{pendingApprovalPayments.length > 1 ? "s" : ""} awaiting admin approval
                        </p>
                        <p className="text-xs text-amber-800 mt-0.5">This order cannot be progressed until all pending payments are reviewed.</p>
                        <Link to="/approvals" className="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-amber-900 underline underline-offset-2 hover:text-amber-700">
                            Review in Approvals queue →
                        </Link>
                    </div>
                </div>
            )}

            {/* ── Main document shell ───────────────────────────────────────── */}
            <div className="bg-white rounded-2xl shadow-sm border border-surface-100 overflow-hidden">

                {/* ── Top action bar ──────────────────────────────────────────── */}
                <div className="flex items-center justify-between gap-3 px-6 py-3 bg-surface-50/80 border-b border-surface-100">
                    <div className="flex items-center gap-2.5 min-w-0">
                        <button onClick={() => navigate("/sales/orders")}
                            className="flex items-center gap-1.5 text-xs text-surface-500 hover:text-surface-900 font-medium transition-colors shrink-0">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                            </svg>
                            All Orders
                        </button>
                        {(order as any).is_international && (
                            <span className="inline-flex items-center gap-1 text-xs font-semibold px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200 shrink-0">
                                🌐 International ({order.currency_code})
                            </span>
                        )}
                        {canChangeCurrency && (
                            <button onClick={() => setShowCurrencyModal(true)}
                                className="inline-flex items-center gap-1 text-xs text-surface-500 hover:text-brand-600 font-medium transition-colors shrink-0 px-2 py-1 rounded-lg hover:bg-surface-100"
                                title="Change country / currency">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253M3 12a8.96 8.96 0 01.284-2.253"/>
                                </svg>
                                {(order as any).is_international ? "Change country" : `${order.currency_code} · Set country`}
                            </button>
                        )}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        {canUpdateStatus && (
                            <button onClick={() => setShowStatusModal(true)} className="btn-secondary btn-sm">Update Status</button>
                        )}
                        {canAddPayment && (
                            <button onClick={() => setShowPaymentModal(true)}
                                className="btn-sm gap-1.5 bg-success text-white hover:bg-success/90 transition-colors rounded-lg px-3 font-medium">
                                + Add Payment
                            </button>
                        )}
                        {outstanding > 0 && !["cancelled", "voided", "refunded"].includes(order.status) && (
                            <button onClick={handleGetPaymentLink} disabled={paymentLinkLoading} className="btn-secondary btn-sm gap-1.5">
                                {paymentLinkLoading ? <Spinner size="xs" /> : <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>}
                                Payment Link
                            </button>
                        )}
                        <MoreActionsMenu items={[
                            canAttachCustomer && { icon: <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>, label: order.customer_name ? "Edit Customer" : "Attach Customer", onClick: () => setShowCustomerModal(true) },
                            { icon: <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0" /></svg>, label: "Receipt", onClick: () => setShowReceiptModal(true) },
                            { icon: <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>, label: pdfLoading ? "Generating…" : "Download Order PDF", onClick: () => downloadPdf("orders", order.id), disabled: pdfLoading },
                            { icon: <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>, label: pdfLoading ? "Generating…" : "Download Invoice PDF", onClick: () => downloadPdf("orders", order.id, "invoice"), disabled: pdfLoading },
                            { icon: <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/></svg>, label: showAuditLog ? "Hide Audit Log" : "Audit Log", onClick: () => setShowAuditLog(v => !v) },
                            order.order_type !== "pos" && { icon: <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>, label: "Resend Confirmation", onClick: () => resendMutation.mutate(), disabled: resendMutation.isPending },
                            canRefund && { icon: <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>, label: "Refund", onClick: () => setShowRefundModal(true) },
                            canVoid && { icon: <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>, label: "Void Order", onClick: () => setShowVoidModal(true), danger: true },
                        ].filter(Boolean) as MoreActionItem[]} />
                    </div>
                </div>

                {/* ── Payment link banner ───────────────────────────────────── */}
                {paymentLink && outstanding > 0 && (
                    <div className="mx-6 mt-4 flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
                        <svg className="w-4 h-4 text-blue-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-blue-900">Customer payment link</p>
                            <a href={paymentLink} target="_blank" rel="noopener noreferrer" className="text-2xs text-blue-600 hover:underline truncate block font-mono">{paymentLink}</a>
                        </div>
                        <button onClick={() => { navigator.clipboard.writeText(paymentLink).catch(() => {}); toast.success("Copied!"); }} className="btn-secondary btn-sm text-2xs gap-1 shrink-0">
                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>Copy
                        </button>
                        <a href={paymentLink} target="_blank" rel="noopener noreferrer" className="btn-secondary btn-sm text-2xs gap-1 shrink-0">
                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>Open
                        </a>
                    </div>
                )}

                {/* ── Hero header ───────────────────────────────────────────── */}
                <div className="px-6 pt-6 pb-5 sm:px-8 sm:pt-8">
                    <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <p className="text-2xs font-bold tracking-[0.18em] text-surface-400 uppercase mb-1.5">Bethany House</p>
                            <h1 className="text-2xl sm:text-3xl font-bold font-mono text-surface-900 tracking-tight leading-none">{order.order_number}</h1>
                            <div className="flex flex-wrap items-center gap-2 mt-3">
                                <span className={clsx("inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full border",
                                    order.status === "completed"  ? "bg-success-light text-success-dark border-success/20" :
                                    order.status === "shipped"    ? "bg-purple-50 text-purple-700 border-purple-200" :
                                    order.status === "confirmed"  ? "bg-blue-50 text-blue-700 border-blue-200" :
                                    order.status === "processing" ? "bg-brand-50 text-brand-700 border-brand-200" :
                                    order.status === "pending"    ? "bg-warning-light text-warning-dark border-warning/20" :
                                    order.status === "cancelled"  ? "bg-danger-light text-danger border-danger/20" :
                                    "bg-surface-100 text-surface-500 border-surface-200")}>
                                    <span className={clsx("w-1.5 h-1.5 rounded-full",
                                        order.status === "completed"  ? "bg-success" :
                                        order.status === "shipped"    ? "bg-purple-500" :
                                        order.status === "confirmed"  ? "bg-blue-500" :
                                        order.status === "processing" ? "bg-brand-500" :
                                        order.status === "pending"    ? "bg-warning" :
                                        order.status === "cancelled"  ? "bg-danger" : "bg-surface-400")} />
                                    {STATUS_FLOW[order.status]?.label ?? order.status}
                                </span>
                                <span className="inline-flex items-center gap-1.5 text-xs text-surface-500 font-medium bg-surface-50 border border-surface-100 px-2.5 py-1.5 rounded-full">
                                    {order.order_type === "pos"
                                        ? <svg className="w-3.5 h-3.5 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"/></svg>
                                        : <svg className="w-3.5 h-3.5 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                                    }
                                    {order.order_type === "pos" ? "POS Sale" : "Online Order"}
                                    {order.outlet_name && <><span className="text-surface-300">·</span><span>{order.outlet_name}</span></>}
                                </span>
                                {order.production_orders && order.production_orders.length > 0 && (
                                    <span className="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-full bg-purple-50 text-purple-700 border border-purple-200">
                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                                        {order.production_orders.length} production order{order.production_orders.length !== 1 ? "s" : ""}
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="sm:text-right shrink-0">
                            <p className="text-2xs text-surface-400 uppercase tracking-wide font-semibold mb-1">
                                {outstanding > 0 ? "Amount Due" : "Total Charged"}
                            </p>
                            <p className={clsx("text-3xl sm:text-4xl font-bold tabular-nums tracking-tight",
                                outstanding > 0 ? "text-danger" : "text-surface-900")}>
                                {fmt(outstanding > 0 ? outstanding : order.total_amount, cc)}
                            </p>
                            <div className="flex items-center gap-2 mt-2.5 sm:justify-end flex-wrap">
                                <span className={clsx("text-xs font-semibold px-3 py-1 rounded-full",
                                    order.payment_status === "paid"    ? "bg-success-light text-success-dark" :
                                    order.payment_status === "deposit" ? "bg-blue-50 text-blue-700" :
                                    order.payment_status === "partial" ? "bg-warning-light text-warning-dark" :
                                    "bg-surface-100 text-surface-500")}>
                                    {order.payment_status === "deposit" ? "Deposit paid" :
                                     order.payment_status === "partial" ? "Partially paid" :
                                     order.payment_status === "paid"    ? "Paid in full" : "Unpaid"}
                                </span>
                                <span className="font-mono text-xs text-surface-400 font-bold">{cc}</span>
                            </div>
                            <p className="text-2xs text-surface-400 mt-2">
                                {new Date(order.created_at).toLocaleDateString("en-KE", { dateStyle: "long" })}
                            </p>
                        </div>
                    </div>
                </div>

                {/* ── Body ─────────────────────────────────────────────────── */}
                <div className="border-t border-surface-100 grid grid-cols-1 lg:grid-cols-[1fr_272px] lg:divide-x divide-surface-100">

                    {/* ══ LEFT COLUMN ══════════════════════════════════════════ */}
                    <div className="p-6 sm:p-8 space-y-0">

                        {/* Bill To / Ship To */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-6 pb-8">
                            <div>
                                <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2.5">Bill To</p>
                                {order.customer_name ? (
                                    <div className="flex items-start gap-3">
                                        <div className="w-8 h-8 rounded-lg bg-surface-100 flex items-center justify-center shrink-0 text-surface-500 font-bold text-sm">
                                            {order.customer_name.charAt(0).toUpperCase()}
                                        </div>
                                        <div className="space-y-0.5">
                                            <p className="text-sm font-semibold text-surface-900">{order.customer_name}</p>
                                            {order.customer_email && <p className="text-xs text-surface-500">{order.customer_email}</p>}
                                            {order.customer_phone && <p className="text-xs text-surface-500">{order.customer_phone}</p>}
                                            {order.user_id && (
                                                <button onClick={() => navigate(`/sales/customers/${order.user_id}`)}
                                                    className="text-2xs text-brand-500 hover:underline mt-1 block">View profile →</button>
                                            )}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="flex items-center gap-2">
                                        <div className="w-8 h-8 rounded-lg bg-surface-100 flex items-center justify-center shrink-0">
                                            <svg className="w-4 h-4 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}><path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                        </div>
                                        <p className="text-xs text-surface-400 italic">Walk-in / Guest</p>
                                    </div>
                                )}
                            </div>
                            {order.shipping_address ? (
                                <div>
                                    <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2.5">Ship To</p>
                                    <div className="text-xs text-surface-600 space-y-0.5">
                                        <p className="font-semibold text-surface-900">{order.shipping_address.name}</p>
                                        <p>{order.shipping_address.address_line_1}</p>
                                        {order.shipping_address.address_line_2 && <p>{order.shipping_address.address_line_2}</p>}
                                        <p>{order.shipping_address.city}{order.shipping_address.state ? `, ${order.shipping_address.state}` : ""}</p>
                                        <p>{order.shipping_address.country}</p>
                                    </div>
                                </div>
                            ) : order.cashier_name ? (
                                <div>
                                    <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2.5">Served By</p>
                                    <div className="flex items-center gap-2">
                                        <div className="w-8 h-8 rounded-lg bg-brand-50 flex items-center justify-center shrink-0 text-brand-600 font-bold text-sm">
                                            {order.cashier_name.charAt(0).toUpperCase()}
                                        </div>
                                        <p className="text-sm font-medium text-surface-700">{order.cashier_name}</p>
                                    </div>
                                </div>
                            ) : null}
                        </div>

                        {/* Items */}
                        <div className="py-8 border-t border-surface-100">
                            <div className="flex items-center gap-2 mb-4">
                                <div className="w-6 h-6 rounded-lg bg-surface-100 flex items-center justify-center">
                                    <svg className="w-3.5 h-3.5 text-surface-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z"/></svg>
                                </div>
                                <p className="text-xs font-bold text-surface-700 uppercase tracking-widest">Items</p>
                            </div>
                            <div className="rounded-xl border border-surface-100 overflow-hidden">
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="bg-surface-50 border-b border-surface-100">
                                            <th className="text-left px-4 py-2.5 font-semibold text-surface-500 w-6">#</th>
                                            <th className="text-left px-4 py-2.5 font-semibold text-surface-500">Description</th>
                                            <th className="text-right px-4 py-2.5 font-semibold text-surface-500 w-10">Qty</th>
                                            <th className="text-right px-4 py-2.5 font-semibold text-surface-500 w-14">Tax</th>
                                            <th className="text-right px-4 py-2.5 font-semibold text-surface-500 w-24">Unit</th>
                                            <th className="text-right px-4 py-2.5 font-semibold text-surface-500 w-24">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {(order.items ?? []).map((item: any, i: number) => (
                                            <tr key={item.id ?? i} className="hover:bg-surface-50/50 transition-colors">
                                                <td className="px-4 py-3 text-surface-300 align-top">{i + 1}</td>
                                                <td className="px-4 py-3 align-top">
                                                    <div className="flex items-start gap-2.5">
                                                        {item.image_url && <img src={item.image_url} alt="" className="w-9 h-9 rounded-lg object-cover shrink-0 mt-0.5 border border-surface-100" />}
                                                        <div>
                                                            <p className="font-semibold text-surface-900">{item.product_name}</p>
                                                            {item.variant_name && <p className="text-surface-400 mt-0.5">{item.variant_name}</p>}
                                                            {item.sku && <p className="text-surface-300 font-mono mt-0.5 text-2xs">SKU: {item.sku}</p>}
                                                            <div className="flex flex-wrap gap-1 mt-1">
                                                                {item.discount_amount > 0 && (
                                                                    <span className="inline-flex items-center text-2xs font-medium text-warning-dark bg-warning-light px-1.5 py-0.5 rounded">
                                                                        Disc: -{fmt(item.discount_amount, "")}
                                                                    </span>
                                                                )}
                                                                {(item as any).price_adjusted && (
                                                                    <span className="inline-flex items-center text-2xs font-medium text-orange-700 bg-orange-100 px-1.5 py-0.5 rounded" title={`Original: ${fmt((item as any).original_price ?? 0, "")}`}>
                                                                        ✎ Adjusted
                                                                    </span>
                                                                )}
                                                                {item.tax_name && (
                                                                    <span className="inline-flex items-center text-2xs font-medium text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                                                        {item.tax_name}{item.tax_rate ? ` ${item.tax_rate}%` : ""}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right text-surface-600 align-top tabular-nums font-medium">{item.quantity}</td>
                                                <td className="px-4 py-3 text-right align-top">
                                                    {(item.tax_rate ?? 0) > 0
                                                        ? <span className="text-2xs font-semibold text-surface-500 bg-surface-100 rounded-full px-2 py-0.5">{item.tax_rate}%</span>
                                                        : <span className="text-2xs text-surface-200">—</span>
                                                    }
                                                </td>
                                                <td className="px-4 py-3 text-right align-top">
                                                    {adjustingItemId === item.id ? (
                                                        <div className="flex items-center justify-end gap-1">
                                                            <input
                                                                type="number"
                                                                min={item.original_price ?? item.unit_price}
                                                                step="1"
                                                                value={priceInput}
                                                                onChange={e => setPriceInput(e.target.value)}
                                                                onKeyDown={e => {
                                                                    if (e.key === "Enter") { const p = parseFloat(priceInput); if (!isNaN(p)) adjustPriceMutation.mutate({ itemId: item.id, price: p }); }
                                                                    if (e.key === "Escape") setAdjustingItemId(null);
                                                                }}
                                                                autoFocus
                                                                className="w-24 text-xs border border-orange-300 rounded px-2 py-1 focus:outline-none focus:border-orange-500 bg-orange-50 text-right tabular-nums"
                                                            />
                                                            <button onClick={() => { const p = parseFloat(priceInput); if (!isNaN(p)) adjustPriceMutation.mutate({ itemId: item.id, price: p }); }} disabled={adjustPriceMutation.isPending} className="text-xs font-semibold text-orange-700 hover:text-orange-900">{adjustPriceMutation.isPending ? "…" : "Set"}</button>
                                                            <button onClick={() => setAdjustingItemId(null)} className="text-xs text-surface-400 hover:text-surface-600">✕</button>
                                                        </div>
                                                    ) : (
                                                        <div className="flex items-center justify-end gap-1 group">
                                                            <div className="text-right">
                                                                <span className="tabular-nums text-surface-600">{fmt(item.unit_price, "")}</span>
                                                                {(item as any).price_adjusted && (item as any).original_price && (
                                                                    <p className="text-2xs text-orange-500" title="Price was manually adjusted">
                                                                        was {fmt((item as any).original_price, "")}
                                                                    </p>
                                                                )}
                                                            </div>
                                                            {order.payment_status === "pending" && (
                                                                <button onClick={() => { setAdjustingItemId(item.id); setPriceInput(String(item.unit_price)); }} title="Adjust price (upward only)" className="opacity-0 group-hover:opacity-100 transition-opacity text-orange-400 hover:text-orange-600 ml-1">
                                                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" />
                                                                    </svg>
                                                                </button>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right font-bold text-surface-900 align-top tabular-nums">
                                                    {fmt((item.unit_price ?? 0) * (item.quantity ?? 1) - (item.discount_amount ?? 0) + ((order.prices_include_tax ?? true) ? 0 : (item.tax_amount ?? 0)), "")}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {/* Totals */}
                            <div className="mt-4 flex justify-end">
                                <div className="w-64 space-y-1.5 text-xs">
                                    <div className="flex justify-between text-surface-500 py-1.5 border-b border-surface-50">
                                        <span>Subtotal</span><span className="tabular-nums font-medium text-surface-700">{fmt(order.subtotal, cc)}</span>
                                    </div>
                                    {order.discount_amount > 0 && (
                                        <div className="flex justify-between text-warning-dark py-1.5 border-b border-surface-50">
                                            <span>Discount</span><span className="tabular-nums">-{fmt(order.discount_amount, cc)}</span>
                                        </div>
                                    )}
                                    <div className="flex justify-between text-surface-500 py-1.5 border-b border-surface-50">
                                        <span className="flex items-center gap-1.5">Shipping
                                            {order.payment_status !== "paid" && (
                                                <button onClick={() => setShowShippingModal(true)} className="text-brand-500 hover:underline text-2xs">{order.shipping_amount > 0 ? "edit" : "+ add"}</button>
                                            )}
                                        </span>
                                        <span className="tabular-nums text-right">
                                            {order.shipping_method && <span className="text-surface-400 mr-1.5 font-normal">{order.shipping_method}</span>}
                                            {fmt(order.shipping_amount ?? 0, cc)}
                                        </span>
                                    </div>
                                    {order.tax_amount > 0 && (
                                        <div className="flex justify-between text-surface-500 py-1.5 border-b border-surface-50">
                                            <span className="flex items-center gap-1.5">Tax
                                                {order.prices_include_tax
                                                    ? <span className="text-2xs bg-surface-100 text-surface-400 rounded px-1 py-0.5">incl.</span>
                                                    : <span className="text-2xs bg-warning-light text-warning-dark rounded px-1 py-0.5">excl.</span>
                                                }
                                            </span>
                                            <span className="tabular-nums">{fmt(order.tax_amount, cc)}</span>
                                        </div>
                                    )}
                                    {(order.tax_breakdown ?? []).length > 1 && (
                                        <div className="space-y-0.5 pb-1 border-b border-surface-50">
                                            {(order.tax_breakdown ?? []).map((b, i) => (
                                                <div key={i} className="flex justify-between text-2xs text-surface-400 pl-2">
                                                    <span>@ {b.rate}%</span><span className="tabular-nums">{fmt(b.amount, cc)}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    <div className="flex justify-between font-bold text-sm pt-2 border-t-2 border-surface-900">
                                        <span>Total</span><span className="tabular-nums text-surface-900">{fmt(order.total_amount, cc)}</span>
                                    </div>
                                    {totalPaid > 0 && <div className="flex justify-between text-success-dark font-medium pt-1"><span>Paid</span><span className="tabular-nums">{fmt(totalPaid, cc)}</span></div>}
                                    {outstanding > 0 && <div className="flex justify-between text-danger font-bold text-sm pt-1 border-t border-danger/20"><span>Balance Due</span><span className="tabular-nums">{fmt(outstanding, cc)}</span></div>}
                                    {order.deposit_amount && (
                                        <div className="flex justify-between text-surface-400 text-2xs pt-1">
                                            <span className="flex items-center gap-1.5">Min. Deposit {order.payment_status !== "paid" && <button onClick={() => setShowDepositModal(true)} className="text-brand-500 hover:underline">edit</button>}</span>
                                            <span className="tabular-nums">{fmt(order.deposit_amount, cc)}</span>
                                        </div>
                                    )}
                                    {order.balance_due_date && (
                                        <div className={clsx("flex justify-between text-2xs", new Date(order.balance_due_date) < new Date() ? "text-danger font-semibold" : "text-surface-400")}>
                                            <span>Balance due by</span>
                                            <span>{new Date(order.balance_due_date).toLocaleDateString("en-KE", { dateStyle: "medium" })}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Production Orders — purple tinted band */}
                        {order.production_orders && order.production_orders.length > 0 && (() => {
                            const PROD_CFG: Record<string, {label:string; cls:string; dot:string}> = {
                                draft:       {label:"Draft",       cls:"bg-surface-100 text-surface-500",    dot:"bg-surface-400"},
                                pending:     {label:"Queued",      cls:"bg-blue-50 text-blue-700",           dot:"bg-blue-500"},
                                in_progress: {label:"In Progress", cls:"bg-brand-50 text-brand-700",         dot:"bg-brand-500"},
                                qc_pending:  {label:"QC Check",    cls:"bg-purple-50 text-purple-700",       dot:"bg-purple-500"},
                                qc_passed:   {label:"QC Passed",   cls:"bg-success-light text-success-dark", dot:"bg-success"},
                                qc_failed:   {label:"QC Failed",   cls:"bg-danger-light text-danger",        dot:"bg-danger"},
                                completed:   {label:"Completed",   cls:"bg-success-light text-success-dark", dot:"bg-success"},
                                cancelled:   {label:"Cancelled",   cls:"bg-surface-100 text-surface-400",    dot:"bg-surface-300"},
                            };
                            return (
                                <div className="-mx-6 sm:-mx-8 px-6 sm:px-8 py-6 bg-purple-50/40 border-y border-purple-100/60">
                                    <div className="flex items-center gap-2 mb-4">
                                        <div className="w-6 h-6 rounded-lg bg-purple-50 flex items-center justify-center">
                                            <svg className="w-3.5 h-3.5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                                        </div>
                                        <p className="text-xs font-bold text-surface-700 uppercase tracking-widest">Production Orders</p>
                                        <span className="text-2xs font-bold bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded-full">{order.production_orders.length}</span>
                                    </div>
                                    <ProductionOrdersSection productionOrders={order.production_orders} PROD_CFG={PROD_CFG} />
                                </div>
                            );
                        })()}

                        {/* Payments — green tinted band */}
                        <div className="-mx-6 sm:-mx-8 px-6 sm:px-8 py-6 bg-emerald-50/50 border-y border-emerald-100/80">
                            <div className="flex items-center justify-between mb-4">
                                <div className="flex items-center gap-2">
                                    <div className="w-6 h-6 rounded-lg bg-success-light flex items-center justify-center">
                                        <svg className="w-3.5 h-3.5 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
                                    </div>
                                    <p className="text-xs font-bold text-surface-700 uppercase tracking-widest">Payments</p>
                                </div>
                                {canAddPayment && (
                                    <button onClick={() => setShowPaymentModal(true)}
                                        className="inline-flex items-center gap-1 text-2xs bg-success-light text-success-dark border border-success/30 hover:bg-success/20 rounded-lg px-2.5 py-1 font-semibold transition-colors">
                                        + Record Payment
                                    </button>
                                )}
                            </div>
                            {order.payments && order.payments.length > 0 ? (
                                <div className="rounded-xl border border-surface-100 overflow-hidden">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="bg-surface-50 border-b border-surface-100">
                                                <th className="text-left px-4 py-2.5 font-semibold text-surface-500">Method</th>
                                                <th className="text-left px-4 py-2.5 font-semibold text-surface-500">Reference / Proof</th>
                                                <th className="text-left px-4 py-2.5 font-semibold text-surface-500 hidden sm:table-cell">Date</th>
                                                <th className="text-left px-4 py-2.5 font-semibold text-surface-500">Status</th>
                                                <th className="text-right px-4 py-2.5 font-semibold text-surface-500">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-surface-50">
                                            {order.payments.map((p: any) => (
                                                <tr key={p.id} className={clsx("hover:bg-surface-50/50 transition-colors",
                                                    p.requires_approval && p.approval_status !== "approved" && "bg-amber-50/40")}>
                                                    <td className="px-4 py-3 align-top">
                                                        <span className="flex items-center gap-1.5 font-medium text-surface-900">
                                                            <span className="w-6 h-6 rounded-lg bg-surface-100 flex items-center justify-center shrink-0">
                                                                <PaymentMethodIcon method={p.payment_method} className="w-3.5 h-3.5 text-surface-500" />
                                                            </span>
                                                            {PAYMENT_METHODS[p.payment_method]?.label ?? p.payment_method}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 align-top">
                                                        <p className="font-mono text-surface-500 text-2xs">{p.payment_number ?? "-"}</p>
                                                        {p.provider_reference && <p className="text-surface-400 font-mono text-2xs">{p.provider_reference}</p>}
                                                        {p.requires_approval && (
                                                            <span className={clsx("inline-flex items-center gap-1 text-2xs font-semibold px-2 py-0.5 rounded-full mt-1",
                                                                p.approval_status === "approved" ? "bg-success-light text-success-dark" :
                                                                p.approval_status === "rejected" ? "bg-danger-light text-danger" :
                                                                "bg-amber-100 text-amber-700")}>
                                                                {p.approval_status === "approved" ? "✓ Approved" : p.approval_status === "rejected" ? "✗ Rejected" : "⏳ Awaiting approval"}
                                                            </span>
                                                        )}
                                                        {p.status !== "refunded" && p.approval_status !== "approved" && (
                                                            <div className="mt-1.5"><ProofUploadButton paymentId={p.id} onDone={refresh} /></div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 align-top text-surface-500 hidden sm:table-cell">
                                                        {p.paid_at ? new Date(p.paid_at).toLocaleDateString("en-KE", {dateStyle:"medium"}) : "—"}
                                                    </td>
                                                    <td className="px-4 py-3 align-top">
                                                        <span className={clsx("inline-flex items-center text-2xs font-semibold px-2 py-0.5 rounded-full",
                                                            p.status === "paid" && (!p.requires_approval || p.approval_status === "approved") ? "bg-success-light text-success-dark" :
                                                            p.requires_approval ? "bg-amber-100 text-amber-700" :
                                                            "bg-surface-100 text-surface-500")}>
                                                            {p.requires_approval && p.approval_status !== "approved" ? "pending approval" : p.status}
                                                        </span>
                                                        {p.refund_amount > 0 && <p className="text-2xs text-danger mt-0.5">-{fmt(p.refund_amount, cc)} refunded</p>}
                                                        {p.status === "pending" && p.payment_method === "mpesa" && (
                                                            <button onClick={() => setVerifyingPaymentId(p.id)} className="mt-1 block text-2xs text-brand-600 hover:underline font-medium">Verify with Daraja →</button>
                                                        )}
                                                        {p.status === "pending" && (p.payment_method === "card_paystack" || p.payment_method === "paystack") && (
                                                            <button onClick={() => { setVerifyingPaystackPaymentId(p.id); setPaystackVerifyRef(""); }} className="mt-1 block text-2xs text-blue-600 hover:underline font-medium">Verify with Paystack →</button>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 align-top text-right font-bold text-surface-900 tabular-nums">{fmt(p.amount, cc)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="py-10 text-center border-2 border-dashed border-surface-100 rounded-xl">
                                    <div className="w-10 h-10 rounded-xl bg-surface-50 flex items-center justify-center mx-auto mb-2">
                                        <svg className="w-5 h-5 text-surface-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}><path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
                                    </div>
                                    <p className="text-xs text-surface-400 font-medium">No payments recorded yet</p>
                                    {canAddPayment && <button onClick={() => setShowPaymentModal(true)} className="mt-2 text-xs text-brand-500 hover:underline font-semibold">Record first payment →</button>}
                                </div>
                            )}
                            {outstanding > 0 && !order.deposit_amount && order.payment_status === "pending" && (
                                <div className="mt-3 flex justify-end">
                                    <button onClick={() => setShowDepositModal(true)}
                                        className="text-2xs border border-brand-200 text-brand-600 bg-brand-50 hover:bg-brand-100 rounded-lg px-3 py-1.5 font-medium transition-colors">
                                        Set Deposit Terms
                                    </button>
                                </div>
                            )}
                        </div>

                        {/* Shipping & Tracking — blue tinted band */}
                        <div className="-mx-6 sm:-mx-8 px-6 sm:px-8 py-6 bg-sky-50/50 border-b border-sky-100/80">
                            <div className="flex items-center gap-2 mb-4">
                                <div className="w-6 h-6 rounded-lg bg-blue-50 flex items-center justify-center">
                                    <svg className="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                                </div>
                                <p className="text-xs font-bold text-surface-700 uppercase tracking-widest">Shipping & Tracking</p>
                            </div>
                            {hasPendingApproval ? (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                                    <p className="font-semibold">Shipment on hold</p>
                                    <p className="mt-0.5">Shipment cannot be created until pending payments are approved.</p>
                                </div>
                            ) : !SHIPPABLE_STATUSES.includes(order.status) ? (
                                <div className="rounded-xl border border-surface-200 bg-surface-50 px-4 py-3 text-xs text-surface-500">
                                    <p className="font-semibold text-surface-700">Shipment not yet available</p>
                                    <p className="mt-0.5">
                                        {["pending","pending_payment"].includes(order.status) ? "Payment must be received before a shipment can be created."
                                        : order.status === "processing" ? "This order has a partial payment or pending approval. Once fully paid and confirmed, shipment can be created."
                                        : ["completed","delivered"].includes(order.status) ? "This order has already been delivered or completed."
                                        : "This order must reach Confirmed status before a shipment can be created."}
                                    </p>
                                </div>
                            ) : (
                                <ShipmentSection orderId={order.id} orderStatus={order.status} />
                            )}
                        </div>

                        {/* Order History — subtle slate band */}
                        {order.status_history && order.status_history.length > 0 && (
                            <div className="-mx-6 sm:-mx-8 px-6 sm:px-8 py-6 bg-slate-50/60 border-b border-slate-100/80">
                                <div className="flex items-center gap-2 mb-4">
                                    <div className="w-6 h-6 rounded-lg bg-surface-100 flex items-center justify-center">
                                        <svg className="w-3.5 h-3.5 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                    <p className="text-xs font-bold text-surface-700 uppercase tracking-widest">Order History</p>
                                </div>
                                <div className="relative pl-5">
                                    <div className="absolute left-[9px] top-2 bottom-2 w-px bg-surface-100" />
                                    <div className="space-y-4">
                                        {[...order.status_history].reverse().map((h: any, i: number) => (
                                            <div key={h.id ?? i} className="flex items-start gap-4 relative">
                                                <div className={clsx("w-3.5 h-3.5 rounded-full border-2 shrink-0 mt-0.5 z-10 bg-white",
                                                    i === 0 ? "border-brand-500" : "border-surface-300")} />
                                                <div className="flex-1 min-w-0 -mt-0.5">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <span className="text-xs font-semibold text-surface-900">
                                                            {STATUS_FLOW[h.new_status ?? h.status]?.label ?? (h.new_status ?? h.status)}
                                                        </span>
                                                        {(h.changed_by_name ?? h.created_by) && <span className="text-2xs text-surface-400">by {h.changed_by_name ?? h.created_by}</span>}
                                                    </div>
                                                    {h.notes && <p className="text-2xs text-surface-500 mt-0.5">{h.notes}</p>}
                                                    <p className="text-2xs text-surface-300 mt-0.5">{new Date(h.created_at).toLocaleString("en-KE", {dateStyle:"medium",timeStyle:"short"})}</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Notes */}
                        <div className="pt-8">
                            <div className="flex items-center gap-2 mb-4">
                                <div className="w-6 h-6 rounded-lg bg-warning-light flex items-center justify-center">
                                    <svg className="w-3.5 h-3.5 text-warning-dark" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                                </div>
                                <p className="text-xs font-bold text-surface-700 uppercase tracking-widest">Notes</p>
                            </div>
                            <div className="space-y-3">
                                {order.notes && (
                                    <div className="rounded-xl p-4 bg-warning-light border border-warning/20">
                                        <p className="text-xs font-semibold text-surface-900 flex items-center gap-2 mb-1.5">
                                            Staff Notes
                                            <span className="text-2xs font-semibold bg-warning text-white px-2 py-0.5 rounded-full">Internal</span>
                                        </p>
                                        <p className="text-xs text-surface-700 whitespace-pre-wrap">{order.notes}</p>
                                    </div>
                                )}
                                {order.customer_notes && (
                                    <div className="rounded-xl p-4 bg-surface-50 border border-surface-100">
                                        <p className="text-xs font-semibold text-surface-900 mb-1.5">Customer Notes</p>
                                        <p className="text-xs text-surface-700 whitespace-pre-wrap">{order.customer_notes}</p>
                                    </div>
                                )}
                                {!order.notes && !order.customer_notes && <p className="text-xs text-surface-300 italic">No notes on this order.</p>}
                                <div className="space-y-2 pt-1 border-t border-surface-100">
                                    <textarea value={noteText} onChange={e => setNoteText(e.target.value)}
                                        placeholder="Add a note…" rows={2} className="input resize-none text-xs w-full" />
                                    <div className="flex items-center justify-between">
                                        <label className="flex items-center gap-2 text-xs text-surface-500 cursor-pointer select-none">
                                            <input type="checkbox" checked={noteInternal} onChange={e => setNoteInternal(e.target.checked)} className="w-3.5 h-3.5 rounded border-surface-300" />
                                            Internal only
                                        </label>
                                        <button onClick={() => noteMutation.mutate()} disabled={!noteText.trim() || noteMutation.isPending} className="btn-primary btn-sm text-xs">
                                            {noteMutation.isPending ? "Saving…" : "Add Note"}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* ══ RIGHT SIDEBAR ════════════════════════════════════════ */}
                    <div className="p-5 space-y-4 bg-surface-50/40">

                        {/* Order Details card */}
                        <div className="bg-white rounded-xl border border-surface-100 overflow-hidden">
                            <div className="px-4 py-2.5 border-b border-surface-100 bg-surface-50">
                                <p className="text-2xs font-bold text-surface-500 uppercase tracking-widest">Order Details</p>
                            </div>
                            <div className="px-4 py-3 space-y-2.5 text-xs">
                                <div className="flex justify-between items-baseline gap-2">
                                    <span className="text-surface-400 shrink-0">Order #</span>
                                    <span className="font-mono font-bold text-surface-900 text-right break-all">{order.order_number}</span>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <span className="text-surface-400 shrink-0">Channel</span>
                                    <span className="text-surface-700 flex items-center gap-1">
                                        {order.order_type === "pos"
                                            ? <svg className="w-3.5 h-3.5 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 2.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"/></svg>
                                            : <svg className="w-3.5 h-3.5 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                                        }
                                        {order.order_type === "pos" ? "POS" : "Online"}
                                    </span>
                                </div>
                                {order.outlet_name && <div className="flex justify-between gap-2"><span className="text-surface-400 shrink-0">Outlet</span><span className="text-surface-700 text-right">{order.outlet_name}</span></div>}
                                {order.cashier_name && <div className="flex justify-between gap-2"><span className="text-surface-400 shrink-0">Cashier</span><span className="text-surface-700">{order.cashier_name}</span></div>}
                                <div className="flex justify-between gap-2"><span className="text-surface-400 shrink-0">Placed</span><span className="text-surface-700 text-right">{new Date(order.created_at).toLocaleString("en-KE", {dateStyle:"medium",timeStyle:"short"})}</span></div>
                                {order.delivery_type && <div className="flex justify-between gap-2"><span className="text-surface-400 shrink-0">Delivery</span><span className="text-surface-700 capitalize">{order.delivery_type}</span></div>}
                                {order.tracking_number && <div className="flex justify-between gap-2"><span className="text-surface-400 shrink-0">Tracking</span><span className="font-mono text-surface-700 text-right break-all">{order.tracking_number}</span></div>}
                            </div>
                        </div>

                        {/* Payment card */}
                        <div className="bg-white rounded-xl border border-surface-100 overflow-hidden">
                            <div className="px-4 py-2.5 border-b border-surface-100 bg-surface-50">
                                <p className="text-2xs font-bold text-surface-500 uppercase tracking-widest">Payment</p>
                            </div>
                            <div className="px-4 py-3 space-y-2.5 text-xs">
                                <div className="flex justify-between gap-2">
                                    <span className="text-surface-400 shrink-0">Method</span>
                                    <span className="flex items-center gap-1.5 text-surface-700">
                                        <PaymentMethodIcon method={order.payment_method} className="w-3.5 h-3.5 text-surface-500" />
                                        {PAYMENT_METHODS[order.payment_method]?.label ?? order.payment_method ?? "—"}
                                    </span>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <span className="text-surface-400 shrink-0">Status</span>
                                    <span className={clsx("font-semibold px-2 py-0.5 rounded-full text-2xs",
                                        order.payment_status === "paid"    ? "bg-success-light text-success-dark" :
                                        order.payment_status === "deposit" || order.payment_status === "partial" ? "bg-warning-light text-warning-dark" :
                                        "bg-surface-100 text-surface-500")}>
                                        {order.payment_status === "deposit" ? "Deposit paid" : order.payment_status === "partial" ? "Partially paid" : order.payment_status === "paid" ? "Paid in full" : "Pending"}
                                    </span>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <span className="text-surface-400 shrink-0">Currency</span>
                                    <span className="font-mono font-bold text-surface-700 flex items-center gap-1">{cc}{(order as any).is_international && <span className="text-blue-500">🌐</span>}</span>
                                </div>
                                {(order as any).customer_country_code && (
                                    <div className="flex justify-between gap-2">
                                        <span className="text-surface-400 shrink-0">Country</span>
                                        <span className="text-surface-700 font-medium">{(order as any).customer_country_code}{(order as any).is_international && <span className="ml-1 text-2xs text-blue-500">· International</span>}</span>
                                    </div>
                                )}
                            </div>
                            <div className="px-4 pb-4 pt-2 space-y-2 border-t border-surface-50">
                                {canChangeCurrency && (
                                    <button onClick={() => setShowCurrencyModal(true)}
                                        className="w-full text-xs py-2 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 font-medium transition-colors flex items-center justify-center gap-1.5">
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253M3 12a8.96 8.96 0 01.284-2.253"/></svg>
                                        Set Country / Currency
                                    </button>
                                )}
                                {canAddPayment && (
                                    <button onClick={() => setShowPaymentModal(true)}
                                        className="w-full text-xs py-2 rounded-lg border border-success/30 bg-success-light text-success-dark hover:bg-success/20 font-semibold transition-colors">
                                        + Record Payment
                                    </button>
                                )}
                                {outstanding > 0 && !order.deposit_amount && order.payment_status === "pending" && (
                                    <button onClick={() => setShowDepositModal(true)}
                                        className="w-full text-xs py-2 rounded-lg border border-brand-200 text-brand-600 bg-brand-50 hover:bg-brand-100 font-medium transition-colors">
                                        Set Deposit Terms
                                    </button>
                                )}
                                <button onClick={() => setShowShippingModal(true)}
                                    className="w-full text-xs py-2 rounded-lg border border-surface-200 text-surface-600 hover:bg-surface-100 font-medium transition-colors">
                                    {order.shipping_amount > 0 ? `Shipping: ${order.shipping_method ?? ""} ${fmt(order.shipping_amount, cc)} · Edit` : "Add Shipping"}
                                </button>
                            </div>
                        </div>

                        {/* Order Status card */}
                        {canUpdateStatus && (
                            <div className="bg-white rounded-xl border border-surface-100 overflow-hidden">
                                <div className="px-4 py-2.5 border-b border-surface-100 bg-surface-50">
                                    <p className="text-2xs font-bold text-surface-500 uppercase tracking-widest">Order Status</p>
                                </div>
                                <div className="px-4 py-3.5">
                                    <div className="mb-3 flex items-center gap-2">
                                        <span className={clsx("w-2 h-2 rounded-full shrink-0",
                                            order.status === "completed"  ? "bg-success" :
                                            order.status === "shipped"    ? "bg-purple-500" :
                                            order.status === "confirmed"  ? "bg-blue-500" :
                                            order.status === "processing" ? "bg-brand-500" :
                                            order.status === "pending"    ? "bg-warning" :
                                            order.status === "cancelled"  ? "bg-danger" : "bg-surface-400")} />
                                        <span className="text-xs font-semibold text-surface-900">{STATUS_FLOW[order.status]?.label ?? order.status}</span>
                                    </div>
                                    {STATUS_FLOW[order.status]?.description && (
                                        <p className="text-2xs text-surface-400 mb-3">{STATUS_FLOW[order.status]?.description}</p>
                                    )}
                                    <button onClick={() => setShowStatusModal(true)}
                                        className="w-full text-xs py-2 rounded-lg border border-brand-200 bg-brand-50 text-brand-700 hover:bg-brand-100 font-semibold transition-colors">
                                        Update Status →
                                    </button>
                                </div>
                            </div>
                        )}

                        {canRefund && (
                            <button onClick={() => setShowRefundModal(true)}
                                className="w-full text-xs py-2.5 rounded-xl border border-danger/30 text-danger hover:bg-danger-light font-medium transition-colors">
                                Process Refund
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {/* Modals */}
            {showStatusModal   && <StatusUpdateModal   order={order} onClose={() => setShowStatusModal(false)}   onUpdated={refresh} />}
            {showVoidModal     && <VoidOrderModal      order={order} onClose={() => setShowVoidModal(false)}     onDone={refresh}    />}
            {showRefundModal   && <RefundModal          order={order} onClose={() => setShowRefundModal(false)}   onDone={refresh}    />}
            {showPaymentModal  && <AddPaymentModal      order={order} onClose={() => setShowPaymentModal(false)}  onDone={refresh}    />}
            {showCustomerModal && <AttachCustomerModal  order={order} onClose={() => setShowCustomerModal(false)} onDone={refresh}    />}
            {showAuditLog      && <OrderAuditLog        orderId={order.id}                                        onClose={() => setShowAuditLog(false)} />}
            {showReceiptModal  && <ReceiptModal         order={order} onClose={() => setShowReceiptModal(false)}                      />}
            {showShippingModal && <SetShippingFeeModal  order={order} onClose={() => setShowShippingModal(false)} onDone={refresh}    />}
            {showDepositModal  && <SetDepositModal      order={order} onClose={() => setShowDepositModal(false)}  onDone={refresh}    />}

            {paymentLink && (
                <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 w-full max-w-lg px-4">
                    <div className="bg-surface-900 text-white rounded-2xl shadow-xl px-5 py-4 flex items-start gap-3">
                        <svg className="w-5 h-5 text-brand-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-semibold text-white mb-1">Payment link copied to clipboard</p>
                            <p className="text-2xs text-surface-300 font-mono truncate">{paymentLink}</p>
                        </div>
                        <button onClick={() => setPaymentLink(null)} className="text-surface-400 hover:text-white transition-colors ml-2 shrink-0" aria-label="Close">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>
            )}

            {verifyingPaymentId !== null && (
                <Modal open title="Verify M-Pesa Payment" onClose={() => { setVerifyingPaymentId(null); setMpesaCode(""); }}>
                    <div className="p-5 space-y-4">
                        <p className="text-sm text-surface-600">Enter the M-Pesa receipt code (e.g. <span className="font-mono">QJL3ABC7DE</span>) shown on the customer's phone.</p>
                        <div>
                            <label className="block text-xs font-semibold text-surface-700 mb-1">M-Pesa Receipt Code <span className="text-surface-400 font-normal">(leave blank to query by checkout request)</span></label>
                            <input className="input font-mono uppercase tracking-widest" placeholder="e.g. QJL3ABC7DE" value={mpesaCode} onChange={e => setMpesaCode(e.target.value.toUpperCase())} maxLength={12} autoFocus />
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <button onClick={() => { setVerifyingPaymentId(null); setMpesaCode(""); }} className="btn-secondary btn-sm">Cancel</button>
                            <button onClick={() => verifyMpesaMutation.mutate({ paymentId: verifyingPaymentId!, code: mpesaCode })} disabled={verifyMpesaMutation.isPending} className="btn-primary btn-sm">
                                {verifyMpesaMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                                Verify with Daraja
                            </button>
                        </div>
                    </div>
                </Modal>
            )}

            {verifyingPaystackPaymentId !== null && (
                <Modal open title="Verify Paystack Payment" onClose={() => { setVerifyingPaystackPaymentId(null); setPaystackVerifyRef(""); }}>
                    <div className="p-5 space-y-4">
                        <p className="text-sm text-surface-600">Enter the Paystack reference code from the Paystack dashboard or the customer's confirmation email.</p>
                        <div className="bg-blue-50 border border-blue-100 rounded-xl px-3 py-2.5 text-xs text-blue-700">
                            Find the reference in: <strong>Paystack Dashboard → Transactions</strong>. It looks like <span className="font-mono">POS-260601-XXXXX-0000000000</span>.
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-surface-700 mb-1">Paystack Reference <span className="text-danger">*</span></label>
                            <input className="input font-mono tracking-wide" placeholder="e.g. POS-260601-LQCPP-1780857668" value={paystackVerifyRef} onChange={e => setPaystackVerifyRef(e.target.value.trim())} autoFocus />
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <button onClick={() => { setVerifyingPaystackPaymentId(null); setPaystackVerifyRef(""); }} className="btn-secondary btn-sm">Cancel</button>
                            <button onClick={() => verifyPaystackMutation.mutate({ paymentId: verifyingPaystackPaymentId!, reference: paystackVerifyRef })} disabled={verifyPaystackMutation.isPending || !paystackVerifyRef.trim()} className="btn-primary btn-sm">
                                {verifyPaystackMutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                                Verify with Paystack
                            </button>
                        </div>
                    </div>
                </Modal>
            )}

            {showCurrencyModal && <ChangeCurrencyModal order={order} onClose={() => setShowCurrencyModal(false)} onDone={refresh} />}
        </div>
    );
}