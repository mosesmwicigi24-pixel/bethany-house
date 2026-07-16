import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate, Link } from "react-router-dom";
import { purchaseOrderApi, supplierApi } from "@/api/procurement";
import type { PurchaseOrder, POStatus } from "@/api/procurement";
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

// ─── Status config ────────────────────────────────────────────────────────────

const PO_STATUSES: Record<
    POStatus,
    { label: string; badge: string; dot: string }
> = {
    draft: { label: "Draft", badge: "badge-neutral", dot: "bg-surface-400" },
    pending_approval: {
        label: "Pending Approval",
        badge: "badge-warning",
        dot: "bg-warning",
    },
    approved: { label: "Approved", badge: "badge-info", dot: "bg-info" },
    ordered: { label: "Ordered", badge: "badge-info", dot: "bg-blue-500" },
    partially_received: {
        label: "Partial Receipt",
        badge: "badge-warning",
        dot: "bg-amber-500",
    },
    received: {
        label: "Fully Received",
        badge: "badge-success",
        dot: "bg-success",
    },
    cancelled: { label: "Cancelled", badge: "badge-danger", dot: "bg-danger" },
};

// Allowed transitions
const STATUS_TRANSITIONS: Partial<Record<POStatus, POStatus[]>> = {
    draft: ["pending_approval", "cancelled"],
    pending_approval: ["approved", "draft", "cancelled"],
    approved: ["ordered", "cancelled"],
    ordered: ["partially_received", "received", "cancelled"],
    partially_received: ["received", "cancelled"],
};

function StatusBadge({ status }: { status: POStatus }) {
    const cfg = PO_STATUSES[status] ?? PO_STATUSES.draft;
    return (
        <span className={clsx("badge", cfg.badge)}>
            <span className={clsx("w-1.5 h-1.5 rounded-full", cfg.dot)} />
            {cfg.label}
        </span>
    );
}

// ─── PO Form modal ────────────────────────────────────────────────────────────

