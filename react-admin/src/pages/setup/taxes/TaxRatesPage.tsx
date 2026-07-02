import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { taxRatesApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import {
    Section,
    Field, useFieldAriaProps,
    Toggle,
    StatusBadge,
    DefaultBadge,
    ConfirmDialog,
    EmptyState,
    FieldInput, FieldSelect, FieldTextarea
} from "@/components/setup/FormComponents";
import type { TaxRate } from "@/types/setup";
import type { ApiError } from "@/types";

const schema = z.object({
    name: z.string().min(1, "Name is required"),
    code: z.string().min(1, "Code is required"),
    rate: z.coerce.number().min(0).max(100),
    type: z.enum(["percentage", "fixed"]),
    applies_to: z.enum(["all", "products", "shipping"]),
    country_code: z.string().nullable(),
    is_default: z.boolean(),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const DEFAULTS: FormValues = {
    name: "",
    code: "",
    rate: 16,
    type: "percentage",
    applies_to: "all",
    country_code: "KE",
    is_default: false,
    is_active: true,
};

const PRESETS = [
    {
        label: "VAT 16% Kenya",
        values: {
            name: "VAT",
            code: "VAT_KE_16",
            rate: 16,
            type: "percentage" as const,
            applies_to: "all" as const,
            country_code: "KE",
            is_default: true,
            is_active: true,
        },
    },
    {
        label: "No Tax (0%)",
        values: {
            name: "No Tax",
            code: "ZERO_RATED",
            rate: 0,
            type: "percentage" as const,
            applies_to: "all" as const,
            country_code: null,
            is_default: false,
            is_active: true,
        },
    },
    {
        label: "USD Sales Tax 10%",
        values: {
            name: "Sales Tax",
            code: "SALES_TAX_10",
            rate: 10,
            type: "percentage" as const,
            applies_to: "products" as const,
            country_code: "US",
            is_default: false,
            is_active: true,
        },
    },
];

export default function TaxRatesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<TaxRate | null>(null);
    const [deleting, setDeleting] = useState<TaxRate | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["tax-rates"],
        queryFn: () => taxRatesApi.list(),
    });

    const taxRates = data?.data ?? [];

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
    const openEdit = (t: TaxRate) => {
        reset({
            name: t.name,
            code: t.code,
            rate: t.rate,
            type: t.type,
            applies_to: t.applies_to,
            country_code: t.country_code,
            is_default: t.is_default,
            is_active: t.is_active,
        });
        setEditing(t);
        setModalOpen(true);
    };

    const saveMutation = useMutation({
        mutationFn: (v: FormValues) =>
            editing ? taxRatesApi.update(editing.id, v) : taxRatesApi.create(v),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["tax-rates"] });
            toast.success(editing ? "Tax rate updated." : "Tax rate added.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const toggleMutation = useMutation({
        mutationFn: (id: number) => taxRatesApi.toggle(id),
        onSuccess: () => qc.invalidateQueries({ queryKey: ["tax-rates"] }),
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => taxRatesApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["tax-rates"] });
            toast.success("Tax rate removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <div className="space-y-6 animate-fade-in max-w-4xl">
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Tax Rates</h1>
                    <p className="page-subtitle">
                        Kenya VAT is 16%. Rates are applied at checkout and on
                        POS sales. Zero-rated items should use 0%.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary shrink-0 self-start sm:self-auto">
                    + Add Tax Rate
                </button>
            </div>

            <Section title="Configured Tax Rates">
                {isLoading ? (
                    <div className="flex justify-center py-10">
                        <Spinner size="lg" />
                    </div>
                ) : taxRates.length === 0 ? (
                    <EmptyState
                        title="No tax rates configured"
                        description="Add VAT 16% for Kenya to get started. Required for correct pricing on POS and checkout."
                        action={
                            <button
                                onClick={openCreate}
                                className="btn-primary btn-sm"
                            >
                                Add Tax Rate
                            </button>
                        }
                    />
                ) : (
                    <div className="divide-y divide-surface-50">
                        {taxRates.map((tax) => (
                            <div
                                key={tax.id}
                                className="flex items-center gap-4 py-3.5 first:pt-0 last:pb-0"
                            >
                                <div className="w-16 h-12 rounded-xl bg-surface-100 flex items-center justify-center shrink-0">
                                    <span className="font-display font-bold text-surface-700 text-sm">
                                        {tax.type === "percentage"
                                            ? `${tax.rate}%`
                                            : `${tax.rate}`}
                                    </span>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-semibold text-sm text-surface-900">
                                            {tax.name}
                                        </span>
                                        <span className="badge badge-neutral text-2xs">
                                            {tax.code}
                                        </span>
                                        {tax.country_code && (
                                            <span className="badge badge-neutral text-2xs">
                                                {tax.country_code}
                                            </span>
                                        )}
                                        {!tax.country_code && (
                                            <span className="badge badge-info text-2xs">
                                                Global
                                            </span>
                                        )}
                                        {tax.is_default && <DefaultBadge />}
                                        <StatusBadge active={tax.is_active} />
                                        {/* Phase 2 - product count badge */}
                                        {(tax as any).product_count > 0 && (
                                            <span className="text-2xs font-medium text-surface-500 bg-surface-100 px-2 py-0.5 rounded-full">
                                                {(tax as any).product_count} product{(tax as any).product_count !== 1 ? "s" : ""}
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-xs text-surface-400 mt-0.5">
                                        Applies to: {tax.applies_to} ·{" "}
                                        {tax.type === "percentage"
                                            ? `${tax.rate}% rate`
                                            : `Fixed ${tax.rate}`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-1.5 shrink-0">
                                    <button
                                        onClick={() =>
                                            toggleMutation.mutate(tax.id)
                                        }
                                        className="btn-ghost btn-sm text-xs"
                                    >
                                        {tax.is_active ? "Disable" : "Enable"}
                                    </button>
                                    <button
                                        onClick={() => openEdit(tax)}
                                        className="btn-ghost btn-sm"
                                        aria-label="Edit"
                                    >
                                        <EditIcon />
                                    </button>
                                    {!tax.is_default && (
                                        <button
                                            onClick={() => setDeleting(tax)}
                                            className="btn-ghost btn-sm text-danger hover:bg-danger-light"
                                            aria-label="Delete"
                                        >
                                            <TrashIcon />
                                        </button>
                                    )}
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
                title={editing ? `Edit ${editing.name}` : "Add Tax Rate"}
                size="md"
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
                            {editing ? "Save" : "Add"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    {!editing && (
                        <div>
                            <p className="label mb-2">Quick preset</p>
                            <div className="flex gap-2 flex-wrap">
                                {PRESETS.map((p) => (
                                    <button
                                        key={p.label}
                                        type="button"
                                        onClick={() =>
                                            reset({ ...DEFAULTS, ...p.values })
                                        }
                                        className="btn-secondary btn-sm text-xs"
                                    >
                                        {p.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                            label="Name"
                            error={errors.name?.message}
                            required
                        >
                            <FieldInput
                                className="input"
                                {...register("name")}
                                placeholder="VAT"
                            />
                        </Field>
                        <Field
                            label="Code"
                            error={errors.code?.message}
                            required
                            hint="Unique identifier"
                        >
                            <FieldInput
                                className="input"
                                {...register("code")}
                                placeholder="VAT_KE_16"
                            />
                        </Field>
                        <Field
                            label="Rate"
                            error={errors.rate?.message}
                            required
                        >
                            <FieldInput
                                className="input"
                                type="number"
                                step="0.01"
                                min={0}
                                max={100}
                                {...register("rate")}
                            />
                        </Field>
                        <Field label="Type">
                            <FieldSelect className="input" {...register("type")}>
                                <option value="percentage">
                                    Percentage (%)
                                </option>
                                <option value="fixed">Fixed amount</option>
                            </FieldSelect>
                        </Field>
                        <Field label="Applies to">
                            <FieldSelect
                                className="input"
                                {...register("applies_to")}
                            >
                                <option value="all">
                                    All (products + shipping)
                                </option>
                                <option value="products">Products only</option>
                                <option value="shipping">Shipping only</option>
                            </FieldSelect>
                        </Field>
                        <Field
                            label="Country code"
                            hint="Leave empty for global rate"
                        >
                            <FieldInput
                                className="input"
                                {...register("country_code")}
                                placeholder="KE"
                            />
                        </Field>
                    </div>

                    <div className="space-y-3 pt-2 border-t border-surface-100">
                        <Toggle
                            checked={watch("is_active")}
                            onChange={(v) => setValue("is_active", v)}
                            label="Active"
                        />
                        <Toggle
                            checked={watch("is_default")}
                            onChange={(v) => setValue("is_default", v)}
                            label="Set as default tax rate"
                        />
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Tax Rate"
                message={`Remove ${deleting?.name}? This may affect product pricing if it is assigned to products.`}
                confirmLabel="Remove"
            />
        </div>
    );
}

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