/**
 * The Production design language — ONE place for how a production order or
 * stage is named, coloured, dated and measured on every surface (My Tasks,
 * Production Orders, WIP, Calendar, QC, Order Detail, Tailor Home).
 *
 * Production UI Cycle 1 (coherence). Before this, seven status maps gave
 * "in progress" four colours, eight due badges said "3d late", "3d ago",
 * "3d overdue" and "Overdue by 3d" for the same order, and four rules named
 * a job with no customer "Name missing", "For stock", "Stock" or "Customer
 * order".
 *
 * Colour rule (one meaning per tone):
 *   neutral = not started / closed · brand = being worked · warning = paused
 *   or on hold · accent = with QC · success = passed / done · danger = failed
 */
import { clsx } from "clsx";
import { businessDaysUntil, toBusinessDateInput } from "@/lib/businessDate";

// ── Status ───────────────────────────────────────────────────────────────────

export interface StatusStyle { label: string; bg: string; text: string; dot: string; strike?: boolean }

export const ORDER_STATUS: Record<string, StatusStyle> = {
    draft:       { label: "Draft",       bg: "bg-surface-50",     text: "text-surface-500",  dot: "bg-surface-300" },
    pending:     { label: "Pending",     bg: "bg-surface-100",    text: "text-surface-600",  dot: "bg-surface-400" },
    in_progress: { label: "In Progress", bg: "bg-brand-50",       text: "text-brand-700",    dot: "bg-brand-500" },
    on_hold:     { label: "On Hold",     bg: "bg-warning-light",  text: "text-warning-dark", dot: "bg-warning" },
    qc_pending:  { label: "Awaiting QC", bg: "bg-accent-50",      text: "text-accent-700",   dot: "bg-accent-500" },
    qc_passed:   { label: "QC Passed",   bg: "bg-success-light",  text: "text-success-dark", dot: "bg-success-vivid" },
    qc_failed:   { label: "QC Failed",   bg: "bg-danger-light",   text: "text-danger",       dot: "bg-danger" },
    completed:   { label: "Completed",   bg: "bg-success-light",  text: "text-success-dark", dot: "bg-success-vivid" },
    cancelled:   { label: "Cancelled",   bg: "bg-surface-100",    text: "text-surface-500",  dot: "bg-surface-300", strike: true },
};

export const TASK_STATUS: Record<string, StatusStyle> = {
    pending:     { label: "Not started", bg: "bg-surface-100",   text: "text-surface-600",  dot: "bg-surface-400" },
    in_progress: { label: "In progress", bg: "bg-brand-50",      text: "text-brand-700",    dot: "bg-brand-500" },
    paused:      { label: "Paused",      bg: "bg-warning-light", text: "text-warning-dark", dot: "bg-warning" },
    completed:   { label: "Done",        bg: "bg-success-light", text: "text-success-dark", dot: "bg-success-vivid" },
    skipped:     { label: "Skipped",     bg: "bg-surface-100",   text: "text-surface-500",  dot: "bg-surface-300" },
    failed:      { label: "Failed",      bg: "bg-danger-light",  text: "text-danger",       dot: "bg-danger" },
};

const FALLBACK: StatusStyle = { label: "", bg: "bg-surface-100", text: "text-surface-500", dot: "bg-surface-400" };

export function orderStatus(status?: string | null): StatusStyle {
    return ORDER_STATUS[status ?? ""] ?? { ...FALLBACK, label: (status ?? "").replace(/_/g, " ") };
}

export function taskStatus(status?: string | null): StatusStyle {
    return TASK_STATUS[status ?? ""] ?? { ...FALLBACK, label: (status ?? "").replace(/_/g, " ") };
}

export function StatusBadge({ status, kind = "order", className }: { status: string; kind?: "order" | "task"; className?: string }) {
    const c = kind === "task" ? taskStatus(status) : orderStatus(status);
    return (
        <span className={clsx("inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-2xs font-semibold whitespace-nowrap", c.bg, c.text, c.strike && "line-through", className)}>
            <span className={clsx("w-1.5 h-1.5 rounded-full shrink-0", c.dot)} />
            {c.label}
        </span>
    );
}

// ── Priority ─────────────────────────────────────────────────────────────────

