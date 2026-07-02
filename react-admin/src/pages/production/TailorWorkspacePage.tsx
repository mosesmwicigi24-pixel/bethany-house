/**
 * TailorWorkspacePage  – /production/my-tasks
 *
 * Tasks are grouped by production order throughout both tabs.
 *
 * FOCUS TAB  — navigates order-by-order (not task-by-task).
 *   • Focus card shows the order's product, measurements, notes
 *   • Stage checklist below the card shows all tasks for that order;
 *     the current active task is highlighted with its action button
 *   • Prev / Next navigate between orders
 *   • Dots represent orders, not individual tasks
 *
 * QUEUE TAB  — orders as collapsible group headers with a progress bar,
 *   task rows indented underneath each order.
 *
 * COMPLETION FLOW, NOTE/SPECS DRAWERS, OFFLINE QUEUE — unchanged.
 */

import { useState, useCallback, useEffect, useMemo } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, put, post } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { PullRefreshIndicator } from "@/components/pwa/PullRefreshIndicator";
import { usePullToRefresh } from "@/lib/usePullToRefresh";
import { tokenStorage } from "@/api/client";
import type { ApiError } from "@/types";

// ── Types ─────────────────────────────────────────────────────────────────────

interface MyTask {
    id: number;
    status: "pending" | "in_progress" | "completed" | "paused" | "failed";
    estimated_hours?: number;
    actual_hours?: number;
    started_at?: string | null;
    completed_at?: string | null;
    notes?: string | null;
    stage: { id: number; name: string; slug: string; description?: string };
    production_order: {
        id: number;
        order_number: string;
        priority: "low" | "normal" | "high" | "urgent";
        due_date: string;
        status: string;
        quantity: number;
        specifications?: Record<string, string> | null;
        measurements?: Record<string, string> | null;
        customer_preferences?: Record<string, string> | null;
        notes?: string | null;
        product_id: number;
        product?: {
            translations?: { name: string }[];
            images?: { image_url: string }[];
        };
        customer?: { first_name: string; last_name: string } | null;
        material_allocations?: {
            material: { name: string; unit_of_measure: string };
            quantity_required: number;
        }[];
    };
}

// A group of tasks that all belong to the same production order.
interface OrderGroup {
    orderId: number;
    orderNumber: string;
    productName: string;
    imageUrl: string | undefined;
    customerName: string | null;
    priority: MyTask["production_order"]["priority"];
    dueDate: string;
    quantity: number;
    tasks: MyTask[]; // all tasks for this order (sorted by stage sort_order)
    activeTask: MyTask | null; // the task the tailor should work on right now
    completedCount: number;
    totalCount: number;
}

type TabId = "focus" | "queue";

// ── Helpers ───────────────────────────────────────────────────────────────────

const daysUntil = (d: string) =>
    Math.ceil((new Date(d).getTime() - Date.now()) / 86_400_000);

function getProductName(task: MyTask) {
    return (
        task.production_order.product?.translations?.[0]?.name ??
        `Order ${task.production_order.order_number}`
    );
}

function getCustomerName(task: MyTask) {
    const c = task.production_order.customer;
    return c ? `${c.first_name} ${c.last_name}`.trim() : null;
}

// Status priority for picking the "active task" within an order group.
const TASK_STATUS_PRIORITY: Record<string, number> = {
    in_progress: 0,
    paused: 1,
    pending: 2,
    completed: 3,
    failed: 4,
};

// Group a flat task list into OrderGroups, sorted so groups with the most
// urgent / active tasks appear first.
function groupTasksByOrder(tasks: MyTask[]): OrderGroup[] {
    const map = new Map<number, MyTask[]>();
    for (const t of tasks) {
        const id = t.production_order.id;
        if (!map.has(id)) map.set(id, []);
        map.get(id)!.push(t);
    }

    const groups: OrderGroup[] = [];
    for (const [orderId, orderTasks] of map) {
        const rep = orderTasks[0];
        const sorted = [...orderTasks].sort(
            (a, b) =>
                (TASK_STATUS_PRIORITY[a.status] ?? 99) -
                (TASK_STATUS_PRIORITY[b.status] ?? 99)
        );
        const activeTask =
            sorted.find(
                (t) =>
                    t.status === "in_progress" ||
                    t.status === "paused" ||
                    t.status === "pending"
            ) ?? null;
        const completedCount = orderTasks.filter(
            (t) => t.status === "completed"
        ).length;

        groups.push({
            orderId,
            orderNumber: rep.production_order.order_number,
            productName: getProductName(rep),
            imageUrl: rep.production_order.product?.images?.[0]?.image_url,
            customerName: getCustomerName(rep),
            priority: rep.production_order.priority,
            dueDate: rep.production_order.due_date,
            quantity: rep.production_order.quantity,
            tasks: sorted,
            activeTask,
            completedCount,
            totalCount: orderTasks.length,
        });
    }

    // Sort groups: fully-done last; within active groups, the one with an
    // in_progress task comes first, then by due date.
    return groups.sort((a, b) => {
        const aDone = a.completedCount === a.totalCount;
        const bDone = b.completedCount === b.totalCount;
        if (aDone !== bDone) return aDone ? 1 : -1;
        const aActive =
            a.activeTask?.status === "in_progress" ? 0 : 1;
        const bActive =
            b.activeTask?.status === "in_progress" ? 0 : 1;
        if (aActive !== bActive) return aActive - bActive;
        return (
            new Date(a.dueDate).getTime() - new Date(b.dueDate).getTime()
        );
    });
}

// ── Offline queue helper ───────────────────────────────────────────────────────

async function queueOfflineTaskUpdate(
    taskId: number,
    action: string,
    body: object
): Promise<void> {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open("bh-offline-queue", 1);
        req.onsuccess = () => {
            const db = req.result;
            const tx = db.transaction("task-updates", "readwrite");
            tx.objectStore("task-updates").add({
                url: `/api/v1/tailor/tasks/${taskId}/status`,
                token: tokenStorage.get() ?? "",
                body,
            });
            tx.oncomplete = () => {
                resolve();
                navigator.serviceWorker?.ready.then((reg) => {
                    (reg as any).sync
                        ?.register("task-status-update")
                        .catch(() => {});
                });
            };
            tx.onerror = () => reject(tx.error);
        };
        req.onerror = () => reject(req.error);
    });
}

