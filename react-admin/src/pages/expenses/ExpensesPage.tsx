// src/pages/expenses/ExpensesPage.tsx
import { useState, useCallback } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { get } from "@/api/client";
import {
    expensesApi,
    EXPENSE_STATUS_CONFIG,
    PAYMENT_METHODS,
    fmtKes,
} from "@/api/expenses";
import type { Expense, ExpenseListParams } from "@/api/expenses";
import { useToastStore } from "@/store/toast.store";
import { useAuthStore } from "@/store/auth.store";
import { useTableState } from "@/hooks/useTableState";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import {
    Field,
    useFieldAriaProps,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";
import { usePermissions } from "@/hooks/usePermissions";
import { clsx } from "clsx";
import dayjs from "dayjs";
import { Fragment } from "react";
import { groupRowsByDate, DateGroupHeaderRow } from "@/lib/dateGrouping";

// ─── Status Badge ─────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: Expense["status"] }) {
    const cfg = EXPENSE_STATUS_CONFIG[status] ?? EXPENSE_STATUS_CONFIG.draft;
    return (
        <span
            className={clsx(
                "inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium",
                cfg.bg,
                cfg.text,
            )}
        >
            <span
                className={clsx("w-1.5 h-1.5 rounded-full shrink-0", cfg.dot)}
            />
            {cfg.label}
        </span>
    );
}

// ─── New Expense Modal ────────────────────────────────────────────────────────

