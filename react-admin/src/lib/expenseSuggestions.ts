import type { ExpenseSuggestionsData, RecentExpense, UsualExpense } from "@/api/expenses";

/**
 * The console's half of the New Expense suggestions (owner, 2026-10-16).
 * The server learns the patterns (App\Services\Expenses\ExpenseSuggestions);
 * this matches what is being typed against them and raises the two warnings.
 * Nothing here decides anything: every suggestion is grey text the person
 * accepts or types over, and every warning can be read and passed.
 */

const MONTHS = [
    "january", "february", "march", "april", "may", "june", "july", "august", "september", "october", "november", "december",
    "jan", "feb", "mar", "apr", "jun", "jul", "aug", "sept", "sep", "oct", "nov", "dec",
];
const MONTH_RE = new RegExp(`\\b(${MONTHS.join("|")})\\b`, "gu");

/** Mirrors ExpenseSuggestions::normalise — a title without its dates. */
export function normaliseTitle(title: string | null | undefined): string {
    return (title ?? "")
        .trim()
        .toLowerCase()
        .replace(MONTH_RE, " ")
        .replace(/[0-9]+/g, " ")
        .replace(/[^\p{L}\s&]+/gu, " ")
        .replace(/\s+/g, " ")
        .trim();
}

export function usualFor(title: string, data: ExpenseSuggestionsData | undefined): UsualExpense | null {
    const norm = normaliseTitle(title);
    if (!norm || !data) return null;
    return data.usuals.find((u) => u.norm === norm) ?? null;
}

export interface FormSnapshot {
    title: string;
    amount: number | "";
    currency: string;
    expenseDate: string;
    vendorName: string;
    paymentRef: string;
}

export interface DuplicateHint {
    expense: RecentExpense;
    why: "reference" | "same_spend";
}

const days = (a: string, b: string) => Math.abs((new Date(a).getTime() - new Date(b).getTime()) / 86_400_000);

/** "Looks like one already recorded": the same payment code, or the same spend within 3 days. */
export function findDuplicate(form: FormSnapshot, data: ExpenseSuggestionsData | undefined): DuplicateHint | null {
    if (!data) return null;
    const ref = form.paymentRef.trim().toLowerCase();
    if (ref.length >= 6) {
        const hit = data.recent.find((r) => (r.payment_reference ?? "").trim().toLowerCase() === ref);
        if (hit) return { expense: hit, why: "reference" };
    }
    if (form.amount === "" || !form.expenseDate) return null;
    const vendor = form.vendorName.trim().toLowerCase();
    const norm = normaliseTitle(form.title);
    const hit = data.recent.find(
        (r) =>
            r.currency_code === form.currency &&
            Math.abs(r.amount - Number(form.amount)) < 0.005 &&
            days(r.expense_date, form.expenseDate) <= 3 &&
            ((vendor !== "" && (r.vendor_name ?? "").trim().toLowerCase() === vendor) || (norm !== "" && r.norm === norm)),
    );
    return hit ? { expense: hit, why: "same_spend" } : null;
}

/** Far outside what this expense usually costs: over 1.5× or under half its median (3+ times seen). */
export function unusualAmount(usual: UsualExpense | null, amount: number | "", currency: string): string | null {
    if (!usual || amount === "" || usual.count < 3 || !usual.median_amount || usual.currency_code !== currency) return null;
    const a = Number(amount);
    if (a <= usual.median_amount * 1.5 && a >= usual.median_amount * 0.5) return null;
    const fmt = (n: number | null) => (n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
    return `Usually ${usual.currency_code} ${fmt(usual.low)}–${fmt(usual.high)} for this. Check the amount before saving.`;
}

export function dueLabel(u: UsualExpense): string | null {
    if (u.due_state === "due") return "due";
    if (u.due_state === "soon") return "due soon";
    if (u.due_state === "done") return "done this period";
    return null;
}