// ── Elapsed timer hook ────────────────────────────────────────────────────────

function useElapsedTimer(task: MyTask | null): string | null {
    const [, forceRender] = useState(0);

    useEffect(() => {
        if (!task || task.status !== "in_progress" || !task.started_at) return;
        const id = setInterval(() => forceRender((n) => n + 1), 30_000);
        return () => clearInterval(id);
    }, [task?.status, task?.started_at]);

    if (!task || task.status !== "in_progress" || !task.started_at)
        return null;

    const elapsed = Math.floor(
        (Date.now() - new Date(task.started_at).getTime()) / 60_000
    );
    const h = Math.floor(elapsed / 60);
    const m = elapsed % 60;
    return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

// ── Badges ────────────────────────────────────────────────────────────────────

function PriorityBadge({ priority }: { priority: string }) {
    const cfgs: Record<string, string> = {
        urgent: "bg-danger-light text-danger border border-danger/30",
        high: "bg-warning-light text-warning-dark border border-warning/30",
        normal: "bg-brand-50 text-brand-700 border border-brand-200",
        low: "bg-surface-100 text-surface-500 border border-surface-200",
    };
    const labels: Record<string, string> = {
        urgent: "🔴 Urgent",
        high: "🟠 High",
        normal: "Normal",
        low: "Low",
    };
    return (
        <span
            className={clsx(
                "text-2xs font-bold px-2 py-0.5 rounded-full uppercase tracking-wide",
                cfgs[priority] ?? cfgs.normal
            )}
        >
            {labels[priority] ?? priority}
        </span>
    );
}

function DueBadge({ date }: { date: string }) {
    const d = daysUntil(date);
    if (d < 0)
        return (
            <span className="text-2xs font-semibold text-danger">
                Overdue {Math.abs(d)}d
            </span>
        );
    if (d === 0)
        return (
            <span className="text-2xs font-semibold text-warning-dark">
                Due today
            </span>
        );
    if (d <= 2)
        return (
            <span className="text-2xs font-semibold text-warning-dark">
                Due in {d}d
            </span>
        );
    return (
        <span className="text-2xs text-surface-400">
            {new Date(date).toLocaleDateString("en-KE", {
                dateStyle: "medium",
            })}
        </span>
    );
}

// ── Right drawer ──────────────────────────────────────────────────────────────

function RightDrawer({
    open,
    onClose,
    title,
    children,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    children: React.ReactNode;
}) {
    useEffect(() => {
        if (open) document.body.style.overflow = "hidden";
        else document.body.style.overflow = "";
        return () => {
            document.body.style.overflow = "";
        };
    }, [open]);

    return (
        <div
            className={clsx(
                "fixed inset-0 z-50 flex justify-end transition-all duration-200",
                open ? "pointer-events-auto" : "pointer-events-none"
            )}
        >
            <div
                className={clsx(
                    "absolute inset-0 bg-black/40 transition-opacity duration-200",
                    open ? "opacity-100" : "opacity-0"
                )}
                onClick={onClose}
            />
            <div
                className={clsx(
                    "relative w-[85vw] max-w-sm bg-white h-full flex flex-col shadow-2xl transition-transform duration-200",
                    open ? "translate-x-0" : "translate-x-full"
                )}
            >
                <div className="flex items-center gap-3 px-4 py-3.5 border-b border-surface-100 shrink-0">
                    <button
                        onClick={onClose}
                        className="tap-target w-8 h-8 flex items-center justify-center rounded-xl bg-surface-100 text-surface-600 active:bg-surface-200 transition-colors"
                        aria-label="Close"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <span className="font-bold text-sm text-surface-900 flex-1">
                        {title}
                    </span>
                </div>
                <div className="overflow-y-auto flex-1 pb-safe">
                    {children}
                </div>
            </div>
        </div>
    );
}

// ── Note drawer ───────────────────────────────────────────────────────────────

function NoteDrawer({
    task,
    open,
    onClose,
    onSaved,
}: {
    task: MyTask;
    open: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const toast = useToastStore();
    const [text, setText] = useState("");
    const [saving, setSaving] = useState(false);

    const handleSave = async () => {
        if (!text.trim()) return;
        setSaving(true);
        try {
            await post(
                `/v1/production-orders/${task.production_order.id}/note`,
                { note: text.trim() }
            );
            toast.success("Note saved");
            setText("");
            onSaved();
            onClose();
        } catch {
            toast.error("Failed to save note");
        } finally {
            setSaving(false);
        }
    };

    return (
        <RightDrawer open={open} onClose={onClose} title="Add note">
            <div className="p-4 space-y-3">
                <p className="text-xs text-surface-500">
                    Note will be attached to{" "}
                    <span className="font-semibold text-surface-700">
                        {task.production_order.order_number}
                    </span>
                    .
                </p>
                <textarea
                    className="input resize-none w-full"
                    rows={6}
                    placeholder="e.g. Adjusted sleeve length by 0.5cm…"
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    autoFocus
                />
                <button
                    onClick={handleSave}
                    disabled={saving || !text.trim()}
                    className="w-full py-3.5 rounded-xl bg-brand-500 text-white font-bold text-sm disabled:opacity-50 active:bg-brand-600 transition-colors tap-target flex items-center justify-center gap-2"
                >
                    {saving ? (
                        <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    ) : (
                        <>
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            Save note
                        </>
                    )}
                </button>
            </div>
        </RightDrawer>
    );
}

// ── Specs drawer ──────────────────────────────────────────────────────────────

function SpecsDrawer({
    task,
    open,
    onClose,
}: {
    task: MyTask;
    open: boolean;
    onClose: () => void;
}) {
    const order = task.production_order;
    const hasMeasurements =
        order.measurements && Object.keys(order.measurements).length > 0;
    const hasSpecs =
        order.specifications && Object.keys(order.specifications).length > 0;
    const hasPrefs =
        order.customer_preferences &&
        Object.keys(order.customer_preferences).length > 0;
    const hasMaterials =
        order.material_allocations && order.material_allocations.length > 0;

    return (
        <RightDrawer open={open} onClose={onClose} title="Order specs">
            <div className="p-4 space-y-5">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-mono text-xs text-surface-400">
                        {order.order_number}
                    </span>
                    <PriorityBadge priority={order.priority} />
                    <DueBadge date={order.due_date} />
                    <span className="text-xs text-surface-400">
                        Qty: {order.quantity}
                    </span>
                </div>

                {task.stage.description && (
                    <div className="rounded-xl bg-brand-50 border border-brand-100 p-3">
                        <p className="text-2xs font-bold text-brand-600 uppercase tracking-widest mb-1">
                            Stage notes
                        </p>
                        <p className="text-xs text-brand-900">
                            {task.stage.description}
                        </p>
                    </div>
                )}

                {hasMeasurements && (
                    <div>
                        <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">
                            Measurements
                        </p>
                        <div className="grid grid-cols-2 gap-2">
                            {Object.entries(order.measurements!).map(
                                ([k, v]) => (
                                    <div
                                        key={k}
                                        className="rounded-xl bg-surface-50 border border-surface-100 px-3 py-2.5 flex flex-col gap-0.5"
                                    >
                                        <span className="text-2xs text-surface-400 uppercase tracking-wide">
                                            {k}
                                        </span>
                                        <span className="text-xl font-bold text-surface-900 leading-none">
                                            {v}
                                        </span>
                                    </div>
                                )
                            )}
                        </div>
                    </div>
                )}

                {hasSpecs && (
                    <div>
                        <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">
                            Specifications
                        </p>
                        <div className="card p-3 space-y-2">
                            {Object.entries(order.specifications!).map(
                                ([k, v]) => (
                                    <div
                                        key={k}
                                        className="flex justify-between gap-2 border-b border-surface-50 last:border-0 pb-2 last:pb-0"
                                    >
                                        <span className="text-xs text-surface-500">
                                            {k}
                                        </span>
                                        <span className="text-xs font-semibold text-surface-900 text-right">
                                            {v}
                                        </span>
                                    </div>
                                )
                            )}
                        </div>
                    </div>
                )}

                {hasPrefs && (
                    <div>
                        <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">
                            Customer preferences
                        </p>
                        <div className="card p-3 space-y-2">
                            {Object.entries(order.customer_preferences!).map(
                                ([k, v]) => (
                                    <div
                                        key={k}
                                        className="flex justify-between gap-2 border-b border-surface-50 last:border-0 pb-2 last:pb-0"
                                    >
                                        <span className="text-xs text-surface-500">
                                            {k}
                                        </span>
                                        <span className="text-xs font-semibold text-surface-900 text-right">
                                            {v}
                                        </span>
                                    </div>
                                )
                            )}
                        </div>
                    </div>
                )}

                {hasMaterials && (
                    <div>
                        <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">
                            Materials allocated
                        </p>
                        <div className="card p-3 space-y-2">
                            {order.material_allocations!.map((a, i) => (
                                <div
                                    key={i}
                                    className="flex justify-between gap-2 border-b border-surface-50 last:border-0 pb-2 last:pb-0"
                                >
                                    <span className="text-xs text-surface-500">
                                        {a.material.name}
                                    </span>
                                    <span className="text-xs font-semibold text-surface-900">
                                        {a.quantity_required}{" "}
                                        {a.material.unit_of_measure}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {!hasMeasurements &&
                    !hasSpecs &&
                    !hasPrefs &&
                    !hasMaterials && (
                        <p className="text-sm text-surface-400 text-center py-6">
                            No specifications recorded for this order.
                        </p>
                    )}
            </div>
        </RightDrawer>
    );
}

// ── Completion screen ─────────────────────────────────────────────────────────

function CompletionScreen({
    completedTask,
    nextGroup,
    onStartNext,
    onBackToQueue,
}: {
    completedTask: MyTask;
    nextGroup: OrderGroup | null;
    onStartNext: (group: OrderGroup) => void;
    onBackToQueue: () => void;
}) {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        requestAnimationFrame(() => setVisible(true));
        navigator.vibrate?.([50, 30, 100, 30, 200]);
    }, []);

    const nextTask = nextGroup?.activeTask ?? null;

    return (
        <div
            className={clsx(
                "flex flex-col items-center transition-all duration-300",
                visible
                    ? "opacity-100 translate-y-0"
                    : "opacity-0 translate-y-4"
            )}
        >
            <div className="flex flex-col items-center pt-10 pb-6">
                <div className="w-20 h-20 rounded-full bg-success-light border-2 border-success flex items-center justify-center mb-4">
                    <svg className="w-10 h-10 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <p className="text-xl font-bold text-surface-900">
                    Task complete!
                </p>
                <p className="text-xs text-surface-400 mt-1">
                    {getProductName(completedTask)} ·{" "}
                    {completedTask.stage.name}
                </p>
            </div>

            <div className="w-full px-4 space-y-3">
                {nextGroup && nextTask ? (
                    <>
                        <div className="card p-4 bg-brand-50 border-brand-100 border">
                            <p className="text-2xs font-bold text-brand-500 uppercase tracking-widest mb-2">
                                Up next
                            </p>
                            <div className="flex items-center gap-3">
                                {nextGroup.imageUrl ? (
                                    <img
                                        src={nextGroup.imageUrl}
                                        alt={nextGroup.productName}
                                        className="w-12 h-12 rounded-xl object-cover border border-brand-100 shrink-0"
                                    />
                                ) : (
                                    <div className="w-12 h-12 rounded-xl bg-brand-100 flex items-center justify-center shrink-0">
                                        <svg className="w-6 h-6 text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
                                            <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                                        </svg>
                                    </div>
                                )}
                                <div className="flex-1 min-w-0">
                                    <p className="font-bold text-surface-900 text-sm truncate">
                                        {nextGroup.productName}
                                    </p>
                                    <div className="flex items-center gap-2 mt-1 flex-wrap">
                                        <span className="text-xs font-medium text-brand-600">
                                            {nextTask.stage.name}
                                        </span>
                                        <DueBadge date={nextGroup.dueDate} />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button
                            onClick={() => onStartNext(nextGroup)}
                            className="w-full py-4 rounded-xl bg-brand-500 text-white font-bold text-base active:bg-brand-600 transition-colors tap-target flex items-center justify-center gap-2"
                        >
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                            </svg>
                            Start next task
                        </button>
                    </>
                ) : (
                    <div className="card p-6 text-center">
                        <p className="text-2xl mb-2">🎉</p>
                        <p className="font-bold text-surface-900">
                            All tasks done!
                        </p>
                        <p className="text-xs text-surface-400 mt-1">
                            You've cleared your queue.
                        </p>
                    </div>
                )}

                <button
                    onClick={onBackToQueue}
                    className="w-full py-3 rounded-xl border border-surface-200 text-surface-600 font-medium text-sm active:bg-surface-50 transition-colors tap-target flex items-center justify-center gap-2"
                >
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 10h16M4 14h12M4 18h8" />
                    </svg>
                    Back to queue
                </button>
            </div>
        </div>
    );
}

// ── Focus card ────────────────────────────────────────────────────────────────
// Shows the order summary at the top, then a stage checklist for all tasks
// in this order. The active task is highlighted with its action button inline.

function FocusCard({
    group,
    onAction,
    isActing,
    onNoteOpen,
    onSpecsOpen,
}: {
    group: OrderGroup;
    onAction: (task: MyTask, action: "start" | "complete" | "pause") => void;
    isActing: boolean;
    onNoteOpen: () => void;
    onSpecsOpen: () => void;
}) {
    const elapsed = useElapsedTimer(group.activeTask);
    const order = group.activeTask?.production_order ?? null;
    const isOverdue =
        daysUntil(group.dueDate) < 0 &&
        group.completedCount < group.totalCount;

    const borderCls = isOverdue
        ? "border-danger"
        : group.activeTask?.status === "in_progress"
        ? "border-brand-400"
        : group.activeTask?.status === "paused"
        ? "border-warning"
        : "border-surface-200";

    const bgCls = isOverdue
        ? "bg-danger-light/20"
        : group.activeTask?.status === "in_progress"
        ? "bg-brand-50/60"
        : group.activeTask?.status === "paused"
        ? "bg-warning-light/40"
        : "bg-white";

    const hasMeasurements =
        order?.measurements && Object.keys(order.measurements).length > 0;

    return (
        <div className="space-y-3">
            {/* ── Order summary card ── */}
            <div className={clsx("rounded-2xl border-2 overflow-hidden", borderCls, bgCls)}>
                <div className="p-4 flex gap-3 items-start">
                    {group.imageUrl ? (
                        <img
                            src={group.imageUrl}
                            alt={group.productName}
                            className="w-14 h-14 rounded-xl object-cover border border-surface-100 shrink-0"
                        />
                    ) : (
                        <div className="w-14 h-14 rounded-xl bg-surface-100 flex items-center justify-center shrink-0 text-surface-300">
                            <svg className="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
                                <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                            </svg>
                        </div>
                    )}

                    <div className="flex-1 min-w-0">
                        <p className="font-bold text-surface-900 text-base leading-snug truncate">
                            {group.productName}
                        </p>
                        <p className="font-mono text-2xs text-surface-400 mt-0.5">
                            {group.orderNumber}
                        </p>
                        {group.customerName && (
                            <p className="text-2xs text-surface-400 mt-0.5">
                                <span className="text-surface-300">for</span>{" "}
                                {group.customerName}
                            </p>
                        )}
                        <div className="flex items-center gap-2 flex-wrap mt-1.5">
                            <PriorityBadge priority={group.priority} />
                            <DueBadge date={group.dueDate} />
                            <span className="text-2xs text-surface-400">
                                Qty {group.quantity}
                            </span>
                        </div>
                    </div>

                    {/* Timer */}
                    {elapsed && (
                        <div className="shrink-0 flex flex-col items-end">
                            <span className="text-lg font-bold font-mono text-brand-600 leading-none tabular-nums">
                                {elapsed}
                            </span>
                            <span className="text-2xs text-surface-400 mt-0.5">
                                on task
                            </span>
                        </div>
                    )}
                </div>

                {/* Order notes alert */}
                {order?.notes && (
                    <div className="mx-4 mb-3 flex gap-2 items-start bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
                        <svg className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                        <p className="text-xs text-amber-800 leading-relaxed">
                            {order.notes}
                        </p>
                    </div>
                )}

                {/* Measurements grid */}
                {hasMeasurements && (
                    <div className="mx-4 mb-4">
                        <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2 flex items-center gap-1.5">
                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21 6.5H3a1 1 0 00-1 1v9a1 1 0 001 1h18a1 1 0 001-1v-9a1 1 0 00-1-1z" />
                                <path strokeLinecap="round" strokeLinejoin="round" d="M7 6.5v4M12 6.5v6M17 6.5v4" />
                            </svg>
                            Measurements
                        </p>
                        <div className="grid grid-cols-3 gap-1.5">
                            {Object.entries(order!.measurements!).map(
                                ([k, v]) => (
                                    <div
                                        key={k}
                                        className="bg-white/80 border border-surface-100 rounded-xl px-2 py-2 flex flex-col gap-0.5"
                                    >
                                        <span className="text-2xs text-surface-400 truncate">
                                            {k}
                                        </span>
                                        <span className="text-base font-bold text-surface-900 leading-none">
                                            {v}
                                        </span>
                                    </div>
                                )
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* ── Stage checklist ── */}
            <div className="card overflow-hidden">
                {/* Progress header */}
                <div className="px-3 pt-3 pb-2">
                    <div className="flex items-center justify-between mb-1.5">
                        <span className="text-2xs font-bold text-surface-400 uppercase tracking-widest">
                            Stages
                        </span>
                        <span className="text-2xs font-semibold text-surface-500">
                            {group.completedCount}/{group.totalCount} done
                        </span>
                    </div>
                    {/* Progress bar */}
                    <div className="h-1 w-full bg-surface-100 rounded-full overflow-hidden">
                        <div
                            className="h-full bg-brand-500 rounded-full transition-all duration-500"
                            style={{
                                width: `${group.totalCount > 0
                                    ? (group.completedCount / group.totalCount) * 100
                                    : 0}%`,
                            }}
                        />
                    </div>
                </div>

                {/* Task rows */}
                <div className="divide-y divide-surface-50">
                    {group.tasks.map((task) => {
                        const isActive = task.id === group.activeTask?.id;
                        const isDone = task.status === "completed";
                        const isPaused = task.status === "paused";
                        const isInProgress = task.status === "in_progress";
                        const canStart =
                            task.status === "pending" ||
                            task.status === "paused";
                        const canComplete = task.status === "in_progress";

                        return (
                            <div
                                key={task.id}
                                className={clsx(
                                    "flex items-center gap-3 px-3 py-2.5 transition-colors",
                                    isActive && !isDone
                                        ? "bg-brand-50/60"
                                        : ""
                                )}
                            >
                                {/* Stage status icon */}
                                {isDone ? (
                                    <div className="w-6 h-6 rounded-full bg-success-light border border-success flex items-center justify-center shrink-0">
                                        <svg className="w-3 h-3 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    </div>
                                ) : isInProgress ? (
                                    <div className="w-6 h-6 rounded-full border-2 border-brand-500 bg-brand-50 flex items-center justify-center shrink-0">
                                        <div className="w-2 h-2 rounded-full bg-brand-500 animate-pulse" />
                                    </div>
                                ) : isPaused ? (
                                    <div className="w-6 h-6 rounded-full border-2 border-warning bg-warning-light/40 flex items-center justify-center shrink-0">
                                        <div className="w-2 h-2 rounded-full bg-warning" />
                                    </div>
                                ) : (
                                    <div className="w-6 h-6 rounded-full border-2 border-surface-200 bg-surface-50 shrink-0" />
                                )}

                                {/* Stage name */}
                                <span
                                    className={clsx(
                                        "flex-1 text-sm",
                                        isDone
                                            ? "line-through text-surface-300"
                                            : isActive
                                            ? "font-semibold text-surface-900"
                                            : "text-surface-500"
                                    )}
                                >
                                    {task.stage.name}
                                </span>

                                {/* Inline action for the active task */}
                                {isActive && !isDone && (
                                    <div onClick={(e) => e.stopPropagation()}>
                                        {canStart && (
                                            <button
                                                onClick={() => {
                                                    navigator.vibrate?.(40);
                                                    onAction(task, "start");
                                                }}
                                                disabled={isActing}
                                                className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-brand-500 text-white text-xs font-bold active:bg-brand-600 transition-colors disabled:opacity-50"
                                            >
                                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                                                </svg>
                                                {task.status === "paused"
                                                    ? "Resume"
                                                    : "Start"}
                                            </button>
                                        )}
                                        {canComplete && (
                                            <button
                                                onClick={() => {
                                                    navigator.vibrate?.([40, 30, 80]);
                                                    onAction(task, "complete");
                                                }}
                                                disabled={isActing}
                                                className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-success text-white text-xs font-bold active:bg-green-700 transition-colors disabled:opacity-50"
                                            >
                                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                </svg>
                                                Done
                                            </button>
                                        )}
                                    </div>
                                )}

                                {/* Completed time */}
                                {isDone && task.completed_at && (
                                    <span className="text-2xs text-surface-300 font-mono shrink-0">
                                        {new Date(
                                            task.completed_at
                                        ).toLocaleTimeString("en-KE", {
                                            timeStyle: "short",
                                        })}
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* Note / Specs shortcuts at the bottom of the card */}
                <div className="flex border-t border-surface-100">
                    <button
                        onClick={onNoteOpen}
                        className="flex-1 flex items-center justify-center gap-1.5 py-2.5 text-xs font-semibold text-surface-500 active:bg-surface-50 transition-colors border-r border-surface-100"
                    >
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                        </svg>
                        Add note
                    </button>
                    <button
                        onClick={onSpecsOpen}
                        className="flex-1 flex items-center justify-center gap-1.5 py-2.5 text-xs font-semibold text-surface-500 active:bg-surface-50 transition-colors"
                    >
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" />
                        </svg>
                        View specs
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── Queue order group ─────────────────────────────────────────────────────────
// Collapsible group header + indented task rows for the Queue tab.

function QueueOrderGroup({
    group,
    focusedOrderId,
    onFocusTask,
    onQuickAction,
    isActing,
    defaultOpen,
}: {
    group: OrderGroup;
    focusedOrderId: number | null;
    onFocusTask: (task: MyTask) => void;
    onQuickAction: (task: MyTask, action: "start" | "complete" | "pause") => void;
    isActing: boolean;
    defaultOpen: boolean;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const allDone = group.completedCount === group.totalCount;
    const isOverdue =
        daysUntil(group.dueDate) < 0 && !allDone;
    const isFocusedOrder = focusedOrderId === group.orderId;

    const progressPct =
        group.totalCount > 0
            ? (group.completedCount / group.totalCount) * 100
            : 0;

    return (
        <div
            className={clsx(
                "rounded-2xl border overflow-hidden transition-colors",
                isFocusedOrder
                    ? "border-brand-300 bg-brand-50/30"
                    : isOverdue
                    ? "border-danger/30 bg-danger-light/10"
                    : "border-surface-200 bg-white"
            )}
        >
            {/* Group header — tap to expand/collapse */}
            <button
                className="w-full flex items-center gap-3 px-3 py-3 text-left"
                onClick={() => setOpen((o) => !o)}
            >
                {/* Thumbnail */}
                {group.imageUrl ? (
                    <img
                        src={group.imageUrl}
                        alt={group.productName}
                        className="w-10 h-10 rounded-lg object-cover border border-surface-100 shrink-0"
                    />
                ) : (
                    <div className="w-10 h-10 rounded-lg bg-surface-100 flex items-center justify-center shrink-0 text-surface-300">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
                            <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                        </svg>
                    </div>
                )}

                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-sm font-semibold text-surface-900 truncate">
                            {group.productName}
                        </span>
                        {isFocusedOrder && (
                            <span className="text-2xs font-bold text-brand-600 bg-brand-100 px-1.5 py-0.5 rounded-full">
                                Active
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                        <span className="font-mono text-2xs text-surface-400">
                            {group.orderNumber}
                        </span>
                        <DueBadge date={group.dueDate} />
                    </div>
                    {/* Mini progress bar */}
                    <div className="flex items-center gap-2 mt-1.5">
                        <div className="flex-1 h-1 bg-surface-100 rounded-full overflow-hidden">
                            <div
                                className={clsx(
                                    "h-full rounded-full transition-all duration-500",
                                    allDone ? "bg-success" : "bg-brand-400"
                                )}
                                style={{ width: `${progressPct}%` }}
                            />
                        </div>
                        <span className="text-2xs text-surface-400 shrink-0">
                            {group.completedCount}/{group.totalCount}
                        </span>
                    </div>
                </div>

                {/* Chevron */}
                <svg
                    className={clsx(
                        "w-4 h-4 text-surface-300 shrink-0 transition-transform duration-200",
                        open ? "rotate-180" : ""
                    )}
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {/* Task rows (collapsible) */}
            {open && (
                <div className="border-t border-surface-100 divide-y divide-surface-50">
                    {group.tasks.map((task) => {
                        const isDone = task.status === "completed";
                        const isInProgress = task.status === "in_progress";
                        const isPaused = task.status === "paused";
                        const canStart =
                            task.status === "pending" ||
                            task.status === "paused";
                        const canComplete = task.status === "in_progress";

                        return (
                            <div
                                key={task.id}
                                className={clsx(
                                    "flex items-center gap-3 pl-6 pr-3 py-2.5 cursor-pointer transition-colors",
                                    isInProgress && "bg-brand-50/40",
                                    isDone && "opacity-50"
                                )}
                                onClick={() => onFocusTask(task)}
                            >
                                {/* Status dot */}
                                {isDone ? (
                                    <div className="w-5 h-5 rounded-full bg-success-light border border-success flex items-center justify-center shrink-0">
                                        <svg className="w-2.5 h-2.5 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    </div>
                                ) : isInProgress ? (
                                    <div className="w-5 h-5 rounded-full border-2 border-brand-500 bg-brand-50 flex items-center justify-center shrink-0">
                                        <div className="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse" />
                                    </div>
                                ) : isPaused ? (
                                    <div className="w-5 h-5 rounded-full border-2 border-warning bg-warning-light/40 shrink-0" />
                                ) : (
                                    <div className="w-5 h-5 rounded-full border-2 border-surface-200 shrink-0" />
                                )}

                                <span
                                    className={clsx(
                                        "flex-1 text-xs",
                                        isDone
                                            ? "line-through text-surface-300"
                                            : isInProgress
                                            ? "font-semibold text-surface-800"
                                            : "text-surface-600"
                                    )}
                                >
                                    {task.stage.name}
                                </span>

                                <div
                                    className="shrink-0"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    {canStart && (
                                        <button
                                            onClick={() => {
                                                navigator.vibrate?.(40);
                                                onQuickAction(task, "start");
                                            }}
                                            disabled={isActing}
                                            className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-brand-500 text-white text-xs font-bold active:bg-brand-600 transition-colors disabled:opacity-50"
                                        >
                                            {task.status === "paused"
                                                ? "Resume"
                                                : "Start"}
                                        </button>
                                    )}
                                    {canComplete && (
                                        <button
                                            onClick={() => {
                                                navigator.vibrate?.([40, 30, 80]);
                                                onQuickAction(task, "complete");
                                            }}
                                            disabled={isActing}
                                            className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-success text-white text-xs font-bold active:bg-green-700 transition-colors disabled:opacity-50"
                                        >
                                            Done
                                        </button>
                                    )}
                                    {isDone && task.completed_at && (
                                        <span className="text-2xs text-surface-300 font-mono">
                                            {new Date(
                                                task.completed_at
                                            ).toLocaleTimeString("en-KE", {
                                                timeStyle: "short",
                                            })}
                                        </span>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function TailorWorkspacePage() {
    const toast = useToastStore();
    const qc = useQueryClient();

    const [activeTab, setActiveTab] = useState<TabId>("focus");
    // focusIndex now refers to an index into activeGroups (order groups)
    const [focusIndex, setFocusIndex] = useState(0);
    const [isOnline, setIsOnline] = useState(navigator.onLine);
    const [queueFilter, setQueueFilter] = useState<"active" | "all">("active");
    const [noteDrawerOpen, setNoteDrawerOpen] = useState(false);
    const [specsDrawerOpen, setSpecsDrawerOpen] = useState(false);
    const [completedTask, setCompletedTask] = useState<MyTask | null>(null);
    const [showCompletion, setShowCompletion] = useState(false);

    useEffect(() => {
        const on = () => setIsOnline(true);
        const off = () => setIsOnline(false);
        window.addEventListener("online", on);
        window.addEventListener("offline", off);
        return () => {
            window.removeEventListener("online", on);
            window.removeEventListener("offline", off);
        };
    }, []);

    // ── Data ─────────────────────────────────────────────────────────────────

    const { data: rawTasks = [], isLoading } = useQuery<MyTask[]>({
        queryKey: ["my-tasks", queueFilter],
        queryFn: () =>
            get<MyTask[]>(
                queueFilter === "all"
                    ? "/v1/tailor/tasks?include_completed=true"
                    : "/v1/tailor/tasks"
            ),
        staleTime: 20_000,
        refetchInterval: 30_000,
    });

    // Groups used in the Focus tab: only orders that have at least one active task
    const activeGroups = useMemo(() => {
        const activeTasks = rawTasks.filter(
            (t) => t.status !== "completed" && t.status !== "failed"
        );
        return groupTasksByOrder(activeTasks);
    }, [rawTasks]);

    // Groups used in the Queue tab: all tasks grouped
    const allGroups = useMemo(
        () => groupTasksByOrder(rawTasks),
        [rawTasks]
    );

    const queueGroups = queueFilter === "all" ? allGroups : activeGroups;

    const clampedFocusIndex = Math.min(
        focusIndex,
        Math.max(0, activeGroups.length - 1)
    );
    const focusedGroup = activeGroups[clampedFocusIndex] ?? null;
    // The task to pass to Note/Specs drawers is the active task of the focused group
    const drawerTask = focusedGroup?.activeTask ?? null;

    const { containerRef, isRefreshing, pullProgress } = usePullToRefresh({
        onRefresh: async () => {
            await qc.invalidateQueries({ queryKey: ["my-tasks"] });
        },
    });

    // ── Mutation ─────────────────────────────────────────────────────────────

    const mutation = useMutation({
        mutationFn: ({
            taskId,
            action,
        }: {
            taskId: number;
            action: "start" | "complete" | "pause";
        }) =>
            put(`/v1/tailor/tasks/${taskId}/status`, { action }),
        onSuccess: (_, vars) => {
            if (vars.action === "complete") {
                const done =
                    rawTasks.find((t) => t.id === vars.taskId) ?? null;
                if (done) setCompletedTask(done);
                setShowCompletion(true);
                navigator.vibrate?.([50, 30, 100, 30, 200]);
            } else {
                toast.success(
                    vars.action === "pause" ? "Task paused" : "Task started!"
                );
                if (vars.action === "start") navigator.vibrate?.(40);
            }
            qc.invalidateQueries({ queryKey: ["my-tasks"] });
        },
        onError: async (e: ApiError, vars) => {
            if (!isOnline || e.message?.includes("Network")) {
                try {
                    await queueOfflineTaskUpdate(vars.taskId, vars.action, {
                        action: vars.action,
                    });
                    toast.info(
                        "You're offline – update queued and will sync when reconnected."
                    );
                } catch {
                    toast.error("Failed to queue update. Please try again.");
                }
            } else {
                toast.error(e.message ?? "Something went wrong.");
            }
        },
    });

    const handleAction = useCallback(
        (task: MyTask, action: "start" | "complete" | "pause") => {
            mutation.mutate({ taskId: task.id, action });
        },
        [mutation]
    );

    // ── Navigation ────────────────────────────────────────────────────────────

    const focusGroup = (group: OrderGroup) => {
        const idx = activeGroups.findIndex(
            (g) => g.orderId === group.orderId
        );
        if (idx >= 0) {
            setFocusIndex(idx);
            setActiveTab("focus");
        }
    };

    const focusTaskFromQueue = (task: MyTask) => {
        const idx = activeGroups.findIndex(
            (g) => g.orderId === task.production_order.id
        );
        if (idx >= 0) {
            setFocusIndex(idx);
            setActiveTab("focus");
        }
    };

    const handleStartNext = (group: OrderGroup) => {
        setShowCompletion(false);
        setCompletedTask(null);
        focusGroup(group);
        if (group.activeTask) handleAction(group.activeTask, "start");
    };

    const handleBackToQueue = () => {
        setShowCompletion(false);
        setCompletedTask(null);
        setActiveTab("queue");
    };

    // Next group after completion: first group (excluding completed order) that
    // still has a pending/paused task
    const completedOrderId = completedTask?.production_order.id;
    const nextGroupAfterCompletion =
        activeGroups.find(
            (g) =>
                g.orderId !== completedOrderId &&
                g.activeTask !== null
        ) ?? null;

    // ── Loading ───────────────────────────────────────────────────────────────

    if (isLoading) {
        return (
            <div className="flex items-center justify-center h-64">
                <Spinner size="lg" />
            </div>
        );
    }

    // ── Render ────────────────────────────────────────────────────────────────

    return (
        <div className="flex flex-col h-full animate-fade-in">
            {/* Offline banner */}
            {!isOnline && (
                <div className="mx-4 mb-2 flex items-center gap-2 px-3 py-2 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs font-medium">
                    <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M18.364 5.636a9 9 0 010 12.728M15.536 8.464a5 5 0 010 7.072M8.464 8.464a5 5 0 000 7.072M5.636 5.636a9 9 0 000 12.728" />
                    </svg>
                    Offline – updates will sync when reconnected
                </div>
            )}

            <PullRefreshIndicator
                progress={pullProgress}
                refreshing={isRefreshing}
            />

            {/* Tab bar */}
            <div className="flex border-b border-surface-100 bg-white shrink-0 px-4">
                {(["focus", "queue"] as const).map((tab) => (
                    <button
                        key={tab}
                        onClick={() => setActiveTab(tab)}
                        className={clsx(
                            "relative py-3 px-1 mr-6 text-sm font-semibold transition-colors",
                            activeTab === tab
                                ? "text-brand-600"
                                : "text-surface-400"
                        )}
                    >
                        {tab === "focus" ? "Focus" : "Queue"}
                        {tab === "queue" && activeGroups.length > 0 && (
                            <span className="ml-1.5 text-2xs font-bold bg-brand-500 text-white rounded-full px-1.5 py-0.5">
                                {activeGroups.length}
                            </span>
                        )}
                        {activeTab === tab && (
                            <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-brand-500 rounded-full" />
                        )}
                    </button>
                ))}
            </div>

            {/* Scrollable body */}
            <div
                ref={containerRef}
                className="flex-1 overflow-y-auto scroll-touch overscroll-contain"
            >
                {/* ── COMPLETION SCREEN ──────────────────────────────────── */}
                {showCompletion && completedTask && (
                    <CompletionScreen
                        completedTask={completedTask}
                        nextGroup={nextGroupAfterCompletion}
                        onStartNext={handleStartNext}
                        onBackToQueue={handleBackToQueue}
                    />
                )}

                {/* ── FOCUS TAB ─────────────────────────────────────────── */}
                {!showCompletion && activeTab === "focus" && (
                    <div className="p-4 space-y-4">
                        {focusedGroup ? (
                            <>
                                {/* Order position indicator */}
                                {activeGroups.length > 1 && (
                                    <div className="flex items-center justify-between">
                                        <p className="text-xs text-surface-400">
                                            Order {clampedFocusIndex + 1} of{" "}
                                            {activeGroups.length}
                                        </p>
                                        <div className="flex items-center gap-1">
                                            {activeGroups.map((g, i) => (
                                                <button
                                                    key={g.orderId}
                                                    onClick={() =>
                                                        setFocusIndex(i)
                                                    }
                                                    aria-label={`Order ${i + 1}`}
                                                    style={{
                                                        padding: "6px 3px",
                                                        background: "none",
                                                        border: "none",
                                                        cursor: "pointer",
                                                    }}
                                                >
                                                    <span
                                                        style={{
                                                            display: "block",
                                                            borderRadius:
                                                                "9999px",
                                                            transition:
                                                                "all 0.15s",
                                                            width:
                                                                i ===
                                                                clampedFocusIndex
                                                                    ? "10px"
                                                                    : "4px",
                                                            height: "4px",
                                                            background:
                                                                i ===
                                                                clampedFocusIndex
                                                                    ? "#818cf8"
                                                                    : "#e2e8f0",
                                                        }}
                                                    />
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Focus card (order + stage checklist) */}
                                <FocusCard
                                    group={focusedGroup}
                                    onAction={handleAction}
                                    isActing={mutation.isPending}
                                    onNoteOpen={() => setNoteDrawerOpen(true)}
                                    onSpecsOpen={() =>
                                        setSpecsDrawerOpen(true)
                                    }
                                />

                                {/* Prev / Next order navigation */}
                                {activeGroups.length > 1 && (
                                    <div className="flex items-center justify-between pt-1">
                                        <button
                                            onClick={() =>
                                                setFocusIndex((i) =>
                                                    Math.max(i - 1, 0)
                                                )
                                            }
                                            disabled={
                                                clampedFocusIndex === 0
                                            }
                                            className="flex items-center gap-1.5 text-sm text-surface-500 font-medium disabled:opacity-30 tap-target"
                                        >
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                                            </svg>
                                            Prev order
                                        </button>
                                        <button
                                            onClick={() =>
                                                setFocusIndex((i) =>
                                                    Math.min(
                                                        i + 1,
                                                        activeGroups.length - 1
                                                    )
                                                )
                                            }
                                            disabled={
                                                clampedFocusIndex >=
                                                activeGroups.length - 1
                                            }
                                            className="flex items-center gap-1.5 text-sm text-surface-500 font-medium disabled:opacity-30 tap-target"
                                        >
                                            Next order
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </button>
                                    </div>
                                )}
                            </>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-24 text-surface-400">
                                <div className="w-16 h-16 rounded-2xl bg-surface-100 flex items-center justify-center mb-4">
                                    <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                                    </svg>
                                </div>
                                <p className="text-sm font-medium text-surface-600">
                                    No active tasks
                                </p>
                                <p className="text-xs mt-1">
                                    Pull down to refresh
                                </p>
                            </div>
                        )}
                    </div>
                )}

                {/* ── QUEUE TAB ─────────────────────────────────────────── */}
                {!showCompletion && activeTab === "queue" && (
                    <div className="p-4 space-y-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="page-title">My tasks</h1>
                                <p className="page-subtitle">
                                    {activeGroups.length > 0
                                        ? `${activeGroups.length} order${activeGroups.length > 1 ? "s" : ""} · ${rawTasks.filter((t) => t.status !== "completed").length} tasks`
                                        : "No active tasks"}
                                </p>
                            </div>
                            <div className="flex gap-1 bg-surface-100 p-1 rounded-xl">
                                {(["active", "all"] as const).map((k) => (
                                    <button
                                        key={k}
                                        onClick={() => setQueueFilter(k)}
                                        className={clsx(
                                            "px-3 py-1.5 rounded-lg text-xs font-semibold transition-all tap-target",
                                            queueFilter === k
                                                ? "bg-white text-surface-900 shadow-sm"
                                                : "text-surface-500"
                                        )}
                                    >
                                        {k === "active" ? "Active" : "All"}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {queueGroups.length === 0 ? (
                            <div className="card flex flex-col items-center justify-center py-16 text-surface-400">
                                <p className="text-sm font-medium">
                                    {queueFilter === "active"
                                        ? "No active tasks right now"
                                        : "No tasks assigned yet"}
                                </p>
                                <p className="text-xs mt-1">
                                    Pull down to refresh
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-3 pb-safe">
                                {queueGroups.map((group, i) => (
                                    <QueueOrderGroup
                                        key={group.orderId}
                                        group={group}
                                        focusedOrderId={
                                            focusedGroup?.orderId ?? null
                                        }
                                        onFocusTask={focusTaskFromQueue}
                                        onQuickAction={handleAction}
                                        isActing={mutation.isPending}
                                        defaultOpen={i < 3}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* ── Right drawers ──────────────────────────────────────────── */}
            {drawerTask && (
                <>
                    <NoteDrawer
                        task={drawerTask}
                        open={noteDrawerOpen}
                        onClose={() => setNoteDrawerOpen(false)}
                        onSaved={() =>
                            qc.invalidateQueries({ queryKey: ["my-tasks"] })
                        }
                    />
                    <SpecsDrawer
                        task={drawerTask}
                        open={specsDrawerOpen}
                        onClose={() => setSpecsDrawerOpen(false)}
                    />
                </>
            )}
        </div>
    );
}