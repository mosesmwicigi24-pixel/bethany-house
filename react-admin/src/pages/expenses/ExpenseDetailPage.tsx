// src/pages/expenses/ExpenseDetailPage.tsx
import { useState, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery, useQueryClient, useMutation } from "@tanstack/react-query";
import { get } from "@/api/client";
import {
    expensesApi,
    EXPENSE_STATUS_CONFIG,
    PAYMENT_METHODS,
    fmtKes,
} from "@/api/expenses";
import type { Expense } from "@/api/expenses";
import { useToastStore } from "@/store/toast.store";
import { useAuthStore } from "@/store/auth.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import {
    Field,
    useFieldAriaProps,
    FieldInput,
    FieldSelect,
    FieldTextarea,
} from "@/components/setup/FormComponents";
import { clsx } from "clsx";
import dayjs from "dayjs";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";

// ─── Shared ───────────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
    const cfg =
        EXPENSE_STATUS_CONFIG[status as keyof typeof EXPENSE_STATUS_CONFIG] ??
        EXPENSE_STATUS_CONFIG.draft;
    return (
        <span
            className={clsx(
                "inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium",
                cfg.bg,
                cfg.text,
            )}
        >
            <span className={clsx("w-2 h-2 rounded-full shrink-0", cfg.dot)} />
            {cfg.label}
        </span>
    );
}

