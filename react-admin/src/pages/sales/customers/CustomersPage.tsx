import { useState, useCallback } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { clsx } from "clsx";
import { customersApi } from "@/api/customers";
import { currenciesApi, languagesApi } from "@/api/setup";
import type {
    Customer,
    CustomerFormData,
    CustomerFilters,
} from "@/api/customers";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import { Field, useFieldAriaProps, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import type { ApiError } from "@/types";

// ── Status badge ──────────────────────────────────────────────────────────────

const STATUS_BADGE: Record<string, string> = {
    active: "badge-success",
    inactive: "badge-neutral",
    suspended: "badge-danger",
};

function StatusBadge({ status }: { status: string }) {
    return (
        <span
            className={clsx(
                "badge text-2xs capitalize",
                STATUS_BADGE[status] ?? "badge-neutral",
            )}
        >
            {status}
        </span>
    );
}

// ── Avatar ────────────────────────────────────────────────────────────────────

function Avatar({ name, size = "sm" }: { name: string; size?: "sm" | "md" }) {
    const initials = name
        .split(" ")
        .map((w) => w[0])
        .slice(0, 2)
        .join("")
        .toUpperCase();
    const colors = [
        "bg-brand-100 text-brand-700",
        "bg-success-light text-success-dark",
        "bg-warning-light text-warning-dark",
        "bg-info-light text-info",
        "bg-purple-100 text-purple-700",
    ];
    const color = colors[name.charCodeAt(0) % colors.length];
    return (
        <div
            className={clsx(
                "rounded-full flex items-center justify-center font-bold shrink-0",
                color,
                size === "sm" ? "w-8 h-8 text-xs" : "w-10 h-10 text-sm",
            )}
        >
            {initials}
        </div>
    );
}

// ── Create/Edit modal ─────────────────────────────────────────────────────────

function CustomerFormModal({
    customer,
    onClose,
    onSaved,
}: {
    customer?: Customer | null;
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const isEdit = !!customer;

    // ── Load languages + currencies from DB ───────────────────────────────────
    const { data: langsData } = useQuery({
        queryKey: ["languages-list"],
        queryFn: () => languagesApi.list(),
        staleTime: 5 * 60 * 1000,
    });
    const { data: cxData } = useQuery({
        queryKey: ["currencies-list"],
        queryFn: () => currenciesApi.list(),
        staleTime: 5 * 60 * 1000,
    });
    const languages = langsData?.data ?? [];
    const currencies = cxData?.data ?? [];

    const defaultLang =
        languages.find((l: any) => l.is_default)?.code ??
        languages[0]?.code ??
        "en";
    const defaultCurrency =
        currencies.find((c: any) => c.is_base || c.is_default)?.code ??
        currencies[0]?.code ??
        "KES";

    const [form, setForm] = useState<CustomerFormData>({
        first_name: customer?.first_name ?? "",
        last_name: customer?.last_name ?? "",
        email: customer?.email ?? "",
        phone: customer?.phone ?? "",
        type: customer?.customer_type ?? "individual",
        company_name: customer?.company ?? "",
        preferred_language: customer?.preferred_language ?? defaultLang,
        preferred_currency: customer?.preferred_currency ?? defaultCurrency,
        notes: customer?.notes ?? "",
    });

    const [defaultsApplied, setDefaultsApplied] = useState(false);
    if (
        !isEdit &&
        !defaultsApplied &&
        (languages.length > 0 || currencies.length > 0)
    ) {
        setForm((prev) => ({
            ...prev,
            preferred_language: prev.preferred_language || defaultLang,
            preferred_currency: prev.preferred_currency || defaultCurrency,
        }));
        setDefaultsApplied(true);
    }

    const set = (key: keyof CustomerFormData, val: string) =>
        setForm((prev) => ({ ...prev, [key]: val }));

    const mutation = useMutation({
        mutationFn: () =>
            isEdit
                ? customersApi.update(customer!.id, form)
                : customersApi.create(form),
        onSuccess: () => {
            toast.success(isEdit ? "Customer updated" : "Customer created");
            onSaved();
            onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    // Valid when names are present AND at least one contact method is supplied
    const hasContact = !!(form.email?.trim() || form.phone?.trim());
    const isValid = !!(
        form.first_name.trim() &&
        form.last_name.trim() &&
        hasContact
    );

    return (
        <Modal
            open={true}
            title={isEdit ? "Edit Customer" : "New Customer"}
            onClose={onClose}
            size="lg"
        >
            <div className="p-5 space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="First Name" required>
                        <FieldInput
                            value={form.first_name}
                            onChange={(e) => set("first_name", e.target.value)}
                            className="input"
                            autoFocus
                        />
                    </Field>
                    <Field label="Last Name" required>
                        <FieldInput
                            value={form.last_name}
                            onChange={(e) => set("last_name", e.target.value)}
                            className="input"
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {/* Email is optional - phone alone is enough for walk-in customers */}
                    <Field
                        label="Email"
                        hint="Optional - required only for portal access"
                    >
                        <FieldInput
                            type="email"
                            value={form.email ?? ""}
                            onChange={(e) => set("email", e.target.value)}
                            className="input"
                            placeholder="customer@example.com"
                        />
                    </Field>
                    <Field label="Phone">
                        <FieldInput
                            type="tel"
                            value={form.phone ?? ""}
                            onChange={(e) => set("phone", e.target.value)}
                            className="input"
                            placeholder="+254…"
                        />
                    </Field>
                </div>

                {/* Warn when neither contact is supplied */}
                {!hasContact && (form.first_name || form.last_name) && (
                    <p className="text-2xs text-warning-dark bg-warning-light/60 rounded-lg px-3 py-2">
                        Please provide at least an email or phone number.
                    </p>
                )}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Customer Type">
                        <FieldSelect
                            value={form.type}
                            onChange={(e) => set("type", e.target.value)}
                            className="input"
                        >
                            <option value="individual">Individual</option>
                            <option value="business">Business</option>
                        </FieldSelect>
                    </Field>
                    {form.type === "business" && (
                        <Field label="Company Name" required>
                            <FieldInput
                                value={form.company_name ?? ""}
                                onChange={(e) =>
                                    set("company_name", e.target.value)
                                }
                                className="input"
                            />
                        </Field>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Preferred Language">
                        <FieldSelect
                            value={form.preferred_language}
                            onChange={(e) =>
                                set("preferred_language", e.target.value)
                            }
                            className="input"
                        >
                            {languages.length === 0 ? (
                                <option value="en">English</option>
                            ) : (
                                (languages as any[]).map((l) => (
                                    <option key={l.code} value={l.code}>
                                        {l.flag ? `${l.flag} ` : ""}
                                        {l.name}
                                        {l.is_default ? " (default)" : ""}
                                    </option>
                                ))
                            )}
                        </FieldSelect>
                    </Field>
                    <Field label="Preferred Currency">
                        <FieldSelect
                            value={form.preferred_currency}
                            onChange={(e) =>
                                set("preferred_currency", e.target.value)
                            }
                            className="input"
                        >
                            {currencies.length === 0 ? (
                                <option value="KES">
                                    KES - Kenyan Shilling
                                </option>
                            ) : (
                                (currencies as any[]).map((c) => (
                                    <option key={c.code} value={c.code}>
                                        {c.code} - {c.name}
                                        {c.is_base ? " (default)" : ""}
                                    </option>
                                ))
                            )}
                        </FieldSelect>
                    </Field>
                </div>

                <Field label="Internal Notes">
                    <FieldTextarea
                        value={form.notes ?? ""}
                        onChange={(e) => set("notes", e.target.value)}
                        className="input resize-none"
                        rows={2}
                        placeholder="Notes visible only to staff…"
                    />
                </Field>

                {!isEdit && form.email?.trim() && (
                    <p className="text-2xs text-surface-400 bg-surface-50 rounded-lg px-3 py-2">
                        A password reset link will be sent to the customer's
                        email so they can set their own password and access the
                        portal.
                    </p>
                )}
                {!isEdit && !form.email?.trim() && form.phone?.trim() && (
                    <p className="text-2xs text-surface-400 bg-surface-50 rounded-lg px-3 py-2">
                        No email provided - this customer will be created as a
                        walk-in record without portal access. You can invite
                        them to the portal later by adding their email.
                    </p>
                )}
            </div>
            <div className="px-5 pb-5 flex gap-3">
                <button onClick={onClose} className="btn-secondary flex-1">
                    Cancel
                </button>
                <button
                    onClick={() => mutation.mutate()}
                    disabled={!isValid || mutation.isPending}
                    className="btn-primary flex-1"
                >
                    {mutation.isPending
                        ? "Saving…"
                        : isEdit
                          ? "Save Changes"
                          : "Create Customer"}
                </button>
            </div>
        </Modal>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function CustomersPage() {
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const [page, setPage] = useState(1);

    const [filters, setFilters] = useState<CustomerFilters>({
        sort_by: "created_at",
        sort_order: "desc",
        per_page: 25,
    });
    const [showCreate, setShowCreate] = useState(false);
    const [editingCustomer, setEditingCustomer] = useState<Customer | null>(
        null,
    );

    const updateFilter = useCallback(
        (key: keyof CustomerFilters, val: string) => {
            setFilters((prev) => ({ ...prev, [key]: val || undefined }));
            setPage(1);
        },
        [setPage],
    );

    const { data, isLoading, isFetching } = useQuery({
        queryKey: ["customers", filters, page],
        queryFn: () => customersApi.list({ ...filters, page }),
        placeholderData: (prev) => prev,
    });

    const customers = data?.data ?? [];
    const meta = data?.meta;
    const summary = data?.summary;

    const statusMutation = useMutation({
        mutationFn: ({
            id,
            status,
        }: {
            id: number;
            status: "active" | "inactive" | "suspended";
        }) => customersApi.updateStatus(id, status),
        onSuccess: () => {
            toast.success("Status updated");
            qc.invalidateQueries({ queryKey: ["customers"] });
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => customersApi.delete(id),
        onSuccess: () => {
            toast.success("Customer deleted");
            qc.invalidateQueries({ queryKey: ["customers"] });
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const inviteMutation = useMutation({
        mutationFn: (id: number) => customersApi.inviteToPortal(id),
        onSuccess: () => {
            toast.success("Portal invite sent");
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    const fmt = (n: number) =>
        n.toLocaleString("en-KE", { minimumFractionDigits: 2 });

    return (
        <div className="flex flex-col gap-5 animate-fade-in">
            {/* ── Header ──────────────────────────────────────────────────── */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="page-title">Customers</h1>
                    <p className="page-subtitle">
                        {meta ? `${meta.total.toLocaleString()} customers` : ""}
                        {isFetching && !isLoading && (
                            <span className="ml-2 text-brand-500 text-xs">
                                Refreshing…
                            </span>
                        )}
                    </p>
                </div>
                <button
                    onClick={() => setShowCreate(true)}
                    className="btn-primary gap-2"
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
                            d="M12 4.5v15m7.5-7.5h-15"
                        />
                    </svg>
                    New Customer
                </button>
            </div>

            {/* ── Summary stats ────────────────────────────────────────────── */}
            {summary && (
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    {[
                        {
                            label: "Total",
                            value: summary.total,
                            cls: "text-surface-900",
                        },
                        {
                            label: "Active",
                            value: summary.active,
                            cls: "text-success-dark",
                        },
                        {
                            label: "Inactive",
                            value: summary.inactive,
                            cls: "text-surface-400",
                        },
                        {
                            label: "New this month",
                            value: summary.new_this_month,
                            cls: "text-brand-600",
                        },
                    ].map(({ label, value, cls }) => (
                        <div key={label} className="card px-4 py-3">
                            <p className="text-2xs text-surface-400 uppercase tracking-wide">
                                {label}
                            </p>
                            <p
                                className={clsx(
                                    "text-xl font-bold mt-0.5",
                                    cls,
                                )}
                            >
                                {value?.toLocaleString()}
                            </p>
                        </div>
                    ))}
                </div>
            )}

            {/* ── Filters ─────────────────────────────────────────────────── */}
            <div className="card px-4 py-3 flex flex-wrap gap-3">
                <input
                    className="input input-sm w-56"
                    placeholder="Search name, email, phone…"
                    value={filters.search ?? ""}
                    onChange={(e) => updateFilter("search", e.target.value)}
                />
                <select
                    className="input input-sm w-32"
                    value={filters.status ?? ""}
                    onChange={(e) => updateFilter("status", e.target.value)}
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                </select>
                <select
                    className="input input-sm w-32"
                    value={filters.type ?? ""}
                    onChange={(e) => updateFilter("type", e.target.value)}
                >
                    <option value="">All types</option>
                    <option value="individual">Individual</option>
                    <option value="business">Business</option>
                </select>
                <select
                    className="input input-sm w-36 ml-auto"
                    value={`${filters.sort_by}:${filters.sort_order}`}
                    onChange={(e) => {
                        const [by, order] = e.target.value.split(":");
                        setFilters((p) => ({
                            ...p,
                            sort_by: by,
                            sort_order: order as "asc" | "desc",
                        }));
                    }}
                >
                    <option value="created_at:desc">Newest first</option>
                    <option value="created_at:asc">Oldest first</option>
                    <option value="name:asc">Name A–Z</option>
                    <option value="name:desc">Name Z–A</option>
                </select>
            </div>

            {/* ── Table ───────────────────────────────────────────────────── */}
            <div className="card overflow-hidden p-0">
                {isLoading ? (
                    <div className="flex items-center justify-center py-20">
                        <Spinner />
                    </div>
                ) : customers.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-20 text-surface-400">
                        <svg
                            className="w-10 h-10 mb-3 opacity-40"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={1.5}
                                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
                            />
                        </svg>
                        <p className="text-sm">No customers found</p>
                    </div>
                ) : (
                    <div className="table-wrapper rounded-none border-0">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th className="text-right">Orders</th>
                                    <th className="text-right">Total Spent</th>
                                    <th>Last Order</th>
                                    <th>Since</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {customers.map((customer) => {
                                    const fullName = `${customer.first_name} ${customer.last_name}`;
                                    const email =
                                        customer.email ?? customer.user?.email;
                                    const phone =
                                        customer.phone ?? customer.user?.phone;
                                    const status =
                                        customer.status ??
                                        customer.user?.status ??
                                        "active";
                                    const isWalkIn = !customer.user_id;
                                    return (
                                        <tr
                                            key={customer.id}
                                            className="cursor-pointer"
                                            onClick={() =>
                                                navigate(
                                                    `/sales/customers/${customer.id}`,
                                                )
                                            }
                                        >
                                            <td>
                                                <div className="flex items-center gap-2.5">
                                                    <Avatar name={fullName} />
                                                    <div>
                                                        <div className="flex items-center gap-1.5">
                                                            <p className="font-medium text-surface-900 text-xs">
                                                                {fullName}
                                                            </p>
                                                            {isWalkIn && (
                                                                <span
                                                                    className="badge text-2xs badge-neutral"
                                                                    title="No portal account"
                                                                >
                                                                    Walk-in
                                                                </span>
                                                            )}
                                                        </div>
                                                        {email && (
                                                            <p className="text-2xs text-surface-400">
                                                                {email}
                                                            </p>
                                                        )}
                                                        {phone && (
                                                            <p className="text-2xs text-surface-400">
                                                                {phone}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span className="text-xs text-surface-600 capitalize flex items-center gap-1">
                                                    {customer.customer_type === "business"
                                                        ? <svg className="w-3.5 h-3.5 shrink-0 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>
                                                        : <svg className="w-3.5 h-3.5 shrink-0 text-surface-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                                    }
                                                    {customer.customer_type}
                                                    {customer.company &&
                                                        ` · ${customer.company}`}
                                                </span>
                                            </td>
                                            <td>
                                                <StatusBadge status={status} />
                                            </td>
                                            <td className="text-right">
                                                <span className="text-xs font-medium text-surface-900">
                                                    {customer.total_orders ?? 0}
                                                </span>
                                            </td>
                                            <td className="text-right">
                                                <span className="text-xs font-semibold text-brand-600">
                                                    KES{" "}
                                                    {fmt(
                                                        customer.total_spent ??
                                                            0,
                                                    )}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="text-xs text-surface-500">
                                                    {customer.last_order_date
                                                        ? new Date(
                                                              customer.last_order_date,
                                                          ).toLocaleDateString(
                                                              "en-KE",
                                                              {
                                                                  dateStyle:
                                                                      "medium",
                                                              },
                                                          )
                                                        : "-"}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="text-xs text-surface-400">
                                                    {new Date(
                                                        customer.created_at,
                                                    ).toLocaleDateString(
                                                        "en-KE",
                                                        { dateStyle: "medium" },
                                                    )}
                                                </span>
                                            </td>
                                            <td
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <div className="flex items-center gap-1 justify-end">
                                                    {/* Invite to portal - only for walk-in customers with an email */}
                                                    {isWalkIn && email && (
                                                        <button
                                                            onClick={() =>
                                                                inviteMutation.mutate(
                                                                    customer.id,
                                                                )
                                                            }
                                                            disabled={
                                                                inviteMutation.isPending
                                                            }
                                                            className="btn-ghost btn-icon btn-sm text-brand-500"
                                                            title="Invite to portal"
                                                        >
                                                            <svg
                                                                className="w-3.5 h-3.5"
                                                                fill="none"
                                                                viewBox="0 0 24 24"
                                                                stroke="currentColor"
                                                                strokeWidth={2}
                                                            >
                                                                <path
                                                                    strokeLinecap="round"
                                                                    strokeLinejoin="round"
                                                                    d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"
                                                                />
                                                            </svg>
                                                        </button>
                                                    )}
                                                    <button
                                                        onClick={() =>
                                                            setEditingCustomer(
                                                                customer,
                                                            )
                                                        }
                                                        className="btn-ghost btn-icon btn-sm"
                                                        aria-label="Edit"
                                                        title="Edit"
                                                    >
                                                        <svg
                                                            className="w-3.5 h-3.5"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            strokeWidth={2}
                                                        >
                                                            <path
                                                                strokeLinecap="round"
                                                                strokeLinejoin="round"
                                                                d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"
                                                            />
                                                        </svg>
                                                    </button>
                                                    <button
                                                        onClick={() =>
                                                            statusMutation.mutate(
                                                                {
                                                                    id: customer.id,
                                                                    status:
                                                                        status ===
                                                                        "active"
                                                                            ? "inactive"
                                                                            : "active",
                                                                },
                                                            )
                                                        }
                                                        className="btn-ghost btn-icon btn-sm"
                                                        title={
                                                            status === "active"
                                                                ? "Deactivate"
                                                                : "Activate"
                                                        }
                                                    >
                                                        <svg
                                                            className="w-3.5 h-3.5"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            strokeWidth={2}
                                                        >
                                                            {status ===
                                                            "active" ? (
                                                                <path
                                                                    strokeLinecap="round"
                                                                    strokeLinejoin="round"
                                                                    d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"
                                                                />
                                                            ) : (
                                                                <path
                                                                    strokeLinecap="round"
                                                                    strokeLinejoin="round"
                                                                    d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                                />
                                                            )}
                                                        </svg>
                                                    </button>
                                                    <button
                                                        onClick={() => {
                                                            if (
                                                                confirm(
                                                                    `Delete ${fullName}? This cannot be undone.`,
                                                                )
                                                            ) {
                                                                deleteMutation.mutate(
                                                                    customer.id,
                                                                );
                                                            }
                                                        }}
                                                        className="btn-ghost btn-icon btn-sm text-danger hover:bg-danger-light"
                                                        aria-label="Delete"
                                                        title="Delete customer"
                                                    >
                                                        <svg
                                                            className="w-3.5 h-3.5"
                                                            fill="none"
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            strokeWidth={2}
                                                        >
                                                            <path
                                                                strokeLinecap="round"
                                                                strokeLinejoin="round"
                                                                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                                                            />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-surface-500">
                            Page {meta.current_page} of {meta.last_page} ·{" "}
                            {meta.total.toLocaleString()} customers
                        </p>
                        <div className="flex gap-1">
                            <button
                                onClick={() => setPage(Math.max(1, page - 1))}
                                disabled={page <= 1}
                                className="btn-secondary btn-sm"
                            >
                                ← Prev
                            </button>
                            <button
                                onClick={() =>
                                    setPage(Math.min(meta.last_page, page + 1))
                                }
                                disabled={page >= meta.last_page}
                                className="btn-secondary btn-sm"
                            >
                                Next →
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* ── Modals ────────────────────────────────────────────────────── */}
            {(showCreate || editingCustomer) && (
                <CustomerFormModal
                    customer={editingCustomer}
                    onClose={() => {
                        setShowCreate(false);
                        setEditingCustomer(null);
                    }}
                    onSaved={() =>
                        qc.invalidateQueries({ queryKey: ["customers"] })
                    }
                />
            )}
        </div>
    );
}