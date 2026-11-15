import { useState } from "react";
import { ActionsMenu } from "@/components/ui/ActionsMenu";
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
import { usePermissions } from "@/hooks/usePermissions";
import { waitsForApproval } from "@/api/proposals";
import { PendingChanges, NeedsApprovalHint, PROPOSALS_QUERY_KEY } from "@/components/approvals/PendingChanges";

const schema = z.object({
    code: z.string().min(2).max(10).toUpperCase(),
    name: z.string().min(1, "Name is required"),
    symbol: z.string().min(1, "Symbol is required"),
    // Entered the way people say it — "150 KES per £" — and inverted on save
    // into the stored exchange_rate (units of this currency per 1 KES). The
    // old box asked for the inverted number directly: GBP went in as 0.0066,
    // i.e. 151.5 KES/£, when 150 was meant (cycle 10).
    kes_per_unit: z.coerce.number().min(0.0001, "Enter how many KES one unit costs a customer"),
    // What a unit of EARNED money is worth in reports. There was no field for
    // it at all, so a currency added here reported nothing (cycle 10).
    reporting_rate_to_kes: z.preprocess(
        (v) => (v === "" || v === null || v === undefined ? null : Number(v)),
        z.number().min(0.0001, "Must be positive").nullable(),
    ),
    // The price list customer prices convert from when a product has no price
    // of its own here ("" = shillings). The rand from USD (owner, 2026-10-29).
    priced_from: z.string(),
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
    kes_per_unit: 1,
    reporting_rate_to_kes: null,
    priced_from: "",
    decimal_places: 2,
    thousand_separator: ",",
    decimal_separator: ".",
    symbol_position: "before",
    is_default: false,
    is_active: true,
};

// Presets fill names and symbols only. They used to carry rate guesses
// (USD 0.0077 = 130 KES/$, GBP 0.006 = 166.7 KES/£) that a busy person could
// save without noticing; a rate is a business decision, typed on purpose.
const PRESETS: Partial<Record<string, Partial<FormValues>>> = {
    KES: {
        code: "KES",
        name: "Kenyan Shilling",
        symbol: "KES",
        kes_per_unit: 1,
        reporting_rate_to_kes: 1,
        decimal_places: 2,
        symbol_position: "before",
    },
    USD: {
        code: "USD",
        name: "US Dollar",
        symbol: "$",
        decimal_places: 2,
        symbol_position: "before",
    },
    EUR: {
        code: "EUR",
        name: "Euro",
        symbol: "€",
        decimal_places: 2,
        symbol_position: "before",
    },
    GBP: {
        code: "GBP",
        name: "British Pound",
        symbol: "£",
        decimal_places: 2,
        symbol_position: "before",
    },
};

export default function CurrenciesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<Currency | null>(null);
    // Phase 3C: the two rates are proposals (pricing: admin → finance;
    // reporting: finance → super admin); everything else is the super admin's.
    const { can } = usePermissions();
    const canEdit = can("settings.edit");
    const [effectiveFrom, setEffectiveFrom] = useState("");
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
        setEffectiveFrom("");
        reset({
            code: c.code,
            name: c.name,
            symbol: c.symbol,
            kes_per_unit: c.exchange_rate > 0 ? Math.round((1 / Number(c.exchange_rate)) * 100) / 100 : 1,
            reporting_rate_to_kes: c.reporting_rate_to_kes == null ? null : Number(c.reporting_rate_to_kes),
            priced_from: c.priced_from ?? "",
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

    // The form speaks "KES per 1 unit"; the API stores the inverse, to the six
    // decimals the column holds (150 KES/£ → 0.006667).
    const toPayload = ({ kes_per_unit, priced_from, ...rest }: FormValues): CurrencyFormData => ({
        ...rest,
        priced_from: priced_from || null,
        // An untouched figure sends the stored rate back exactly: the 2-decimal
        // round trip must never read as a pricing-rate change (Phase 3C).
        exchange_rate: editing && Number(kes_per_unit) === (editing.exchange_rate > 0 ? Math.round((1 / Number(editing.exchange_rate)) * 100) / 100 : 1)
            ? Number(editing.exchange_rate)
            : Math.round((1 / kes_per_unit) * 1_000_000) / 1_000_000,
    });

    const saveMutation = useMutation({
        mutationFn: (values: FormValues) =>
            editing
                ? currenciesApi.update(editing.id, { ...toPayload(values), effective_from: effectiveFrom ? new Date(effectiveFrom).toISOString() : null })
                : currenciesApi.create(toPayload(values)),
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["currencies"] });
            qc.invalidateQueries({ queryKey: [PROPOSALS_QUERY_KEY] });
            if (waitsForApproval(res)) toast.info(res.message);
            else toast.success(editing ? "Currency updated." : "Currency added.");
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
                        The currencies you sell in. The till sells in shillings; customers abroad are billed in theirs.
                    </p>
                </div>
                {canEdit && (
                    <button onClick={openCreate} className="btn-primary shrink-0 self-start sm:self-auto">
                        + Add Currency
                    </button>
                )}
            </div>

            <PendingChanges subjectType="currency" subjectIds={currencies.map((c) => c.id)} />

            {/* One explanation for the whole page, instead of one per box. */}
            <dl className="grid gap-x-6 gap-y-1.5 rounded-xl border border-line bg-white px-4 py-3 text-xs text-surface-500 sm:grid-cols-2">
                <div><dt className="inline font-semibold text-surface-900">Customer price</dt> <dd className="inline">— what a customer paying in that currency is charged. A price typed on a product in that currency always wins.</dd></div>
                <div><dt className="inline font-semibold text-surface-900">Books rate</dt> <dd className="inline">— what one unit is worth in sales reports and P&amp;L. It never changes what a customer pays.</dd></div>
            </dl>

            {isLoading ? (
                <div className="flex justify-center py-10"><Spinner size="lg" /></div>
            ) : currencies.length === 0 ? (
                <Section title="Currencies">
                    <EmptyState
                        title="No currencies configured"
                        description="Add KES first: the till and every rate are in shillings."
                        action={<button onClick={openCreate} className="btn-primary btn-sm">Add Currency</button>}
                    />
                </Section>
            ) : (() => {
                const fmt = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const home = currencies.filter((c) => c.is_base);
                const foreign = currencies.filter((c) => !c.is_base);

                const actions = (currency: (typeof currencies)[number]) => (
                    <>
                        {/* Phone: every setting behind one gear (owner, 2026-11-15). */}
                        <div className="sm:hidden">
                            <ActionsMenu
                                trigger="gear"
                                label={`${currency.code} settings`}
                                title={currency.name && currency.name.trim().toUpperCase() !== currency.code ? `${currency.code} · ${currency.name}` : currency.code}
                                items={[
                                    { label: `Edit ${currency.code}`, icon: "edit", run: () => openEdit(currency) },
                                    ...(canEdit && !currency.is_default ? [{ label: "Set as default", icon: "star" as const, run: () => defaultMutation.mutate(currency.id) }] : []),
                                    ...(canEdit ? [{ label: currency.is_active ? "Disable" : "Enable", icon: "power" as const, run: () => toggleMutation.mutate(currency.id) }] : []),
                                    ...(canEdit && !currency.is_default ? [{ label: "Remove", icon: "trash" as const, tone: "danger" as const, run: () => setDeleting(currency) }] : []),
                                ]}
                            />
                        </div>
                        {/* Wider screens: the buttons */}
                        <div className="hidden items-center gap-1.5 sm:flex">
                            {canEdit && !currency.is_default && (
                                <button onClick={() => defaultMutation.mutate(currency.id)} className="btn-ghost btn-sm text-xs" disabled={defaultMutation.isPending}>
                                    Set default
                                </button>
                            )}
                            {canEdit && (
                                <button onClick={() => toggleMutation.mutate(currency.id)} className="btn-ghost btn-sm text-xs" disabled={toggleMutation.isPending}>
                                    {currency.is_active ? "Disable" : "Enable"}
                                </button>
                            )}
                            <button onClick={() => openEdit(currency)} className="btn-secondary btn-sm text-xs" aria-label={`Edit ${currency.code}`}>
                                <EditIcon /> Edit
                            </button>
                            {canEdit && !currency.is_default && (
                                <button onClick={() => setDeleting(currency)} className="btn-ghost btn-sm text-danger hover:bg-danger-light" aria-label={`Delete ${currency.code}`}>
                                    <TrashIcon />
                                </button>
                            )}
                        </div>
                    </>
                );

                const title = (currency: (typeof currencies)[number]) => (
                    <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="font-display text-base font-bold text-surface-900">{currency.code}</span>
                        {currency.name && currency.name.trim().toUpperCase() !== currency.code && (
                            <span className="truncate text-sm text-surface-500">{currency.name}</span>
                        )}
                        {currency.is_default && <DefaultBadge />}
                        {!currency.is_active && <StatusBadge active={false} />}
                    </div>
                );

                return (
                    <>
                        {home.length > 0 && (
                            <Section title="Home currency">
                                {home.map((currency) => (
                                    <div key={currency.id} className="flex items-center justify-between gap-3">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-sm font-bold text-brand-700">{currency.symbol}</div>
                                            <div className="min-w-0">
                                                {title(currency)}
                                                <p className="text-xs text-surface-500">The till sells in it, and every rate below is in it.</p>
                                            </div>
                                        </div>
                                        {actions(currency)}
                                    </div>
                                ))}
                            </Section>
                        )}

                        <Section title={`Foreign currencies (${foreign.length})`}>
                            {foreign.length === 0 ? (
                                <p className="py-4 text-sm text-surface-500">None yet. Add one to bill customers abroad in their own currency.</p>
                            ) : (
                                <div className="divide-y divide-line">
                                    {foreign.map((currency) => {
                                        const customerKes = Number(currency.exchange_rate) > 0 ? 1 / Number(currency.exchange_rate) : null;
                                        const booksKes = currency.reporting_rate_to_kes != null ? Number(currency.reporting_rate_to_kes) : null;
                                        return (
                                            <div key={currency.id} className={`py-4 first:pt-0 last:pb-0 ${currency.is_active ? "" : "opacity-60"}`}>
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex min-w-0 items-center gap-3">
                                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-surface-100 text-sm font-bold text-surface-700">{currency.symbol}</div>
                                                        {title(currency)}
                                                    </div>
                                                    {actions(currency)}
                                                </div>
                                                <dl className="mt-3 grid grid-cols-2 gap-2 sm:ml-14 sm:max-w-lg">
                                                    <div className="rounded-lg bg-surface-50 px-3 py-2">
                                                        <dt className="text-2xs font-medium uppercase tracking-wide text-surface-500">Customer price</dt>
                                                        <dd className="mt-0.5 whitespace-nowrap text-sm font-semibold tabular-nums text-surface-900">
                                                            {customerKes ? `KES ${fmt(customerKes)}` : "—"}
                                                            <span className="font-normal text-surface-500"> = 1 {currency.code}</span>
                                                        </dd>
                                                        <dd className="text-2xs text-surface-500">
                                                            {currency.priced_from ? `converted from ${currency.priced_from} prices` : "converted from KES prices"}
                                                        </dd>
                                                    </div>
                                                    <div className={`rounded-lg px-3 py-2 ${booksKes == null ? "bg-amber-50" : "bg-surface-50"}`}>
                                                        <dt className="text-2xs font-medium uppercase tracking-wide text-surface-500">Books rate</dt>
                                                        <dd className="mt-0.5 whitespace-nowrap text-sm font-semibold tabular-nums text-surface-900">
                                                            {booksKes != null
                                                                ? <>KES {fmt(booksKes)}<span className="font-normal text-surface-500"> = 1 {currency.code}</span></>
                                                                : <span className="text-amber-700">Not set</span>}
                                                        </dd>
                                                        <dd className="text-2xs text-surface-500">
                                                            {booksKes != null ? "in reports and P&L" : "sales left out of reports"}
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </Section>
                    </>
                );
            })()}

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

                    <h4 className="text-2xs font-semibold uppercase tracking-wider text-surface-500">Currency</h4>
                    <div className="grid grid-cols-2 gap-3">
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
                    </div>

                    {/* The two rates, each in its own card with what it means in money. */}
                    <h4 className="pt-1 text-2xs font-semibold uppercase tracking-wider text-surface-500">Rates</h4>
                    <div className="space-y-3">
                        <div className="rounded-xl border border-line bg-surface-50/60 p-3 space-y-3">
                        <p className="text-sm font-semibold text-surface-900">What customers pay</p>
                        <Field
                            label={`Customer price: KES per 1 ${watch("code") || "unit"}`}
                            error={errors.kes_per_unit?.message}
                            hint={(() => {
                                const k = Number(watch("kes_per_unit"));
                                return k > 0
                                    ? `What a customer paying in this currency is charged. Stored as ${(Math.round((1 / k) * 1_000_000) / 1_000_000).toFixed(6)}.`
                                    : "What a customer paying in this currency is charged.";
                            })()}
                        >
                            <FieldInput
                                className="input"
                                type="number"
                                step="0.01"
                                placeholder="150"
                                {...register("kes_per_unit")}
                            />
                            {editing && <NeedsApprovalHint>A change needs approval from finance.</NeedsApprovalHint>}
                        </Field>
                        {(() => {
                            const code   = watch("code") || "this currency";
                            const from   = watch("priced_from");
                            const anchor = currencies.find(c => c.code === from);
                            const k      = Number(watch("kes_per_unit"));
                            const perAnchor = anchor && anchor.exchange_rate > 0 && k > 0 ? (1 / Number(anchor.exchange_rate)) / k : null;
                            return (
                                <Field
                                    label="Customer prices converted from"
                                    hint={perAnchor
                                        ? `A product with no ${code} price of its own: its ${from} price × ${perAnchor.toFixed(4).replace(/\.?0+$/, "")} (1 ${from} = ${perAnchor.toFixed(2)} ${code}). Exact, never rounded. A ${code} price typed on a product always holds.`
                                        : `A product with no ${code} price of its own is converted from its shillings price at the customer rate above. Exact, never rounded. A ${code} price typed on a product always holds.`}
                                >
                                    <FieldSelect className="input" {...register("priced_from")}>
                                        <option value="">The shillings price</option>
                                        {currencies
                                            .filter(c => !c.is_base && c.is_active && c.code !== watch("code") && !c.priced_from)
                                            .map(c => <option key={c.code} value={c.code}>The {c.code} price</option>)}
                                    </FieldSelect>
                                    {editing && <NeedsApprovalHint>A change needs approval from finance, with the rate.</NeedsApprovalHint>}
                                </Field>
                            );
                        })()}
                        </div>
                        <div className="rounded-xl border border-line bg-surface-50/60 p-3 space-y-3">
                        <p className="text-sm font-semibold text-surface-900">What the books count</p>
                        <Field
                            label={`Reporting rate: KES per 1 ${watch("code") || "unit"}`}
                            error={errors.reporting_rate_to_kes?.message}
                            hint="What a unit of earned money is worth in reports. Leave empty only if you never sell in this currency: its sales would be left out of every report."
                        >
                            <FieldInput
                                className="input"
                                type="number"
                                step="0.01"
                                placeholder="165"
                                {...register("reporting_rate_to_kes")}
                            />
                            {editing && <NeedsApprovalHint>A change needs approval from the super admin.</NeedsApprovalHint>}
                        </Field>
                        {editing && (
                            <Field
                                label="Reporting rate takes effect"
                                hint="Leave empty for 'when approved'. Never earlier than now."
                                className="sm:col-span-2"
                            >
                                <FieldInput
                                    className="input"
                                    type="datetime-local"
                                    value={effectiveFrom}
                                    onChange={(e) => setEffectiveFrom(e.target.value)}
                                />
                            </Field>
                        )}
                        </div>
                    </div>

                    <h4 className="pt-1 text-2xs font-semibold uppercase tracking-wider text-surface-500">Display</h4>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
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
                        <Field label="Symbol Position" className="sm:col-span-3">
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

                    <div className="space-y-3 pt-2 border-t border-line">
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