function SectionCard({
    title,
    children,
    action,
}: {
    title: string;
    children: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <div className="card overflow-hidden">
            <div className="flex items-center justify-between px-5 py-4 border-b border-surface-100">
                <h3 className="font-semibold text-surface-900 text-sm">
                    {title}
                </h3>
                {action}
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

// ─── Edit Expense Modal ───────────────────────────────────────────────────────

function EditExpenseModal({
    expense,
    open,
    onClose,
    onSaved,
}: {
    expense: Expense;
    open: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const { user } = useAuthStore();

    const [title, setTitle] = useState(expense.title);
    const [amount, setAmount] = useState<number | "">(Number(expense.amount));
    const [currency, setCurrency] = useState(expense.currency_code);
    const [expenseDate, setExpenseDate] = useState(
        dayjs(expense.expense_date).format("YYYY-MM-DD"),
    );
    const [paymentMethod, setPaymentMethod] = useState(expense.payment_method);
    const [paymentRef, setPaymentRef] = useState(
        expense.payment_reference ?? "",
    );
    const [vendorName, setVendorName] = useState(expense.vendor_name ?? "");
    const [vendorContact, setVendorContact] = useState(
        expense.vendor_contact ?? "",
    );
    const [outletId, setOutletId] = useState<number | "">(
        expense.outlet_id ?? "",
    );
    const [department, setDepartment] = useState(expense.department ?? "");
    const [isRecurring, setIsRecurring] = useState(expense.is_recurring);
    const [recurrenceFreq, setRecurrenceFreq] = useState(
        expense.recurrence_frequency ?? "monthly",
    );
    const [recurrenceEnd, setRecurrenceEnd] = useState(
        expense.recurrence_end_date ?? "",
    );
    const [description, setDescription] = useState(expense.description ?? "");
    const [notes, setNotes] = useState(expense.notes ?? "");
    const [tags, setTags] = useState((expense.tags ?? []).join(", "));

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

    const mutation = useMutation({
        mutationFn: (data: any) => expensesApi.update(expense.id, data),
        onSuccess: () => {
            toast.success("Expense updated.");
            qc.invalidateQueries({ queryKey: ["expense", String(expense.id)] });
            qc.invalidateQueries({ queryKey: ["expenses"] });
            onSaved();
        },
        onError: (err: any) =>
            toast.error(err?.response?.data?.message ?? "Update failed."),
    });

    const handleSave = () => {
        if (!title || !amount || !expenseDate) {
            toast.error("Title, amount, and date are required.");
            return;
        }
        mutation.mutate({
            title,
            amount: Number(amount),
            currency_code: currency,
            expense_date: expenseDate,
            payment_method: paymentMethod,
            payment_reference: paymentRef || undefined,
            vendor_name: vendorName || undefined,
            vendor_contact: vendorContact || undefined,
            outlet_id: outletId || undefined,
            department: department || undefined,
            is_recurring: isRecurring,
            recurrence_frequency: isRecurring ? recurrenceFreq : undefined,
            recurrence_end_date:
                isRecurring && recurrenceEnd ? recurrenceEnd : undefined,
            description: description || undefined,
            notes: notes || undefined,
            tags: tags
                ? tags
                      .split(",")
                      .map((t) => t.trim())
                      .filter(Boolean)
                : [],
        });
    };

    // Show who originally created this expense (read-only)
    const createdByName = (() => {
        const cb = (expense as any).createdBy;
        if (cb)
            return (
                `${cb.first_name ?? ""} ${cb.last_name ?? ""}`.trim() ||
                cb.email
            );
        if (user)
            return (
                `${user.first_name ?? ""} ${user.last_name ?? ""}`.trim() ||
                user.email
            );
        return "-";
    })();

    return (
        <Modal open={open} onClose={onClose} title="Edit Expense" size="lg">
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
                        id="edit-title"
                        className="input"
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                    />
                </Field>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Amount *">
                        <FieldInput
                            id="edit-amt"
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
                        />
                    </Field>
                    <Field label="Currency">
                        <FieldSelect
                            id="edit-cur"
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
                    <Field label="Expense Date *">
                        <FieldInput
                            id="edit-date"
                            type="date"
                            className="input"
                            value={expenseDate}
                            onChange={(e) => setExpenseDate(e.target.value)}
                            max={dayjs().format("YYYY-MM-DD")}
                        />
                    </Field>
                    <Field label="Payment Method">
                        <FieldSelect
                            id="edit-pm"
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
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Vendor Name">
                        <FieldInput
                            id="edit-vendor"
                            className="input"
                            value={vendorName}
                            onChange={(e) => setVendorName(e.target.value)}
                        />
                    </Field>
                    <Field label="Vendor Contact">
                        <FieldInput
                            id="edit-vcon"
                            className="input"
                            value={vendorContact}
                            onChange={(e) => setVendorContact(e.target.value)}
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Payment Reference">
                        <FieldInput
                            id="edit-ref"
                            className="input"
                            value={paymentRef}
                            onChange={(e) => setPaymentRef(e.target.value)}
                            placeholder="e.g. M-PESA code"
                        />
                    </Field>
                    <Field label="Department">
                        <FieldInput
                            id="edit-dept"
                            className="input"
                            value={department}
                            onChange={(e) => setDepartment(e.target.value)}
                        />
                    </Field>
                </div>

                <Field label="Outlet" hint="Leave blank for company-wide">
                    <FieldSelect
                        id="edit-outlet"
                        className="input"
                        value={outletId}
                        onChange={(e) =>
                            setOutletId(
                                e.target.value ? Number(e.target.value) : "",
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
                                    id="edit-freq"
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
                                    id="edit-recend"
                                    type="date"
                                    className="input bg-white"
                                    value={recurrenceEnd ?? ""}
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
                        id="edit-desc"
                        className="input resize-none"
                        rows={2}
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                    />
                </Field>

                <Field label="Internal Notes">
                    <FieldTextarea
                        id="edit-notes"
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                    />
                </Field>

                <Field
                    label="Tags"
                    hint="Comma-separated, e.g. recurring, capex, travel"
                >
                    <FieldInput
                        id="edit-tags"
                        className="input"
                        value={tags}
                        onChange={(e) => setTags(e.target.value)}
                    />
                </Field>
            </div>

            <div className="flex justify-end gap-3 mt-6 pt-4 border-t border-surface-200">
                <button
                    className="btn-ghost"
                    onClick={onClose}
                    disabled={mutation.isPending}
                >
                    Cancel
                </button>
                <button
                    className="btn-primary"
                    onClick={handleSave}
                    disabled={mutation.isPending}
                >
                    {mutation.isPending ? (
                        <>
                            <Spinner size="sm" className="mr-1.5" />
                            Saving…
                        </>
                    ) : (
                        "Save Changes"
                    )}
                </button>
            </div>
        </Modal>
    );
}

// ─── Mark as Paid Modal ───────────────────────────────────────────────────────

function MarkPaidModal({
    expense,
    open,
    onClose,
    onSaved,
}: {
    expense: Expense;
    open: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [paymentRef, setPaymentRef] = useState(
        expense.payment_reference ?? "",
    );
    const [paymentMethod, setPaymentMethod] = useState(expense.payment_method);

    const mutation = useMutation({
        mutationFn: () =>
            expensesApi.markPaid(expense.id, {
                payment_reference: paymentRef,
                payment_method: paymentMethod,
            }),
        onSuccess: () => {
            toast.success("Expense marked as paid.");
            qc.invalidateQueries({ queryKey: ["expense", String(expense.id)] });
            qc.invalidateQueries({ queryKey: ["expenses"] });
            onSaved();
        },
        onError: (err: any) =>
            toast.error(err?.response?.data?.message ?? "Action failed."),
    });

    return (
        <Modal open={open} onClose={onClose} title="Mark as Paid" size="sm">
            <p className="text-sm text-surface-600 mb-4">
                Confirm payment details before marking this expense as paid.
            </p>
            <div className="space-y-3">
                <Field label="Payment Method">
                    <FieldSelect
                        id="paid-pm"
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
                <Field
                    label="Payment Reference"
                    hint="M-PESA code, bank ref, etc."
                >
                    <FieldInput
                        id="paid-ref"
                        className="input"
                        value={paymentRef}
                        onChange={(e) => setPaymentRef(e.target.value)}
                    />
                </Field>
            </div>
            <div className="flex justify-end gap-3 mt-5 pt-4 border-t border-surface-200">
                <button
                    className="btn-ghost"
                    onClick={onClose}
                    disabled={mutation.isPending}
                >
                    Cancel
                </button>
                <button
                    className="btn-primary"
                    onClick={() => mutation.mutate()}
                    disabled={mutation.isPending}
                >
                    {mutation.isPending ? (
                        <>
                            <Spinner size="sm" className="mr-1.5" />
                            Processing…
                        </>
                    ) : (
                        "Confirm Payment"
                    )}
                </button>
            </div>
        </Modal>
    );
}

// ─── Rejection history timeline ───────────────────────────────────────────────

function ApprovalTimeline({ approvals }: { approvals: any[] }) {
    return (
        <div className="space-y-4">
            {approvals.map((a, i) => (
                <div key={a.id} className="flex gap-3">
                    {/* Icon */}
                    <div
                        className={clsx(
                            "w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0",
                            a.action === "approved"
                                ? "bg-success"
                                : a.action === "rejected"
                                  ? "bg-danger"
                                  : "bg-info",
                        )}
                    >
                        {a.action === "approved"
                            ? "✓"
                            : a.action === "rejected"
                              ? "✕"
                              : "?"}
                    </div>
                    {/* Content */}
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-medium text-surface-900 text-sm">
                                {a.approver?.first_name} {a.approver?.last_name}
                            </span>
                            <span
                                className={clsx(
                                    "text-xs font-medium px-2 py-0.5 rounded-full",
                                    a.action === "approved"
                                        ? "bg-success-light text-success"
                                        : a.action === "rejected"
                                          ? "bg-danger-light text-danger"
                                          : "bg-info-light text-info",
                                )}
                            >
                                {a.action}
                            </span>
                            {a.step > 1 && (
                                <span className="text-xs text-surface-400">
                                    Step {a.step}
                                </span>
                            )}
                        </div>
                        {a.comments && (
                            <p className="text-sm text-surface-600 mt-1 bg-surface-50 rounded-lg px-3 py-2">
                                "{a.comments}"
                            </p>
                        )}
                        <p className="text-xs text-surface-400 mt-1">
                            {dayjs(a.acted_at).format("DD MMM YYYY · HH:mm")}
                        </p>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ExpenseDetailPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const { can } = usePermissions();
    const fileRef = useRef<HTMLInputElement>(null);

    const [editOpen, setEditOpen] = useState(false);
    const [rejectOpen, setRejectOpen] = useState(false);
    const [markPaidOpen, setMarkPaidOpen] = useState(false);
    const [receiptPreviewOpen, setReceiptPreviewOpen] = useState(false);
    const [receiptBlob, setReceiptBlob] = useState<{
        url: string;
        mimeType: string;
    } | null>(null);
    const [receiptLoading, setReceiptLoading] = useState(false);
    const [rejectReason, setRejectReason] = useState("");
    const [busy, setBusy] = useState(false);

    const openReceiptPreview = async () => {
        if (!expense?.receipt_path) return;
        setReceiptLoading(true);
        setReceiptPreviewOpen(true);
        try {
            const blob = await expensesApi.fetchReceiptBlob(expense.id);
            setReceiptBlob(blob);
        } catch {
            toast.error("Failed to load receipt.");
            setReceiptPreviewOpen(false);
        } finally {
            setReceiptLoading(false);
        }
    };

    const closeReceiptPreview = () => {
        setReceiptPreviewOpen(false);
        if (receiptBlob) {
            URL.revokeObjectURL(receiptBlob.url);
            setReceiptBlob(null);
        }
    };

    const { data, isLoading, refetch } = useQuery({
        queryKey: ["expense", id],
        queryFn: () => expensesApi.show(Number(id)),
    });
    const expense = data?.expense;

    const doAction = async (fn: () => Promise<any>, msg: string) => {
        setBusy(true);
        try {
            await fn();
            toast.success(msg);
            qc.invalidateQueries({ queryKey: ["expense", id] });
            qc.invalidateQueries({ queryKey: ["expenses"] });
            refetch();
        } catch (err: any) {
            toast.error(err?.response?.data?.message ?? "Action failed.");
        } finally {
            setBusy(false);
        }
    };

    const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        try {
            await expensesApi.uploadReceipt(Number(id), file);
            toast.success("Receipt uploaded.");
            refetch();
        } catch {
            toast.error("Upload failed.");
        }
        e.target.value = "";
    };

    if (isLoading)
        return (
            <div className="flex justify-center py-20">
                <Spinner />
            </div>
        );
    if (!expense)
        return (
            <div className="text-center py-20">
                <p className="text-surface-500 mb-3">Expense not found.</p>
                <button
                    className="btn-ghost"
                    onClick={() => navigate("/expenses")}
                >
                    ← Back to Expenses
                </button>
            </div>
        );

    // These were purely status-based, with no permission check at all -
    // Edit/Cancel/Delete/Submit all showed for any user regardless of
    // expenses.edit/expenses.delete/expenses.create, only to 403 on the
    // matching route (PUT /{id}, POST /{id}/cancel -> expenses.edit;
    // DELETE /{id} -> expenses.delete; POST /{id}/submit -> expenses.create).
    const canCreate = can("expenses.create");
    const canEditExpense = can("expenses.edit");
    const isEditable =
        ["draft", "rejected"].includes(expense.status) && canEditExpense;
    const isDeletable =
        ["draft", "rejected", "cancelled"].includes(expense.status) &&
        can("expenses.delete");
    const canApprove = can("expenses.approve");
    const paymentMethod =
        PAYMENT_METHODS.find((m) => m.value === expense.payment_method)
            ?.label ?? expense.payment_method;

    return (
        <div className="space-y-5 animate-fade-in max-w-5xl">
            {/* ── Breadcrumb + header ── */}
            <div className="flex items-center gap-2 text-sm text-surface-500">
                <button
                    className="hover:text-surface-900 transition-colors"
                    onClick={() => navigate("/expenses")}
                >
                    Expenses
                </button>
                <span className="text-surface-300">/</span>
                <span className="text-surface-700 font-medium">
                    {expense.reference_number}
                </span>
            </div>

            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
                <div>
                    <h1 className="page-title">{expense.title}</h1>
                    <p className="page-subtitle">
                        {expense.reference_number}
                        {expense.category && <> · {expense.category.name}</>}
                        {" · "}
                        {dayjs(expense.expense_date).format("DD MMM YYYY")}
                    </p>
                </div>

                {/* Action area */}
                <div className="flex items-center gap-2 flex-wrap shrink-0">
                    <StatusBadge status={expense.status} />
                    <PdfDownloadButton type="expenses" id={expense.id} label="Download PDF" />

                    {expense.status === "draft" && canCreate && (
                        <button
                            className="btn-primary"
                            disabled={busy}
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

                    {expense.status === "pending_approval" && canApprove && (
                        <>
                            <button
                                className="btn-secondary"
                                disabled={busy}
                                onClick={() =>
                                    doAction(
                                        () => expensesApi.approve(expense.id),
                                        "Expense approved.",
                                    )
                                }
                            >
                                ✓ Approve
                            </button>
                            <button
                                className="btn-danger"
                                disabled={busy}
                                onClick={() => setRejectOpen(true)}
                            >
                                ✕ Reject
                            </button>
                        </>
                    )}

                    {expense.status === "approved" && canApprove && (
                        <button
                            className="btn-primary"
                            disabled={busy}
                            onClick={() => setMarkPaidOpen(true)}
                        >
                            Mark as Paid
                        </button>
                    )}

                    {isEditable && (
                        <button
                            className="btn-ghost"
                            onClick={() => setEditOpen(true)}
                        >
                            Edit
                        </button>
                    )}

                    {!["paid", "cancelled"].includes(expense.status) &&
                        canEditExpense && (
                        <button
                            className="btn-ghost text-surface-500"
                            disabled={busy}
                            onClick={() =>
                                doAction(
                                    () => expensesApi.cancel(expense.id),
                                    "Expense cancelled.",
                                )
                            }
                        >
                            Cancel
                        </button>
                    )}

                    {isDeletable && (
                        <button
                            className="btn-ghost text-danger"
                            disabled={busy}
                            onClick={() => {
                                if (
                                    confirm(
                                        "Delete this expense? This cannot be undone.",
                                    )
                                ) {
                                    doAction(
                                        () => expensesApi.delete(expense.id),
                                        "Expense deleted.",
                                    ).then(() => navigate("/expenses"));
                                }
                            }}
                        >
                            Delete
                        </button>
                    )}
                </div>
            </div>

            {/* ── Rejection banner ── */}
            {expense.status === "rejected" && expense.rejection_reason && (
                <div className="flex gap-3 p-4 bg-danger-light border border-danger/20 rounded-xl">
                    <svg
                        className="w-5 h-5 text-danger shrink-0 mt-0.5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={2}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"
                        />
                    </svg>
                    <div>
                        <p className="text-sm font-semibold text-danger">
                            Expense Rejected
                        </p>
                        <p className="text-sm text-danger/80 mt-0.5">
                            {expense.rejection_reason}
                        </p>
                        {expense.rejected_by && (
                            <p className="text-xs text-danger/60 mt-1">
                                by {(expense.rejected_by as any).first_name}{" "}
                                {(expense.rejected_by as any).last_name}
                                {expense.rejected_by && (
                                    <>
                                        {" "}
                                        ·{" "}
                                        {dayjs(
                                            (expense as any).rejected_at,
                                        ).format("DD MMM YYYY")}
                                    </>
                                )}
                            </p>
                        )}
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                {/* ── Left: main content ── */}
                <div className="lg:col-span-2 space-y-5">
                    {/* Financial summary strip */}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div className="card card-body">
                            <p className="text-xs text-surface-500">Amount</p>
                            <p className="text-lg font-bold text-surface-900 tabular-nums mt-0.5">
                                {expense.currency_code !== "KES" && (
                                    <>
                                        {expense.currency_code}{" "}
                                        {Number(expense.amount).toLocaleString(
                                            "en-KE",
                                            { minimumFractionDigits: 2 },
                                        )}
                                    </>
                                )}
                                {expense.currency_code === "KES" &&
                                    fmtKes(expense.amount_kes)}
                            </p>
                            {expense.currency_code !== "KES" && (
                                <p className="text-xs text-surface-400 mt-0.5 tabular-nums">
                                    {fmtKes(expense.amount_kes)}
                                </p>
                            )}
                        </div>
                        <div className="card card-body">
                            <p className="text-xs text-surface-500">Payment</p>
                            <p className="text-sm font-semibold text-surface-900 mt-0.5">
                                {paymentMethod}
                            </p>
                            {expense.payment_reference && (
                                <p className="text-xs font-mono text-surface-400 mt-0.5 truncate">
                                    {expense.payment_reference}
                                </p>
                            )}
                        </div>
                        <div className="card card-body">
                            <p className="text-xs text-surface-500">Category</p>
                            {expense.category && (
                                <div className="flex items-center gap-1.5 mt-0.5">
                                    {expense.category.color && (
                                        <span
                                            className="w-2.5 h-2.5 rounded-full shrink-0"
                                            style={{
                                                backgroundColor:
                                                    expense.category.color,
                                            }}
                                        />
                                    )}
                                    <p className="text-sm font-semibold text-surface-900 truncate">
                                        {expense.category.name}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Core details */}
                    <SectionCard title="Expense Details">
                        <dl className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
                            {[
                                {
                                    label: "Vendor",
                                    value: expense.vendor_name ?? "-",
                                },
                                {
                                    label: "Vendor Contact",
                                    value: expense.vendor_contact ?? "-",
                                },
                                {
                                    label: "Outlet",
                                    value:
                                        expense.outlet?.name ?? "Company-wide",
                                },
                                {
                                    label: "Department",
                                    value: expense.department ?? "-",
                                },
                                {
                                    label: "Exchange Rate",
                                    value:
                                        expense.currency_code !== "KES"
                                            ? `1 ${expense.currency_code} = ${Number(expense.exchange_rate).toFixed(4)} KES`
                                            : "N/A",
                                },
                                {
                                    label: "Recurring",
                                    value: expense.is_recurring
                                        ? `Yes · ${expense.recurrence_frequency}`
                                        : "No",
                                },
                            ].map(({ label, value }) => (
                                <div key={label}>
                                    <dt className="text-xs text-surface-500 uppercase tracking-wide mb-0.5">
                                        {label}
                                    </dt>
                                    <dd className="text-sm font-medium text-surface-900">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>

                        {(expense.description || expense.notes) && (
                            <div className="mt-5 pt-5 border-t border-surface-100 space-y-4">
                                {expense.description && (
                                    <div>
                                        <p className="text-xs text-surface-500 uppercase tracking-wide mb-1">
                                            Description
                                        </p>
                                        <p className="text-sm text-surface-700 leading-relaxed">
                                            {expense.description}
                                        </p>
                                    </div>
                                )}
                                {expense.notes && (
                                    <div>
                                        <p className="text-xs text-surface-500 uppercase tracking-wide mb-1">
                                            Internal Notes
                                        </p>
                                        <p className="text-sm text-surface-700 leading-relaxed bg-surface-50 rounded-lg p-3">
                                            {expense.notes}
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}

                        {expense.tags && expense.tags.length > 0 && (
                            <div className="mt-4 pt-4 border-t border-surface-100">
                                <p className="text-xs text-surface-500 uppercase tracking-wide mb-2">
                                    Tags
                                </p>
                                <div className="flex flex-wrap gap-1.5">
                                    {expense.tags.map((tag) => (
                                        <span
                                            key={tag}
                                            className="px-2.5 py-0.5 bg-surface-100 text-surface-600 text-xs font-medium rounded-full"
                                        >
                                            {tag}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                    </SectionCard>

                    {/* Linked records */}
                    {((expense as any).purchase_order ||
                        (expense as any).production_order ||
                        (expense as any).order) && (
                        <SectionCard title="Linked Records">
                            <div className="space-y-2">
                                {(expense as any).purchase_order && (
                                    <div className="flex items-center justify-between py-2 border-b border-surface-50">
                                        <span className="text-sm text-surface-500">
                                            Purchase Order
                                        </span>
                                        <span className="text-sm font-mono font-medium text-brand-600">
                                            {
                                                (expense as any).purchase_order
                                                    .po_number
                                            }
                                        </span>
                                    </div>
                                )}
                                {(expense as any).production_order && (
                                    <div className="flex items-center justify-between py-2 border-b border-surface-50">
                                        <span className="text-sm text-surface-500">
                                            Production Order
                                        </span>
                                        <span className="text-sm font-mono font-medium text-brand-600">
                                            {
                                                (expense as any)
                                                    .production_order
                                                    .order_number
                                            }
                                        </span>
                                    </div>
                                )}
                                {(expense as any).order && (
                                    <div className="flex items-center justify-between py-2">
                                        <span className="text-sm text-surface-500">
                                            Sales Order
                                        </span>
                                        <span className="text-sm font-mono font-medium text-brand-600">
                                            {
                                                (expense as any).order
                                                    .order_number
                                            }
                                        </span>
                                    </div>
                                )}
                            </div>
                        </SectionCard>
                    )}

                    {/* Line items */}
                    {expense.lineItems && expense.lineItems.length > 0 && (
                        <div className="card overflow-hidden">
                            <div className="flex items-center justify-between px-5 py-4 border-b border-surface-100">
                                <h3 className="font-semibold text-surface-900 text-sm">
                                    Line Items
                                </h3>
                                <span className="text-xs text-surface-500">
                                    {expense.lineItems.length} items
                                </span>
                            </div>
                            <div className="overflow-x-auto">
                            <table className="w-full min-w-[520px]">
                                <thead>
                                    <tr className="border-b border-surface-100 bg-surface-50/50">
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Description
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Category
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Qty
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Unit Price
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Tax
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-50">
                                    {expense.lineItems.map((item) => (
                                        <tr
                                            key={item.id}
                                            className="hover:bg-surface-50/50 transition-colors"
                                        >
                                            <td className="px-4 py-3 text-sm text-surface-900">
                                                {item.description}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-surface-600">
                                                {(item as any).category?.name ??
                                                    "-"}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-sm">
                                                {Number(
                                                    item.quantity,
                                                ).toLocaleString()}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-sm">
                                                {fmtKes(item.unit_price)}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums text-sm text-surface-500">
                                                {item.tax_amount > 0
                                                    ? fmtKes(item.tax_amount)
                                                    : "-"}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums font-semibold">
                                                {fmtKes(item.amount)}
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="bg-surface-50 border-t border-surface-200">
                                        <td
                                            colSpan={5}
                                            className="px-4 py-3 text-sm font-semibold text-surface-900"
                                        >
                                            Total
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums font-bold text-surface-900">
                                            {fmtKes(
                                                expense.lineItems.reduce(
                                                    (s, i) =>
                                                        s + Number(i.amount),
                                                    0,
                                                ),
                                            )}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    )}

                    {/* Approval history */}
                    {expense.approvals && expense.approvals.length > 0 && (
                        <SectionCard title="Approval History">
                            <ApprovalTimeline approvals={expense.approvals} />
                        </SectionCard>
                    )}
                </div>

                {/* ── Right: sidebar ── */}
                <div className="space-y-4">
                    {/* Receipt */}
                    <SectionCard title="Receipt">
                        {expense.receipt_path ? (
                            <div className="space-y-2">
                                <button
                                    className="btn-secondary w-full text-sm justify-center flex items-center gap-2"
                                    onClick={openReceiptPreview}
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
                                            d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"
                                        />
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                        />
                                    </svg>
                                    View Receipt
                                </button>
                                {canCreate && (
                                <button
                                    className="btn-ghost w-full text-sm"
                                    onClick={() => fileRef.current?.click()}
                                >
                                    Replace Receipt
                                </button>
                                )}
                            </div>
                        ) : canCreate ? (
                            <div>
                                <button
                                    className="btn-ghost w-full text-sm justify-center flex items-center gap-2"
                                    onClick={() => fileRef.current?.click()}
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
                                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"
                                        />
                                    </svg>
                                    Upload Receipt
                                </button>
                                <p className="text-xs text-surface-400 text-center mt-2">
                                    JPG, PNG or PDF · Max 10MB
                                </p>
                            </div>
                        ) : (
                            <p className="text-xs text-surface-400 text-center py-2">
                                No receipt uploaded.
                            </p>
                        )}
                        <input
                            ref={fileRef}
                            type="file"
                            accept=".jpg,.jpeg,.png,.pdf"
                            className="hidden"
                            onChange={handleFileUpload}
                        />
                    </SectionCard>

                    {/* Workflow timeline */}
                    <SectionCard title="Workflow">
                        <div className="space-y-3">
                            {[
                                {
                                    label: "Created by",
                                    user: expense.created_by,
                                    date: expense.created_at,
                                },
                                expense.submitted_by
                                    ? {
                                          label: "Submitted by",
                                          user: expense.submitted_by,
                                          date: expense.submitted_at,
                                      }
                                    : null,
                                expense.approved_by
                                    ? {
                                          label: "Approved by",
                                          user: expense.approved_by,
                                          date: expense.approved_at,
                                      }
                                    : null,
                                expense.rejected_by
                                    ? {
                                          label: "Rejected by",
                                          user: expense.rejected_by,
                                          date: (expense as any).rejected_at,
                                      }
                                    : null,
                                (expense as any).paidBy
                                    ? {
                                          label: "Paid by",
                                          user: (expense as any).paidBy,
                                          date: (expense as any).paid_at,
                                      }
                                    : null,
                            ]
                                .filter(Boolean)
                                .map((item: any, i) => (
                                    <div
                                        key={i}
                                        className="flex justify-between items-start text-sm"
                                    >
                                        <span className="text-surface-500 shrink-0">
                                            {item.label}
                                        </span>
                                        <div className="text-right">
                                            <p className="font-medium text-surface-900">
                                                {item.user
                                                    ? `${item.user.first_name ?? ""} ${item.user.last_name ?? ""}`.trim() ||
                                                      "-"
                                                    : "-"}
                                            </p>
                                            {item.date && (
                                                <p className="text-xs text-surface-400">
                                                    {dayjs(item.date).format(
                                                        "DD MMM YYYY",
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                        </div>
                    </SectionCard>

                    {/* Meta */}
                    <SectionCard title="Record Info">
                        <div className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <span className="text-surface-500">
                                    Reference
                                </span>
                                <span className="font-mono text-xs bg-surface-100 px-1.5 py-0.5 rounded text-surface-700">
                                    {expense.reference_number}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-surface-500">
                                    Created
                                </span>
                                <span className="text-surface-700">
                                    {dayjs(expense.created_at).format(
                                        "DD MMM YYYY HH:mm",
                                    )}
                                </span>
                            </div>
                            {expense.line_items_count > 0 && (
                                <div className="flex justify-between">
                                    <span className="text-surface-500">
                                        Line Items
                                    </span>
                                    <span className="text-surface-700">
                                        {expense.line_items_count}
                                    </span>
                                </div>
                            )}
                        </div>
                    </SectionCard>
                </div>
            </div>

            {/* ── Modals ── */}
            {editOpen && (
                <EditExpenseModal
                    expense={expense}
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    onSaved={() => setEditOpen(false)}
                />
            )}

            {markPaidOpen && (
                <MarkPaidModal
                    expense={expense}
                    open={markPaidOpen}
                    onClose={() => setMarkPaidOpen(false)}
                    onSaved={() => setMarkPaidOpen(false)}
                />
            )}

            {/* ── Receipt preview modal ── */}
            {receiptPreviewOpen && (
                <Modal
                    open={receiptPreviewOpen}
                    onClose={closeReceiptPreview}
                    title={`Receipt · ${expense.reference_number}`}
                    size="lg"
                >
                    <div className="flex flex-col gap-4">
                        {receiptLoading || !receiptBlob ? (
                            <div
                                className="flex items-center justify-center bg-surface-50 rounded-lg border border-surface-200"
                                style={{ height: "60vh" }}
                            >
                                <Spinner />
                            </div>
                        ) : receiptBlob.mimeType === "application/pdf" ? (
                            <iframe
                                src={receiptBlob.url}
                                className="w-full rounded-lg border border-surface-200 bg-surface-50"
                                style={{ height: "70vh" }}
                                title="Receipt PDF"
                            />
                        ) : (
                            <div
                                className="flex items-center justify-center bg-surface-50 rounded-lg border border-surface-200 p-2"
                                style={{ minHeight: "60vh" }}
                            >
                                <img
                                    src={receiptBlob.url}
                                    alt="Receipt"
                                    className="max-w-full max-h-[65vh] object-contain rounded"
                                />
                            </div>
                        )}
                        {receiptBlob && (
                            <div className="flex justify-between items-center pt-2 border-t border-surface-100">
                                <p className="text-xs text-surface-400">
                                    {receiptBlob.mimeType === "application/pdf"
                                        ? "PDF document"
                                        : "Image"}{" "}
                                    · {expense.reference_number}
                                </p>
                                <a
                                    href={receiptBlob.url}
                                    download={`receipt-${expense.reference_number}`}
                                    className="btn-ghost btn-sm flex items-center gap-1.5 text-sm"
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
                                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
                                        />
                                    </svg>
                                    Download
                                </a>
                            </div>
                        )}
                    </div>
                </Modal>
            )}

            <Modal
                open={rejectOpen}
                onClose={() => setRejectOpen(false)}
                title="Reject Expense"
                size="sm"
            >
                <p className="text-sm text-surface-600 mb-3">
                    Provide a clear reason - this will be sent to the submitter.
                </p>
                <textarea
                    className="input w-full resize-none"
                    rows={4}
                    value={rejectReason}
                    onChange={(e) => setRejectReason(e.target.value)}
                    placeholder="e.g. Missing receipt, exceeds approved budget…"
                />
                <div className="flex justify-end gap-3 mt-4">
                    <button
                        className="btn-ghost"
                        onClick={() => setRejectOpen(false)}
                    >
                        Cancel
                    </button>
                    <button
                        className="btn-danger"
                        disabled={!rejectReason.trim() || busy}
                        onClick={() => {
                            setRejectOpen(false);
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
                        Confirm Rejection
                    </button>
                </div>
            </Modal>
        </div>
    );
}