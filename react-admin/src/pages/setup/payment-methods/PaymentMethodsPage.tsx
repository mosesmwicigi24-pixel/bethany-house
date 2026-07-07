import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { paymentMethodsApi } from "@/api/setup";
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
import type { PaymentMethodSetup } from "@/types/setup";
import type { ApiError } from "@/types";

const schema = z.object({
    name: z.string().min(1, "Name is required"),
    code: z.string().min(1, "Code is required"),
    type: z.enum(["mobile_money", "card", "cash", "bank_transfer"]),
    provider: z.string(),
    description: z.string(),
    is_active: z.boolean(),
    is_default: z.boolean(),
    requires_approval: z.boolean(),
    sort_order: z.coerce.number().min(0),
    supported_currencies: z.array(z.string()),
});

type FormValues = z.infer<typeof schema>;

const DEFAULTS: FormValues = {
    name: "",
    code: "",
    type: "card",
    provider: "",
    description: "",
    is_active: true,
    is_default: false,
    requires_approval: false,
    sort_order: 0,
    supported_currencies: ["KES"],
};

const PRESETS = [
    {
        label: "M-PESA",
        icon: "phone",
        values: {
            name: "M-PESA",
            code: "mpesa",
            type: "mobile_money" as const,
            provider: "safaricom",
            description: "Pay via Safaricom M-PESA STK Push",
            is_active: true,
            is_default: true,
            sort_order: 1,
            supported_currencies: ["KES"],
        },
    },
    {
        label: "Paystack",
        icon: "card",
        values: {
            name: "Paystack",
            code: "paystack",
            type: "card" as const,
            provider: "paystack",
            description: "Pay with Visa, Mastercard via Paystack",
            is_active: true,
            is_default: false,
            sort_order: 2,
            supported_currencies: ["KES", "USD"],
        },
    },
    {
        label: "Flutterwave",
        icon: "card",
        values: {
            name: "Flutterwave",
            code: "flutterwave",
            type: "card" as const,
            provider: "flutterwave",
            description: "Pay with cards and mobile money via Flutterwave",
            is_active: false,
            is_default: false,
            sort_order: 3,
            supported_currencies: ["KES", "USD"],
        },
    },
    {
        label: "Cash",
        icon: "cash",
        values: {
            name: "Cash",
            code: "cash",
            type: "cash" as const,
            provider: "",
            description: "Pay in cash at the store (POS only)",
            is_active: true,
            is_default: false,
            sort_order: 4,
            supported_currencies: ["KES"],
        },
    },
];

const PROVIDER_ICONS: Record<string, string> = {
    safaricom: "phone",
    paystack: "card",
    flutterwave: "card",
    "": "money",
};

const PaymentIcon = ({
    name,
    className = "w-5 h-5",
}: {
    name: string;
    className?: string;
}) => {
    const s = {
        className,
        fill: "none" as const,
        viewBox: "0 0 24 24",
        stroke: "currentColor",
        strokeWidth: 1.75,
        strokeLinecap: "round" as const,
        strokeLinejoin: "round" as const,
    };
    if (name === "phone")
        return (
            <svg {...s}>
                <rect x="5" y="2" width="14" height="20" rx="2" />
                <line x1="12" y1="18" x2="12.01" y2="18" />
            </svg>
        );
    if (name === "card")
        return (
            <svg {...s}>
                <rect x="1" y="4" width="22" height="16" rx="2" />
                <line x1="1" y1="10" x2="23" y2="10" />
            </svg>
        );
    if (name === "cash")
        return (
            <svg {...s}>
                <rect x="2" y="6" width="20" height="12" rx="2" />
                <circle cx="12" cy="12" r="2" />
                <path d="M6 12h.01M18 12h.01" />
            </svg>
        );
    return (
        <svg {...s}>
            <circle cx="12" cy="12" r="10" />
            <path d="M12 8v4l3 3" />
        </svg>
    );
};

