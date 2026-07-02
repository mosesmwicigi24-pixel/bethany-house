import { useState, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { get, post, put } from "@/api/client";
import { currenciesApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { DataTable } from "@/components/ui/DataTable";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { Field, useFieldAriaProps, Toggle, StatusBadge, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import type { ApiError } from "@/types";

// ── Types ─────────────────────────────────────────────────────────────────────

interface Country {
    id: number;
    code: string;
    name: string;
    native_name: string | null;
    region: string | null;
    subregion: string | null;
    default_currency_code: string | null;
    phone_code: string | null;
    flag: string | null;
    is_active: boolean;
    is_shipping_enabled: boolean;
    free_shipping_threshold: number | null;
    standard_shipping_cost: number | null;
    express_shipping_cost: number | null;
    estimated_delivery_days: number | null;
}

// ── Schemas ───────────────────────────────────────────────────────────────────

const countrySchema = z.object({
    code: z.string().min(2, "Required").max(3, "Max 3 chars").toUpperCase(),
    name: z.string().min(1, "Required"),
    native_name: z.string().optional(),
    phone_code: z.string().optional(),
    flag: z.string().optional(),
    region: z.string().optional(),
    subregion: z.string().optional(),
    default_currency_code: z.string().optional(),
    is_active: z.boolean(),
    is_shipping_enabled: z.boolean(),
    free_shipping_threshold: z.coerce.number().min(0).optional().nullable(),
    standard_shipping_cost: z.coerce.number().min(0).optional().nullable(),
    express_shipping_cost: z.coerce.number().min(0).optional().nullable(),
    estimated_delivery_days: z.coerce
        .number()
        .int()
        .min(1)
        .optional()
        .nullable(),
});

const editSchema = countrySchema.omit({ code: true });

type CreateFormValues = z.infer<typeof countrySchema>;
type EditFormValues = z.infer<typeof editSchema>;

const CREATE_DEFAULTS: CreateFormValues = {
    code: "",
    name: "",
    native_name: "",
    phone_code: "",
    flag: "",
    region: "",
    subregion: "",
    default_currency_code: "",
    is_active: true,
    is_shipping_enabled: false,
    free_shipping_threshold: null,
    standard_shipping_cost: null,
    express_shipping_cost: null,
    estimated_delivery_days: null,
};

// ── API ───────────────────────────────────────────────────────────────────────

const countriesApi = {
    list: (params?: Record<string, string>) =>
        get<{
            data: Country[];
            grouped: Record<string, Country[]>;
            stats: { total: number; active: number; shipping_enabled: number };
        }>("/v1/admin/countries", { params }),
    regions: () => get<{ data: string[] }>("/v1/admin/countries/regions"),
    create: (data: CreateFormValues) =>
        post<{ message: string; country: Country }>(
            "/v1/admin/countries",
            data,
        ),
    update: (code: string, data: EditFormValues) =>
        put<{ message: string; country: Country }>(
            `/v1/admin/countries/${code}`,
            data,
        ),
    toggle: (code: string) =>
        put<{ message: string; country: Country }>(
            `/v1/admin/countries/${code}/toggle`,
        ),
};

// ── REGIONS reference data ────────────────────────────────────────────────────

const WORLD_REGIONS = [
    "Africa",
    "Americas",
    "Asia",
    "Europe",
    "Oceania",
    "Antarctica",
];

// ── CountryFields - defined outside component to prevent remount on re-render ──

interface CountryFieldsProps {
    form: any;
    activeCurrencies: { code: string; name: string; symbol: string }[];
}

function CountryFields({ form, activeCurrencies }: CountryFieldsProps) {
    return (
        <div className="space-y-4">
            {/* Identity */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field
                    label="Country Name"
                    error={form.formState.errors.name?.message}
                    required
                >
                    <FieldInput
                        className={`input ${form.formState.errors.name ? "input-error" : ""}`}
                        {...form.register("name")}
                        placeholder="Kenya"
                    />
                </Field>
                <Field label="Native Name" hint="Name in local language">
                    <FieldInput
                        className="input"
                        {...form.register("native_name")}
                        placeholder="Kenya"
                    />
                </Field>
                <Field label="Flag Emoji" hint="Paste a flag emoji e.g. 🇰🇪">
                    <FieldInput
                        className="input"
                        {...form.register("flag")}
                        placeholder="🇰🇪"
                    />
                </Field>
                <Field label="Phone Code" hint="E.g. +254">
                    <FieldInput
                        className="input"
                        {...form.register("phone_code")}
                        placeholder="+254"
                    />
                </Field>
                <Field label="Region">
                    <FieldSelect className="input" {...form.register("region")}>
                        <option value="">- Select region -</option>
                        {WORLD_REGIONS.map((r) => (
                            <option key={r} value={r}>
                                {r}
                            </option>
                        ))}
                    </FieldSelect>
                </Field>
                <Field label="Subregion">
                    <FieldInput
                        className="input"
                        {...form.register("subregion")}
                        placeholder="Eastern Africa"
                    />
                </Field>
                {/* Currency - dropdown from DB, enforces FK relationship */}
                <Field
                    label="Default Currency"
                    hint="Must be an active currency in the system"
                >
                    <FieldSelect
                        className="input"
                        {...form.register("default_currency_code")}
                    >
                        <option value="">- None -</option>
                        {activeCurrencies.map((c: any) => (
                            <option key={c.code} value={c.code}>
                                {c.code} - {c.name} ({c.symbol})
                            </option>
                        ))}
                    </FieldSelect>
                </Field>
            </div>

            {/* Status toggles */}
            <div className="border-t border-surface-100 pt-4 space-y-3">
                <Toggle
                    checked={form.watch("is_active")}
                    onChange={(v: boolean) => form.setValue("is_active", v)}
                    label="Active"
                    description="Country appears in storefront dropdowns and can be used in orders."
                />
                <Toggle
                    checked={form.watch("is_shipping_enabled")}
                    onChange={(v: boolean) =>
                        form.setValue("is_shipping_enabled", v)
                    }
                    label="Shipping enabled"
                    description="Orders can be shipped to this country."
                />
            </div>

            {/* Shipping costs - only when shipping enabled */}
            {form.watch("is_shipping_enabled") && (
                <div className="border-t border-surface-100 pt-4">
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                        Shipping Costs
                    </p>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field
                            label="Free Shipping Threshold"
                            hint="Order value above which shipping is free"
                        >
                            <FieldInput
                                className="input"
                                type="number"
                                min="0"
                                step="0.01"
                                {...form.register("free_shipping_threshold")}
                                placeholder="0.00"
                            />
                        </Field>
                        <Field label="Standard Shipping Cost">
                            <FieldInput
                                className="input"
                                type="number"
                                min="0"
                                step="0.01"
                                {...form.register("standard_shipping_cost")}
                                placeholder="0.00"
                            />
                        </Field>
                        <Field label="Express Shipping Cost">
                            <FieldInput
                                className="input"
                                type="number"
                                min="0"
                                step="0.01"
                                {...form.register("express_shipping_cost")}
                                placeholder="0.00"
                            />
                        </Field>
                        <Field label="Estimated Delivery Days">
                            <FieldInput
                                className="input"
                                type="number"
                                min="1"
                                max="365"
                                {...form.register("estimated_delivery_days")}
                                placeholder="5"
                            />
                        </Field>
                    </div>
                </div>
            )}
        </div>
    );
}

// ── Component ──────────────────────────────────────────────────────────────────

export default function CountriesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const table = useTableState({ defaultSortBy: "name", defaultPerPage: 50 });

    const [regionFilter, setRegionFilter] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [createOpen, setCreateOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editing, setEditing] = useState<Country | null>(null);

    const params: Record<string, string> = {
        ...table.toParams(),
        ...(regionFilter && { region: regionFilter }),
        ...(statusFilter && { is_active: statusFilter }),
    };

    // ── Queries ──────────────────────────────────────────────────────────────────

    const { data, isLoading } = useQuery({
        queryKey: ["countries", params],
        queryFn: () => countriesApi.list(params),
    });

    const { data: regionsData } = useQuery({
        queryKey: ["country-regions"],
        queryFn: () => countriesApi.regions(),
    });

    const { data: currenciesData } = useQuery({
        queryKey: ["currencies"],
        queryFn: () => currenciesApi.list(),
    });

    const countries = data?.data ?? [];
    const regions = regionsData?.data ?? [];
    const activeCurrencies =
        currenciesData?.data?.filter((c: any) => c.is_active) ?? [];
    const activeCount = data?.stats?.active ?? 0;
    const shippingCount = data?.stats?.shipping_enabled ?? 0;

    // ── Forms ─────────────────────────────────────────────────────────────────────

    const createForm = useForm<CreateFormValues>({
        resolver: zodResolver(countrySchema),
        defaultValues: CREATE_DEFAULTS,
    });

    const editForm = useForm<EditFormValues>({
        resolver: zodResolver(editSchema),
        defaultValues: {},
    });

    // ── Handlers ─────────────────────────────────────────────────────────────────

    const openCreate = useCallback(() => {
        createForm.reset(CREATE_DEFAULTS);
        setCreateOpen(true);
    }, [createForm]);

    const openEdit = useCallback(
        (c: Country) => {
            editForm.reset({
                name: c.name,
                native_name: c.native_name ?? "",
                phone_code: c.phone_code ?? "",
                flag: c.flag ?? "",
                region: c.region ?? "",
                subregion: c.subregion ?? "",
                default_currency_code: c.default_currency_code ?? "",
                is_active: c.is_active,
                is_shipping_enabled: c.is_shipping_enabled,
                free_shipping_threshold: c.free_shipping_threshold ?? null,
                standard_shipping_cost: c.standard_shipping_cost ?? null,
                express_shipping_cost: c.express_shipping_cost ?? null,
                estimated_delivery_days: c.estimated_delivery_days ?? null,
            });
            setEditing(c);
            setEditOpen(true);
        },
        [editForm],
    );

    // ── Mutations ─────────────────────────────────────────────────────────────────

    const createMutation = useMutation({
        mutationFn: (v: CreateFormValues) => countriesApi.create(v),
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["countries"] });
            qc.invalidateQueries({ queryKey: ["country-regions"] });
            toast.success(res.message);
            setCreateOpen(false);
        },
        onError: (err: ApiError) => {
            if (err.errors?.code)
                createForm.setError("code", { message: err.errors.code[0] });
            else toast.error(err.message);
        },
    });

    const editMutation = useMutation({
        mutationFn: (v: EditFormValues) =>
            countriesApi.update(editing!.code, v),
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["countries"] });
            qc.invalidateQueries({ queryKey: ["country-regions"] });
            toast.success(res.message);
            setEditOpen(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const toggleMutation = useMutation({
        mutationFn: (code: string) => countriesApi.toggle(code),
        onSuccess: (res) => {
            qc.invalidateQueries({ queryKey: ["countries"] });
            toast.success(res.message);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    // ── Render ────────────────────────────────────────────────────────────────────

    return (
        <div className="space-y-5 animate-fade-in">
            {/* Header */}
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Countries</h1>
                    <p className="page-subtitle">
                        Manage which countries are active for shipping,
                        payments, and tax. Kenya is always enabled.
                    </p>
                </div>
                <div className="flex items-center gap-2 flex-wrap shrink-0">
                    <div className="text-center px-3 py-2 bg-surface-50 rounded-lg border border-surface-100">
                        <p className="text-lg font-bold text-surface-900">
                            {activeCount}
                        </p>
                        <p className="text-2xs text-surface-500">Active</p>
                    </div>
                    <div className="text-center px-3 py-2 bg-surface-50 rounded-lg border border-surface-100">
                        <p className="text-lg font-bold text-surface-900">
                            {shippingCount}
                        </p>
                        <p className="text-2xs text-surface-500">Shipping</p>
                    </div>
                    <button
                        onClick={openCreate}
                        className="btn-primary shrink-0"
                    >
                        + Add Country
                    </button>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap gap-3">
                <input
                    className="input max-w-xs"
                    placeholder="Search country…"
                    value={table.state.search}
                    onChange={(e) => table.setSearch(e.target.value)}
                />
                <select
                    className="input w-44"
                    value={regionFilter}
                    onChange={(e) => setRegionFilter(e.target.value)}
                >
                    <option value="">All regions</option>
                    {regions.map((r) => (
                        <option key={r} value={r}>
                            {r}
                        </option>
                    ))}
                </select>
                <select
                    className="input w-36"
                    value={statusFilter}
                    onChange={(e) => setStatusFilter(e.target.value)}
                >
                    <option value="">All statuses</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
                {(table.state.search || regionFilter || statusFilter) && (
                    <button
                        onClick={() => {
                            table.setSearch("");
                            setRegionFilter("");
                            setStatusFilter("");
                        }}
                        className="btn-ghost btn-sm text-xs"
                    >
                        ✕ Clear
                    </button>
                )}
            </div>

            {/* Table */}
            <div className="card">
                <DataTable
                    columns={[
                        {
                            key: "name",
                            label: "Country",
                            sortable: true,
                            render: (row) => {
                                const c = row as unknown as Country;
                                return (
                                    <div className="flex items-center gap-2.5">
                                        <span className="text-xl leading-none w-7 text-center">
                                            {c.flag ?? "🏳️"}
                                        </span>
                                        <div>
                                            <p className="text-sm font-medium text-surface-900">
                                                {c.name}
                                            </p>
                                            <p className="text-xs font-mono text-surface-400">
                                                {c.code}
                                            </p>
                                            {c.native_name &&
                                                c.native_name !== c.name && (
                                                    <p className="text-xs text-surface-400">
                                                        {c.native_name}
                                                    </p>
                                                )}
                                        </div>
                                    </div>
                                );
                            },
                        },
                        {
                            key: "region",
                            label: "Region",
                            render: (row) => {
                                const c = row as unknown as Country;
                                return (
                                    <div>
                                        <p className="text-sm text-surface-600">
                                            {c.region ?? "-"}
                                        </p>
                                        {c.subregion && (
                                            <p className="text-xs text-surface-400">
                                                {c.subregion}
                                            </p>
                                        )}
                                    </div>
                                );
                            },
                        },
                        {
                            key: "default_currency_code",
                            label: "Currency",
                            render: (row) => (
                                <span className="text-sm font-mono text-surface-600">
                                    {(row as unknown as Country)
                                        .default_currency_code ?? "-"}
                                </span>
                            ),
                        },
                        {
                            key: "phone_code",
                            label: "Dial Code",
                            render: (row) => (
                                <span className="text-sm font-mono text-surface-500">
                                    {(row as unknown as Country).phone_code ??
                                        "-"}
                                </span>
                            ),
                        },
                        {
                            key: "is_shipping_enabled",
                            label: "Shipping",
                            render: (row) => {
                                const c = row as unknown as Country;
                                return c.is_shipping_enabled ? (
                                    <span className="badge badge-success text-2xs">
                                        ✓ Enabled
                                    </span>
                                ) : (
                                    <span className="text-xs text-surface-300">
                                        -
                                    </span>
                                );
                            },
                        },
                        {
                            key: "is_active",
                            label: "Status",
                            render: (row) => (
                                <StatusBadge
                                    active={
                                        (row as unknown as Country).is_active
                                    }
                                />
                            ),
                        },
                        {
                            key: "code",
                            label: "",
                            width: "120px",
                            render: (row) => {
                                const c = row as unknown as Country;
                                const isKenya = c.code === "KE";
                                return (
                                    <div className="flex items-center gap-1">
                                        <button
                                            title="Edit"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                openEdit(c);
                                            }}
                                            className="btn-ghost btn-sm"
                                            aria-label="Edit"
                                        >
                                            <EditIcon />
                                        </button>
                                        <button
                                            title={
                                                isKenya
                                                    ? "Kenya cannot be disabled"
                                                    : c.is_active
                                                      ? "Disable"
                                                      : "Enable"
                                            }
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                if (!isKenya)
                                                    toggleMutation.mutate(
                                                        c.code,
                                                    );
                                            }}
                                            disabled={
                                                isKenya ||
                                                toggleMutation.isPending
                                            }
                                            className={`btn-ghost btn-sm text-xs ${
                                                isKenya
                                                    ? "opacity-30 cursor-not-allowed"
                                                    : c.is_active
                                                      ? "text-danger hover:bg-danger-light"
                                                      : "text-success hover:bg-success-light"
                                            }`}
                                        >
                                            {isKenya
                                                ? "🔒"
                                                : c.is_active
                                                  ? "Disable"
                                                  : "Enable"}
                                        </button>
                                    </div>
                                );
                            },
                        },
                    ]}
                    data={countries as unknown as Record<string, unknown>[]}
                    isLoading={isLoading}
                    sortBy={table.state.sortBy}
                    sortDir={table.state.sortDir}
                    onSort={table.setSort}
                    emptyMessage="No countries found."
                />
                {countries.length > 0 && (
                    <div className="px-4 py-2.5 border-t border-surface-100 text-xs text-surface-400">
                        Showing {countries.length}{" "}
                        {countries.length === 1 ? "country" : "countries"}
                    </div>
                )}
            </div>

            {/* ── CREATE modal ───────────────────────────────────────────────────── */}
            <Modal
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                title="Add Country"
                size="lg"
                footer={
                    <>
                        <button
                            onClick={() => setCreateOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={createForm.handleSubmit((v) =>
                                createMutation.mutate(v),
                            )}
                            disabled={createMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {createMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Add Country
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    {/* Code - only on create */}
                    <Field
                        label="ISO Code"
                        error={createForm.formState.errors.code?.message}
                        required
                        hint="2 or 3-letter ISO 3166-1 code e.g. KE, USA"
                    >
                        <FieldInput
                            className={`input font-mono uppercase w-28 ${createForm.formState.errors.code ? "input-error" : ""}`}
                            maxLength={3}
                            {...createForm.register("code")}
                            placeholder="KE"
                            onChange={(e) =>
                                createForm.setValue(
                                    "code",
                                    e.target.value.toUpperCase(),
                                )
                            }
                        />
                    </Field>
                    <CountryFields
                        form={createForm as any}
                        activeCurrencies={activeCurrencies}
                    />
                </div>
            </Modal>

            {/* ── EDIT modal ─────────────────────────────────────────────────────── */}
            <Modal
                open={editOpen}
                onClose={() => setEditOpen(false)}
                title={
                    editing
                        ? `Edit - ${editing.flag ?? ""} ${editing.name} (${editing.code})`
                        : "Edit Country"
                }
                size="lg"
                footer={
                    <>
                        <button
                            onClick={() => setEditOpen(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={editForm.handleSubmit((v) =>
                                editMutation.mutate(v),
                            )}
                            disabled={editMutation.isPending}
                            className="btn-primary btn-sm"
                        >
                            {editMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Save Changes
                        </button>
                    </>
                }
            >
                <CountryFields
                    form={editForm as any}
                    activeCurrencies={activeCurrencies}
                />
            </Modal>
        </div>
    );
}

// ── Icons ──────────────────────────────────────────────────────────────────────

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