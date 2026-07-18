/**
 * ProductionCalendarPage.tsx
 * Route: /production/calendar
 *
 * Two audiences, one page:
 *
 *  • Production team (production.view) - full month/week calendar of all active
 *    orders, click-through to order detail, visual load heatmap per day.
 *
 *  • Sales staff (production.raise_order without production.view) - read-only
 *    "Workshop Availability" view showing busy/free days so they can promise
 *    customers realistic lead times. No order numbers or internal details exposed.
 *
 * Data source: GET /v1/admin/production/schedule  (already exists in backend)
 * + GET /v1/admin/production-orders?per_page=200&status=pending,in_progress,on_hold,qc_pending
 *   (filtered client-side into calendar cells)
 *
 * Permission gating:
 *   production.view        → full calendar
 *   production.raise_order → sales preview (simplified, no order details)
 *   neither                → hidden from nav (PermissionGate handles the page)
 */

import { useState, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get } from "@/api/client";
import { usePermissions } from "@/hooks/usePermissions";
import { useAuthStore } from "@/store/auth.store";
import { Spinner } from "@/components/ui/Spinner";

// ─── Types ────────────────────────────────────────────────────────────────────

interface ScheduleResponse {
    active_count: number;
    upcoming_orders: UpcomingOrder[];
    earliest_free_slot: string;         // ISO date string
    by_stage: Record<string, number>;   // stage_id → pending task count
}

interface UpcomingOrder {
    order_number: string;
    due_date: string;
    fitting_date?: string | null;
    collection_date?: string | null;
    estimated_completion_date?: string | null;
    status: string;
    priority: "low" | "normal" | "high" | "urgent";
}

interface ProductionOrder {
    id: number;
    order_number: string;
    product_name: string;
    status: string;
    priority: "low" | "normal" | "high" | "urgent";
    due_date: string;
    estimated_completion_date?: string | null;
    fitting_date?: string | null;
    collection_date?: string | null;
    completion_percentage: number;
    customer_order_id?: number | null;
}

type ViewMode = "month" | "week";

// ─── User filter types ────────────────────────────────────────────────────────

interface ProductionUser {
    id: number;
    first_name: string;
    last_name: string;
    email?: string;
}

// Task shape returned by /v1/admin/production-tasks (for user-filtered view)
// and /v1/tailor/tasks (for the worker's own view)
interface CalendarTask {
    id: number;
    status: string;
    production_order: {
        id: number;
        order_number: string;
        priority: "low" | "normal" | "high" | "urgent";
        due_date: string;
        status: string;
        quantity: number;
        product?: { translations?: { name: string }[] };
    };
    stage: { id: number; name: string };
    assigned_to?: number;
}

// Adapts a CalendarTask into the ProductionOrder shape the calendar already understands
function taskToOrder(t: CalendarTask): ProductionOrder {
    const pName = t.production_order.product?.translations?.[0]?.name
               ?? `Order ${t.production_order.order_number}`;
    return {
        id:                    t.production_order.id,
        order_number:          t.production_order.order_number,
        product_name:          pName,
        status:                t.production_order.status,
        priority:              t.production_order.priority,
        due_date:              t.production_order.due_date,
        completion_percentage: 0,
    };
}

// ─── Constants ────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
    pending:     "bg-surface-200 text-surface-600",
    in_progress: "bg-brand-500/20 text-brand-700",
    on_hold:     "bg-warning-light text-warning-dark",
    qc_pending:  "bg-purple-50 text-purple-700",
    qc_passed:   "bg-success-light text-success",
    qc_failed:   "bg-danger-light text-danger",
};

const STATUS_DOT: Record<string, string> = {
    pending:     "bg-surface-400",
    in_progress: "bg-brand-500",
    on_hold:     "bg-warning",
    qc_pending:  "bg-purple-500",
    qc_passed:   "bg-success",
    qc_failed:   "bg-danger",
};

const PRIORITY_BORDER: Record<string, string> = {
    urgent: "border-l-danger",
    high:   "border-l-warning",
    normal: "border-l-brand-400",
    low:    "border-l-surface-300",
};

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const MONTHS   = [
    "January","February","March","April","May","June",
    "July","August","September","October","November","December",
];

// ─── Helpers ──────────────────────────────────────────────────────────────────

function isoDate(d: Date) {
    return d.toISOString().slice(0, 10);
}

function addDays(d: Date, n: number) {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
}

function startOfWeek(d: Date) {
    const r = new Date(d);
    r.setDate(r.getDate() - r.getDay());
    return r;
}

function startOfMonth(d: Date) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
}

/** Heatmap colour based on number of orders due on a day */
function heatClass(count: number): string {
    if (count === 0) return "";
    if (count <= 1)  return "bg-brand-50";
    if (count <= 3)  return "bg-brand-100";
    if (count <= 5)  return "bg-brand-200";
    return "bg-brand-300";
}

/** Friendly label for sales-team capacity indicator */
function capacityLabel(count: number): { label: string; cls: string } {
    if (count === 0) return { label: "Free",    cls: "text-success font-semibold" };
    if (count <= 2)  return { label: "Light",   cls: "text-brand-600 font-medium" };
    if (count <= 4)  return { label: "Moderate",cls: "text-warning-dark font-medium" };
    return              { label: "Busy",    cls: "text-danger font-semibold" };
}

function fmtDate(iso: string) {
    return new Date(iso).toLocaleDateString("en-KE", { dateStyle: "medium" });
}

