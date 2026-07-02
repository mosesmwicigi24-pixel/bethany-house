import { useState, Fragment } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { purchaseOrderApi, supplierApi, grnApi } from "@/api/procurement";
import type { PurchaseOrder, POItem, GRN } from "@/api/procurement";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Field, useFieldAriaProps, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";
import { clsx } from "clsx";
import { get } from "@/api/client";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ─── GRN Form modal ───────────────────────────────────────────────────────────

interface GRNItemState {
    po_item_id: number;
    name: string;
    ordered: number;
    pending: number;
    quantity_received: string;
    quality_status: "passed" | "rejected";
    notes: string;
}

function GRNFormModal({
    po,
    onClose,
}: {
    po: PurchaseOrder;
    onClose: () => void;
}) {
    const qc = useQueryClient();
    const toast = useToastStore();

    // Fetch full PO details
    const { data, isLoading } = useQuery({
        queryKey: ["po-grn-detail", po.id],
        queryFn: () => purchaseOrderApi.get(po.id),
    });

    const detail = data?.purchase_order;

    const [locationType, setLocationType] = useState<"warehouse" | "outlet">(
        "warehouse",
    );
    const [outletId, setOutletId] = useState("");
    const [notes, setNotes] = useState("");
    const [itemStates, setItemStates] = useState<Record<number, GRNItemState>>(
        {},
    );

    // Initialise item states once data loads
    const initItems = (items: POItem[]) => {
        const init: Record<number, GRNItemState> = {};
        items.forEach((item) => {
            const pending = item.quantity - item.quantity_received;
            if (pending > 0) {
                init[item.id] = {
                    po_item_id: item.id,
                    name:
                        item.product?.name ??
                        item.material?.name ??
                        `Item #${item.item_id}`,
                    ordered: item.quantity,
                    pending,
                    quantity_received: String(pending),
                    quality_status: "passed",
                    notes: "",
                };
            }
        });
        return init;
    };

    const items = detail?.items ?? [];
    const pendingItems = items.filter(
        (i) => i.quantity - i.quantity_received > 0,
    );

    const states =
        Object.keys(itemStates).length === 0 && pendingItems.length > 0
            ? initItems(pendingItems)
            : itemStates;

    const setItem = (id: number, key: keyof GRNItemState, value: string) =>
        setItemStates((prev) => ({
            ...prev,
            [id]: { ...(prev[id] ?? states[id]), [key]: value },
        }));

    const { data: outletsData } = useQuery({
        queryKey: ["outlets-dropdown"],
        queryFn: () =>
            get<{ data: Array<{ id: number; name: string }> }>("/v1/admin/outlets", {
                params: { per_page: 100 },
            }),
    });
    const outlets = outletsData?.data ?? [];

    const totalReceiving = Object.values(states).reduce(
        (sum, i) => sum + (parseInt(i.quantity_received) || 0),
        0,
    );

    const mutation = useMutation({
        mutationFn: () => {
            const receiveItems = Object.values(states)
                .filter((i) => (parseInt(i.quantity_received) || 0) > 0)
                .map((i) => ({
                    po_item_id: i.po_item_id,
                    quantity_received: parseInt(i.quantity_received) || 0,
                    quality_status: i.quality_status,
                    notes: i.notes || undefined,
                }));

            return purchaseOrderApi.receive(po.id, {
                items: receiveItems,
                location_type: locationType,
                outlet_id:
                    locationType === "outlet" && outletId
                        ? parseInt(outletId)
                        : undefined,
                notes: notes || undefined,
            });
        },
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["purchase-orders"] });
            qc.invalidateQueries({ queryKey: ["purchase-order-detail"] });
            toast.success(`GRN recorded - ${res.receipt_number ?? res.receipt_number}`);
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    if (isLoading)
        return (
            <Modal open onClose={onClose} title="Record Goods Receipt">
                <div className="flex justify-center py-12">
                    <Spinner size="lg" />
                </div>
            </Modal>
        );

    return (
        <Modal
            open
            onClose={onClose}
            title={`Goods Receipt - ${po.po_number}`}
            size="xl"
            footer={
                <div className="flex gap-2 justify-end w-full">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        className="btn-primary btn-sm"
                        disabled={mutation.isPending || totalReceiving === 0}
                        onClick={() => mutation.mutate()}
                    >
                        {mutation.isPending ? (
                            <Spinner size="sm" />
                        ) : (
                            `Confirm Receipt (${totalReceiving} units)`
                        )}
                    </button>
                </div>
            }
        >
            <div className="space-y-5">
                {/* PO info banner */}
                <div className="flex flex-wrap gap-4 p-3 bg-brand-50 rounded-lg text-sm">
                    <div>
                        <span className="text-surface-500">Supplier:</span>{" "}
                        <span className="font-semibold">
                            {po.supplier?.name}
                        </span>
                    </div>
                    <div>
                        <span className="text-surface-500">PO Date:</span>{" "}
                        <span className="font-semibold">
                            {new Date(po.created_at).toLocaleDateString()}
                        </span>
                    </div>
                    <div>
                        <span className="text-surface-500">Expected:</span>{" "}
                        <span className="font-semibold">
                            {new Date(
                                po.expected_delivery_date,
                            ).toLocaleDateString()}
                        </span>
                    </div>
                </div>

                {/* Receive into location */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Field label="Receive Into *">
                        <FieldSelect
                            className="input"
                            value={locationType}
                            onChange={(e) =>
                                setLocationType(
                                    e.target.value as "warehouse" | "outlet",
                                )
                            }
                        >
                            <option value="warehouse">Main Warehouse</option>
                            <option value="outlet">Outlet</option>
                        </FieldSelect>
                    </Field>
                    {locationType === "outlet" && (
                        <Field label="Select Outlet *">
                            <FieldSelect
                                className="input"
                                value={outletId}
                                onChange={(e) => setOutletId(e.target.value)}
                            >
                                <option value="">-- Select Outlet --</option>
                                {outlets.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </FieldSelect>
                        </Field>
                    )}
                </div>

                {/* Items */}
                <div>
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                        Items to Receive ({pendingItems.length} line items
                        pending)
                    </p>
                    {pendingItems.length === 0 ? (
                        <div className="text-center py-6 text-sm text-surface-400">
                            All items have been fully received for this PO.
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {pendingItems.map((item) => {
                                const state = states[item.id] ?? {
                                    quantity_received: String(
                                        item.quantity - item.quantity_received,
                                    ),
                                    quality_status: "passed",
                                    notes: "",
                                };
                                const name =
                                    item.product?.name ??
                                    item.material?.name ??
                                    `Item #${item.item_id}`;
                                const pending =
                                    item.quantity - item.quantity_received;

                                return (
                                    <div
                                        key={item.id}
                                        className="border border-surface-200 rounded-xl p-4 space-y-3"
                                    >
                                        {/* Item header */}
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
                                                <div className="flex items-center gap-3 mt-1 text-xs text-surface-500">
                                                    <span>
                                                        Ordered:{" "}
                                                        <strong>
                                                            {item.quantity}
                                                        </strong>
                                                    </span>
                                                    <span>
                                                        Received so far:{" "}
                                                        <strong className="text-success">
                                                            {
                                                                item.quantity_received
                                                            }
                                                        </strong>
                                                    </span>
                                                    <span>
                                                        Pending:{" "}
                                                        <strong className="text-warning">
                                                            {pending}
                                                        </strong>
                                                    </span>
                                                </div>
                                            </div>
                                            <span className="badge badge-neutral capitalize flex-shrink-0">
                                                {item.item_type}
                                            </span>
                                        </div>

                                        {/* Receipt inputs */}
                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                            <Field label="Qty Receiving *">
                                                <FieldInput
                                                    type="number"
                                                    min="0"
                                                    max={pending}
                                                    className="input"
                                                    value={
                                                        state.quantity_received
                                                    }
                                                    onChange={(e) =>
                                                        setItem(
                                                            item.id,
                                                            "quantity_received",
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                <p className="text-2xs text-surface-400 mt-1">
                                                    Max: {pending} units
                                                </p>
                                            </Field>
                                            <Field label="Quality Check">
                                                <div className="flex gap-2 mt-1">
                                                    {(
                                                        [
                                                            "passed",
                                                            "rejected",
                                                        ] as const
                                                    ).map((v) => (
                                                        <button
                                                            key={v}
                                                            type="button"
                                                            onClick={() =>
                                                                setItem(
                                                                    item.id,
                                                                    "quality_status",
                                                                    v,
                                                                )
                                                            }
                                                            className={clsx(
                                                                "flex-1 py-2 rounded-lg text-xs font-semibold border transition-all",
                                                                state.quality_status ===
                                                                    v
                                                                    ? v ===
                                                                      "passed"
                                                                        ? "bg-success-light border-success text-success"
                                                                        : "bg-danger-light border-danger text-danger"
                                                                    : "border-surface-200 text-surface-500 hover:bg-surface-50",
                                                            )}
                                                        >
                                                            {v === "passed"
                                                                ? "✓ Passed"
                                                                : "✗ Rejected"}
                                                        </button>
                                                    ))}
                                                </div>
                                            </Field>
                                            <Field label="Notes (optional)">
                                                <FieldInput
                                                    className="input"
                                                    placeholder="e.g. 2 units damaged"
                                                    value={state.notes}
                                                    onChange={(e) =>
                                                        setItem(
                                                            item.id,
                                                            "notes",
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                        </div>

                                        {/* Quality warning */}
                                        {state.quality_status ===
                                            "rejected" && (
                                            <div className="flex items-center gap-2 px-3 py-2 bg-danger-light rounded-lg text-xs text-danger">
                                                <WarningIcon className="w-4 h-4 flex-shrink-0" />
                                                Rejected items will NOT be added
                                                to inventory. A purchase return
                                                can be raised separately.
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* Batch notes */}
                <Field label="Delivery Notes (optional)">
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="e.g. Delivery was late, packaging damaged, driver ref..."
                    />
                </Field>

                {/* Receipt summary */}
                {totalReceiving > 0 && (
                    <div className="flex items-center gap-3 px-4 py-3 bg-success-light rounded-lg">
                        <CheckIcon className="w-5 h-5 text-success flex-shrink-0" />
                        <div className="text-sm">
                            <span className="font-semibold text-success">
                                {totalReceiving} units
                            </span>
                            <span className="text-success-dark">
                                {" "}
                                will be added to{" "}
                                {locationType === "warehouse"
                                    ? "main warehouse"
                                    : "selected outlet"}{" "}
                                inventory
                            </span>
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}

// ─── GRN History table ────────────────────────────────────────────────────────

function GRNHistoryTab() {
    const navigate = useNavigate();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState("");

    const params: Record<string, string | number> = {
        page,
        per_page: 20,
        ...(search && { search }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["grn-history", params],
        queryFn: () => grnApi.list(params as any),
        staleTime: 30_000,
    });

    const grns: GRN[] = (data as any)?.data ?? [];
    const meta = (data as any)?.meta;

    // Group the current page of rows by received_date. Pagination, sort, and
    // search are untouched - this only re-partitions the rows already fetched.
    const grnGroups = groupRowsByDate(grns, (grn: any) => grn.received_date);

    const fmtDate = (d: string | null | undefined) => {
        if (!d) return "-";
        // received_date comes as "YYYY-MM-DD" - parse explicitly to avoid
        // timezone-offset turning it into the previous day
        const [y, m, day] = d.split("-").map(Number);
        return new Date(y, m - 1, day).toLocaleDateString("en-KE", {
            day: "2-digit",
            month: "short",
            year: "numeric",
        });
    };

    return (
        <div className="space-y-4">
            {/* Search */}
            <div className="card">
                <div className="card-body py-3 flex gap-3 items-center">
                    <div className="relative flex-1 max-w-sm">
                        <SearchIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                            placeholder="Search GRN or PO number…"
                            className="input pl-9"
                        />
                    </div>
                    <span className="text-xs text-surface-400 ml-auto">
                        {meta?.total ?? 0} receipts
                    </span>
                </div>
            </div>

            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex items-center justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                ) : grns.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-2">
                        <ReceiptIcon className="w-10 h-10 opacity-30" />
                        <p className="text-sm font-medium text-surface-500">No receipts yet</p>
                        <p className="text-xs">GRNs will appear here once you record deliveries</p>
                    </div>
                ) : (
                    <>
                        {/* Mobile cards */}
                        <div className="sm:hidden divide-y divide-surface-100">
                            {grns.map((grn: any) => (
                                <div
                                    key={grn.id}
                                    className="p-4 flex items-center justify-between gap-3 cursor-pointer hover:bg-surface-50 transition-colors"
                                    onClick={() => navigate(`/procurement/goods-receipt/${grn.id}`)}
                                >
                                    <div>
                                        <p className="font-mono font-semibold text-brand-700">
                                            {grn.grn_number}
                                        </p>
                                        <p className="text-xs text-surface-500 mt-0.5">
                                            {grn.po_number ?? `PO #${grn.purchase_order_id}`}
                                            {grn.supplier_name ? ` · ${grn.supplier_name}` : ""}
                                        </p>
                                        <p className="text-xs text-surface-400">
                                            {fmtDate(grn.received_date)}
                                            {grn.received_by?.name ? ` · ${grn.received_by.name}` : ""}
                                        </p>
                                    </div>
                                    <svg className="w-4 h-4 text-surface-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                    </svg>
                                </div>
                            ))}
                        </div>

                        {/* Desktop table */}
                        <div className="hidden sm:block table-wrapper rounded-none border-0">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th>GRN Number</th>
                                        <th>Purchase Order</th>
                                        <th>Supplier</th>
                                        <th>Received By</th>
                                        <th>Date Received</th>
                                        <th>Location</th>
                                        <th className="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {grnGroups.map((group) => (
                                        <Fragment key={group.key}>
                                            <DateGroupHeaderRow label={group.label} colSpan={7} />
                                            {group.items.map((grn: any) => (
                                    <tr
                                        key={grn.id}
                                        className="cursor-pointer hover:bg-surface-50/70 transition-colors"
                                        onClick={() => navigate(`/procurement/goods-receipt/${grn.id}`)}
                                    >
                                        <td>
                                            <span className="font-mono font-semibold text-brand-700">
                                                {grn.grn_number}
                                            </span>
                                        </td>
                                        <td>
                                            <span
                                                className="font-mono text-surface-700 hover:text-brand-600 hover:underline cursor-pointer"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    navigate(`/procurement/purchase-orders/${grn.purchase_order_id}`);
                                                }}
                                            >
                                                {grn.po_number ?? `PO #${grn.purchase_order_id}`}
                                            </span>
                                        </td>
                                        <td className="text-surface-700">
                                            {grn.supplier_name ?? "-"}
                                        </td>
                                        <td className="text-surface-600">
                                            {grn.received_by?.name ?? "-"}
                                        </td>
                                        <td className="text-surface-600">
                                            {fmtDate(grn.received_date)}
                                        </td>
                                        <td>
                                            {grn.location_type === "outlet" ? (
                                                <span className="badge badge-info">
                                                    📍 {grn.outlet_name ?? "Outlet"}
                                                </span>
                                            ) : (
                                                <span className="badge badge-neutral">
                                                    🏭 Warehouse
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className="text-right"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <button
                                                className="btn-ghost btn-sm"
                                                title="View GRN detail"
                                                onClick={() => navigate(`/procurement/goods-receipt/${grn.id}`)}
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
                                    Showing {meta.from}–{meta.to} of {meta.total}
                                </p>
                                <div className="flex gap-1">
                                    <button
                                        disabled={meta.current_page === 1}
                                        onClick={() => setPage((p) => p - 1)}
                                        className="btn-ghost btn-sm text-xs disabled:opacity-40"
                                    >
                                        ← Prev
                                    </button>
                                    <button
                                        disabled={meta.current_page === meta.last_page}
                                        onClick={() => setPage((p) => p + 1)}
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
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function GoodsReceiptPage() {
    const navigate = useNavigate();
    const { can } = usePermissions();
    const canReceive = can("procurement.receive");
    const [tab, setTab] = useState<"pending" | "history">("pending");
    const [page, setPage] = useState(1);
    const table = { page, setPage };
    const [supplierFilter, setSupplierFilter] = useState("");
    const [selectedPO, setSelectedPO] = useState<PurchaseOrder | null>(null);

    const params: Record<string, string | number> = {
        page: table.page,
        per_page: 20,
        status: supplierFilter ? "all" : "receivable",
        ...(supplierFilter && { supplier_id: supplierFilter }),
    };

    // Fetch POs that can be received
    const { data, isLoading } = useQuery({
        queryKey: ["purchase-orders-receivable", params],
        queryFn: () =>
            purchaseOrderApi.list({
                ...params,
                status: "ordered,partially_received",
            }),
    });

    const { data: suppliersData } = useQuery({
        queryKey: ["suppliers-dropdown"],
        queryFn: () => supplierApi.list({ per_page: 200, status: "active" }),
    });

    const orders = data?.data ?? [];
    const meta = data?.meta;
    const suppliers = suppliersData?.data ?? [];

    return (
        <div className="space-y-5">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="page-header mb-0">
                    <h1 className="page-title">Goods Receipt (GRN)</h1>
                    <p className="page-subtitle">
                        Record deliveries against open purchase orders and view past receipts
                    </p>
                </div>
            </div>

            {/* Tabs */}
            <div className="flex border-b border-surface-200 gap-1 overflow-x-auto no-scrollbar">
                <button
                    onClick={() => setTab("pending")}
                    className={clsx(
                        "px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-all",
                        tab === "pending"
                            ? "border-brand-500 text-brand-600"
                            : "border-transparent text-surface-400 hover:text-surface-700",
                    )}
                >
                    📦 Pending Delivery
                    {meta?.total ? (
                        <span className="ml-2 px-1.5 py-0.5 rounded-full text-2xs bg-brand-100 text-brand-700 tabular-nums">
                            {meta.total}
                        </span>
                    ) : null}
                </button>
                <button
                    onClick={() => setTab("history")}
                    className={clsx(
                        "px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition-all",
                        tab === "history"
                            ? "border-brand-500 text-brand-600"
                            : "border-transparent text-surface-400 hover:text-surface-700",
                    )}
                >
                    🗂 Past Receipts
                </button>
            </div>

            {/* ── Pending tab ── */}
            {tab === "pending" && (
                <>
                    {/* Info banner */}
                    <div className="flex items-start gap-3 px-4 py-3 bg-brand-50 border border-brand-100 rounded-xl text-sm">
                        <InfoIcon className="w-5 h-5 text-brand-500 flex-shrink-0 mt-0.5" />
                        <div className="text-brand-800">
                            Showing purchase orders with status <strong>Ordered</strong>{" "}
                            or <strong>Partially Received</strong>. Select a PO to
                            record a delivery - stock levels update automatically on
                            confirmation.
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="card">
                        <div className="card-body py-3 flex flex-col sm:flex-row gap-3">
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
                            <div className="flex items-center gap-1 ml-auto text-xs text-surface-400">
                                <span>{meta?.total ?? 0} orders awaiting receipt</span>
                            </div>
                        </div>
                    </div>

                    {/* PO cards / table */}
                    <div className="card overflow-hidden">
                        {isLoading ? (
                            <div className="flex items-center justify-center py-16">
                                <Spinner size="lg" />
                            </div>
                        ) : orders.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-2">
                                <CheckCircleIcon className="w-10 h-10 opacity-30" />
                                <p className="text-sm font-medium text-surface-500">
                                    No pending deliveries
                                </p>
                                <p className="text-xs text-surface-400">
                                    All ordered POs have been fully received
                                </p>
                            </div>
                        ) : (
                            <>
                                {/* Mobile: cards */}
                                <div className="sm:hidden divide-y divide-surface-100">
                                    {orders.map((po) => {
                                        const totalItems = po.items?.length ?? 0;
                                        const receivedItems =
                                            po.items?.filter(
                                                (i) =>
                                                    i.quantity_received >= i.quantity,
                                            ).length ?? 0;
                                        return (
                                            <div key={po.id} className="p-4 space-y-3">
                                                <div className="flex items-start justify-between">
                                                    <div>
                                                        <p className="font-mono font-semibold text-brand-700">
                                                            {po.po_number}
                                                        </p>
                                                        <p className="text-sm text-surface-600 mt-0.5">
                                                            {po.supplier?.name}
                                                        </p>
                                                    </div>
                                                    <span
                                                        className={clsx(
                                                            "badge",
                                                            po.status ===
                                                                "partially_received"
                                                                ? "badge-warning"
                                                                : "badge-info",
                                                        )}
                                                    >
                                                        {po.status ===
                                                        "partially_received"
                                                            ? "Partial"
                                                            : "Ordered"}
                                                    </span>
                                                </div>
                                                <div className="flex items-center justify-between text-xs text-surface-500">
                                                    <span>
                                                        Expected:{" "}
                                                        {new Date(
                                                            po.expected_delivery_date,
                                                        ).toLocaleDateString()}
                                                    </span>
                                                    <span>
                                                        {receivedItems}/{totalItems}{" "}
                                                        lines received
                                                    </span>
                                                </div>
                                                <div className="w-full h-1.5 bg-surface-100 rounded-full overflow-hidden">
                                                    <div
                                                        className="h-full bg-brand-400 rounded-full"
                                                        style={{
                                                            width: `${totalItems > 0 ? (receivedItems / totalItems) * 100 : 0}%`,
                                                        }}
                                                    />
                                                </div>
                                                <div className="flex gap-2">
                                                    <button
                                                        className="btn-secondary btn-sm flex-1"
                                                        onClick={() => navigate(`/procurement/purchase-orders/${po.id}`)}
                                                    >
                                                        View PO
                                                    </button>
                                                    {canReceive && (
                                                    <button
                                                        className="btn-primary btn-sm flex-1"
                                                        onClick={() => setSelectedPO(po)}
                                                    >
                                                        Record Receipt
                                                    </button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {/* Desktop: table */}
                                <div className="hidden sm:block table-wrapper rounded-none border-0">
                                    <table className="table">
                                        <thead>
                                            <tr>
                                                <th>PO Number</th>
                                                <th>Supplier</th>
                                                <th>Expected Delivery</th>
                                                <th>Status</th>
                                                <th>Progress</th>
                                                <th className="text-right">Value</th>
                                                <th className="text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {orders.map((po) => {
                                                const totalQty =
                                                    po.items?.reduce(
                                                        (sum, i) => sum + i.quantity,
                                                        0,
                                                    ) ?? 0;
                                                const receivedQty =
                                                    po.items?.reduce(
                                                        (sum, i) =>
                                                            sum + i.quantity_received,
                                                        0,
                                                    ) ?? 0;
                                                const pct =
                                                    totalQty > 0
                                                        ? (receivedQty / totalQty) * 100
                                                        : 0;
                                                const isOverdue =
                                                    new Date(
                                                        po.expected_delivery_date,
                                                    ) < new Date();

                                                return (
                                                    <tr
                                                        key={po.id}
                                                        className="cursor-pointer hover:bg-surface-50/70 transition-colors"
                                                        onClick={() => navigate(`/procurement/purchase-orders/${po.id}`)}
                                                    >
                                                        <td>
                                                            <span className="font-mono font-semibold text-brand-700">
                                                                {po.po_number}
                                                            </span>
                                                            <p className="text-xs text-surface-400">
                                                                {new Date(
                                                                    po.created_at,
                                                                ).toLocaleDateString()}
                                                            </p>
                                                        </td>
                                                        <td className="font-medium text-surface-900">
                                                            {po.supplier?.name}
                                                        </td>
                                                        <td>
                                                            <span
                                                                className={clsx(
                                                                    "text-sm",
                                                                    isOverdue
                                                                        ? "text-danger font-semibold"
                                                                        : "text-surface-600",
                                                                )}
                                                            >
                                                                {new Date(
                                                                    po.expected_delivery_date,
                                                                ).toLocaleDateString()}
                                                                {isOverdue && (
                                                                    <span className="ml-1 badge badge-danger">
                                                                        Overdue
                                                                    </span>
                                                                )}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span
                                                                className={clsx(
                                                                    "badge",
                                                                    po.status ===
                                                                        "partially_received"
                                                                        ? "badge-warning"
                                                                        : "badge-info",
                                                                )}
                                                            >
                                                                {po.status ===
                                                                "partially_received"
                                                                    ? "Partially Received"
                                                                    : "Ordered"}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div className="flex items-center gap-2">
                                                                <div className="w-20 h-1.5 bg-surface-100 rounded-full overflow-hidden">
                                                                    <div
                                                                        className="h-full bg-brand-500 rounded-full"
                                                                        style={{
                                                                            width: `${pct}%`,
                                                                        }}
                                                                    />
                                                                </div>
                                                                <span className="text-xs text-surface-500 tabular-nums">
                                                                    {Math.round(pct)}%
                                                                </span>
                                                            </div>
                                                            <p className="text-xs text-surface-400 mt-0.5">
                                                                {receivedQty}/{totalQty}{" "}
                                                                units
                                                            </p>
                                                        </td>
                                                        <td className="text-right font-semibold tabular-nums">
                                                            {po.currency ?? po.currency_code}{" "}
                                                            {Number(
                                                                po.total ?? po.total_amount,
                                                            ).toLocaleString()}
                                                        </td>
                                                        <td
                                                            className="text-center"
                                                            onClick={(e) => e.stopPropagation()}
                                                        >
                                                            {canReceive && (
                                                            <button
                                                                className="btn-primary btn-sm"
                                                                onClick={() =>
                                                                    setSelectedPO(po)
                                                                }
                                                            >
                                                                Receive
                                                            </button>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
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
                </>
            )}

            {/* ── History tab ── */}
            {tab === "history" && <GRNHistoryTab />}

            {selectedPO && (
                <GRNFormModal
                    po={selectedPO}
                    onClose={() => setSelectedPO(null)}
                />
            )}
        </div>
    );
}

// ─── Icons ────────────────────────────────────────────────────────────────────
const SearchIcon = ({ className }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
    </svg>
);
const EyeIcon = ({ className }: { className?: string }) => (
    <svg className={className ?? "w-4 h-4"} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    </svg>
);
const ReceiptIcon = ({ className }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
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
const CheckCircleIcon = ({ className }: { className?: string }) => (
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
            d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
        />
    </svg>
);
const WarningIcon = ({ className }: { className?: string }) => (
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
            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
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