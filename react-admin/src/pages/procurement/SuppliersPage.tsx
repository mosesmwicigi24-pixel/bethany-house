import { useState, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { get } from "@/api/client";
import { supplierApi } from "@/api/procurement";
import type {
    Supplier,
    SupplierStats,
    SupplierPerformance,
} from "@/api/procurement";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Field, useFieldAriaProps, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";
import { clsx } from "clsx";

// ─── Status badge ─────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: "active" | "inactive" }) {
    return (
        <span
            className={clsx(
                "badge",
                status === "active" ? "badge-success" : "badge-neutral",
            )}
        >
            <span
                className={clsx(
                    "w-1.5 h-1.5 rounded-full",
                    status === "active" ? "bg-success" : "bg-surface-400",
                )}
            />
            {status === "active" ? "Active" : "Inactive"}
        </span>
    );
}

// ─── Supplier form modal ───────────────────────────────────────────────────────

interface SupplierFormProps {
    supplier?: Supplier | null;
    onClose: () => void;
}

function SupplierFormModal({ supplier, onClose }: SupplierFormProps) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const isEdit = !!supplier;

    const [form, setForm] = useState({
        name: supplier?.name ?? "",
        company_code: supplier?.company_code ?? "",
        type: (supplier as any)?.type ?? "",
        contact_person: supplier?.contact_person ?? "",
        email: supplier?.email ?? "",
        phone: supplier?.phone ?? "",
        address_line_1:
            (supplier as any)?.address_line_1 ?? supplier?.address ?? "",
        city: supplier?.city ?? "",
        country: supplier?.country ?? "",
        payment_terms: supplier?.payment_terms ?? "NET30",
        supply_category: supplier?.supply_category ?? "",
        notes: supplier?.notes ?? "",
        status: supplier?.status ?? "active",
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    // Fetch countries from the database
    const { data: countriesData } = useQuery({
        queryKey: ["countries-list"],
        queryFn: () =>
            get<{ data: Array<{ code: string; name: string }> }>("/countries", {
                params: { all: 1 },
            }),
    });
    const countries = countriesData?.data ?? [];

    const mutation = useMutation({
        mutationFn: (data: typeof form) =>
            isEdit
                ? supplierApi.update(supplier!.id, data)
                : supplierApi.create(data),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["suppliers"] });
            toast.success(isEdit ? "Supplier updated" : "Supplier created");
            onClose();
        },
        onError: (err: ApiError) => {
            setErrors(err.errors ?? {});
            toast.error(err.message);
        },
    });

    const set =
        (k: keyof typeof form) =>
        (
            e: React.ChangeEvent<
                HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
            >,
        ) =>
            setForm((f) => ({ ...f, [k]: e.target.value }));

    return (
        <Modal
            open
            onClose={onClose}
            title={isEdit ? "Edit Supplier" : "New Supplier"}
            size="xl"
            footer={
                <div className="flex gap-2 justify-end w-full">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        className="btn-primary btn-sm"
                        onClick={() => mutation.mutate(form)}
                        disabled={mutation.isPending}
                    >
                        {mutation.isPending ? (
                            <Spinner size="sm" />
                        ) : isEdit ? (
                            "Save Changes"
                        ) : (
                            "Create Supplier"
                        )}
                    </button>
                </div>
            }
        >
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {/* Name - full width, required */}
                <div className="sm:col-span-2">
                    <Field label="Supplier Name *" error={errors.name?.[0]}>
                        <FieldInput
                            className={clsx(
                                "input",
                                errors.name && "input-error",
                            )}
                            value={form.name}
                            onChange={set("name")}
                            placeholder="e.g. Apex Textiles Ltd"
                        />
                    </Field>
                </div>

                <Field label="Company Code" error={errors.company_code?.[0]}>
                    <FieldInput
                        className="input"
                        value={form.company_code}
                        onChange={set("company_code")}
                        placeholder="SUP-001 (auto-generated if blank)"
                    />
                </Field>

                <Field label="Supplier Type">
                    <FieldSelect
                        className="input"
                        value={form.type}
                        onChange={set("type")}
                    >
                        <option value="">-- Select Type --</option>
                        <option value="manufacturer">Manufacturer</option>
                        <option value="wholesaler">Wholesaler</option>
                        <option value="distributor">Distributor</option>
                        <option value="other">Other</option>
                    </FieldSelect>
                </Field>

                <Field label="Supply Category">
                    <FieldSelect
                        className="input"
                        value={form.supply_category}
                        onChange={set("supply_category")}
                    >
                        <option value="">-- Select Category --</option>
                        <option value="finished_products">
                            Finished Products
                        </option>
                        <option value="raw_materials">Raw Materials</option>
                        <option value="packaging">Packaging</option>
                        <option value="accessories">Accessories</option>
                        <option value="mixed">Mixed</option>
                    </FieldSelect>
                </Field>

                <Field label="Contact Person">
                    <FieldInput
                        className="input"
                        value={form.contact_person}
                        onChange={set("contact_person")}
                        placeholder="Full name"
                    />
                </Field>

                <Field label="Email" error={errors.email?.[0]}>
                    <FieldInput
                        type="email"
                        className="input"
                        value={form.email}
                        onChange={set("email")}
                        placeholder="supplier@example.com"
                    />
                </Field>

                <Field label="Phone" error={errors.phone?.[0]}>
                    <FieldInput
                        className="input"
                        value={form.phone}
                        onChange={set("phone")}
                        placeholder="+254 700 000 000"
                    />
                </Field>

                <Field label="Payment Terms">
                    <FieldSelect
                        className="input"
                        value={form.payment_terms}
                        onChange={set("payment_terms")}
                    >
                        <option value="COD">COD (Cash on Delivery)</option>
                        <option value="NET15">NET 15 Days</option>
                        <option value="NET30">NET 30 Days</option>
                        <option value="NET60">NET 60 Days</option>
                        <option value="50_50">
                            50% Upfront / 50% on Delivery
                        </option>
                        <option value="prepaid">Prepaid</option>
                    </FieldSelect>
                </Field>

                <div className="sm:col-span-2">
                    <Field label="Address" error={errors.address_line_1?.[0]}>
                        <FieldInput
                            className="input"
                            value={form.address_line_1}
                            onChange={set("address_line_1")}
                            placeholder="Street address"
                        />
                    </Field>
                </div>

                <Field label="City" error={errors.city?.[0]}>
                    <FieldInput
                        className="input"
                        value={form.city}
                        onChange={set("city")}
                        placeholder="Nairobi"
                    />
                </Field>

                <Field label="Country">
                    <FieldSelect
                        className="input"
                        value={form.country}
                        onChange={set("country")}
                    >
                        <option value="">-- Select Country --</option>
                        {countries.map((c) => (
                            <option key={c.code} value={c.name}>
                                {c.name}
                            </option>
                        ))}
                    </FieldSelect>
                </Field>

                <Field label="Status">
                    <FieldSelect
                        className="input"
                        value={form.status}
                        onChange={set("status")}
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </FieldSelect>
                </Field>

                <div className="sm:col-span-2">
                    <Field label="Notes">
                        <FieldTextarea
                            className="input resize-none"
                            rows={2}
                            value={form.notes}
                            onChange={set("notes")}
                            placeholder="Any additional notes..."
                        />
                    </Field>
                </div>
            </div>
        </Modal>
    );
}

