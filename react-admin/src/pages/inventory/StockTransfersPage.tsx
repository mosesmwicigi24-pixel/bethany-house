import { useState, useCallback, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { transfersApi } from "@/api/transfers";
import type { Transfer, TransferItem } from "@/api/transfers";
import { stockApi } from "@/api/stock";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import { Field, useFieldAriaProps, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import { get } from "@/api/client";
import type { ApiError } from "@/types";
import { clsx } from "clsx";
import { Fragment } from "react";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

const STATUS_CONFIG = {
    pending: {
        label: "Pending",
        bg: "bg-warning-light",
        text: "text-warning",
        step: 1,
    },
    approved: {
        label: "Approved",
        bg: "bg-brand-50",
        text: "text-brand-600",
        step: 2,
    },
    in_transit: {
        label: "In Transit",
        bg: "bg-purple-50",
        text: "text-purple-600",
        step: 3,
    },
    completed: {
        label: "Completed",
        bg: "bg-success-light",
        text: "text-success",
        step: 4,
    },
    cancelled: {
        label: "Cancelled",
        bg: "bg-surface-100",
        text: "text-surface-400",
        step: 0,
    },
} as const;

function TransferProgress({ status }: { status: string }) {
    const steps = ["Pending", "Approved", "In Transit", "Completed"];
    const current =
        STATUS_CONFIG[status as keyof typeof STATUS_CONFIG]?.step ?? 0;
    if (status === "cancelled")
        return (
            <div className="flex items-center gap-1.5 text-xs text-surface-400">
                <span className="w-4 h-4 rounded-full bg-surface-200 flex items-center justify-center text-2xs">
                    ✕
                </span>
                Cancelled
            </div>
        );
    return (
        <div className="flex items-center gap-0">
            {steps.map((step, i) => {
                const stepNum = i + 1;
                const done = stepNum < current;
                const active = stepNum === current;
                return (
                    <div key={step} className="flex items-center">
                        <div
                            className={clsx(
                                "flex items-center gap-1",
                                stepNum <= current ? "" : "opacity-30",
                            )}
                        >
                            <div
                                className={clsx(
                                    "w-5 h-5 rounded-full flex items-center justify-center text-2xs font-bold",
                                    done
                                        ? "bg-success text-white"
                                        : active
                                          ? "bg-brand-500 text-white"
                                          : "bg-surface-200 text-surface-500",
                                )}
                            >
                                {done ? "✓" : stepNum}
                            </div>
                            <span
                                className={clsx(
                                    "text-2xs hidden lg:block",
                                    active
                                        ? "text-brand-600 font-medium"
                                        : "text-surface-400",
                                )}
                            >
                                {step}
                            </span>
                        </div>
                        {i < steps.length - 1 && (
                            <div
                                className={clsx(
                                    "w-5 h-px mx-1",
                                    stepNum < current
                                        ? "bg-success"
                                        : "bg-surface-200",
                                )}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function NewTransferModal({
    open,
    onClose,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [fromOutletId, setFromOutletId] = useState<number | "">("");
    const [toOutletId, setToOutletId] = useState<number | "">("");
    const [notes, setNotes] = useState("");
    const [items, setItems] = useState<any[]>([]);
    const [stockSearch, setStockSearch] = useState("");

    const { data: outletsData } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => get<any>("/v1/admin/outlets"),
        enabled: open,
    });
    const outlets = Array.isArray(outletsData)
        ? outletsData
        : (outletsData?.data ?? []);

    const { data: stockData, isFetching } = useQuery({
        queryKey: ["stock-search-tf", stockSearch, fromOutletId],
        queryFn: () =>
            stockApi.list({
                search: stockSearch,
                outlet_id: String(fromOutletId),
                per_page: "20",
            }),
        enabled: stockSearch.length >= 2 && !!fromOutletId,
    });
    const stockResults = stockData?.data ?? [];

    const addItem = (item: any) => {
        if (
            items.some(
                (i) =>
                    i.product_id === item.product_id &&
                    i.product_variant_id === item.product_variant_id,
            )
        ) {
            toast.error("Already in list.");
            return;
        }
        setItems((prev) => [
            ...prev,
            {
                product_id: item.product_id,
                product_variant_id: item.product_variant_id,
                quantity_requested: 1,
                name: item.product?.name ?? item.product?.sku,
                sku: item.product?.sku,
                variant_name: item.variant?.variant_name,
                available: item.quantity_available,
            },
        ]);
        setStockSearch("");
    };

    const mutation = useMutation({
        mutationFn: () =>
            transfersApi.create({
                from_outlet_id: Number(fromOutletId),
                to_outlet_id: Number(toOutletId),
                notes: notes || undefined,
                items: items.map((i) => ({
                    product_id: i.product_id,
                    product_variant_id: i.product_variant_id,
                    quantity_requested: i.quantity_requested,
                })),
            }),
        onSuccess: (res) => {
            toast.success(res.message);
            qc.invalidateQueries({ queryKey: ["transfers"] });
            onSaved();
            onClose();
            setFromOutletId("");
            setToOutletId("");
            setNotes("");
            setItems([]);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="New Stock Transfer"
            size="xl"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={
                            !fromOutletId ||
                            !toOutletId ||
                            items.length === 0 ||
                            mutation.isPending
                        }
                        className="btn-primary btn-sm"
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        Create Transfer Request
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field label="From Outlet" required>
                        <FieldSelect
                            className="input"
                            value={fromOutletId}
                            onChange={(e) => {
                                setFromOutletId(Number(e.target.value));
                                setItems([]);
                            }}
                        >
                            <option value="">- Source -</option>
                            {outlets.map((o: any) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>
                    <Field label="To Outlet" required>
                        <FieldSelect
                            className="input"
                            value={toOutletId}
                            onChange={(e) =>
                                setToOutletId(Number(e.target.value))
                            }
                        >
                            <option value="">- Destination -</option>
                            {outlets
                                .filter(
                                    (o: any) => o.id !== Number(fromOutletId),
                                )
                                .map((o: any) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                        </FieldSelect>
                    </Field>
                </div>

                {fromOutletId && (
                    <>
                        {items.length > 0 && (
                            <div className="border border-surface-100 rounded-xl overflow-hidden">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-surface-50 border-b border-surface-100 text-xs text-surface-400 uppercase">
                                            <th className="px-3 py-2 text-left">
                                                Product
                                            </th>
                                            <th className="px-3 py-2 text-right w-24">
                                                Available
                                            </th>
                                            <th className="px-3 py-2 text-center w-28">
                                                Request Qty
                                            </th>
                                            <th className="px-3 py-2 w-8" />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-surface-50">
                                        {items.map((item, i) => (
                                            <tr key={i}>
                                                <td className="px-3 py-2.5">
                                                    <p className="font-medium text-surface-800">
                                                        {item.name}
                                                    </p>
                                                    <p className="text-xs text-surface-400 font-mono">
                                                        {item.sku}
                                                        {item.variant_name
                                                            ? ` · ${item.variant_name}`
                                                            : ""}
                                                    </p>
                                                </td>
                                                <td className="px-3 py-2.5 text-right">
                                                    <span
                                                        className={clsx(
                                                            "text-sm font-semibold tabular-nums",
                                                            item.available === 0
                                                                ? "text-danger"
                                                                : "text-success",
                                                        )}
                                                    >
                                                        {item.available}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max={item.available}
                                                        className="input text-sm py-1 text-center w-full"
                                                        value={
                                                            item.quantity_requested
                                                        }
                                                        onChange={(e) =>
                                                            setItems((prev) =>
                                                                prev.map(
                                                                    (x, j) =>
                                                                        j === i
                                                                            ? {
                                                                                  ...x,
                                                                                  quantity_requested:
                                                                                      parseInt(
                                                                                          e
                                                                                              .target
                                                                                              .value,
                                                                                      ) ||
                                                                                      1,
                                                                              }
                                                                            : x,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <button
                                                        onClick={() =>
                                                            setItems((prev) =>
                                                                prev.filter(
                                                                    (_, j) =>
                                                                        j !== i,
                                                                ),
                                                            )
                                                        }
                                                        className="text-surface-300 hover:text-danger"
                                                        aria-label="Close"
                                                    >
                                                        <svg
                                                            className="w-4 h-4"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            strokeWidth={2}
                                                        >
                                                            <path d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <div className="relative">
                            <input
                                className="input text-sm"
                                placeholder="Add product - type to search stock at source…"
                                value={stockSearch}
                                onChange={(e) => setStockSearch(e.target.value)}
                            />
                            {stockSearch.length >= 2 && (
                                <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-surface-200 rounded-xl shadow-lg max-h-48 overflow-y-auto">
                                    {isFetching ? (
                                        <div className="flex justify-center py-4">
                                            <Spinner size="sm" />
                                        </div>
                                    ) : stockResults.length === 0 ? (
                                        <p className="text-sm text-surface-400 text-center py-4">
                                            No stock found at this outlet.
                                        </p>
                                    ) : (
                                        stockResults.map((item: any) => (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onMouseDown={() =>
                                                    addItem(item)
                                                }
                                                className="w-full flex items-center gap-3 px-3 py-2.5 hover:bg-surface-50 text-left border-b border-surface-50 last:border-0"
                                            >
                                                <div className="flex-1 min-w-0">
                                                    <p className="text-sm font-medium text-surface-900 truncate">
                                                        {item.product?.name}
                                                    </p>
                                                    <p className="text-xs text-surface-400">
                                                        {item.product?.sku}
                                                        {item.variant
                                                            ? ` · ${item.variant.variant_name}`
                                                            : ""}
                                                    </p>
                                                </div>
                                                <span
                                                    className={clsx(
                                                        "text-sm font-bold tabular-nums shrink-0",
                                                        item.quantity_available ===
                                                            0
                                                            ? "text-danger"
                                                            : "text-success",
                                                    )}
                                                >
                                                    {item.quantity_available}{" "}
                                                    avail
                                                </span>
                                            </button>
                                        ))
                                    )}
                                </div>
                            )}
                        </div>
                    </>
                )}

                <Field label="Notes">
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Optional…"
                    />
                </Field>
            </div>
        </Modal>
    );
}

function TransferDetailModal({
    id,
    onClose,
}: {
    id: number;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const { can } = usePermissions();
    const canTransfer = can("inventory.transfer");
    const canApproveTransfer = can("inventory.approve");
    const [dispatchQtys, setDispatchQtys] = useState<Record<number, number>>(
        {},
    );
    const [cancelReason, setCancelReason] = useState("");
    const [showCancel, setShowCancel] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ["transfer", id],
        queryFn: () => transfersApi.get(id),
    });
    const transfer = data?.transfer;

    // Init dispatch quantities when transfer data loads - can't use onSuccess in TanStack Query v5
    useEffect(() => {
        if (transfer?.items && Object.keys(dispatchQtys).length === 0) {
            const init: Record<number, number> = {};
            transfer.items.forEach((i: TransferItem) => {
                init[i.id] = i.quantity_requested;
            });
            setDispatchQtys(init);
        }
    }, [transfer?.items]);

    const invalidate = () => {
        qc.invalidateQueries({ queryKey: ["transfer", id] });
        qc.invalidateQueries({ queryKey: ["transfers"] });
        qc.invalidateQueries({ queryKey: ["stock-levels"] });
    };

    const approveMutation = useMutation({
        mutationFn: () => transfersApi.approve(id),
        onSuccess: (r) => {
            toast.success(r.message);
            invalidate();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const dispatchMutation = useMutation({
        mutationFn: () =>
            transfersApi.dispatch(
                id,
                Object.entries(dispatchQtys).map(([k, v]) => ({
                    id: Number(k),
                    quantity_received: v,
                })),
            ),
        onSuccess: (r) => {
            toast.success(r.message);
            invalidate();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const receiveMutation = useMutation({
        mutationFn: () => transfersApi.receive(id),
        onSuccess: (r) => {
            toast.success(r.message);
            invalidate();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const cancelMutation = useMutation({
        mutationFn: () => transfersApi.cancel(id, cancelReason || undefined),
        onSuccess: (r) => {
            toast.success(r.message);
            invalidate();
            onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading || !transfer)
        return (
            <Modal
                open
                onClose={onClose}
                title="Transfer Details"
                size="xl"
                footer={
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Close
                    </button>
                }
            >
                <div className="flex justify-center py-12">
                    <Spinner size="lg" />
                </div>
            </Modal>
        );

    const status = STATUS_CONFIG[transfer.status as keyof typeof STATUS_CONFIG];

    return (
        <Modal
            open
            onClose={onClose}
            title={`Transfer ${transfer.transfer_number}`}
            size="xl"
            footer={
                <div className="flex items-center justify-between w-full">
                    <div>
                        {["pending", "approved"].includes(transfer.status) &&
                            !showCancel &&
                            canTransfer && (
                                <button
                                    onClick={() => setShowCancel(true)}
                                    className="btn-ghost btn-sm text-danger text-xs"
                                >
                                    ✕ Cancel
                                </button>
                            )}
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={onClose}
                            className="btn-secondary btn-sm"
                        >
                            Close
                        </button>
                        <button
                            onClick={() => { onClose(); window.location.href = `/inventory/transfers/${transfer.id}`; }}
                            className="btn-ghost btn-sm text-brand-600 text-xs"
                        >
                            Full Detail ↗
                        </button>
                        {transfer.status === "pending" && canApproveTransfer && (
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
                                ✓ Approve
                            </button>
                        )}
                        {transfer.status === "approved" && canTransfer && (
                            <button
                                onClick={() => dispatchMutation.mutate()}
                                disabled={dispatchMutation.isPending}
                                className="btn-primary btn-sm"
                            >
                                {dispatchMutation.isPending && (
                                    <Spinner
                                        size="xs"
                                        className="border-white/30 border-t-white"
                                    />
                                )}
                                ↗ Dispatch
                            </button>
                        )}
                        {transfer.status === "in_transit" && canTransfer && (
                            <button
                                onClick={() => receiveMutation.mutate()}
                                disabled={receiveMutation.isPending}
                                className="btn-primary btn-sm"
                            >
                                {receiveMutation.isPending && (
                                    <Spinner
                                        size="xs"
                                        className="border-white/30 border-t-white"
                                    />
                                )}
                                ✓ Received
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <div className="space-y-4">
                <div className="flex items-center justify-between flex-wrap gap-3">
                    <TransferProgress status={transfer.status} />
                    <span
                        className={clsx(
                            "text-xs font-medium px-2.5 py-1 rounded-full",
                            status.bg,
                            status.text,
                        )}
                    >
                        {status.label}
                    </span>
                </div>

                <div className="grid grid-cols-3 gap-3 text-center text-sm">
                    <div className="bg-surface-50 rounded-xl p-3">
                        <p className="text-2xs text-surface-400 uppercase tracking-wider mb-1">
                            From
                        </p>
                        <p className="font-semibold text-surface-800">
                            {transfer.from_outlet?.name}
                        </p>
                    </div>
                    <div className="flex items-center justify-center text-surface-300">
                        <svg
                            className="w-6 h-6"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1.5}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"
                            />
                        </svg>
                    </div>
                    <div className="bg-surface-50 rounded-xl p-3">
                        <p className="text-2xs text-surface-400 uppercase tracking-wider mb-1">
                            To
                        </p>
                        <p className="font-semibold text-surface-800">
                            {transfer.to_outlet?.name}
                        </p>
                    </div>
                </div>

                <div className="border border-surface-100 rounded-xl overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-surface-50 border-b border-surface-100 text-xs text-surface-500 uppercase">
                                <th className="px-3 py-2.5 text-left font-semibold">
                                    Product
                                </th>
                                <th className="px-3 py-2.5 text-right font-semibold">
                                    Requested
                                </th>
                                {transfer.status === "approved" && (
                                    <th className="px-3 py-2.5 text-center font-semibold">
                                        Dispatch Qty
                                    </th>
                                )}
                                {["in_transit", "completed"].includes(
                                    transfer.status,
                                ) && (
                                    <th className="px-3 py-2.5 text-right font-semibold">
                                        Sent
                                    </th>
                                )}
                                {!["cancelled", "completed"].includes(
                                    transfer.status,
                                ) && (
                                    <th className="px-3 py-2.5 text-right font-semibold">
                                        Available
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {transfer.items.map((item) => (
                                <tr
                                    key={item.id}
                                    className="hover:bg-surface-50/50"
                                >
                                    <td className="px-3 py-2.5">
                                        <div className="flex items-center gap-2">
                                            {item.product?.image_url && (
                                                <img
                                                    src={item.product.image_url}
                                                    className="w-7 h-7 rounded object-cover shrink-0"
                                                    alt=""
                                                />
                                            )}
                                            <div>
                                                <p className="font-medium text-surface-900 text-sm">
                                                    {item.product?.name}
                                                </p>
                                                <p className="text-xs text-surface-400 font-mono">
                                                    {item.product?.sku}
                                                    {item.variant
                                                        ? ` · ${item.variant.variant_name}`
                                                        : ""}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="px-3 py-2.5 text-right font-semibold tabular-nums">
                                        {item.quantity_requested}
                                    </td>
                                    {transfer.status === "approved" && (
                                        <td className="px-3 py-2.5 text-center">
                                            <input
                                                type="number"
                                                min="0"
                                                max={item.quantity_requested}
                                                className="input text-sm py-1 w-20 text-center mx-auto"
                                                value={
                                                    dispatchQtys[item.id] ??
                                                    item.quantity_requested
                                                }
                                                onChange={(e) =>
                                                    setDispatchQtys((prev) => ({
                                                        ...prev,
                                                        [item.id]:
                                                            parseInt(
                                                                e.target.value,
                                                            ) || 0,
                                                    }))
                                                }
                                            />
                                        </td>
                                    )}
                                    {["in_transit", "completed"].includes(
                                        transfer.status,
                                    ) && (
                                        <td className="px-3 py-2.5 text-right text-brand-600 font-semibold tabular-nums">
                                            {item.quantity_received}
                                        </td>
                                    )}
                                    {!["cancelled", "completed"].includes(
                                        transfer.status,
                                    ) && (
                                        <td className="px-3 py-2.5 text-right">
                                            <span
                                                className={clsx(
                                                    "tabular-nums text-sm",
                                                    (item.source_stock ?? 0) <
                                                        item.quantity_requested
                                                        ? "text-danger font-semibold"
                                                        : "text-success",
                                                )}
                                            >
                                                {item.source_stock ?? "-"}
                                            </span>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="grid grid-cols-3 gap-3 text-xs text-surface-500">
                    {transfer.requested_by && (
                        <div>
                            <p className="font-semibold text-surface-600 mb-0.5">
                                Requested by
                            </p>
                            <p>{transfer.requested_by.name}</p>
                        </div>
                    )}
                    {transfer.approved_by && (
                        <div>
                            <p className="font-semibold text-surface-600 mb-0.5">
                                Approved by
                            </p>
                            <p>{transfer.approved_by.name}</p>
                        </div>
                    )}
                    {transfer.completed_by && (
                        <div>
                            <p className="font-semibold text-surface-600 mb-0.5">
                                Completed by
                            </p>
                            <p>{transfer.completed_by.name}</p>
                        </div>
                    )}
                </div>

                {transfer.notes && (
                    <p className="text-xs text-surface-400 italic bg-surface-50 rounded-lg px-3 py-2">
                        {transfer.notes}
                    </p>
                )}

                {showCancel && (
                    <div className="border border-danger/20 bg-danger-light rounded-xl p-3 space-y-2">
                        <p className="text-sm font-medium text-danger">
                            Cancel this transfer?
                        </p>
                        <input
                            className="input text-sm"
                            placeholder="Reason…"
                            value={cancelReason}
                            onChange={(e) => setCancelReason(e.target.value)}
                        />
                        <div className="flex gap-2">
                            <button
                                onClick={() => setShowCancel(false)}
                                className="btn-secondary btn-sm text-xs"
                            >
                                Back
                            </button>
                            <button
                                onClick={() => cancelMutation.mutate()}
                                disabled={cancelMutation.isPending}
                                className="btn-sm bg-danger text-white hover:bg-danger/90 text-xs px-3 py-1.5 rounded-lg"
                            >
                                {cancelMutation.isPending && (
                                    <Spinner
                                        size="xs"
                                        className="border-white/30 border-t-white"
                                    />
                                )}{" "}
                                Confirm Cancel
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}

export default function StockTransfersPage() {
    const navigate = useNavigate();
    const { can } = usePermissions();
    const canTransfer = can("inventory.transfer");
    const table = useTableState({
        defaultSortBy: "created_at",
        defaultPerPage: 25,
    });
    const [statusFilter, setStatusFilter] = useState("");
    const [newModal, setNewModal] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(null);

    const params: Record<string, string> = {
        ...table.toParams(),
        ...(statusFilter && { status: statusFilter }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["transfers", params],
        queryFn: () => transfersApi.list(params),
    });
    const transfers = data?.data ?? [];
    const meta = data?.meta;
    const stats = data?.stats;

    // Group the current page of rows by requested_at. Pagination, sort, and
    // filters are untouched - this only re-partitions the rows already fetched.
    const transferGroups = groupRowsByDate(transfers, (t) => t.requested_at);

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Stock Transfers</h1>
                    <p className="page-subtitle">
                        {stats
                            ? `${stats.total} transfers · ${stats.pending} pending · ${stats.in_transit} in transit`
                            : "Loading…"}
                    </p>
                </div>
                {canTransfer && (
                <button
                    onClick={() => setNewModal(true)}
                    className="btn-primary self-start"
                >
                    + New Transfer
                </button>
                )}
            </div>

            {stats && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {(
                        [
                            {
                                label: "Pending",
                                value: stats.pending,
                                color: "text-warning",
                                filter: "pending",
                            },
                            {
                                label: "Approved",
                                value: stats.approved,
                                color: "text-brand-600",
                                filter: "approved",
                            },
                            {
                                label: "In Transit",
                                value: stats.in_transit,
                                color: "text-purple-600",
                                filter: "in_transit",
                            },
                            {
                                label: "Completed",
                                value: stats.completed,
                                color: "text-success",
                                filter: "completed",
                            },
                            {
                                label: "Cancelled",
                                value: stats.cancelled,
                                color: "text-surface-400",
                                filter: "cancelled",
                            },
                        ] as const
                    ).map((s) => (
                        <button
                            key={s.label}
                            onClick={() =>
                                setStatusFilter(
                                    statusFilter === s.filter ? "" : s.filter,
                                )
                            }
                            className={clsx(
                                "card p-4 text-center transition-all hover:shadow-sm",
                                statusFilter === s.filter
                                    ? "ring-2 ring-brand-300"
                                    : "",
                            )}
                        >
                            <p className={clsx("text-2xl font-bold", s.color)}>
                                {s.value}
                            </p>
                            <p className="text-xs text-surface-500 mt-0.5">
                                {s.label}
                            </p>
                        </button>
                    ))}
                </div>
            )}

            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <input
                    className="input w-full sm:max-w-xs"
                    placeholder="Search transfer #…"
                    value={table.state.search}
                    onChange={(e) => table.setSearch(e.target.value)}
                />
                <div className="flex gap-2 flex-wrap">
                <select
                    className="input flex-1 sm:w-40 sm:flex-none"
                    value={statusFilter}
                    onChange={(e) => setStatusFilter(e.target.value)}
                >
                    <option value="">All statuses</option>
                    {Object.entries(STATUS_CONFIG).map(([k, v]) => (
                        <option key={k} value={k}>
                            {v.label}
                        </option>
                    ))}
                </select>
                {(table.state.search || statusFilter) && (
                    <button
                        onClick={() => {
                            table.setSearch("");
                            setStatusFilter("");
                        }}
                        className="btn-ghost btn-sm text-xs"
                    >
                        ✕ Clear
                    </button>
                )}
                </div>
            </div>

            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                ) : transfers.length === 0 ? (
                    <div className="text-center py-16">
                        <p className="text-surface-400 text-sm mb-3">
                            {table.state.search || statusFilter
                                ? "No transfers match."
                                : "No transfers yet."}
                        </p>
                        {!table.state.search && !statusFilter && canTransfer && (
                            <button
                                onClick={() => setNewModal(true)}
                                className="btn-primary btn-sm"
                            >
                                Create first transfer
                            </button>
                        )}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[560px]">
                        <thead>
                            <tr className="border-b border-surface-100 bg-surface-50/50">
                                {[
                                    "Transfer #",
                                    "Route",
                                    "Items",
                                    "Progress",
                                    "Date",
                                    "By",
                                    "",
                                ].map((h, idx) => (
                                    <th
                                        key={h || idx}
                                        className={clsx("px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider",
                                            h === "By" && "hidden lg:table-cell",
                                            h === "Date" && "hidden sm:table-cell",
                                        )}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {transferGroups.map((group) => (
                                <Fragment key={group.key}>
                                    <DateGroupHeaderRow label={group.label} colSpan={7} />
                                    {group.items.map((t) => {
                                const st =
                                    STATUS_CONFIG[
                                        t.status as keyof typeof STATUS_CONFIG
                                    ];
                                return (
                                    <tr
                                        key={t.id}
                                        className="hover:bg-surface-50/50 transition-colors cursor-pointer"
                                        onClick={() => navigate(`/inventory/transfers/${t.id}`)}
                                    >
                                        <td className="px-4 py-3">
                                            <p className="text-sm font-mono font-medium text-surface-900">
                                                {t.transfer_number}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1.5 text-sm flex-wrap">
                                                <span className="font-medium text-surface-700">
                                                    {t.from_outlet?.name}
                                                </span>
                                                <svg
                                                    className="w-3.5 h-3.5 text-surface-300 shrink-0"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                    strokeWidth={2}
                                                >
                                                    <path
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                        d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"
                                                    />
                                                </svg>
                                                <span className="font-medium text-surface-700">
                                                    {t.to_outlet?.name}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="text-sm font-semibold">
                                                {t.items_count}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <TransferProgress
                                                status={t.status}
                                            />
                                        </td>
                                        <td className="px-4 py-3 hidden sm:table-cell">
                                            <p className="text-xs text-surface-500">
                                                {t.requested_at
                                                    ? new Date(
                                                          t.requested_at,
                                                      ).toLocaleDateString(
                                                          "en-GB",
                                                          {
                                                              day: "numeric",
                                                              month: "short",
                                                          },
                                                      )
                                                    : "-"}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3 hidden lg:table-cell">
                                            <p className="text-sm text-surface-600">
                                                {t.requested_by?.name ?? "-"}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                            <div className="flex items-center gap-1 justify-end">
                                                {[
                                                    "pending",
                                                    "approved",
                                                    "in_transit",
                                                ].includes(t.status) && (
                                                    <button
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setSelectedId(t.id);
                                                        }}
                                                        className="btn-primary btn-sm text-xs"
                                                    >
                                                        {t.status === "pending"
                                                            ? "Review"
                                                            : t.status === "approved"
                                                              ? "Dispatch"
                                                              : "Receive"}
                                                    </button>
                                                )}
                                                <button
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        navigate(`/inventory/transfers/${t.id}`);
                                                    }}
                                                    className="btn-ghost btn-icon btn-sm"
                                                    title="View detail"
                                                >
                                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    </svg>
                                                </button>
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

            {newModal && (
                <NewTransferModal
                    open={newModal}
                    onClose={() => setNewModal(false)}
                    onSaved={() => {}}
                />
            )}
            {selectedId && (
                <TransferDetailModal
                    id={selectedId}
                    onClose={() => setSelectedId(null)}
                />
            )}
        </div>
    );
}