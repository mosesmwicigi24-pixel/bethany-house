// src/pages/expenses/ExpenseCategoriesPage.tsx
// Manage expense categories and period budgets in one settings-style page.
import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { expensesApi, fmtKes } from "@/api/expenses";
import type { ExpenseCategory, ExpenseBudget } from "@/api/expenses";
import { useToastStore } from "@/store/toast.store";
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

// ─── Category Form Modal ──────────────────────────────────────────────────────

function CategoryModal({
    editing,
    open,
    onClose,
}: {
    editing: ExpenseCategory | null;
    open: boolean;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const qc = useQueryClient();

    const blank = {
        name: "",
        code: "",
        description: "",
        color: "#6366f1",
        requires_approval_above: "",
        budget_monthly: "",
        budget_annual: "",
        is_tax_deductible: false,
        gl_code: "",
    };

    const [form, setForm] = useState(() =>
        editing
            ? {
                  name: editing.name,
                  code: editing.code,
                  description: editing.description ?? "",
                  color: editing.color ?? "#6366f1",
                  requires_approval_above:
                      editing.requires_approval_above != null
                          ? String(editing.requires_approval_above)
                          : "",
                  budget_monthly:
                      editing.budget_monthly != null
                          ? String(editing.budget_monthly)
                          : "",
                  budget_annual:
                      editing.budget_annual != null
                          ? String(editing.budget_annual)
                          : "",
                  is_tax_deductible: editing.is_tax_deductible,
                  gl_code: editing.gl_code ?? "",
              }
            : blank,
    );

    const set = (k: string, v: any) => setForm((f) => ({ ...f, [k]: v }));

    const mutation = useMutation({
        mutationFn: (data: any) =>
            editing
                ? expensesApi.updateCategory(editing.id, data)
                : expensesApi.createCategory(data),
        onSuccess: () => {
            toast.success(editing ? "Category updated." : "Category created.");
            qc.invalidateQueries({ queryKey: ["expense-categories"] });
            onClose();
        },
        onError: (err: any) =>
            toast.error(err?.response?.data?.message ?? "Save failed."),
    });

    const handleSave = () => {
        if (!form.name || !form.code) {
            toast.error("Name and code are required.");
            return;
        }
        mutation.mutate({
            ...form,
            requires_approval_above: form.requires_approval_above
                ? Number(form.requires_approval_above)
                : null,
            budget_monthly: form.budget_monthly
                ? Number(form.budget_monthly)
                : null,
            budget_annual: form.budget_annual
                ? Number(form.budget_annual)
                : null,
        });
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={editing ? "Edit Category" : "New Category"}
            size="md"
        >
            <div className="space-y-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Name *">
                        <FieldInput
                            id="cat-name"
                            className="input"
                            value={form.name}
                            onChange={(e) => set("name", e.target.value)}
                            placeholder="e.g. Office Supplies"
                        />
                    </Field>
                    <Field label="Code *" hint="Short uppercase identifier">
                        <FieldInput
                            id="cat-code"
                            className="input uppercase"
                            value={form.code}
                            onChange={(e) =>
                                set("code", e.target.value.toUpperCase())
                            }
                            placeholder="e.g. OFFC"
                        />
                    </Field>
                </div>

                <Field label="Description">
                    <FieldTextarea
                        id="cat-desc"
                        className="input resize-none"
                        rows={2}
                        value={form.description}
                        onChange={(e) => set("description", e.target.value)}
                    />
                </Field>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Colour" hint="Used in charts and badges">
                        <div className="flex items-center gap-2">
                            <FieldInput
                                type="color"
                                id="cat-color"
                                className="w-10 h-9 rounded-lg border border-surface-200 cursor-pointer p-0.5"
                                value={form.color}
                                onChange={(e) => set("color", e.target.value)}
                            />
                            <FieldInput
                                className="input flex-1 font-mono text-sm"
                                value={form.color}
                                onChange={(e) => set("color", e.target.value)}
                                placeholder="#6366f1"
                            />
                        </div>
                    </Field>
                    <Field label="GL Code" hint="General Ledger code">
                        <FieldInput
                            id="cat-gl"
                            className="input font-mono"
                            value={form.gl_code}
                            onChange={(e) => set("gl_code", e.target.value)}
                            placeholder="e.g. 6100"
                        />
                    </Field>
                </div>

                <div className="grid grid-cols-3 gap-4">
                    <Field
                        label="Auto-approve below (KES)"
                        hint="Require approval above this"
                    >
                        <FieldInput
                            id="cat-thresh"
                            type="number"
                            min="0"
                            step="100"
                            className="input"
                            value={form.requires_approval_above}
                            onChange={(e) =>
                                set("requires_approval_above", e.target.value)
                            }
                            placeholder="e.g. 50000"
                        />
                    </Field>
                    <Field label="Monthly Budget (KES)">
                        <FieldInput
                            id="cat-bm"
                            type="number"
                            min="0"
                            step="1000"
                            className="input"
                            value={form.budget_monthly}
                            onChange={(e) =>
                                set("budget_monthly", e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Annual Budget (KES)">
                        <FieldInput
                            id="cat-ba"
                            type="number"
                            min="0"
                            step="1000"
                            className="input"
                            value={form.budget_annual}
                            onChange={(e) =>
                                set("budget_annual", e.target.value)
                            }
                        />
                    </Field>
                </div>

                <label className="flex items-center gap-2 cursor-pointer select-none">
                    <input
                        type="checkbox"
                        className="rounded accent-brand-500 w-4 h-4"
                        checked={form.is_tax_deductible}
                        onChange={(e) =>
                            set("is_tax_deductible", e.target.checked)
                        }
                    />
                    <span className="text-sm text-surface-700">
                        Tax deductible expenses
                    </span>
                </label>
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
                    ) : editing ? (
                        "Save Changes"
                    ) : (
                        "Create Category"
                    )}
                </button>
            </div>
        </Modal>
    );
}

// ─── Budget Form Modal ────────────────────────────────────────────────────────

function BudgetModal({
    editing,
    categories,
    open,
    onClose,
}: {
    editing: ExpenseBudget | null;
    open: boolean;
    onClose: () => void;
    categories: ExpenseCategory[];
}) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const now = dayjs();

    const [categoryId, setCategoryId] = useState(
        editing?.category_id ? String(editing.category_id) : "",
    );
    const [periodType, setPeriodType] = useState<
        "monthly" | "quarterly" | "annual"
    >(editing?.period_type ?? "monthly");
    const [periodYear, setPeriodYear] = useState(
        editing?.period_year ?? now.year(),
    );
    const [periodNumber, setPeriodNumber] = useState(
        editing?.period_number ?? now.month() + 1,
    );
    const [budgetedAmount, setBudgetedAmount] = useState<number | "">(
        editing ? Number(editing.budgeted_amount) : "",
    );
    const [notes, setNotes] = useState((editing as any)?.notes ?? "");

    const mutation = useMutation({
        mutationFn: (data: any) =>
            editing
                ? expensesApi.updateBudget(editing.id, data)
                : expensesApi.createBudget(data),
        onSuccess: () => {
            toast.success(editing ? "Budget updated." : "Budget created.");
            qc.invalidateQueries({ queryKey: ["expense-budgets"] });
            onClose();
        },
        onError: (err: any) =>
            toast.error(err?.response?.data?.message ?? "Save failed."),
    });

    const handleSave = () => {
        if (!categoryId || !budgetedAmount) {
            toast.error("Category and amount are required.");
            return;
        }
        mutation.mutate({
            category_id: Number(categoryId),
            period_type: periodType,
            period_year: periodYear,
            period_number: periodNumber,
            budgeted_amount: Number(budgetedAmount),
            currency_code: "KES",
            notes: notes || undefined,
        });
    };

    const periodOptions =
        periodType === "monthly"
            ? Array.from({ length: 12 }, (_, i) => ({
                  value: i + 1,
                  label: dayjs().month(i).format("MMMM"),
              }))
            : periodType === "quarterly"
              ? [
                    { value: 1, label: "Q1 (Jan–Mar)" },
                    { value: 2, label: "Q2 (Apr–Jun)" },
                    { value: 3, label: "Q3 (Jul–Sep)" },
                    { value: 4, label: "Q4 (Oct–Dec)" },
                ]
              : [{ value: 1, label: "Full Year" }];

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={editing ? "Edit Budget" : "New Budget Allocation"}
            size="md"
        >
            <div className="space-y-4">
                <Field label="Category *">
                    <FieldSelect
                        id="bgt-cat"
                        className="input"
                        value={categoryId}
                        onChange={(e) => setCategoryId(e.target.value)}
                        disabled={!!editing}
                    >
                        <option value="">Select category…</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name} ({c.code})
                            </option>
                        ))}
                    </FieldSelect>
                </Field>

                <div className="grid grid-cols-3 gap-4">
                    <Field label="Period Type">
                        <FieldSelect
                            id="bgt-ptype"
                            className="input"
                            value={periodType}
                            onChange={(e) => {
                                setPeriodType(e.target.value as any);
                                setPeriodNumber(1);
                            }}
                            disabled={!!editing}
                        >
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="annual">Annual</option>
                        </FieldSelect>
                    </Field>
                    <Field label="Year">
                        <FieldSelect
                            id="bgt-yr"
                            className="input"
                            value={periodYear}
                            onChange={(e) =>
                                setPeriodYear(Number(e.target.value))
                            }
                            disabled={!!editing}
                        >
                            {[now.year() - 1, now.year(), now.year() + 1].map(
                                (y) => (
                                    <option key={y} value={y}>
                                        {y}
                                    </option>
                                ),
                            )}
                        </FieldSelect>
                    </Field>
                    <Field label="Period">
                        <FieldSelect
                            id="bgt-pnum"
                            className="input"
                            value={periodNumber}
                            onChange={(e) =>
                                setPeriodNumber(Number(e.target.value))
                            }
                            disabled={!!editing}
                        >
                            {periodOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </FieldSelect>
                    </Field>
                </div>

                <Field label="Budgeted Amount (KES) *">
                    <FieldInput
                        id="bgt-amt"
                        type="number"
                        min="0"
                        step="1000"
                        className="input"
                        value={budgetedAmount}
                        onChange={(e) =>
                            setBudgetedAmount(
                                e.target.value === ""
                                    ? ""
                                    : Number(e.target.value),
                            )
                        }
                        placeholder="e.g. 100000"
                    />
                </Field>

                <Field label="Notes">
                    <FieldTextarea
                        id="bgt-notes"
                        className="input resize-none"
                        rows={2}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Optional context about this budget allocation…"
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
                    ) : editing ? (
                        "Save Changes"
                    ) : (
                        "Create Budget"
                    )}
                </button>
            </div>
        </Modal>
    );
}

