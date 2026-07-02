import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { currenciesApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import {
    Section,
    Field,
    useFieldAriaProps,
    Toggle,
    StatusBadge,
    DefaultBadge,
    ConfirmDialog,
    EmptyState,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";
import type { Currency, CurrencyFormData } from "@/types/setup";
import type { ApiError } from "@/types";

const schema = z.object({
    code: z.string().min(2).max(10).toUpperCase(),
    name: z.string().min(1, "Name is required"),
    symbol: z.string().min(1, "Symbol is required"),
    exchange_rate: z.coerce
        .number()
        .min(0.0001, "Exchange rate must be positive"),
    decimal_places: z.coerce.number().min(0).max(4),
    thousand_separator: z.string(),
    decimal_separator: z.string(),
    symbol_position: z.enum(["before", "after"]),
    is_default: z.boolean(),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const DEFAULTS: FormValues = {
    code: "",
    name: "",
    symbol: "",
    exchange_rate: 1,
    decimal_places: 2,
    thousand_separator: ",",
    decimal_separator: ".",
    symbol_position: "before",
    is_default: false,
    is_active: true,
};

const PRESETS: Partial<Record<string, Partial<FormValues>>> = {
    KES: {
        code: "KES",
        name: "Kenyan Shilling",
        symbol: "KES",
        exchange_rate: 1,
        decimal_places: 2,
        symbol_position: "before",
    },
    USD: {
        code: "USD",
        name: "US Dollar",
        symbol: "$",
        exchange_rate: 0.0077,
        decimal_places: 2,
        symbol_position: "before",
    },
    EUR: {
        code: "EUR",
        name: "Euro",
        symbol: "€",
        exchange_rate: 0.0071,
        decimal_places: 2,
        symbol_position: "before",
    },
    GBP: {
        code: "GBP",
        name: "British Pound",
        symbol: "£",
        exchange_rate: 0.006,
        decimal_places: 2,
        symbol_position: "before",
    },
};

export default function CurrenciesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Currency | null>(null);
    const [deleting, setDeleting] = useState<Currency | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["currencies"],
        queryFn: () => currenciesApi.list(),
    });

    const currencies = data?.data ?? [];

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
    const openEdit = (c: Currency) => {
        reset({
            code: c.code,
            name: c.name,
            symbol: c.symbol,
            exchange_rate: c.exchange_rate,
            decimal_places: c.decimal_places,
            thousand_separator: c.thousand_separator,
            decimal_separator: c.decimal_separator,
            symbol_position: c.symbol_position,
            is_default: c.is_default,
            is_active: c.is_active,
        });
        setEditing(c);
        setModalOpen(true);
    };

    const saveMutation = useMutation({
        mutationFn: (values: FormValues) =>
            editing
                ? currenciesApi.update(editing.id, values)
                : currenciesApi.create(values),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["currencies"] });
            toast.success(editing ? "Currency updated." : "Currency added.");
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const toggleMutation = useMutation({
        mutationFn: (id: number) => currenciesApi.toggle(id),
        onSuccess: () => qc.invalidateQueries({ queryKey: ["currencies"] }),
        onError: (err: ApiError) => toast.error(err.message),
    });

    const defaultMutation = useMutation({
        mutationFn: (id: number) => currenciesApi.setDefault(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["currencies"] });
            toast.success("Default currency updated.");
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => currenciesApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["currencies"] });
            toast.success("Currency removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const applyPreset = (code: string) => {
        const preset = PRESETS[code];
        if (preset) reset({ ...DEFAULTS, ...preset });
    };

    return (
        <div className="space-y-6 animate-fade-in max-w-4xl">
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Currencies</h1>
                    <p className="page-subtitle">
                        Configure KES and USD. The POS operates in KES; online
                        orders use the customer's selected currency.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary shrink-0 self-start sm:self-auto">
                    + Add Currency
                </button>
            </div>

            <Section title="Active Currencies">
                {isLoading ? (
                    <div className="flex justify-center py-10">
                        <Spinner size="lg" />
                    </div>
                ) : currencies.length === 0 ? (
                    <EmptyState
                        title="No currencies configured"
                        description="Add KES and USD to get started. KES is required for POS operations."
                        action={
                            <button
                                onClick={openCreate}
                                className="btn-primary btn-sm"
                            >
                                Add Currency
                            </button>
                        }
                    />
                ) : (
                    <div className="divide-y divide-surface-50">
                        {currencies.map((currency) => (
                            <div
                                key={currency.id}
                                className="flex items-center gap-4 py-3.5 first:pt-0 last:pb-0"
                            >
                                {/* Symbol pill */}
                                <div className="w-12 h-12 rounded-xl bg-surface-100 flex items-center justify-center shrink-0">
                                    <span className="font-display font-bold text-surface-700 text-sm">
                                        {currency.symbol}
                                    </span>
                                </div>
                                {/* Info */}
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-semibold text-sm text-surface-900">
                                            {currency.code}
                                        </span>
                                        <span className="text-sm text-surface-500">
                                            - {currency.name}
                                        </span>
                                        {currency.is_default && (
                                            <DefaultBadge />
                                        )}
                                        <StatusBadge
                                            active={currency.is_active}
                                        />
                                    </div>
                                    <p className="text-xs text-surface-400 mt-0.5">
                                        Rate: {currency.exchange_rate} ·{" "}
                                        {currency.decimal_places} decimals ·
                                        Symbol {currency.symbol_position}
                                    </p>
                                </div>
                                {/* Actions */}
                                <div className="flex items-center gap-1.5 shrink-0">
                                    {!currency.is_default && (
                                        <button
                                            onClick={() =>
                                                defaultMutation.mutate(
                                                    currency.id,
                                                )
                                            }
                                            className="btn-ghost btn-sm text-xs"
                                            disabled={defaultMutation.isPending}
                                        >
                                            Set default
                                        </button>
                                    )}
                                    <button
                                        onClick={() =>
                                            toggleMutation.mutate(currency.id)
                                        }
                                        className="btn-ghost btn-sm text-xs"
                                        disabled={toggleMutation.isPending}
                                    >
                                        {currency.is_active
                                            ? "Disable"
                                            : "Enable"}
                                    </button>
                                    <button
                                        onClick={() => openEdit(currency)}
                                        className="btn-ghost btn-sm"
                                        aria-label="Edit"
                                    >
                                        <EditIcon />
                                    </button>
                                    {!currency.is_default && (
                                        <button
                                            onClick={() =>
                                                setDeleting(currency)
                                            }
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

            {/* ── Currency modal ──────────────────────────────────────────────────── */}
            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.code}` : "Add Currency"}
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
                            {editing ? "Save Changes" : "Add Currency"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    {/* Presets (only for new) */}
                    {!editing && (
                        <div>
                            <p className="label mb-2">Quick preset</p>
                            <div className="flex gap-2 flex-wrap">
                                {Object.keys(PRESETS).map((code) => (
                                    <button
                                        key={code}
                                        type="button"
                                        onClick={() => applyPreset(code)}
                                        className="btn-secondary btn-sm text-xs"
                                    >
                                        {code}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                            label="Code"
                            error={errors.code?.message}
                            required
                        >
                            <FieldInput
                                className={`input uppercase ${errors.code ? "input-error" : ""}`}
                                {...register("code")}
                                placeholder="KES"
                                disabled={!!editing}
                            />
                        </Field>
                        <Field
                            label="Symbol"
                            error={errors.symbol?.message}
                            required
                        >
                            <FieldInput
                                className={`input ${errors.symbol ? "input-error" : ""}`}
                                {...register("symbol")}
                                placeholder="KES"
                            />
                        </Field>
                        <Field
                            label="Name"
                            error={errors.name?.message}
                            required
                            className="col-span-2"
                        >
                            <FieldInput
                                className={`input ${errors.name ? "input-error" : ""}`}
                                {...register("name")}
                                placeholder="Kenyan Shilling"
                            />
                        </Field>
                        <Field
                            label="Exchange Rate"
                            error={errors.exchange_rate?.message}
                            hint="Relative to KES (KES = 1)"
                        >
                            <FieldInput
                                className="input"
                                type="number"
                                step="0.000001"
                                {...register("exchange_rate")}
                            />
                        </Field>
                        <Field label="Decimal Places">
                            <FieldInput
                                className="input"
                                type="number"
                                min={0}
                                max={4}
                                {...register("decimal_places")}
                            />
                        </Field>
                        <Field label="Thousand Separator">
                            <FieldInput
                                className="input"
                                {...register("thousand_separator")}
                                placeholder=","
                            />
                        </Field>
                        <Field label="Decimal Separator">
                            <FieldInput
                                className="input"
                                {...register("decimal_separator")}
                                placeholder="."
                            />
                        </Field>
                        <Field label="Symbol Position" className="col-span-2">
                            <FieldSelect
                                className="input"
                                {...register("symbol_position")}
                            >
                                <option value="before">
                                    Before amount (e.g. KES 1,000)
                                </option>
                                <option value="after">
                                    After amount (e.g. 1,000 KES)
                                </option>
                            </FieldSelect>
                        </Field>
                    </div>

                    <div className="space-y-3 pt-2 border-t border-surface-100">
                        <Toggle
                            checked={watch("is_active")}
                            onChange={(v) => setValue("is_active", v)}
                            label="Active"
                            description="This currency is available for use."
                        />
                        <Toggle
                            checked={watch("is_default")}
                            onChange={(v) => setValue("is_default", v)}
                            label="Set as default"
                            description="Used as the fallback currency throughout the system."
                        />
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Currency"
                message={`Remove ${deleting?.name} (${deleting?.code})? This cannot be undone.`}
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