function NewExpenseModal({
    open,
    onClose,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const { user } = useAuthStore();

    const [title, setTitle] = useState("");
    const [categoryId, setCategoryId] = useState<number | "">("");
    const [amount, setAmount] = useState<number | "">("");
    const [currency, setCurrency] = useState("KES");
    const [expenseDate, setExpenseDate] = useState(
        dayjs().format("YYYY-MM-DD"),
    );
    const [paymentMethod, setPaymentMethod] = useState("cash");
    const [paymentRef, setPaymentRef] = useState("");
    const [vendorName, setVendorName] = useState("");
    const [outletId, setOutletId] = useState<number | "">(""); // '' = company-wide
    const [department, setDepartment] = useState("");
    const [isRecurring, setIsRecurring] = useState(false);
    const [recurrenceFreq, setRecurrenceFreq] = useState("monthly");
    const [recurrenceEnd, setRecurrenceEnd] = useState("");
    const [description, setDescription] = useState("");
    const [notes, setNotes] = useState("");
    const [submitting, setSubmitting] = useState(false);

    const { data: catData } = useQuery({
        queryKey: ["expense-categories"],
        queryFn: () => expensesApi.categories(),
        enabled: open,
    });
    const categories = catData?.categories ?? [];

    const { data: outletsData } = useQuery({
        queryKey: ["outlets-dropdown"],
        queryFn: () =>
            get<{ data: Array<{ id: number; name: string }> }>(
                "/v1/admin/outlets",
                { params: { per_page: 100 } },
            ),
        enabled: open,
    });
    const outlets = outletsData?.data ?? [];

    const reset = () => {
        setTitle("");
        setCategoryId("");
        setAmount("");
        setCurrency("KES");
        setExpenseDate(dayjs().format("YYYY-MM-DD"));
        setPaymentMethod("cash");
        setPaymentRef("");
        setVendorName("");
        setOutletId("");
        setDepartment("");
        setIsRecurring(false);
        setRecurrenceFreq("monthly");
        setRecurrenceEnd("");
        setDescription("");
        setNotes("");
    };

    const handleSubmit = async () => {
        if (!title || !categoryId || !amount || !expenseDate) {
            toast.error("Please fill in all required fields.");
            return;
        }
        setSubmitting(true);
        try {
            await expensesApi.create({
                title,
                category_id: Number(categoryId),
                amount: Number(amount),
                currency_code: currency,
                expense_date: expenseDate,
                payment_method: paymentMethod,
                payment_reference: paymentRef || undefined,
                vendor_name: vendorName || undefined,
                outlet_id: outletId || undefined,
                department: department || undefined,
                is_recurring: isRecurring,
                recurrence_frequency: isRecurring ? recurrenceFreq : undefined,
                recurrence_end_date:
                    isRecurring && recurrenceEnd ? recurrenceEnd : undefined,
                description: description || undefined,
                notes: notes || undefined,
            } as any);
            toast.success("Expense created.");
            qc.invalidateQueries({ queryKey: ["expenses"] });
            reset();
            onSaved();
        } catch (err: any) {
            toast.error(
                err?.response?.data?.message ?? "Failed to create expense.",
            );
        } finally {
            setSubmitting(false);
        }
    };

    const createdByName = user
        ? `${user.first_name ?? ""} ${user.last_name ?? ""}`.trim() ||
          user.email
        : "-";

    return (
        <Modal open={open} onClose={onClose} title="New Expense" size="lg">
            <div className="space-y-4">
                {/* Created by - read-only */}
                <div className="flex items-center gap-2 px-3 py-2 bg-surface-50 rounded-lg border border-surface-100">
                    <svg
                        className="w-4 h-4 text-surface-400 shrink-0"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.75}
                    >
                        <circle cx="12" cy="8" r="4" />
                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
                    </svg>
                    <span className="text-xs text-surface-500">Created by</span>
                    <span className="text-sm font-medium text-surface-900 ml-auto">
                        {createdByName}
                    </span>
                </div>

                <Field label="Title *">
                    <FieldInput
                        id="exp-title"
                        className="input"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        placeholder="e.g. Office rent – June 2025"
                    />
                </Field>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Category *">
                        <FieldSelect
                            id="exp-cat"
                            className="input"
                            value={categoryId}
                            onChange={(e) =>
                                setCategoryId(Number(e.target.value))
                            }
                        >
                            <option value="">Select category…</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>
                    <Field label="Date *">
                        <FieldInput
                            id="exp-date"
                            type="date"
                            className="input"
                            value={expenseDate}
                            onChange={(e) => setExpenseDate(e.target.value)}
                            max={dayjs().format("YYYY-MM-DD")}
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Amount *">
                        <FieldInput
                            id="exp-amt"
                            type="number"
                            min="0.01"
                            step="0.01"
                            className="input"
                            value={amount}
                            onChange={(e) =>
                                setAmount(
                                    e.target.value === ""
                                        ? ""
                                        : Number(e.target.value),
                                )
                            }
                            placeholder="0.00"
                        />
                    </Field>
                    <Field label="Currency">
                        <FieldSelect
                            id="exp-cur"
                            className="input"
                            value={currency}
                            onChange={(e) => setCurrency(e.target.value)}
                        >
                            <option value="KES">KES</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="GBP">GBP</option>
                        </FieldSelect>
                    </Field>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Payment Method *">
                        <FieldSelect
                            id="exp-pm"
                            className="input"
                            value={paymentMethod}
                            onChange={(e) => setPaymentMethod(e.target.value)}
                        >
                            {PAYMENT_METHODS.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>
                    <Field label="Payment Reference">
                        <FieldInput
                            id="exp-ref"
                            className="input"
                            value={paymentRef}
                            onChange={(e) => setPaymentRef(e.target.value)}
                            placeholder="e.g. M-PESA code"
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Outlet" hint="Leave blank for company-wide">
                        <FieldSelect
                            id="exp-outlet"
                            className="input"
                            value={outletId}
                            onChange={(e) =>
                                setOutletId(
                                    e.target.value
                                        ? Number(e.target.value)
                                        : "",
                                )
                            }
                        >
                            <option value="">Company-wide</option>
                            {outlets.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>
                    <Field label="Department">
                        <FieldInput
                            id="exp-dept"
                            className="input"
                            value={department}
                            onChange={(e) => setDepartment(e.target.value)}
                            placeholder="e.g. Operations"
                        />
                    </Field>
                </div>

                <Field label="Vendor / Supplier">
                    <FieldInput
                        id="exp-vendor"
                        className="input"
                        value={vendorName}
                        onChange={(e) => setVendorName(e.target.value)}
                        placeholder="Vendor name (optional)"
                    />
                </Field>

                {/* Recurring toggle */}
                <div className="rounded-lg border border-surface-200 overflow-hidden">
                    <label className="flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-surface-50 transition-colors">
                        <div className="flex items-center gap-2">
                            <svg
                                className="w-4 h-4 text-surface-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth={1.75}
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                />
                            </svg>
                            <span className="text-sm font-medium text-surface-900">
                                Recurring expense
                            </span>
                        </div>
                        <div
                            className={clsx(
                                "w-10 h-5.5 rounded-full transition-colors relative",
                                isRecurring ? "bg-brand-500" : "bg-surface-200",
                            )}
                        >
                            <span
                                className={clsx(
                                    "absolute top-0.5 w-4.5 h-4.5 bg-white rounded-full shadow transition-transform",
                                    isRecurring
                                        ? "translate-x-5"
                                        : "translate-x-0.5",
                                )}
                            />
                            <input
                                type="checkbox"
                                className="sr-only"
                                checked={isRecurring}
                                onChange={(e) =>
                                    setIsRecurring(e.target.checked)
                                }
                            />
                        </div>
                    </label>
                    {isRecurring && (
                        <div className="px-4 pb-4 pt-1 border-t border-surface-100 grid grid-cols-1 gap-4 sm:grid-cols-2 bg-surface-50/50">
                            <Field label="Frequency">
                                <FieldSelect
                                    id="exp-freq"
                                    className="input bg-white"
                                    value={recurrenceFreq}
                                    onChange={(e) =>
                                        setRecurrenceFreq(e.target.value)
                                    }
                                >
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="annually">Annually</option>
                                </FieldSelect>
                            </Field>
                            <Field label="End Date" hint="Optional">
                                <FieldInput
                                    id="exp-recend"
                                    type="date"
                                    className="input bg-white"
                                    value={recurrenceEnd}
                                    onChange={(e) =>
                                        setRecurrenceEnd(e.target.value)
                                    }
                                    min={expenseDate}
                                />
                            </Field>
                        </div>
                    )}
                </div>

                <Field label="Description">
                    <FieldTextarea
                        id="exp-desc"
                        className="input resize-none"
                        rows={2}
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        placeholder="Optional description…"
                    />
                </Field>

                <Field label="Internal Notes">
                    <FieldTextarea
                        id="exp-notes"
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Optional internal notes…"
                    />
                </Field>
            </div>

            <div className="flex justify-end gap-3 mt-6 pt-4 border-t border-surface-200">
                <button
                    className="btn-ghost"
                    onClick={onClose}
                    disabled={submitting}
                >
                    Cancel
                </button>
                <button
                    className="btn-primary"
                    onClick={handleSubmit}
                    disabled={submitting}
                >
                    {submitting ? (
                        <>
                            <Spinner size="sm" className="mr-1.5" /> Creating…
                        </>
                    ) : (
                        "Create Expense"
                    )}
                </button>
            </div>
        </Modal>
    );
}

// ─── Row Actions Dropdown ─────────────────────────────────────────────────────

function ExpenseRowActions({
    expense,
    onRefresh,
}: {
    expense: Expense;
    onRefresh: () => void;
}) {
    const toast = useToastStore();
    const { can } = usePermissions();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [rejectReason, setRejectReason] = useState("");
    const [busy, setBusy] = useState(false);

    const doAction = async (fn: () => Promise<any>, msg: string) => {
        setBusy(true);
        try {
            await fn();
            toast.success(msg);
            onRefresh();
        } catch (err: any) {
            toast.error(err?.response?.data?.message ?? "Action failed.");
        } finally {
            setBusy(false);
            setOpen(false);
        }
    };

    return (
        <div className="relative flex justify-end">
            <button
                className="w-8 h-8 flex items-center justify-center rounded-lg text-surface-400 hover:bg-surface-100 hover:text-surface-600 transition-colors"
                onClick={(e) => {
                    e.stopPropagation();
                    setOpen((o) => !o);
                }}
                disabled={busy}
                title="Actions"
            >
                {busy ? (
                    <Spinner size="sm" />
                ) : (
                    <svg
                        className="w-4 h-4"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                    >
                        <path d="M10 6a2 2 0 100-4 2 2 0 000 4zm0 6a2 2 0 100-4 2 2 0 000 4zm0 6a2 2 0 100-4 2 2 0 000 4z" />
                    </svg>
                )}
            </button>

            {open && (
                <div
                    className="absolute right-0 top-full mt-1 w-52 bg-white rounded-xl shadow-lg border border-surface-200 z-30 py-1 animate-fade-in"
                    onMouseLeave={() => setOpen(false)}
                >
                    <button
                        className="dropdown-item"
                        onClick={() => {
                            setOpen(false);
                            navigate(`/expenses/${expense.id}`);
                        }}
                    >
                        View Details
                    </button>

                    {expense.status === "draft" && can("expenses.create") && (
                        <button
                            className="dropdown-item"
                            onClick={() =>
                                doAction(
                                    () => expensesApi.submit(expense.id),
                                    "Submitted for approval.",
                                )
                            }
                        >
                            Submit for Approval
                        </button>
                    )}

                    {expense.status === "pending_approval" &&
                        can("expenses.approve") && (
                            <>
                                <div className="my-1 border-t border-surface-100" />
                                <button
                                    className="dropdown-item text-success"
                                    onClick={() =>
                                        doAction(
                                            () =>
                                                expensesApi.approve(expense.id),
                                            "Expense approved.",
                                        )
                                    }
                                >
                                    ✓ Approve
                                </button>
                                <button
                                    className="dropdown-item text-danger"
                                    onClick={() => {
                                        setOpen(false);
                                        setRejecting(true);
                                    }}
                                >
                                    ✕ Reject
                                </button>
                            </>
                        )}

                    {expense.status === "approved" &&
                        can("expenses.approve") && (
                            <button
                                className="dropdown-item"
                                onClick={() =>
                                    doAction(
                                        () => expensesApi.markPaid(expense.id),
                                        "Marked as paid.",
                                    )
                                }
                            >
                                Mark as Paid
                            </button>
                        )}

                    {["draft", "rejected", "cancelled"].includes(
                        expense.status,
                    ) &&
                        can("expenses.delete") && (
                        <>
                            <div className="my-1 border-t border-surface-100" />
                            <button
                                className="dropdown-item text-danger"
                                onClick={() =>
                                    doAction(
                                        () => expensesApi.delete(expense.id),
                                        "Expense deleted.",
                                    )
                                }
                            >
                                Delete
                            </button>
                        </>
                    )}
                </div>
            )}

            {rejecting && (
                <Modal
                    open={rejecting}
                    onClose={() => setRejecting(false)}
                    title="Reject Expense"
                    size="sm"
                >
                    <p className="text-sm text-surface-600 mb-3">
                        Provide a reason for rejection:
                    </p>
                    <textarea
                        className="input w-full resize-none"
                        rows={3}
                        value={rejectReason}
                        onChange={(e) => setRejectReason(e.target.value)}
                        placeholder="Reason…"
                    />
                    <div className="flex justify-end gap-3 mt-4">
                        <button
                            className="btn-ghost"
                            onClick={() => setRejecting(false)}
                        >
                            Cancel
                        </button>
                        <button
                            className="btn-danger"
                            disabled={!rejectReason.trim() || busy}
                            onClick={() => {
                                setRejecting(false);
                                doAction(
                                    () =>
                                        expensesApi.reject(
                                            expense.id,
                                            rejectReason,
                                        ),
                                    "Expense rejected.",
                                );
                            }}
                        >
                            Reject Expense
                        </button>
                    </div>
                </Modal>
            )}
        </div>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ExpensesPage() {
    const { can } = usePermissions();
    const navigate = useNavigate();

    const [newModalOpen, setNewModalOpen] = useState(false);
    const { state, setPage, setSearch } = useTableState({ defaultPerPage: 20 });
    const [statusFilter, setStatusFilter] = useState("");
    const [startDate, setStartDate] = useState("");
    const [endDate, setEndDate] = useState("");

    const hasFilters = !!(statusFilter || startDate || endDate);

    const params: ExpenseListParams = {
        page: state.page,
        per_page: state.perPage,
        search: state.search || undefined,
        status: statusFilter || undefined,
        start_date: startDate || undefined,
        end_date: endDate || undefined,
        sort: "expense_date",
        direction: "desc",
    };

    const { data, isLoading, refetch } = useQuery({
        queryKey: ["expenses", params],
        queryFn: () => expensesApi.list(params),
    });

    const expenses = (data?.expenses?.data ?? []) as Expense[];
    const pagination = data?.expenses;
    const stats = data?.stats;

    // Group the current page of rows by expense_date. Pagination, sort, and
    // filters are untouched - this only re-partitions the rows already fetched.
    const expenseGroups = groupRowsByDate(expenses, (exp) => exp.expense_date);

    const clearFilters = () => {
        setStatusFilter("");
        setStartDate("");
        setEndDate("");
        setPage(1);
    };

    return (
        <div className="space-y-5 animate-fade-in">
            {/* ── Header ── */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Expenses</h1>
                    <p className="page-subtitle">
                        Track, submit, and approve business expenses.
                    </p>
                </div>
                <div className="flex items-center gap-2 shrink-0 flex-wrap">
                    {stats?.pending_count > 0 && (
                        <div className="flex items-center gap-1.5 px-3 py-1.5 bg-warning-light text-warning-dark rounded-xl text-sm font-medium">
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
                                    d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            {stats.pending_count} pending
                        </div>
                    )}
                    {can("expenses.create") && (
                        <button
                            className="btn-primary"
                            onClick={() => setNewModalOpen(true)}
                        >
                            + New Expense
                        </button>
                    )}
                </div>
            </div>

            {/* ── Stats ── */}
            {stats && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {[
                        {
                            label: "Approved (Period)",
                            value: fmtKes(stats.approved_total),
                            color: "text-surface-900",
                        },
                        {
                            label: "Pending Approval",
                            value: fmtKes(stats.pending_total),
                            color: "text-warning",
                        },
                        {
                            label: "Total Records",
                            value: String(stats.total_count),
                            color: "text-surface-900",
                        },
                    ].map((s) => (
                        <div
                            key={s.label}
                            className="card card-body flex flex-col gap-1"
                        >
                            <p className="text-xs text-surface-500">
                                {s.label}
                            </p>
                            <p className={clsx("text-xl font-bold", s.color)}>
                                {s.value}
                            </p>
                        </div>
                    ))}
                </div>
            )}

            {/* ── Filters ── */}
            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                {/* Search */}
                <div className="relative w-full sm:flex-1 sm:min-w-48">
                    <svg
                        className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-surface-400"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={2}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"
                        />
                    </svg>
                    <input
                        className="input pl-9 w-full"
                        placeholder="Search expenses…"
                        value={state.search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setPage(1);
                        }}
                    />
                </div>

                <div className="flex gap-2 flex-wrap">
                <select
                    className="input flex-1 sm:w-44 sm:flex-none"
                    value={statusFilter}
                    onChange={(e) => {
                        setStatusFilter(e.target.value);
                        setPage(1);
                    }}
                >
                    <option value="">All Statuses</option>
                    {Object.entries(EXPENSE_STATUS_CONFIG).map(([k, v]) => (
                        <option key={k} value={k}>
                            {v.label}
                        </option>
                    ))}
                </select>

                <input
                    type="date"
                    className="input flex-1 sm:w-36 sm:flex-none"
                    title="From date"
                    value={startDate}
                    onChange={(e) => {
                        setStartDate(e.target.value);
                        setPage(1);
                    }}
                />
                <input
                    type="date"
                    className="input flex-1 sm:w-36 sm:flex-none"
                    title="To date"
                    value={endDate}
                    onChange={(e) => {
                        setEndDate(e.target.value);
                        setPage(1);
                    }}
                />

                {hasFilters && (
                    <button
                        className="btn-ghost btn-sm text-danger"
                        onClick={clearFilters}
                    >
                        Clear
                    </button>
                )}
                </div>
            </div>

            {/* ── Table ── */}
            <div className="card overflow-hidden">
                <div className="overflow-x-auto">
                <table className="w-full min-w-[640px]">
                    <thead>
                        <tr className="border-b border-surface-100 bg-surface-50/50">
                            <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                Reference
                            </th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Title
                            </th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                                Category
                            </th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">
                                Date
                            </th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden lg:table-cell">
                                Vendor
                            </th>
                            <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Amount (KES)
                            </th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                Status
                            </th>
                            <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">
                                Submitted By
                            </th>
                            <th className="px-4 py-3 w-12" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-surface-50">
                        {isLoading ? (
                            <tr>
                                <td
                                    colSpan={9}
                                    className="px-4 py-12 text-center"
                                >
                                    <Spinner />
                                </td>
                            </tr>
                        ) : expenses.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={9}
                                    className="px-4 py-16 text-center text-surface-400 text-sm"
                                >
                                    {hasFilters || state.search
                                        ? "No expenses match your filters."
                                        : "No expenses yet."}
                                </td>
                            </tr>
                        ) : (
                            expenseGroups.map((group) => (
                                <Fragment key={group.key}>
                                    <DateGroupHeaderRow label={group.label} colSpan={9} />
                                    {group.items.map((exp) => (
                            <tr
                                    key={exp.id}
                                    className="hover:bg-surface-50/50 transition-colors cursor-pointer"
                                    onClick={() =>
                                        navigate(`/expenses/${exp.id}`)
                                    }
                                >
                                    {/* Reference */}
                                    <td className="px-4 py-3 hidden sm:table-cell">
                                        <span className="font-mono text-xs text-surface-500 bg-surface-100 px-1.5 py-0.5 rounded">
                                            {exp.reference_number}
                                        </span>
                                    </td>

                                    {/* Title */}
                                    <td className="px-4 py-3 max-w-[180px] sm:max-w-[200px]">
                                        <p className="font-medium text-surface-900 truncate">
                                            {exp.title}
                                        </p>
                                        {exp.vendor_name && (
                                            <p className="text-xs text-surface-400 truncate mt-0.5">
                                                {exp.vendor_name}
                                            </p>
                                        )}
                                    </td>

                                    {/* Category */}
                                    <td className="px-4 py-3 hidden md:table-cell">
                                        {exp.category ? (
                                            <span className="inline-flex items-center gap-1.5 text-sm text-surface-700">
                                                {exp.category.color && (
                                                    <span
                                                        className="w-2 h-2 rounded-full shrink-0"
                                                        style={{
                                                            backgroundColor:
                                                                exp.category
                                                                    .color,
                                                        }}
                                                    />
                                                )}
                                                {exp.category.name}
                                            </span>
                                        ) : (
                                            <span className="text-surface-400">
                                                -
                                            </span>
                                        )}
                                    </td>

                                    {/* Date */}
                                    <td className="px-4 py-3 text-sm text-surface-600 whitespace-nowrap hidden sm:table-cell">
                                        {dayjs(exp.expense_date).format(
                                            "DD MMM YYYY",
                                        )}
                                    </td>

                                    {/* Vendor */}
                                    <td className="px-4 py-3 text-sm text-surface-500 max-w-[120px] truncate hidden lg:table-cell">
                                        {exp.vendor_name ?? (
                                            <span className="text-surface-300">
                                                -
                                            </span>
                                        )}
                                    </td>

                                    {/* Amount */}
                                    <td className="px-4 py-3 text-right">
                                        <span className="font-semibold text-surface-900 tabular-nums">
                                            {fmtKes(exp.amount_kes)}
                                        </span>
                                        {exp.currency_code !== "KES" && (
                                            <p className="text-xs text-surface-400 mt-0.5 text-right tabular-nums">
                                                {exp.currency_code}{" "}
                                                {Number(
                                                    exp.amount,
                                                ).toLocaleString()}
                                            </p>
                                        )}
                                    </td>

                                    {/* Status */}
                                    <td className="px-4 py-3">
                                        <StatusBadge status={exp.status} />
                                    </td>

                                    {/* Submitted by */}
                                    <td className="px-4 py-3 text-sm text-surface-600 hidden md:table-cell">
                                        {exp.submittedBy ? (
                                            `${exp.submittedBy.first_name} ${exp.submittedBy.last_name}`
                                        ) : (
                                            <span className="text-surface-300">
                                                -
                                            </span>
                                        )}
                                    </td>

                                    {/* Actions */}
                                    <td
                                        className="px-4 py-3"
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <ExpenseRowActions
                                            expense={exp}
                                            onRefresh={() => refetch()}
                                        />
                                    </td>
                                </tr>
                                    ))}
                                </Fragment>
                            ))
                        )}
                    </tbody>
                </table>
                </div>

                {/* Pagination */}
                {pagination && pagination.last_page > 1 && (
                    <div className="flex flex-col gap-2 px-4 py-3 border-t border-surface-100 bg-surface-50/50 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-surface-500">
                            Showing {(state.page - 1) * state.perPage + 1}–
                            {Math.min(
                                state.page * state.perPage,
                                pagination.total,
                            )}{" "}
                            of {pagination.total}
                        </p>
                        <div className="flex items-center gap-1">
                            <button
                                className="btn-ghost btn-sm"
                                disabled={state.page === 1}
                                onClick={() => setPage(state.page - 1)}
                            >
                                ← Prev
                            </button>
                            <span className="px-3 py-1 text-sm text-surface-600">
                                {pagination.current_page} /{" "}
                                {pagination.last_page}
                            </span>
                            <button
                                className="btn-ghost btn-sm"
                                disabled={state.page >= pagination.last_page}
                                onClick={() => setPage(state.page + 1)}
                            >
                                Next →
                            </button>
                        </div>
                    </div>
                )}
            </div>

            <NewExpenseModal
                open={newModalOpen}
                onClose={() => setNewModalOpen(false)}
                onSaved={() => setNewModalOpen(false)}
            />
        </div>
    );
}