// ─── Supplier detail drawer ───────────────────────────────────────────────────

function SupplierDetailModal({
    supplier,
    onClose,
    onEdit,
}: {
    supplier: Supplier;
    onClose: () => void;
    onEdit: () => void;
}) {
    const { can } = usePermissions();
    const canEdit = can("procurement.create");
    const { data, isLoading } = useQuery({
        queryKey: ["supplier-detail", supplier.id],
        queryFn: () => supplierApi.get(supplier.id),
    });

    const { data: perf, isLoading: perfLoading } = useQuery({
        queryKey: ["supplier-performance", supplier.id],
        queryFn: () => supplierApi.performance(supplier.id),
    });

    const stats = data?.stats;
    const recentOrders = data?.recent_orders ?? [];

    const PO_STATUS_COLORS: Record<string, string> = {
        draft: "badge-neutral",
        pending_approval: "badge-warning",
        approved: "badge-info",
        ordered: "badge-info",
        partially_received: "badge-warning",
        received: "badge-success",
        cancelled: "badge-danger",
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={supplier.name}
            size="xl"
            footer={
                <div className="flex gap-2 justify-end w-full">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Close
                    </button>
                    {canEdit && (
                    <button className="btn-primary btn-sm" onClick={onEdit}>
                        Edit Supplier
                    </button>
                    )}
                </div>
            }
        >
            {isLoading ? (
                <div className="flex justify-center py-10">
                    <Spinner size="lg" />
                </div>
            ) : (
                <div className="space-y-5">
                    {/* Info grid */}
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                        {[
                            {
                                label: "Code",
                                value: supplier.company_code || "-",
                            },
                            {
                                label: "Contact",
                                value: supplier.contact_person || "-",
                            },
                            { label: "Email", value: supplier.email || "-" },
                            { label: "Phone", value: supplier.phone || "-" },
                            {
                                label: "Payment Terms",
                                value: supplier.payment_terms || "-",
                            },
                            {
                                label: "Category",
                                value:
                                    supplier.supply_category?.replace(
                                        "_",
                                        " ",
                                    ) || "-",
                            },
                            { label: "City", value: supplier.city || "-" },
                            {
                                label: "Country",
                                value: supplier.country || "-",
                            },
                            {
                                label: "Status",
                                value: <StatusBadge status={supplier.status} />,
                            },
                        ].map(({ label, value }) => (
                            <div
                                key={label}
                                className="bg-surface-50 rounded-lg px-3 py-2"
                            >
                                <p className="text-2xs text-surface-400 uppercase tracking-wider mb-0.5">
                                    {label}
                                </p>
                                <div className="font-medium text-surface-800">
                                    {value}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Stats */}
                    {stats && (
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            {[
                                {
                                    label: "Total Orders",
                                    value: stats.total_orders,
                                    color: "text-brand-600",
                                },
                                {
                                    label: "Pending Orders",
                                    value: stats.pending_orders,
                                    color: "text-warning",
                                },
                                {
                                    label: "Total Spent",
                                    value: `KES ${Number(stats.total_value).toLocaleString()}`,
                                    color: "text-surface-900",
                                },
                                {
                                    label: "Last Order",
                                    value: stats.last_order_date
                                        ? new Date(
                                              stats.last_order_date,
                                          ).toLocaleDateString()
                                        : "-",
                                    color: "text-surface-700",
                                },
                            ].map((s) => (
                                <div
                                    key={s.label}
                                    className="text-center border border-surface-100 rounded-xl py-3"
                                >
                                    <p
                                        className={clsx(
                                            "text-lg font-bold",
                                            s.color,
                                        )}
                                    >
                                        {s.value}
                                    </p>
                                    <p className="text-2xs text-surface-400 mt-0.5">
                                        {s.label}
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Performance */}
                    {!perfLoading && perf && (
                        <div>
                            <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                                Supplier Performance
                            </p>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="bg-success-light rounded-lg px-3 py-2 text-center">
                                    <p className="text-lg font-bold text-success">
                                        {Math.round(perf.quality_rate ?? 0)}%
                                    </p>
                                    <p className="text-2xs text-success-dark">
                                        Quality Rate
                                    </p>
                                </div>
                                <div className="bg-info-light rounded-lg px-3 py-2 text-center">
                                    <p className="text-lg font-bold text-info">
                                        {perf.on_time_deliveries}
                                    </p>
                                    <p className="text-2xs text-info-dark">
                                        On-Time Deliveries
                                    </p>
                                </div>
                                <div className="bg-danger-light rounded-lg px-3 py-2 text-center">
                                    <p className="text-lg font-bold text-danger">
                                        {perf.late_deliveries}
                                    </p>
                                    <p className="text-2xs text-danger-dark">
                                        Late Deliveries
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Recent POs */}
                    {recentOrders.length > 0 && (
                        <div>
                            <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2">
                                Recent Purchase Orders
                            </p>
                            <div className="space-y-1.5">
                                {recentOrders.slice(0, 5).map((po) => (
                                    <div
                                        key={po.id}
                                        className="flex items-center justify-between px-3 py-2 bg-surface-50 rounded-lg text-sm"
                                    >
                                        <span className="font-mono text-xs text-brand-600">
                                            {po.po_number}
                                        </span>
                                        <span className="text-surface-500 text-xs">
                                            {new Date(
                                                po.created_at,
                                            ).toLocaleDateString()}
                                        </span>
                                        <span
                                            className={clsx(
                                                "badge",
                                                PO_STATUS_COLORS[po.status] ??
                                                    "badge-neutral",
                                            )}
                                        >
                                            {po.status.replace("_", " ")}
                                        </span>
                                        <span className="font-semibold text-surface-900">
                                            KES{" "}
                                            {Number(po.total).toLocaleString()}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </Modal>
    );
}

// ─── Delete confirm ───────────────────────────────────────────────────────────

function DeleteModal({
    supplier,
    onClose,
}: {
    supplier: Supplier;
    onClose: () => void;
}) {
    const qc = useQueryClient();
    const toast = useToastStore();

    const mutation = useMutation({
        mutationFn: () => supplierApi.delete(supplier.id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["suppliers"] });
            toast.success("Supplier deleted");
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open
            onClose={onClose}
            title="Delete Supplier"
            size="sm"
            footer={
                <div className="flex gap-2 justify-end w-full">
                    <button className="btn-secondary btn-sm" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        className="btn-danger btn-sm"
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending}
                    >
                        {mutation.isPending ? <Spinner size="sm" /> : "Delete"}
                    </button>
                </div>
            }
        >
            <p className="text-sm text-surface-700">
                Are you sure you want to delete{" "}
                <span className="font-semibold">{supplier.name}</span>? This
                action cannot be undone. Consider deactivating instead.
            </p>
        </Modal>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function SuppliersPage() {
    const { can } = usePermissions();
    const canEdit = can("procurement.create");
    const table = useTableState();
    const [search, setSearch] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [categoryFilter, setCategoryFilter] = useState("");

    const [createOpen, setCreateOpen] = useState(false);
    const [editSupplier, setEditSupplier] = useState<Supplier | null>(null);
    const [detailSupplier, setDetailSupplier] = useState<Supplier | null>(null);
    const [deleteSupplier, setDeleteSupplier] = useState<Supplier | null>(null);

    const params: Record<string, string | number> = {
        page: table.state.page,
        per_page: 20,
        ...(search && { search }),
        ...(statusFilter && { status: statusFilter }),
        ...(categoryFilter && { supply_category: categoryFilter }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["suppliers", params],
        queryFn: () => supplierApi.list(params),
    });

    const suppliers = data?.data ?? [];
    const meta = data?.meta;

    const handleEdit = useCallback((s: Supplier) => {
        setDetailSupplier(null);
        setEditSupplier(s);
    }, []);

    return (
        <div className="space-y-5">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div className="page-header mb-0">
                    <h1 className="page-title">Suppliers</h1>
                    <p className="page-subtitle">
                        Manage your supplier directory and track performance
                    </p>
                </div>
                {canEdit && (
                <button
                    className="btn-primary btn-sm whitespace-nowrap"
                    onClick={() => setCreateOpen(true)}
                >
                    <PlusIcon /> New Supplier
                </button>
                )}
            </div>

            {/* Filters */}
            <div className="card">
                <div className="card-body py-3 flex flex-col sm:flex-row gap-3">
                    <div className="relative flex-1">
                        <SearchIcon className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400" />
                        <input
                            className="input pl-9"
                            placeholder="Search supplier name, code..."
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                table.setPage(1);
                            }}
                        />
                    </div>
                    <select
                        className="input w-full sm:w-40"
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                    >
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <select
                        className="input w-full sm:w-48"
                        value={categoryFilter}
                        onChange={(e) => setCategoryFilter(e.target.value)}
                    >
                        <option value="">All Categories</option>
                        <option value="finished_products">
                            Finished Products
                        </option>
                        <option value="raw_materials">Raw Materials</option>
                        <option value="packaging">Packaging</option>
                        <option value="accessories">Accessories</option>
                        <option value="mixed">Mixed</option>
                    </select>
                </div>
            </div>

            {/* Table */}
            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex items-center justify-center py-16">
                        <Spinner size="lg" />
                    </div>
                ) : suppliers.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16 text-surface-400 gap-2">
                        <SupplierIcon className="w-10 h-10 opacity-30" />
                        <p className="text-sm font-medium text-surface-500">
                            No suppliers found
                        </p>
                        {canEdit && (
                        <button
                            className="btn-primary btn-sm mt-2"
                            onClick={() => setCreateOpen(true)}
                        >
                            Add your first supplier
                        </button>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="table-wrapper rounded-none border-0">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th>Supplier</th>
                                        <th className="hidden sm:table-cell">
                                            Category
                                        </th>
                                        <th className="hidden md:table-cell">
                                            Contact
                                        </th>
                                        <th className="hidden lg:table-cell">
                                            Payment Terms
                                        </th>
                                        <th>Status</th>
                                        <th className="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {suppliers.map((s) => (
                                        <tr
                                            key={s.id}
                                            className="cursor-pointer"
                                            onClick={() => setDetailSupplier(s)}
                                        >
                                            <td>
                                                <div className="flex items-center gap-3">
                                                    <div className="w-8 h-8 rounded-lg bg-brand-50 flex items-center justify-center flex-shrink-0">
                                                        <span className="text-sm font-bold text-brand-600">
                                                            {s.name
                                                                .charAt(0)
                                                                .toUpperCase()}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <p className="font-medium text-surface-900">
                                                            {s.name}
                                                        </p>
                                                        {s.company_code && (
                                                            <p className="text-2xs font-mono text-surface-400">
                                                                {s.company_code}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="hidden sm:table-cell">
                                                <span className="text-sm capitalize">
                                                    {s.supply_category?.replace(
                                                        "_",
                                                        " ",
                                                    ) || "-"}
                                                </span>
                                            </td>
                                            <td className="hidden md:table-cell">
                                                <div>
                                                    {s.contact_person && (
                                                        <p className="text-sm text-surface-700">
                                                            {s.contact_person}
                                                        </p>
                                                    )}
                                                    {s.email && (
                                                        <p className="text-xs text-surface-400">
                                                            {s.email}
                                                        </p>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="hidden lg:table-cell">
                                                <span className="text-sm">
                                                    {s.payment_terms || "-"}
                                                </span>
                                            </td>
                                            <td>
                                                <StatusBadge
                                                    status={s.status}
                                                />
                                            </td>
                                            <td
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <div className="flex items-center gap-1 justify-end">
                                                    {canEdit && (
                                                    <button
                                                        className="btn-ghost btn-sm"
                                                        title="Edit"
                                                        onClick={() =>
                                                            setEditSupplier(s)
                                                        }
                                                    >
                                                        <EditIcon className="w-4 h-4" />
                                                    </button>
                                                    )}
                                                    {canEdit && (
                                                    <button
                                                        className="btn-ghost btn-sm text-danger hover:bg-danger-light"
                                                        title="Delete"
                                                        onClick={() =>
                                                            setDeleteSupplier(s)
                                                        }
                                                    >
                                                        <TrashIcon className="w-4 h-4" />
                                                    </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

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
                    </>
                )}
            </div>

            {/* Modals */}
            {createOpen && (
                <SupplierFormModal onClose={() => setCreateOpen(false)} />
            )}
            {editSupplier && (
                <SupplierFormModal
                    supplier={editSupplier}
                    onClose={() => setEditSupplier(null)}
                />
            )}
            {detailSupplier && (
                <SupplierDetailModal
                    supplier={detailSupplier}
                    onClose={() => setDetailSupplier(null)}
                    onEdit={() => handleEdit(detailSupplier)}
                />
            )}
            {deleteSupplier && (
                <DeleteModal
                    supplier={deleteSupplier}
                    onClose={() => setDeleteSupplier(null)}
                />
            )}
        </div>
    );
}

// ─── Icons ────────────────────────────────────────────────────────────────────

const PlusIcon = () => (
    <svg
        className="w-4 h-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={2}
    >
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
    </svg>
);
const SearchIcon = ({ className }: { className?: string }) => (
    <svg
        className={className}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={2}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"
        />
    </svg>
);
const EditIcon = ({ className }: { className?: string }) => (
    <svg
        className={className}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"
        />
    </svg>
);
const TrashIcon = ({ className }: { className?: string }) => (
    <svg
        className={className}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.75}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
        />
    </svg>
);
const SupplierIcon = ({ className }: { className?: string }) => (
    <svg
        className={className}
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.5}
    >
        <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"
        />
    </svg>
);