const TYPE_LABELS = {
    mobile_money: "Mobile Money",
    card: "Card",
    cash: "Cash",
    bank_transfer: "Bank Transfer",
};
const TYPE_COLORS = {
    mobile_money: "bg-success-light text-success",
    card: "bg-info-light text-info",
    cash: "bg-warning-light text-warning",
    bank_transfer: "bg-surface-100 text-surface-600",
};

export default function PaymentMethodsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [configModalOpen, setConfigModal] = useState(false);
    const [editing, setEditing] = useState<PaymentMethodSetup | null>(null);
    const [configuring, setConfiguring] = useState<PaymentMethodSetup | null>(
        null,
    );
    const [deleting, setDeleting] = useState<PaymentMethodSetup | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["payment-methods"],
        queryFn: () => paymentMethodsApi.list(),
    });

    const methods = data?.data ?? [];

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

    // Config form - gateway credentials
    const [configValues, setConfigValues] = useState<Record<string, string>>(
        {},
    );
    const configFields: Record<
        string,
        { key: string; label: string; secret?: boolean }[]
    > = {
        mpesa: [
            { key: "consumer_key", label: "Consumer Key", secret: true },
            { key: "consumer_secret", label: "Consumer Secret", secret: true },
            { key: "shortcode", label: "Shortcode" },
            { key: "passkey", label: "Passkey", secret: true },
            { key: "environment", label: "Environment (sandbox/production)" },
        ],
        paystack: [
            { key: "public_key", label: "Public Key" },
            { key: "secret_key", label: "Secret Key", secret: true },
        ],
        flutterwave: [
            { key: "public_key", label: "Public Key" },
            { key: "secret_key", label: "Secret Key", secret: true },
            { key: "encryption_key", label: "Encryption Key", secret: true },
        ],
        cash: [],
    };

    const openCreate = () => {
        reset(DEFAULTS);
        setEditing(null);
        setModalOpen(true);
    };
    const openEdit = (m: PaymentMethodSetup) => {
        reset({
            name: m.name,
            code: m.code,
            type: m.type,
            provider: m.provider ?? "",
            description: m.description ?? "",
            is_active: m.is_active,
            is_default: m.is_default,
            requires_approval: m.requires_approval ?? false,
            sort_order: m.sort_order,
            supported_currencies: m.supported_currencies,
        });
        setEditing(m);
        setModalOpen(true);
    };
    const openConfig = (m: PaymentMethodSetup) => {
        setConfiguring(m);
        setConfigValues((m as any).config ?? {});
        setConfigModal(true);
    };

    const saveMutation = useMutation({
        mutationFn: (v: FormValues) =>
            editing
                ? paymentMethodsApi.update(editing.id, v)
                : paymentMethodsApi.create(v),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["payment-methods"] });
            toast.success(
                editing ? "Payment method updated." : "Payment method added.",
            );
            setModalOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const configMutation = useMutation({
        mutationFn: () =>
            paymentMethodsApi.updateConfig(configuring!.id, configValues),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["payment-methods"] });
            toast.success("Credentials saved.");
            setConfigModal(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const toggleMutation = useMutation({
        mutationFn: (id: number) => paymentMethodsApi.toggle(id),
        onSuccess: () =>
            qc.invalidateQueries({ queryKey: ["payment-methods"] }),
        onError: (err: ApiError) => toast.error(err.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => paymentMethodsApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["payment-methods"] });
            toast.success("Payment method removed.");
            setDeleting(null);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const toggleCurrency = (code: string) => {
        const current = watch("supported_currencies");
        setValue(
            "supported_currencies",
            current.includes(code)
                ? current.filter((c) => c !== code)
                : [...current, code],
        );
    };

    return (
        <div className="space-y-6 animate-fade-in max-w-4xl">
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Payment Methods</h1>
                    <p className="page-subtitle">
                        M-PESA is required for KES POS and online sales. Add
                        Paystack or Flutterwave for card payments.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary shrink-0 self-start sm:self-auto">
                    + Add Method
                </button>
            </div>

            <Section title="Configured Methods">
                {isLoading ? (
                    <div className="flex justify-center py-10">
                        <Spinner size="lg" />
                    </div>
                ) : methods.length === 0 ? (
                    <EmptyState
                        title="No payment methods configured"
                        description="Add M-PESA as the default, then Paystack for card payments."
                        action={
                            <button
                                onClick={openCreate}
                                className="btn-primary btn-sm"
                            >
                                Add Payment Method
                            </button>
                        }
                    />
                ) : (
                    <div className="divide-y divide-surface-50">
                        {methods
                            .sort((a, b) => a.sort_order - b.sort_order)
                            .map((method) => (
                                <div
                                    key={method.id}
                                    className="flex items-center gap-4 py-3.5 first:pt-0 last:pb-0"
                                >
                                    <div className="w-10 h-10 rounded-xl bg-surface-100 flex items-center justify-center shrink-0 text-surface-600">
                                        <PaymentIcon
                                            name={
                                                PROVIDER_ICONS[
                                                    method.provider ?? ""
                                                ] ?? "money"
                                            }
                                        />
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="font-semibold text-sm text-surface-900">
                                                {method.name}
                                            </span>
                                            <span
                                                className={`badge text-2xs ${TYPE_COLORS[method.type]}`}
                                            >
                                                {TYPE_LABELS[method.type]}
                                            </span>
                                            {method.is_default && (
                                                <DefaultBadge />
                                            )}
                                            {method.requires_approval && (
                                                <span className="badge text-2xs bg-amber-100 text-amber-700">
                                                    Needs approval
                                                </span>
                                            )}
                                            <StatusBadge
                                                active={method.is_active}
                                            />
                                        </div>
                                        <div className="flex items-center gap-2 mt-0.5">
                                            <p className="text-xs text-surface-400 truncate">
                                                {method.description}
                                            </p>
                                            <div className="flex gap-1">
                                                {method.supported_currencies.map(
                                                    (c) => (
                                                        <span
                                                            key={c}
                                                            className="badge badge-neutral text-2xs"
                                                        >
                                                            {c}
                                                        </span>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-1.5 shrink-0">
                                        {method.code !== "cash" && (
                                            <button
                                                onClick={() =>
                                                    openConfig(method)
                                                }
                                                className="btn-ghost btn-sm text-xs"
                                            >
                                                Credentials
                                            </button>
                                        )}
                                        <button
                                            onClick={() =>
                                                toggleMutation.mutate(method.id)
                                            }
                                            className="btn-ghost btn-sm text-xs"
                                        >
                                            {method.is_active
                                                ? "Disable"
                                                : "Enable"}
                                        </button>
                                        <button
                                            onClick={() => openEdit(method)}
                                            className="btn-ghost btn-sm"
                                            aria-label="Edit"
                                        >
                                            <EditIcon />
                                        </button>
                                        <button
                                            onClick={() => setDeleting(method)}
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

            {/* ── Add/Edit modal ─────────────────────────────────────────────────── */}
            <Modal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title={editing ? `Edit ${editing.name}` : "Add Payment Method"}
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
                                        className="btn-secondary btn-sm text-xs flex items-center gap-1.5"
                                    >
                                        <PaymentIcon
                                            name={p.icon}
                                            className="w-3.5 h-3.5"
                                        />{" "}
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
                                className={`input ${errors.name ? "input-error" : ""}`}
                                {...register("name")}
                                placeholder="M-PESA"
                            />
                        </Field>
                        <Field
                            label="Code"
                            error={errors.code?.message}
                            required
                            hint="Unique identifier"
                        >
                            <FieldInput
                                className={`input ${errors.code ? "input-error" : ""}`}
                                {...register("code")}
                                placeholder="mpesa"
                                disabled={!!editing}
                            />
                        </Field>
                        <Field label="Type">
                            <FieldSelect
                                className="input"
                                {...register("type")}
                            >
                                <option value="mobile_money">
                                    Mobile Money
                                </option>
                                <option value="card">Card</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">
                                    Bank Transfer
                                </option>
                            </FieldSelect>
                        </Field>
                        <Field label="Provider" hint="e.g. safaricom, paystack">
                            <FieldInput
                                className="input"
                                {...register("provider")}
                                placeholder="safaricom"
                            />
                        </Field>
                        <Field label="Description" className="col-span-2">
                            <FieldInput
                                className="input"
                                {...register("description")}
                                placeholder="Pay via M-PESA STK Push"
                            />
                        </Field>
                        <Field label="Sort Order" hint="Lower = shown first">
                            <FieldInput
                                className="input"
                                type="number"
                                min={0}
                                {...register("sort_order")}
                            />
                        </Field>
                        <Field label="Supported Currencies">
                            <div className="flex gap-2">
                                {["KES", "USD"].map((c) => (
                                    <label
                                        key={c}
                                        className="flex items-center gap-1.5 cursor-pointer text-sm"
                                    >
                                        <FieldInput
                                            type="checkbox"
                                            checked={watch(
                                                "supported_currencies",
                                            ).includes(c)}
                                            onChange={() => toggleCurrency(c)}
                                            className="accent-brand-500"
                                        />
                                        {c}
                                    </label>
                                ))}
                            </div>
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
                            label="Set as default payment method"
                        />
                        <div>
                            <Toggle
                                checked={watch("requires_approval")}
                                onChange={(v) => setValue("requires_approval", v)}
                                label="Requires admin approval"
                            />
                            <p className="text-2xs text-surface-400 mt-1">
                                On → payments are held pending until an admin approves them
                                (cheque, bank transfer, Western Union, MoneyGram). Off → they
                                settle immediately with a notification (cash, I&M, M-Pesa, card).
                            </p>
                        </div>
                    </div>
                </div>
            </Modal>

            {/* ── Credentials modal ──────────────────────────────────────────────── */}
            <Modal
                open={configModalOpen}
                onClose={() => setConfigModal(false)}
                title={`${configuring?.name} - Credentials`}
                size="md"
                footer={
                    <>
                        <button
                            onClick={() => setConfigModal(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={() => configMutation.mutate()}
                            disabled={configMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {configMutation.isPending ? (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            ) : null}
                            Save Credentials
                        </button>
                    </>
                }
            >
                <div className="space-y-3">
                    <p className="text-xs text-surface-500 bg-warning-light rounded-lg px-3 py-2 flex items-start gap-1.5">
                        <svg
                            className="w-3.5 h-3.5 shrink-0 mt-0.5 text-warning-dark"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                            <line x1="12" y1="9" x2="12" y2="13" />
                            <line x1="12" y1="17" x2="12.01" y2="17" />
                        </svg>
                        Credentials are stored encrypted. Never share them. Use
                        sandbox keys for development.
                    </p>
                    {(configFields[configuring?.code ?? ""] ?? []).map(
                        (field) => (
                            <Field key={field.key} label={field.label}>
                                <FieldInput
                                    className="input font-mono text-xs"
                                    type={field.secret ? "password" : "text"}
                                    value={configValues[field.key] ?? ""}
                                    onChange={(e) =>
                                        setConfigValues((prev) => ({
                                            ...prev,
                                            [field.key]: e.target.value,
                                        }))
                                    }
                                    placeholder={
                                        field.secret ? "••••••••••••" : ""
                                    }
                                />
                            </Field>
                        ),
                    )}
                    {(configFields[configuring?.code ?? ""] ?? []).length ===
                        0 && (
                        <p className="text-sm text-surface-500 py-4 text-center">
                            No credentials required for this payment method.
                        </p>
                    )}
                </div>
            </Modal>

            <ConfirmDialog
                open={!!deleting}
                onClose={() => setDeleting(null)}
                onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
                isLoading={deleteMutation.isPending}
                title="Remove Payment Method"
                message={`Remove ${deleting?.name}? This will disable it for all future transactions.`}
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