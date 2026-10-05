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
import type { ReactNode } from "react";
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

// ── Whether the floor can work on an order ───────────────────────────────────

/**
 * Mirrors ProductionOrder::FLOOR_WORK_STATUSES on the server, which refuses
 * every floor action outside these. Screens hide the actions to match, so a
 * closed order never offers a button that can only fail.
 */
export const FLOOR_WORK_STATUSES = ["pending", "in_progress", "on_hold"] as const;
export const acceptsFloorWork = (status?: string | null) =>
    !!status && (FLOOR_WORK_STATUSES as readonly string[]).includes(status);

// ── Stage actions: one set of buttons wherever a stage is worked ─────────────

export type StageAction = "start" | "complete" | "pause";

const ICON: Record<StageAction | "resume", ReactNode> = {
    start:    <path strokeLinecap="round" strokeLinejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />,
    resume:   <path strokeLinecap="round" strokeLinejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />,
    complete: <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />,
    pause:    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />,
};

/**
 * Start / Resume · Mark done · Pause for one stage — the same words, colours
 * and order on the order page and the order drawer (My Tasks keeps its larger
 * floor buttons, in the same colours). Renders nothing when the person may not
 * act: not their stage, a closed order, or a stage still waiting on another.
 */
export function StageActions({ status, canAct, blocked = false, pending = false, onAction }: {
    status: string;
    canAct: boolean;
    blocked?: boolean;
    pending?: boolean;
    onAction: (action: StageAction) => void;
}) {
    if (!canAct) return null;
    const buttons: { action: StageAction; label: string; icon: ReactNode; cls: string }[] = [];
    if ((status === "pending" || status === "paused") && !blocked) {
        buttons.push({ action: "start", label: status === "paused" ? "Resume" : "Start",
            icon: ICON[status === "paused" ? "resume" : "start"], cls: "bg-brand-500 text-white hover:bg-brand-600" });
    }
    if (status === "in_progress") {
        buttons.push({ action: "complete", label: "Mark done", icon: ICON.complete, cls: "bg-success-700 text-white hover:bg-success-dark" });
        buttons.push({ action: "pause", label: "Pause", icon: ICON.pause, cls: "bg-warning-light text-warning-dark border border-warning/40 hover:brightness-95" });
    }
    if (!buttons.length) return null;
    return (
        <div className="flex items-center gap-2 mt-2.5">
            {buttons.map(b => (
                <button key={b.action} type="button" onClick={() => onAction(b.action)} disabled={pending}
                    className={clsx("flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold transition-colors disabled:opacity-50", b.cls)}>
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>{b.icon}</svg>
                    {b.label}
                </button>
            ))}
        </div>
    );
}

// ── Where an order is ────────────────────────────────────────────────────────

/**
 * The stage line on a card: the server's current stage, else done / not
 * started. A cancelled order is at no stage, whatever its tasks say.
 */
export function stageLabel(currentStage?: string | null, percent?: number | null, status?: string | null): string {
    if (status === "cancelled") return "Cancelled";
    if (currentStage) return currentStage;
    return (percent ?? 0) >= 100 ? "All stages done" : "Not started";
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

/** Orders whose due date no longer drives work: nothing is late or "due" once closed. */
const CLOSED_STATUSES = new Set(["completed", "cancelled"]);

/**
 * The one wording for a due date: "Overdue 3d" · "Due today" · "Due in 2d".
 * Pass the order's status: a completed or cancelled order shows its plain date
 * ("11 Oct 2026") with no urgency, so a closed job never reads as overdue.
 */
export function dueInfo(date?: string | null, status?: string | null): { label: string; tone: DueTone; days: number } {
    const days = daysUntil(date);
    if (Number.isNaN(days)) return { label: "No due date", tone: "none", days };
    if (status && CLOSED_STATUSES.has(status)) return { label: fmtDueDate(date), tone: "none", days };
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

export function DueBadge({ date, status, className }: { date?: string | null; status?: string | null; className?: string }) {
    const d = dueInfo(date, status);
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
