import { useState, useCallback, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { rawMaterialsApi } from "@/api/rawMaterials";
import type {
    RawMaterial,
    MaterialTransaction,
    BomUsage,
} from "@/api/rawMaterials";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import {
    Field, useFieldAriaProps,
    Toggle,
    StatusBadge,
    ConfirmDialog,
    FieldInput, FieldSelect, FieldTextarea
} from "@/components/setup/FormComponents";
import { get } from "@/api/client";
import type { ApiError } from "@/types";
import { clsx } from "clsx";

// ── Helpers ───────────────────────────────────────────────────────────────────

const STATUS_CONFIG = {
    in_stock: {
        label: "In Stock",
        bg: "bg-success-light",
        text: "text-success",
    },
    low_stock: {
        label: "Low Stock",
        bg: "bg-warning-light",
        text: "text-warning",
    },
    out_of_stock: {
        label: "Out of Stock",
        bg: "bg-danger-light",
        text: "text-danger",
    },
} as const;

const TX_COLORS: Record<string, string> = {
    purchase: "text-success",
    opening_stock: "text-brand-600",
    adjustment: "text-warning",
    production_use: "text-danger",
    production_return: "text-success",
    damaged: "text-danger",
    correction: "text-surface-600",
    transfer_in: "text-success",
    transfer_out: "text-danger",
};

const MATERIAL_TYPES = [
    "Fabric",
    "Lining",
    "Interfacing",
    "Thread",
    "Buttons",
    "Zippers",
    "Elastic",
    "Trim",
    "Packaging",
    "Labels",
    "Other",
];

const UNITS = [
    "meters",
    "yards",
    "kg",
    "g",
    "pcs",
    "rolls",
    "spools",
    "boxes",
    "pairs",
    "sets",
    "liters",
    "ml",
];

// ── Stock bar ─────────────────────────────────────────────────────────────────

function StockBar({ material }: { material: RawMaterial }) {
    if (material.reorder_point === 0) return null;
    const pct = Math.min(
        100,
        (material.total_stock / Math.max(material.reorder_point * 2, 1)) * 100,
    );
    return (
        <div className="w-20 h-1.5 bg-surface-100 rounded-full overflow-hidden">
            <div
                className={clsx(
                    "h-full rounded-full transition-all",
                    material.stock_status === "out_of_stock"
                        ? "bg-danger"
                        : material.stock_status === "low_stock"
                          ? "bg-warning"
                          : "bg-success",
                )}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

// ── Code generator ────────────────────────────────────────────────────────────

function generateMaterialCode(category: string, name: string): string {
    // Category prefix - first 3 letters of first word, uppercase
    const catPrefix = category
        ? category
              .replace(/[^a-zA-Z\s]/g, "")
              .split(/\s+/)[0]
              .slice(0, 3)
              .toUpperCase()
        : "MAT";

    // Name initials - up to 3 words
    const nameCode = name
        ? name
              .replace(/[^a-zA-Z\s]/g, "")
              .split(/\s+/)
              .slice(0, 3)
              .map((w) => w[0])
              .join("")
              .toUpperCase()
        : "MAT";

    return `${catPrefix}-${nameCode}`;
}

// ── Material form modal ───────────────────────────────────────────────────────

const materialSchema = z.object({
    code: z.string().min(1, "Code required"),
    name: z.string().min(1, "Name required"),
    description: z.string().optional(),
    category: z.string().optional(),
    unit_of_measure: z.string().min(1, "UOM required"),
    unit_cost: z.coerce.number().min(0, "Cost must be ≥ 0"),
    reorder_point: z.coerce.number().min(0).optional(),
    is_active: z.boolean(),
});
type MaterialForm = z.infer<typeof materialSchema>;

function MaterialFormModal({
    open,
    onClose,
    editing,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    editing: RawMaterial | null;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const [codeManual, setCodeManual] = useState(false);

    const form = useForm<MaterialForm>({
        resolver: zodResolver(materialSchema),
        defaultValues: {
            code: "",
            name: "",
            description: "",
            category: "",
            unit_of_measure: "",
            unit_cost: 0,
            reorder_point: 0,
            is_active: true,
        },
    });
    const {
        register,
        handleSubmit,
        watch,
        setValue,
        reset,
        formState: { errors },
    } = form;

    // Reset form when modal opens
    useEffect(() => {
        if (!open) return;
        if (editing) {
            reset({
                code: editing.code,
                name: editing.name,
                description: editing.description ?? "",
                category: editing.category ?? "",
                unit_of_measure: editing.unit_of_measure,
                unit_cost: editing.unit_cost,
                reorder_point: editing.reorder_point,
                is_active: editing.is_active,
            });
            setCodeManual(true); // existing codes are locked to manual
        } else {
            reset({
                code: "",
                name: "",
                description: "",
                category: "",
                unit_of_measure: "",
                unit_cost: 0,
                reorder_point: 0,
                is_active: true,
            });
            setCodeManual(false);
        }
    }, [open, editing, reset]);

    // Auto-generate code from category + name when not in manual mode
    const watchedName = watch("name");
    const watchedCategory = watch("category");

    useEffect(() => {
        if (codeManual || editing) return;
        if (!watchedName && !watchedCategory) return;
        const base = generateMaterialCode(
            watchedCategory ?? "",
            watchedName ?? "",
        );
        setValue("code", base, { shouldValidate: false });
    }, [watchedName, watchedCategory, codeManual, editing, setValue]);

    const mutation = useMutation({
        mutationFn: (v: MaterialForm) => {
            const payload = { ...v, code: v.code.toUpperCase() };
            return editing
                ? rawMaterialsApi.update(editing.id, payload)
                : rawMaterialsApi.create(payload);
        },
        onSuccess: () => {
            toast.success(editing ? "Material updated." : "Material created.");
            onSaved();
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={editing ? `Edit - ${editing.name}` : "New Raw Material"}
            size="md"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={handleSubmit((v) => mutation.mutate(v))}
                        disabled={mutation.isPending}
                        className="btn-primary btn-sm"
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        {editing ? "Save" : "Create Material"}
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {/* Name - comes before Code so auto-gen triggers work */}
                    <Field label="Name" error={errors.name?.message} required>
                        <FieldInput
                            className="input"
                            {...register("name")}
                            placeholder="e.g. Ankara Wax Print"
                            autoFocus={!editing}
                        />
                    </Field>

                    {/* Category - also feeds into code generation */}
                    <Field label="Category">
                        <FieldSelect className="input" {...register("category")}>
                            <option value="">- Select category -</option>
                            {MATERIAL_TYPES.map((t) => (
                                <option key={t} value={t}>
                                    {t}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>

                    {/* Code - auto-generated, overridable */}
                    <Field
                        label="Code"
                        error={errors.code?.message}
                        required
                        hint={
                            !editing && !codeManual
                                ? "Auto-generated · type to override"
                                : undefined
                        }
                    >
                        <div className="flex gap-1.5">
                            <FieldInput
                                className={`input font-mono uppercase flex-1 ${errors.code ? "input-error" : ""}`}
                                {...register("code")}
                                placeholder="FAB-AWP"
                                onChange={(e) => {
                                    setValue(
                                        "code",
                                        e.target.value.toUpperCase(),
                                    );
                                    setCodeManual(true);
                                }}
                            />
                            {!editing && codeManual && (
                                <button
                                    type="button"
                                    title="Re-generate from name and category"
                                    onClick={() => {
                                        setCodeManual(false);
                                        setValue(
                                            "code",
                                            generateMaterialCode(
                                                watchedCategory ?? "",
                                                watchedName ?? "",
                                            ),
                                        );
                                    }}
                                    className="px-2 rounded-lg border border-surface-200 text-surface-400 hover:text-brand-600 hover:border-brand-300 transition-colors text-xs"
                                >
                                    ↺
                                </button>
                            )}
                        </div>
                        {!editing && !codeManual && watchedName && (
                            <p className="text-2xs text-surface-400 mt-1">
                                Generated as{" "}
                                <span className="font-mono font-medium text-brand-600">
                                    {watch("code")}
                                </span>
                            </p>
                        )}
                    </Field>

                    <Field label="Unit of Measure" required>
                        <FieldSelect
                            className="input"
                            {...register("unit_of_measure")}
                        >
                            <option value="">- Select UOM -</option>
                            {UNITS.map((u) => (
                                <option key={u} value={u}>
                                    {u}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>

                    <Field label="Cost per Unit (KES)" required>
                        <FieldInput
                            className="input"
                            type="number"
                            step="0.01"
                            min="0"
                            {...register("unit_cost")}
                        />
                    </Field>

                    <Field
                        label="Reorder Point"
                        hint="Alert when stock falls to this level"
                    >
                        <FieldInput
                            className="input"
                            type="number"
                            step="0.01"
                            min="0"
                            {...register("reorder_point")}
                            placeholder="0"
                        />
                    </Field>
                </div>

                <Field label="Description">
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        {...register("description")}
                        placeholder="Optional description…"
                    />
                </Field>

                <Toggle
                    checked={watch("is_active")}
                    onChange={(v) => setValue("is_active", v)}
                    label="Active"
                    description="Inactive materials are hidden from BOM and production forms."
                />
            </div>
        </Modal>
    );
}

// ── Receive stock modal ───────────────────────────────────────────────────────

function ReceiveModal({
    material,
    onClose,
}: {
    material: RawMaterial;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [outletId, setOutletId] = useState<number | "">("");
    const [quantity, setQuantity] = useState<number>(1);
    const [txType, setTxType] = useState<
        "opening_stock" | "purchase" | "adjustment" | "transfer_in"
    >("purchase");
    const [unitCost, setUnitCost] = useState<number>(material.unit_cost);
    const [notes, setNotes] = useState("");
    const [reference, setReference] = useState("");

    const { data: outletsData } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => get<any>("/v1/admin/outlets"),
    });
    const outlets = Array.isArray(outletsData)
        ? outletsData
        : (outletsData?.data ?? []);

    const mutation = useMutation({
        mutationFn: () =>
            rawMaterialsApi.receive(material.id, {
                outlet_id: Number(outletId),
                quantity,
                transaction_type: txType,
                unit_cost: unitCost || undefined,
                notes: notes || undefined,
                reference: reference || undefined,
            }),
        onSuccess: (res) => {
            toast.success(res.message);
            qc.invalidateQueries({ queryKey: ["materials"] });
            qc.invalidateQueries({ queryKey: ["material", material.id] });
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const newTotal = material.total_stock + quantity;

    return (
        <Modal
            open
            onClose={onClose}
            title={`Receive Stock - ${material.name}`}
            size="sm"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={
                            !outletId || quantity <= 0 || mutation.isPending
                        }
                        className="btn-primary btn-sm"
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        Receive Stock
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                {/* Current stock */}
                <div className="grid grid-cols-3 gap-2 text-center">
                    {[
                        {
                            label: "Current",
                            value: `${material.total_stock} ${material.unit_of_measure}`,
                            color: "text-surface-700",
                        },
                        {
                            label: "Receiving",
                            value: `+${quantity} ${material.unit_of_measure}`,
                            color: "text-success",
                        },
                        {
                            label: "New Total",
                            value: `${newTotal} ${material.unit_of_measure}`,
                            color: "text-brand-600",
                        },
                    ].map((s) => (
                        <div
                            key={s.label}
                            className="bg-surface-50 rounded-xl py-2.5"
                        >
                            <p className={clsx("text-base font-bold", s.color)}>
                                {s.value}
                            </p>
                            <p className="text-2xs text-surface-400 mt-0.5">
                                {s.label}
                            </p>
                        </div>
                    ))}
                </div>

                <Field label="Outlet / Location" required>
                    <FieldSelect
                        className="input"
                        value={outletId}
                        onChange={(e) => setOutletId(Number(e.target.value))}
                    >
                        <option value="">- Select outlet -</option>
                        {outlets.map((o: any) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </FieldSelect>
                </Field>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field
                        label={`Quantity (${material.unit_of_measure})`}
                        required
                    >
                        <FieldInput
                            className="input"
                            type="number"
                            step="0.001"
                            min="0.001"
                            value={quantity}
                            onChange={(e) =>
                                setQuantity(parseFloat(e.target.value) || 0)
                            }
                        />
                    </Field>
                    <Field label="Unit Cost (KES)">
                        <FieldInput
                            className="input"
                            type="number"
                            step="0.01"
                            min="0"
                            value={unitCost}
                            onChange={(e) =>
                                setUnitCost(parseFloat(e.target.value) || 0)
                            }
                        />
                    </Field>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field label="Transaction Type">
                        <FieldSelect
                            className="input"
                            value={txType}
                            onChange={(e) => setTxType(e.target.value as any)}
                        >
                            <option value="purchase">Purchase Receipt</option>
                            <option value="opening_stock">Opening Stock</option>
                            <option value="adjustment">Adjustment</option>
                            <option value="transfer_in">Transfer In</option>
                        </FieldSelect>
                    </Field>
                    <Field label="Reference #" hint="PO number, delivery note…">
                        <FieldInput
                            className="input"
                            value={reference}
                            onChange={(e) => setReference(e.target.value)}
                            placeholder="Optional"
                        />
                    </Field>
                </div>
                <Field label="Notes">
                    <FieldInput
                        className="input"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Optional notes…"
                    />
                </Field>
            </div>
        </Modal>
    );
}

// ── Adjust modal ──────────────────────────────────────────────────────────────

function AdjustModal({
    material,
    onClose,
}: {
    material: RawMaterial;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [outletId, setOutletId] = useState<number | "">("");
    const [quantityChange, setQtyChange] = useState<number>(-1);
    const [txType, setTxType] = useState<
        "adjustment" | "damaged" | "correction" | "transfer_out"
    >("adjustment");
    const [notes, setNotes] = useState("");

    const { data: outletsData } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => get<any>("/v1/admin/outlets"),
    });
    const outlets = Array.isArray(outletsData)
        ? outletsData
        : (outletsData?.data ?? []);

    // Fetch live detail so we get inventory per outlet - the list item never has inventory[]
    const { data: detailData, isLoading: loadingDetail } = useQuery({
        queryKey: ["material", material.id],
        queryFn: () => rawMaterialsApi.get(material.id),
    });
    const inventory = detailData?.material?.inventory ?? [];

    // Current stock at the selected outlet from the live detail
    const outletStock = outletId
        ? (inventory.find((inv) => inv.outlet_id === Number(outletId))
              ?.quantity_on_hand ?? 0)
        : 0;
    const newQty = outletStock + quantityChange;
    const wouldGoNeg = newQty < 0;

    const mutation = useMutation({
        mutationFn: () =>
            rawMaterialsApi.adjust(material.id, {
                outlet_id: Number(outletId),
                quantity_change: quantityChange,
                transaction_type: txType,
                notes,
            }),
        onSuccess: () => {
            toast.success("Stock adjusted.");
            qc.invalidateQueries({ queryKey: ["materials"] });
            qc.invalidateQueries({ queryKey: ["material", material.id] });
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={`Adjust Stock - ${material.name}`}
            size="sm"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">
                        Cancel
                    </button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={
                            !outletId ||
                            !notes.trim() ||
                            mutation.isPending ||
                            wouldGoNeg
                        }
                        className="btn-primary btn-sm"
                    >
                        {mutation.isPending && (
                            <Spinner
                                size="xs"
                                className="border-white/30 border-t-white"
                            />
                        )}
                        Apply Adjustment
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Outlet" required>
                    {loadingDetail ? (
                        <div className="input flex items-center gap-2 text-surface-400">
                            <Spinner size="xs" /> Loading stock data…
                        </div>
                    ) : (
                        <FieldSelect
                            className="input"
                            value={outletId}
                            onChange={(e) =>
                                setOutletId(Number(e.target.value))
                            }
                        >
                            <option value="">- Select outlet -</option>
                            {outlets.map((o: any) => {
                                const inv = inventory.find(
                                    (i) => i.outlet_id === o.id,
                                );
                                return (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                        {inv !== undefined
                                            ? ` - ${inv.quantity_on_hand} ${material.unit_of_measure}`
                                            : " - no stock"}
                                    </option>
                                );
                            })}
                        </FieldSelect>
                    )}
                </Field>
                {outletId && !loadingDetail && (
                    <div className="flex items-center justify-between text-sm bg-surface-50 rounded-lg px-3 py-2.5">
                        <span className="text-surface-500">Current stock:</span>
                        <span
                            className={clsx(
                                "font-bold tabular-nums",
                                outletStock === 0
                                    ? "text-danger"
                                    : "text-success",
                            )}
                        >
                            {outletStock} {material.unit_of_measure}
                        </span>
                    </div>
                )}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field label="Quantity Change" hint="Negative to decrease">
                        <FieldInput
                            className={`input ${wouldGoNeg ? "input-error" : ""}`}
                            type="number"
                            step="0.001"
                            value={quantityChange}
                            onChange={(e) =>
                                setQtyChange(parseFloat(e.target.value) || 0)
                            }
                        />
                    </Field>
                    <Field label="Reason">
                        <FieldSelect
                            className="input"
                            value={txType}
                            onChange={(e) => setTxType(e.target.value as any)}
                        >
                            <option value="adjustment">
                                Manual Adjustment
                            </option>
                            <option value="damaged">Damaged / Scrapped</option>
                            <option value="correction">
                                Stock Count Correction
                            </option>
                            <option value="transfer_out">Transfer Out</option>
                        </FieldSelect>
                    </Field>
                </div>
                {outletId && quantityChange !== 0 && (
                    <div
                        className={clsx(
                            "rounded-xl px-4 py-2.5 text-sm",
                            wouldGoNeg ? "bg-danger-light" : "bg-surface-50",
                        )}
                    >
                        <div className="flex justify-between">
                            <span className="text-surface-500">New stock:</span>
                            <span
                                className={clsx(
                                    "font-bold",
                                    wouldGoNeg ? "text-danger" : "text-success",
                                )}
                            >
                                {newQty.toFixed(3)} {material.unit_of_measure}
                            </span>
                        </div>
                        {wouldGoNeg && (
                            <p className="text-xs text-danger mt-1">
                                Would result in negative stock.
                            </p>
                        )}
                    </div>
                )}
                <Field label="Notes (required)">
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Reason for adjustment…"
                        required
                    />
                </Field>
            </div>
        </Modal>
    );
}

// ── Detail panel (right side) ─────────────────────────────────────────────────

function DetailPanel({
    material,
    onEdit,
    onDelete,
    onReceive,
    onAdjust,
}: {
    material: RawMaterial;
    onEdit: () => void;
    onDelete: () => void;
    onReceive: () => void;
    onAdjust: () => void;
}) {
    const [historyFilter, setHistoryFilter] = useState("");
    const { can } = usePermissions();
    const canAdjust = can("inventory.adjust");
    const canReceive = can("procurement.receive");

    const { data: detailData } = useQuery({
        queryKey: ["material", material.id],
        queryFn: () => rawMaterialsApi.get(material.id),
    });

    const detail = detailData?.material ?? material;
    const transactions = detailData?.transactions ?? [];
    const bomUsage = detailData?.bom_usage ?? [];

    const filteredTxns = historyFilter
        ? transactions.filter((t) => t.transaction_type === historyFilter)
        : transactions;

    const status = STATUS_CONFIG[detail.stock_status];

    return (
        <div className="flex flex-col h-full">
            {/* Header */}
            <div className="p-5 border-b border-surface-100">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2 flex-wrap">
                            <h2 className="text-base font-bold text-surface-900">
                                {detail.name}
                            </h2>
                            <span
                                className={clsx(
                                    "text-xs font-medium px-2 py-0.5 rounded-full",
                                    status.bg,
                                    status.text,
                                )}
                            >
                                {status.label}
                            </span>
                            {!detail.is_active && (
                                <span className="badge badge-neutral text-2xs">
                                    Inactive
                                </span>
                            )}
                        </div>
                        <div className="flex items-center gap-2 mt-1 text-xs text-surface-400">
                            <span className="font-mono">{detail.code}</span>
                            <span>·</span>
                            <span>{detail.category}</span>
                            {detail.supplier && (
                                <>
                                    <span>·</span>
                                    <span>{(detail.supplier as any).name}</span>
                                </>
                            )}
                        </div>
                    </div>
                </div>

                <div className="flex gap-2 mt-4">
                    {canReceive && (
                    <button
                        onClick={onReceive}
                        className="btn-primary btn-sm flex-1"
                    >
                        ⬇ Receive
                    </button>
                    )}
                    {canAdjust && (
                    <button
                        onClick={onAdjust}
                        className="btn-secondary btn-sm flex-1"
                    >
                        ± Adjust
                    </button>
                    )}
                    {canAdjust && (
                    <button
                        onClick={onEdit}
                        className="btn-secondary btn-sm px-3"
                        aria-label="Edit"
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
                                d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                            />
                        </svg>
                    </button>
                    )}
                    {canAdjust && (
                    <button
                        onClick={onDelete}
                        className="btn-secondary btn-sm px-3 text-danger hover:bg-danger-light"
                        aria-label="Delete"
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
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            />
                        </svg>
                    </button>
                    )}
                </div>
            </div>

            {/* Stats */}
            <div className="grid grid-cols-3 divide-x divide-surface-100 border-b border-surface-100 shrink-0">
                {[
                    {
                        label: "Total Stock",
                        value: `${detail.total_stock} ${detail.unit_of_measure}`,
                    },
                    {
                        label: "Cost/Unit",
                        value: `KES ${detail.unit_cost.toLocaleString()}`,
                    },
                    {
                        label: "Stock Value",
                        value: `KES ${detail.stock_value.toLocaleString()}`,
                    },
                ].map((s) => (
                    <div key={s.label} className="py-3 px-4 text-center">
                        <p className="text-sm font-bold text-surface-900">
                            {s.value}
                        </p>
                        <p className="text-2xs text-surface-400 mt-0.5">
                            {s.label}
                        </p>
                    </div>
                ))}
            </div>

            <div className="flex-1 overflow-y-auto">
                {/* Per-outlet stock */}
                {(detail.inventory?.length ?? 0) > 0 && (
                    <div className="px-5 py-4 border-b border-surface-50">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                            Stock by Location
                        </p>
                        <div className="space-y-2">
                            {detail.inventory!.map((inv) => (
                                <div
                                    key={inv.id}
                                    className="flex items-center justify-between text-sm"
                                >
                                    <span className="text-surface-700">
                                        {inv.outlet?.name ?? "Unknown"}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-semibold tabular-nums">
                                            {inv.quantity_on_hand}{" "}
                                            {detail.unit_of_measure}
                                        </span>
                                        {inv.last_counted_at && (
                                            <span className="text-xs text-surface-400">
                                                counted{" "}
                                                {new Date(
                                                    inv.last_counted_at,
                                                ).toLocaleDateString("en-GB", {
                                                    day: "numeric",
                                                    month: "short",
                                                })}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Reorder info */}
                {detail.reorder_point > 0 && (
                    <div className="px-5 py-4 border-b border-surface-50">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            Reorder Settings
                        </p>
                        <div className="flex items-center gap-4 text-sm">
                            <div>
                                <p className="text-surface-400 text-xs">
                                    Alert at
                                </p>
                                <p className="font-semibold">
                                    {detail.reorder_point}{" "}
                                    {detail.unit_of_measure}
                                </p>
                            </div>
                        </div>
                        <div className="mt-2">
                            <StockBar material={detail} />
                        </div>
                    </div>
                )}

                {/* BOM usage */}
                {bomUsage.length > 0 && (
                    <div className="px-5 py-4 border-b border-surface-50">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                            Used in {bomUsage.length} Bill
                            {bomUsage.length !== 1 ? "s" : ""} of Materials
                        </p>
                        <div className="space-y-1.5">
                            {bomUsage.map((b) => (
                                <div
                                    key={b.product_id}
                                    className="flex items-center justify-between text-sm bg-surface-50 rounded-lg px-3 py-2"
                                >
                                    <div>
                                        <p className="font-medium text-surface-800">
                                            {b.product_name ?? b.sku}
                                        </p>
                                        <p className="text-xs font-mono text-surface-400">
                                            {b.sku}
                                        </p>
                                    </div>
                                    <span className="text-xs text-surface-600 font-semibold tabular-nums">
                                        {b.quantity} {b.unit_of_measure} / unit
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Movement history */}
                <div className="px-5 py-4">
                    <div className="flex items-center justify-between mb-3">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                            Recent Movements
                        </p>
                        <select
                            className="input text-xs py-1 w-36"
                            value={historyFilter}
                            onChange={(e) => setHistoryFilter(e.target.value)}
                        >
                            <option value="">All types</option>
                            <option value="purchase">Purchase</option>
                            <option value="opening_stock">Opening Stock</option>
                            <option value="production_use">
                                Production Use
                            </option>
                            <option value="adjustment">Adjustment</option>
                            <option value="damaged">Damaged</option>
                            <option value="correction">Correction</option>
                        </select>
                    </div>
                    {filteredTxns.length === 0 ? (
                        <p className="text-xs text-surface-400 text-center py-4">
                            No movements recorded.
                        </p>
                    ) : (
                        <div className="space-y-0 border border-surface-100 rounded-xl overflow-hidden">
                            {filteredTxns.slice(0, 20).map((tx, i) => (
                                <div
                                    key={tx.id}
                                    className={clsx(
                                        "flex items-start gap-3 px-3 py-2.5 border-b border-surface-50 last:border-0",
                                        i === 0 && "bg-brand-50/30",
                                    )}
                                >
                                    <div
                                        className={clsx(
                                            "w-1.5 h-1.5 rounded-full mt-1.5 shrink-0",
                                            tx.quantity_change > 0
                                                ? "bg-success"
                                                : "bg-danger",
                                        )}
                                    />
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs font-medium text-surface-800">
                                                {tx.type_label}
                                            </span>
                                            <span
                                                className={clsx(
                                                    "text-xs font-bold tabular-nums",
                                                    TX_COLORS[
                                                        tx.transaction_type
                                                    ] ?? "text-surface-600",
                                                )}
                                            >
                                                {tx.quantity_change > 0
                                                    ? "+"
                                                    : ""}
                                                {tx.quantity_change}
                                            </span>
                                        </div>
                                        <p className="text-2xs text-surface-400 mt-0.5">
                                            {tx.quantity_before} →{" "}
                                            {tx.quantity_after}
                                            {tx.outlet &&
                                                ` · ${tx.outlet.name}`}
                                            {tx.notes && ` · ${tx.notes}`}
                                        </p>
                                    </div>
                                    <div className="text-right shrink-0">
                                        <p className="text-2xs text-surface-400">
                                            {new Date(
                                                tx.created_at,
                                            ).toLocaleDateString("en-GB", {
                                                day: "numeric",
                                                month: "short",
                                            })}
                                        </p>
                                        {tx.created_by && (
                                            <p className="text-2xs text-surface-300">
                                                {tx.created_by.name}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function RawMaterialsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const canAdjust = can("inventory.adjust");
    const canReceive = can("procurement.receive");
    const table = useTableState({ defaultSortBy: "name", defaultPerPage: 30 });

    const [typeFilter, setTypeFilter] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [selected, setSelected] = useState<RawMaterial | null>(null);
    const [formModal, setFormModal] = useState(false);
    const [editingMat, setEditingMat] = useState<RawMaterial | null>(null);
    const [receiveMat, setReceiveMat] = useState<RawMaterial | null>(null);
    const [adjustMat, setAdjustMat] = useState<RawMaterial | null>(null);
    const [deletingMat, setDeletingMat] = useState<RawMaterial | null>(null);

    const params: Record<string, string> = {
        ...table.toParams(),
        ...(typeFilter && { category: typeFilter }),
        ...(statusFilter === "low_stock" && { low_stock: "true" }),
        ...(statusFilter === "out_of_stock" && { low_stock: "true" }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["materials", params],
        queryFn: () => rawMaterialsApi.list(params),
    });

    const materials = data?.data ?? [];
    const meta = data?.meta;
    const stats = data?.stats;

    const deleteMutation = useMutation({
        mutationFn: (id: number) => rawMaterialsApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["materials"] });
            toast.success("Material deleted.");
            setDeletingMat(null);
            if (selected?.id === deletingMat?.id) setSelected(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const openEdit = useCallback((m: RawMaterial) => {
        setEditingMat(m);
        setFormModal(true);
    }, []);

    return (
        <div
            className="flex flex-col animate-fade-in"
            style={{ minHeight: "calc(100vh - 120px)" }}
        >
            {/* Header */}
            <div className="flex flex-col gap-3 mb-4 shrink-0 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Raw Materials</h1>
                    <p className="page-subtitle">
                        {stats
                            ? `${stats.total} materials · ${stats.active} active · ${stats.low_stock} low stock · ${stats.out_of_stock} out of stock`
                            : "Loading…"}
                    </p>
                </div>
                {canAdjust && (
                <button
                    onClick={() => {
                        setEditingMat(null);
                        setFormModal(true);
                    }}
                    className="btn-primary self-start"
                >
                    + Add Material
                </button>
                )}
            </div>

            {/* Stats */}
            {stats && (
                <div className="grid grid-cols-2 gap-3 mb-4 shrink-0 sm:grid-cols-4">
                    {[
                        {
                            label: "Total",
                            value: stats.total,
                            color: "",
                            filter: "",
                        },
                        {
                            label: "Active",
                            value: stats.active,
                            color: "text-success",
                            filter: "",
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
                                "card p-4 text-center transition-all",
                                s.filter && statusFilter === s.filter
                                    ? "ring-2 ring-brand-300"
                                    : "",
                                s.filter ? "hover:shadow-sm" : "cursor-default",
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

            {/* Two-panel layout */}
            <div className="flex flex-col gap-4 flex-1 min-h-0 lg:flex-row">
                {/* LEFT: List */}
                <div className="flex-1 flex flex-col min-w-0 space-y-3">
                    {/* Filters */}
                    <div className="flex flex-wrap gap-3 shrink-0">
                        <input
                            className="input w-full sm:max-w-xs"
                            placeholder="Search name, code…"
                            value={table.state.search}
                            onChange={(e) => table.setSearch(e.target.value)}
                        />
                        <select
                            className="input flex-1 sm:w-40 sm:flex-none"
                            value={typeFilter}
                            onChange={(e) => setTypeFilter(e.target.value)}
                        >
                            <option value="">All types</option>
                            {(stats?.types ?? stats?.categories ?? MATERIAL_TYPES).map(
                                (t: string) => (
                                    <option key={t} value={t}>
                                        {t}
                                    </option>
                                ),
                            )}
                        </select>
                        {(table.state.search || typeFilter || statusFilter) && (
                            <button
                                onClick={() => {
                                    table.setSearch("");
                                    setTypeFilter("");
                                    setStatusFilter("");
                                }}
                                className="btn-ghost btn-sm text-xs"
                            >
                                ✕ Clear
                            </button>
                        )}
                    </div>

                    {/* Table */}
                    <div className="card overflow-hidden flex-1">
                        {isLoading ? (
                            <div className="flex justify-center py-16">
                                <Spinner size="lg" />
                            </div>
                        ) : materials.length === 0 ? (
                            <div className="text-center py-16">
                                <p className="text-surface-400 text-sm mb-3">
                                    {table.state.search || typeFilter
                                        ? "No materials match your filters."
                                        : "No materials yet."}
                                </p>
                                {!table.state.search && canAdjust && (
                                    <button
                                        onClick={() => {
                                            setEditingMat(null);
                                            setFormModal(true);
                                        }}
                                        className="btn-primary btn-sm"
                                    >
                                        Add first material
                                    </button>
                                )}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                            <table className="w-full min-w-[520px]">
                                <thead>
                                    <tr className="border-b border-surface-100 bg-surface-50/50">
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Material
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                            Type
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Stock
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Cost/Unit
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
                                    {materials.map((m) => {
                                        const status =
                                            STATUS_CONFIG[m.stock_status];
                                        return (
                                            <tr
                                                key={m.id}
                                                onClick={() => setSelected(m)}
                                                className={clsx(
                                                    "hover:bg-surface-50/50 transition-colors cursor-pointer",
                                                    selected?.id === m.id &&
                                                        "bg-brand-50/30",
                                                )}
                                            >
                                                <td className="px-4 py-3">
                                                    <p className="text-sm font-medium text-surface-900">
                                                        {m.name}
                                                    </p>
                                                    <div className="flex items-center gap-2 mt-0.5">
                                                        <span className="text-xs font-mono text-surface-400">
                                                            {m.code}
                                                        </span>
                                                        {m.supplier && (
                                                            <span className="text-xs text-surface-400">
                                                                ·{" "}
                                                                {
                                                                    (m.supplier as any)
                                                                        .name
                                                                }
                                                            </span>
                                                        )}
                                                        {!m.is_active && (
                                                            <span className="text-2xs text-surface-300">
                                                                inactive
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-600">
                                                    {m.category}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex flex-col items-end gap-1">
                                                        <span
                                                            className={clsx(
                                                                "text-sm font-semibold tabular-nums",
                                                                m.stock_status ===
                                                                    "out_of_stock"
                                                                    ? "text-danger"
                                                                    : m.stock_status ===
                                                                        "low_stock"
                                                                      ? "text-warning"
                                                                      : "text-success",
                                                            )}
                                                        >
                                                            {m.total_stock}{" "}
                                                            {m.unit_of_measure}
                                                        </span>
                                                        <StockBar
                                                            material={m}
                                                        />
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right text-sm text-surface-600 tabular-nums">
                                                    KES{" "}
                                                    {m.unit_cost.toLocaleString()}
                                                </td>
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
                                                <td className="px-4 py-3">
                                                    <div
                                                        className="flex items-center gap-1 justify-end"
                                                        onClick={(e) =>
                                                            e.stopPropagation()
                                                        }
                                                    >
                                                        {canReceive && (
                                                        <button
                                                            title="Receive stock"
                                                            onClick={() =>
                                                                setReceiveMat(m)
                                                            }
                                                            className="btn-ghost btn-sm text-success hover:bg-success-light"
                                                        >
                                                            ⬇
                                                        </button>
                                                        )}
                                                        {canAdjust && (
                                                        <button
                                                            title="Adjust stock"
                                                            onClick={() =>
                                                                setAdjustMat(m)
                                                            }
                                                            className="btn-ghost btn-sm"
                                                        >
                                                            ±
                                                        </button>
                                                        )}
                                                        {canAdjust && (
                                                        <button
                                                            title="Edit"
                                                            onClick={() =>
                                                                openEdit(m)
                                                            }
                                                            className="btn-ghost btn-sm"
                                                            aria-label="Edit"
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
                                                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                                                />
                                                            </svg>
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
                    </div>
                </div>

                {/* RIGHT: Detail */}
                <div className="w-full lg:w-80 lg:shrink-0 card overflow-hidden">
                    {selected ? (
                        <DetailPanel
                            material={selected}
                            onEdit={() => openEdit(selected)}
                            onDelete={() => setDeletingMat(selected)}
                            onReceive={() => setReceiveMat(selected)}
                            onAdjust={() => setAdjustMat(selected)}
                        />
                    ) : (
                        <div className="flex flex-col items-center justify-center h-full text-center px-6">
                            <div className="w-14 h-14 rounded-2xl bg-surface-100 flex items-center justify-center mb-4">
                                <svg
                                    className="w-7 h-7 text-surface-300"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth={1.5}
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 2.625c0 2.278-3.694 4.125-8.25 4.125S3.75 11.278 3.75 9m16.5 2.625c0 2.278-3.694 4.125-8.25 4.125S3.75 13.903 3.75 11.625"
                                    />
                                </svg>
                            </div>
                            <h3 className="text-sm font-semibold text-surface-700 mb-1">
                                Select a material
                            </h3>
                            <p className="text-xs text-surface-400">
                                Click a row to view stock by location, movement
                                history, and BOM usage.
                            </p>
                        </div>
                    )}
                </div>
            </div>

            {/* Modals */}
            {formModal && (
                <MaterialFormModal
                    open={formModal}
                    onClose={() => {
                        setFormModal(false);
                        setEditingMat(null);
                    }}
                    editing={editingMat}
                    onSaved={() =>
                        qc.invalidateQueries({ queryKey: ["materials"] })
                    }
                />
            )}
            {receiveMat && (
                <ReceiveModal
                    material={receiveMat}
                    onClose={() => setReceiveMat(null)}
                />
            )}
            {adjustMat && (
                <AdjustModal
                    material={adjustMat}
                    onClose={() => setAdjustMat(null)}
                />
            )}

            <ConfirmDialog
                open={!!deletingMat}
                onClose={() => setDeletingMat(null)}
                onConfirm={() =>
                    deletingMat && deleteMutation.mutate(deletingMat.id)
                }
                isLoading={deleteMutation.isPending}
                title="Delete Material"
                message={`Delete "${deletingMat?.name}"? All inventory records and transaction history will be removed. This cannot be undone.`}
                confirmLabel="Delete"
            />
        </div>
    );
}