export const PRIORITY: Record<string, { label: string; cls: string; dot: string; border: string }> = {
    low:    { label: "Low",    cls: "text-surface-500 bg-surface-50 border-surface-200",   dot: "bg-surface-300", border: "border-l-surface-300" },
    normal: { label: "Normal", cls: "text-surface-600 bg-surface-50 border-surface-200",   dot: "bg-surface-400", border: "border-l-surface-300" },
    high:   { label: "High",   cls: "text-warning-dark bg-warning-light border-warning/30", dot: "bg-warning",     border: "border-l-warning" },
    urgent: { label: "Urgent", cls: "text-danger bg-danger-light border-danger/30",        dot: "bg-danger",      border: "border-l-danger" },
};

export function priority(p?: string | null) {
    return PRIORITY[p ?? "normal"] ?? PRIORITY.normal;
}

/** Normal priority is the default and needs no badge; pass `showNormal` where a column must not be empty. */
export function PriorityBadge({ priority: p, showNormal = false }: { priority?: string | null; showNormal?: boolean }) {
    if (!showNormal && (p ?? "normal") === "normal") return null;
    const c = priority(p);
    return <span className={clsx("inline-flex text-2xs font-bold px-1.5 py-0.5 rounded border uppercase tracking-wide whitespace-nowrap", c.cls)}>{c.label}</span>;
}

// ── Due dates (business calendar, Africa/Nairobi) ────────────────────────────

/** Calendar days until the date on the business calendar (0 = today, negative = overdue). */
export const daysUntil = (date?: string | null) => businessDaysUntil(date);

const fmtDay = new Intl.DateTimeFormat("en-KE", { timeZone: "Africa/Nairobi", day: "numeric", month: "short", year: "numeric" });

/** "11 Oct 2026" for a date-only column, read on the business calendar. */
export function fmtDueDate(date?: string | null): string {
    const ymd = toBusinessDateInput(date);
    if (!ymd) return "—";
    const [y, m, d] = ymd.split("-").map(Number);
    return fmtDay.format(new Date(Date.UTC(y, m - 1, d, 9)));
}

export type DueTone = "overdue" | "today" | "soon" | "later" | "none";

/** The one wording for a due date: "Overdue 3d" · "Due today" · "Due in 2d". */
export function dueInfo(date?: string | null): { label: string; tone: DueTone; days: number } {
    const days = daysUntil(date);
    if (Number.isNaN(days)) return { label: "No due date", tone: "none", days };
    if (days < 0)   return { label: `Overdue ${Math.abs(days)}d`, tone: "overdue", days };
    if (days === 0) return { label: "Due today", tone: "today", days };
    return { label: `Due in ${days}d`, tone: days <= 2 ? "soon" : "later", days };
}

export const DUE_TONE_CLS: Record<DueTone, string> = {
    overdue: "text-danger",
    today:   "text-warning-dark",
    soon:    "text-warning-dark",
    later:   "text-surface-500",
    none:    "text-surface-400",
};

export function DueBadge({ date, className }: { date?: string | null; className?: string }) {
    const d = dueInfo(date);
    if (d.tone === "none") return null;
    return (
        <span title={fmtDueDate(date)} className={clsx("text-2xs font-semibold whitespace-nowrap", DUE_TONE_CLS[d.tone], className)}>
            {d.label}
        </span>
    );
}

// ── Progress ─────────────────────────────────────────────────────────────────

/**
 * The order's progress as the server sends it (backend OrderProgress): pieces
 * passed across the whole pipeline. Every surface shows this figure — never a
 * stage count or the viewer's own stages.
 */
export interface OrderProgressData { percent: number; finished: number; stages: number }

export function ProgressBar({ pct, done = false, className }: { pct: number; done?: boolean; className?: string }) {
    return (
        <div className={clsx("h-1.5 bg-surface-100 rounded-full overflow-hidden", className)}>
            <div className={clsx("h-full rounded-full transition-all duration-500", done || pct >= 100 ? "bg-success-vivid" : "bg-brand-500")}
                style={{ width: `${Math.min(100, Math.max(0, pct || 0))}%` }} />
        </div>
    );
}

// ── Job identity ─────────────────────────────────────────────────────────────

interface IdentitySource {
    customer_label?: string | null;
    is_customer_order?: boolean | null;
    customer_order_id?: number | null;
    customer_id?: number | null;
}

/** One rule for "is this made for a customer", used everywhere. */
export function isCustomerJob(o: IdentitySource): boolean {
    return o.is_customer_order ?? !!(o.customer_order_id || o.customer_id);
}

/**
 * Who the job is for: the customer's name; "Name missing" for a customer job
 * with no name on file (a gap worth seeing, not hiding); or "For stock".
 */
export function jobFor(o: IdentitySource): string {
    if (o.customer_label?.trim()) return o.customer_label.trim();
    return isCustomerJob(o) ? "Name missing" : "For stock";
}
