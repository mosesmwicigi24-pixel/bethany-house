import { useState, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { stockApi } from "@/api/stock";
import type {
    StockItem,
    StockTransaction,
    OpeningStockEntry,
} from "@/api/stock";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import { Field, useFieldAriaProps, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import type { ApiError } from "@/types";
import { clsx } from "clsx";
import { useQuery as useQ } from "@tanstack/react-query";
import { get } from "@/api/client";

// ── Helpers ───────────────────────────────────────────────────────────────────

const STATUS_CONFIG = {
    in_stock: {
        label: "In Stock",
        bg: "bg-success-light",
        text: "text-success",
        dot: "bg-success",
    },
    low_stock: {
        label: "Low Stock",
        bg: "bg-warning-light",
        text: "text-warning",
        dot: "bg-warning",
    },
    out_of_stock: {
        label: "Out of Stock",
        bg: "bg-danger-light",
        text: "text-danger",
        dot: "bg-danger",
    },
} as const;

const TX_TYPE_LABELS: Record<string, string> = {
    opening_stock: "Opening Stock",
    adjustment: "Adjustment",
    sale: "Sale",
    purchase: "Purchase Receipt",
    return: "Return",
    transfer_in: "Transfer In",
    transfer_out: "Transfer Out",
    production_in: "Production In",
    production_out: "Production Out",
    stock_count: "Stock Count",
    damage: "Damaged",
    expired: "Expired",
};

const TX_COLORS: Record<string, string> = {
    sale: "text-danger",
    adjustment: "text-warning",
    purchase: "text-success",
    return: "text-success",
    transfer_in: "text-success",
    transfer_out: "text-danger",
    production_in: "text-success",
    production_out: "text-danger",
    damage: "text-danger",
    opening_stock: "text-brand-600",
    stock_count: "text-surface-600",
};

// ── Stock bar ─────────────────────────────────────────────────────────────────

function StockBar({ item }: { item: StockItem }) {
    if (item.reorder_point === 0) return null;
    const pct = Math.min(
        100,
        (item.quantity_available / Math.max(item.reorder_point * 2, 1)) * 100,
    );
    return (
        <div className="w-20 h-1.5 bg-surface-100 rounded-full overflow-hidden">
            <div
                className={clsx(
                    "h-full rounded-full transition-all",
                    item.status === "out_of_stock"
                        ? "bg-danger"
                        : item.status === "low_stock"
                          ? "bg-warning"
                          : "bg-success",
                )}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

// ── Movement history modal ────────────────────────────────────────────────────

function MovementModal({
    item,
    onClose,
}: {
    item: StockItem;
    onClose: () => void;
}) {
    const [typeFilter, setTypeFilter] = useState("");
    const [fromDate, setFromDate] = useState("");
    const [toDate, setToDate] = useState("");

    const params: Record<string, string> = {
        ...(typeFilter && { type: typeFilter }),
        ...(fromDate && { from: fromDate }),
        ...(toDate && { to: toDate }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["stock-history", item.id, params],
        queryFn: () => stockApi.history(item.id, params),
    });

    const transactions = data?.data ?? [];

    const productName = item.product?.name ?? item.product?.sku ?? "-";
    const variantName = item.variant?.variant_name
        ? ` · ${item.variant.variant_name}`
        : "";
    const outletName = item.outlet?.name ?? "-";

    return (
        <Modal
            open
            onClose={onClose}
            title={`Movement History - ${productName}${variantName}`}
            size="xl"
            footer={
                <button onClick={onClose} className="btn-primary btn-sm">
                    Close
                </button>
            }
        >
            <div className="space-y-4">
                {/* Summary row */}
                <div className="grid grid-cols-3 gap-3">
                    {[
                        {
                            label: "On Hand",
                            value: item.quantity_on_hand,
                            color: "text-surface-900",
                        },
                        {
                            label: "Reserved",
                            value: item.quantity_reserved,
                            color: "text-warning",
                        },
                        {
                            label: "Available",
                            value: item.quantity_available,
                            color: "text-success",
                        },
                    ].map((s) => (
                        <div
                            key={s.label}
                            className="text-center py-2.5 bg-surface-50 rounded-xl"
                        >
                            <p className={clsx("text-xl font-bold", s.color)}>
                                {s.value}
                            </p>
                            <p className="text-xs text-surface-500 mt-0.5">
                                {s.label}
                            </p>
                        </div>
                    ))}
                </div>

                <p className="text-xs text-surface-400">
                    Outlet:{" "}
                    <span className="text-surface-600 font-medium">
                        {outletName}
                    </span>
                </p>

                {/* Filters */}
                <div className="flex gap-2 flex-wrap">
                    <select
                        className="input text-sm w-44"
                        value={typeFilter}
                        onChange={(e) => setTypeFilter(e.target.value)}
                    >
                        <option value="">All types</option>
                        {Object.entries(TX_TYPE_LABELS).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </select>
                    <input
                        className="input text-sm w-36"
                        type="date"
                        value={fromDate}
                        onChange={(e) => setFromDate(e.target.value)}
                        placeholder="From"
                    />
                    <input
                        className="input text-sm w-36"
                        type="date"
                        value={toDate}
                        onChange={(e) => setToDate(e.target.value)}
                        placeholder="To"
                    />
                    {(typeFilter || fromDate || toDate) && (
                        <button
                            onClick={() => {
                                setTypeFilter("");
                                setFromDate("");
                                setToDate("");
                            }}
                            className="btn-ghost btn-sm text-xs"
                        >
                            ✕ Clear
                        </button>
                    )}
                </div>

                {/* Timeline */}
                {isLoading ? (
                    <div className="flex justify-center py-8">
                        <Spinner size="md" />
                    </div>
                ) : transactions.length === 0 ? (
                    <p className="text-sm text-surface-400 text-center py-6">
                        No movements found.
                    </p>
                ) : (
                    <div className="space-y-0 max-h-96 overflow-y-auto border border-surface-100 rounded-xl overflow-hidden">
                        {transactions.map((tx, i) => (
                            <div
                                key={tx.id}
                                className={clsx(
                                    "flex items-start gap-3 px-4 py-3 border-b border-surface-50 last:border-0 hover:bg-surface-50/50",
                                    i === 0 && "bg-brand-50/30",
                                )}
                            >
                                {/* Type dot */}
                                <div className="mt-0.5 shrink-0">
                                    <div
                                        className={clsx(
                                            "w-2 h-2 rounded-full mt-1.5",
                                            tx.quantity_change > 0
                                                ? "bg-success"
                                                : "bg-danger",
                                        )}
                                    />
                                </div>

                                {/* Detail */}
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium text-surface-800">
                                            {TX_TYPE_LABELS[
                                                tx.transaction_type
                                            ] ?? tx.transaction_type}
                                        </span>
                                        <span
                                            className={clsx(
                                                "text-sm font-bold tabular-nums",
                                                TX_COLORS[
                                                    tx.transaction_type
                                                ] ?? "text-surface-600",
                                            )}
                                        >
                                            {tx.quantity_change > 0 ? "+" : ""}
                                            {tx.quantity_change}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 mt-0.5 text-xs text-surface-400">
                                        <span>
                                            {tx.quantity_before} →{" "}
                                            {tx.quantity_after}
                                        </span>
                                        {tx.notes && (
                                            <span className="truncate italic max-w-48">
                                                {tx.notes}
                                            </span>
                                        )}
                                        {tx.created_by && (
                                            <span>{tx.created_by.name}</span>
                                        )}
                                    </div>
                                </div>

                                {/* Date */}
                                <div className="text-right shrink-0">
                                    <p className="text-xs text-surface-500">
                                        {new Date(
                                            tx.created_at,
                                        ).toLocaleDateString("en-GB", {
                                            day: "numeric",
                                            month: "short",
                                            year: "numeric",
                                        })}
                                    </p>
                                    <p className="text-2xs text-surface-400">
                                        {new Date(
                                            tx.created_at,
                                        ).toLocaleTimeString("en-GB", {
                                            hour: "2-digit",
                                            minute: "2-digit",
                                        })}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </Modal>
    );
}

// ── Opening stock modal ───────────────────────────────────────────────────────

interface OpeningStockModalProps {
    open: boolean;
    onClose: () => void;
    onSaved: () => void;
}

interface EntryRow {
    _key: string;
    product_id: number | "";
    product_variant_id: number | null;
    outlet_id: number | "";
    quantity: number;
    reorder_point: number;
    notes: string;
    // display helpers
    product_name?: string;
    product_sku?: string;
    variant_name?: string;
    outlet_name?: string;
}

function OpeningStockModal({ open, onClose, onSaved }: OpeningStockModalProps) {
    const toast = useToastStore();
    const qc = useQueryClient();

    const [rows, setRows] = useState<EntryRow[]>([newRow()]);
    const [step, setStep] = useState<"form" | "confirm">("form");
    // Cache fetched variants keyed by product_id
    const [variantCache, setVariantCache] = useState<Record<number, any[]>>({});
    const [loadingVariants, setLoadingVariants] = useState<
        Record<number, boolean>
    >({});

    // Data for selectors
    const { data: productsData } = useQuery({
        queryKey: ["products-simple"],
        queryFn: () =>
            get<{ data: any[] }>("/v1/admin/products", {
                params: { per_page: "200" },
            }),
        enabled: open,
    });
    const { data: outletsData } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => get<any>("/v1/admin/outlets"),
        enabled: open,
    });

    // Sort the product picker alphabetically by the same label shown in the
    // dropdown (name, falling back to SKU), case-insensitive.
    const productLabel = (p: any) => p?.en_translation?.name ?? p?.sku ?? "";
    const products = [...(productsData?.data ?? [])].sort((a, b) =>
        productLabel(a).localeCompare(productLabel(b), undefined, { sensitivity: "base" }),
    );
    const outlets = Array.isArray(outletsData)
        ? outletsData
        : (outletsData?.data ?? []);

    function newRow(): EntryRow {
        return {
            _key: `row-${Date.now()}-${Math.random()}`,
            product_id: "",
            product_variant_id: null,
            outlet_id: "",
            quantity: 0,
            reorder_point: 0,
            notes: "",
        };
    }

    const addRow = () => setRows((prev) => [...prev, newRow()]);
    const removeRow = (key: string) =>
        setRows((prev) => prev.filter((r) => r._key !== key));

    // Fetch variants for a product if it's variable and not cached
    const fetchVariants = async (productId: number) => {
        if (variantCache[productId] !== undefined) return;
        const p = products.find((x: any) => x.id === productId);
        // Only fetch if variable product with variants
        if (!p || p.product_type !== "variable" || !p.variants_count) {
            setVariantCache((prev) => ({ ...prev, [productId]: [] }));
            return;
        }
        setLoadingVariants((prev) => ({ ...prev, [productId]: true }));
        try {
            const res = await get<{ product: any }>(
                `/v1/admin/products/${productId}`,
            );
            const variants = res.product?.variants ?? [];
            setVariantCache((prev) => ({ ...prev, [productId]: variants }));
        } catch {
            setVariantCache((prev) => ({ ...prev, [productId]: [] }));
        } finally {
            setLoadingVariants((prev) => ({ ...prev, [productId]: false }));
        }
    };

    const updateRow = (key: string, field: keyof EntryRow, value: any) => {
        setRows((prev) =>
            prev.map((r) => {
                if (r._key !== key) return r;
                const updated = { ...r, [field]: value };
                if (field === "product_id") {
                    const p = products.find((x: any) => x.id === Number(value));
                    updated.product_variant_id = null;
                    updated.product_name =
                        p?.en_translation?.name ?? p?.sku ?? "";
                    updated.product_sku = p?.sku ?? "";
                    updated.variant_name = "";
                    // Kick off variant fetch
                    if (value) fetchVariants(Number(value));
                }
                if (field === "product_id" && !value) {
                    updated.product_variant_id = null;
                    updated.product_name = "";
                    updated.product_sku = "";
                    updated.variant_name = "";
                }
                if (field === "product_variant_id") {
                    const variants = variantCache[r.product_id as number] ?? [];
                    const v = variants.find((x: any) => x.id === Number(value));
                    updated.variant_name = v?.variant_name ?? "";
                }
                if (field === "outlet_id") {
                    const o = outlets.find((x: any) => x.id === Number(value));
                    updated.outlet_name = o?.name ?? "";
                }
                return updated;
            }),
        );
    };

    // Get variants for a row from cache
    const getVariants = (productId: number | "") => {
        if (!productId) return [];
        return variantCache[productId as number] ?? [];
    };

    const isVariableProduct = (productId: number | "") => {
        if (!productId) return false;
        const p = products.find((x: any) => x.id === productId);
        return p?.product_type === "variable" && (p?.variants_count ?? 0) > 0;
    };

    const saveMutation = useMutation({
        mutationFn: () => {
            const entries: OpeningStockEntry[] = rows
                .filter((r) => r.product_id && r.outlet_id)
                .map((r) => ({
                    product_id: Number(r.product_id),
                    product_variant_id: r.product_variant_id ?? undefined,
                    outlet_id: Number(r.outlet_id),
                    quantity: r.quantity,
                    reorder_point: r.reorder_point || undefined,
                    notes: r.notes || undefined,
                }));
            return stockApi.setOpeningStock(entries);
        },
        onSuccess: (res) => {
            toast.success(res.message);
            qc.invalidateQueries({ queryKey: ["stock-levels"] });
            onSaved();
            onClose();
            setRows([newRow()]);
            setStep("form");
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const validRows = rows.filter((r) => r.product_id && r.outlet_id);

    if (!open) return null;

    return (
        <Modal
            open={open}
            onClose={() => {
                onClose();
                setStep("form");
            }}
            title="Set Opening Stock"
            size="full"
            footer={
                <>
                    <button
                        onClick={() => {
                            onClose();
                            setStep("form");
                        }}
                        className="btn-secondary btn-sm"
                    >
                        Cancel
                    </button>
                    {step === "form" ? (
                        <button
                            onClick={() => {
                                if (validRows.length > 0) setStep("confirm");
                            }}
                            disabled={validRows.length === 0}
                            className="btn-primary btn-sm"
                        >
                            Review {validRows.length} entr
                            {validRows.length === 1 ? "y" : "ies"} →
                        </button>
                    ) : (
                        <button
                            onClick={() => saveMutation.mutate()}
                            disabled={saveMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {saveMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Save Opening Stock
                        </button>
                    )}
                </>
            }
        >
            {step === "form" ? (
                <div className="space-y-3">
                    <p className="text-xs text-surface-400 bg-surface-50 rounded-lg px-3 py-2">
                        Enter the current quantity on hand for each
                        product/outlet combination. Existing records will be
                        updated; new ones will be created.
                    </p>

                    {/* Header row */}
                    <div className="grid grid-cols-12 gap-2 text-xs font-semibold text-surface-500 uppercase tracking-wider px-1">
                        <div className="col-span-3">Product</div>
                        <div className="col-span-2">Variant</div>
                        <div className="col-span-2">Outlet</div>
                        <div className="col-span-1 text-right">Qty</div>
                        <div className="col-span-1 text-right">Reorder</div>
                        <div className="col-span-2">Notes</div>
                        <div className="col-span-1" />
                    </div>

                    <div className="space-y-2 max-h-96 overflow-y-auto pr-1">
                        {rows.map((row) => {
                            return (
                                <div
                                    key={row._key}
                                    className="grid grid-cols-12 gap-2 items-start"
                                >
                                    {/* Product */}
                                    <div className="col-span-3">
                                        <select
                                            className="input text-sm py-1.5"
                                            value={row.product_id}
                                            onChange={(e) =>
                                                updateRow(
                                                    row._key,
                                                    "product_id",
                                                    e.target.value
                                                        ? Number(e.target.value)
                                                        : "",
                                                )
                                            }
                                        >
                                            <option value="">
                                                - Product -
                                            </option>
                                            {products.map((p: any) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.en_translation?.name ??
                                                        p.sku}{" "}
                                                    ({p.sku})
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Variant */}
                                    <div className="col-span-2">
                                        {isVariableProduct(row.product_id) ? (
                                            <select
                                                className="input text-sm py-1.5"
                                                value={
                                                    row.product_variant_id ?? ""
                                                }
                                                onChange={(e) =>
                                                    updateRow(
                                                        row._key,
                                                        "product_variant_id",
                                                        e.target.value
                                                            ? Number(
                                                                  e.target
                                                                      .value,
                                                              )
                                                            : null,
                                                    )
                                                }
                                                disabled={
                                                    loadingVariants[
                                                        row.product_id as number
                                                    ]
                                                }
                                            >
                                                <option value="">
                                                    {loadingVariants[
                                                        row.product_id as number
                                                    ]
                                                        ? "Loading…"
                                                        : "- Select variant -"}
                                                </option>
                                                {getVariants(
                                                    row.product_id,
                                                ).map((v: any) => (
                                                    <option
                                                        key={v.id}
                                                        value={v.id}
                                                    >
                                                        {v.variant_name} (
                                                        {v.sku})
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <select
                                                className="input text-sm py-1.5"
                                                disabled
                                            >
                                                <option value="">
                                                    - Base product -
                                                </option>
                                            </select>
                                        )}
                                    </div>

                                    {/* Outlet */}
                                    <div className="col-span-2">
                                        <select
                                            className="input text-sm py-1.5"
                                            value={row.outlet_id}
                                            onChange={(e) =>
                                                updateRow(
                                                    row._key,
                                                    "outlet_id",
                                                    e.target.value
                                                        ? Number(e.target.value)
                                                        : "",
                                                )
                                            }
                                        >
                                            <option value="">- Outlet -</option>
                                            {outlets.map((o: any) => (
                                                <option key={o.id} value={o.id}>
                                                    {o.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {/* Qty */}
                                    <div className="col-span-1">
                                        <input
                                            type="number"
                                            min="0"
                                            className="input text-sm py-1.5 text-right"
                                            value={row.quantity}
                                            onChange={(e) =>
                                                updateRow(
                                                    row._key,
                                                    "quantity",
                                                    parseInt(e.target.value) ||
                                                        0,
                                                )
                                            }
                                        />
                                    </div>

                                    {/* Reorder point */}
                                    <div className="col-span-1">
                                        <input
                                            type="number"
                                            min="0"
                                            className="input text-sm py-1.5 text-right"
                                            value={row.reorder_point}
                                            placeholder="0"
                                            onChange={(e) =>
                                                updateRow(
                                                    row._key,
                                                    "reorder_point",
                                                    parseInt(e.target.value) ||
                                                        0,
                                                )
                                            }
                                        />
                                    </div>

                                    {/* Notes */}
                                    <div className="col-span-2">
                                        <input
                                            type="text"
                                            className="input text-sm py-1.5"
                                            placeholder="Optional…"
                                            value={row.notes}
                                            onChange={(e) =>
                                                updateRow(
                                                    row._key,
                                                    "notes",
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>

                                    {/* Remove */}
                                    <div className="col-span-1 flex items-center justify-center">
                                        {rows.length > 1 && (
                                            <button
                                                onClick={() =>
                                                    removeRow(row._key)
                                                }
                                                className="text-surface-300 hover:text-danger transition-colors"
                                                aria-label="Close"
                                            >
                                                <svg
                                                    className="w-4 h-4"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke="currentColor"
                                                    strokeWidth={2}
                                                >
                                                    <path
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                        d="M6 18L18 6M6 6l12 12"
                                                    />
                                                </svg>
                                            </button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    <button
                        onClick={addRow}
                        className="btn-ghost btn-sm text-xs text-brand-600"
                    >
                        + Add Row
                    </button>
                </div>
            ) : (
                /* Confirmation step */
                <div className="space-y-3">
                    <p className="text-xs text-surface-400 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-amber-700">
                        Review the entries below. Saving will update existing
                        stock records or create new ones.
                    </p>
                    <div className="border border-surface-100 rounded-xl overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-surface-100 bg-surface-50 text-xs text-surface-500 uppercase">
                                    <th className="px-4 py-2.5 text-left font-semibold">
                                        Product
                                    </th>
                                    <th className="px-4 py-2.5 text-left font-semibold">
                                        Outlet
                                    </th>
                                    <th className="px-4 py-2.5 text-right font-semibold">
                                        Qty
                                    </th>
                                    <th className="px-4 py-2.5 text-right font-semibold">
                                        Reorder at
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
                                {validRows.map((r) => (
                                    <tr key={r._key}>
                                        <td className="px-4 py-2.5">
                                            <p className="font-medium text-surface-900">
                                                {r.product_name ||
                                                    r.product_sku}
                                            </p>
                                            {r.variant_name && (
                                                <p className="text-xs text-surface-400">
                                                    {r.variant_name}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-2.5 text-surface-600">
                                            {r.outlet_name}
                                        </td>
                                        <td className="px-4 py-2.5 text-right font-semibold text-surface-900">
                                            {r.quantity}
                                        </td>
                                        <td className="px-4 py-2.5 text-right text-surface-600">
                                            {r.reorder_point || "-"}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <button
                        onClick={() => setStep("form")}
                        className="text-xs text-brand-500 hover:underline"
                    >
                        ← Back to edit
                    </button>
                </div>
            )}
        </Modal>
    );
}

// ── Reorder settings modal ────────────────────────────────────────────────────

function ReorderModal({
    item,
    onClose,
}: {
    item: StockItem;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [reorderPoint, setReorderPoint] = useState(item.reorder_point);
    const [reorderQuantity, setReorderQuantity] = useState(
        item.reorder_quantity,
    );

    const mutation = useMutation({
        mutationFn: () =>
            stockApi.update(item.id, {
                reorder_point: reorderPoint,
                reorder_quantity: reorderQuantity,
            }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["stock-levels"] });
            toast.success("Reorder settings updated.");
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open
            onClose={onClose}
            title="Reorder Settings"
            size="sm"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending}
                        className="btn-primary btn-sm"
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        Save
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <p className="text-sm text-surface-600">
                    <span className="font-medium">{item.product?.name}</span>
                    {item.variant && (
                        <span className="text-surface-400">
                            {" "}
                            · {item.variant.variant_name}
                        </span>
                    )}
                    <span className="text-surface-400">
                        {" "}
                        @ {item.outlet?.name}
                    </span>
                </p>
                <Field
                    label="Reorder Point"
                    hint="Alert when stock falls to or below this quantity"
                >
                    <FieldInput
                        type="number"
                        min="0"
                        className="input"
                        value={reorderPoint}
                        onChange={(e) =>
                            setReorderPoint(parseInt(e.target.value) || 0)
                        }
                    />
                </Field>
                <Field
                    label="Reorder Quantity"
                    hint="Suggested quantity to order when restocking"
                >
                    <FieldInput
                        type="number"
                        min="0"
                        className="input"
                        value={reorderQuantity}
                        onChange={(e) =>
                            setReorderQuantity(parseInt(e.target.value) || 0)
                        }
                    />
                </Field>
            </div>
        </Modal>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function StockLevelsPage() {
    const toast = useToastStore();
    const { can } = usePermissions();
    const canAdjust = can("inventory.adjust");
    const table = useTableState({
        defaultSortBy: "quantity_on_hand",
        defaultPerPage: 30,
    });

    const [statusFilter, setStatusFilter] = useState("");
    const [outletFilter, setOutletFilter] = useState("");
    const [openingModal, setOpeningModal] = useState(false);
    const [historyItem, setHistoryItem] = useState<StockItem | null>(null);
    const [reorderItem, setReorderItem] = useState<StockItem | null>(null);

    const params: Record<string, string> = {
        ...table.toParams(),
        ...(statusFilter && { status: statusFilter }),
        ...(outletFilter && { outlet_id: outletFilter }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["stock-levels", params],
        queryFn: () => stockApi.list(params),
    });

    const { data: outletsData } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => get<any>("/v1/admin/outlets"),
    });

    const items = data?.data ?? [];
    const meta = data?.meta;
    const stats = data?.stats;
    const outlets = Array.isArray(outletsData)
        ? outletsData
        : (outletsData?.data ?? []);

    return (
        <div className="space-y-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Stock Levels</h1>
                    <p className="page-subtitle">
                        {stats
                            ? `${stats.total_skus} SKUs · ${stats.in_stock} in stock · ${stats.low_stock} low · ${stats.out_of_stock} out of stock`
                            : "Loading…"}
                    </p>
                </div>
                {canAdjust && (
                <button
                    onClick={() => setOpeningModal(true)}
                    className="btn-primary self-start"
                >
                    + Set Opening Stock
                </button>
                )}
            </div>

            {/* Stats cards */}
            {stats && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        {
                            label: "Total SKUs",
                            value: stats.total_skus,
                            color: "",
                            filter: "",
                        },
                        {
                            label: "In Stock",
                            value: stats.in_stock,
                            color: "text-success",
                            filter: "in_stock",
                        },
                        {
                            label: "Low Stock",
                            value: stats.low_stock,
                            color: "text-warning",
                            filter: "low_stock",
                        },
                        {
                            label: "Out of Stock",
                            value: stats.out_of_stock,
                            color: "text-danger",
                            filter: "out_of_stock",
                        },
                    ].map((s) => (
                        <button
                            key={s.label}
                            onClick={() =>
                                setStatusFilter(
                                    statusFilter === s.filter ? "" : s.filter,
                                )
                            }
                            className={clsx(
                                "card p-4 text-center transition-all hover:shadow-sm",
                                statusFilter === s.filter && s.filter
                                    ? "ring-2 ring-brand-300"
                                    : "",
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

            {/* Filters */}
            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <input
                    className="input w-full sm:max-w-xs"
                    placeholder="Search product or SKU…"
                    value={table.state.search}
                    onChange={(e) => table.setSearch(e.target.value)}
                />
                <select
                    className="input flex-1 sm:w-44 sm:flex-none"
                    value={statusFilter}
                    onChange={(e) => setStatusFilter(e.target.value)}
                >
                    <option value="">All statuses</option>
                    <option value="in_stock">In Stock</option>
                    <option value="low_stock">Low Stock</option>
                    <option value="out_of_stock">Out of Stock</option>
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
                {(table.state.search || statusFilter || outletFilter) && (
                    <button
                        onClick={() => {
                            table.setSearch("");
                            setStatusFilter("");
                            setOutletFilter("");
                        }}
                        className="btn-ghost btn-sm text-xs"
                    >
                        ✕ Clear
                    </button>
                )}
            </div>

            {/* Table */}
            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                ) : items.length === 0 ? (
                    <div className="text-center py-16">
                        <p className="text-surface-400 text-sm mb-3">
                            {table.state.search || statusFilter
                                ? "No items match your filters."
                                : "No stock records yet."}
                        </p>
                        {!table.state.search && !statusFilter && canAdjust && (
                            <button
                                onClick={() => setOpeningModal(true)}
                                className="btn-primary btn-sm"
                            >
                                Set Opening Stock
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
                                    Outlet
                                </th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    On Hand
                                </th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                                    Reserved
                                </th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Available
                                </th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">
                                    Reorder at
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {items.map((item) => {
                                const status = STATUS_CONFIG[item.status];
                                return (
                                    <tr
                                        key={item.id}
                                        className="hover:bg-surface-50/50 transition-colors"
                                    >
                                        {/* Product */}
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                <div className="w-9 h-9 rounded-lg overflow-hidden shrink-0 bg-surface-100 border border-surface-200">
                                                    {item.product?.image_url ? (
                                                        <img
                                                            src={
                                                                item.product
                                                                    .image_url
                                                            }
                                                            alt=""
                                                            className="w-full h-full object-cover"
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
                                                        {item.product?.name ??
                                                            "-"}
                                                    </p>
                                                    <div className="flex items-center gap-1.5 mt-0.5">
                                                        <span className="text-xs font-mono text-surface-400">
                                                            {item.product?.sku}
                                                        </span>
                                                        {item.variant && (
                                                            <span className="text-xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                                                {
                                                                    item.variant
                                                                        .variant_name
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        {/* Outlet */}
                                        <td className="px-4 py-3 hidden sm:table-cell">
                                            <span className="text-sm text-surface-600">
                                                {item.outlet?.name ?? "-"}
                                            </span>
                                            {item.outlet?.code && (
                                                <p className="text-xs font-mono text-surface-400">
                                                    {item.outlet.code}
                                                </p>
                                            )}
                                        </td>

                                        {/* Quantities */}
                                        <td className="px-4 py-3 text-center">
                                            <span className="text-sm font-semibold text-surface-900 tabular-nums">
                                                {item.quantity_on_hand}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-center hidden md:table-cell">
                                            <span
                                                className={clsx(
                                                    "text-sm tabular-nums",
                                                    item.quantity_reserved > 0
                                                        ? "text-warning font-semibold"
                                                        : "text-surface-400",
                                                )}
                                            >
                                                {item.quantity_reserved}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <div className="flex flex-col items-center gap-1">
                                                <span
                                                    className={clsx(
                                                        "text-sm font-bold tabular-nums",
                                                        item.quantity_available ===
                                                            0
                                                            ? "text-danger"
                                                            : item.status ===
                                                                "low_stock"
                                                              ? "text-warning"
                                                              : "text-success",
                                                    )}
                                                >
                                                    {item.quantity_available}
                                                </span>
                                                <StockBar item={item} />
                                            </div>
                                        </td>

                                        {/* Reorder point */}
                                        <td className="px-4 py-3 text-center hidden lg:table-cell">
                                            <span className="text-sm text-surface-500 tabular-nums">
                                                {item.reorder_point || "-"}
                                            </span>
                                        </td>

                                        {/* Status */}
                                        <td className="px-4 py-3">
                                            <span
                                                className={clsx(
                                                    "text-xs font-medium px-2.5 py-1 rounded-full",
                                                    status.bg,
                                                    status.text,
                                                )}
                                            >
                                                {status.label}
                                            </span>
                                        </td>

                                        {/* Actions */}
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1 justify-end">
                                                <button
                                                    title="Movement history"
                                                    onClick={() =>
                                                        setHistoryItem(item)
                                                    }
                                                    className="btn-ghost btn-sm"
                                                >
                                                    <HistoryIcon />
                                                </button>
                                                {canAdjust && (
                                                <button
                                                    title="Reorder settings"
                                                    onClick={() =>
                                                        setReorderItem(item)
                                                    }
                                                    className="btn-ghost btn-sm"
                                                >
                                                    <SettingsIcon />
                                                </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
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
            <OpeningStockModal
                open={openingModal}
                onClose={() => setOpeningModal(false)}
                onSaved={() => {}}
            />

            {historyItem && (
                <MovementModal
                    item={historyItem}
                    onClose={() => setHistoryItem(null)}
                />
            )}
            {reorderItem && (
                <ReorderModal
                    item={reorderItem}
                    onClose={() => setReorderItem(null)}
                />
            )}
        </div>
    );
}

// ── Icons ──────────────────────────────────────────────────────────────────────

const HistoryIcon = () => (
    <svg
        className="w-4 h-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
        />
    </svg>
);
const SettingsIcon = () => (
    <svg
        className="w-4 h-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
        />
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
        />
    </svg>
);