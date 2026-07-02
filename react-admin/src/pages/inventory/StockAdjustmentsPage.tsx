import { useState, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { adjustmentsApi } from "@/api/adjustments";
import { stockApi } from "@/api/stock";
import type { Adjustment, ReasonCode } from "@/api/adjustments";
import type { StockItem } from "@/api/stock";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import {
    Field,
    useFieldAriaProps,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";
import { get } from "@/api/client";
import type { ApiError } from "@/types";
import { clsx } from "clsx";
import { Fragment } from "react";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ── Helpers ───────────────────────────────────────────────────────────────────

const STATUS_CONFIG = {
    pending_approval: {
        label: "Pending",
        bg: "bg-warning-light",
        text: "text-warning",
        icon: "⏳",
    },
    approved: {
        label: "Approved",
        bg: "bg-success-light",
        text: "text-success",
        icon: "✓",
    },
    rejected: {
        label: "Rejected",
        bg: "bg-danger-light",
        text: "text-danger",
        icon: "✕",
    },
} as const;

const DIRECTION_CONFIG = {
    increase: { color: "text-success", prefix: "+" },
    decrease: { color: "text-danger", prefix: "" },
    neutral: { color: "text-surface-600", prefix: "" },
};

function qtyColor(change: number) {
    if (change > 0) return "text-success font-bold";
    if (change < 0) return "text-danger font-bold";
    return "text-surface-500";
}

// ── New adjustment modal ──────────────────────────────────────────────────────

interface NewAdjustmentModalProps {
    open: boolean;
    onClose: () => void;
    preselected?: StockItem | null;
    reasonCodes: Record<string, ReasonCode>;
    onSaved: (requiresApproval: boolean) => void;
}

function NewAdjustmentModal({
    open,
    onClose,
    preselected,
    reasonCodes,
    onSaved,
}: NewAdjustmentModalProps) {
    const toast = useToastStore();
    const qc = useQueryClient();

    const [itemId, setItemId] = useState<number | "">(preselected?.id ?? "");
    const [reasonCode, setReasonCode] = useState("");
    const [quantityChange, setQuantityChange] = useState<number | "">(0);
    const [notes, setNotes] = useState("");
    const [referenceNumber, setReferenceNumber] = useState("");
    const [stockSearch, setStockSearch] = useState("");
    const [selectedItem, setSelectedItem] = useState<StockItem | null>(
        preselected ?? null,
    );

    // Stock search
    const { data: stockData, isFetching: searchingStock } = useQuery({
        queryKey: ["stock-search", stockSearch],
        queryFn: () => stockApi.list({ search: stockSearch, per_page: "20" }),
        enabled: stockSearch.length >= 2 && !selectedItem,
    });
    const stockResults = stockData?.data ?? [];

    const selectedReason = reasonCode ? reasonCodes[reasonCode] : null;
    const requiresApproval = selectedReason?.requires_approval ?? false;

    // Auto-set sign based on reason direction
    const handleReasonChange = (code: string) => {
        setReasonCode(code);
        const reason = reasonCodes[code];
        if (!reason) return;
        if (reason.direction === "decrease" && Number(quantityChange) > 0) {
            setQuantityChange(-Math.abs(Number(quantityChange)));
        } else if (
            reason.direction === "increase" &&
            Number(quantityChange) < 0
        ) {
            setQuantityChange(Math.abs(Number(quantityChange)));
        }
    };

    const reset = useCallback(() => {
        setItemId(preselected?.id ?? "");
        setSelectedItem(preselected ?? null);
        setReasonCode("");
        setQuantityChange(0);
        setNotes("");
        setReferenceNumber("");
        setStockSearch("");
    }, [preselected]);

    const mutation = useMutation({
        mutationFn: () =>
            adjustmentsApi.create({
                inventory_item_id: Number(itemId),
                quantity_change: Number(quantityChange),
                reason_code: reasonCode,
                notes: notes || undefined,
                reference_number: referenceNumber || undefined,
            }),
        onSuccess: (res) => {
            if ((res as any).auto_approved) {
                toast.success(
                    "Adjustment applied directly - admin role bypassed approval.",
                );
            } else {
                toast.success(res.message);
            }
            qc.invalidateQueries({ queryKey: ["adjustments"] });
            qc.invalidateQueries({ queryKey: ["stock-levels"] });
            qc.invalidateQueries({ queryKey: ["adjustments-pending"] });
            onSaved(res.requires_approval);
            onClose();
            reset();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const canSubmit =
        itemId &&
        reasonCode &&
        quantityChange !== "" &&
        Number(quantityChange) !== 0;

    // Preview new quantity
    const currentQty = selectedItem?.quantity_on_hand ?? 0;
    const newQty = currentQty + Number(quantityChange || 0);
    const wouldGoNeg = newQty < 0 && !requiresApproval;

    return (
        <Modal
            open={open}
            onClose={() => {
                onClose();
                reset();
            }}
            title="New Stock Adjustment"
            size="md"
            footer={
                <>
                    <button
                        onClick={() => {
                            onClose();
                            reset();
                        }}
                        className="btn-secondary btn-sm"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={
                            !canSubmit || mutation.isPending || !!wouldGoNeg
                        }
                        className={clsx(
                            "btn-sm",
                            requiresApproval ? "btn-secondary" : "btn-primary",
                        )}
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        {requiresApproval
                            ? "Submit for Approval"
                            : "Apply Adjustment"}
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                {/* Stock item selection */}
                <Field label="Stock Item" required>
                    {selectedItem ? (
                        <div className="flex items-center gap-3 p-3 border border-brand-200 bg-brand-50 rounded-xl">
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-semibold text-surface-900">
                                    {selectedItem.product?.name ??
                                        selectedItem.product?.sku}
                                </p>
                                <div className="flex items-center gap-2 mt-0.5 text-xs text-surface-500">
                                    <span className="font-mono">
                                        {selectedItem.product?.sku}
                                    </span>
                                    {selectedItem.variant && (
                                        <span className="bg-surface-200 px-1.5 rounded">
                                            {selectedItem.variant.variant_name}
                                        </span>
                                    )}
                                    <span>@ {selectedItem.outlet?.name}</span>
                                    <span className="font-semibold text-surface-700">
                                        · {selectedItem.quantity_on_hand} on
                                        hand
                                    </span>
                                </div>
                            </div>
                            {!preselected && (
                                <button
                                    onClick={() => {
                                        setSelectedItem(null);
                                        setItemId("");
                                    }}
                                    className="text-xs text-danger hover:underline shrink-0"
                                >
                                    Change
                                </button>
                            )}
                        </div>
                    ) : (
                        <div className="relative">
                            <FieldInput
                                className="input"
                                placeholder="Search product or SKU…"
                                value={stockSearch}
                                onChange={(e) => setStockSearch(e.target.value)}
                                autoFocus
                            />
                            {stockSearch.length >= 2 && (
                                <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-surface-200 rounded-xl shadow-lg overflow-hidden max-h-52 overflow-y-auto">
                                    {searchingStock ? (
                                        <div className="flex justify-center py-4">
                                            <Spinner size="sm" />
                                        </div>
                                    ) : stockResults.length === 0 ? (
                                        <p className="text-sm text-surface-400 text-center py-4">
                                            No stock records found.
                                        </p>
                                    ) : (
                                        stockResults.map((item) => (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onMouseDown={() => {
                                                    setSelectedItem(item);
                                                    setItemId(item.id);
                                                    setStockSearch("");
                                                }}
                                                className="w-full flex items-center gap-3 px-3 py-2.5 hover:bg-surface-50 text-left border-b border-surface-50 last:border-0"
                                            >
                                                {item.product?.image_url ? (
                                                    <img
                                                        src={
                                                            item.product
                                                                .image_url
                                                        }
                                                        className="w-8 h-8 rounded object-cover shrink-0"
                                                        alt=""
                                                    />
                                                ) : (
                                                    <div className="w-8 h-8 rounded bg-surface-100 shrink-0" />
                                                )}
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-sm font-medium text-surface-900 truncate">
                                                        {item.product?.name}
                                                    </p>
                                                    <p className="text-xs text-surface-400">
                                                        {item.product?.sku}
                                                        {item.variant
                                                            ? ` · ${item.variant.variant_name}`
                                                            : ""}{" "}
                                                        · {item.outlet?.name}
                                                    </p>
                                                </div>
                                                <span
                                                    className={clsx(
                                                        "text-sm font-bold tabular-nums shrink-0",
                                                        item.quantity_on_hand ===
                                                            0
                                                            ? "text-danger"
                                                            : item.status ===
                                                                "low_stock"
                                                              ? "text-warning"
                                                              : "text-success",
                                                    )}
                                                >
                                                    {item.quantity_on_hand}
                                                </span>
                                            </button>
                                        ))
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </Field>

                {/* Reason code */}
                <Field label="Reason" required>
                    <FieldSelect
                        className="input"
                        value={reasonCode}
                        onChange={(e) => handleReasonChange(e.target.value)}
                    >
                        <option value="">- Select reason -</option>
                        <optgroup label="Decrease stock">
                            {Object.entries(reasonCodes)
                                .filter(([, r]) => r.direction === "decrease")
                                .map(([k, r]) => (
                                    <option key={k} value={k}>
                                        {r.label}
                                        {r.requires_approval ? " *" : ""}
                                    </option>
                                ))}
                        </optgroup>
                        <optgroup label="Increase stock">
                            {Object.entries(reasonCodes)
                                .filter(([, r]) => r.direction === "increase")
                                .map(([k, r]) => (
                                    <option key={k} value={k}>
                                        {r.label}
                                        {r.requires_approval ? " *" : ""}
                                    </option>
                                ))}
                        </optgroup>
                        <optgroup label="Correction / Count">
                            {Object.entries(reasonCodes)
                                .filter(([, r]) => r.direction === "either")
                                .map(([k, r]) => (
                                    <option key={k} value={k}>
                                        {r.label}
                                        {r.requires_approval ? " *" : ""}
                                    </option>
                                ))}
                        </optgroup>
                    </FieldSelect>
                    {requiresApproval && (
                        <p className="text-xs text-warning mt-1 flex items-center gap-1">
                            <span>⚠</span> This reason requires approval before
                            stock is updated.
                        </p>
                    )}
                </Field>

                {/* Quantity */}
                <Field
                    label="Quantity Change"
                    hint="Negative to decrease, positive to increase"
                    required
                >
                    <FieldInput
                        type="number"
                        className={clsx("input", wouldGoNeg && "input-error")}
                        value={quantityChange}
                        onChange={(e) =>
                            setQuantityChange(
                                e.target.value === ""
                                    ? ""
                                    : Number(e.target.value),
                            )
                        }
                        placeholder={
                            selectedReason?.direction === "decrease"
                                ? "-5"
                                : selectedReason?.direction === "increase"
                                  ? "+5"
                                  : "±0"
                        }
                    />
                </Field>

                {/* Stock preview */}
                {selectedItem &&
                    quantityChange !== "" &&
                    Number(quantityChange) !== 0 && (
                        <div
                            className={clsx(
                                "rounded-xl px-4 py-3 text-sm",
                                wouldGoNeg
                                    ? "bg-danger-light border border-danger/20"
                                    : "bg-surface-50 border border-surface-100",
                            )}
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-surface-500">
                                    Current stock:
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {currentQty}
                                </span>
                            </div>
                            <div className="flex items-center justify-between mt-1">
                                <span className="text-surface-500">
                                    Change:
                                </span>
                                <span
                                    className={clsx(
                                        "font-bold tabular-nums",
                                        qtyColor(Number(quantityChange)),
                                    )}
                                >
                                    {Number(quantityChange) > 0 ? "+" : ""}
                                    {quantityChange}
                                </span>
                            </div>
                            <div className="flex items-center justify-between mt-1 pt-1 border-t border-surface-200">
                                <span className="font-semibold text-surface-700">
                                    New stock:
                                </span>
                                <span
                                    className={clsx(
                                        "text-base font-bold tabular-nums",
                                        wouldGoNeg
                                            ? "text-danger"
                                            : "text-success",
                                    )}
                                >
                                    {newQty}
                                </span>
                            </div>
                            {wouldGoNeg && (
                                <p className="text-xs text-danger mt-1.5 font-medium">
                                    ⚠ Would result in negative stock - not
                                    allowed.
                                </p>
                            )}
                        </div>
                    )}

                {/* Reference + notes */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field
                        label="Reference #"
                        hint="PO number, incident report, etc."
                    >
                        <FieldInput
                            className="input"
                            value={referenceNumber}
                            onChange={(e) => setReferenceNumber(e.target.value)}
                            placeholder="Optional"
                        />
                    </Field>
                </div>
                <Field label="Notes">
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Additional details…"
                    />
                </Field>

                <p className="text-2xs text-surface-400">
                    * Reasons marked with an asterisk require manager approval
                    before stock is updated.
                </p>
            </div>
        </Modal>
    );
}

// ── Approval modal ────────────────────────────────────────────────────────────

function ApprovalModal({
    adjustment,
    onClose,
}: {
    adjustment: Adjustment;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [notes, setNotes] = useState("");
    const [rejectMode, setReject] = useState(false);

    const approveMutation = useMutation({
        mutationFn: () =>
            adjustmentsApi.approve(adjustment.id, notes || undefined),
        onSuccess: () => {
            toast.success("Adjustment approved and applied.");
            qc.invalidateQueries({ queryKey: ["adjustments"] });
            qc.invalidateQueries({ queryKey: ["stock-levels"] });
            qc.invalidateQueries({ queryKey: ["adjustments-pending"] });
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const rejectMutation = useMutation({
        mutationFn: () => adjustmentsApi.reject(adjustment.id, notes),
        onSuccess: () => {
            toast.success("Adjustment rejected.");
            qc.invalidateQueries({ queryKey: ["adjustments"] });
            qc.invalidateQueries({ queryKey: ["adjustments-pending"] });
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const item = adjustment.inventory_item;
    const newQty = (item?.quantity_on_hand ?? 0) + adjustment.quantity_change;

    return (
        <Modal
            open
            onClose={onClose}
            title="Review Adjustment"
            size="sm"
            footer={
                rejectMode ? (
                    <>
                        <button
                            onClick={() => setReject(false)}
                            className="btn-secondary btn-sm"
                        >
                            Back
                        </button>
                        <button
                            onClick={() => rejectMutation.mutate()}
                            disabled={!notes.trim() || rejectMutation.isPending}
                            className="btn-sm bg-danger text-white hover:bg-danger/90"
                        >
                            {rejectMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Confirm Reject
                        </button>
                    </>
                ) : (
                    <>
                        <button
                            onClick={() => setReject(true)}
                            className="btn-secondary btn-sm text-danger"
                        >
                            Reject
                        </button>
                        <button
                            onClick={() => approveMutation.mutate()}
                            disabled={approveMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {approveMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Approve & Apply
                        </button>
                    </>
                )
            }
        >
            <div className="space-y-4">
                {/* Adjustment summary */}
                <div className="border border-surface-100 rounded-xl overflow-hidden">
                    <div className="px-4 py-3 bg-surface-50 border-b border-surface-100">
                        <p className="text-sm font-semibold text-surface-800">
                            {adjustment.reason_label}
                        </p>
                        {adjustment.reference_number && (
                            <p className="text-xs text-surface-400 mt-0.5">
                                Ref: {adjustment.reference_number}
                            </p>
                        )}
                    </div>
                    <div className="px-4 py-3 space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-surface-500">Product</span>
                            <span className="font-medium text-right max-w-48 truncate">
                                {item?.product?.name}
                                {item?.variant && (
                                    <span className="text-surface-400">
                                        {" "}
                                        · {item.variant.variant_name}
                                    </span>
                                )}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-surface-500">Outlet</span>
                            <span className="font-medium">
                                {item?.outlet?.name}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-surface-500">
                                Current stock
                            </span>
                            <span className="font-semibold tabular-nums">
                                {item?.quantity_on_hand}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-surface-500">Change</span>
                            <span
                                className={clsx(
                                    "font-bold tabular-nums",
                                    qtyColor(adjustment.quantity_change),
                                )}
                            >
                                {adjustment.quantity_change > 0 ? "+" : ""}
                                {adjustment.quantity_change}
                            </span>
                        </div>
                        <div className="flex justify-between pt-1 border-t border-surface-100">
                            <span className="font-semibold text-surface-700">
                                New stock
                            </span>
                            <span
                                className={clsx(
                                    "text-base font-bold tabular-nums",
                                    newQty < 0 ? "text-danger" : "text-success",
                                )}
                            >
                                {newQty}
                            </span>
                        </div>
                    </div>
                </div>

                {adjustment.notes && (
                    <div className="text-sm">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-1">
                            Notes from submitter
                        </p>
                        <p className="text-surface-600 italic">
                            "{adjustment.notes}"
                        </p>
                    </div>
                )}

                <div className="text-xs text-surface-400">
                    Submitted by{" "}
                    <span className="font-medium text-surface-600">
                        {adjustment.created_by?.name}
                    </span>
                    {" · "}
                    {new Date(adjustment.created_at).toLocaleString("en-GB", {
                        day: "numeric",
                        month: "short",
                        hour: "2-digit",
                        minute: "2-digit",
                    })}
                </div>

                <Field
                    label={
                        rejectMode
                            ? "Rejection reason (required)"
                            : "Approval notes (optional)"
                    }
                >
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder={
                            rejectMode
                                ? "Why is this adjustment being rejected?"
                                : "Optional notes…"
                        }
                        autoFocus={rejectMode}
                    />
                </Field>

                {newQty < 0 && !rejectMode && (
                    <p className="text-xs text-danger bg-danger-light rounded-lg px-3 py-2">
                        ⚠ Approving this will result in negative stock ({newQty}
                        ). Consider rejecting instead.
                    </p>
                )}
            </div>
        </Modal>
    );
}

// ── Pending approvals panel ───────────────────────────────────────────────────

function PendingPanel({ onReview }: { onReview: (adj: Adjustment) => void }) {
    const { can } = usePermissions();
    const canApprove = can("inventory.approve");
    const { data } = useQuery({
        queryKey: ["adjustments-pending"],
        queryFn: () => adjustmentsApi.pending(),
        refetchInterval: 30_000,
    });

    const items = data?.data ?? [];
    if (items.length === 0 || !canApprove) return null;

    return (
        <div className="card border-warning border overflow-hidden">
            <div className="px-4 py-3 bg-warning-light border-b border-warning/20 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <span className="text-warning">⚠</span>
                    <p className="text-sm font-semibold text-warning">
                        {items.length} Adjustment{items.length !== 1 ? "s" : ""}{" "}
                        Pending Approval
                    </p>
                </div>
            </div>
            <div className="divide-y divide-surface-50">
                {items.map((adj) => (
                    <div
                        key={adj.id}
                        className="flex items-center gap-3 px-4 py-3 hover:bg-surface-50 transition-colors"
                    >
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <p className="text-sm font-medium text-surface-900">
                                    {adj.inventory_item?.product?.name}
                                </p>
                                {adj.inventory_item?.variant && (
                                    <span className="text-xs bg-surface-100 text-surface-500 px-1.5 py-0.5 rounded">
                                        {
                                            adj.inventory_item.variant
                                                .variant_name
                                        }
                                    </span>
                                )}
                                <span
                                    className={clsx(
                                        "text-sm font-bold tabular-nums",
                                        qtyColor(adj.quantity_change),
                                    )}
                                >
                                    {adj.quantity_change > 0 ? "+" : ""}
                                    {adj.quantity_change}
                                </span>
                                <span className="text-xs text-surface-400 bg-surface-100 px-1.5 py-0.5 rounded">
                                    {adj.reason_label}
                                </span>
                            </div>
                            <p className="text-xs text-surface-400 mt-0.5">
                                {adj.inventory_item?.outlet?.name} · by{" "}
                                {adj.created_by?.name} ·{" "}
                                {new Date(adj.created_at).toLocaleString(
                                    "en-GB",
                                    {
                                        day: "numeric",
                                        month: "short",
                                        hour: "2-digit",
                                        minute: "2-digit",
                                    },
                                )}
                            </p>
                        </div>
                        <button
                            onClick={() => onReview(adj)}
                            className="btn-primary btn-sm shrink-0"
                        >
                            Review
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}

// ── Reverse modal ─────────────────────────────────────────────────────────────

function ReverseModal({
    adjustment,
    onClose,
}: {
    adjustment: Adjustment;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [notes, setNotes] = useState("");

    const item = adjustment.inventory_item;
    const newQty = (item?.quantity_on_hand ?? 0) + -adjustment.quantity_change;
    const wouldGoNeg = newQty < 0;

    const mutation = useMutation({
        mutationFn: () =>
            adjustmentsApi.reverse(adjustment.id, notes || undefined),
        onSuccess: (res) => {
            toast.success(res.message);
            qc.invalidateQueries({ queryKey: ["adjustments"] });
            qc.invalidateQueries({ queryKey: ["stock-levels"] });
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open
            onClose={onClose}
            title="Reverse Adjustment"
            size="sm"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || wouldGoNeg}
                        className="btn-sm bg-warning text-white hover:bg-warning/90"
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        Confirm Reversal
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="bg-warning-light border border-warning/20 rounded-xl px-4 py-3 text-sm text-warning font-medium">
                    ⚠ This will create a compensating transaction to undo
                    adjustment #{adjustment.id}. The original record is
                    preserved for audit purposes.
                </div>

                <div className="border border-surface-100 rounded-xl overflow-hidden text-sm">
                    <div className="px-4 py-2.5 bg-surface-50 border-b border-surface-100 font-medium text-surface-700">
                        Original: {adjustment.reason_label}
                    </div>
                    <div className="px-4 py-3 space-y-1.5">
                        <div className="flex justify-between">
                            <span className="text-surface-500">Product</span>
                            <span className="font-medium">
                                {item?.product?.name}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-surface-500">
                                Original change
                            </span>
                            <span
                                className={clsx(
                                    "font-bold",
                                    qtyColor(adjustment.quantity_change),
                                )}
                            >
                                {adjustment.quantity_change > 0 ? "+" : ""}
                                {adjustment.quantity_change}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-surface-500">
                                Reversal change
                            </span>
                            <span
                                className={clsx(
                                    "font-bold",
                                    qtyColor(-adjustment.quantity_change),
                                )}
                            >
                                {-adjustment.quantity_change > 0 ? "+" : ""}
                                {-adjustment.quantity_change}
                            </span>
                        </div>
                        <div className="flex justify-between pt-1.5 border-t border-surface-100">
                            <span className="font-semibold text-surface-700">
                                Stock after reversal
                            </span>
                            <span
                                className={clsx(
                                    "font-bold",
                                    wouldGoNeg ? "text-danger" : "text-success",
                                )}
                            >
                                {newQty}
                            </span>
                        </div>
                    </div>
                </div>

                {wouldGoNeg && (
                    <p className="text-xs text-danger bg-danger-light rounded-lg px-3 py-2">
                        Cannot reverse - current stock ({item?.quantity_on_hand}
                        ) is less than the reversal amount.
                    </p>
                )}

                <Field label="Reason for reversal (optional)">
                    <FieldInput
                        className="input"
                        placeholder="e.g. Data entry error, duplicate adjustment…"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        autoFocus
                    />
                </Field>
            </div>
        </Modal>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function StockAdjustmentsPage() {
    const { can } = usePermissions();
    const canAdjust = can("inventory.adjust");
    const canApprove = can("inventory.approve");
    const table = useTableState({
        defaultSortBy: "created_at",
        defaultPerPage: 25,
    });

    const [statusFilter, setStatusFilter] = useState("");
    const [reasonFilter, setReasonFilter] = useState("");
    const [outletFilter, setOutletFilter] = useState("");
    const [fromDate, setFromDate] = useState("");
    const [toDate, setToDate] = useState("");
    const [newModal, setNewModal] = useState(false);
    const [reviewAdj, setReviewAdj] = useState<Adjustment | null>(null);
    const [reversingAdj, setReversingAdj] = useState<Adjustment | null>(null);
    const navigate = useNavigate();

    const params: Record<string, string> = {
        ...table.toParams(),
        ...(statusFilter && { status: statusFilter }),
        ...(reasonFilter && { reason_code: reasonFilter }),
        ...(outletFilter && { outlet_id: outletFilter }),
        ...(fromDate && { from: fromDate }),
        ...(toDate && { to: toDate }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["adjustments", params],
        queryFn: () => adjustmentsApi.list(params),
    });

    const { data: outletsData } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => get<any>("/v1/admin/outlets"),
    });

    const adjustments = data?.data ?? [];
    const meta = data?.meta;
    const stats = data?.stats;
    const reasonCodes = data?.reason_codes ?? {};
    const outlets = Array.isArray(outletsData)
        ? outletsData
        : (outletsData?.data ?? []);

    // Group the current page of rows by created_at. Pagination, sort, and
    // filters are untouched - this only re-partitions the rows already fetched.
    const adjustmentGroups = groupRowsByDate(
        adjustments,
        (adj) => adj.created_at,
    );

    return (
        <div className="space-y-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Stock Adjustments</h1>
                    <p className="page-subtitle">
                        {stats
                            ? `${stats.total} total · ${stats.pending_approval} pending · ${stats.total_shrinkage} units shrinkage`
                            : "Loading…"}
                    </p>
                </div>
                {canAdjust && (
                <button
                    onClick={() => setNewModal(true)}
                    className="btn-primary self-start"
                >
                    + New Adjustment
                </button>
                )}
            </div>

            {/* Stats */}
            {stats && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        {
                            label: "Total",
                            value: stats.total,
                            color: "",
                            filter: "",
                        },
                        {
                            label: "Pending",
                            value: stats.pending_approval,
                            color: "text-warning",
                            filter: "pending_approval",
                        },
                        {
                            label: "Approved",
                            value: stats.approved,
                            color: "text-success",
                            filter: "approved",
                        },
                        {
                            label: "Shrinkage",
                            value: stats.total_shrinkage,
                            color: "text-danger",
                            filter: "",
                        },
                    ].map((s) => (
                        <button
                            key={s.label}
                            onClick={() =>
                                s.filter &&
                                setStatusFilter(
                                    statusFilter === s.filter ? "" : s.filter,
                                )
                            }
                            className={clsx(
                                "card p-4 text-center transition-all",
                                s.filter && statusFilter === s.filter
                                    ? "ring-2 ring-brand-300"
                                    : "",
                                s.filter
                                    ? "hover:shadow-sm cursor-pointer"
                                    : "cursor-default",
                            )}
                        >
                            <p
                                className={clsx(
                                    "text-2xl font-bold",
                                    s.color || "text-surface-900",
                                )}
                            >
                                {s.value}
                            </p>
                            <p className="text-xs text-surface-500 mt-0.5">
                                {s.label}
                            </p>
                        </button>
                    ))}
                </div>
            )}

            {/* Pending approvals panel */}
            <PendingPanel onReview={(adj) => setReviewAdj(adj)} />

            {/* Filters */}
            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <input
                    className="input w-full sm:max-w-xs"
                    placeholder="Search product or SKU…"
                    value={table.state.search}
                    onChange={(e) => table.setSearch(e.target.value)}
                />
                <div className="flex flex-wrap gap-2">
                    <select
                        className="input flex-1 sm:w-44 sm:flex-none"
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                    >
                        <option value="">All statuses</option>
                        <option value="pending_approval">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                    <select
                        className="input flex-1 sm:w-44 sm:flex-none"
                        value={reasonFilter}
                        onChange={(e) => setReasonFilter(e.target.value)}
                    >
                        <option value="">All reasons</option>
                        {Object.entries(reasonCodes).map(([k, r]) => (
                            <option key={k} value={k}>
                                {(r as ReasonCode).label}
                            </option>
                        ))}
                    </select>
                    <select
                        className="input flex-1 sm:w-44 sm:flex-none"
                        value={outletFilter}
                        onChange={(e) => setOutletFilter(e.target.value)}
                    >
                        <option value="">All outlets</option>
                        {outlets.map((o: any) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                    <input
                        className="input flex-1 sm:w-36 sm:flex-none"
                        type="date"
                        value={fromDate}
                        onChange={(e) => setFromDate(e.target.value)}
                    />
                    <input
                        className="input flex-1 sm:w-36 sm:flex-none"
                        type="date"
                        value={toDate}
                        onChange={(e) => setToDate(e.target.value)}
                    />
                    {(table.state.search ||
                        statusFilter ||
                        reasonFilter ||
                        outletFilter ||
                        fromDate ||
                        toDate) && (
                        <button
                            onClick={() => {
                                table.setSearch("");
                                setStatusFilter("");
                                setReasonFilter("");
                                setOutletFilter("");
                                setFromDate("");
                                setToDate("");
                            }}
                            className="btn-ghost btn-sm text-xs"
                        >
                            ✕ Clear
                        </button>
                    )}
                </div>
            </div>

            {/* Table */}
            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                ) : adjustments.length === 0 ? (
                    <div className="text-center py-16">
                        <p className="text-surface-400 text-sm mb-3">
                            {table.state.search || statusFilter
                                ? "No adjustments match your filters."
                                : "No adjustments yet."}
                        </p>
                        {!table.state.search && !statusFilter && canAdjust && (
                            <button
                                onClick={() => setNewModal(true)}
                                className="btn-primary btn-sm"
                            >
                                Create first adjustment
                            </button>
                        )}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px]">
                            <thead>
                                <tr className="border-b border-surface-100 bg-surface-50/50">
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Product
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                        Reason
                                    </th>
                                    <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Change
                                    </th>
                                    <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                                        Before → After
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">
                                        By
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                        Date
                                    </th>
                                    <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 w-20" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
                                {adjustmentGroups.map((group) => (
                                    <Fragment key={group.key}>
                                        <DateGroupHeaderRow
                                            label={group.label}
                                            colSpan={8}
                                        />
                                        {group.items.map((adj) => {
                                            const status =
                                                STATUS_CONFIG[
                                                    adj.status as keyof typeof STATUS_CONFIG
                                                ] ?? STATUS_CONFIG.approved;
                                            return (
                                                <tr
                                                    key={adj.id}
                                                    className="hover:bg-surface-50/50 transition-colors"
                                                >
                                                    {/* Product */}
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-2.5">
                                                            <div className="w-8 h-8 rounded-lg bg-surface-100 overflow-hidden shrink-0 border border-surface-200">
                                                                {adj
                                                                    .inventory_item
                                                                    ?.product
                                                                    ?.image_url ? (
                                                                    <img
                                                                        src={
                                                                            adj
                                                                                .inventory_item
                                                                                .product
                                                                                .image_url
                                                                        }
                                                                        className="w-full h-full object-cover"
                                                                        alt=""
                                                                    />
                                                                ) : (
                                                                    <div className="w-full h-full flex items-center justify-center">
                                                                        <svg
                                                                            className="w-4 h-4 text-surface-300"
                                                                            fill="none"
                                                                            viewBox="0 0 24 24"
                                                                            stroke="currentColor"
                                                                            strokeWidth={
                                                                                1.5
                                                                            }
                                                                        >
                                                                            <path
                                                                                strokeLinecap="round"
                                                                                strokeLinejoin="round"
                                                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                                                                            />
                                                                        </svg>
                                                                    </div>
                                                                )}
                                                            </div>
                                                            <div>
                                                                <p className="text-sm font-medium text-surface-900">
                                                                    {adj
                                                                        .inventory_item
                                                                        ?.product
                                                                        ?.name ??
                                                                        "-"}
                                                                </p>
                                                                <div className="flex items-center gap-1.5 mt-0.5 text-xs text-surface-400">
                                                                    <span className="font-mono">
                                                                        {
                                                                            adj
                                                                                .inventory_item
                                                                                ?.product
                                                                                ?.sku
                                                                        }
                                                                    </span>
                                                                    {adj
                                                                        .inventory_item
                                                                        ?.variant && (
                                                                        <span className="bg-surface-100 px-1 rounded">
                                                                            {
                                                                                adj
                                                                                    .inventory_item
                                                                                    .variant
                                                                                    .variant_name
                                                                            }
                                                                        </span>
                                                                    )}
                                                                    <span>
                                                                        ·{" "}
                                                                        {
                                                                            adj
                                                                                .inventory_item
                                                                                ?.outlet
                                                                                ?.name
                                                                        }
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>

                                                    {/* Reason */}
                                                    <td className="px-4 py-3 hidden sm:table-cell">
                                                        <p className="text-sm text-surface-800">
                                                            {adj.reason_label}
                                                        </p>
                                                        {adj.reference_number && (
                                                            <p className="text-xs text-surface-400 font-mono">
                                                                #
                                                                {
                                                                    adj.reference_number
                                                                }
                                                            </p>
                                                        )}
                                                        {adj.notes && (
                                                            <p className="text-xs text-surface-400 italic truncate max-w-36">
                                                                {adj.notes}
                                                            </p>
                                                        )}
                                                    </td>

                                                    {/* Change */}
                                                    <td className="px-4 py-3 text-center">
                                                        <span
                                                            className={clsx(
                                                                "text-base tabular-nums",
                                                                qtyColor(
                                                                    adj.quantity_change,
                                                                ),
                                                            )}
                                                        >
                                                            {adj.quantity_change >
                                                            0
                                                                ? "+"
                                                                : ""}
                                                            {
                                                                adj.quantity_change
                                                            }
                                                        </span>
                                                    </td>

                                                    {/* Before → After */}
                                                    <td className="px-4 py-3 text-center hidden md:table-cell">
                                                        <span className="text-xs tabular-nums text-surface-500">
                                                            {
                                                                adj.quantity_before
                                                            }{" "}
                                                            →{" "}
                                                            {adj.status ===
                                                            "pending_approval" ? (
                                                                <span className="text-warning">
                                                                    pending
                                                                </span>
                                                            ) : (
                                                                adj.quantity_after
                                                            )}
                                                        </span>
                                                    </td>

                                                    {/* By */}
                                                    <td className="px-4 py-3 hidden lg:table-cell">
                                                        <p className="text-sm text-surface-600">
                                                            {adj.created_by
                                                                ?.name ?? "-"}
                                                        </p>
                                                    </td>

                                                    {/* Date */}
                                                    <td className="px-4 py-3 hidden sm:table-cell">
                                                        <p className="text-sm text-surface-600">
                                                            {new Date(
                                                                adj.created_at,
                                                            ).toLocaleDateString(
                                                                "en-GB",
                                                                {
                                                                    day: "numeric",
                                                                    month: "short",
                                                                    year: "numeric",
                                                                },
                                                            )}
                                                        </p>
                                                        <p className="text-xs text-surface-400">
                                                            {new Date(
                                                                adj.created_at,
                                                            ).toLocaleTimeString(
                                                                "en-GB",
                                                                {
                                                                    hour: "2-digit",
                                                                    minute: "2-digit",
                                                                },
                                                            )}
                                                        </p>
                                                    </td>

                                                    {/* Status */}
                                                    <td className="px-4 py-3 text-center">
                                                        <span
                                                            className={clsx(
                                                                "text-xs font-medium px-2.5 py-1 rounded-full",
                                                                status.bg,
                                                                status.text,
                                                            )}
                                                        >
                                                            {status.icon}{" "}
                                                            {status.label}
                                                        </span>
                                                        {adj.approved_by &&
                                                            adj.status !==
                                                                "pending_approval" && (
                                                                <p className="text-2xs text-surface-400 mt-0.5">
                                                                    {
                                                                        adj
                                                                            .approved_by
                                                                            .name
                                                                    }
                                                                </p>
                                                            )}
                                                    </td>

                                                    {/* Actions */}
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex items-center gap-1 justify-end">
                                                            <button
                                                                onClick={(
                                                                    e,
                                                                ) => {
                                                                    e.stopPropagation();
                                                                    navigate(
                                                                        `/inventory/adjustments/${adj.id}`,
                                                                    );
                                                                }}
                                                                className="btn-ghost btn-sm text-xs text-brand-600 hover:bg-brand-50"
                                                                title="View detail"
                                                            >
                                                                View
                                                            </button>
                                                            {adj.status ===
                                                                "pending_approval" &&
                                                                canApprove && (
                                                                <button
                                                                    onClick={() =>
                                                                        setReviewAdj(
                                                                            adj,
                                                                        )
                                                                    }
                                                                    className="btn-primary btn-sm text-xs"
                                                                >
                                                                    Review
                                                                </button>
                                                            )}
                                                            {adj.status ===
                                                                "approved" &&
                                                                !adj.notes?.startsWith(
                                                                    "[REVERSAL",
                                                                ) &&
                                                                canApprove && (
                                                                    <button
                                                                        onClick={() =>
                                                                            setReversingAdj(
                                                                                adj,
                                                                            )
                                                                        }
                                                                        className="btn-ghost btn-sm text-xs text-warning hover:bg-warning-light"
                                                                        title="Reverse this adjustment"
                                                                    >
                                                                        ↩
                                                                        Reverse
                                                                    </button>
                                                                )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-surface-500">
                            Showing {meta.from}–{meta.to} of {meta.total}
                        </p>
                        <div className="flex gap-1">
                            <button
                                disabled={meta.current_page === 1}
                                onClick={() =>
                                    table.setPage(meta.current_page - 1)
                                }
                                className="btn-ghost btn-sm text-xs disabled:opacity-40"
                            >
                                ← Prev
                            </button>
                            <button
                                disabled={meta.current_page === meta.last_page}
                                onClick={() =>
                                    table.setPage(meta.current_page + 1)
                                }
                                className="btn-ghost btn-sm text-xs disabled:opacity-40"
                            >
                                Next →
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* Modals */}
            {newModal && (
                <NewAdjustmentModal
                    open={newModal}
                    onClose={() => setNewModal(false)}
                    reasonCodes={reasonCodes}
                    onSaved={(requiresApproval) => {
                        if (requiresApproval)
                            setStatusFilter("pending_approval");
                    }}
                />
            )}

            {reviewAdj && (
                <ApprovalModal
                    adjustment={reviewAdj}
                    onClose={() => setReviewAdj(null)}
                />
            )}

            {reversingAdj && (
                <ReverseModal
                    adjustment={reversingAdj}
                    onClose={() => setReversingAdj(null)}
                />
            )}
        </div>
    );
}