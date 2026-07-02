/**
 * ShippingSettingsPage.tsx
 *
 * Two-panel layout:
 *  LEFT  - Shipping Zones  (create / edit / delete, each with country tags)
 *  RIGHT - Shipping Methods per zone (create / edit / delete / toggle active)
 *
 * Matches the project's TaxRatesPage / PaymentMethodsPage patterns:
 *  - Section, Field, Toggle, EmptyState, ConfirmDialog from FormComponents
 *  - Modal from @/components/ui/Modal
 *  - react-hook-form + zod validation
 *  - @tanstack/react-query for data fetching & mutations
 */

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { clsx } from "clsx";
import { shippingApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import {
    Section,
    Field, useFieldAriaProps,
    Toggle,
    StatusBadge,
    EmptyState,
    ConfirmDialog,
    FieldInput, FieldSelect, FieldTextarea
} from "@/components/setup/FormComponents";
import type { ShippingZone, ShippingMethod } from "@/types/setup";
import type { ApiError } from "@/types";

// ─── Zod schemas ──────────────────────────────────────────────────────────────

const zoneSchema = z.object({
    name:        z.string().min(1, "Name is required"),
    description: z.string().nullable().optional(),
    countries:   z.string().min(2, "At least one country code required"),
    // countries stored as comma-separated string in form, converted to array on submit
});

const methodSchema = z.object({
    name:             z.string().min(1, "Name is required"),
    description:      z.string().nullable().optional(),
    delivery_time:    z.string().nullable().optional(),   // e.g. "2–5 business days"
    cost_type:        z.enum(["flat_rate", "free", "percentage"]),
    flat_rate:        z.coerce.number().min(0),
    min_order_amount: z.coerce.number().min(0).nullable().optional(),
    is_active:        z.boolean(),
    sort_order:       z.coerce.number().int().min(0).optional(),
});

type ZoneForm   = z.infer<typeof zoneSchema>;
type MethodForm = z.infer<typeof methodSchema>;

const ZONE_DEFAULTS: ZoneForm = {
    name:        "",
    description: "",
    countries:   "",
};

const METHOD_DEFAULTS: MethodForm = {
    name:             "",
    description:      "",
    delivery_time:    "",
    cost_type:        "flat_rate",
    flat_rate:        0,
    min_order_amount: null,
    is_active:        true,
    sort_order:       0,
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

const fmt = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

function parseCodes(raw: string): string[] {
    return raw
        .toUpperCase()
        .split(/[\s,;]+/)
        .map((s) => s.trim())
        .filter((s) => s.length === 2);
}

function costTypeLabel(ct: string) {
    if (ct === "flat_rate")   return "Flat rate";
    if (ct === "free")        return "Free";
    if (ct === "percentage")  return "Percentage";
    return ct;
}

// ─── Small icon components ────────────────────────────────────────────────────

const EditIcon = () => (
    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
    </svg>
);
const TrashIcon = () => (
    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
    </svg>
);
const TruckIcon = () => (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
    </svg>
);
const GlobeIcon = () => (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253M3 12a8.959 8.959 0 01.284-2.253" />
    </svg>
);

// ─── Zone Modal ───────────────────────────────────────────────────────────────

function ZoneModal({
    open,
    editing,
    onClose,
    onSave,
    isSaving,
}: {
    open: boolean;
    editing: ShippingZone | null;
    onClose: () => void;
    onSave: (data: ZoneForm) => void;
    isSaving: boolean;
}) {
    const { register, handleSubmit, formState: { errors }, reset } = useForm<ZoneForm>({
        resolver: zodResolver(zoneSchema),
        defaultValues: editing
            ? { name: editing.name, description: editing.description ?? "", countries: (editing.countries ?? []).join(", ") }
            : ZONE_DEFAULTS,
    });

    // Reset form when editing changes
    useState(() => {
        reset(editing
            ? { name: editing.name, description: editing.description ?? "", countries: (editing.countries ?? []).join(", ") }
            : ZONE_DEFAULTS);
    });

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={editing ? "Edit Shipping Zone" : "Add Shipping Zone"}
            size="md"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm" disabled={isSaving}>Cancel</button>
                    <button onClick={handleSubmit(onSave)} disabled={isSaving} className="btn-primary btn-sm">
                        {isSaving && <Spinner size="xs" className="border-white/30 border-t-white" />}
                        {editing ? "Save" : "Create Zone"}
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Zone Name" error={errors.name?.message} required>
                    <FieldInput className="input" {...register("name")} placeholder="East Africa" autoFocus />
                </Field>

                <Field
                    label="Countries"
                    error={errors.countries?.message}
                    hint="Comma-separated 2-letter ISO codes, e.g. KE, UG, TZ, RW"
                    required
                >
                    <FieldInput
                        className={`input font-mono tracking-wider ${errors.countries ? "input-error" : ""}`}
                        {...register("countries")}
                        placeholder="KE, UG, TZ, RW"
                    />
                </Field>

                <Field label="Description" hint="Optional note shown to staff">
                    <FieldTextarea
                        className="input resize-none"
                        rows={2}
                        {...register("description")}
                        placeholder="Covers East African Community countries"
                    />
                </Field>
            </div>
        </Modal>
    );
}

// ─── Method Modal ─────────────────────────────────────────────────────────────

function MethodModal({
    open,
    editing,
    zoneName,
    onClose,
    onSave,
    isSaving,
}: {
    open: boolean;
    editing: ShippingMethod | null;
    zoneName: string;
    onClose: () => void;
    onSave: (data: MethodForm) => void;
    isSaving: boolean;
}) {
    const { register, handleSubmit, watch, setValue, formState: { errors } } = useForm<MethodForm>({
        resolver: zodResolver(methodSchema),
        defaultValues: editing
            ? {
                name:             editing.name,
                description:      editing.description ?? "",
                delivery_time:    editing.delivery_time ?? "",
                cost_type:        (editing.cost_type ?? "flat_rate") as MethodForm["cost_type"],
                flat_rate:        editing.flat_rate ?? 0,
                min_order_amount: editing.min_order_amount ?? null,
                is_active:        editing.is_active ?? true,
                sort_order:       editing.sort_order ?? 0,
            }
            : METHOD_DEFAULTS,
    });

    const costType = watch("cost_type");

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={editing ? `Edit Method` : `Add Method - ${zoneName}`}
            size="lg"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm" disabled={isSaving}>Cancel</button>
                    <button onClick={handleSubmit(onSave)} disabled={isSaving} className="btn-primary btn-sm">
                        {isSaving && <Spinner size="xs" className="border-white/30 border-t-white" />}
                        {editing ? "Save" : "Add Method"}
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                {/* Name + cost type */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field label="Method Name" error={errors.name?.message} required className="">
                        <FieldInput className="input" {...register("name")} placeholder="Standard Delivery" autoFocus />
                    </Field>
                    <Field label="Cost Type" required className="">
                        <FieldSelect className="input" {...register("cost_type")}>
                            <option value="flat_rate">Flat rate</option>
                            <option value="free">Free</option>
                            <option value="percentage">Percentage of order</option>
                        </FieldSelect>
                    </Field>
                </div>

                {/* Description */}
                <Field label="Description" hint="Shown to customer at checkout (optional)">
                    <FieldInput className="input" {...register("description")} placeholder="Delivered in 3–5 business days" />
                </Field>

                {/* Delivery time */}
                <Field label="Delivery Time" hint='Shown as a label, e.g. "2–5 business days"'>
                    <FieldInput className="input" {...register("delivery_time")} placeholder="2–5 business days" />
                </Field>

                {/* Cost + min order */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {costType !== "free" && (
                        <Field
                            label={costType === "percentage" ? "Rate (%)" : "Flat Rate (KES)"}
                            error={errors.flat_rate?.message}
                            required
                        >
                            <div className="relative">
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-surface-400 font-medium pointer-events-none">
                                    {costType === "percentage" ? "%" : "KES"}
                                </span>
                                <FieldInput type="number" min={0} step={costType === "percentage" ? 0.1 : 0.01}
                                    className={`input pl-10 ${errors.flat_rate ? "input-error" : ""}`}
                                    {...register("flat_rate")} />
                            </div>
                        </Field>
                    )}

                    <Field label="Minimum Order (KES)" hint="Leave empty for no minimum">
                        <div className="relative">
                            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-surface-400 font-medium pointer-events-none">KES</span>
                            <FieldInput type="number" min={0} step={100} className="input pl-10"
                                {...register("min_order_amount")} placeholder="0" />
                        </div>
                    </Field>

                    <Field label="Sort Order" hint="Lower numbers appear first">
                        <FieldInput type="number" min={0} step={1} className="input" {...register("sort_order")} placeholder="0" />
                    </Field>
                </div>

                <div className="border-t border-surface-100 pt-3">
                    <Toggle
                        checked={watch("is_active")}
                        onChange={(v) => setValue("is_active", v)}
                        label="Active"
                        description="Inactive methods are hidden from checkout and POS."
                    />
                </div>
            </div>
        </Modal>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ShippingSettingsPage() {
    const qc    = useQueryClient();
    const toast = useToastStore();

    // ── Zones state ────────────────────────────────────────────────────────────
    const [selectedZoneId, setSelectedZoneId]   = useState<number | null>(null);
    const [zoneModalOpen, setZoneModalOpen]     = useState(false);
    const [editingZone, setEditingZone]         = useState<ShippingZone | null>(null);
    const [deletingZone, setDeletingZone]       = useState<ShippingZone | null>(null);

    // ── Methods state ──────────────────────────────────────────────────────────
    const [methodModalOpen, setMethodModalOpen] = useState(false);
    const [editingMethod, setEditingMethod]     = useState<ShippingMethod | null>(null);
    const [deletingMethod, setDeletingMethod]   = useState<ShippingMethod | null>(null);

    // ── Queries ────────────────────────────────────────────────────────────────
    const { data: zonesData, isLoading: loadingZones } = useQuery({
        queryKey: ["shipping-zones"],
        queryFn:  () => shippingApi.zones(),
    });
    const zones: ShippingZone[] = zonesData ?? [];

    const { data: methodsData, isLoading: loadingMethods } = useQuery({
        queryKey: ["shipping-methods", selectedZoneId],
        queryFn:  () => shippingApi.methods(selectedZoneId ?? undefined),
        enabled:  true,
    });
    const allMethods: ShippingMethod[] = methodsData ?? [];
    const methods = selectedZoneId
        ? allMethods.filter((m) => m.shipping_zone_id === selectedZoneId)
        : allMethods;

    const selectedZone = zones.find((z) => z.id === selectedZoneId) ?? null;

    // ── Zone mutations ─────────────────────────────────────────────────────────
    const createZoneMutation = useMutation({
        mutationFn: (v: ZoneForm) => shippingApi.createZone({ ...v, countries: parseCodes(v.countries) }),
        onSuccess: (data) => {
            qc.invalidateQueries({ queryKey: ["shipping-zones"] });
            toast.success("Zone created.");
            setZoneModalOpen(false);
            setSelectedZoneId(data.zone.id);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const updateZoneMutation = useMutation({
        mutationFn: (v: ZoneForm) => shippingApi.updateZone(editingZone!.id, { ...v, countries: parseCodes(v.countries) }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["shipping-zones"] });
            toast.success("Zone updated.");
            setZoneModalOpen(false);
            setEditingZone(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteZoneMutation = useMutation({
        mutationFn: (id: number) => shippingApi.deleteZone(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["shipping-zones"] });
            toast.success("Zone deleted.");
            if (deletingZone?.id === selectedZoneId) setSelectedZoneId(null);
            setDeletingZone(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    // ── Method mutations ───────────────────────────────────────────────────────
    const createMethodMutation = useMutation({
        mutationFn: (v: MethodForm) => shippingApi.createMethod({
            zone_id:          selectedZoneId!,
            name:             v.name,
            description:      v.description ?? null,
            delivery_time:    v.delivery_time ?? null,
            cost_type:        v.cost_type,
            flat_rate:        v.flat_rate,
            min_order_amount: v.min_order_amount ?? null,
            is_active:        v.is_active,
            sort_order:       v.sort_order ?? 0,
        }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["shipping-methods"] });
            toast.success("Method added.");
            setMethodModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const updateMethodMutation = useMutation({
        mutationFn: (v: MethodForm) => shippingApi.updateMethod(editingMethod!.id, {
            name:             v.name,
            description:      v.description ?? null,
            delivery_time:    v.delivery_time ?? null,
            cost_type:        v.cost_type,
            flat_rate:        v.flat_rate,
            min_order_amount: v.min_order_amount ?? null,
            is_active:        v.is_active,
            sort_order:       v.sort_order ?? 0,
        }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["shipping-methods"] });
            toast.success("Method updated.");
            setMethodModalOpen(false);
            setEditingMethod(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const toggleMethodMutation = useMutation({
        mutationFn: (m: ShippingMethod) => shippingApi.updateMethod(m.id, { is_active: !m.is_active }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ["shipping-methods"] }),
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMethodMutation = useMutation({
        mutationFn: (id: number) => shippingApi.deleteMethod(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["shipping-methods"] });
            toast.success("Method removed.");
            setDeletingMethod(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    // ── Handlers ───────────────────────────────────────────────────────────────
    const openCreateZone = () => { setEditingZone(null); setZoneModalOpen(true); };
    const openEditZone   = (z: ShippingZone) => { setEditingZone(z); setZoneModalOpen(true); };

    const openCreateMethod = () => { setEditingMethod(null); setMethodModalOpen(true); };
    const openEditMethod   = (m: ShippingMethod) => { setEditingMethod(m); setMethodModalOpen(true); };

    const handleSaveZone   = (v: ZoneForm) => editingZone ? updateZoneMutation.mutate(v) : createZoneMutation.mutate(v);
    const handleSaveMethod = (v: MethodForm) => editingMethod ? updateMethodMutation.mutate(v) : createMethodMutation.mutate(v);

    // ── Render ─────────────────────────────────────────────────────────────────
    return (
        <div className="space-y-6 animate-fade-in max-w-6xl">
            {/* Page header */}
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Shipping</h1>
                    <p className="page-subtitle">
                        Define shipping zones by country and configure delivery methods with pricing for each zone. Methods appear in POS checkout and the customer storefront.
                    </p>
                </div>
                <button onClick={openCreateZone} className="btn-primary shrink-0 gap-2 self-start sm:self-auto">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Zone
                </button>
            </div>

            {/* ── Two-panel layout ── */}
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-5 items-start">

                {/* LEFT: Zones list */}
                <div className="lg:col-span-2">
                    <Section
                        title="Shipping Zones"
                        description="Group countries into zones. Each zone can have its own delivery methods and rates."
                    >
                        {loadingZones ? (
                            <div className="flex justify-center py-10"><Spinner size="lg" /></div>
                        ) : zones.length === 0 ? (
                            <EmptyState
                                title="No zones yet"
                                description="Add your first shipping zone to start configuring delivery options."
                                icon={<GlobeIcon />}
                                action={
                                    <button onClick={openCreateZone} className="btn-primary btn-sm">Add Zone</button>
                                }
                            />
                        ) : (
                            <div className="space-y-2">
                                {/* "All methods" selector */}
                                <button
                                    onClick={() => setSelectedZoneId(null)}
                                    className={clsx(
                                        "w-full flex items-center gap-3 px-3 py-2.5 rounded-xl border text-left transition-all",
                                        selectedZoneId === null
                                            ? "border-brand-300 bg-brand-50 text-brand-700"
                                            : "border-surface-200 hover:border-surface-300 text-surface-600 bg-white",
                                    )}
                                >
                                    <div className={clsx(
                                        "w-8 h-8 rounded-lg flex items-center justify-center shrink-0",
                                        selectedZoneId === null ? "bg-brand-500 text-white" : "bg-surface-100 text-surface-400",
                                    )}>
                                        <TruckIcon />
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-semibold">All methods</p>
                                        <p className="text-2xs text-surface-400">{allMethods.length} method{allMethods.length !== 1 ? "s" : ""} total</p>
                                    </div>
                                </button>

                                {zones.map((zone) => {
                                    const zoneMethods = allMethods.filter((m) => m.shipping_zone_id === zone.id);
                                    const isSelected  = selectedZoneId === zone.id;
                                    return (
                                        <div key={zone.id} className={clsx(
                                            "rounded-xl border transition-all",
                                            isSelected ? "border-brand-300 bg-brand-50" : "border-surface-200 bg-white hover:border-surface-300",
                                        )}>
                                            <button
                                                onClick={() => setSelectedZoneId(zone.id)}
                                                className="w-full flex items-center gap-3 px-3 py-2.5 text-left"
                                            >
                                                <div className={clsx(
                                                    "w-8 h-8 rounded-lg flex items-center justify-center shrink-0",
                                                    isSelected ? "bg-brand-500 text-white" : "bg-surface-100 text-surface-400",
                                                )}>
                                                    <GlobeIcon />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <p className={clsx("text-sm font-semibold", isSelected ? "text-brand-800" : "text-surface-900")}>
                                                        {zone.name}
                                                    </p>
                                                    <p className="text-2xs text-surface-400">
                                                        {zoneMethods.length} method{zoneMethods.length !== 1 ? "s" : ""} · {(zone.countries ?? []).length} countr{(zone.countries ?? []).length !== 1 ? "ies" : "y"}
                                                    </p>
                                                </div>
                                            </button>

                                            {/* Country tags (always visible) */}
                                            {(zone.countries ?? []).length > 0 && (
                                                <div className="px-3 pb-2.5 flex flex-wrap gap-1">
                                                    {(zone.countries ?? []).slice(0, 8).map((cc) => (
                                                        <span key={cc} className="text-2xs font-mono bg-surface-100 text-surface-600 px-1.5 py-0.5 rounded font-semibold">
                                                            {cc}
                                                        </span>
                                                    ))}
                                                    {(zone.countries ?? []).length > 8 && (
                                                        <span className="text-2xs text-surface-400">+{(zone.countries ?? []).length - 8} more</span>
                                                    )}
                                                </div>
                                            )}

                                            {/* Zone actions */}
                                            <div className="border-t border-surface-100 px-3 py-2 flex items-center gap-1">
                                                <button
                                                    onClick={() => openEditZone(zone)}
                                                    className="btn-ghost btn-sm text-xs gap-1.5"
                                                >
                                                    <EditIcon /> Edit
                                                </button>
                                                <button
                                                    onClick={() => setDeletingZone(zone)}
                                                    className="btn-ghost btn-sm text-xs gap-1.5 text-danger hover:bg-danger-light ml-auto"
                                                >
                                                    <TrashIcon /> Delete
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </Section>
                </div>

                {/* RIGHT: Methods */}
                <div className="lg:col-span-3">
                    <Section
                        title={selectedZone ? `Methods - ${selectedZone.name}` : "All Shipping Methods"}
                        description={
                            selectedZone
                                ? `Delivery options available in the ${selectedZone.name} zone.`
                                : "All configured delivery methods across all zones."
                        }
                        actions={
                            selectedZoneId != null && (
                                <button onClick={openCreateMethod} className="btn-primary btn-sm gap-1.5">
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    Add Method
                                </button>
                            )
                        }
                    >
                        {loadingMethods ? (
                            <div className="flex justify-center py-10"><Spinner size="lg" /></div>
                        ) : methods.length === 0 ? (
                            <EmptyState
                                title={selectedZoneId ? "No methods in this zone" : "No methods configured"}
                                description={
                                    selectedZoneId
                                        ? "Add a delivery method to this zone so it appears at checkout."
                                        : "Select a zone on the left, then add delivery methods."
                                }
                                icon={<TruckIcon />}
                                action={
                                    selectedZoneId != null
                                        ? <button onClick={openCreateMethod} className="btn-primary btn-sm">Add Method</button>
                                        : undefined
                                }
                            />
                        ) : (
                            <div className="divide-y divide-surface-50">
                                {methods.map((method) => {
                                    const zone = zones.find((z) => z.id === method.shipping_zone_id);

                                    return (
                                        <div key={method.id} className="py-3.5 first:pt-0 last:pb-0">
                                            <div className="flex items-start gap-3">
                                                {/* Cost badge */}
                                                <div className="w-16 shrink-0 h-14 rounded-xl bg-surface-50 border border-surface-100 flex flex-col items-center justify-center">
                                                    {method.cost_type === "free" ? (
                                                        <span className="text-xs font-bold text-success">Free</span>
                                                    ) : method.cost_type === "percentage" ? (
                                                        <>
                                                            <span className="text-sm font-bold text-surface-900">{method.flat_rate}%</span>
                                                            <span className="text-2xs text-surface-400">of order</span>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <span className="text-2xs text-surface-400 font-medium">KES</span>
                                                            <span className="text-sm font-bold text-surface-900">{fmt(method.flat_rate ?? 0)}</span>
                                                        </>
                                                    )}
                                                </div>

                                                {/* Info */}
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <span className="font-semibold text-sm text-surface-900">{method.name}</span>
                                                        <StatusBadge active={method.is_active} />
                                                        <span className="text-2xs text-surface-400 bg-surface-100 px-1.5 py-0.5 rounded">
                                                            {costTypeLabel(method.cost_type ?? "flat_rate")}
                                                        </span>
                                                        {!selectedZoneId && zone && (
                                                            <span className="text-2xs text-brand-600 bg-brand-50 px-1.5 py-0.5 rounded font-medium">
                                                                {zone.name}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-surface-400">
                                                        {method.description && (
                                                            <span className="truncate">{method.description}</span>
                                                        )}
                                                        {method.delivery_time && (
                                                            <span className="flex items-center gap-1">
                                                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                </svg>
                                                                {method.delivery_time}
                                                            </span>
                                                        )}
                                                        {(method.min_order_amount ?? 0) > 0 && (
                                                            <span>Min order KES {fmt(method.min_order_amount ?? 0)}</span>
                                                        )}
                                                    </div>
                                                </div>

                                                {/* Actions */}
                                                <div className="flex items-center gap-1 shrink-0">
                                                    <button
                                                        onClick={() => toggleMethodMutation.mutate(method)}
                                                        className="btn-ghost btn-sm text-xs"
                                                        disabled={toggleMethodMutation.isPending}
                                                    >
                                                        {method.is_active ? "Disable" : "Enable"}
                                                    </button>
                                                    <button
                                                        onClick={() => openEditMethod(method)}
                                                        className="btn-ghost btn-sm"
                                                        aria-label="Edit"
                                                    >
                                                        <EditIcon />
                                                    </button>
                                                    <button
                                                        onClick={() => setDeletingMethod(method)}
                                                        className="btn-ghost btn-sm text-danger hover:bg-danger-light"
                                                        aria-label="Delete"
                                                    >
                                                        <TrashIcon />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </Section>

                    {/* POS hint */}
                    <div className="mt-3 flex items-start gap-2 px-3 py-2.5 bg-brand-50 border border-brand-100 rounded-xl text-xs text-brand-700">
                        <svg className="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                        <p>Active shipping methods appear in the <strong>POS checkout</strong> shipping picker and on the customer storefront at checkout. Inactive methods are hidden from both.</p>
                    </div>
                </div>
            </div>

            {/* ── Zone modals ── */}
            <ZoneModal
                open={zoneModalOpen}
                editing={editingZone}
                onClose={() => { setZoneModalOpen(false); setEditingZone(null); }}
                onSave={handleSaveZone}
                isSaving={createZoneMutation.isPending || updateZoneMutation.isPending}
            />

            <ConfirmDialog
                open={!!deletingZone}
                onClose={() => setDeletingZone(null)}
                onConfirm={() => deletingZone && deleteZoneMutation.mutate(deletingZone.id)}
                isLoading={deleteZoneMutation.isPending}
                title="Delete Shipping Zone"
                message={`Delete "${deletingZone?.name}"? All methods in this zone will also be removed. This cannot be undone.`}
                confirmLabel="Delete Zone"
            />

            {/* ── Method modals ── */}
            <MethodModal
                open={methodModalOpen}
                editing={editingMethod}
                zoneName={selectedZone?.name ?? ""}
                onClose={() => { setMethodModalOpen(false); setEditingMethod(null); }}
                onSave={handleSaveMethod}
                isSaving={createMethodMutation.isPending || updateMethodMutation.isPending}
            />

            <ConfirmDialog
                open={!!deletingMethod}
                onClose={() => setDeletingMethod(null)}
                onConfirm={() => deletingMethod && deleteMethodMutation.mutate(deletingMethod.id)}
                isLoading={deleteMethodMutation.isPending}
                title="Remove Shipping Method"
                message={`Remove "${deletingMethod?.name}"? This cannot be undone.`}
                confirmLabel="Remove"
            />
        </div>
    );
}