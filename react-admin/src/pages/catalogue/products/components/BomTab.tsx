import { useState, useEffect, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { bomApi, materialsSearchApi } from "@/api/bom";
import type { Bom, BomItem, Material } from "@/api/bom";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Field, useFieldAriaProps, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";
import { clsx } from "clsx";

// ── UOM options ───────────────────────────────────────────────────────────────

const UOM_OPTIONS = [
    "pcs",
    "meters",
    "yards",
    "kg",
    "g",
    "liters",
    "ml",
    "rolls",
    "sheets",
    "pairs",
    "sets",
    "boxes",
];

// ── Material search combobox ──────────────────────────────────────────────────

interface MaterialSearchProps {
    onSelect: (material: Material) => void;
    placeholder?: string;
}

function MaterialSearch({
    onSelect,
    placeholder = "Search materials…",
}: MaterialSearchProps) {
    const [query, setQuery] = useState("");
    const [open, setOpen] = useState(false);
    const [focused, setFocused] = useState(false);

    const { data, isFetching } = useQuery({
        queryKey: ["materials-search", query],
        queryFn: () => materialsSearchApi.search(query),
        enabled: query.length >= 1,
    });

    const materials = data?.data ?? [];

    return (
        <div className="relative">
            <div
                className={clsx(
                    "flex items-center gap-2 input py-0",
                    focused && "ring-2 ring-brand-300 border-brand-400",
                )}
            >
                <svg
                    className="w-4 h-4 text-surface-400 shrink-0"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                    />
                </svg>
                <input
                    className="flex-1 py-2 bg-transparent outline-none text-sm placeholder:text-surface-400"
                    placeholder={placeholder}
                    value={query}
                    onFocus={() => {
                        setFocused(true);
                        setOpen(true);
                    }}
                    onBlur={() => {
                        setFocused(false);
                        setTimeout(() => setOpen(false), 150);
                    }}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setOpen(true);
                    }}
                />
                {isFetching && <Spinner size="xs" />}
            </div>

            {open && query.length >= 1 && (
                <div className="absolute z-50 top-full left-0 right-0 mt-1 bg-white border border-surface-200 rounded-xl shadow-lg overflow-hidden max-h-52 overflow-y-auto">
                    {materials.length === 0 ? (
                        <p className="text-sm text-surface-400 text-center py-4">
                            {isFetching ? "Searching…" : "No materials found."}
                        </p>
                    ) : (
                        materials.map((m) => (
                            <button
                                key={m.id}
                                type="button"
                                onMouseDown={() => {
                                    onSelect(m);
                                    setQuery("");
                                    setOpen(false);
                                }}
                                className="w-full flex items-center gap-3 px-3 py-2.5 hover:bg-surface-50 text-left transition-colors border-b border-surface-50 last:border-0"
                            >
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-medium text-surface-900 truncate">
                                        {m.name}
                                    </p>
                                    <p className="text-xs text-surface-400">
                                        {m.code} · {m.material_type} ·{" "}
                                        {m.unit_of_measure}
                                    </p>
                                </div>
                                <div className="text-right shrink-0">
                                    <p className="text-xs font-mono text-surface-600">
                                        KES{" "}
                                        {Number(
                                            m.cost_per_unit,
                                        ).toLocaleString()}
                                        /{m.unit_of_measure}
                                    </p>
                                    {m.stock_quantity !== undefined && (
                                        <p
                                            className={clsx(
                                                "text-2xs",
                                                m.stock_quantity <= 0
                                                    ? "text-danger"
                                                    : "text-success",
                                            )}
                                        >
                                            {m.stock_quantity}{" "}
                                            {m.unit_of_measure} in stock
                                        </p>
                                    )}
                                </div>
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

// ── BOM line item row ─────────────────────────────────────────────────────────

interface LineItemRowProps {
    item: BomItem & { _key: string };
    index: number;
    onChange: (key: string, field: keyof BomItem, value: any) => void;
    onRemove: (key: string) => void;
}

function LineItemRow({ item, index, onChange, onRemove }: LineItemRowProps) {
    const lineCost = (item.quantity ?? 0) * (item.material?.cost_per_unit ?? 0);

    return (
        <tr className="border-b border-surface-50 hover:bg-surface-50/30 transition-colors group">
            {/* # */}
            <td className="px-3 py-2.5 text-xs text-surface-400 tabular-nums w-8">
                {index + 1}
            </td>

            {/* Material name */}
            <td className="px-3 py-2.5">
                {item.material ? (
                    <div>
                        <p className="text-sm font-medium text-surface-900">
                            {item.material.name}
                        </p>
                        <p className="text-xs text-surface-400">
                            {item.material.code} · {item.material.material_type}
                        </p>
                    </div>
                ) : (
                    <span className="text-sm text-surface-400 italic">
                        Unknown material
                    </span>
                )}
            </td>

            {/* Quantity */}
            <td className="px-3 py-2.5 w-28">
                <input
                    type="number"
                    min="0.001"
                    step="0.001"
                    className="input text-sm py-1 text-right"
                    value={item.quantity}
                    onChange={(e) =>
                        onChange(
                            item._key,
                            "quantity",
                            parseFloat(e.target.value) || 0,
                        )
                    }
                />
            </td>

            {/* UOM */}
            <td className="px-3 py-2.5 w-28">
                <select
                    className="input text-sm py-1"
                    value={item.unit_of_measure}
                    onChange={(e) =>
                        onChange(item._key, "unit_of_measure", e.target.value)
                    }
                >
                    {UOM_OPTIONS.map((u) => (
                        <option key={u} value={u}>
                            {u}
                        </option>
                    ))}
                    {!UOM_OPTIONS.includes(item.unit_of_measure) && (
                        <option value={item.unit_of_measure}>
                            {item.unit_of_measure}
                        </option>
                    )}
                </select>
            </td>

            {/* Line cost */}
            <td className="px-3 py-2.5 w-32 text-right">
                <p className="text-sm font-medium text-surface-800">
                    KES{" "}
                    {lineCost.toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    })}
                </p>
                <p className="text-2xs text-surface-400">
                    @{" "}
                    {Number(item.material?.cost_per_unit ?? 0).toLocaleString()}
                    /{item.unit_of_measure}
                </p>
            </td>

            {/* Notes */}
            <td className="px-3 py-2.5">
                <input
                    type="text"
                    className="input text-sm py-1"
                    placeholder="Optional note…"
                    value={item.notes ?? ""}
                    onChange={(e) =>
                        onChange(item._key, "notes", e.target.value)
                    }
                />
            </td>

            {/* Remove */}
            <td className="px-3 py-2.5 w-10">
                <button
                    type="button"
                    onClick={() => onRemove(item._key)}
                    className="w-6 h-6 rounded-full flex items-center justify-center text-surface-300 hover:text-danger hover:bg-danger-light transition-colors opacity-0 group-hover:opacity-100"
                    aria-label="Close"
                >
                    <svg
                        className="w-3.5 h-3.5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={2.5}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </td>
        </tr>
    );
}

// ── Feasibility panel ─────────────────────────────────────────────────────────

function FeasibilityPanel({ productId, bom }: { productId: number; bom: Bom }) {
    const [qty, setQty] = useState(1);

    const { data, isFetching, refetch } = useQuery({
        queryKey: ["bom-feasibility", bom.id, qty],
        queryFn: () => bomApi.feasibility(productId, bom.id, qty),
        enabled: false,
    });

    return (
        <div className="border border-surface-100 rounded-xl overflow-hidden">
            <div className="flex items-center justify-between px-4 py-3 bg-surface-50 border-b border-surface-100">
                <p className="text-sm font-semibold text-surface-800">
                    Production Feasibility Check
                </p>
                <div className="flex items-center gap-2">
                    <span className="text-xs text-surface-500">Units:</span>
                    <input
                        type="number"
                        min="1"
                        className="input text-sm py-1 w-20 text-center"
                        value={qty}
                        onChange={(e) =>
                            setQty(Math.max(1, parseInt(e.target.value) || 1))
                        }
                    />
                    <button
                        onClick={() => refetch()}
                        disabled={isFetching}
                        className="btn-secondary btn-sm text-xs"
                    >
                        {isFetching ? <Spinner size="xs" /> : null}
                        Check
                    </button>
                </div>
            </div>

            {data && (
                <div className="p-4">
                    {/* Summary banner */}
                    <div
                        className={clsx(
                            "flex items-center gap-2 rounded-lg px-3 py-2.5 mb-4 text-sm font-medium",
                            data.feasible
                                ? "bg-success-light text-success"
                                : "bg-danger-light text-danger",
                        )}
                    >
                        <span className="text-lg">
                            {data.feasible ? "✓" : "✕"}
                        </span>
                        {data.summary}
                    </div>

                    {/* Shortfalls */}
                    {data.shortfalls.length > 0 && (
                        <div className="space-y-2">
                            <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Materials short:
                            </p>
                            {data.shortfalls.map((s) => (
                                <div
                                    key={s.material_id}
                                    className="flex items-center justify-between text-sm bg-danger-light/50 rounded-lg px-3 py-2"
                                >
                                    <span className="font-medium text-surface-800">
                                        {s.material_name}
                                    </span>
                                    <div className="text-right text-xs">
                                        <p className="text-danger font-medium">
                                            Short {s.shortfall.toLocaleString()}{" "}
                                            {s.uom}
                                        </p>
                                        <p className="text-surface-500">
                                            Need {s.required.toLocaleString()} ·
                                            Have {s.available.toLocaleString()}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

// ── Main BomTab ───────────────────────────────────────────────────────────────

interface BomTabProps {
    productId: number;
    variants: { id: number; variant_name: string; sku: string }[];
}

type ItemWithKey = BomItem & { _key: string };

export default function BomTab({ productId, variants }: BomTabProps) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const canEdit = can("products.edit");

    const [activeBomId, setActiveBomId] = useState<number | null>(null);
    const [editing, setEditing] = useState(false);
    /** The id of the BOM currently being edited - null means creating a NEW version */
    const [editingBomId, setEditingBomId] = useState<number | null>(null);
    const [notes, setNotes] = useState("");
    const [variantId, setVariantId] = useState<number | null>(null);
    const [lineItems, setLineItems] = useState<ItemWithKey[]>([]);
    const [showHistory, setShowHistory] = useState(false);

    // ── Data ──────────────────────────────────────────────────────────────────

    const { data, isLoading } = useQuery({
        queryKey: ["bom", productId],
        queryFn: () => bomApi.list(productId),
    });

    const boms = data?.data ?? [];
    const activeBom = boms.find((b) => b.is_active) ?? boms[0] ?? null;
    const viewingBom = activeBomId
        ? boms.find((b) => b.id === activeBomId)
        : activeBom;

    // Open editor - pass a specific bom to EDIT it, or nothing to CREATE a new version
    const openEditor = useCallback(
        (bom?: Bom) => {
            if (bom) {
                // Editing an existing BOM
                setEditingBomId(bom.id);
                setNotes(bom.notes ?? "");
                setVariantId(bom.product_variant_id ?? null);
                setLineItems(
                    bom.items.map((item, i) => ({
                        ...item,
                        _key: `existing-${item.id ?? i}`,
                    }))
                );
            } else {
                // Creating a NEW BOM version - start blank
                setEditingBomId(null);
                setNotes("");
                setVariantId(activeBom?.product_variant_id ?? null);
                setLineItems([]);
            }
            setEditing(true);
        },
        [activeBom],
    );

    // ── Line item handlers ────────────────────────────────────────────────────

    const addMaterial = useCallback(
        (material: Material) => {
            setLineItems((prev) => {
                if (prev.some((item) => item.material_id === material.id)) {
                    toast.error(`${material.name} is already in the BOM.`);
                    return prev;
                }
                return [
                    ...prev,
                    {
                        _key: `new-${Date.now()}`,
                        material_id: material.id,
                        quantity: 1,
                        unit_of_measure: material.unit_of_measure,
                        notes: "",
                        material: {
                            id: material.id,
                            code: material.code,
                            name: material.name,
                            material_type: material.material_type,
                            unit_of_measure: material.unit_of_measure,
                            cost_per_unit: material.cost_per_unit,
                        },
                        line_cost: material.cost_per_unit,
                    },
                ];
            });
        },
        [toast],
    );

    const updateItem = useCallback(
        (key: string, field: keyof BomItem, value: any) => {
            setLineItems((prev) =>
                prev.map((item) =>
                    item._key === key ? { ...item, [field]: value } : item,
                ),
            );
        },
        [],
    );

    const removeItem = useCallback((key: string) => {
        setLineItems((prev) => prev.filter((item) => item._key !== key));
    }, []);

    // ── Save mutation ─────────────────────────────────────────────────────────

    const saveMutation = useMutation({
        mutationFn: () => {
            const payload = {
                notes: notes || undefined,
                product_variant_id: variantId,
                items: lineItems.map((item) => ({
                    material_id: item.material_id,
                    quantity: item.quantity,
                    unit_of_measure: item.unit_of_measure,
                    notes: item.notes || undefined,
                })),
            };
            // If editing an existing BOM - update it in place
            if (editingBomId !== null) {
                return bomApi.update(productId, editingBomId, payload);
            }
            // Otherwise create new version
            return bomApi.save(productId, payload);
        },
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["bom", productId] });
            qc.invalidateQueries({ queryKey: ["product", String(productId)] });
            toast.success("BOM saved.");
            setEditing(false);
            setEditingBomId(null);
            setActiveBomId(res.bom.id);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (bomId: number) => bomApi.delete(productId, bomId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["bom", productId] });
            toast.success("BOM deleted.");
            setActiveBomId(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const activateMutation = useMutation({
        mutationFn: (bomId: number) => bomApi.activate(productId, bomId),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["bom", productId] });
            toast.success("BOM version activated.");
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    // ── Cost summary ──────────────────────────────────────────────────────────

    const totalCost = lineItems.reduce(
        (sum, item) =>
            sum + (item.quantity ?? 0) * (item.material?.cost_per_unit ?? 0),
        0,
    );
    const itemCount = lineItems.length;

    // ── Render ────────────────────────────────────────────────────────────────

    if (isLoading)
        return (
            <div className="flex justify-center py-12">
                <Spinner size="lg" />
            </div>
        );

    // ── Empty state ───────────────────────────────────────────────────────────
    if (!editing && boms.length === 0) {
        return (
            <div className="card">
                <div className="card-body text-center py-12">
                    <div className="w-16 h-16 rounded-2xl bg-surface-100 flex items-center justify-center mx-auto mb-4">
                        <svg
                            className="w-8 h-8 text-surface-300"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={1.5}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"
                            />
                        </svg>
                    </div>
                    <h3 className="text-sm font-semibold text-surface-700 mb-1">
                        No Bill of Materials
                    </h3>
                    <p className="text-xs text-surface-400 max-w-xs mx-auto mb-4">
                        A BOM lists the raw materials needed to produce this
                        product. It feeds directly into production orders and
                        stock calculations.
                    </p>
                    {canEdit && (
                    <button
                        onClick={() => openEditor()}
                        className="btn-primary btn-sm"
                    >
                        Create BOM
                    </button>
                    )}
                </div>
            </div>
        );
    }

    // ── Editor mode ───────────────────────────────────────────────────────────
    if (editing) {
        return (
            <div className="space-y-3">
                {/* Editor header */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 className="text-sm font-semibold text-surface-900">
                            {editingBomId !== null
                                ? `Editing BOM v${boms.find(b => b.id === editingBomId)?.version ?? "?"}`
                                : "New BOM Version"}
                        </h3>
                        <p className="text-xs text-surface-400 mt-0.5">
                            Add materials and set quantities required per unit
                            produced.
                        </p>
                    </div>
                    <div className="flex gap-2 shrink-0">
                        <button
                            onClick={() => { setEditing(false); setEditingBomId(null); }}
                            className="btn-secondary btn-sm flex-1 sm:flex-none"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={() => saveMutation.mutate()}
                            disabled={
                                saveMutation.isPending || lineItems.length === 0
                            }
                            className="btn-primary btn-sm flex-1 sm:flex-none"
                        >
                            {saveMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Save BOM
                        </button>
                    </div>
                </div>

                {/* Variant selector (if variable product) */}
                {variants.length > 0 && (
                    <div className="card">
                        <div className="card-body">
                            <Field
                                label="Applies to variant"
                                hint="Leave empty to apply to all variants"
                            >
                                <FieldSelect
                                    className="input text-sm"
                                    value={variantId ?? ""}
                                    onChange={(e) =>
                                        setVariantId(
                                            e.target.value
                                                ? Number(e.target.value)
                                                : null,
                                        )
                                    }
                                >
                                    <option value="">
                                        - All variants (base product) -
                                    </option>
                                    {variants.map((v) => (
                                        <option key={v.id} value={v.id}>
                                            {v.variant_name} ({v.sku})
                                        </option>
                                    ))}
                                </FieldSelect>
                            </Field>
                        </div>
                    </div>
                )}

                {/* Add material - appears first, above the table */}
                <div className="card">
                    <div className="card-body">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            Add Material
                        </p>
                        <MaterialSearch
                            onSelect={addMaterial}
                            placeholder="Type material name or code to search…"
                        />
                    </div>
                </div>

                {/* Materials table */}
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[560px]">
                        <thead>
                            <tr className="border-b border-surface-100 bg-surface-50/50">
                                <th className="px-3 py-2.5 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider w-8">
                                    #
                                </th>
                                <th className="px-3 py-2.5 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Material
                                </th>
                                <th className="px-3 py-2.5 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider w-28">
                                    Qty
                                </th>
                                <th className="px-3 py-2.5 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider w-28">
                                    UOM
                                </th>
                                <th className="px-3 py-2.5 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider w-32">
                                    Line Cost
                                </th>
                                <th className="px-3 py-2.5 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Notes
                                </th>
                                <th className="px-3 py-2.5 w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            {lineItems.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-3 py-8 text-center text-sm text-surface-400"
                                    >
                                        No materials added yet - search above to add.
                                    </td>
                                </tr>
                            ) : (
                                lineItems.map((item, i) => (
                                    <LineItemRow
                                        key={item._key}
                                        item={item}
                                        index={i}
                                        onChange={updateItem}
                                        onRemove={removeItem}
                                    />
                                ))
                            )}
                        </tbody>
                        {lineItems.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 border-surface-100 bg-surface-50">
                                    <td
                                        colSpan={4}
                                        className="px-3 py-2.5 text-xs text-surface-500"
                                    >
                                        {itemCount} material
                                        {itemCount !== 1 ? "s" : ""}
                                    </td>
                                    <td className="px-3 py-2.5 text-right">
                                        <p className="text-sm font-bold text-surface-900">
                                            KES{" "}
                                            {totalCost.toLocaleString(
                                                undefined,
                                                {
                                                    minimumFractionDigits: 2,
                                                    maximumFractionDigits: 2,
                                                },
                                            )}
                                        </p>
                                        <p className="text-2xs text-surface-400">
                                            per unit
                                        </p>
                                    </td>
                                    <td colSpan={2} />
                                </tr>
                            </tfoot>
                        )}
                    </table>
                    </div>
                </div>

                {/* Notes */}
                <div className="card">
                    <div className="card-body">
                        <Field
                            label="BOM Notes"
                            hint="Internal notes about this bill of materials"
                        >
                            <FieldTextarea
                                className="input resize-none"
                                rows={2}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="e.g. Standard cut for size M. Adjust fabric by ±0.2m for other sizes."
                            />
                        </Field>
                    </div>
                </div>
            </div>
        );
    }

    // ── View mode ─────────────────────────────────────────────────────────────
    return (
        <div className="space-y-3">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div className="flex items-center gap-2">
                        <h3 className="text-sm font-semibold text-surface-900">
                            Bill of Materials
                        </h3>
                        {viewingBom && (
                            <span
                                className={clsx(
                                    "text-2xs font-medium px-2 py-0.5 rounded-full",
                                    viewingBom.is_active
                                        ? "bg-success-light text-success"
                                        : "bg-surface-100 text-surface-500",
                                )}
                            >
                                v{viewingBom.version}{" "}
                                {viewingBom.is_active ? "· Active" : ""}
                            </span>
                        )}
                    </div>
                    {viewingBom?.variant && (
                        <p className="text-xs text-surface-400 mt-0.5">
                            Variant: {viewingBom.variant.variant_name}
                        </p>
                    )}
                </div>
                <div className="flex gap-2 shrink-0">
                    {boms.length > 1 && (
                        <button
                            onClick={() => setShowHistory(!showHistory)}
                            className="btn-ghost btn-sm text-xs"
                        >
                            {showHistory ? "Hide" : "Version History"} (
                            {boms.length})
                        </button>
                    )}
                    {viewingBom && !viewingBom.is_active && canEdit && (
                        <button
                            onClick={() =>
                                activateMutation.mutate(viewingBom.id)
                            }
                            disabled={activateMutation.isPending}
                            className="btn-secondary btn-sm text-xs"
                        >
                            Set Active
                        </button>
                    )}
                    {viewingBom && canEdit && (
                        <button
                            onClick={() => openEditor(viewingBom)}
                            className="btn-secondary btn-sm"
                        >
                            Edit BOM
                        </button>
                    )}
                    {canEdit && (
                    <button
                        onClick={() => openEditor()}
                        className="btn-primary btn-sm"
                    >
                        + New Version
                    </button>
                    )}
                </div>
            </div>

            {/* Version history */}
            {showHistory && boms.length > 1 && (
                <div className="card overflow-hidden">
                    <div className="px-4 py-2.5 border-b border-surface-100 bg-surface-50">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                            Version History
                        </p>
                    </div>
                    <div className="divide-y divide-surface-50">
                        {boms.map((bom) => (
                            <div
                                key={bom.id}
                                className={clsx(
                                    "flex flex-col gap-2 px-4 py-2.5 transition-colors sm:flex-row sm:items-center sm:justify-between",
                                    activeBomId === bom.id
                                        ? "bg-brand-50"
                                        : "hover:bg-surface-50",
                                )}
                            >
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="text-sm font-medium text-surface-800">
                                        Version {bom.version}
                                    </span>
                                    {bom.is_active && (
                                        <span className="badge badge-success text-2xs">
                                            Active
                                        </span>
                                    )}
                                    <span className="text-xs text-surface-400">
                                        {bom.items_count} materials · KES{" "}
                                        {Number(
                                            bom.total_cost,
                                        ).toLocaleString()}
                                    </span>
                                </div>
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="text-2xs text-surface-400">
                                        {new Date(
                                            bom.created_at,
                                        ).toLocaleDateString("en-GB", {
                                            day: "numeric",
                                            month: "short",
                                            year: "numeric",
                                        })}
                                    </span>
                                    <button
                                        onClick={() => setActiveBomId(bom.id)}
                                        className="btn-ghost btn-sm text-xs"
                                    >
                                        View
                                    </button>
                                    {!bom.is_active && canEdit && (
                                        <button
                                            onClick={() =>
                                                activateMutation.mutate(bom.id)
                                            }
                                            disabled={
                                                activateMutation.isPending
                                            }
                                            className="btn-ghost btn-sm text-xs text-brand-600"
                                        >
                                            Activate
                                        </button>
                                    )}
                                    {!bom.is_active && canEdit && (
                                        <button
                                            onClick={() =>
                                                deleteMutation.mutate(bom.id)
                                            }
                                            disabled={deleteMutation.isPending}
                                            className="btn-ghost btn-sm text-xs text-danger"
                                        >
                                            Delete
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* BOM items table */}
            {viewingBom && (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[480px]">
                        <thead>
                            <tr className="border-b border-surface-100 bg-surface-50/50">
                                <th className="px-3 py-2.5 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider w-8">
                                    #
                                </th>
                                <th className="px-3 py-2.5 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Material
                                </th>
                                <th className="px-3 py-2.5 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Quantity
                                </th>
                                <th className="px-3 py-2.5 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    UOM
                                </th>
                                <th className="px-3 py-2.5 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Unit Cost
                                </th>
                                <th className="px-3 py-2.5 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Line Cost
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {viewingBom.items.map((item, i) => (
                                <tr
                                    key={item.id ?? i}
                                    className="hover:bg-surface-50/50"
                                >
                                    <td className="px-3 py-2.5 text-xs text-surface-400">
                                        {i + 1}
                                    </td>
                                    <td className="px-3 py-2.5">
                                        <p className="text-sm font-medium text-surface-900">
                                            {item.material?.name}
                                        </p>
                                        <p className="text-xs text-surface-400">
                                            {item.material?.code} ·{" "}
                                            {item.material?.material_type}
                                        </p>
                                        {item.notes && (
                                            <p className="text-xs text-surface-400 italic mt-0.5">
                                                {item.notes}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-3 py-2.5 text-right text-sm font-medium text-surface-800 tabular-nums">
                                        {Number(item.quantity).toLocaleString()}
                                    </td>
                                    <td className="px-3 py-2.5 text-sm text-surface-600">
                                        {item.unit_of_measure}
                                    </td>
                                    <td className="px-3 py-2.5 text-right text-sm text-surface-600 tabular-nums">
                                        {Number(
                                            item.material?.cost_per_unit ?? 0,
                                        ).toLocaleString()}
                                    </td>
                                    <td className="px-3 py-2.5 text-right text-sm font-semibold text-surface-900 tabular-nums">
                                        {(
                                            (item.quantity ?? 0) *
                                            (item.material?.cost_per_unit ?? 0)
                                        ).toLocaleString(undefined, {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2,
                                        })}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t-2 border-surface-100 bg-surface-50">
                                <td
                                    colSpan={5}
                                    className="px-3 py-2.5 text-xs text-surface-500"
                                >
                                    {viewingBom.items_count} material
                                    {viewingBom.items_count !== 1 ? "s" : ""}
                                    {viewingBom.notes && (
                                        <span className="ml-2 italic text-surface-400">
                                            · {viewingBom.notes}
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2.5 text-right">
                                    <p className="text-sm font-bold text-surface-900">
                                        KES{" "}
                                        {Number(
                                            viewingBom.total_cost,
                                        ).toLocaleString(undefined, {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2,
                                        })}
                                    </p>
                                    <p className="text-2xs text-surface-400">
                                        material cost / unit
                                    </p>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            )}

            {/* Feasibility check */}
            {viewingBom && viewingBom.items_count > 0 && (
                <FeasibilityPanel productId={productId} bom={viewingBom} />
            )}
        </div>
    );
}