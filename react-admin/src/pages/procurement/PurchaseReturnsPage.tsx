import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { purchaseOrderApi, supplierApi } from "@/api/procurement";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Field, useFieldAriaProps, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";
import { clsx } from "clsx";
import { get } from "@/api/client";
import { Fragment } from "react";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ─── Types ────────────────────────────────────────────────────────────────────

interface PurchaseReturnRecord {
    id: number;
    return_number: string;
    purchase_order_id: number;
    purchase_order?: {
        id: number;
        po_number: string;
        supplier?: { id: number; name: string };
    };
    supplier?: { id: number; name: string };
    po_number?: string;
    supplier_name?: string;
    status?: string;
    reason?: string;
    notes?: string;
    created_at: string;
    return_date?: string;
    items_count?: number; // from list response (withCount)
    items?: Array<{
        id: number;
        po_item_id: number;
        quantity: number;
        reason: string;
        purchase_order_item?: {
            description?: string;
            unit_price?: number;
            product?: { name?: string; sku?: string };
            material?: { name?: string };
            item_type?: string;
        };
    }>;
    created_by_user?: { id: number; first_name: string; last_name: string };
}

// ─── Return detail modal ──────────────────────────────────────────────────────

function ReturnDetailModal({
    returnId,
    onClose,
}: {
    returnId: number;
    onClose: () => void;
}) {
    const { data, isLoading } = useQuery({
        queryKey: ["purchase-return-detail", returnId],
        queryFn: () =>
            get<{ return: PurchaseReturnRecord }>(
                `/v1/admin/purchase-returns/${returnId}`,
            ),
    });

    const detail = data?.return;

    const poNumber =
        detail?.purchase_order?.po_number ?? detail?.po_number ?? "-";
    const supplierName =
        detail?.supplier?.name ??
        detail?.purchase_order?.supplier?.name ??
        detail?.supplier_name ??
        "-";

    return (
        <Modal
            open
            onClose={onClose}
            title={`Purchase Return - ${detail?.return_number ?? "…"}`}
            size="xl"
            footer={
                <div className="flex justify-end w-full">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Close
                    </button>
                </div>
            }
        >
            {isLoading ? (
                <div className="flex justify-center py-12">
                    <Spinner size="lg" />
                </div>
            ) : detail ? (
                <div className="space-y-5">
                    {/* Summary cards */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        {[
                            { label: "Return #", value: detail.return_number },
                            { label: "Purchase Order", value: poNumber },
                            { label: "Supplier", value: supplierName },
                            {
                                label: "Date",
                                value: new Date(
                                    detail.return_date ?? detail.created_at,
                                ).toLocaleDateString(),
                            },
                        ].map((s) => (
                            <div
                                key={s.label}
                                className="bg-surface-50 rounded-lg px-3 py-2"
                            >
                                <p className="text-2xs text-surface-400 uppercase tracking-wider mb-1">
                                    {s.label}
                                </p>
                                <p className="font-semibold text-surface-900 text-sm truncate">
                                    {s.value}
                                </p>
                            </div>
                        ))}
                    </div>

                    {/* Status + reason */}
                    <div className="flex flex-wrap items-center gap-3 px-4 py-3 bg-surface-50 rounded-lg">
                        {detail.status && (
                            <span
                                className={clsx(
                                    "badge capitalize",
                                    detail.status === "pending"
                                        ? "badge-warning"
                                        : detail.status === "approved"
                                          ? "badge-success"
                                          : detail.status === "rejected"
                                            ? "badge-danger"
                                            : "badge-neutral",
                                )}
                            >
                                {detail.status}
                            </span>
                        )}
                        {detail.reason && (
                            <span className="text-sm text-surface-600">
                                {detail.reason}
                            </span>
                        )}
                    </div>

                    {/* Items table */}
                    <div>
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            Returned Items ({detail.items?.length ?? 0})
                        </p>
                        {detail.items && detail.items.length > 0 ? (
                            <div className="border border-surface-100 rounded-lg overflow-hidden">
                                <table className="table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Type</th>
                                            <th className="text-center">
                                                Qty Returned
                                            </th>
                                            <th className="text-right">
                                                Unit Price
                                            </th>
                                            <th>Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {detail.items.map((item) => {
                                            const poi =
                                                item.purchase_order_item;
                                            const name =
                                                poi?.product?.name ??
                                                poi?.material?.name ??
                                                poi?.description ??
                                                `Item #${item.po_item_id}`;
                                            const itemType =
                                                poi?.item_type ?? "-";

                                            return (
                                                <tr key={item.id}>
                                                    <td>
                                                        <p className="font-medium text-surface-900">
                                                            {name}
                                                        </p>
                                                        {poi?.product?.sku && (
                                                            <p className="text-xs font-mono text-surface-400">
                                                                {
                                                                    poi.product
                                                                        .sku
                                                                }
                                                            </p>
                                                        )}
                                                    </td>
                                                    <td>
                                                        <span className="badge badge-neutral capitalize">
                                                            {itemType}
                                                        </span>
                                                    </td>
                                                    <td className="text-center tabular-nums font-semibold text-danger">
                                                        -{item.quantity}
                                                    </td>
                                                    <td className="text-right tabular-nums text-sm text-surface-700">
                                                        {poi?.unit_price
                                                            ? poi.unit_price.toLocaleString(
                                                                  "en-KE",
                                                                  {
                                                                      minimumFractionDigits: 2,
                                                                  },
                                                              )
                                                            : "-"}
                                                    </td>
                                                    <td className="text-sm text-surface-600">
                                                        {item.reason || "-"}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className="text-sm text-surface-400 py-4 text-center">
                                No item details available
                            </p>
                        )}
                    </div>

                    {/* Notes */}
                    {detail.notes && (
                        <div className="bg-surface-50 rounded-lg px-4 py-3">
                            <p className="text-xs text-surface-400 mb-1">
                                Notes
                            </p>
                            <p className="text-sm text-surface-700">
                                {detail.notes}
                            </p>
                        </div>
                    )}

                    {/* Created by */}
                    {detail.created_by_user && (
                        <p className="text-xs text-surface-400">
                            Raised by{" "}
                            <span className="font-medium text-surface-600">
                                {detail.created_by_user.first_name}{" "}
                                {detail.created_by_user.last_name}
                            </span>
                        </p>
                    )}
                </div>
            ) : (
                <p className="text-sm text-surface-400 py-8 text-center">
                    Return not found.
                </p>
            )}
        </Modal>
    );
}

// ─── Return form ──────────────────────────────────────────────────────────────

interface ReturnItemState {
    po_item_id: number;
    name: string;
    max_qty: number;
    quantity: string;
    reason: string;
}

function CreateReturnModal({ onClose }: { onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();

    const [step, setStep] = useState<"select_po" | "select_items">("select_po");
    const [selectedPOId, setSelectedPOId] = useState("");
    const [notes, setNotes] = useState("");
    const [returnItems, setReturnItems] = useState<
        Record<number, ReturnItemState>
    >({});

    const { data: poData } = useQuery({
        queryKey: ["purchase-orders-received"],
        queryFn: () =>
            purchaseOrderApi.list({
                status: "received,partially_received",
                per_page: 100,
            }),
    });
    const receivedPOs = poData?.data ?? [];

    const { data: detailData, isLoading: detailLoading } = useQuery({
        queryKey: ["po-return-detail", selectedPOId],
        queryFn: () => purchaseOrderApi.get(parseInt(selectedPOId)),
        enabled: !!selectedPOId,
    });
    const selectedPO = detailData?.purchase_order;
    const receivedItems =
        selectedPO?.items.filter((i) => i.quantity_received > 0) ?? [];

    const initReturnItems = () => {
        const init: Record<number, ReturnItemState> = {};
        receivedItems.forEach((item) => {
            init[item.id] = {
                po_item_id: item.id,
                name:
                    item.product?.name ??
                    item.material?.name ??
                    `Item #${item.item_id}`,
                max_qty: item.quantity_received,
                quantity: "0",
                reason: "",
            };
        });
        return init;
    };

    const states =
        Object.keys(returnItems).length === 0 && receivedItems.length > 0
            ? initReturnItems()
            : returnItems;

    const setItem = (id: number, key: "quantity" | "reason", value: string) =>
        setReturnItems((prev) => ({
            ...prev,
            [id]: { ...(prev[id] ?? states[id]), [key]: value },
        }));

    const totalReturning = Object.values(states).reduce(
        (sum, i) => sum + (parseInt(i.quantity) || 0),
        0,
    );

    const RETURN_REASONS = [
        "Defective / Damaged",
        "Wrong item received",
        "Quality not as specified",
        "Overstock / Excess inventory",
        "Incorrect quantity sent",
        "Price discrepancy",
        "Other",
    ];

    const mutation = useMutation({
        mutationFn: () => {
            const items = Object.values(states)
                .filter((i) => (parseInt(i.quantity) || 0) > 0)
                .map((i) => ({
                    po_item_id: i.po_item_id,
                    quantity: parseInt(i.quantity),
                    reason: i.reason,
                }));
            return purchaseOrderApi.createReturn(parseInt(selectedPOId), {
                items,
                notes,
            });
        },
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["purchase-orders"] });
            qc.invalidateQueries({ queryKey: ["purchase-returns"] });
            toast.success(`Purchase return raised - ${res.return_number}`);
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open
            onClose={onClose}
            title="New Purchase Return"
            size="xl"
            footer={
                <div className="flex gap-2 justify-end w-full">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Cancel
                    </button>
                    {step === "select_po" ? (
                        <button
                            className="btn-primary btn-sm"
                            disabled={!selectedPOId || detailLoading}
                            onClick={() => setStep("select_items")}
                        >
                            {detailLoading ? (
                                <Spinner size="sm" />
                            ) : (
                                "Select Items →"
                            )}
                        </button>
                    ) : (
                        <>
                            <button
                                className="btn-secondary btn-sm"
                                onClick={() => setStep("select_po")}
                            >
                                ← Back
                            </button>
                            <button
                                className="btn-danger btn-sm"
                                disabled={
                                    mutation.isPending || totalReturning === 0
                                }
                                onClick={() => mutation.mutate()}
                            >
                                {mutation.isPending ? (
                                    <Spinner size="sm" />
                                ) : (
                                    `Submit Return (${totalReturning} units)`
                                )}
                            </button>
                        </>
                    )}
                </div>
            }
        >
            {step === "select_po" ? (
                <div className="space-y-4">
                    <p className="text-sm text-surface-600">
                        Select the purchase order containing items you want to
                        return to the supplier. Only orders that have been
                        received (fully or partially) are shown.
                    </p>
                    <Field label="Purchase Order *">
                        <FieldSelect
                            className="input"
                            value={selectedPOId}
                            onChange={(e) => {
                                setSelectedPOId(e.target.value);
                                setReturnItems({});
                            }}
                        >
                            <option value="">
                                -- Select a Purchase Order --
                            </option>
                            {receivedPOs.map((po) => (
                                <option key={po.id} value={po.id}>
                                    {po.po_number} - {po.supplier?.name} (
                                    {new Date(
                                        po.created_at,
                                    ).toLocaleDateString()}
                                    )
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>

                    {selectedPO && (
                        <div className="bg-surface-50 rounded-xl p-4 space-y-2">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <span className="font-semibold text-surface-900">
                                    {selectedPO.po_number}
                                </span>
                                <span className="badge badge-neutral">
                                    {selectedPO.status.replace("_", " ")}
                                </span>
                            </div>
                            <p className="text-sm text-surface-600">
                                Supplier: {selectedPO.supplier?.name}
                            </p>
                            <p className="text-sm text-surface-600">
                                Total: {selectedPO.currency_code}{" "}
                                {(selectedPO.total_amount ?? 0).toLocaleString(
                                    "en-KE",
                                    {
                                        minimumFractionDigits: 2,
                                    },
                                )}
                            </p>
                            <p className="text-xs text-surface-400">
                                {receivedItems.length} received line items
                                eligible for return
                            </p>
                        </div>
                    )}
                </div>
            ) : (
                <div className="space-y-5">
                    <div className="flex flex-wrap gap-3 p-3 bg-surface-50 rounded-lg text-sm">
                        <span className="font-mono font-semibold text-brand-700">
                            {selectedPO?.po_number}
                        </span>
                        <span className="text-surface-500">•</span>
                        <span className="text-surface-700">
                            {selectedPO?.supplier?.name}
                        </span>
                    </div>

                    <div>
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                            Select Items to Return
                        </p>
                        <div className="space-y-3">
                            {receivedItems.map((item) => {
                                const state = states[item.id] ?? {
                                    quantity: "0",
                                    reason: "",
                                };
                                const name =
                                    item.product?.name ??
                                    item.material?.name ??
                                    `Item #${item.item_id}`;
                                const qty = parseInt(state.quantity) || 0;
                                const hasQty = qty > 0;

                                return (
                                    <div
                                        key={item.id}
                                        className={clsx(
                                            "border rounded-xl p-4 space-y-3 transition-all",
                                            hasQty
                                                ? "border-danger/40 bg-danger-light/20"
                                                : "border-surface-200",
                                        )}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div>
                                                <p className="font-semibold text-surface-900">
                                                    {name}
                                                </p>
                                                {item.product?.sku && (
                                                    <p className="text-xs font-mono text-surface-400">
                                                        {item.product.sku}
                                                    </p>
                                                )}
                                                <p className="text-xs text-surface-500 mt-1">
                                                    Received:{" "}
                                                    <strong>
                                                        {item.quantity_received}
                                                    </strong>{" "}
                                                    units available to return
                                                </p>
                                            </div>
                                            <span className="badge badge-neutral capitalize flex-shrink-0">
                                                {item.item_type}
                                            </span>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <Field label="Quantity to Return">
                                                <FieldInput
                                                    type="number"
                                                    min="0"
                                                    max={item.quantity_received}
                                                    className={clsx(
                                                        "input",
                                                        hasQty &&
                                                            "border-danger focus:border-danger focus:ring-danger",
                                                    )}
                                                    value={state.quantity}
                                                    onChange={(e) =>
                                                        setItem(
                                                            item.id,
                                                            "quantity",
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                <p className="text-2xs text-surface-400 mt-1">
                                                    Max:{" "}
                                                    {item.quantity_received}
                                                </p>
                                            </Field>
                                            <Field
                                                label={`Return Reason ${hasQty ? "*" : ""}`}
                                            >
                                                <FieldSelect
                                                    className={clsx(
                                                        "input",
                                                        hasQty &&
                                                            !state.reason &&
                                                            "input-error",
                                                    )}
                                                    value={state.reason}
                                                    onChange={(e) =>
                                                        setItem(
                                                            item.id,
                                                            "reason",
                                                            e.target.value,
                                                        )
                                                    }
                                                    disabled={!hasQty}
                                                >
                                                    <option value="">
                                                        -- Select Reason --
                                                    </option>
                                                    {RETURN_REASONS.map((r) => (
                                                        <option
                                                            key={r}
                                                            value={r}
                                                        >
                                                            {r}
                                                        </option>
                                                    ))}
                                                </FieldSelect>
                                            </Field>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <Field label="Additional Notes">
                        <FieldTextarea
                            className="input resize-none"
                            rows={2}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Supplier agreement, credit note reference, etc."
                        />
                    </Field>

                    {totalReturning > 0 && (
                        <div className="flex items-center gap-3 px-4 py-3 bg-danger-light rounded-lg">
                            <ReturnIcon className="w-5 h-5 text-danger flex-shrink-0" />
                            <p className="text-sm text-danger-dark">
                                <strong>{totalReturning} units</strong> will be
                                removed from inventory and a return record
                                created.
                            </p>
                        </div>
                    )}
                </div>
            )}
        </Modal>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function PurchaseReturnsPage() {
    const { can } = usePermissions();
    const canCreateReturn = can("procurement.receive");
    const [page, setPage] = useState(1);
    const table = { page, setPage };
    const navigate = useNavigate();
    const [createOpen, setCreateOpen] = useState(false);
    const [selectedReturn, setSelectedReturn] =
        useState<PurchaseReturnRecord | null>(null);
    const [supplierFilter, setSupplierFilter] = useState("");

    const { data: suppliersData } = useQuery({
        queryKey: ["suppliers-dropdown"],
        queryFn: () => supplierApi.list({ per_page: 200, status: "active" }),
    });
    const suppliers = suppliersData?.data ?? [];

    const { data, isLoading } = useQuery({
        queryKey: [
            "purchase-returns",
            { page: table.page, supplier: supplierFilter },
        ],
        queryFn: () =>
            get<{ data: PurchaseReturnRecord[]; meta: any }>(
                "/v1/admin/purchase-returns",
                {
                    params: {
                        page: table.page,
                        per_page: 20,
                        ...(supplierFilter && { supplier_id: supplierFilter }),
                    },
                },
            ),
    });

    const returns = data?.data ?? [];
    const meta = data?.meta;

    // Group the current page of rows by return_date (falling back to
    // created_at). Pagination, sort, and filters are untouched - this only
    // re-partitions the rows already fetched.
    const returnGroups = groupRowsByDate(returns, (r) => r.return_date ?? r.created_at);

    return (
        <div className="space-y-5">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div className="page-header mb-0">
                    <h1 className="page-title">Purchase Returns</h1>
                    <p className="page-subtitle">
                        Return defective or excess items to suppliers
                    </p>
                </div>
                {canCreateReturn && (
                <button
                    className="btn-danger btn-sm whitespace-nowrap"
                    onClick={() => setCreateOpen(true)}
                >
                    <ReturnIcon className="w-4 h-4" /> Raise Return
                </button>
                )}
            </div>

            {/* Info */}
            <div className="flex items-start gap-3 px-4 py-3 bg-warning-light border border-warning/20 rounded-xl text-sm">
                <InfoIcon className="w-5 h-5 text-warning flex-shrink-0 mt-0.5" />
                <span className="text-warning-dark">
                    Raising a purchase return will reduce inventory by the
                    specified quantities. Ensure you have the supplier's
                    agreement before processing.
                </span>
            </div>

            {/* Filters */}
            <div className="card">
                <div className="card-body py-3 flex gap-3">
                    <select
                        className="input w-full sm:w-56"
                        value={supplierFilter}
                        onChange={(e) => setSupplierFilter(e.target.value)}
                    >
                        <option value="">All Suppliers</option>
                        {suppliers.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {/* Table */}
            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex items-center justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                ) : returns.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-2">
                        <ReturnBoxIcon className="w-10 h-10 opacity-30" />
                        <p className="text-sm font-medium text-surface-500">
                            No purchase returns yet
                        </p>
                        {canCreateReturn && (
                        <button
                            className="btn-danger btn-sm mt-2"
                            onClick={() => setCreateOpen(true)}
                        >
                            Raise a return
                        </button>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="table-wrapper rounded-none border-0">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th>Return Number</th>
                                        <th>Purchase Order</th>
                                        <th className="hidden sm:table-cell">
                                            Supplier
                                        </th>
                                        <th className="text-center hidden md:table-cell">
                                            Items
                                        </th>
                                        <th>Date</th>
                                        <th>Notes</th>
                                        <th className="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {returnGroups.map((group) => (
                                        <Fragment key={group.key}>
                                            <DateGroupHeaderRow label={group.label} colSpan={7} />
                                            {group.items.map((r) => (
                                        <tr
                                            key={r.id}
                                            className="cursor-pointer hover:bg-surface-50"
                                            onClick={() => navigate(`/procurement/returns/${r.id}`)}
                                        >
                                            <td>
                                                <span className="font-mono text-sm font-semibold text-danger">
                                                    {r.return_number}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="font-mono text-xs text-brand-600">
                                                    {r.purchase_order
                                                        ?.po_number ??
                                                        r.po_number ??
                                                        "-"}
                                                </span>
                                            </td>
                                            <td className="hidden sm:table-cell">
                                                <span className="font-medium text-surface-900">
                                                    {r.supplier?.name ??
                                                        r.purchase_order
                                                            ?.supplier?.name ??
                                                        r.supplier_name ??
                                                        "-"}
                                                </span>
                                            </td>
                                            <td className="text-center hidden md:table-cell">
                                                <span className="text-sm text-surface-600">
                                                    {r.items_count ??
                                                        r.items?.length ??
                                                        "-"}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="text-sm text-surface-600">
                                                    {new Date(
                                                        r.return_date ??
                                                            r.created_at,
                                                    ).toLocaleDateString()}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="text-sm text-surface-500 truncate max-w-xs block">
                                                    {r.notes || r.reason || "-"}
                                                </span>
                                            </td>
                                            <td
                                                className="text-right"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <button
                                                    className="btn-ghost btn-sm"
                                                    title="View details"
                                                    onClick={() =>
                                                        navigate(`/procurement/returns/${r.id}`)
                                                    }
                                                >
                                                    <EyeIcon className="w-4 h-4" />
                                                </button>
                                            </td>
                                        </tr>
                                            ))}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {meta && meta.last_page > 1 && (
                            <div className="px-4 py-3 border-t border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-xs text-surface-500">
                                    Showing {meta.from}–{meta.to} of{" "}
                                    {meta.total}
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
                                        disabled={
                                            meta.current_page === meta.last_page
                                        }
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
                    </>
                )}
            </div>

            {/* Modals */}
            {createOpen && (
                <CreateReturnModal onClose={() => setCreateOpen(false)} />
            )}
            {selectedReturn && (
                <ReturnDetailModal
                    returnId={selectedReturn.id}
                    onClose={() => setSelectedReturn(null)}
                />
            )}
        </div>
    );
}

// ─── Icons ────────────────────────────────────────────────────────────────────
const ReturnIcon = ({ className }: { className?: string }) => (
    <svg
        className={className ?? "w-4 h-4"}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"
        />
    </svg>
);
const ReturnBoxIcon = ({ className }: { className?: string }) => (
    <svg
        className={className}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.5}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M21 7.5l-2.25-1.313M21 7.5v2.25m0-2.25l-2.25 1.313M3 7.5l2.25-1.313M3 7.5l2.25 1.313M3 7.5v2.25m9 3l2.25-1.313M12 12.75l-2.25-1.313M12 12.75V15m0 6.75l2.25-1.313M12 21.75V19.5m0 2.25l-2.25-1.313m0-16.875L12 2.25l2.25 1.313M21 14.25v2.25l-9 5.25-9-5.25v-2.25"
        />
    </svg>
);
const InfoIcon = ({ className }: { className?: string }) => (
    <svg
        className={className}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={2}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"
        />
    </svg>
);
const EyeIcon = ({ className }: { className?: string }) => (
    <svg
        className={className ?? "w-4 h-4"}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
        />
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
        />
    </svg>
);