function daysFromNow(iso: string) {
    return Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function LoadBadge({ count }: { count: number }) {
    const { label, cls } = capacityLabel(count);
    return <span className={clsx("text-2xs", cls)}>{label}</span>;
}

function PriorityPip({ priority }: { priority: string }) {
    const map: Record<string, string> = {
        urgent: "bg-danger",
        high:   "bg-warning",
        normal: "bg-brand-400",
        low:    "bg-surface-300",
    };
    return (
        <span
            className={clsx("inline-block w-1.5 h-1.5 rounded-full shrink-0 mt-0.5", map[priority] ?? "bg-surface-300")}
            title={priority}
        />
    );
}

/** Single order pill shown inside a calendar cell (full view only) */
function OrderPill({
    order,
    onClick,
}: {
    order: ProductionOrder;
    onClick: () => void;
}) {
    return (
        <button
            onClick={onClick}
            className={clsx(
                "w-full text-left text-2xs px-1.5 py-0.5 rounded-md border-l-2 truncate transition-colors",
                "hover:ring-1 hover:ring-brand-300",
                STATUS_COLORS[order.status] ?? "bg-surface-100 text-surface-600",
                PRIORITY_BORDER[order.priority] ?? "border-l-surface-300",
            )}
            title={`${order.order_number} - ${order.product_name}`}
        >
            <span className="font-mono font-semibold">{order.order_number}</span>
            {" "}
            <span className="opacity-70 truncate">{order.product_name}</span>
        </button>
    );
}

/** Summary row used in the "Upcoming" sidebar panel */
function UpcomingRow({ order, isSales }: { order: ProductionOrder; isSales: boolean }) {
    const navigate = useNavigate();
    const d = daysFromNow(order.due_date);
    const overdue = d < 0;
    return (
        <div
            className={clsx(
                "flex items-start gap-3 px-4 py-3 border-b border-surface-100 last:border-0",
                !isSales && "cursor-pointer hover:bg-surface-50 transition-colors"
            )}
            onClick={() => !isSales && navigate(`/production/orders/${order.id}`)}
        >
            <div className={clsx("w-2 h-2 rounded-full mt-1.5 shrink-0", STATUS_DOT[order.status] ?? "bg-surface-400")} />
            <div className="flex-1 min-w-0">
                {!isSales && (
                    <p className="text-xs font-semibold text-surface-900 font-mono">{order.order_number}</p>
                )}
                <p className={clsx("text-xs text-surface-600 truncate", isSales && "font-medium")}>{order.product_name}</p>
                <p className={clsx("text-2xs mt-0.5", overdue ? "text-danger font-semibold" : "text-surface-400")}>
                    {overdue
                        ? `Overdue by ${Math.abs(d)}d`
                        : d === 0
                        ? "Due today"
                        : `Due in ${d}d - ${fmtDate(order.due_date)}`}
                </p>
            </div>
            <div className="shrink-0">
                <PriorityPip priority={order.priority} />
            </div>
        </div>
    );
}

// ─── Month grid ───────────────────────────────────────────────────────────────

function MonthGrid({
    year,
    month,
    ordersByDate,
    appointmentsByDate,
    today,
    isSales,
    onDayClick,
}: {
    year: number;
    month: number;         // 0-indexed
    ordersByDate: Map<string, ProductionOrder[]>;
    /** Customer appointments by day: fittings and collections. */
    appointmentsByDate?: Map<string, { type: "fitting" | "collection"; order: ProductionOrder }[]>;
    today: string;
    isSales: boolean;
    onDayClick: (dateIso: string) => void;
}) {
    const firstDay  = new Date(year, month, 1);
    const startPad  = firstDay.getDay();           // 0 = Sun
    const daysInMo  = new Date(year, month + 1, 0).getDate();

    // Build a 6-row × 7-col grid (some cells are padding)
    const cells: Array<{ date: string | null; day: number | null }> = [];
    for (let i = 0; i < startPad; i++) cells.push({ date: null, day: null });
    for (let d = 1; d <= daysInMo; d++) {
        cells.push({ date: isoDate(new Date(year, month, d)), day: d });
    }
    while (cells.length % 7 !== 0) cells.push({ date: null, day: null });

    return (
        <div className="flex flex-col gap-px">
            {/* Weekday headers */}
            <div className="grid grid-cols-7 gap-px mb-1">
                {WEEKDAYS.map(w => (
                    <div key={w} className="text-center text-2xs font-semibold text-surface-400 uppercase tracking-wide py-1">
                        {w}
                    </div>
                ))}
            </div>

            {/* Cells */}
            <div className="grid grid-cols-7 gap-px">
                {cells.map((cell, idx) => {
                    if (!cell.date) {
                        return <div key={`pad-${idx}`} className="h-24 bg-surface-50/50 rounded-lg" />;
                    }
                    const orders = ordersByDate.get(cell.date) ?? [];
                    const count  = orders.length;
                    const isToday = cell.date === today;
                    const isPast  = cell.date < today;

                    return (
                        <div
                            key={cell.date}
                            onClick={() => onDayClick(cell.date!)}
                            className={clsx(
                                "relative h-24 p-1.5 rounded-lg border transition-colors cursor-pointer group",
                                isToday
                                    ? "border-brand-400 bg-brand-50/60"
                                    : "border-surface-100 hover:border-brand-200",
                                isPast && !isToday ? "opacity-60" : "",
                                !isSales && count > 0 ? heatClass(count) : "",
                            )}
                        >
                            {/* Day number */}
                            <div className="flex items-start justify-between">
                                <span className={clsx(
                                    "text-xs font-semibold leading-none",
                                    isToday ? "text-brand-600" : "text-surface-700",
                                )}>
                                    {cell.day}
                                </span>
                                {count > 0 && (
                                    <span className={clsx(
                                        "text-2xs font-bold leading-none px-1 py-0.5 rounded",
                                        isSales
                                            ? capacityLabel(count).cls + " bg-white/70"
                                            : "bg-white/70 text-surface-600",
                                    )}>
                                        {isSales ? capacityLabel(count).label : count}
                                    </span>
                                )}
                            </div>

                            {/* Order pills - full view only, max 3 visible */}
                            {!isSales && orders.length > 0 && (
                                <div className="mt-1 flex flex-col gap-0.5 overflow-hidden">
                                    {orders.slice(0, 2).map(o => (
                                        <div
                                            key={o.id}
                                            className={clsx(
                                                "text-2xs px-1 py-0.5 rounded border-l-2 truncate leading-tight",
                                                STATUS_COLORS[o.status] ?? "bg-surface-100 text-surface-500",
                                                PRIORITY_BORDER[o.priority] ?? "border-l-surface-300",
                                            )}
                                        >
                                            <span className="font-mono">{o.order_number.replace(/^[A-Z]+-/, "")}</span>
                                        </div>
                                    ))}
                                    {orders.length > 2 && (
                                        <span className="text-2xs text-surface-400 px-1">+{orders.length - 2} more</span>
                                    )}
                                </div>
                            )}

                            {/* Customer appointments — fittings (violet) and
                                collections (emerald). Dots, not pills: they are
                                moments in a day, not workload. */}
                            {!isSales && (appointmentsByDate?.get(cell.date!)?.length ?? 0) > 0 && (
                                <div className="mt-0.5 flex items-center gap-0.5 px-0.5">
                                    {appointmentsByDate!.get(cell.date!)!.slice(0, 4).map((a, i) => (
                                        <span key={i} title={`${a.type === "fitting" ? "Fitting" : "Collection"} · ${a.order.order_number}`}
                                            className={clsx("w-1.5 h-1.5 rounded-full", a.type === "fitting" ? "bg-violet-500" : "bg-emerald-500")} />
                                    ))}
                                </div>
                            )}

                            {/* Sales view - just a bar showing load */}
                            {isSales && count > 0 && (
                                <div className="mt-2">
                                    <div className="h-1.5 bg-surface-200 rounded-full overflow-hidden">
                                        <div
                                            className={clsx(
                                                "h-full rounded-full transition-all",
                                                count >= 6 ? "bg-danger" : count >= 4 ? "bg-warning" : count >= 2 ? "bg-brand-400" : "bg-success"
                                            )}
                                            style={{ width: `${Math.min(100, count * 16)}%` }}
                                        />
                                    </div>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// ─── Week grid ────────────────────────────────────────────────────────────────

function WeekGrid({
    weekStart,
    ordersByDate,
    today,
    isSales,
    onOrderClick,
}: {
    weekStart: Date;
    ordersByDate: Map<string, ProductionOrder[]>;
    today: string;
    isSales: boolean;
    onOrderClick: (id: number) => void;
}) {
    const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

    return (
        <div className="grid grid-cols-7 gap-2">
            {days.map(day => {
                const iso    = isoDate(day);
                const orders = ordersByDate.get(iso) ?? [];
                const isToday = iso === today;
                const isPast  = iso < today;

                return (
                    <div
                        key={iso}
                        className={clsx(
                            "flex flex-col gap-1.5 rounded-xl p-2 min-h-[180px] border transition-colors",
                            isToday
                                ? "border-brand-400 bg-brand-50/60"
                                : "border-surface-100 bg-white",
                            isPast && !isToday && "opacity-60",
                        )}
                    >
                        {/* Header */}
                        <div className="flex items-center justify-between mb-1">
                            <div>
                                <p className="text-2xs text-surface-400 uppercase tracking-wide font-medium">
                                    {WEEKDAYS[day.getDay()]}
                                </p>
                                <p className={clsx(
                                    "text-sm font-bold leading-tight",
                                    isToday ? "text-brand-600" : "text-surface-800",
                                )}>
                                    {day.getDate()}
                                </p>
                            </div>
                            {orders.length > 0 && (
                                isSales
                                    ? <LoadBadge count={orders.length} />
                                    : (
                                        <span className={clsx(
                                            "text-2xs font-bold w-5 h-5 flex items-center justify-center rounded-full",
                                            orders.length >= 5 ? "bg-danger text-white" :
                                            orders.length >= 3 ? "bg-warning text-white" :
                                            "bg-brand-100 text-brand-700",
                                        )}>
                                            {orders.length}
                                        </span>
                                    )
                            )}
                        </div>

                        {/* Orders */}
                        <div className="flex flex-col gap-1 flex-1 overflow-y-auto no-scrollbar">
                            {isSales ? (
                                orders.length > 0 && (
                                    <div className="mt-1">
                                        <div className="h-2 bg-surface-100 rounded-full overflow-hidden">
                                            <div
                                                className={clsx(
                                                    "h-full rounded-full",
                                                    orders.length >= 6 ? "bg-danger" :
                                                    orders.length >= 4 ? "bg-warning" :
                                                    orders.length >= 2 ? "bg-brand-400" : "bg-success"
                                                )}
                                                style={{ width: `${Math.min(100, orders.length * 16)}%` }}
                                            />
                                        </div>
                                        <p className="text-2xs text-surface-500 mt-1">
                                            {orders.length} order{orders.length !== 1 ? "s" : ""} due
                                        </p>
                                    </div>
                                )
                            ) : (
                                orders.map(o => (
                                    <OrderPill
                                        key={o.id}
                                        order={o}
                                        onClick={() => onOrderClick(o.id)}
                                    />
                                ))
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

// ─── Day detail panel ─────────────────────────────────────────────────────────

function DayPanel({
    dateIso,
    orders,
    appointments = [],
    isSales,
    onClose,
    onOrderClick,
}: {
    dateIso: string;
    orders: ProductionOrder[];
    appointments?: { type: "fitting" | "collection"; order: ProductionOrder }[];
    isSales: boolean;
    onClose: () => void;
    onOrderClick: (id: number) => void;
}) {
    const d = new Date(dateIso + "T12:00:00");
    const label = d.toLocaleDateString("en-KE", { weekday: "long", year: "numeric", month: "long", day: "numeric" });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" onClick={onClose} />
            <div className="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[80vh]">
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-surface-100 shrink-0">
                    <div>
                        <p className="text-xs text-surface-400 uppercase tracking-wide font-semibold">
                            {isSales ? "Workshop Schedule" : "Due on this day"}
                        </p>
                        <p className="text-sm font-bold text-surface-900 mt-0.5">{label}</p>
                    </div>
                    <button onClick={onClose} className="btn-icon btn-ghost text-surface-400">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto">
                    {!isSales && appointments.length > 0 && (
                        <div className="px-5 py-3 border-b border-surface-100 space-y-1.5">
                            <p className="text-2xs font-bold uppercase tracking-wide text-surface-400">Customer appointments</p>
                            {appointments.map((a, i) => (
                                <button key={i} onClick={() => a.order.id && onOrderClick(a.order.id)}
                                    className="w-full flex items-center gap-2 text-left text-xs hover:bg-surface-50 rounded-lg px-2 py-1.5 transition-colors">
                                    <span className={clsx("w-2 h-2 rounded-full shrink-0", a.type === "fitting" ? "bg-violet-500" : "bg-emerald-500")} />
                                    <span className={clsx("font-bold", a.type === "fitting" ? "text-violet-700" : "text-emerald-700")}>
                                        {a.type === "fitting" ? "Fitting" : "Collection"}
                                    </span>
                                    <span className="font-mono text-surface-500 truncate">{a.order.order_number}</span>
                                    <span className="text-surface-400 truncate flex-1">{a.order.product_name}</span>
                                </button>
                            ))}
                        </div>
                    )}
                    {orders.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-12 text-surface-400 gap-2">
                            <svg className="w-10 h-10 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.25}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <p className="text-sm font-medium text-surface-500">
                                {isSales ? "Workshop is free on this day" : "No orders due"}
                            </p>
                        </div>
                    ) : isSales ? (
                        /* Sales view - just capacity summary, no order details */
                        <div className="px-5 py-6 flex flex-col gap-5">
                            <div className={clsx(
                                "rounded-2xl p-5 flex flex-col items-center gap-2",
                                orders.length >= 6 ? "bg-danger-light" :
                                orders.length >= 4 ? "bg-warning-light" :
                                orders.length >= 2 ? "bg-brand-50" : "bg-success-light",
                            )}>
                                <p className={clsx(
                                    "text-3xl font-bold",
                                    orders.length >= 6 ? "text-danger" :
                                    orders.length >= 4 ? "text-warning-dark" :
                                    orders.length >= 2 ? "text-brand-600" : "text-success",
                                )}>
                                    {capacityLabel(orders.length).label}
                                </p>
                                <p className="text-sm text-surface-600 text-center">
                                    {orders.length} production order{orders.length !== 1 ? "s" : ""} scheduled for this day.
                                </p>
                            </div>
                            <div className="bg-surface-50 rounded-xl p-4 text-sm text-surface-600 space-y-1.5">
                                <p className="font-semibold text-surface-800 text-xs uppercase tracking-wide mb-2">Guidance</p>
                                {orders.length === 0 && <p>✅ Workshop is free - standard lead times apply.</p>}
                                {orders.length >= 1 && orders.length <= 2 && <p>✅ Light load - taking new orders for this date should be fine.</p>}
                                {orders.length >= 3 && orders.length <= 4 && <p>⚠️ Moderate load - discuss with production before confirming this date.</p>}
                                {orders.length >= 5 && <p>🚫 Workshop is heavily loaded - avoid promising this delivery date. Suggest a later date.</p>}
                            </div>
                        </div>
                    ) : (
                        /* Full view - order list */
                        <div className="divide-y divide-surface-100">
                            {orders.map(o => {
                                const cfg = STATUS_COLORS[o.status] ?? "bg-surface-100 text-surface-600";
                                return (
                                    <button
                                        key={o.id}
                                        onClick={() => { onOrderClick(o.id); onClose(); }}
                                        className="w-full flex items-start gap-3 px-5 py-4 text-left hover:bg-surface-50 transition-colors"
                                    >
                                        <div className={clsx("w-2 h-2 rounded-full mt-1.5 shrink-0", STATUS_DOT[o.status] ?? "bg-surface-400")} />
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-mono text-sm font-bold text-surface-900">{o.order_number}</span>
                                                <span className={clsx("text-2xs px-2 py-0.5 rounded-full font-medium", cfg)}>
                                                    {o.status.replace("_", " ")}
                                                </span>
                                                {o.customer_order_id && (
                                                    <span className="text-2xs px-2 py-0.5 rounded-full bg-purple-50 text-purple-700 font-medium">MTO</span>
                                                )}
                                            </div>
                                            <p className="text-xs text-surface-600 mt-0.5 truncate">{o.product_name}</p>
                                            {o.completion_percentage > 0 && (
                                                <div className="mt-2 flex items-center gap-2">
                                                    <div className="flex-1 h-1.5 bg-surface-100 rounded-full overflow-hidden">
                                                        <div
                                                            className="h-full bg-brand-400 rounded-full transition-all"
                                                            style={{ width: `${o.completion_percentage}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-2xs text-surface-400">{o.completion_percentage}%</span>
                                                </div>
                                            )}
                                        </div>
                                        <PriorityPip priority={o.priority} />
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function ProductionCalendarPage() {
    const navigate = useNavigate();
    const { can } = usePermissions();

    // Determine which audience this user belongs to
    const canViewFull = can("production.view");
    const canRaiseOrder = can("production.raise_order");
    const isSales = !canViewFull && canRaiseOrder;

    const today = isoDate(new Date());
    const [viewMode, setViewMode] = useState<ViewMode>("month");
    const [cursor, setCursor] = useState(new Date()); // month/week navigation anchor
    const [selectedDay, setSelectedDay] = useState<string | null>(null);

    // Current logged-in user — used to pre-select the dropdown and guard queries
    const currentUserId = useAuthStore(s => s.user?.id ?? null);
    const currentUser   = useAuthStore(s => s.user);
    const canViewUsers  = can("users.view");

    // Workers default to "mine". Admins/managers with production.view pre-select
    // themselves so they see their own tasks immediately, but can switch to any user.
    const isWorker = !canViewFull && !canRaiseOrder && can("production.worker");
    const [selectedUserId, setSelectedUserId] = useState<"all" | "mine" | string>(
        isWorker ? "mine" : currentUserId ? String(currentUserId) : "all"
    );

    // ── Data fetching ────────────────────────────────────────────────────────

    // Schedule summary (always fetched - used for sales panel)
    const { data: scheduleData, isLoading: scheduleLoading } = useQuery({
        queryKey: ["production-schedule"],
        queryFn: () => get<ScheduleResponse>("/v1/admin/production/schedule"),
        staleTime: 2 * 60_000,
        refetchInterval: 5 * 60_000,
    });

    // Full order list (production team only - sales team only sees schedule)
    const { data: ordersData, isLoading: ordersLoading } = useQuery({
        queryKey: ["production-calendar-orders"],
        queryFn: () => get<{ data: ProductionOrder[] }>("/v1/admin/production-orders", {
            params: {
                status: "pending,in_progress,on_hold,qc_pending,qc_passed,qc_failed",
                per_page: "200",
                sort_by: "due_date",
                sort_order: "asc",
            },
        }),
        enabled: canViewFull && selectedUserId === "all",
        staleTime: 2 * 60_000,
        refetchInterval: 5 * 60_000,
    });

    // Production workers list — only fetch when the user actually has users.view.
    // Guards against 403s for users who have production.view but not users.view.
    const { data: usersData } = useQuery({
        queryKey: ["production-calendar-users"],
        queryFn: () => get<{ data: ProductionUser[] }>("/v1/admin/users", {
            params: { per_page: "100", exclude_type: "customer" } as any,
        }),
        enabled: canViewFull && canViewUsers,
        staleTime: 5 * 60_000,
        retry: false,
    });
    const productionUsers: ProductionUser[] = usersData?.data ?? [];

    // Tasks for a specific user (admin filtering by user, or worker viewing own)
    const { data: userTasksData, isLoading: userTasksLoading } = useQuery({
        queryKey: ["production-calendar-user-tasks", selectedUserId],
        queryFn: () => {
            if (selectedUserId === "mine") {
                return get<CalendarTask[]>("/v1/tailor/tasks?include_completed=true");
            }
            return get<{ data: CalendarTask[] }>("/v1/admin/production-tasks", {
                params: { tailor_id: selectedUserId, per_page: "200" } as any,
            }).then(r => r.data);
        },
        enabled: selectedUserId !== "all",
        staleTime: 2 * 60_000,
        refetchInterval: 5 * 60_000,
    });
    const userTasks: CalendarTask[] = Array.isArray(userTasksData) ? userTasksData : (userTasksData as any ?? []);

    const orders: ProductionOrder[] = ordersData?.data ?? [];
    const isLoading = scheduleLoading || (canViewFull && ordersLoading) || userTasksLoading;

    // ── Build ordersByDate map ───────────────────────────────────────────────
    // Three data sources depending on role + filter selection:
    //   1. selectedUserId === "all"  → full orders list (production team, admin)
    //   2. selectedUserId === "mine" → own tasks (worker/tailor view)
    //   3. selectedUserId === <id>   → tasks filtered by that user (admin per-user view)
    //   4. isSales                   → schedule data only (no order details)
    const ordersByDate = useMemo(() => {
        const map = new Map<string, ProductionOrder[]>();

        if (selectedUserId !== "all") {
            // Worker own view OR admin filtered to a specific user — use task data
            for (const t of userTasks) {
                const key = t.production_order.due_date?.slice(0, 10);
                if (!key) continue;
                if (!map.has(key)) map.set(key, []);
                // De-duplicate by production_order.id (multiple tasks per order)
                const existing = map.get(key)!;
                if (!existing.find(o => o.id === t.production_order.id)) {
                    existing.push(taskToOrder(t));
                }
            }
        } else if (canViewFull) {
            for (const o of orders) {
                const key = o.due_date?.slice(0, 10);
                if (!key) continue;
                if (!map.has(key)) map.set(key, []);
                map.get(key)!.push(o);
            }
        } else {
            // Sales view — populate from schedule data
            for (const o of scheduleData?.upcoming_orders ?? []) {
                const key = o.due_date?.slice(0, 10);
                if (!key) continue;
                if (!map.has(key)) map.set(key, []);
                map.get(key)!.push({
                    id: 0,
                    order_number: o.order_number,
                    product_name: "",
                    status: o.status,
                    priority: o.priority,
                    due_date: o.due_date,
                    completion_percentage: 0,
                } as ProductionOrder);
            }
        }
        return map;
    }, [orders, scheduleData, canViewFull, selectedUserId, userTasks]);

    // Customer appointments: fittings and collections, keyed by day. Full-view
    // only — the sales availability view has no business seeing order details.
    const appointmentsByDate = useMemo(() => {
        const map = new Map<string, { type: "fitting" | "collection"; order: ProductionOrder }[]>();
        if (!canViewFull) return map;
        for (const o of orders) {
            for (const [type, date] of [["fitting", o.fitting_date], ["collection", o.collection_date]] as const) {
                const key = date?.slice(0, 10);
                if (!key) continue;
                if (!map.has(key)) map.set(key, []);
                map.get(key)!.push({ type, order: o });
            }
        }
        return map;
    }, [orders, canViewFull]);

    // ── Navigation ───────────────────────────────────────────────────────────

    const goBack = () => {
        if (viewMode === "month") {
            setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1));
        } else {
            setCursor(addDays(cursor, -7));
        }
    };
    const goForward = () => {
        if (viewMode === "month") {
            setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1));
        } else {
            setCursor(addDays(cursor, 7));
        }
    };
    const goToday = () => setCursor(new Date());

    // ── Computed labels ──────────────────────────────────────────────────────

    const monthLabel = `${MONTHS[cursor.getMonth()]} ${cursor.getFullYear()}`;
    const wStart = startOfWeek(cursor);
    const wEnd   = addDays(wStart, 6);
    const weekLabel = wStart.getMonth() === wEnd.getMonth()
        ? `${wStart.getDate()}–${wEnd.getDate()} ${MONTHS[wStart.getMonth()]} ${wStart.getFullYear()}`
        : `${wStart.getDate()} ${MONTHS[wStart.getMonth()]} – ${wEnd.getDate()} ${MONTHS[wEnd.getMonth()]} ${wEnd.getFullYear()}`;

    // Upcoming orders for sidebar (next 14 days)
    const upcoming = useMemo(() => {
        const cutoff = isoDate(addDays(new Date(), 14));
        if (!canViewFull && selectedUserId === "all") return [];

        const source: ProductionOrder[] = selectedUserId !== "all"
            ? userTasks.map(taskToOrder)
            : orders;

        // De-duplicate by id when coming from tasks (multiple tasks per order)
        const seen = new Set<number>();
        return source
            .filter(o => {
                if (seen.has(o.id)) return false;
                seen.add(o.id);
                return o.due_date >= today && o.due_date <= cutoff;
            })
            .sort((a, b) => a.due_date.localeCompare(b.due_date));
    }, [orders, userTasks, today, canViewFull, selectedUserId]);

    // ── Overdue count (full view) ────────────────────────────────────────────
    const overdueCount = useMemo(() => {
        if (!canViewFull && selectedUserId === "all") return 0;
        const source = selectedUserId !== "all"
            ? userTasks.map(taskToOrder)
            : orders;
        const seen = new Set<number>();
        return source.filter(o => {
            if (seen.has(o.id)) return false;
            seen.add(o.id);
            return o.due_date < today && !["completed","cancelled"].includes(o.status);
        }).length;
    }, [orders, userTasks, today, canViewFull, selectedUserId]);

    const selectedOrders = selectedDay ? (ordersByDate.get(selectedDay) ?? []) : [];

    // ── Render ───────────────────────────────────────────────────────────────

    return (
        <div className="flex flex-col gap-5 animate-fade-in pb-6">

            {/* ── Page header ────────────────────────────────────────────── */}
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h1 className="page-title">
                        {isSales ? "Workshop Availability" : "Production Calendar"}
                    </h1>
                    <p className="page-subtitle">
                        {isSales
                            ? "See how busy the workshop is before promising customers a delivery date."
                            : isWorker
                            ? "Your assigned production tasks by due date."
                            : selectedUserId !== "all"
                            ? (() => {
                                if (!canViewUsers) {
                                    // Can't browse other users — always their own view
                                    return currentUser
                                        ? `Showing your assigned tasks, ${currentUser.first_name}.`
                                        : "Your assigned production tasks.";
                                }
                                const u = productionUsers.find(u => String(u.id) === selectedUserId);
                                return u
                                    ? `Showing tasks assigned to ${u.first_name} ${u.last_name}.`
                                    : "Filtered by worker.";
                              })()
                            : "All active production orders by due date. Click any day for details."}
                    </p>
                </div>

                {/* Controls: user filter (admin) + view toggle */}
                {(canViewFull || isWorker) && (
                    <div className="flex items-center gap-2 flex-wrap">

                        {/* ── User filter ──────────────────────────────────────
                            canViewUsers = true  → full dropdown (All + user list)
                            canViewFull only     → static name pill (no users.view)
                            isWorker             → static "My Tasks" pill
                        ──────────────────────────────────────────────────── */}

                        {canViewFull && canViewUsers && (
                            <div className="relative">
                                <select
                                    value={selectedUserId}
                                    onChange={e => setSelectedUserId(e.target.value)}
                                    className={clsx(
                                        "appearance-none pl-8 pr-8 py-1.5 rounded-xl text-xs font-semibold border transition-all cursor-pointer",
                                        selectedUserId !== "all"
                                            ? "bg-brand-500 text-white border-brand-500"
                                            : "bg-white text-surface-700 border-surface-200 hover:border-surface-300"
                                    )}
                                >
                                    <option value="all">All Workers</option>
                                    {/* "Me" shortcut — always at top */}
                                    {currentUserId && (
                                        <option value={String(currentUserId)}>
                                            Me ({currentUser ? `${currentUser.first_name} ${(currentUser as any).last_name ?? ""}`.trim() : ""})
                                        </option>
                                    )}
                                    {productionUsers
                                        .filter(u => u.id !== currentUserId)
                                        .map(u => (
                                            <option key={u.id} value={String(u.id)}>
                                                {u.first_name} {u.last_name}
                                            </option>
                                        ))}
                                </select>
                                <svg className={clsx("pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5",
                                    selectedUserId !== "all" ? "text-white" : "text-surface-400")}
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                                </svg>
                                <svg className={clsx("pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 w-3 h-3",
                                    selectedUserId !== "all" ? "text-white/70" : "text-surface-400")}
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </div>
                        )}

                        {/* Has production.view but NOT users.view — static name pill */}
                        {canViewFull && !canViewUsers && currentUser && (
                            <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-500 text-white text-xs font-semibold">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                                </svg>
                                {`${currentUser.first_name} ${(currentUser as any).last_name ?? ""}`.trim()}
                            </div>
                        )}

                        {/* Worker: "My Tasks" pill — informational, no toggle */}
                        {isWorker && (
                            <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-50 border border-brand-200 text-brand-700 text-xs font-semibold">
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                                </svg>
                                My Tasks
                            </div>
                        )}

                        {/* View toggle — production team only */}
                        {canViewFull && (
                            <div className="flex rounded-xl border border-surface-200 overflow-hidden bg-white">
                                {(["month","week"] as ViewMode[]).map(v => (
                                    <button
                                        key={v}
                                        onClick={() => setViewMode(v)}
                                        className={clsx(
                                            "px-4 py-1.5 text-xs font-semibold capitalize transition-colors",
                                            viewMode === v
                                                ? "bg-surface-900 text-white"
                                                : "text-surface-500 hover:text-surface-700",
                                        )}
                                    >
                                        {v}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* ── Summary strip (production team) ───────────────────────── */}
            {(canViewFull || isWorker) && !isLoading && (
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    {[
                        {
                            label: selectedUserId !== "all" ? "Assigned Orders" : "Active Orders",
                            value: selectedUserId !== "all"
                                ? new Set(userTasks.map(t => t.production_order.id)).size
                                : (scheduleData?.active_count ?? 0),
                            cls: "text-brand-600",
                            bg: "bg-brand-50",
                        },
                        {
                            label: "Due Next 14 Days",
                            value: upcoming.length,
                            cls: "text-surface-700",
                            bg: "bg-surface-50",
                        },
                        {
                            label: "Overdue",
                            value: overdueCount,
                            cls: overdueCount > 0 ? "text-danger" : "text-success",
                            bg: overdueCount > 0 ? "bg-danger-light" : "bg-success-light",
                        },
                        {
                            label: "Earliest Free Slot",
                            value: scheduleData?.earliest_free_slot
                                ? fmtDate(scheduleData.earliest_free_slot)
                                : "Now",
                            cls: "text-surface-700 text-sm",
                            bg: "bg-surface-50",
                            small: true,
                        },
                    ].map(({ label, value, cls, bg, small }) => (
                        <div key={label} className={clsx("rounded-2xl p-4", bg)}>
                            <p className="text-2xs text-surface-400 font-semibold uppercase tracking-wide mb-1">{label}</p>
                            <p className={clsx("font-bold", small ? "text-lg" : "text-2xl", cls)}>{value}</p>
                        </div>
                    ))}
                </div>
            )}

            {/* ── Sales capacity strip ───────────────────────────────────── */}
            {isSales && !isLoading && scheduleData && (
                <div className="rounded-2xl border border-surface-200 bg-white p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                    <div className="flex-1">
                        <p className="text-sm font-semibold text-surface-800">Current Workshop Load</p>
                        <p className="text-xs text-surface-500 mt-0.5">
                            {scheduleData.active_count} active order{scheduleData.active_count !== 1 ? "s" : ""} in production right now.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <div className={clsx(
                            "px-4 py-2 rounded-xl font-bold text-sm",
                            scheduleData.active_count >= 8 ? "bg-danger-light text-danger" :
                            scheduleData.active_count >= 5 ? "bg-warning-light text-warning-dark" :
                            scheduleData.active_count >= 2 ? "bg-brand-50 text-brand-700" : "bg-success-light text-success",
                        )}>
                            {capacityLabel(scheduleData.active_count).label}
                        </div>
                        <div className="text-sm text-surface-600">
                            <span className="font-medium">Earliest free slot: </span>
                            <span className="font-bold text-surface-800">{fmtDate(scheduleData.earliest_free_slot)}</span>
                        </div>
                    </div>
                </div>
            )}

            {/* ── Calendar + sidebar ─────────────────────────────────────── */}
            <div className="flex gap-5 items-start">

                {/* Calendar pane */}
                <div className="card flex flex-col gap-4 flex-1 min-w-0 p-5 pb-6">

                    {/* Nav bar */}
                    <div className="flex items-center gap-3 shrink-0">
                        <button
                            onClick={goBack}
                            className="btn-icon btn-ghost btn-sm"
                            aria-label="Previous"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <h2 className="flex-1 text-center text-sm font-bold text-surface-900">
                            {viewMode === "month" ? monthLabel : weekLabel}
                        </h2>
                        <button
                            onClick={goForward}
                            className="btn-icon btn-ghost btn-sm"
                            aria-label="Next"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                        <button onClick={goToday} className="btn-secondary btn-sm">Today</button>
                    </div>

                    {/* Legend */}
                    <div className="flex items-center gap-4 text-2xs text-surface-400 flex-wrap shrink-0">
                        {isSales ? (
                            <>
                                <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-success inline-block" />Free</span>
                                <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-brand-400 inline-block" />Light</span>
                                <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-warning inline-block" />Moderate</span>
                                <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-danger inline-block" />Busy</span>
                            </>
                        ) : (
                            <>
                                <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-brand-100 inline-block" />1–3 orders</span>
                                <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-brand-200 inline-block" />4–5 orders</span>
                                <span className="flex items-center gap-1"><span className="w-2.5 h-2.5 rounded-sm bg-brand-300 inline-block" />6+ orders</span>
                                <span className="ml-2 flex items-center gap-1"><span className="inline-block w-2 h-3 border-l-2 border-l-danger bg-surface-100 rounded-sm" />Urgent</span>
                                <span className="flex items-center gap-1"><span className="inline-block w-2 h-3 border-l-2 border-l-warning bg-surface-100 rounded-sm" />High</span>
                            </>
                        )}
                    </div>

                    {isLoading ? (
                        <div className="flex justify-center py-16"><Spinner size="lg" /></div>
                    ) : viewMode === "month" ? (
                        <MonthGrid
                            appointmentsByDate={appointmentsByDate}
                            year={cursor.getFullYear()}
                            month={cursor.getMonth()}
                            ordersByDate={ordersByDate}
                            today={today}
                            isSales={isSales}
                            onDayClick={setSelectedDay}
                        />
                    ) : (
                        <WeekGrid
                            weekStart={startOfWeek(cursor)}
                            ordersByDate={ordersByDate}
                            today={today}
                            isSales={isSales}
                            onOrderClick={id => navigate(`/production/orders/${id}`)}
                        />
                    )}
                </div>

                {/* Sidebar panel - different content per audience */}
                <div className="hidden lg:flex flex-col w-72 shrink-0 gap-4 sticky top-4">

                    {(canViewFull || isWorker) && (
                        /* Production team / worker: upcoming orders list */
                        <div className="card p-0 flex flex-col overflow-hidden max-h-[520px]">
                            <div className="px-4 py-3 border-b border-surface-100 shrink-0 flex items-center justify-between gap-2">
                                <p className="text-xs font-bold text-surface-800 uppercase tracking-wide">
                                    Due next 14 days
                                </p>
                                {selectedUserId !== "all" && canViewFull && (
                                    <span className="text-2xs font-semibold px-2 py-0.5 rounded-full bg-brand-50 text-brand-600 truncate max-w-[120px]">
                                        {selectedUserId === "mine"
                                            ? "My tasks"
                                            : productionUsers.find(u => String(u.id) === selectedUserId)
                                                ? `${productionUsers.find(u => String(u.id) === selectedUserId)!.first_name}'s tasks`
                                                : "Filtered"}
                                    </span>
                                )}
                            </div>
                            {upcoming.length === 0 ? (
                                <div className="flex items-center justify-center py-10 text-surface-400 text-sm">
                                    No orders due soon
                                </div>
                            ) : (
                                <div className="overflow-y-auto flex-1 no-scrollbar">
                                    {upcoming.map(o => (
                                        <UpcomingRow key={o.id} order={o} isSales={false} />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {isSales && scheduleData && (
                        /* Sales team: capacity guidance */
                        <div className="card p-5 flex flex-col gap-5">
                            <div>
                                <p className="text-xs font-bold text-surface-800 uppercase tracking-wide mb-3">
                                    How to read this calendar
                                </p>
                                <div className="space-y-2.5 text-xs text-surface-600">
                                    {[
                                        { color: "bg-success", label: "Free", desc: "Standard lead times - no issues." },
                                        { color: "bg-brand-400", label: "Light", desc: "Lightly loaded - fine to book." },
                                        { color: "bg-warning", label: "Moderate", desc: "Confirm with production first." },
                                        { color: "bg-danger", label: "Busy", desc: "Avoid - suggest a later date." },
                                    ].map(({ color, label, desc }) => (
                                        <div key={label} className="flex items-start gap-2">
                                            <span className={clsx("w-3 h-3 rounded mt-0.5 shrink-0", color)} />
                                            <span><strong>{label}:</strong> {desc}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="border-t border-surface-100 pt-4">
                                <p className="text-xs font-bold text-surface-800 uppercase tracking-wide mb-2">
                                    Suggested earliest date
                                </p>
                                <div className="bg-brand-50 rounded-xl px-4 py-3">
                                    <p className="text-base font-bold text-brand-700">
                                        {fmtDate(scheduleData.earliest_free_slot)}
                                    </p>
                                    <p className="text-2xs text-brand-500 mt-0.5">
                                        Based on current queue - always confirm with the production manager.
                                    </p>
                                </div>
                            </div>

                            <div className="border-t border-surface-100 pt-4">
                                <p className="text-xs font-bold text-surface-800 uppercase tracking-wide mb-2">
                                    Active right now
                                </p>
                                <p className="text-2xl font-bold text-surface-900">
                                    {scheduleData.active_count}
                                    <span className="text-sm font-normal text-surface-400 ml-1">orders</span>
                                </p>
                            </div>
                        </div>
                    )}

                    {/* Stage load breakdown (production team + workers) */}
                    {(canViewFull || isWorker) && (() => {
                        // When a user is selected (or worker viewing own tasks), derive
                        // stage breakdown from userTasks. Otherwise use the global API data.
                        const useFiltered = selectedUserId !== "all";

                        const byStage: Record<string, { name: string; count: number }> = {};

                        if (useFiltered) {
                            for (const t of userTasks) {
                                const key  = String(t.stage.id);
                                const name = t.stage.name;
                                if (!byStage[key]) byStage[key] = { name, count: 0 };
                                byStage[key].count++;
                            }
                        } else if (scheduleData) {
                            for (const [stageId, count] of Object.entries(scheduleData.by_stage)) {
                                byStage[stageId] = { name: `Stage ${stageId}`, count: count as number };
                            }
                        }

                        const entries = Object.entries(byStage);
                        if (entries.length === 0) return null;

                        const maxCount = Math.max(...entries.map(([, v]) => v.count));

                        return (
                            <div className="card p-4">
                                <div className="flex items-center justify-between mb-3">
                                    <p className="text-xs font-bold text-surface-800 uppercase tracking-wide">
                                        Active tasks by stage
                                    </p>
                                    {useFiltered && (
                                        <span className="text-2xs text-brand-600 font-semibold bg-brand-50 px-2 py-0.5 rounded-full">
                                            {selectedUserId === "mine"
                                                ? "My tasks"
                                                : (() => {
                                                    const u = productionUsers.find(u => String(u.id) === selectedUserId);
                                                    return u ? `${u.first_name}'s` : "Filtered";
                                                })()}
                                        </span>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    {entries.map(([key, { name, count }]) => (
                                        <div key={key} className="flex items-center gap-2">
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between text-xs mb-0.5">
                                                    <span className="text-surface-500 truncate">{name}</span>
                                                    <span className="font-semibold text-surface-700">{count}</span>
                                                </div>
                                                <div className="h-1.5 bg-surface-100 rounded-full overflow-hidden">
                                                    <div
                                                        className="h-full bg-brand-400 rounded-full transition-all"
                                                        style={{ width: `${Math.min(100, (count / maxCount) * 100)}%` }}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        );
                    })()}
                </div>
            </div>

            {/* ── Day detail modal ───────────────────────────────────────── */}
            {selectedDay && (
                <DayPanel
                    appointments={appointmentsByDate.get(selectedDay!) ?? []}
                    dateIso={selectedDay}
                    orders={selectedOrders}
                    isSales={isSales}
                    onClose={() => setSelectedDay(null)}
                    onOrderClick={id => navigate(`/production/orders/${id}`)}
                />
            )}
        </div>
    );
}