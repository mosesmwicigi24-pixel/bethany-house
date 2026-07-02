import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { outletsApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import {
    Section,
    Field,
    useFieldAriaProps,
    Toggle,
    StatusBadge,
    ConfirmDialog,
    EmptyState,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";
import type { OutletSetup } from "@/types/setup";
import type { ApiError } from "@/types";

const schema = z.object({
    code: z.string().min(1, "Code is required"),
    name: z.string().min(1, "Name is required"),
    outlet_type: z.enum(["store", "warehouse", "outlet", "workshop"]),
    email: z.string().email("Invalid email").or(z.literal("")),
    phone: z.string(),
    address_line1: z.string(),
    address_line2: z.string(),
    city: z.string(),
    state_province: z.string(),
    postal_code: z.string(),
    country_code: z.string().min(2).max(3),
    is_active: z.boolean(),
    is_pickup_location: z.boolean(),
    latitude: z.number().nullable().optional(),
    longitude: z.number().nullable().optional(),
    geofence_radius_meters: z.number().nullable().optional(),
});

type FormValues = z.infer<typeof schema>;

const DEFAULTS: FormValues = {
    code: "",
    name: "",
    outlet_type: "store",
    email: "",
    phone: "",
    address_line1: "",
    address_line2: "",
    city: "Nairobi",
    state_province: "",
    postal_code: "",
    country_code: "KE",
    is_active: true,
    is_pickup_location: true,
    latitude: null,
    longitude: null,
    geofence_radius_meters: 100,
};

const TYPE_LABELS = {
    store: "Store",
    warehouse: "Warehouse",
    outlet: "Outlet",
    workshop: "Workshop",
};
const TYPE_COLORS = {
    store: "bg-brand-50 text-brand-700",
    warehouse: "bg-info-light text-info",
    outlet: "bg-success-light text-success",
    workshop: "bg-purple-50 text-purple-700",
};

export default function OutletsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<OutletSetup | null>(null);
    const [deleting, setDeleting] = useState<OutletSetup | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => outletsApi.list(),
    });

    const outlets = data?.data ?? [];

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: DEFAULTS,
    });
    const {
        register,
        handleSubmit,
        watch,
        setValue,
        reset,
        formState: { errors },
    } = form;

    const openCreate = () => {
        reset(DEFAULTS);
        setEditing(null);
        setModalOpen(true);
    };
    const openEdit = (o: OutletSetup) => {
        reset({
            code: o.code,
            name: o.name,
            outlet_type: o.outlet_type,
            email: o.email ?? "",
            phone: o.phone ?? "",
            address_line1: o.address_line1 ?? "",
            address_line2: o.address_line2 ?? "",
            city: o.city ?? "",
            state_province: o.state_province ?? "",
            postal_code: o.postal_code ?? "",
            country_code: o.country_code,
            is_active: o.is_active,
            is_pickup_location: o.is_pickup_location,
            latitude: o.latitude ?? null,
            longitude: o.longitude ?? null,
            geofence_radius_meters: o.geofence_radius_meters ?? null,
        });
        setEditing(o);
        setModalOpen(true);
    };

    const saveMutation = useMutation({
        mutationFn: (v: FormValues) =>
            editing ? outletsApi.update(editing.id, v) : outletsApi.create(v),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["outlets"] });
            toast.success(editing ? "Outlet updated." : "Outlet created.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => outletsApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["outlets"] });
            toast.success("Outlet removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <div className="space-y-6 animate-fade-in max-w-4xl">
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Outlets</h1>
                    <p className="page-subtitle">
                        Physical store locations. POS clerks are assigned to
                        outlets, and inventory is tracked per outlet.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary shrink-0 self-start sm:self-auto">
                    + Add Outlet
                </button>
            </div>

            <Section title="Store Locations">
                {isLoading ? (
                    <div className="flex justify-center py-10">
                        <Spinner size="lg" />
                    </div>
                ) : outlets.length === 0 ? (
                    <EmptyState
                        title="No outlets configured"
                        description="Create at least one outlet before using POS or managing inventory."
                        action={
                            <button
                                onClick={openCreate}
                                className="btn-primary btn-sm"
                            >
                                Create Outlet
                            </button>
                        }
                    />
                ) : (
                    <div className="divide-y divide-surface-50">
                        {outlets.map((outlet) => (
                            <div
                                key={outlet.id}
                                className="flex items-center gap-4 py-3.5 first:pt-0 last:pb-0"
                            >
                                <div className="w-10 h-10 rounded-xl bg-surface-100 flex items-center justify-center shrink-0">
                                    <StoreIcon />
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-semibold text-sm text-surface-900">
                                            {outlet.name}
                                        </span>
                                        <span className="badge badge-neutral text-2xs font-mono">
                                            {outlet.code}
                                        </span>
                                        <span
                                            className={`badge text-2xs ${TYPE_COLORS[outlet.outlet_type]}`}
                                        >
                                            {TYPE_LABELS[outlet.outlet_type]}
                                        </span>
                                        {outlet.is_pickup_location && (
                                            <span className="badge badge-success text-2xs">
                                                Pickup
                                            </span>
                                        )}
                                        <StatusBadge
                                            active={outlet.is_active}
                                        />
                                    </div>
                                    <p className="text-xs text-surface-400 mt-0.5 truncate">
                                        {[
                                            outlet.address_line1,
                                            outlet.city,
                                            outlet.country_code,
                                        ]
                                            .filter(Boolean)
                                            .join(", ")}
                                        {outlet.phone && ` · ${outlet.phone}`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-1.5 shrink-0">
                                    <button
                                        onClick={() => openEdit(outlet)}
                                        className="btn-ghost btn-sm"
                                        aria-label="Edit"
                                    >
                                        <EditIcon />
                                    </button>
                                    <button
                                        onClick={() => setDeleting(outlet)}
                                        className="btn-ghost btn-sm text-danger hover:bg-danger-light"
                                        aria-label="Delete"
                                    >
                                        <TrashIcon />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Section>

            {/* ── Modal ─────────────────────────────────────────────────────────── */}
            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit - ${editing.name}` : "Add Outlet"}
                size="lg"
                footer={
                    <>
                        <button
                            onClick={() => setModalOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={handleSubmit((v) =>
                                saveMutation.mutate(v),
                            )}
                            disabled={saveMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {saveMutation.isPending ? (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            ) : null}
                            {editing ? "Save Changes" : "Create Outlet"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                            label="Outlet Code"
                            error={errors.code?.message}
                            required
                            hint="Short unique ID e.g. NBO-01"
                        >
                            <FieldInput
                                className={`input font-mono ${errors.code ? "input-error" : ""}`}
                                {...register("code")}
                                placeholder="NBO-01"
                            />
                        </Field>
                        <Field label="Type">
                            <FieldSelect
                                className="input"
                                {...register("outlet_type")}
                            >
                                <option value="store">Store</option>
                                <option value="warehouse">Warehouse</option>
                                <option value="outlet">Outlet</option>
                                <option value="workshop">Workshop</option>
                            </FieldSelect>
                        </Field>
                        <Field
                            label="Outlet Name"
                            error={errors.name?.message}
                            required
                            className="col-span-2"
                        >
                            <FieldInput
                                className={`input ${errors.name ? "input-error" : ""}`}
                                {...register("name")}
                                placeholder="Nairobi CBD Store"
                            />
                        </Field>
                        <Field label="Email" error={errors.email?.message}>
                            <FieldInput
                                className="input"
                                type="email"
                                {...register("email")}
                                placeholder="nairobi@bethanyhouse.co.ke"
                            />
                        </Field>
                        <Field label="Phone">
                            <FieldInput
                                className="input"
                                {...register("phone")}
                                placeholder="+254 700 000 000"
                            />
                        </Field>
                    </div>

                    <div className="border-t border-surface-100 pt-4">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                            Address
                        </p>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field
                                label="Address Line 1"
                                className="col-span-2"
                            >
                                <FieldInput
                                    className="input"
                                    {...register("address_line1")}
                                    placeholder="123 Moi Avenue"
                                />
                            </Field>
                            <Field
                                label="Address Line 2"
                                className="col-span-2"
                            >
                                <FieldInput
                                    className="input"
                                    {...register("address_line2")}
                                    placeholder="Suite 4"
                                />
                            </Field>
                            <Field label="City">
                                <FieldInput
                                    className="input"
                                    {...register("city")}
                                    placeholder="Nairobi"
                                />
                            </Field>
                            <Field label="State / Province">
                                <FieldInput
                                    className="input"
                                    {...register("state_province")}
                                    placeholder="Nairobi County"
                                />
                            </Field>
                            <Field label="Postal Code">
                                <FieldInput
                                    className="input"
                                    {...register("postal_code")}
                                    placeholder="00100"
                                />
                            </Field>
                            <Field label="Country Code" required>
                                <FieldInput
                                    className="input uppercase"
                                    {...register("country_code")}
                                    placeholder="KE"
                                />
                            </Field>
                        </div>
                    </div>

                    <div className="border-t border-surface-100 pt-4">
                        <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-1">
                            Location & Geofence
                        </p>
                        <p className="text-2xs text-surface-400 mb-3">
                            Used by the Time Clock app to confirm staff are on-site when they
                            clock in. Leave radius blank to disable geofencing for this location.
                        </p>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <Field label="Latitude">
                                <FieldInput
                                    className="input"
                                    type="number"
                                    step="0.0000001"
                                    {...register("latitude", { valueAsNumber: true })}
                                    placeholder="-1.2920660"
                                />
                            </Field>
                            <Field label="Longitude">
                                <FieldInput
                                    className="input"
                                    type="number"
                                    step="0.0000001"
                                    {...register("longitude", { valueAsNumber: true })}
                                    placeholder="36.8219460"
                                />
                            </Field>
                            <Field label="Geofence radius (m)" hint="Typical: 75-150m">
                                <FieldInput
                                    className="input"
                                    type="number"
                                    {...register("geofence_radius_meters", { valueAsNumber: true })}
                                    placeholder="100"
                                />
                            </Field>
                        </div>
                    </div>

                    <div className="space-y-3 pt-2 border-t border-surface-100">
                        <Toggle
                            checked={watch("is_active")}
                            onChange={(v) => setValue("is_active", v)}
                            label="Active"
                            description="This outlet is open and operational."
                        />
                        <Toggle
                            checked={watch("is_pickup_location")}
                            onChange={(v) => setValue("is_pickup_location", v)}
                            label="Pickup location"
                            description="Customers can choose this outlet as a pickup point at checkout."
                        />
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Outlet"
                message={`Remove ${deleting?.name}? This will also remove all user outlet assignments. Inventory data is retained.`}
                confirmLabel="Remove Outlet"
            />
        </div>
    );
}

const StoreIcon = () => (
    <svg
        className="w-5 h-5 text-surface-500"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
        />
        <polyline points="9 22 9 12 15 12 15 22" />
    </svg>
);
const EditIcon = () => (
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
            d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
        />
    </svg>
);
const TrashIcon = () => (
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
            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
        />
    </svg>
);