function POFormModal({ onClose }: { onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();

    const [supplierId, setSupplierId] = useState("");
    const [deliveryDate, setDeliveryDate] = useState("");
    const [currency, setCurrency] = useState("KES");
    const [shippingCost, setShippingCost] = useState("0");
    const [tax, setTax] = useState("0");
    const [paymentTerms, setPaymentTerms] = useState("NET30");
    const [notes, setNotes] = useState("");
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    // Line items
    const [items, setItems] = useState<
        Array<{
            id: string;
            type: "product" | "material";
            item_id: string;
            name: string;
            quantity: string;
            unit_price: string;
        }>
    >([]);
    const [itemSearch, setItemSearch] = useState("");
    const [itemType, setItemType] = useState<"product" | "material">("product");
    const [searchResults, setSearchResults] = useState<
        Array<{
            id:        number;
            name:      string;
            sku?:      string;
            unit?:     string;
            category?: string;
            brand?:    string;
            image?:    string;
        }>
    >([]);
    const [searching, setSearching] = useState(false);

    const { data: suppliersData } = useQuery({
        queryKey: ["suppliers", { per_page: 100, status: "active" }],
        queryFn: () => supplierApi.list({ per_page: 100, status: "active" }),
    });
    const suppliers = suppliersData?.data ?? [];

    const searchItems = async (q: string) => {
        if (q.length < 2) return setSearchResults([]);
        setSearching(true);
        try {
            if (itemType === "material") {
                const res = await get<{
                    data: Array<{
                        id: number;
                        name: string;
                        unit_of_measure: string;
                    }>;
                }>("/v1/admin/inventory/materials", {
                    params: { search: q, per_page: 20 },
                });
                setSearchResults(
                    (res.data ?? []).map((m) => ({
                        id: m.id,
                        name: m.name,
                        unit: m.unit_of_measure,
                    })),
                );
            } else {
                const res = await get<{
                    data: Array<{ id: number; name: string; sku: string }>;
                }>("/v1/admin/products", { params: { search: q, per_page: 20 } });
                setSearchResults(
                    (res.data ?? []).map((p: any) => ({
                        id:       p.id,
                        // adminIndex returns a formatted object: name lives in
                        // en_translation.name, image in primary_image.image_url
                        name:     p.en_translation?.name
                                  ?? p.sku
                                  ?? `Product #${p.id}`,
                        sku:      p.sku,
                        brand:    p.brand ?? undefined,
                        category: p.category?.name_en ?? undefined,
                        image:    p.primary_image?.image_url ?? undefined,
                    })),
                );
            }
        } catch {
            setSearchResults([]);
        } finally {
            setSearching(false);
        }
    };

    const addItem = (item: { id: number; name: string }) => {
        setItems((prev) => [
            ...prev,
            {
                id: Math.random().toString(36).slice(2),
                type: itemType,
                item_id: String(item.id),
                name: item.name,
                quantity: "1",
                unit_price: "0",
            },
        ]);
        setItemSearch("");
        setSearchResults([]);
    };

    const updateItem = (
        id: string,
        key: "quantity" | "unit_price",
        value: string,
    ) =>
        setItems((prev) =>
            prev.map((i) => (i.id === id ? { ...i, [key]: value } : i)),
        );

    const removeItem = (id: string) =>
        setItems((prev) => prev.filter((i) => i.id !== id));

    const subtotal = items.reduce(
        (sum, i) =>
            sum +
            (parseFloat(i.quantity) || 0) * (parseFloat(i.unit_price) || 0),
        0,
    );
    const total =
        subtotal + (parseFloat(shippingCost) || 0) + (parseFloat(tax) || 0);

    const mutation = useMutation({
        mutationFn: () =>
            purchaseOrderApi.create({
                supplier_id: parseInt(supplierId),
                expected_delivery_date: deliveryDate,
                currency,
                shipping_cost: parseFloat(shippingCost) || 0,
                tax: parseFloat(tax) || 0,
                payment_terms: paymentTerms,
                notes,
                items: items.map((i) => ({
                    type: i.type,
                    item_id: parseInt(i.item_id),
                    quantity: parseInt(i.quantity),
                    unit_price: parseFloat(i.unit_price),
                })),
            }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["purchase-orders"] });
            toast.success("Purchase order created");
            onClose();
        },
        onError: (err: ApiError) => {
            setErrors(err.errors ?? {});
            toast.error(err.message);
        },
    });

    return (
        <Modal
            open
            onClose={onClose}
            title="New Purchase Order"
            size="xl"
            footer={
                <div className="flex gap-2 justify-end w-full">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        className="btn-primary btn-sm"
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || items.length === 0}
                    >
                        {mutation.isPending ? (
                            <Spinner size="sm" />
                        ) : (
                            "Create Purchase Order"
                        )}
                    </button>
                </div>
            }
        >
            <div className="space-y-5">
                {/* Basic info */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field label="Supplier *" error={errors.supplier_id?.[0]}>
                        <FieldSelect
                            className={clsx(
                                "input",
                                errors.supplier_id && "input-error",
                            )}
                            value={supplierId}
                            onChange={(e) => setSupplierId(e.target.value)}
                        >
                            <option value="">-- Select Supplier --</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>
                    <Field
                        label="Expected Delivery Date *"
                        error={errors.expected_delivery_date?.[0]}
                    >
                        <FieldInput
                            type="date"
                            className={clsx(
                                "input",
                                errors.expected_delivery_date && "input-error",
                            )}
                            value={deliveryDate}
                            onChange={(e) => setDeliveryDate(e.target.value)}
                        />
                    </Field>
                    <Field label="Currency">
                        <FieldSelect
                            className="input"
                            value={currency}
                            onChange={(e) => setCurrency(e.target.value)}
                        >
                            <option value="KES">KES - Kenyan Shilling</option>
                            <option value="USD">USD - US Dollar</option>
                            <option value="EUR">EUR - Euro</option>
                            <option value="GBP">GBP - British Pound</option>
                        </FieldSelect>
                    </Field>
                    <Field label="Payment Terms">
                        <FieldSelect
                            className="input"
                            value={paymentTerms}
                            onChange={(e) => setPaymentTerms(e.target.value)}
                        >
                            <option value="COD">COD</option>
                            <option value="NET15">NET 15</option>
                            <option value="NET30">NET 30</option>
                            <option value="NET60">NET 60</option>
                            <option value="prepaid">Prepaid</option>
                        </FieldSelect>
                    </Field>
                </div>

                {/* Line items */}
                <div>
                    <div className="flex items-center justify-between mb-2">
                        <p className="text-xs font-semibold text-surface-600 uppercase tracking-wider">
                            Line Items
                        </p>
                    </div>

                    {/* Item search */}
                    <div className="flex gap-2 mb-3">
                        <select
                            className="input w-32 flex-shrink-0"
                            value={itemType}
                            onChange={(e) =>
                                setItemType(
                                    e.target.value as "product" | "material",
                                )
                            }
                        >
                            <option value="product">Product</option>
                            <option value="material">Material</option>
                        </select>
                        <div className="relative flex-1">
                            <input
                                className="input"
                                placeholder={`Search ${itemType === "product" ? "products / variants" : "raw materials"}...`}
                                value={itemSearch}
                                onChange={(e) => {
                                    setItemSearch(e.target.value);
                                    searchItems(e.target.value);
                                }}
                            />
                            {(searching || searchResults.length > 0) && (
                                <div className="absolute z-20 top-full left-0 right-0 mt-1 bg-white rounded-xl border border-surface-200 shadow-xl max-h-72 overflow-auto">
                                    {searching && (
                                        <div className="px-3 py-2 text-xs text-surface-400">
                                            Searching...
                                        </div>
                                    )}
                                    {searchResults.map((r) => (
                                        <button
                                            key={r.id}
                                            className="w-full text-left px-3 py-2.5 hover:bg-surface-50 flex items-center gap-3 border-b border-surface-50 last:border-0 transition-colors"
                                            onClick={() => addItem(r)}
                                        >
                                            {/* Thumbnail */}
                                            <div className="w-9 h-9 rounded-lg bg-surface-100 flex items-center justify-center shrink-0 overflow-hidden border border-surface-200">
                                                {r.image ? (
                                                    <img src={r.image} alt={r.name} className="w-full h-full object-cover" />
                                                ) : (
                                                    <svg className="w-4 h-4 text-surface-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                                    </svg>
                                                )}
                                            </div>

                                            {/* Info */}
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-semibold text-surface-900 truncate leading-tight">
                                                    {r.name}
                                                </p>
                                                <div className="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                                    {r.sku && (
                                                        <span className="text-2xs font-mono bg-surface-100 text-surface-500 px-1.5 py-0.5 rounded">
                                                            {r.sku}
                                                        </span>
                                                    )}
                                                    {r.category && (
                                                        <span className="text-2xs text-brand-600 bg-brand-50 px-1.5 py-0.5 rounded font-medium">
                                                            {r.category}
                                                        </span>
                                                    )}
                                                    {r.brand && (
                                                        <span className="text-2xs text-surface-400 truncate">
                                                            {r.brand}
                                                        </span>
                                                    )}
                                                    {r.unit && (
                                                        <span className="text-2xs text-surface-400">
                                                            · {r.unit}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Add hint */}
                                            <svg className="w-3.5 h-3.5 text-surface-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                            </svg>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Items table */}
                    {items.length > 0 ? (
                        <div className="border border-surface-200 rounded-lg overflow-hidden">
                            <div className="table-wrapper rounded-none border-0">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Type</th>
                                        <th className="text-center">Qty</th>
                                        <th className="text-right">
                                            Unit Price ({currency})
                                        </th>
                                        <th className="text-right">Subtotal</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.map((item) => {
                                        const lineTotal =
                                            (parseFloat(item.quantity) || 0) *
                                            (parseFloat(item.unit_price) || 0);
                                        return (
                                            <tr key={item.id}>
                                                <td className="font-medium text-surface-900">
                                                    {item.name}
                                                </td>
                                                <td>
                                                    <span className="badge badge-neutral capitalize">
                                                        {item.type}
                                                    </span>
                                                </td>
                                                <td className="text-center">
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        className="input w-20 text-center text-sm py-1"
                                                        value={item.quantity}
                                                        onChange={(e) =>
                                                            updateItem(
                                                                item.id,
                                                                "quantity",
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="text-right">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        className="input w-28 text-right text-sm py-1"
                                                        value={item.unit_price}
                                                        onChange={(e) =>
                                                            updateItem(
                                                                item.id,
                                                                "unit_price",
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="text-right font-semibold tabular-nums">
                                                    {currency}{" "}
                                                    {lineTotal.toLocaleString(
                                                        "en-KE",
                                                        {
                                                            minimumFractionDigits: 2,
                                                        },
                                                    )}
                                                </td>
                                                <td>
                                                    <button
                                                        className="btn-ghost btn-sm text-danger"
                                                        onClick={() =>
                                                            removeItem(item.id)
                                                        }
                                                    >
                                                        <TrashIcon className="w-3.5 h-3.5" />
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            </div>
                        </div>
                    ) : (
                        <div className="border-2 border-dashed border-surface-200 rounded-lg py-8 text-center text-surface-400 text-sm">
                            Search and add items above to build the order
                        </div>
                    )}
                </div>

                {/* Totals & notes */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
                    <Field label="Notes">
                        <FieldTextarea
                            className="input resize-none"
                            rows={3}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Supplier reference, delivery instructions..."
                        />
                    </Field>
                    <div className="space-y-2">
                        {[
                            { label: "Subtotal", value: subtotal },
                            {
                                label: "Shipping Cost",
                                value: null,
                                input: true,
                                key: "shipping",
                            },
                            {
                                label: "Tax / Duty",
                                value: null,
                                input: true,
                                key: "tax",
                            },
                        ].map((row) => (
                            <div
                                key={row.label}
                                className="flex items-center justify-between text-sm"
                            >
                                <span className="text-surface-500">
                                    {row.label}
                                </span>
                                {row.input ? (
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        className="input w-36 text-right py-1 text-sm"
                                        value={
                                            row.key === "shipping"
                                                ? shippingCost
                                                : tax
                                        }
                                        onChange={(e) =>
                                            row.key === "shipping"
                                                ? setShippingCost(
                                                      e.target.value,
                                                  )
                                                : setTax(e.target.value)
                                        }
                                    />
                                ) : (
                                    <span className="font-semibold tabular-nums">
                                        {currency}{" "}
                                        {(row.value as number).toLocaleString(
                                            "en-KE",
                                            { minimumFractionDigits: 2 },
                                        )}
                                    </span>
                                )}
                            </div>
                        ))}
                        <div className="flex items-center justify-between pt-2 border-t border-surface-200">
                            <span className="font-semibold text-surface-900">
                                Total
                            </span>
                            <span className="text-lg font-bold text-brand-700 tabular-nums">
                                {currency}{" "}
                                {total.toLocaleString("en-KE", {
                                    minimumFractionDigits: 2,
                                })}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>
    );
}

// ─── Status update modal ──────────────────────────────────────────────────────

function StatusUpdateModal({
    po,
    onClose,
}: {
    po: PurchaseOrder;
    onClose: () => void;
}) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [newStatus, setNewStatus] = useState<POStatus | "">("");
    const [notes, setNotes] = useState("");

    const transitions = STATUS_TRANSITIONS[po.status] ?? [];

    const mutation = useMutation({
        mutationFn: () =>
            purchaseOrderApi.updateStatus(po.id, newStatus as POStatus, notes),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["purchase-orders"] });
            toast.success("Status updated successfully");
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={`Update Status - ${po.po_number}`}
            size="sm"
            footer={
                <div className="flex gap-2 justify-end w-full">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        className="btn-primary btn-sm"
                        disabled={!newStatus || mutation.isPending}
                        onClick={() => mutation.mutate()}
                    >
                        {mutation.isPending ? (
                            <Spinner size="sm" />
                        ) : (
                            "Update Status"
                        )}
                    </button>
                </div>
            }
        >
            <div className="space-y-4">
                <div className="flex items-center gap-2">
                    <StatusBadge status={po.status} />
                    <span className="text-surface-400">→</span>
                    <select
                        className="input flex-1"
                        value={newStatus}
                        onChange={(e) =>
                            setNewStatus(e.target.value as POStatus)
                        }
                    >
                        <option value="">Select new status</option>
                        {transitions.map((s) => (
                            <option key={s} value={s}>
                                {PO_STATUSES[s].label}
                            </option>
                        ))}
                    </select>
                </div>
                <Field label="Notes (optional)">
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Reason for status change..."
                    />
                </Field>
            </div>
        </Modal>
    );
}

// ─── PO detail modal ──────────────────────────────────────────────────────────

function PODetailModal({
    po,
    onClose,
    onUpdateStatus,
}: {
    po: PurchaseOrder;
    onClose: () => void;
    onUpdateStatus: () => void;
}) {
    const { data, isLoading } = useQuery({
        queryKey: ["purchase-order-detail", po.id],
        queryFn: () => purchaseOrderApi.get(po.id),
    });

    const detail = data?.purchase_order;
    const history = data?.receiving_history ?? [];

    const { can } = usePermissions();
    const canApprove = can("procurement.approve");
    const canReceive = ["ordered", "partially_received"].includes(po.status) && can("procurement.receive");

    return (
        <Modal
            open
            onClose={onClose}
            title={`Purchase Order - ${po.po_number}`}
            size="xl"
            footer={
                <div className="flex gap-2 justify-end w-full flex-wrap">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Close
                    </button>
                    {STATUS_TRANSITIONS[po.status]?.length && canApprove ? (
                        <button
                            className="btn-secondary btn-sm"
                            onClick={onUpdateStatus}
                        >
                            Update Status
                        </button>
                    ) : null}
                    {canReceive && (
                        <button
                            className="btn-primary btn-sm"
                            onClick={() => {
                                onClose(); /* navigate to GRN */
                            }}
                        >
                            Record Receipt (GRN)
                        </button>
                    )}
                </div>
            }
        >
            {isLoading ? (
                <div className="flex justify-center py-10">
                    <Spinner size="lg" />
                </div>
            ) : detail ? (
                <div className="space-y-5">
                    {/* Summary cards */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        {[
                            {
                                label: "Status",
                                value: <StatusBadge status={detail.status} />,
                            },
                            {
                                label: "Supplier",
                                value: detail.supplier?.name ?? "-",
                            },
                            {
                                label: "Expected Delivery",
                                value: new Date(
                                    detail.expected_delivery_date,
                                ).toLocaleDateString(),
                            },
                            {
                                label: "Total",
                                value: `${(detail.currency ?? detail.currency_code)} ${Number(detail.total_amount).toLocaleString()}`,
                            },
                        ].map((s) => (
                            <div
                                key={s.label}
                                className="bg-surface-50 rounded-lg px-3 py-2"
                            >
                                <p className="text-2xs text-surface-400 uppercase tracking-wider mb-1">
                                    {s.label}
                                </p>
                                <div className="font-semibold text-surface-900 text-sm">
                                    {s.value}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Items */}
                    <div>
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            Line Items
                        </p>
                        <div className="border border-surface-100 rounded-lg overflow-hidden">
                            <div className="table-wrapper rounded-none border-0">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Type</th>
                                        <th className="text-center">Ordered</th>
                                        <th className="text-center">
                                            Received
                                        </th>
                                        <th className="text-right">
                                            Unit Price
                                        </th>
                                        <th className="text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {detail.items.map((item) => {
                                        const name =
                                            item.product?.name ??
                                            item.material?.name ??
                                            `#${item.item_id}`;
                                        const pct =
                                            item.quantity > 0
                                                ? (item.quantity_received /
                                                      item.quantity) *
                                                  100
                                                : 0;
                                        return (
                                            <tr key={item.id}>
                                                <td>
                                                    <p className="font-medium text-surface-900">
                                                        {name}
                                                    </p>
                                                    {item.product?.sku && (
                                                        <p className="text-xs font-mono text-surface-400">
                                                            {item.product.sku}
                                                        </p>
                                                    )}
                                                </td>
                                                <td>
                                                    <span className="badge badge-neutral capitalize">
                                                        {item.item_type}
                                                    </span>
                                                </td>
                                                <td className="text-center tabular-nums">
                                                    {item.quantity}
                                                </td>
                                                <td className="text-center">
                                                    <div className="flex flex-col items-center gap-1">
                                                        <span
                                                            className={clsx(
                                                                "tabular-nums text-sm font-semibold",
                                                                item.quantity_received >=
                                                                    item.quantity
                                                                    ? "text-success"
                                                                    : item.quantity_received >
                                                                        0
                                                                      ? "text-warning"
                                                                      : "text-surface-400",
                                                            )}
                                                        >
                                                            {
                                                                item.quantity_received
                                                            }
                                                        </span>
                                                        <div className="w-16 h-1 bg-surface-100 rounded-full overflow-hidden">
                                                            <div
                                                                className={clsx(
                                                                    "h-full rounded-full",
                                                                    pct >= 100
                                                                        ? "bg-success"
                                                                        : pct >
                                                                            0
                                                                          ? "bg-warning"
                                                                          : "bg-surface-200",
                                                                )}
                                                                style={{
                                                                    width: `${Math.min(pct, 100)}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="text-right tabular-nums">
                                                    {(detail.currency ?? detail.currency_code)}{" "}
                                                    {Number(
                                                        item.unit_price,
                                                    ).toLocaleString()}
                                                </td>
                                                <td className="text-right font-semibold tabular-nums">
                                                    {(detail.currency ?? detail.currency_code)}{" "}
                                                    {Number(
                                                        (item.subtotal ?? (item as any).total_price),
                                                    ).toLocaleString()}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-surface-50">
                                        <td colSpan={4}></td>
                                        <td className="text-right text-xs text-surface-500 font-medium">
                                            Subtotal
                                        </td>
                                        <td className="text-right font-semibold">
                                            {(detail.currency ?? detail.currency_code)}{" "}
                                            {Number(
                                                detail.subtotal,
                                            ).toLocaleString()}
                                        </td>
                                    </tr>
                                    {(detail.shipping_cost ?? detail.shipping_amount ?? 0) > 0 && (
                                        <tr className="bg-surface-50">
                                            <td colSpan={4}></td>
                                            <td className="text-right text-xs text-surface-500 font-medium">
                                                Shipping
                                            </td>
                                            <td className="text-right">
                                                {(detail.currency ?? detail.currency_code)}{" "}
                                                {Number(
                                                    detail.shipping_cost ?? detail.shipping_amount ?? 0,
                                                ).toLocaleString()}
                                            </td>
                                        </tr>
                                    )}
                                    {(detail.tax ?? detail.tax_amount ?? 0) > 0 && (
                                        <tr className="bg-surface-50">
                                            <td colSpan={4}></td>
                                            <td className="text-right text-xs text-surface-500 font-medium">
                                                Tax
                                            </td>
                                            <td className="text-right">
                                                {(detail.currency ?? detail.currency_code)}{" "}
                                                {Number(
                                                    detail.tax ?? detail.tax_amount ?? 0,
                                                ).toLocaleString()}
                                            </td>
                                        </tr>
                                    )}
                                    <tr className="bg-brand-50">
                                        <td colSpan={4}></td>
                                        <td className="text-right font-bold text-brand-700">
                                            Total
                                        </td>
                                        <td className="text-right font-bold text-brand-700 text-base">
                                            {(detail.currency ?? detail.currency_code)}{" "}
                                            {Number(
                                                detail.total_amount,
                                            ).toLocaleString()}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                            </div>
                        </div>
                    </div>

                    {/* Payment info */}
                    <div className="flex items-center gap-3 px-4 py-3 rounded-lg bg-surface-50">
                        <div
                            className={clsx(
                                "w-2 h-2 rounded-full",
                                detail.is_paid ? "bg-success" : "bg-warning",
                            )}
                        />
                        <span className="text-sm font-medium">
                            {detail.is_paid ? "Paid" : "Unpaid"}
                        </span>
                        {detail.invoice_number && (
                            <span className="text-xs text-surface-400 ml-auto">
                                Invoice: {detail.invoice_number}
                            </span>
                        )}
                        {detail.payment_terms && (
                            <span className="badge badge-neutral">
                                {detail.payment_terms}
                            </span>
                        )}
                    </div>

                    {/* GRN history */}
                    {history.length > 0 && (
                        <div>
                            <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                                Receiving History
                            </p>
                            {history.map((grn: any) => (
                                <div
                                    key={grn.id}
                                    className="flex items-center gap-3 px-3 py-2 bg-success-light/50 rounded-lg text-sm mb-1.5"
                                >
                                    <CheckIcon className="w-4 h-4 text-success flex-shrink-0" />
                                    <span className="font-mono text-xs text-success-dark">
                                        {grn.grn_number}
                                    </span>
                                    <span className="text-surface-500 text-xs ml-auto">
                                        {new Date(
                                            grn.received_date,
                                        ).toLocaleString()}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}

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
                </div>
            ) : null}
        </Modal>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function PurchaseOrdersPage() {
    const { can } = usePermissions();
    const canCreate = can("procurement.create");
    const canApprove = can("procurement.approve");
    const [page, setPage] = useState(1);
    const table = { page, setPage };
    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [supplierFilter, setSupplierFilter] = useState("");
    const navigate = useNavigate();

    const [createOpen, setCreateOpen] = useState(false);
    const [detailPO, setDetailPO] = useState<PurchaseOrder | null>(null);
    const [statusPO, setStatusPO] = useState<PurchaseOrder | null>(null);

    const params: Record<string, string | number> = {
        page: table.page,
        per_page: 20,
        ...(search && { search }),
        ...(statusFilter && { status: statusFilter }),
        ...(supplierFilter && { supplier_id: supplierFilter }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["purchase-orders", params],
        queryFn: () => purchaseOrderApi.list(params),
    });

    const { data: suppliersData } = useQuery({
        queryKey: ["suppliers-dropdown"],
        queryFn: () => supplierApi.list({ per_page: 200, status: "active" }),
    });

    const orders = data?.data ?? [];
    const meta = data?.meta;
    const suppliers = suppliersData?.data ?? [];

    // Group the current page of rows by created_at. Pagination, sort, and
    // filters are untouched - this only re-partitions the rows already fetched.
    const orderGroups = groupRowsByDate(orders, (po) => po.created_at);

    // Summary stats
    const summary = {
        total: orders.length,
        draft: orders.filter((o) => o.status === "draft").length,
        pending: orders.filter((o) =>
            ["pending_approval", "approved", "ordered"].includes(o.status),
        ).length,
        partial: orders.filter((o) => o.status === "partially_received").length,
    };

    return (
        <div className="space-y-5">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div className="page-header mb-0">
                    <h1 className="page-title">Purchase Orders</h1>
                    <p className="page-subtitle">
                        Create and track orders from suppliers
                    </p>
                </div>
                {canCreate && (
                <button
                    className="btn-primary btn-sm whitespace-nowrap"
                    onClick={() => setCreateOpen(true)}
                >
                    <PlusIcon /> New Purchase Order
                </button>
                )}
            </div>

            {/* Summary stats */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                {[
                    {
                        label: "Total Orders",
                        value: meta?.total ?? 0,
                        color: "text-surface-900",
                        bg: "bg-surface-50",
                    },
                    {
                        label: "Awaiting Action",
                        value: summary.pending,
                        color: "text-info",
                        bg: "bg-info-light",
                    },
                    {
                        label: "Partial Receipt",
                        value: summary.partial,
                        color: "text-warning",
                        bg: "bg-warning-light",
                    },
                    {
                        label: "Drafts",
                        value: summary.draft,
                        color: "text-surface-500",
                        bg: "bg-surface-50",
                    },
                ].map((s) => (
                    <div
                        key={s.label}
                        className={clsx(
                            "rounded-xl px-4 py-3 border border-surface-100",
                            s.bg,
                        )}
                    >
                        <p className={clsx("text-2xl font-bold", s.color)}>
                            {s.value}
                        </p>
                        <p className="text-xs text-surface-500 mt-0.5">
                            {s.label}
                        </p>
                    </div>
                ))}
            </div>

            {/* Filters */}
            <div className="card">
                <div className="card-body py-3 flex flex-col sm:flex-row gap-3">
                    <div className="relative flex-1">
                        <SearchIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" />
                        <input
                            className="input pl-9"
                            placeholder="Search PO number..."
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                table.setPage(1);
                            }}
                        />
                    </div>
                    <select
                        className="input w-full sm:w-48"
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                    >
                        <option value="">All Statuses</option>
                        {Object.entries(PO_STATUSES).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v.label}
                            </option>
                        ))}
                    </select>
                    <select
                        className="input w-full sm:w-48"
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
                ) : orders.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-2">
                        <POIcon className="w-10 h-10 opacity-30" />
                        <p className="text-sm font-medium text-surface-500">
                            No purchase orders found
                        </p>
                        {canCreate && (
                        <button
                            className="btn-primary btn-sm mt-2"
                            onClick={() => setCreateOpen(true)}
                        >
                            Create your first PO
                        </button>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="table-wrapper rounded-none border-0">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th>PO Number</th>
                                        <th>Supplier</th>
                                        <th className="hidden sm:table-cell">
                                            Expected Delivery
                                        </th>
                                        <th>Status</th>
                                        <th className="hidden md:table-cell text-center">
                                            Items
                                        </th>
                                        <th className="text-right">Total</th>
                                        <th className="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orderGroups.map((group) => (
                                        <Fragment key={group.key}>
                                            <DateGroupHeaderRow label={group.label} colSpan={7} />
                                            {group.items.map((po) => (
                                        <tr
                                            key={po.id}
                                            className="cursor-pointer"
                                            onClick={() => navigate(`/procurement/purchase-orders/${po.id}`)}
                                        >
                                            <td>
                                                <span className="font-mono text-sm font-semibold text-brand-700">
                                                    {po.po_number}
                                                </span>
                                                <p className="text-2xs text-surface-400 mt-0.5">
                                                    {new Date(
                                                        po.created_at,
                                                    ).toLocaleDateString()}
                                                </p>
                                            </td>
                                            <td>
                                                <span className="font-medium text-surface-900">
                                                    {po.supplier?.name ?? "-"}
                                                </span>
                                            </td>
                                            <td className="hidden sm:table-cell">
                                                <span
                                                    className={clsx(
                                                        "text-sm",
                                                        ![
                                                            "received",
                                                            "cancelled",
                                                        ].includes(po.status) &&
                                                            new Date(
                                                                po.expected_delivery_date,
                                                            ) < new Date()
                                                            ? "text-danger font-medium"
                                                            : "text-surface-600",
                                                    )}
                                                >
                                                    {new Date(
                                                        po.expected_delivery_date,
                                                    ).toLocaleDateString()}
                                                </span>
                                            </td>
                                            <td>
                                                <StatusBadge
                                                    status={po.status}
                                                />
                                            </td>
                                            <td className="hidden md:table-cell text-center">
                                                <span className="text-sm text-surface-600">
                                                    {po.items?.length ?? 0}
                                                </span>
                                            </td>
                                            <td className="text-right">
                                                <span className="font-semibold tabular-nums text-surface-900">
                                                    {(po.currency ?? po.currency_code)}{" "}
                                                    {Number(
                                                        (po.total ?? po.total_amount),
                                                    ).toLocaleString()}
                                                </span>
                                            </td>
                                            <td
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <div className="flex items-center gap-1 justify-end">
                                                    {STATUS_TRANSITIONS[
                                                        po.status
                                                    ]?.length && canApprove ? (
                                                        <button
                                                            className="btn-ghost btn-sm"
                                                            title="Update status"
                                                            onClick={() =>
                                                                setStatusPO(po)
                                                            }
                                                        >
                                                            <ArrowIcon className="w-4 h-4" />
                                                        </button>
                                                    ) : null}
                                                    <button
                                                        className="btn-ghost btn-sm"
                                                        title="View details"
                                                        onClick={() =>
                                                            navigate(`/procurement/purchase-orders/${po.id}`)
                                                        }
                                                    >
                                                        <EyeIcon className="w-4 h-4" />
                                                    </button>
                                                </div>
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
            {createOpen && <POFormModal onClose={() => setCreateOpen(false)} />}
            {detailPO && (
                <PODetailModal
                    po={detailPO}
                    onClose={() => setDetailPO(null)}
                    onUpdateStatus={() => {
                        setStatusPO(detailPO);
                        setDetailPO(null);
                    }}
                />
            )}
            {statusPO && (
                <StatusUpdateModal
                    po={statusPO}
                    onClose={() => setStatusPO(null)}
                />
            )}
        </div>
    );
}

// ─── Icons ────────────────────────────────────────────────────────────────────
const PlusIcon = () => (
    <svg
        className="w-4 h-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={2}
    >
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
    </svg>
);
const SearchIcon = ({ className }: { className?: string }) => (
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
            d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"
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
const ArrowIcon = ({ className }: { className?: string }) => (
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
            d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"
        />
    </svg>
);
const TrashIcon = ({ className }: { className?: string }) => (
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
            d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
        />
    </svg>
);
const POIcon = ({ className }: { className?: string }) => (
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
            d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"
        />
    </svg>
);
const CheckIcon = ({ className }: { className?: string }) => (
    <svg
        className={className}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={2.5}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M4.5 12.75l6 6 9-13.5"
        />
    </svg>
);