// ─── Budget utilization bar ───────────────────────────────────────────────────

function UtilizationBar({ pct }: { pct: number }) {
    const capped = Math.min(pct, 100);
    const color =
        pct >= 100 ? "bg-danger" : pct >= 80 ? "bg-warning" : "bg-success";
    return (
        <div className="flex items-center gap-2">
            <div className="flex-1 h-1.5 bg-surface-100 rounded-full overflow-hidden">
                <div
                    className={clsx(
                        "h-full rounded-full transition-all",
                        color,
                    )}
                    style={{ width: `${capped}%` }}
                />
            </div>
            <span
                className={clsx(
                    "text-xs font-medium tabular-nums w-10 text-right",
                    pct >= 100
                        ? "text-danger"
                        : pct >= 80
                          ? "text-warning"
                          : "text-success",
                )}
            >
                {pct.toFixed(0)}%
            </span>
        </div>
    );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

type PageTab = "categories" | "budgets";

export default function ExpenseCategoriesPage() {
    const { can } = usePermissions();
    const toast = useToastStore();
    const qc = useQueryClient();
    const now = dayjs();

    const [tab, setTab] = useState<PageTab>("categories");
    const [catModal, setCatModal] = useState(false);
    const [editingCat, setEditingCat] = useState<ExpenseCategory | null>(null);
    const [budgetModal, setBudgetModal] = useState(false);
    const [editingBgt, setEditingBgt] = useState<ExpenseBudget | null>(null);
    const [budgetYear, setBudgetYear] = useState(now.year());
    const [budgetType, setBudgetType] = useState<
        "monthly" | "quarterly" | "annual"
    >("monthly");

    const { data: catData, isLoading: catLoading } = useQuery({
        queryKey: ["expense-categories"],
        queryFn: () => expensesApi.categories(),
    });
    const categories = catData?.categories ?? [];

    const { data: bgtData, isLoading: bgtLoading } = useQuery({
        queryKey: ["expense-budgets", budgetYear, budgetType],
        queryFn: () =>
            expensesApi.budgets({
                period_year: budgetYear,
                period_type: budgetType,
            } as any),
        enabled: tab === "budgets",
    });
    const budgets = bgtData?.budgets ?? [];

    const openCreateCat = () => {
        setEditingCat(null);
        setCatModal(true);
    };
    const openEditCat = (c: ExpenseCategory) => {
        setEditingCat(c);
        setCatModal(true);
    };
    const openCreateBgt = () => {
        setEditingBgt(null);
        setBudgetModal(true);
    };
    const openEditBgt = (b: ExpenseBudget) => {
        setEditingBgt(b);
        setBudgetModal(true);
    };

    return (
        <div className="space-y-5 animate-fade-in">
            {/* Header */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Expense Settings</h1>
                    <p className="page-subtitle">
                        Manage categories, approval thresholds, and budget
                        allocations.
                    </p>
                </div>
                <div className="shrink-0">
                    {tab === "categories" && can("expenses.budgets") && (
                        <button className="btn-primary" onClick={openCreateCat}>
                            + New Category
                        </button>
                    )}
                    {tab === "budgets" && can("expenses.budgets") && (
                        <button className="btn-primary" onClick={openCreateBgt}>
                            + New Budget
                        </button>
                    )}
                </div>
            </div>

            {/* Tab nav */}
            <div className="border-b border-surface-200">
                <div className="flex gap-0">
                    {(
                        [
                            { id: "categories", label: "Categories" },
                            { id: "budgets", label: "Budgets" },
                        ] as { id: PageTab; label: string }[]
                    ).map((t) => (
                        <button
                            key={t.id}
                            onClick={() => setTab(t.id)}
                            className={clsx(
                                "px-5 py-3 text-sm font-medium border-b-2 -mb-px transition-colors",
                                tab === t.id
                                    ? "border-brand-500 text-brand-600"
                                    : "border-transparent text-surface-500 hover:text-surface-700",
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* ── Categories tab ── */}
            {tab === "categories" && (
                <div className="card overflow-hidden">
                    <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px]">
                        <thead>
                            <tr className="border-b border-surface-100 bg-surface-50/50">
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Category
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Code
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Approval Above
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Monthly Budget
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    This Month
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    Utilization
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                    GL Code
                                </th>
                                <th className="px-4 py-3 w-12" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {catLoading ? (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-4 py-12 text-center"
                                    >
                                        <Spinner />
                                    </td>
                                </tr>
                            ) : categories.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-4 py-16 text-center text-sm text-surface-400"
                                    >
                                        No categories yet.
                                        {can("expenses.budgets") && (
                                            <button
                                                className="ml-2 text-brand-500 hover:underline"
                                                onClick={openCreateCat}
                                            >
                                                Create one
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ) : (
                                categories.map((cat) => (
                                    <tr
                                        key={cat.id}
                                        className="hover:bg-surface-50/50 transition-colors"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2.5">
                                                {cat.color && (
                                                    <span
                                                        className="w-3 h-3 rounded-full shrink-0"
                                                        style={{
                                                            backgroundColor:
                                                                cat.color,
                                                        }}
                                                    />
                                                )}
                                                <div>
                                                    <p className="font-medium text-surface-900 text-sm">
                                                        {cat.name}
                                                    </p>
                                                    {cat.description && (
                                                        <p className="text-xs text-surface-400 truncate max-w-[200px]">
                                                            {cat.description}
                                                        </p>
                                                    )}
                                                    {cat.is_tax_deductible && (
                                                        <span className="text-xs text-info bg-info-light px-1.5 py-0.5 rounded">
                                                            Tax deductible
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-mono text-xs bg-surface-100 text-surface-600 px-1.5 py-0.5 rounded">
                                                {cat.code}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm tabular-nums">
                                            {cat.requires_approval_above !=
                                            null ? (
                                                fmtKes(
                                                    cat.requires_approval_above,
                                                )
                                            ) : (
                                                <span className="text-surface-300">
                                                    -
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm tabular-nums">
                                            {cat.budget_monthly != null ? (
                                                fmtKes(cat.budget_monthly)
                                            ) : (
                                                <span className="text-surface-300">
                                                    -
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm tabular-nums font-medium">
                                            {fmtKes(
                                                (cat as any)
                                                    .current_month_spend ?? 0,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 min-w-[120px]">
                                            {(cat as any)
                                                .budget_utilization_percent !=
                                            null ? (
                                                <UtilizationBar
                                                    pct={
                                                        (cat as any)
                                                            .budget_utilization_percent
                                                    }
                                                />
                                            ) : (
                                                <span className="text-xs text-surface-300">
                                                    No budget set
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm font-mono text-surface-500">
                                            {cat.gl_code ?? (
                                                <span className="text-surface-300">
                                                    -
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {can("expenses.budgets") && (
                                                <button
                                                    className="w-8 h-8 flex items-center justify-center rounded-lg text-surface-400 hover:bg-surface-100 hover:text-surface-700 transition-colors"
                                                    aria-label="Edit"
                                                    onClick={() =>
                                                        openEditCat(cat)
                                                    }
                                                    title="Edit category"
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
                                                            d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"
                                                        />
                                                    </svg>
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    </div>
                </div>
            )}

            {/* ── Budgets tab ── */}
            {tab === "budgets" && (
                <div className="space-y-4">
                    {/* Filters */}
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            className="input flex-1 sm:w-36 sm:flex-none"
                            value={budgetYear}
                            onChange={(e) =>
                                setBudgetYear(Number(e.target.value))
                            }
                        >
                            {[now.year() - 1, now.year(), now.year() + 1].map(
                                (y) => (
                                    <option key={y} value={y}>
                                        {y}
                                    </option>
                                ),
                            )}
                        </select>
                        <select
                            className="input flex-1 sm:w-36 sm:flex-none"
                            value={budgetType}
                            onChange={(e) =>
                                setBudgetType(e.target.value as any)
                            }
                        >
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="annual">Annual</option>
                        </select>
                    </div>

                    <div className="card overflow-hidden">
                        <div className="overflow-x-auto">
                        <table className="w-full min-w-[560px]">
                            <thead>
                                <tr className="border-b border-surface-100 bg-surface-50/50">
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Category
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Period
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Budget
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Actual
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase tracking-wider">
                                        Variance
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider min-w-[140px]">
                                        Utilization
                                    </th>
                                    <th className="px-4 py-3 w-12" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
                                {bgtLoading ? (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-12 text-center"
                                        >
                                            <Spinner />
                                        </td>
                                    </tr>
                                ) : budgets.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-16 text-center text-sm text-surface-400"
                                        >
                                            No budgets set for this period.
                                            {can("expenses.budgets") && (
                                                <button
                                                    className="ml-2 text-brand-500 hover:underline"
                                                    onClick={openCreateBgt}
                                                >
                                                    Add one
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ) : (
                                    budgets.map((b: any) => {
                                        const variance = Number(b.variance);
                                        const utilization = Number(
                                            b.utilization_percent,
                                        );
                                        const periodLabel =
                                            b.period_type === "monthly"
                                                ? dayjs()
                                                      .month(
                                                          b.period_number - 1,
                                                      )
                                                      .format("MMMM")
                                                : b.period_type === "quarterly"
                                                  ? `Q${b.period_number}`
                                                  : "Full Year";
                                        return (
                                            <tr
                                                key={b.id}
                                                className="hover:bg-surface-50/50 transition-colors"
                                            >
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-2">
                                                        {b.category?.color && (
                                                            <span
                                                                className="w-2.5 h-2.5 rounded-full shrink-0"
                                                                style={{
                                                                    backgroundColor:
                                                                        b
                                                                            .category
                                                                            .color,
                                                                }}
                                                            />
                                                        )}
                                                        <p className="font-medium text-surface-900 text-sm">
                                                            {b.category?.name ??
                                                                "-"}
                                                        </p>
                                                    </div>
                                                    {b.outlet && (
                                                        <p className="text-xs text-surface-400 mt-0.5 pl-5">
                                                            {b.outlet.name}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-sm text-surface-600">
                                                    {periodLabel}{" "}
                                                    {b.period_year}
                                                </td>
                                                <td className="px-4 py-3 text-right font-semibold tabular-nums">
                                                    {fmtKes(b.budgeted_amount)}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {fmtKes(b.actual_spend)}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    <span
                                                        className={clsx(
                                                            "font-medium",
                                                            variance >= 0
                                                                ? "text-success"
                                                                : "text-danger",
                                                        )}
                                                    >
                                                        {variance >= 0
                                                            ? "+"
                                                            : ""}
                                                        {fmtKes(variance)}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <UtilizationBar
                                                        pct={utilization}
                                                    />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {can(
                                                        "expenses.budgets",
                                                    ) && (
                                                        <button
                                                            className="w-8 h-8 flex items-center justify-center rounded-lg text-surface-400 hover:bg-surface-100 hover:text-surface-700 transition-colors"
                                                            aria-label="Edit"
                                                            onClick={() =>
                                                                openEditBgt(b)
                                                            }
                                                            title="Edit budget"
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
                                                                    d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"
                                                                />
                                                            </svg>
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            )}

            {/* Modals */}
            {catModal && (
                <CategoryModal
                    editing={editingCat}
                    open={catModal}
                    onClose={() => setCatModal(false)}
                />
            )}
            {budgetModal && (
                <BudgetModal
                    editing={editingBgt}
                    categories={categories}
                    open={budgetModal}
                    onClose={() => setBudgetModal(false)}
                />
            )}
        </div>
    );
}