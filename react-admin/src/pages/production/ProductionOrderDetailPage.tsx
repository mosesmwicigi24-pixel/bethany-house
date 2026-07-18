import React, { useState, useCallback, useRef, useEffect } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, put, del } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import type { ApiError } from "@/types";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";
import { channelApi, type Channel, type ChannelMessage, type LinkedEntity, type EntitySearchResult } from "@/api/channels";
import { parseBodyToNodes } from "@/pages/comms/CommsHub";
import { commentApi, type MentionUser } from "@/api/comments";
import { subscribeToChannel, getEcho } from "@/lib/echo";
import { useAuthStore } from "@/store/auth.store";

// ── Types ─────────────────────────────────────────────────────────────────────

interface OrderBatch {
    id: number;
    label: string;
    quantity: number;
    attributes?: Record<string, string> | null;
    /** Reference photos; the first one is the batch's thumbnail. */
    images?: string[] | null;
    created_at?: string;
}

interface ProductionOrder {
    id: number;
    order_number: string;
    product_id: number;
    batches?: OrderBatch[];
    product_name: string;
    product_image?: string;
    quantity: number;
    status: string;
    priority: string;
    due_date: string;
    started_at?: string;
    completed_at?: string;
    confirmed_at?: string;
    completion_percentage: number;
    current_stage?: string;
    notes?: string;
    customer_order_id?: number | null;
    customer_order?: { order_number: string; customer_first_name?: string | null; customer_last_name?: string | null; customer_phone?: string | null; customer_email?: string | null } | null;
    customer?: { id: number; first_name: string; last_name: string } | null;
    specifications?: Record<string, string>;
    measurements?: Record<string, string>;
    customer_preferences?: Record<string, string>;
    assignees?: { user_id: number; role_in_order: string; user: { first_name: string; last_name: string } }[];
    tasks: Task[];
    material_allocations?: MaterialAlloc[];
    created_by?: { first_name: string; last_name: string };
    outlet?: { name: string } | null;
}

interface Task {
    id: number;
    production_stage_id: number;
    status: string;
    // Laravel serializes the assignedTo() relationship as "assigned_to",
    // overwriting the FK integer — so this field can be either.
    assigned_to?: number | { id: number; first_name: string; last_name: string };
    estimated_hours?: number;
    actual_hours?: number;
    started_at?: string;
    completed_at?: string;
    notes?: string;
    batch_progress?: { production_order_batch_id: number; quantity_done: number }[];
    /** Snapshot of the order's stage sequence, stamped at seeding — gating runs on this. */
    sequence?: number | null;
    /** Pieces that have passed this stage (cumulative) — batch orders. */
    quantity_done?: number;
    /** Manager has allowed this stage to run in parallel with its predecessors. */
    concurrent_allowed?: boolean;
    stage: { id: number; name: string; slug: string; sort_order: number };
    // Kept for backwards-compat; prefer resolveAssignee() helper below
    assigned_to_user?: { first_name: string; last_name: string };
}

/** Resolves the assignee from a task regardless of serialisation shape */
function resolveAssignee(task: Task): { first_name: string; last_name: string } | null {
    if (task.assigned_to_user) return task.assigned_to_user;
    if (task.assigned_to && typeof task.assigned_to === "object") return task.assigned_to;
    return null;
}

interface MaterialAlloc {
    id: number;
    material_id: number;
    quantity_required: number;
    quantity_allocated: number;
    quantity_used: number;
    material: { id: number; name: string; code: string; unit_of_measure: string };
}

interface ProductionMessage {
    id: number;
    type: "message" | "note" | "system";
    body: string;
    created_at: string;
    user: { id: number; first_name: string; last_name: string; initials: string };
}

interface AuditEntry {
    id: number; event: string; label: string; description: string;
    properties: Record<string, any>; actor_name: string; created_at: string;
}

// ── Config ────────────────────────────────────────────────────────────────────

const STATUS_CFG: Record<string, { label: string; color: string; bg: string; dot: string }> = {
    draft:       { label: "Draft",        color: "text-surface-600",  bg: "bg-surface-100", dot: "bg-surface-400" },
    pending:     { label: "Pending",      color: "text-amber-700",    bg: "bg-amber-50",    dot: "bg-amber-500" },
    in_progress: { label: "In Progress",  color: "text-brand-700",    bg: "bg-brand-50",    dot: "bg-brand-500" },
    on_hold:     { label: "On Hold",      color: "text-orange-700",   bg: "bg-orange-50",   dot: "bg-orange-500" },
    qc_pending:  { label: "QC Pending",   color: "text-purple-700",   bg: "bg-purple-50",   dot: "bg-purple-500" },
    qc_passed:   { label: "QC Passed",    color: "text-emerald-700",  bg: "bg-emerald-50",  dot: "bg-emerald-500" },
    qc_failed:   { label: "QC Failed",    color: "text-red-700",      bg: "bg-red-50",      dot: "bg-red-500" },
    completed:   { label: "Completed",    color: "text-emerald-700",  bg: "bg-emerald-100", dot: "bg-emerald-600" },
    cancelled:   { label: "Cancelled",    color: "text-surface-500",  bg: "bg-surface-100", dot: "bg-surface-400" },
};

const PRIORITY_CFG: Record<string, { label: string; color: string; bg: string; cls: string }> = {
    low:    { label: "Low",    color: "text-surface-500",  bg: "bg-surface-100", cls: "text-surface-400 bg-surface-50 border-surface-200" },
    normal: { label: "Normal", color: "text-blue-700",     bg: "bg-blue-50",     cls: "text-brand-600 bg-brand-50 border-brand-200" },
    high:   { label: "High",   color: "text-orange-700",   bg: "bg-orange-50",   cls: "text-warning-dark bg-warning-light border-warning/30" },
    urgent: { label: "Urgent", color: "text-red-700",      bg: "bg-red-50",      cls: "text-danger bg-danger-light border-danger/30" },
};

const STAGE_ICONS: Record<string, string> = {
    cutting: "cut", stitching: "needle", sewing: "needle",
    finishing: "star", quality_check: "search", embroidery: "needle", pressing: "layers",
};

const DEFECT_TYPES = [
    "Stitching issue", "Wrong measurements", "Fabric defect",
    "Color inconsistency", "Finishing issue", "Missing component", "Other",
];

const DONE_STATUSES = ["completed", "failed", "cancelled", "skipped"];

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtDate = (d?: string | null) =>
    d ? new Date(d).toLocaleDateString("en-KE", { day: "2-digit", month: "short", year: "numeric" }) : "-";
const fmtDateTime = (d?: string | null) =>
    d ? new Date(d).toLocaleString("en-KE", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "-";
const daysUntil = (d: string) => Math.ceil((new Date(d).getTime() - Date.now()) / 86_400_000);
const fmtNum = (n: number) => n.toLocaleString("en-KE", { minimumFractionDigits: 0, maximumFractionDigits: 3 });
const hoursBetween = (from?: string | null, to?: string | null): number | null =>
    from ? ((to ? new Date(to).getTime() : Date.now()) - new Date(from).getTime()) / 3_600_000 : null;
const fmtDuration = (hours: number) => {
    if (hours < 1) return `${Math.max(1, Math.round(hours * 60))}m`;
    if (hours < 24) return `${Math.round(hours * 10) / 10}h`;
    const d = Math.floor(hours / 24);
    const h = Math.round(hours % 24);
    return h > 0 ? `${d}d ${h}h` : `${d}d`;
};

/** Pieces of THIS batch that have passed a stage — completed stages pass everything. */
const batchPassed = (task: Task, batch: OrderBatch): number =>
    GATE_SATISFIED.includes((task.status ?? "").toLowerCase())
        ? batch.quantity
        : Math.min(batch.quantity, task.batch_progress?.find(r => r.production_order_batch_id === batch.id)?.quantity_done ?? 0);

// ── Shared UI atoms ───────────────────────────────────────────────────────────

function ProgressBar({ pct, colorClass = "bg-brand-500" }: { pct: number; colorClass?: string }) {
    return (
        <div className="h-1.5 bg-surface-100 rounded-full overflow-hidden">
            <div className={clsx("h-full rounded-full transition-all duration-500", colorClass)}
                style={{ width: `${Math.min(100, Math.max(0, pct))}%` }} />
        </div>
    );
}

function SectionLabel({ children }: { children: React.ReactNode }) {
    return <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest mb-2">{children}</p>;
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-2 py-1.5 border-b border-surface-50 last:border-0">
            <span className="text-xs text-surface-400 shrink-0">{label}</span>
            <span className="text-xs text-surface-800 font-medium text-right">{value ?? "-"}</span>
        </div>
    );
}

const StageIcon = ({ slug, className = "w-4 h-4" }: { slug?: string; className?: string }) => {
    const s = { className, fill: "none" as const, viewBox: "0 0 24 24", stroke: "currentColor", strokeWidth: 1.75, strokeLinecap: "round" as const, strokeLinejoin: "round" as const };
    const name = slug ? (STAGE_ICONS[slug] ?? "gear") : "gear";
    if (name === "cut")    return <svg {...s}><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg>;
    if (name === "needle") return <svg {...s}><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>;
    if (name === "star")   return <svg {...s}><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>;
    if (name === "search") return <svg {...s}><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>;
    if (name === "layers") return <svg {...s}><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>;
    return <svg {...s}><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>;
};

// ── Edit Order Modal ──────────────────────────────────────────────────────────
// Amendments, not rewrites. Quantity is structural — serials are minted per unit
// and materials sized from it at confirmation — so it is editable only while the
// order is still a draft; the backend enforces the same rule. Everything else
// (priority, due date, notes) amends at any point before completion, and every
// change lands in the order's audit trail as field → from → to.

function EditOrderModal({ order, onClose, onSaved }: { order: ProductionOrder; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const isDraft = order.status === "draft";

    const [quantity, setQuantity] = useState(String(order.quantity));
    const [priority, setPriority] = useState(order.priority ?? "normal");
    const [dueDate, setDueDate]   = useState((order.due_date ?? "").slice(0, 10));
    const [fittingDate, setFittingDate]       = useState(((order as any).fitting_date ?? "").slice(0, 10));
    const [collectionDate, setCollectionDate] = useState(((order as any).collection_date ?? "").slice(0, 10));
    const [notes, setNotes]       = useState((order as any).notes ?? "");

    const mutation = useMutation({
        mutationFn: () => {
            const payload: Record<string, unknown> = {
                priority,
                notes: notes || null,
            };
            if (dueDate) payload.due_date = dueDate;
            payload.fitting_date    = fittingDate || null;
            payload.collection_date = collectionDate || null;
            // Only send quantity when it may legally change — a draft-only field
            // shouldn't even travel from a confirmed order's form.
            if (isDraft && Number(quantity) > 0) payload.quantity = Number(quantity);
            return put(`/v1/admin/production-orders/${order.id}`, payload);
        },
        onSuccess: () => {
            toast.success("Order updated");
            qc.invalidateQueries({ queryKey: ["production-order", order.id] });
            onSaved(); onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title={`Edit Order - ${order.order_number}`} onClose={onClose} size="md">
            <div className="p-5 space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className="text-2xs font-bold text-surface-500 uppercase tracking-wide">Quantity</label>
                        <input type="number" min={1} value={quantity}
                            onChange={e => setQuantity(e.target.value)}
                            disabled={!isDraft}
                            className="input mt-1 w-full text-sm disabled:opacity-50" />
                        {!isDraft && (
                            <p className="text-2xs text-surface-400 mt-1 leading-snug">
                                Locked after confirmation — serials and material requirements were
                                generated from it. Cancel &amp; re-raise, or raise a second order for the difference.
                            </p>
                        )}
                    </div>
                    <div>
                        <label className="text-2xs font-bold text-surface-500 uppercase tracking-wide">Priority</label>
                        <select value={priority} onChange={e => setPriority(e.target.value)}
                            className="input mt-1 w-full text-sm">
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label className="text-2xs font-bold text-surface-500 uppercase tracking-wide">Due date</label>
                    <input type="date" value={dueDate} onChange={e => setDueDate(e.target.value)}
                        className="input mt-1 w-full text-sm" />
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className="text-2xs font-bold text-surface-500 uppercase tracking-wide">Fitting date</label>
                        <input type="date" value={fittingDate} onChange={e => setFittingDate(e.target.value)}
                            className="input mt-1 w-full text-sm" />
                        <p className="text-2xs text-surface-400 mt-1">When the customer comes in to be fitted.</p>
                    </div>
                    <div>
                        <label className="text-2xs font-bold text-surface-500 uppercase tracking-wide">Collection date</label>
                        <input type="date" value={collectionDate} onChange={e => setCollectionDate(e.target.value)}
                            className="input mt-1 w-full text-sm" />
                        <p className="text-2xs text-surface-400 mt-1">When they collect the finished garment.</p>
                    </div>
                </div>
                <div>
                    <label className="text-2xs font-bold text-surface-500 uppercase tracking-wide">Notes</label>
                    <textarea value={notes} onChange={e => setNotes(e.target.value)} rows={3}
                        placeholder="Amendment reason, customer request, spec change…"
                        className="input mt-1 w-full text-sm resize-none" />
                </div>
                <p className="text-2xs text-surface-400">
                    Changes are recorded on the order's audit trail (what changed, from and to).
                </p>
                <div className="flex gap-2 pt-1">
                    <button onClick={onClose} className="btn-secondary flex-1 text-sm">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
                        className="flex-1 px-4 py-2 rounded-xl bg-brand-600 text-white text-sm font-bold hover:bg-brand-700 disabled:opacity-50 transition-colors">
                        {mutation.isPending ? "Saving…" : "Save Changes"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Batches Modal ─────────────────────────────────────────────────────────────
// Split an order into colourway batches: same body, different trim. Quantities
// must sum EXACTLY to the order quantity — the Save button stays disabled until
// the arithmetic holds, with a live remainder readout doing the explaining.
// The server refuses redefinition once counting has started.

function BatchesModal({ order, onClose, onSaved }: { order: ProductionOrder; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [rows, setRows] = useState<{ label: string; quantity: string; attrs: string }[]>(
        (order.batches?.length ?? 0) > 0
            ? order.batches!.map((b) => ({
                label: b.label,
                quantity: String(b.quantity),
                attrs: Object.entries(b.attributes ?? {}).map(([k, v]) => `${k}: ${v}`).join(", "),
            }))
            : [{ label: "", quantity: "", attrs: "" }],
    );

    const sum = rows.reduce((t, r) => t + (Number(r.quantity) || 0), 0);
    const remainder = order.quantity - sum;
    const valid = rows.every((r) => r.label.trim() && Number(r.quantity) > 0) && remainder === 0;

    const parseAttrs = (raw: string): Record<string, string> | null => {
        const out: Record<string, string> = {};
        raw.split(",").map((p) => p.trim()).filter(Boolean).forEach((pair) => {
            const [k, ...rest] = pair.split(":");
            if (k && rest.length) out[k.trim()] = rest.join(":").trim();
        });
        return Object.keys(out).length ? out : null;
    };

    const mutation = useMutation({
        mutationFn: () => put(`/v1/admin/production-orders/${order.id}/batches`, {
            batches: rows.map((r) => ({
                label: r.label.trim(),
                quantity: Number(r.quantity),
                attributes: parseAttrs(r.attrs),
            })),
        }),
        onSuccess: () => {
            toast.success("Batches saved");
            qc.invalidateQueries({ queryKey: ["production-order", order.id] });
            onSaved(); onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title={`Batches - ${order.order_number}`} onClose={onClose} size="md">
            <div className="p-5 space-y-3">
                <p className="text-xs text-surface-500">
                    Same garment, different trim. Give each combination a name, its quantities must
                    add up to the order's <b>{order.quantity}</b> pieces, and tailors then count
                    each batch separately — "10 green done" is visible the moment it happens.
                </p>
                {rows.map((r, i) => (
                    <div key={i} className="rounded-xl border border-surface-200 p-3 space-y-2">
                        <div className="flex items-center gap-2">
                            <input value={r.label} placeholder="Label — e.g. Blue trim"
                                onChange={(e) => setRows((p) => p.map((x, j) => j === i ? { ...x, label: e.target.value } : x))}
                                className="input flex-1 text-sm" />
                            <input type="number" min={1} value={r.quantity} placeholder="Qty"
                                onChange={(e) => setRows((p) => p.map((x, j) => j === i ? { ...x, quantity: e.target.value } : x))}
                                className="input w-20 text-sm text-right" />
                            <button onClick={() => setRows((p) => p.filter((_, j) => j !== i))}
                                disabled={rows.length === 1}
                                className="shrink-0 w-8 h-8 rounded-lg text-surface-400 hover:text-danger hover:bg-danger/10 disabled:opacity-30 transition-colors"
                                aria-label="Remove batch">✕</button>
                        </div>
                        <input value={r.attrs} placeholder="Attributes — e.g. piping: blue, buttons: blue"
                            onChange={(e) => setRows((p) => p.map((x, j) => j === i ? { ...x, attrs: e.target.value } : x))}
                            className="input w-full text-xs" />
                    </div>
                ))}
                <button onClick={() => setRows((p) => [...p, { label: "", quantity: "", attrs: "" }])}
                    className="w-full text-xs font-semibold text-brand-600 border border-dashed border-brand-300 rounded-xl py-2 hover:bg-brand-50 transition-colors">
                    + Add batch
                </button>
                <div className={clsx("rounded-xl px-3 py-2 text-xs font-semibold",
                    remainder === 0 ? "bg-emerald-50 text-emerald-700" : "bg-amber-50 text-amber-700")}>
                    {remainder === 0
                        ? `✓ ${sum} of ${order.quantity} pieces allocated — the arithmetic holds.`
                        : remainder > 0
                            ? `${remainder} piece(s) still unallocated — batches must add up to ${order.quantity}.`
                            : `${-remainder} piece(s) over — batches must add up to ${order.quantity}.`}
                </div>
                <div className="flex gap-2 pt-1">
                    <button onClick={onClose} className="btn-secondary flex-1 text-sm">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={!valid || mutation.isPending}
                        className="flex-1 px-4 py-2 rounded-xl bg-brand-600 text-white text-sm font-bold hover:bg-brand-700 disabled:opacity-50 transition-colors">
                        {mutation.isPending ? "Saving…" : "Save Batches"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Assign Tasks Modal ────────────────────────────────────────────────────────

function AssignModal({ order, onClose, onSaved }: { order: ProductionOrder; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [assignments, setAssignments] = useState<Record<number, string>>({});
    const [hours, setHours] = useState<Record<number, string>>({});
    // Multi-select: tick several stages, pick one person, assign in one go.
    // Deliberately a pure UI-state operation — "assign selected" just fills the
    // same per-row assignments map, so the single proven save path is untouched.
    const [checked, setChecked] = useState<Record<number, boolean>>({});
    const [bulkTailor, setBulkTailor] = useState("");

    const { data: freshData, isLoading: loadingOrder } = useQuery({
        queryKey: ["production-order-assign", order.id],
        queryFn: () => get<{ order: ProductionOrder }>(`/v1/admin/production-orders/${order.id}`),
        staleTime: 0,
    });
    const freshOrder = (freshData as any)?.order as ProductionOrder | undefined;

    const { data: tailorsData } = useQuery({
        queryKey: ["staff-users-list"],
        queryFn: () => get<any>("/v1/admin/users", { params: { exclude_type: "customer", per_page: "100" } }),
        staleTime: 60_000,
    });
    const tailors = tailorsData?.data ?? [];

    const rawTasks = freshOrder?.tasks ?? order.tasks ?? [];
    const activeTasks = [...rawTasks]
        .filter(t => !DONE_STATUSES.includes((t.status ?? "").toLowerCase()))
        .sort((a, b) => (a.sequence ?? a.stage?.sort_order ?? 0) - (b.sequence ?? b.stage?.sort_order ?? 0));

    const mutation = useMutation({
        mutationFn: () => {
            const payload = activeTasks.map(t => {
                const existingId = typeof t.assigned_to === "object" ? (t.assigned_to as any)?.id : t.assigned_to;
                const tailorId = Number(assignments[t.id] ?? existingId ?? "") || undefined;
                return { task_id: t.id, tailor_id: tailorId, estimated_hours: hours[t.id] ? Number(hours[t.id]) : (t.estimated_hours ?? undefined) };
            }).filter(a => a.tailor_id);
            if (!payload.length) return Promise.reject({ message: "Please assign at least one stage." });
            return post(`/v1/admin/production-orders/${order.id}/assign`, { assignments: payload });
        },
        onSuccess: () => {
            toast.success("Assignments saved");
            qc.invalidateQueries({ queryKey: ["production-order", order.id] });
            onSaved(); onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title={`Assign Tasks - ${order.order_number}`} onClose={onClose} size="lg">
            <div className="p-5 space-y-4">
                <p className="text-xs text-surface-500">Assign team members to each stage. You can reassign any stage that hasn't been completed yet.</p>
                {loadingOrder ? (
                    <div className="flex justify-center py-6"><Spinner /></div>
                ) : activeTasks.length === 0 ? (
                    <div className="text-center py-6">
                        <p className="text-sm font-medium text-surface-500">All stages completed</p>
                        <p className="text-xs text-surface-400 mt-1">No pending stages to assign.</p>
                    </div>
                ) : (
                    <div className="space-y-2 overflow-x-auto">
                        {/* Bulk assign: applies one person to every ticked stage. */}
                        <div className="flex items-center gap-2 flex-wrap p-3 rounded-xl bg-brand-50/60 border border-brand-100">
                            <label className="flex items-center gap-2 text-xs font-semibold text-surface-700 shrink-0">
                                <input type="checkbox"
                                    checked={activeTasks.length > 0 && activeTasks.every(t => checked[t.id])}
                                    onChange={e => {
                                        const on = e.target.checked;
                                        setChecked(Object.fromEntries(activeTasks.map(t => [t.id, on])));
                                    }}
                                    className="w-4 h-4 rounded border-surface-300 text-brand-600 focus:ring-brand-400" />
                                Select all
                            </label>
                            <select value={bulkTailor} onChange={e => setBulkTailor(e.target.value)}
                                className="input flex-1 min-w-[160px] text-sm">
                                <option value="">Assign selected to…</option>
                                {tailors.map((t: any) => <option key={t.id} value={t.id}>{t.first_name} {t.last_name}</option>)}
                            </select>
                            <button type="button"
                                disabled={!bulkTailor || !activeTasks.some(t => checked[t.id])}
                                onClick={() => {
                                    setAssignments(prev => {
                                        const next = { ...prev };
                                        activeTasks.forEach(t => { if (checked[t.id]) next[t.id] = bulkTailor; });
                                        return next;
                                    });
                                    setChecked({});
                                    setBulkTailor("");
                                }}
                                className="shrink-0 px-3 py-2 rounded-xl bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 disabled:opacity-40 transition-colors">
                                Apply
                            </button>
                        </div>
                        <div className="grid grid-cols-12 gap-3 px-3 text-2xs font-bold text-surface-400 uppercase tracking-wide min-w-[480px]">
                            <span className="col-span-4">Stage</span>
                            <span className="col-span-5">Assign to</span>
                            <span className="col-span-3">Est. hours</span>
                        </div>
                        {activeTasks.map(task => {
                            const currentId = typeof task.assigned_to === "object" ? (task.assigned_to as any)?.id?.toString() ?? "" : task.assigned_to?.toString() ?? "";
                            return (
                                <div key={task.id} className="grid grid-cols-12 gap-3 items-center p-3 rounded-xl bg-surface-50 border border-surface-100">
                                    <div className="col-span-4 flex items-center gap-2">
                                        <input type="checkbox" checked={!!checked[task.id]}
                                            onChange={e => setChecked(p => ({ ...p, [task.id]: e.target.checked }))}
                                            className="w-4 h-4 rounded border-surface-300 text-brand-600 focus:ring-brand-400 shrink-0" />
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold text-surface-900 flex items-center gap-1.5">
                                            <StageIcon slug={task.stage?.slug} className="w-3.5 h-3.5 text-surface-500" />
                                            {task.stage?.name ?? `Stage ${task.production_stage_id}`}
                                        </p>
                                        <span className={clsx("text-2xs font-medium mt-0.5", task.status === "in_progress" ? "text-brand-600" : "text-surface-400")}>
                                            {task.status === "in_progress" ? "In progress" : "Pending"}
                                        </span>
                                    </div>
                                    </div>
                                    <select value={assignments[task.id] ?? currentId}
                                        onChange={e => setAssignments(p => ({ ...p, [task.id]: e.target.value }))}
                                        className="input col-span-5 text-sm">
                                        <option value="">- Unassigned -</option>
                                        {tailors.map((t: any) => <option key={t.id} value={t.id}>{t.first_name} {t.last_name}</option>)}
                                    </select>
                                    <div className="relative col-span-3">
                                        <input type="number" min={0} step={0.5} placeholder="0"
                                            value={hours[task.id] ?? (task.estimated_hours?.toString() ?? "")}
                                            onChange={e => setHours(p => ({ ...p, [task.id]: e.target.value }))}
                                            className="input text-sm pr-7 w-full" />
                                        <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-2xs text-surface-400">h</span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
                <div className="flex gap-3 pt-1">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || loadingOrder || activeTasks.length === 0}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Saving…" : "Save Assignments"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Issue Materials Modal ─────────────────────────────────────────────────────

function IssueMaterialsModal({ order, onClose, onSaved }: { order: ProductionOrder; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const allocs = order.material_allocations ?? [];
    const [qtys, setQtys] = useState<Record<number, string>>(() => {
        const init: Record<number, string> = {};
        allocs.forEach(a => {
            const rem = Math.max(0, a.quantity_required - a.quantity_allocated);
            if (rem > 0) init[a.id] = String(rem);
        });
        return init;
    });

    const mutation = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${order.id}/materials`, {
            allocations: Object.entries(qtys)
                .filter(([, v]) => Number(v) > 0)
                .map(([id, qty]) => ({ allocation_id: Number(id), quantity: Number(qty) })),
        }),
        onSuccess: () => { toast.success("Materials issued to production"); onSaved(); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (!allocs.length) return (
        <Modal open title="Issue Materials" onClose={onClose}>
            <div className="p-8 text-center text-surface-400 text-sm">No material allocations found for this order.</div>
        </Modal>
    );

    return (
        <Modal open title={`Issue Materials - ${order.order_number}`} onClose={onClose} size="lg">
            <div className="p-5 space-y-4">
                <div className="grid grid-cols-12 gap-2 text-2xs font-bold text-surface-400 uppercase tracking-wide px-2">
                    <span className="col-span-4">Material</span>
                    <span className="col-span-2 text-right">Required</span>
                    <span className="col-span-2 text-right">Allocated</span>
                    <span className="col-span-2 text-right">Remaining</span>
                    <span className="col-span-2 text-right">Issue Now</span>
                </div>
                {allocs.map(a => {
                    const rem = Math.max(0, a.quantity_required - a.quantity_allocated);
                    const pct = a.quantity_required > 0 ? (a.quantity_allocated / a.quantity_required * 100) : 0;
                    return (
                        <div key={a.id} className={clsx("rounded-xl p-3 space-y-2", rem <= 0 ? "bg-emerald-50/40" : "bg-surface-50")}>
                            <div className="grid grid-cols-12 gap-2 items-center text-xs">
                                <div className="col-span-4">
                                    <p className="font-semibold text-surface-900">{a.material.name}</p>
                                    <p className="text-2xs text-surface-400">{a.material.code} · {a.material.unit_of_measure}</p>
                                </div>
                                <span className="col-span-2 text-right tabular-nums text-surface-600">{fmtNum(a.quantity_required)}</span>
                                <span className={clsx("col-span-2 text-right tabular-nums font-semibold", pct >= 100 ? "text-emerald-600" : "text-amber-700")}>{fmtNum(a.quantity_allocated)}</span>
                                <span className="col-span-2 text-right tabular-nums text-surface-600">{fmtNum(rem)}</span>
                                <input type="number" min={0} max={rem} step={0.001}
                                    value={qtys[a.id] ?? "0"} disabled={rem <= 0}
                                    onChange={e => setQtys(p => ({ ...p, [a.id]: e.target.value }))}
                                    className="col-span-2 input text-right text-xs py-1.5 disabled:opacity-40" />
                            </div>
                            <ProgressBar pct={pct} colorClass={pct >= 100 ? "bg-emerald-500" : "bg-brand-500"} />
                        </div>
                    );
                })}
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || Object.values(qtys).every(v => !Number(v))}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Issuing…" : "Issue Materials"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── QC Modal ──────────────────────────────────────────────────────────────────

function QCModal({ order, onClose, onDone }: { order: ProductionOrder; onClose: () => void; onDone: () => void }) {
    const toast = useToastStore();
    const [form, setForm] = useState({ passed: true, passed_quantity: order.quantity, failed_quantity: 0, notes: "", defect_types: [] as string[] });
    const mutation = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${order.id}/qc`, form),
        onSuccess: () => { toast.success(form.passed ? "QC Passed!" : "QC Failed - order on hold"); onDone(); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const toggleDefect = (d: string) => setForm(p => ({ ...p, defect_types: p.defect_types.includes(d) ? p.defect_types.filter(x => x !== d) : [...p.defect_types, d] }));

    return (
        <Modal open title={`Quality Control - ${order.order_number}`} onClose={onClose} size="lg">
            <div className="p-5 space-y-4">
                <div className="flex rounded-xl overflow-hidden border border-surface-200">
                    <button onClick={() => setForm(p => ({ ...p, passed: true }))}
                        className={clsx("flex-1 py-3 text-sm font-semibold flex items-center justify-center gap-2 transition-colors",
                            form.passed ? "bg-emerald-500 text-white" : "bg-white text-surface-500 hover:bg-surface-50")}>
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        Pass
                    </button>
                    <button onClick={() => setForm(p => ({ ...p, passed: false }))}
                        className={clsx("flex-1 py-3 text-sm font-semibold flex items-center justify-center gap-2 transition-colors border-l border-surface-200",
                            !form.passed ? "bg-danger text-white" : "bg-white text-surface-500 hover:bg-surface-50")}>
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        Fail
                    </button>
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label className="label">Passed Quantity</label>
                        <input type="number" min={0} max={order.quantity} value={form.passed_quantity}
                            onChange={e => setForm(p => ({ ...p, passed_quantity: Number(e.target.value) }))} className="input" />
                    </div>
                    <div>
                        <label className="label">Rejected Quantity</label>
                        <input type="number" min={0} value={form.failed_quantity}
                            onChange={e => setForm(p => ({ ...p, failed_quantity: Number(e.target.value) }))} className="input" />
                    </div>
                </div>
                {!form.passed && (
                    <div>
                        <label className="label">Defect Types</label>
                        <div className="flex flex-wrap gap-2">
                            {DEFECT_TYPES.map(d => (
                                <button key={d} onClick={() => toggleDefect(d)}
                                    className={clsx("px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors",
                                        form.defect_types.includes(d) ? "bg-danger border-danger text-white" : "border-surface-200 text-surface-600 hover:border-danger/40")}>
                                    {d}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                <div>
                    <label className="label">Inspector Notes</label>
                    <textarea value={form.notes} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))}
                        rows={3} className="input resize-none"
                        placeholder={form.passed ? "Any observations, measurements verified…" : "Describe the issues found…"} />
                </div>
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
                        className={clsx("flex-1 font-semibold rounded-xl px-4 py-2 text-sm text-white transition-colors", form.passed ? "bg-emerald-500 hover:bg-emerald-600" : "bg-danger hover:bg-danger/90")}>
                        {mutation.isPending ? "Recording…" : form.passed ? "Mark as Passed" : "Mark as Failed"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Complete & Stock Modal ────────────────────────────────────────────────────

function CompleteModal({ order, onClose, onDone }: { order: ProductionOrder; onClose: () => void; onDone: () => void }) {
    const toast = useToastStore();
    const [outletId, setOutletId] = useState("");
    const [finalQty, setFinalQty] = useState(String(order.quantity));
    const { data: outletsData } = useQuery({
        queryKey: ["outlets-list"],
        queryFn: () => get<any>("/v1/admin/outlets"),
        staleTime: 60_000,
    });
    const outlets = outletsData?.data ?? [];
    const mutation = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${order.id}/complete`, {
            outlet_id: outletId ? Number(outletId) : undefined,
            final_quantity: Number(finalQty),
        }),
        onSuccess: () => { toast.success(`${finalQty} unit(s) added to inventory`); onDone(); onClose(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    return (
        <Modal open title="Complete Production & Stock" onClose={onClose}>
            <div className="p-5 space-y-4">
                <div className="bg-emerald-50 border border-emerald-200 rounded-xl p-4">
                    <p className="text-sm font-semibold text-emerald-800">Ready for inventory</p>
                    <p className="text-xs text-emerald-700 mt-0.5">QC has passed. Finished goods will be added to the selected location.</p>
                </div>
                <div>
                    <label className="label">Final Quantity Produced</label>
                    <input type="number" min={1} max={order.quantity} value={finalQty}
                        onChange={e => setFinalQty(e.target.value)} className="input" />
                    <p className="text-2xs text-surface-400 mt-1">Production target was {order.quantity} unit(s)</p>
                </div>
                <div>
                    <label className="label">Add to Outlet / Location</label>
                    <select value={outletId} onChange={e => setOutletId(e.target.value)} className="input">
                        <option value="">Main Warehouse (default)</option>
                        {outlets.map((o: any) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                </div>
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending} className="btn-primary flex-1">
                        {mutation.isPending ? "Processing…" : "Complete & Add to Inventory"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Stages Pipeline ───────────────────────────────────────────────────────────

// Mirrors the backend's gate (ProductionTask::SATISFIED_STATUSES): only these
// statuses release the stages behind them.
const GATE_SATISFIED = ["completed", "skipped"];

// One line of time-truth per stage: how long it's been on the bench (live),
// or how long it took (done), always against the estimate — and an explicit
// flag the moment a live stage runs past its estimate.
function StageTiming({ task }: { task: Task }) {
    const est = task.estimated_hours != null ? Number(task.estimated_hours) : null;
    if (task.started_at && !task.completed_at && !DONE_STATUSES.includes(task.status)) {
        const elapsed = hoursBetween(task.started_at) ?? 0;
        const over = est != null && elapsed > est;
        return (
            <>
                <span>Entered {fmtDate(task.started_at)}</span>
                <span className={clsx("flex items-center gap-1 font-medium", over ? "text-amber-700" : "text-brand-600")}>
                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {fmtDuration(elapsed)} in stage{est != null && !over && <span className="text-surface-400 font-normal"> · est {fmtDuration(est)}</span>}
                </span>
                {over && (
                    <span className="text-2xs font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-1.5 py-0.5">
                        {fmtDuration(elapsed - est!)} over estimate
                    </span>
                )}
            </>
        );
    }
    if (task.completed_at) {
        const took = task.actual_hours != null ? Number(task.actual_hours) : hoursBetween(task.started_at, task.completed_at);
        const over = est != null && took != null && took > est;
        return (
            <>
                <span>Completed {fmtDate(task.completed_at)}</span>
                {took != null && took > 0 && (
                    <span className={clsx(over ? "text-amber-700 font-medium" : undefined)}>
                        took {fmtDuration(took)}{est != null && ` · est ${fmtDuration(est)}`}
                    </span>
                )}
            </>
        );
    }
    return est != null ? (
        <span className="flex items-center gap-1">
            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Est. {fmtDuration(est)}
        </span>
    ) : null;
}

function StagesPipeline({
    tasks,
    orderQuantity = 1,
    batches = [],
    currentUserId,
    onTaskAction,
    taskActionPending,
    canUnlock,
    onUnlock,
}: {
    tasks: Task[];
    orderQuantity?: number;
    batches?: OrderBatch[];
    currentUserId: number | null;
    onTaskAction: (taskId: number, action: "start" | "complete" | "pause") => void;
    taskActionPending: boolean;
    /** production.manage_assignees — the manager who may allow parallel stages */
    canUnlock: boolean;
    onUnlock: (taskId: number, allow: boolean) => void;
}) {
    if (!tasks.length) return (
        <div className="text-center py-12 text-surface-400">
            <svg className="w-10 h-10 mx-auto mb-2 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            <p className="text-sm font-medium">No stages defined</p>
            <p className="text-xs mt-1 text-surface-300">Stages will appear once the order is confirmed.</p>
        </div>
    );

    // ── Derived distribution — nobody typed these numbers ────────────────────
    // passed(k) effective = full quantity for satisfied stages, else counter.
    // held at stage k = passed(k−1) − passed(k); not started = qty − passed(1);
    // finished = passed(last). Only meaningful for batch orders.
    const seq = [...tasks].filter(t => t.sequence != null).sort((a, b) => (a.sequence! - b.sequence!));
    const eff = (t: Task) => GATE_SATISFIED.includes((t.status ?? "").toLowerCase()) ? orderQuantity : Math.min(t.quantity_done ?? 0, orderQuantity);
    const distribution = orderQuantity > 1 && seq.length > 0 ? {
        notStarted: orderQuantity - eff(seq[0]),
        finished:   eff(seq[seq.length - 1]),
        held: seq.map((t, i) => ({
            name:  t.stage?.name ?? `Stage ${t.production_stage_id}`,
            count: (i === 0 ? orderQuantity : eff(seq[i - 1])) - eff(t),
        })).filter(h => h.count > 0),
    } : null;

    // The bottleneck is the stage with the biggest pile waiting on its bench —
    // held(k) = passed(k−1) − passed(k). One amber flag on the single worst
    // offender (≥2 pieces, so a lone straggler doesn't shout); the distribution
    // chips above already carry the full picture.
    const heldByTaskId = new Map<number, number>(
        seq.map((t, i) => [t.id, (i === 0 ? orderQuantity : eff(seq[i - 1])) - eff(t)]));
    const maxHeld = Math.max(0, ...heldByTaskId.values());
    const bottleneckId = maxHeld >= 2
        ? seq.find(t => heldByTaskId.get(t.id) === maxHeld)?.id ?? null
        : null;

    return (
        <div className="space-y-2">
            {distribution && (
                <div className="flex flex-wrap items-center gap-1.5 px-1 pb-1">
                    <span className="text-2xs font-bold px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                        ✓ {distribution.finished} finished
                    </span>
                    {distribution.held.map(h => (
                        <span key={h.name} className="text-2xs font-semibold px-2 py-1 rounded-full bg-brand-50 text-brand-700 border border-brand-200">
                            {h.count} at {h.name}
                        </span>
                    ))}
                    {distribution.notStarted > 0 && (
                        <span className="text-2xs font-semibold px-2 py-1 rounded-full bg-surface-100 text-surface-500 border border-surface-200">
                            {distribution.notStarted} not started
                        </span>
                    )}
                </div>
            )}
            {tasks.map((task, idx) => {
                const assignee = resolveAssignee(task);
                const isDone    = DONE_STATUSES.includes(task.status);
                const isActive  = task.status === "in_progress";
                const isFailed  = task.status === "failed";

                // Resolve the raw assigned user ID to detect ownership
                const assignedId = typeof task.assigned_to === "object"
                    ? (task.assigned_to as any)?.id
                    : task.assigned_to;
                const isMyTask = currentUserId !== null && assignedId === currentUserId &&
                    !DONE_STATUSES.includes(task.status);
                // Client-side mirror of the server's FLOW gate, so the lock is
                // VISIBLE rather than discovered as an error after tapping
                // Start. An earlier stage only blocks while the pipeline has no
                // surplus over this stage's own count; on a tie the latest
                // stage takes the blame. The server remains authoritative.
                let blocker: Task | undefined;
                if (!task.concurrent_allowed && task.sequence != null && !task.started_at) {
                    let minPassed = orderQuantity;
                    for (const t of seq) {
                        if (t.sequence! >= task.sequence) continue;
                        const passed = eff(t);
                        if (passed <= minPassed) { minPassed = passed; blocker = t; }
                    }
                    if (minPassed > (task.quantity_done ?? 0)) blocker = undefined;
                }
                const isBlocked = !!blocker && !isDone && !isActive;

                const canStart    = isMyTask && !isBlocked && (task.status === "pending" || task.status === "paused");
                const canComplete = isMyTask && task.status === "in_progress";
                const canPause    = isMyTask && task.status === "in_progress";

                const statusColor = isDone && !isFailed
                    ? "bg-emerald-50 border-emerald-200"
                    : isActive
                        ? "bg-brand-50 border-brand-200"
                        : isFailed
                            ? "bg-red-50 border-red-200"
                            : isMyTask
                                ? "bg-white border-brand-100"
                                : "bg-surface-50 border-surface-100";

                const dotColor = isDone && !isFailed
                    ? "bg-emerald-500"
                    : isActive
                        ? "bg-brand-500 animate-pulse"
                        : isFailed
                            ? "bg-red-500"
                            : "bg-surface-300";

                const badgeColor = isDone && !isFailed
                    ? "bg-emerald-100 text-emerald-700"
                    : isActive
                        ? "bg-brand-100 text-brand-700"
                        : isFailed
                            ? "bg-red-100 text-red-700"
                            : task.status === "cancelled"
                                ? "bg-surface-100 text-surface-400"
                                : "bg-amber-50 text-amber-700";

                const badgeLabel = task.status === "completed"
                    ? "Completed"
                    : task.status === "in_progress"
                        ? "In Progress"
                        : task.status === "failed"
                            ? "Failed"
                            : task.status === "skipped"
                                ? "Skipped"
                                : task.status === "cancelled"
                                    ? "Cancelled"
                                    : "Pending";

                return (
                    <div key={task.id}
                        className={clsx("rounded-xl border p-3 flex items-start gap-3 transition-colors", statusColor)}>

                        {/* Step number / connector */}
                        <div className="flex flex-col items-center shrink-0">
                            <div className={clsx("w-7 h-7 rounded-full flex items-center justify-center shrink-0", dotColor)}>
                                {isDone && !isFailed ? (
                                    <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                ) : isFailed ? (
                                    <svg className="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                ) : (
                                    <span className="text-white text-2xs font-bold">{idx + 1}</span>
                                )}
                            </div>
                            {idx < tasks.length - 1 && (
                                <div className={clsx("w-0.5 flex-1 mt-1", isDone ? "bg-emerald-300" : "bg-surface-200")}
                                    style={{ minHeight: 12 }} />
                            )}
                        </div>

                        {/* Content */}
                        <div className="flex-1 min-w-0">
                            <div className="flex items-start justify-between gap-2 flex-wrap">
                                <div className="flex items-center gap-1.5">
                                    <StageIcon slug={task.stage?.slug} className="w-3.5 h-3.5 text-surface-500 shrink-0" />
                                    <p className="text-sm font-semibold text-surface-900">
                                        {task.stage?.name ?? `Stage ${task.production_stage_id}`}
                                    </p>
                                    {task.concurrent_allowed && !isDone && (
                                        <span className="text-sky-600 font-bold text-xs" title="May run in parallel — allowed by a production manager">∥</span>
                                    )}
                                    {orderQuantity > 1 && (
                                        <span className="text-2xs font-bold tabular-nums text-surface-500 bg-surface-100 rounded-full px-1.5 py-0.5">
                                            {eff(task)}/{orderQuantity}
                                        </span>
                                    )}
                                    {bottleneckId === task.id && !isDone && (
                                        <span className="text-2xs font-bold px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200"
                                            title="Largest pile in the pipeline is waiting on this bench">
                                            ⚠ {maxHeld} waiting
                                        </span>
                                    )}
                                </div>
                                {/* ONE state per card. Four chips used to compete here
                                    — My task · Pending · Allow parallel · Waiting on X —
                                    wrapping and clipping off-screen on phones. A stage is
                                    in exactly one state; everything else moved to quieter
                                    homes (ownership → assignee line, the manager's unlock
                                    → meta row, parallel → ∥ mark by the name). */}
                                <div className="shrink-0">
                                    {isDone && !isFailed ? (
                                        <span className="text-2xs font-semibold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">✓ Done</span>
                                    ) : isFailed || task.status === "cancelled" ? (
                                        <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", badgeColor)}>{badgeLabel}</span>
                                    ) : isActive ? (
                                        <span className="text-2xs font-semibold px-2 py-0.5 rounded-full bg-brand-100 text-brand-700">In progress</span>
                                    ) : isBlocked ? (
                                        <span className="flex items-center gap-1 text-2xs font-semibold px-2 py-0.5 rounded-full bg-surface-100 text-surface-500"
                                            title={`Locked until "${blocker?.stage?.name}" has pieces ready`}>
                                            <svg className="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                            </svg>
                                            <span className="truncate max-w-[110px] sm:max-w-none">Waiting on {blocker?.stage?.name}</span>
                                        </span>
                                    ) : (
                                        <span className="text-2xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Ready</span>
                                    )}
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-3 mt-1.5 text-xs text-surface-500">
                                {assignee ? (
                                    <span className="flex items-center gap-1">
                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                        </svg>
                                        {assignee.first_name} {assignee.last_name}
                                    </span>
                                ) : (
                                    <span className="text-surface-300 italic text-2xs">Unassigned</span>
                                )}
                                {isMyTask && <span className="text-2xs font-bold text-brand-600">(you)</span>}
                                {canUnlock && !isDone && !task.started_at && (
                                    <button type="button"
                                        onClick={() => onUnlock(task.id, !task.concurrent_allowed)}
                                        className="ml-auto text-2xs font-semibold text-surface-400 hover:text-brand-600 underline decoration-dotted underline-offset-2 transition-colors"
                                        title={task.concurrent_allowed
                                            ? "Re-lock this stage to sequential order"
                                            : "Let this stage run in parallel with earlier stages"}>
                                        {task.concurrent_allowed ? "Re-lock" : "Allow parallel"}
                                    </button>
                                )}
                                <StageTiming task={task} />
                            </div>

                            {/* Per-batch position at this stage — each colourway counted
                                on its own. Hidden on finished stages: every chip would
                                just read full. */}
                            {batches.length > 0 && task.sequence != null && orderQuantity > 1 && !isDone && (
                                <div className="flex flex-wrap items-center gap-1 mt-1.5">
                                    {batches.map(b => {
                                        const p = batchPassed(task, b);
                                        const full = p >= b.quantity;
                                        return (
                                            <span key={b.id}
                                                className={clsx("text-2xs font-semibold px-1.5 py-0.5 rounded-md tabular-nums border",
                                                    full ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                                                    : p > 0 ? "bg-brand-50 text-brand-700 border-brand-200"
                                                    : "bg-surface-50 text-surface-400 border-surface-200")}>
                                                {full ? "✓ " : ""}{b.label} {p}/{b.quantity}
                                            </span>
                                        );
                                    })}
                                </div>
                            )}

                            {task.notes && (
                                <p className="mt-1.5 text-xs text-surface-600 bg-white/70 rounded-lg px-2.5 py-1.5 border border-surface-100 whitespace-pre-wrap">
                                    {task.notes}
                                </p>
                            )}

                            {/* Inline actions — only rendered for the current user's assigned tasks */}
                            {isMyTask && (
                                <div className="flex items-center gap-2 mt-3">
                                    {canStart && (
                                        <button
                                            onClick={() => onTaskAction(task.id, "start")}
                                            disabled={taskActionPending}
                                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand-500 text-white text-xs font-semibold hover:bg-brand-600 transition-colors disabled:opacity-50"
                                        >
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                                            </svg>
                                            {task.status === "paused" ? "Resume" : "Start"}
                                        </button>
                                    )}
                                    {canComplete && (
                                        <button
                                            onClick={() => onTaskAction(task.id, "complete")}
                                            disabled={taskActionPending}
                                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700 transition-colors disabled:opacity-50"
                                        >
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            Mark done
                                        </button>
                                    )}
                                    {canPause && (
                                        <button
                                            onClick={() => onTaskAction(task.id, "pause")}
                                            disabled={taskActionPending}
                                            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface-100 text-surface-600 text-xs font-semibold hover:bg-surface-200 transition-colors disabled:opacity-50"
                                        >
                                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 5.25v13.5m-7.5-13.5v13.5" />
                                            </svg>
                                            Pause
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}


// ── Embedded channel thread (production order context) ───────────────────────
//
// Replaces the old poll-based ActivityLog. Calls POST /channels/context to
// find-or-create the order's context channel on first render, then renders
// messages from the real channel API with real-time updates via Reverb.
// The same thread is visible in CommsHub under the order's channel name.

// ── Mini hooks for mention + entity search ────────────────────────────────────

function useThreadStaffSearch(query: string) {
    return useQuery({
        queryKey: ["thread-staff-search", query],
        queryFn: () => commentApi.searchUsers(query).then(r => r.users),
        staleTime: 15_000,
        placeholderData: [] as MentionUser[],
    });
}

function useThreadEntitySearch(query: string, enabled: boolean) {
    return useQuery({
        queryKey: ["thread-entity-search", query],
        queryFn: () => channelApi.entitySearch(query),
        staleTime: 10_000,
        placeholderData: { results: [] as EntitySearchResult[] },
        enabled: enabled && query.length >= 1,
    });
}

// ── Inline mention popup for the thread composer ──────────────────────────────

function ThreadMentionPopup({ query, onSelect }: { query: string; onSelect: (u: MentionUser) => void }) {
    const { data: users = [] } = useThreadStaffSearch(query);
    if (!users.length) return null;
    return (
        <div className="absolute bottom-full left-0 mb-1 w-56 bg-white rounded-xl border border-surface-200 shadow-xl py-1 z-50 max-h-40 overflow-y-auto">
            {users.map(u => (
                <button key={u.id} onMouseDown={e => { e.preventDefault(); onSelect(u); }}
                    className="w-full flex items-center gap-2.5 px-3 py-2 hover:bg-surface-50 text-left">
                    <div className="w-6 h-6 rounded-full bg-brand-100 flex items-center justify-center text-brand-700 text-2xs font-bold shrink-0">
                        {u.initials}
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs font-semibold text-surface-800 truncate">{u.name}</p>
                        <p className="text-2xs text-surface-400 truncate">{u.email}</p>
                    </div>
                </button>
            ))}
        </div>
    );
}

// ── Inline entity picker popup for the thread composer ────────────────────────

const ENTITY_STATUS_COLOURS: Record<string, string> = {
    pending:      "bg-surface-100 text-surface-500",
    processing:   "bg-brand-50 text-brand-700",
    completed:    "bg-emerald-50 text-emerald-700",
    shipped:      "bg-blue-50 text-blue-700",
    delivered:    "bg-emerald-50 text-emerald-700",
    cancelled:    "bg-red-50 text-red-700",
    draft:        "bg-surface-100 text-surface-500",
    in_progress:  "bg-brand-50 text-brand-700",
    approved:     "bg-emerald-50 text-emerald-700",
    urgent:       "bg-red-50 text-red-700",
};
const entityStatusCls = (s: string) => ENTITY_STATUS_COLOURS[s] ?? "bg-surface-100 text-surface-500";

function ThreadEntityPopup({ query, onSelect, onDismiss }: {
    query: string; onSelect: (e: EntitySearchResult) => void; onDismiss: () => void;
}) {
    const { data } = useThreadEntitySearch(query, true);
    const results  = data?.results ?? [];

    return (
        <div className="absolute bottom-full left-0 mb-1 w-72 bg-white rounded-xl border border-surface-200 shadow-xl py-1 z-50 max-h-60 overflow-y-auto">
            <div className="flex items-center justify-between px-3 pt-1.5 pb-1">
                <p className="text-2xs font-bold text-surface-400 uppercase tracking-widest">Tag order or production</p>
                <button onMouseDown={e => { e.preventDefault(); onDismiss(); }}
                    className="text-surface-300 hover:text-surface-500 p-0.5 rounded">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            {results.length === 0 ? (
                <p className="text-xs text-surface-400 px-3 py-2">{query.length < 1 ? "Type to search orders…" : "No results"}</p>
            ) : results.map(r => (
                <button key={`${r.type}:${r.id}`}
                    onMouseDown={e => { e.preventDefault(); onSelect(r); }}
                    className="w-full flex items-start gap-2.5 px-3 py-2 hover:bg-surface-50 text-left transition-colors">
                    <div className={clsx("mt-0.5 w-6 h-6 rounded-md flex items-center justify-center shrink-0",
                        r.type === "order" ? "bg-brand-50" : "bg-purple-50")}>
                        {r.type === "order" ? (
                            <svg className="w-3.5 h-3.5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        ) : (
                            <svg className="w-3.5 h-3.5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                            </svg>
                        )}
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="text-xs font-bold text-surface-900 font-mono">{r.label}</span>
                            <span className={clsx("text-2xs px-1.5 py-0.5 rounded-full font-medium", entityStatusCls(r.status))}>
                                {r.status.replace(/_/g, " ")}
                            </span>
                        </div>
                        <p className="text-2xs text-surface-500 truncate mt-0.5">{r.subtitle}</p>
                        <p className="text-2xs text-surface-400">{r.meta}</p>
                    </div>
                </button>
            ))}
        </div>
    );
}

// ── Message body renderer (mentions + entity chips) ───────────────────────────

function ThreadMessageBody({ body, linkedEntities, isOwn }: {
    body: string; linkedEntities?: LinkedEntity[]; isOwn: boolean;
}) {
    const navigate = useNavigate();
    // Strip entity tokens from display text; they're shown as chips below
    const cleanBody = body.replace(/#\[([^\]]+)\]\(entity:[^)]+\)/g, "");
    // Render @mention tokens as highlighted pills
    const parts = cleanBody.split(/(@\[[^\]]+\]\(user:\d+\))/g);
    return (
        <div>
            <span className="text-xs leading-relaxed whitespace-pre-wrap break-words">
                {parts.map((p, i) => {
                    const m = p.match(/^@\[([^\]]+)\]\(user:(\d+)\)$/);
                    if (m) return (
                        <span key={i} className={clsx("font-semibold px-1 py-0.5 rounded text-2xs",
                            isOwn ? "bg-white/20 text-white" : "bg-brand-100 text-brand-700")}>
                            @{m[1]}
                        </span>
                    );
                    return <span key={i}>{p}</span>;
                })}
            </span>
            {(linkedEntities ?? []).length > 0 && (
                <div className="flex flex-wrap gap-1 mt-1.5">
                    {linkedEntities!.map((e, i) => (
                        <button key={i}
                            onClick={() => navigate(e.type === "order" ? `/sales/orders/${e.id}` : `/production/orders/${e.id}`)}
                            className={clsx(
                                "inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-xs font-bold whitespace-nowrap border transition-colors",
                                isOwn
                                    ? "bg-white/15 border-white/30 text-white hover:bg-white/25"
                                    : e.type === "order"
                                        ? "bg-brand-50 border-brand-200 text-brand-700 hover:bg-brand-100"
                                        : "bg-purple-50 border-purple-200 text-purple-700 hover:bg-purple-100"
                            )}>
                            {e.type === "order" ? (
                                <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                            ) : (
                                <svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                                </svg>
                            )}
                            {e.label}
                            <svg className="w-2.5 h-2.5 shrink-0 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                            </svg>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

// ── Main thread component ─────────────────────────────────────────────────────

function OrderChannelThread({ orderId }: { orderId: number }) {
    const auth    = useAuthStore();
    const toast   = useToastStore();
    const qc      = useQueryClient();
    const [body, setBody]             = useState("");
    const [channel, setChannel]       = useState<Channel | null>(null);
    const [messages, setMessages]     = useState<ChannelMessage[]>([]);
    const [loadingCh, setLoadingCh]   = useState(true);
    const [sending, setSending]       = useState(false);
    const [mentionQ, setMentionQ]     = useState<string | null>(null);
    const [mentionStart, setMentionStart] = useState(0);
    const [entityQ, setEntityQ]       = useState<string | null>(null);
    const [entityStart, setEntityStart]   = useState(0);
    // Non-member mention guard
    const [pendingMention, setPendingMention] = useState<MentionUser | null>(null);
    const [channelMemberIds, setChannelMemberIds] = useState<Set<number>>(new Set());
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const bottomRef   = useRef<HTMLDivElement>(null);

    const currentUserId = (auth.user as any)?.id;

    const AVATAR_COLORS = ["bg-blue-500","bg-purple-500","bg-pink-500","bg-orange-500","bg-teal-500","bg-indigo-500","bg-rose-500","bg-amber-500"];
    const avatarColor = (id: number) => AVATAR_COLORS[id % AVATAR_COLORS.length];
    const fmtTime = (ts: string) => new Date(ts).toLocaleString("en-KE", { dateStyle: "short", timeStyle: "short" });
    const scrollBottom = (behavior: ScrollBehavior = "smooth") =>
        setTimeout(() => bottomRef.current?.scrollIntoView({ behavior }), 80);

    // 1. Find-or-create context channel.
    //    `cancelled` ensures that if the effect is cleaned up before the request
    //    resolves (Strict Mode double-invoke, tab switch, unmount) the stale
    //    response is silently dropped and loadingCh is resolved by the fresh run.
    //    Duplicate-creation is prevented at the DB level (unique partial index +
    //    UniqueConstraintViolation catch in Channel::findOrCreateContext), so
    //    concurrent requests from Strict Mode are harmless on the backend.
    useEffect(() => {
        let cancelled = false;
        setLoadingCh(true);

        channelApi.findOrCreateContext("production_order", orderId)
            .then(res => {
                if (cancelled) return;
                const ch = res.channel;
                setChannel(ch);
                // Fetch full member list for non-member mention guard
                channelApi.get(ch.id).then(d => {
                    if (cancelled) return;
                    const members = Array.isArray(d.channel.members)
                        ? (d.channel.members as any[]).map((m: any) => m.id as number)
                        : [];
                    setChannelMemberIds(new Set(members));
                }).catch(() => {});
                return channelApi.messages(ch.id).then(r => {
                    if (cancelled) return;
                    setMessages(r.messages);
                    scrollBottom("auto");
                });
            })
            .catch(() => { if (!cancelled) toast.error("Could not load activity thread"); })
            .finally(() => { if (!cancelled) setLoadingCh(false); });

        return () => { cancelled = true; };
    }, [orderId]);

    // 2. Subscribe to real-time messages
    useEffect(() => {
        if (!channel) return;
        subscribeToChannel(channel.id, (raw) => {
            const msg = raw as unknown as ChannelMessage;
            setMessages(prev => prev.find(m => m.id === msg.id) ? prev : [...prev, msg]);
            scrollBottom();
        });
        return () => {
            try { getEcho().leave(`channel.${channel.id}`); } catch { /* ignore */ }
        };
    }, [channel?.id]);

    // ── Composer handlers ────────────────────────────────────────────────────

    const handleChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
        const val    = e.target.value;
        setBody(val);
        const cursor = e.target.selectionStart;
        const before = val.slice(0, cursor);

        const at = before.match(/@([^@\n]*)$/);
        if (at) {
            setMentionQ(at[1]); setMentionStart(cursor - at[0].length); setEntityQ(null);
        } else {
            setMentionQ(null);
            const hash = before.match(/#([^#\n]*)$/);
            if (hash) { setEntityQ(hash[1]); setEntityStart(cursor - hash[0].length); }
            else setEntityQ(null);
        }
    };

    const doInsertMention = (u: MentionUser) => {
        const before = body.slice(0, mentionStart);
        const after  = body.slice(textareaRef.current?.selectionStart ?? mentionStart);
        setBody(before + `@[${u.name}](user:${u.id})` + (after && !after.startsWith(" ") ? " " : "") + after);
        setMentionQ(null);
        setTimeout(() => {
            textareaRef.current?.focus();
            if (textareaRef.current) {
                textareaRef.current.style.height = "auto";
                textareaRef.current.style.height = textareaRef.current.scrollHeight + "px";
            }
        }, 0);
    };

    const insertMention = (u: MentionUser) => {
        if (channelMemberIds.size > 0 && !channelMemberIds.has(u.id)) {
            setMentionQ(null);
            setPendingMention(u);
            return;
        }
        doInsertMention(u);
    };

    const insertEntity = (entity: EntitySearchResult) => {
        const token  = `#[${entity.label}](entity:${entity.type}:${entity.id})`;
        const before = body.slice(0, entityStart);
        const after  = body.slice(textareaRef.current?.selectionStart ?? entityStart);
        setBody(before + token + (after && !after.startsWith(" ") ? " " : "") + after);
        setEntityQ(null);
        setTimeout(() => {
            textareaRef.current?.focus();
            if (textareaRef.current) {
                textareaRef.current.style.height = "auto";
                textareaRef.current.style.height = textareaRef.current.scrollHeight + "px";
            }
        }, 0);
    };

    const handleSend = async () => {
        if (!channel || !body.trim() || sending) return;
        setSending(true);
        try {
            const res = await channelApi.send(channel.id, { body: body.trim() });
            setMessages(prev => [...prev, res.message]);
            setBody("");
            if (textareaRef.current) {
                textareaRef.current.style.height = "auto";
                textareaRef.current.style.height = "20px";
            }
            scrollBottom();
            qc.invalidateQueries({ queryKey: ["channels"] });
        } catch (e: any) {
            toast.error(e?.message ?? "Failed to send");
        } finally {
            setSending(false);
        }
    };

    const handleKey = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === "Escape") { setMentionQ(null); setEntityQ(null); return; }
        if (e.key === "Enter" && !e.shiftKey && mentionQ === null && entityQ === null && body.trim()) {
            e.preventDefault(); handleSend();
        }
    };

    if (loadingCh) return <div className="flex justify-center py-8"><Spinner /></div>;

    return (
        <>
        <div className="flex flex-col" style={{ minHeight: 400 }}>
            {/* CommsHub deep-link */}
            {channel && (
                <div className="pb-2 shrink-0">
                    <p className="text-2xs text-surface-400">
                        Messages here also appear in{" "}
                        <a href={`/comms/${channel.id}`} className="text-brand-500 hover:underline font-medium">
                            CommsHub → {channel.name}
                        </a>
                    </p>
                </div>
            )}

            {/* Messages */}
            <div className="flex-1 overflow-y-auto px-1 py-2 space-y-3" style={{ maxHeight: 420 }}>
                {messages.length === 0 ? (
                    <div className="text-center py-10 text-surface-300">
                        <svg className="w-10 h-10 mx-auto mb-2 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <p className="text-sm font-medium text-surface-400">No messages yet</p>
                        <p className="text-xs text-surface-300 mt-1">Start the conversation below</p>
                    </div>
                ) : messages.map(msg => {
                    if (msg.type === "system") return (
                        <div key={msg.id} className="flex items-center gap-2 text-2xs text-surface-400 py-1">
                            <div className="flex-1 h-px bg-surface-100" />
                            <span>{msg.body}</span>
                            <div className="flex-1 h-px bg-surface-100" />
                        </div>
                    );
                    const isOwn = msg.user?.id === currentUserId;
                    return (
                        <div key={msg.id} className={clsx("flex gap-2.5", isOwn && "flex-row-reverse")}>
                            {msg.user ? (
                                <div className={clsx("w-7 h-7 rounded-full flex items-center justify-center text-white text-2xs font-bold shrink-0 mt-0.5", avatarColor(msg.user.id))}>
                                    {msg.user.initials}
                                </div>
                            ) : (
                                <div className="w-7 h-7 rounded-full bg-surface-200 shrink-0 mt-0.5" />
                            )}
                            <div className={clsx("flex flex-col gap-0.5 max-w-[78%]", isOwn && "items-end")}>
                                <div className={clsx("flex items-center gap-1.5 px-0.5", isOwn && "flex-row-reverse")}>
                                    <span className="text-2xs font-semibold text-surface-700">{msg.user?.name ?? "System"}</span>
                                    <span className="text-2xs text-surface-300">{fmtTime(msg.created_at)}</span>
                                </div>
                                <div className={clsx(
                                    "px-3 py-2 rounded-2xl",
                                    isOwn ? "bg-brand-500 text-white rounded-tr-sm" : "bg-surface-100 text-surface-900 rounded-tl-sm"
                                )}>
                                    <ThreadMessageBody body={msg.body} linkedEntities={msg.linked_entities} isOwn={isOwn} />
                                </div>
                            </div>
                        </div>
                    );
                })}
                <div ref={bottomRef} />
            </div>

            {/* Composer */}
            <div className="border-t border-surface-100 p-3 bg-surface-50 rounded-b-xl shrink-0">
                <div className="relative">
                    {mentionQ !== null && (
                        <ThreadMentionPopup query={mentionQ} onSelect={insertMention} />
                    )}
                    {entityQ !== null && (
                        <ThreadEntityPopup query={entityQ} onSelect={insertEntity} onDismiss={() => setEntityQ(null)} />
                    )}
                    <div className="flex gap-2 items-end bg-white rounded-xl border border-surface-200 focus-within:border-brand-400 focus-within:ring-1 focus-within:ring-brand-200 px-3 py-2">
                        {/* Rich composer: transparent textarea + visual mirror overlay */}
                        <div className="relative flex-1 self-center min-w-0 max-h-28 overflow-y-auto">
                            {/* Mirror drives wrapper height; textarea is absolute overlay */}
                            <div aria-hidden="true"
                                className="composer-mirror pointer-events-none text-xs leading-5 whitespace-pre-wrap break-words select-none w-full"
                                style={{ wordBreak: "break-word", minHeight: "20px" }}>
                                {body
                                    ? parseBodyToNodes(body)
                                    : <span className="text-surface-400">Message… (Enter to send · @ mention · # tag order)</span>
                                }
                                <span className="select-none">{"​"}</span>
                            </div>
                            <textarea
                                ref={textareaRef}
                                value={body}
                                onChange={handleChange}
                                onKeyDown={handleKey}
                                rows={1}
                                className="absolute inset-0 w-full text-xs leading-5 bg-transparent resize-none outline-none focus:outline-none focus:ring-0 border-0 shadow-none overflow-hidden"
                                style={{ color: "transparent", caretColor: "rgb(15 23 42)", height: "100%" }}
                                onInput={e => {
                                    const t = e.currentTarget;
                                    t.style.height = t.parentElement ? t.parentElement.offsetHeight + "px" : "auto";
                                }}
                            />
                        </div>
                        <button
                            onClick={handleSend}
                            disabled={!body.trim() || sending}
                            className="shrink-0 w-8 h-8 rounded-xl bg-brand-600 text-white flex items-center justify-center hover:bg-brand-700 disabled:opacity-40 transition-colors self-end"
                        >
                            {sending
                                ? <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                : <svg className="w-3.5 h-3.5 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            }
                        </button>
                    </div>
                </div>
                {/* Shortcut hints */}
                {!body && (
                    <div className="flex items-center gap-3 px-1 pt-1.5">
                        <span className="flex items-center gap-1 text-2xs text-surface-300">
                            <kbd className="px-1 py-0.5 rounded bg-surface-100 text-surface-400 font-mono text-2xs border border-surface-200 leading-none">@</kbd>
                            mention people
                        </span>
                        <span className="text-surface-200 text-2xs select-none">·</span>
                        <span className="flex items-center gap-1 text-2xs text-surface-300">
                            <kbd className="px-1 py-0.5 rounded bg-surface-100 text-surface-400 font-mono text-2xs border border-surface-200 leading-none">#</kbd>
                            tag an order
                        </span>
                        <span className="text-surface-200 text-2xs select-none">·</span>
                        <span className="text-2xs text-surface-300">visible in CommsHub</span>
                    </div>
                )}
            </div>
        </div>

        {/* Non-member @mention prompt */}
        {pendingMention && channel && (
            <div className="fixed inset-0 z-[70] flex items-end sm:items-center justify-center bg-black/40 p-4"
                onMouseDown={e => { if (e.target === e.currentTarget) setPendingMention(null); }}>
                <div className="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-5 flex flex-col gap-4">
                    <div className="flex items-start gap-3">
                        <div className="w-9 h-9 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                            <svg className="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 className="text-sm font-bold text-surface-900">{pendingMention.name} isn't a member</h3>
                            <p className="text-xs text-surface-500 mt-1">
                                <span className="font-semibold text-surface-700">{pendingMention.name}</span> is not in this thread.
                                They won't be notified and won't see this message unless added.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col gap-2">
                        <button
                            onClick={async () => {
                                try {
                                    await channelApi.addMember(channel.id, pendingMention!.id);
                                    setChannelMemberIds(prev => new Set([...prev, pendingMention!.id]));
                                    qc.invalidateQueries({ queryKey: ["channels"] });
                                } catch { /* continue even if add fails */ }
                                doInsertMention(pendingMention!);
                                setPendingMention(null);
                            }}
                            className="w-full py-2.5 rounded-xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 transition-colors flex items-center justify-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            Add {pendingMention.name} and mention
                        </button>
                        <button onClick={() => { doInsertMention(pendingMention!); setPendingMention(null); }}
                            className="w-full py-2 rounded-xl border border-surface-200 text-surface-600 text-sm hover:bg-surface-50 transition-colors">
                            Mention anyway (they won't see it)
                        </button>
                        <button onClick={() => setPendingMention(null)}
                            className="w-full py-2 rounded-xl text-surface-400 text-sm hover:text-surface-600 transition-colors">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        )}
        </>
    );
}
// ── Audit Trail ───────────────────────────────────────────────────────────────

function AuditTrail({ orderId }: { orderId: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ["production-order-audit", orderId],
        queryFn: () => get<{ logs: AuditEntry[] }>(`/v1/admin/production-orders/${orderId}/audit-log`),
        staleTime: 30_000,
    });
    const logs = data?.logs ?? [];
    if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
    if (!logs.length) return <div className="text-center py-12 text-xs text-surface-400">No audit entries yet.</div>;
    return (
        <div className="divide-y divide-surface-50">
            {logs.map(e => (
                <div key={e.id} className="flex gap-3 py-3.5">
                    <div className="w-7 h-7 rounded-full bg-brand-100 flex items-center justify-center shrink-0 mt-0.5">
                        <svg className="w-3 h-3 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-2">
                            <span className="text-xs font-semibold text-surface-800">{e.label} <span className="font-normal text-surface-500">· {e.actor_name}</span></span>
                            <span className="text-2xs text-surface-400 shrink-0">{fmtDateTime(e.created_at)}</span>
                        </div>
                        <p className="text-xs text-surface-600 mt-0.5">{e.description}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Key-value grid for specs / measurements ───────────────────────────────────

// Tailoring reads top-down: the shop measures in this order, so every order
// displays in this order — regardless of the sequence the keys were typed in.
const MEASUREMENT_ORDER = ["neck", "shoulders", "sleeves", "wrist", "chest", "stomach", "waist", "hip", "shirt_length", "full_length"];
const normKey = (k: string) => k.toLowerCase().trim().replace(/[\s-]+/g, "_");
const measurementRank = (k: string) => {
    const i = MEASUREMENT_ORDER.indexOf(normKey(k));
    return i === -1 ? MEASUREMENT_ORDER.length : i;
};

// Three measurements per row: a tape-measure card, not a ledger. Each cell is
// name-over-value so the eye sweeps left-to-right exactly the way the shop
// measures top-to-bottom — three at a glance instead of one per line.
function SpecGrid({ data, accentClass = "bg-surface-50 border-surface-100" }: { data: Record<string, string>; accentClass?: string }) {
    const entries = Object.entries(data).filter(([, v]) => v)
        .map(([k, v], i) => ({ k, v, i }))
        .sort((a, b) => (measurementRank(a.k) - measurementRank(b.k)) || (a.i - b.i))
        .map(({ k, v }) => [k, v] as [string, string]);
    if (!entries.length) return null;
    return (
        <div className="grid grid-cols-3 gap-1.5">
            {entries.map(([k, v]) => (
                <div key={k} className={clsx("rounded-lg border px-2.5 py-2 min-w-0", accentClass)}>
                    <p className="text-2xs text-surface-400 capitalize truncate leading-tight">{k.replace(/_/g, " ")}</p>
                    <p className="text-sm font-bold text-surface-900 tabular-nums leading-tight mt-0.5 break-words">{v}</p>
                </div>
            ))}
        </div>
    );
}

// ── Batch cards ───────────────────────────────────────────────────────────────
// A batch card is a self-contained production unit: what it looks like, what
// it's made of, where it sits in the pipeline, who has it on their bench, and
// what materials it consumes — readable without opening anything else.
// Everything on it is DERIVED from data that already exists (per-batch stage
// counts, order allocations pro-rated by piece share); nothing is typed twice.

function BatchCard({ batch, order, seqTasks, allocations, canEdit, onUpload, onDeleteImage, uploadPending }: {
    batch: OrderBatch;
    order: ProductionOrder;
    /** Tasks with a sequence, already sorted by it. */
    seqTasks: Task[];
    allocations: MaterialAlloc[];
    canEdit: boolean;
    onUpload: (batchId: number, file: File) => void;
    onDeleteImage: (batchId: number, url: string) => void;
    uploadPending: boolean;
}) {
    const nStages = seqTasks.length;
    const passedPer = seqTasks.map(t => batchPassed(t, batch));
    const finished = nStages ? passedPer[nStages - 1] : 0;
    const pct = nStages && batch.quantity > 0
        ? Math.round((passedPer.reduce((s, p) => s + p, 0) / (batch.quantity * nStages)) * 100)
        : 0;
    const curIdx = passedPer.findIndex(p => p < batch.quantity);
    const complete = nStages > 0 && curIdx === -1;
    const inProduction = !complete && passedPer.some(p => p > 0);
    const currentTask = curIdx >= 0 ? seqTasks[curIdx] : undefined;
    const tailor = currentTask ? resolveAssignee(currentTask) : null;

    // Pro-rata material share: allocations live on the order; this batch's slice
    // of them is its piece share. An estimate by definition — labelled as such.
    const share = order.quantity > 0 ? batch.quantity / order.quantity : 0;

    const attrs = Object.entries(batch.attributes ?? {});
    const photos = batch.images ?? [];
    const thumb = photos[0];
    const priorityCfg = PRIORITY_CFG[order.priority] ?? PRIORITY_CFG.normal;

    return (
        <div className={clsx("rounded-xl border overflow-hidden",
            complete ? "border-emerald-200 bg-emerald-50/40" : "border-surface-200 bg-white")}>

            {/* Identity: thumbnail + name + one status */}
            <div className="p-3 sm:p-4">
                <div className="flex items-start gap-3">
                    {thumb ? (
                        <img src={thumb} alt={batch.label} onClick={() => window.open(thumb, "_blank")}
                            className="w-16 h-16 rounded-lg object-cover border border-surface-200 shrink-0 cursor-pointer" />
                    ) : (
                        <div className="w-16 h-16 rounded-lg bg-surface-100 flex items-center justify-center text-xl shrink-0">🎨</div>
                    )}
                    <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-sm font-bold text-surface-900 truncate">{batch.label}</p>
                                <p className="text-2xs text-surface-400 mt-0.5">
                                    <span className="font-semibold text-surface-600 tabular-nums">{batch.quantity} pcs</span>
                                    {" · "}<span className={clsx("font-semibold uppercase", priorityCfg.color)}>{priorityCfg.label}</span>
                                    {batch.created_at && <> · Created {fmtDate(batch.created_at)}</>}
                                    {" · "}Due {fmtDate(order.due_date)}
                                </p>
                            </div>
                            <span className={clsx("shrink-0 text-2xs font-semibold px-2 py-0.5 rounded-full",
                                complete ? "bg-emerald-100 text-emerald-700"
                                : inProduction ? "bg-brand-100 text-brand-700"
                                : "bg-surface-100 text-surface-500")}>
                                {complete ? "✓ Complete" : inProduction ? "In production" : "Not started"}
                            </span>
                        </div>
                        {/* Where it is and who has it — the two questions the floor asks */}
                        {!complete && currentTask && (
                            <p className="text-xs text-surface-600 mt-1.5 flex items-center gap-1.5 flex-wrap">
                                <StageIcon slug={currentTask.stage?.slug} className="w-3 h-3 text-surface-400 shrink-0" />
                                <span>Now at <b className="text-surface-800">{currentTask.stage?.name}</b></span>
                                <span className="text-surface-300">·</span>
                                {tailor
                                    ? <span>{tailor.first_name} {tailor.last_name}</span>
                                    : <span className="italic text-surface-400">unassigned</span>}
                            </p>
                        )}
                    </div>
                </div>

                {/* Attributes — fabric, colour, pattern, trim… whatever this batch carries */}
                {attrs.length > 0 && (
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 mt-3">
                        {attrs.map(([k, v]) => (
                            <div key={k} className="flex gap-1.5 text-2xs leading-snug min-w-0">
                                <span className="text-surface-400 capitalize shrink-0">{k.replace(/_/g, " ")}:</span>
                                <span className="text-surface-800 font-semibold truncate">{v}</span>
                            </div>
                        ))}
                    </div>
                )}

                {/* Pipeline position: one chip per stage, counted for THIS batch */}
                {nStages > 0 && (
                    <div className="flex items-center gap-1 mt-3 overflow-x-auto no-scrollbar">
                        {seqTasks.map((t, i) => {
                            const p = passedPer[i];
                            const full = p >= batch.quantity;
                            return (
                                <span key={t.id} title={t.stage?.name}
                                    className={clsx("shrink-0 text-2xs font-semibold px-1.5 py-0.5 rounded-md tabular-nums border",
                                        full ? "bg-emerald-50 text-emerald-700 border-emerald-200"
                                        : p > 0 ? "bg-brand-50 text-brand-700 border-brand-200"
                                        : "bg-surface-50 text-surface-400 border-surface-200")}>
                                    {t.stage?.name} {p}/{batch.quantity}
                                </span>
                            );
                        })}
                    </div>
                )}

                {/* Progress: measured across every stage, not just the last */}
                <div className="flex items-center gap-2 mt-3">
                    <div className="flex-1 h-1.5 bg-surface-100 rounded-full overflow-hidden">
                        <div className={clsx("h-full rounded-full transition-all", complete ? "bg-emerald-500" : "bg-brand-500")}
                            style={{ width: `${pct}%` }} />
                    </div>
                    <span className={clsx("text-2xs font-bold tabular-nums shrink-0", complete ? "text-emerald-700" : "text-surface-500")}>
                        {complete ? "✓ " : ""}{finished}/{batch.quantity} · {pct}%
                    </span>
                </div>

                {/* Reference photos — fabric, trim, the finished look */}
                {(photos.length > 0 || canEdit) && (
                    <div className="flex flex-wrap items-center gap-1.5 mt-3">
                        {photos.map(u => (
                            <div key={u} className="relative group">
                                <img src={u} alt="" onClick={() => window.open(u, "_blank")}
                                    className="w-10 h-10 rounded-md object-cover border border-surface-200 cursor-pointer" />
                                {canEdit && (
                                    <button
                                        onClick={() => onDeleteImage(batch.id, u)}
                                        className="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-surface-700 text-white text-2xs leading-none hidden group-hover:flex items-center justify-center"
                                        aria-label="Remove photo">×</button>
                                )}
                            </div>
                        ))}
                        {canEdit && (
                            <label className={clsx("w-10 h-10 rounded-md border border-dashed border-surface-300 text-surface-400 flex items-center justify-center text-sm cursor-pointer hover:border-brand-400 hover:text-brand-500 transition-colors", uploadPending && "opacity-50 pointer-events-none")}>
                                +
                                <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden"
                                    onChange={e => {
                                        const f = e.target.files?.[0];
                                        if (f) onUpload(batch.id, f);
                                        e.target.value = "";
                                    }} />
                            </label>
                        )}
                    </div>
                )}
            </div>

            {/* Material share — what this batch consumes of the order's allocations */}
            {allocations.length > 0 && share > 0 && (
                <details className="border-t border-surface-100 group">
                    <summary className="px-3 sm:px-4 py-2 text-2xs font-bold text-surface-400 uppercase tracking-widest cursor-pointer select-none hover:text-surface-600 flex items-center gap-1.5 list-none [&::-webkit-details-marker]:hidden">
                        <svg className="w-3 h-3 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                        Materials — {Math.round(share * 100)}% share of order
                    </summary>
                    <div className="px-3 sm:px-4 pb-3">
                        <div className="grid grid-cols-12 gap-2 text-2xs font-bold text-surface-400 uppercase tracking-wide px-1 pb-1">
                            <span className="col-span-5">Material</span>
                            <span className="col-span-2 text-right">Req.</span>
                            <span className="col-span-2 text-right">Used</span>
                            <span className="col-span-3 text-right">Remaining</span>
                        </div>
                        <div className="divide-y divide-surface-50">
                            {allocations.map(a => {
                                const req  = a.quantity_required * share;
                                const used = a.quantity_used * share;
                                const rem  = Math.max(0, req - used);
                                return (
                                    <div key={a.id} className="grid grid-cols-12 gap-2 items-center py-1.5 text-xs">
                                        <div className="col-span-5 min-w-0">
                                            <p className="font-medium text-surface-800 truncate">{a.material.name}</p>
                                            <p className="text-2xs text-surface-400">{a.material.unit_of_measure}</p>
                                        </div>
                                        <span className="col-span-2 text-right tabular-nums text-surface-600">{fmtNum(req)}</span>
                                        <span className="col-span-2 text-right tabular-nums text-surface-600">{fmtNum(used)}</span>
                                        <span className={clsx("col-span-3 text-right tabular-nums font-semibold", rem <= 0 ? "text-emerald-600" : "text-surface-800")}>
                                            {rem <= 0 ? "✓ fully used" : fmtNum(rem)}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                        <p className="text-2xs text-surface-400 mt-1.5">
                            Pro-rata estimate: this batch is {batch.quantity} of {order.quantity} pieces, so it carries {Math.round(share * 100)}% of each order allocation.
                        </p>
                    </div>
                </details>
            )}
        </div>
    );
}

function BatchesSection({ order, seqTasks, canEdit, onEditBatches, onUpload, onDeleteImage, uploadPending }: {
    order: ProductionOrder;
    seqTasks: Task[];
    canEdit: boolean;
    onEditBatches: () => void;
    onUpload: (batchId: number, file: File) => void;
    onDeleteImage: (batchId: number, url: string) => void;
    uploadPending: boolean;
}) {
    const batches = order.batches ?? [];
    if (batches.length === 0) return (
        <div className="text-center py-10">
            <p className="text-sm font-medium text-surface-500">No batches defined</p>
            <p className="text-xs text-surface-400 mt-1 max-w-sm mx-auto">
                Split the order into colourway batches — same garment, different trim — and
                tailors count each batch separately.
            </p>
            {canEdit && (
                <button onClick={onEditBatches}
                    className="mt-4 text-xs font-semibold text-brand-600 border border-dashed border-brand-300 rounded-xl px-4 py-2 hover:bg-brand-50 transition-colors">
                    + Split into colourway batches
                </button>
            )}
        </div>
    );
    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <p className="text-xs text-surface-400">
                    {batches.length} batch{batches.length === 1 ? "" : "es"} · {order.quantity} pieces total
                </p>
                {canEdit && (
                    <button onClick={onEditBatches}
                        className="text-2xs font-semibold text-brand-600 hover:text-brand-700">✎ Edit batches</button>
                )}
            </div>
            {batches.map(b => (
                <BatchCard key={b.id} batch={b} order={order} seqTasks={seqTasks}
                    allocations={order.material_allocations ?? []}
                    canEdit={canEdit} onUpload={onUpload} onDeleteImage={onDeleteImage}
                    uploadPending={uploadPending} />
            ))}
        </div>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function ProductionOrderDetailPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const [tab, setTab] = useState<"stages" | "batches" | "materials" | "specs" | "activity" | "audit">("stages");
    const [modal, setModal] = useState<"assign" | "materials" | "qc" | "complete" | "edit" | "batches" | null>(null);
    const [showCancelConfirm, setShowCancelConfirm] = useState(false);
    const [cancelReason, setCancelReason] = useState("");

    // Current user — to detect which stages they are assigned to
    const currentUserId = useAuthStore(s => s.user?.id ?? null);

    // Permissions. MUST be read here (before any early return) so the hook is
    // always called in the same order — reading it after the isLoading/!order
    // guards changed the hook count between renders and crashed the page to a
    // white screen the moment the order loaded.
    const { can } = usePermissions();

    const { data, isLoading } = useQuery({
        queryKey: ["production-order", Number(id)],
        queryFn: () => get<{ order: ProductionOrder }>(`/v1/admin/production-orders/${id}`),
        enabled: !!id,
        staleTime: 0,
    });
    const order = (data as any)?.order as ProductionOrder | undefined;

    const refresh = useCallback(() => {
        qc.invalidateQueries({ queryKey: ["production-order", Number(id)] });
        qc.invalidateQueries({ queryKey: ["production-orders"] });
    }, [id, qc]);

    // Inline task-status mutation for stages the current user is assigned to
    const taskMutation = useMutation({
        mutationFn: ({ taskId, action }: { taskId: number; action: "start" | "complete" | "pause" }) =>
            put(`/v1/tailor/tasks/${taskId}/status`, { action }),
        onSuccess: (_, vars) => {
            const msg = vars.action === "complete" ? "Stage marked complete!" :
                        vars.action === "pause"    ? "Stage paused" : "Stage started!";
            toast.success(msg);
            refresh();
        },
        onError: (e: ApiError) => toast.error(e.message ?? "Failed to update task"),
    });

    const unlockMutation = useMutation({
        mutationFn: ({ taskId, allow }: { taskId: number; allow: boolean }) =>
            post(`/v1/tailor/tasks/${taskId}/unlock`, { allow }),
        onSuccess: (_, vars) => {
            toast.success(vars.allow ? "Stage unlocked — it can run in parallel" : "Stage re-locked");
            refresh();
        },
        onError: (e: ApiError) => toast.error(e.message ?? "Failed to update stage lock"),
    });

    const batchImageMutation = useMutation({
        mutationFn: ({ batchId, file }: { batchId: number; file: File }) => {
            const fd = new FormData();
            fd.append("image", file);
            return post(`/v1/admin/production-orders/${id}/batches/${batchId}/images`, fd);
        },
        onSuccess: () => { toast.success("Reference photo added"); refresh(); },
        onError: (e: ApiError) => toast.error(e.message ?? "Upload failed"),
    });
    const batchImageDeleteMutation = useMutation({
        mutationFn: ({ batchId, url }: { batchId: number; url: string }) =>
            del(`/v1/admin/production-orders/${id}/batches/${batchId}/images`, { data: { url } }),
        onSuccess: () => { toast.success("Photo removed"); refresh(); },
        onError: (e: ApiError) => toast.error(e.message ?? "Failed to remove photo"),
    });

    const confirmMutation = useMutation({
        mutationFn: () => post(`/v1/admin/production-orders/${id}/confirm`, {}),
        onSuccess: () => { toast.success("Order confirmed - now in production queue"); refresh(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const cancelMutation = useMutation({
        mutationFn: (reason: string) => post(`/v1/admin/production-orders/${id}/cancel`, { reason }),
        onSuccess: () => {
            toast.success("Production order cancelled.");
            setShowCancelConfirm(false);
            setCancelReason("");
            refresh();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    if (isLoading) return <div className="flex items-center justify-center h-64"><Spinner /></div>;
    if (!order) return (
        <div className="text-center py-16 text-surface-400 text-sm">
            Production order not found.
            <button onClick={() => navigate("/production/orders")} className="block mt-3 btn-secondary mx-auto">Back</button>
        </div>
    );

    const statusCfg   = STATUS_CFG[order.status]    ?? STATUS_CFG.draft;
    const priorityCfg = PRIORITY_CFG[order.priority] ?? PRIORITY_CFG.normal;
    // Sequence is the order's own snapshot (stamped at seeding); stage sort_order
    // is only the fallback for legacy tasks that predate it.
    const sortedTasks = [...(order.tasks ?? [])].sort((a, b) =>
        (a.sequence ?? a.stage?.sort_order ?? 0) - (b.sequence ?? b.stage?.sort_order ?? 0));
    const allocations = order.material_allocations ?? [];
    const isCustomer  = !!order.customer_order_id;
    const days        = daysUntil(order.due_date);
    // Finished = pieces past the LAST stage — the same arithmetic the pipeline
    // runs on, surfaced as a headline number.
    const seqTasks = sortedTasks.filter(t => t.sequence != null);
    const lastSeq  = seqTasks[seqTasks.length - 1];
    const finishedPieces = lastSeq
        ? (GATE_SATISFIED.includes((lastSeq.status ?? "").toLowerCase())
            ? order.quantity
            : Math.min(lastSeq.quantity_done ?? 0, order.quantity))
        : (order.status === "completed" ? order.quantity : 0);
    const hasSpecs    = !!(order.measurements || order.specifications || order.customer_preferences);
    // Gender leads the spec sheet — pulled out of whichever map it was typed into.
    const specGender = [order.measurements, order.specifications, order.customer_preferences]
        .map(d => d && Object.entries(d).find(([k, v]) => normKey(k) === "gender" && v)?.[1])
        .find(Boolean);
    const withoutGender = (d?: Record<string, string>) =>
        d ? Object.fromEntries(Object.entries(d).filter(([k]) => normKey(k) !== "gender")) : d;
    const orderMeasurements = withoutGender(order.measurements);
    const orderSpecifications = withoutGender(order.specifications);
    const orderPreferences = withoutGender(order.customer_preferences);

    const canConfirmOrderPerm = can("production.confirm_order");
    const canManageAssignees = can("production.manage_assignees");
    const canSubmitQcPerm = can("production.submit_qc");
    const canApproveQcPerm = can("production.approve_qc");
    const canConfirm  = order.status === "draft" && canConfirmOrderPerm;
    const canAssign   = ["pending", "in_progress"].includes(order.status) && canManageAssignees;
    const canMaterials= ["pending", "in_progress"].includes(order.status) && canManageAssignees;
    const canQC       = order.status === "qc_pending" && canSubmitQcPerm;
    const canComplete = order.status === "qc_passed" && canApproveQcPerm;
    const canCancel   = ["draft", "pending"].includes(order.status) && canConfirmOrderPerm;
    // Same permission that raises orders; the backend refuses completed/cancelled,
    // and only drafts may change quantity (serials + materials were sized from it).
    const canEdit     = !["completed", "cancelled"].includes(order.status) && can("production.raise_order");
    const canOpenWIP  = ["pending", "in_progress", "on_hold", "qc_pending", "qc_passed", "qc_failed"].includes(order.status);

    // Batches earn a tab of their own the moment they can exist: a rich batch
    // card needs the main column, not a 260px sidebar sliver.
    const showBatchesTab = (order.batches?.length ?? 0) > 0 || (canEdit && order.quantity > 1);
    const tabs = [
        { key: "stages",    label: `⚙️ Stages (${sortedTasks.length})` },
        ...(showBatchesTab ? [{ key: "batches", label: `🎨 Batches (${order.batches?.length ?? 0})` }] : []),
        { key: "materials", label: `🧵 Materials (${allocations.length})` },
        ...(hasSpecs ? [{ key: "specs", label: "📐 Specs & Measurements" }] : []),
        { key: "activity",  label: "💬 Activity" },
        { key: "audit",     label: "🕐 Audit Trail" },
    ] as { key: string; label: string }[];

    return (
        <div className="max-w-6xl mx-auto">
            {/* Back */}
            <button onClick={() => navigate("/production/orders")}
                className="flex items-center gap-1.5 text-xs text-surface-500 hover:text-surface-800 mb-4 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Production Orders
            </button>

            <div className="bg-white rounded-2xl shadow-sm border border-surface-200 overflow-hidden">

                {/* Header band */}
                {/* On a phone this header used to be a full-screen billboard —
                    QUANTITY at 4xl pushed every action below the fold. Mobile
                    gets one compact banner: identity, status, one stat line. */}
                <div className={clsx("px-4 py-4 sm:px-8 sm:py-6 bg-gradient-to-r",
                    order.status === "completed" ? "from-emerald-800 to-teal-700" :
                    order.status === "cancelled" ? "from-slate-700 to-slate-600" :
                    order.status === "qc_failed" ? "from-red-800 to-red-700" :
                    "from-slate-800 to-slate-700")}>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
                        <div>
                            <p className="text-slate-400 text-xs font-semibold uppercase tracking-widest mb-1">Production Order</p>
                            <h1 className="text-lg sm:text-2xl font-bold text-white font-mono">{order.order_number}</h1>
                            <p className="text-sm sm:text-lg text-white/80 mt-0.5">{order.product_name}</p>
                            <div className="flex items-center gap-2 mt-2 flex-wrap">
                                <span className={clsx("px-2.5 py-1 rounded-full text-xs font-semibold", statusCfg.bg, statusCfg.color)}>
                                    {statusCfg.label}
                                </span>
                                <span className={clsx("text-2xs font-bold px-1.5 py-0.5 rounded border uppercase tracking-wide", priorityCfg.cls)}>
                                    {priorityCfg.label}
                                </span>
                                {isCustomer && (
                                    <span className="px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-700">
                                        Custom Order
                                    </span>
                                )}
                            </div>
                            {/* Everything a coordinator asks first, on the card they see first. */}
                            {(order.outlet || order.confirmed_at || order.created_by) && (
                                <div className="flex items-center gap-x-3 gap-y-0.5 flex-wrap mt-2 text-2xs text-slate-300/90">
                                    {order.outlet && <span>🏬 {order.outlet.name}</span>}
                                    {order.confirmed_at && <span>✓ Confirmed {fmtDate(order.confirmed_at)}</span>}
                                    {order.created_by && <span>✎ {[order.created_by.first_name, order.created_by.last_name].filter(Boolean).join(" ")}</span>}
                                </div>
                            )}
                        </div>
                        {/* KPI strip: the three numbers a manager reads first —
                            how many, how many are done, and how long is left. */}
                        <div className="flex items-start gap-5 sm:gap-7 sm:justify-end">
                            <div className="sm:text-right">
                                <p className="text-white font-bold tabular-nums text-xl sm:text-3xl leading-none">{order.quantity}</p>
                                <p className="text-slate-400 text-2xs uppercase tracking-wide mt-1">pieces</p>
                            </div>
                            <div className="sm:text-right">
                                <p className={clsx("font-bold tabular-nums text-xl sm:text-3xl leading-none",
                                    finishedPieces >= order.quantity && order.quantity > 0 ? "text-emerald-300" : finishedPieces > 0 ? "text-emerald-200" : "text-white/60")}>
                                    {finishedPieces}
                                </p>
                                <p className="text-slate-400 text-2xs uppercase tracking-wide mt-1">finished</p>
                            </div>
                            <div className="sm:text-right">
                                <p className={clsx("font-bold tabular-nums text-xl sm:text-3xl leading-none",
                                    days < 0 ? "text-red-300" : days <= 2 ? "text-amber-300" : "text-white")}>
                                    {days < 0 ? `${Math.abs(days)}d` : days === 0 ? "Today" : `${days}d`}
                                </p>
                                <p className="text-slate-400 text-2xs uppercase tracking-wide mt-1 whitespace-nowrap">
                                    {days < 0 ? "overdue" : "until due"} · {fmtDate(order.due_date)}
                                </p>
                            </div>
                        </div>
                    </div>
                    {/* Progress bar */}
                    <div className="mt-4">
                        <div className="flex justify-between text-2xs text-slate-400 mb-1">
                            <span>{order.current_stage ?? "Not started"}</span>
                            <span>{order.completion_percentage}% complete</span>
                        </div>
                        <div className="w-full h-2 bg-white/10 rounded-full overflow-hidden">
                            <div className="h-full bg-white/60 rounded-full transition-all" style={{ width: `${order.completion_percentage}%` }} />
                        </div>
                    </div>
                </div>

                {/* Action bar */}
                {/* One thumb-height scrollable strip on phones — six buttons no
                    longer stack into a half-screen pile. Desktop wraps as before. */}
                <div className="px-4 py-3 bg-slate-50 border-b border-surface-100 flex items-center gap-2 overflow-x-auto no-scrollbar sm:flex-wrap sm:overflow-visible sm:px-8 [&>*]:shrink-0">
                    {canConfirm && (
                        <button onClick={() => confirmMutation.mutate()} disabled={confirmMutation.isPending}
                            className="btn-sm bg-brand-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-brand-700">
                            {confirmMutation.isPending ? "Confirming…" : "✓ Confirm Order"}
                        </button>
                    )}
                    {canAssign && (
                        <button onClick={() => setModal("assign")}
                            className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-brand-300 hover:text-brand-600 transition-colors">
                            👥 Assign Tasks
                        </button>
                    )}
                    {canMaterials && (
                        <button onClick={() => setModal("materials")}
                            className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-brand-300 hover:text-brand-600 transition-colors">
                            🧵 Issue Materials
                        </button>
                    )}
                    {canQC && (
                        <button onClick={() => setModal("qc")}
                            className="btn-sm bg-purple-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-purple-700">
                            🔍 Quality Check
                        </button>
                    )}
                    {canComplete && (
                        <button onClick={() => setModal("complete")}
                            className="btn-sm bg-emerald-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700">
                            ✅ Complete & Stock
                        </button>
                    )}
                    {canOpenWIP && (
                        <button onClick={() => navigate("/production/wip", { state: { openOrderId: order.id } })}
                            className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-brand-300 hover:text-brand-600 transition-colors">
                            Open in WIP Board ↗
                        </button>
                    )}
                    {canEdit && (
                        <button onClick={() => setModal("edit")}
                            className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-brand-300 hover:text-brand-600 transition-colors">
                            ✎ Edit Order
                        </button>
                    )}
                    <PdfDownloadButton type="production-orders" id={order.id} label="Download PDF" />
                    <button
                        onClick={() => navigate(`/reports/production/costing/${order.id}`)}
                        className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-emerald-300 hover:text-emerald-700 transition-colors"
                    >
                        📊 Costing Report
                    </button>
                    {canCancel && (
                        <button onClick={() => setShowCancelConfirm(true)}
                            disabled={cancelMutation.isPending}
                            className="btn-sm bg-white text-danger border border-danger/30 rounded-xl px-3 py-1.5 text-xs font-semibold ml-auto">
                            {cancelMutation.isPending ? "Cancelling…" : "Cancel Order"}
                        </button>
                    )}
                </div>

                {/* Body */}
                <div className="px-5 py-5 grid grid-cols-1 lg:grid-cols-[1fr_260px] gap-6 lg:gap-8 sm:px-8 sm:py-6 lg:divide-x divide-surface-100">

                    {/* Left */}
                    <div className="space-y-4 lg:pr-8">

                        {/* Linked sales order — one line, not a billboard: the order
                            number is the link, customer name and phone ride along. */}
                        {isCustomer && (
                            <div className="flex items-center gap-2.5 px-3 py-2 rounded-xl border bg-indigo-50/70 border-indigo-100 text-xs">
                                <svg className="w-4 h-4 text-indigo-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                <div className="flex items-center gap-x-2 gap-y-0.5 flex-wrap min-w-0">
                                    <span className="text-2xs text-indigo-500 font-semibold uppercase tracking-widest">Sales Order</span>
                                    {order.customer_order ? (
                                        <Link to={`/sales/orders/${order.customer_order_id}`}
                                            className="font-bold text-indigo-800 font-mono hover:underline">
                                            {order.customer_order.order_number}
                                        </Link>
                                    ) : (
                                        <span className="font-bold text-indigo-800">#{order.customer_order_id}</span>
                                    )}
                                    {order.customer_order && (order.customer_order.customer_first_name || order.customer_order.customer_last_name) && (
                                        <span className="text-indigo-700 font-medium">
                                            {[order.customer_order.customer_first_name, order.customer_order.customer_last_name].filter(Boolean).join(" ")}
                                        </span>
                                    )}
                                    {order.customer_order?.customer_phone && (
                                        <span className="text-indigo-500">{order.customer_order.customer_phone}</span>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Tabs */}
                        <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar gap-0 -mb-px">
                            {tabs.map(t => (
                                <button key={t.key} onClick={() => setTab(t.key as any)}
                                    className={clsx("px-4 py-2.5 text-xs font-semibold border-b-2 transition-all whitespace-nowrap",
                                        tab === t.key ? "border-brand-500 text-brand-600" : "border-transparent text-surface-400 hover:text-surface-700")}>
                                    {t.label}
                                </button>
                            ))}
                        </div>

                        {tab === "stages"    && <StagesPipeline
                            tasks={sortedTasks}
                            orderQuantity={order.quantity}
                            batches={order.batches ?? []}
                            currentUserId={currentUserId}
                            onTaskAction={(taskId, action) => taskMutation.mutate({ taskId, action })}
                            taskActionPending={taskMutation.isPending}
                            canUnlock={can("production.manage_assignees")}
                            onUnlock={(taskId, allow) => unlockMutation.mutate({ taskId, allow })}
                        />}
                        {tab === "batches" && <BatchesSection
                            order={order}
                            seqTasks={sortedTasks.filter(t => t.sequence != null)}
                            canEdit={canEdit}
                            onEditBatches={() => setModal("batches")}
                            onUpload={(batchId, file) => batchImageMutation.mutate({ batchId, file })}
                            onDeleteImage={(batchId, url) => batchImageDeleteMutation.mutate({ batchId, url })}
                            uploadPending={batchImageMutation.isPending}
                        />}
                        {tab === "materials" && (
                            allocations.length === 0 ? (
                                <div className="text-center py-10 text-surface-400">
                                    <p className="text-xs">No material allocations. <button onClick={() => setModal("materials")} className="text-brand-500 hover:underline">Issue materials now</button>.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto rounded-xl border border-surface-200">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="bg-surface-50 border-b border-surface-200">
                                                <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Material</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Required</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Allocated</th>
                                                <th className="text-right px-3 py-2.5 font-semibold text-surface-600">Used</th>
                                                <th className="text-left px-3 py-2.5 font-semibold text-surface-600">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-surface-100">
                                            {allocations.map(a => {
                                                const pct = a.quantity_required > 0 ? (a.quantity_allocated / a.quantity_required) * 100 : 0;
                                                return (
                                                    <tr key={a.id}>
                                                        <td className="px-3 py-2.5">
                                                            <p className="font-medium text-surface-800">{a.material.name}</p>
                                                            <p className="text-2xs text-surface-400 font-mono">{a.material.code} · {a.material.unit_of_measure}</p>
                                                        </td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums">{a.quantity_required}</td>
                                                        <td className={clsx("px-3 py-2.5 text-right tabular-nums font-semibold", pct >= 100 ? "text-emerald-700" : "text-amber-700")}>{a.quantity_allocated}</td>
                                                        <td className="px-3 py-2.5 text-right tabular-nums text-surface-600">{a.quantity_used}</td>
                                                        <td className="px-3 py-2.5">
                                                            <div className="flex items-center gap-2">
                                                                <div className="w-16"><ProgressBar pct={pct} colorClass={pct >= 100 ? "bg-emerald-500" : "bg-amber-400"} /></div>
                                                                <span className={clsx("text-2xs font-semibold", pct >= 100 ? "text-emerald-600" : "text-amber-600")}>{Math.round(pct)}%</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )
                        )}
                        {tab === "specs" && (
                            <div className="space-y-4">
                                {specGender && (
                                    <div className="flex items-center gap-3 rounded-xl bg-slate-800 px-4 py-3">
                                        <span className="text-2xs font-semibold uppercase tracking-widest text-slate-400">Gender</span>
                                        <span className="text-sm font-bold text-white capitalize">{specGender}</span>
                                    </div>
                                )}
                                {orderMeasurements && Object.keys(orderMeasurements).length > 0 && (
                                    <div><SectionLabel>Measurements</SectionLabel><SpecGrid data={orderMeasurements} accentClass="bg-blue-50/70 border-blue-100" /></div>
                                )}
                                {orderSpecifications && Object.keys(orderSpecifications).length > 0 && (
                                    <div><SectionLabel>Specifications</SectionLabel><SpecGrid data={orderSpecifications} accentClass="bg-surface-50 border-surface-100" /></div>
                                )}
                                {orderPreferences && Object.keys(orderPreferences).length > 0 && (
                                    <div><SectionLabel>Customer Preferences</SectionLabel><SpecGrid data={orderPreferences} accentClass="bg-indigo-50/70 border-indigo-100" /></div>
                                )}
                                {order.notes && (
                                    <div><SectionLabel>Notes</SectionLabel>
                                        <p className="text-xs text-surface-700 bg-surface-50 rounded-xl p-3 whitespace-pre-wrap">{order.notes}</p>
                                    </div>
                                )}
                                {!hasSpecs && !order.notes && (
                                    <p className="text-xs text-surface-400 text-center py-8">No specifications recorded.</p>
                                )}
                            </div>
                        )}
                        {tab === "activity" && <OrderChannelThread orderId={order.id} />}
                        {tab === "audit"    && <AuditTrail orderId={order.id} />}
                    </div>

                    {/* Right sidebar */}
                    <div className="lg:pl-8 space-y-6">

                        {order.product_image && (
                            <div className="rounded-xl overflow-hidden border border-surface-200">
                                {/* h-28 on phones: the photo is orientation, not content */}
                                <img src={order.product_image} alt={order.product_name} className="w-full h-28 sm:h-40 object-cover" />
                            </div>
                        )}

                        {/* Stage progress mini — desktop sidebar only. On phones this
                            stacks directly under the full stage list it duplicates. */}
                        <div className="hidden lg:block">
                            <SectionLabel>Stage Progress</SectionLabel>
                            <div className="flex gap-1 mb-2">
                                {sortedTasks.map(t => (
                                    <div key={t.id} title={t.stage?.name}
                                        className={clsx("flex-1 h-2 rounded-full",
                                            t.status === "completed" ? "bg-emerald-500" :
                                            t.status === "in_progress" ? "bg-brand-500 animate-pulse" :
                                            t.status === "failed" ? "bg-red-500" : "bg-surface-200")} />
                                ))}
                            </div>
                            <ProgressBar pct={order.completion_percentage} colorClass={order.status === "completed" ? "bg-emerald-500" : "bg-brand-500"} />
                            <p className="text-2xs text-surface-400 mt-1 text-right">{order.completion_percentage}%</p>
                        </div>

                        {/* Assignees */}
                        {order.assignees && order.assignees.length > 0 && (
                            <div>
                                <SectionLabel>Assigned Tailors</SectionLabel>
                                <div className="space-y-2">
                                    {order.assignees.map(a => (
                                        <div key={a.user_id} className="flex items-center gap-2 p-2 bg-surface-50 rounded-lg">
                                            <div className="w-7 h-7 rounded-full bg-brand-100 flex items-center justify-center text-2xs font-bold text-brand-700 shrink-0">
                                                {a.user.first_name[0]}{a.user.last_name[0]}
                                            </div>
                                            <div>
                                                <p className="text-xs font-semibold text-surface-800">{a.user.first_name} {a.user.last_name}</p>
                                                <p className="text-2xs text-surface-400 capitalize">{a.role_in_order.replace("_", " ")}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Order info */}
                        <div>
                            {/* Batches moved to their own tab in the main column — a rich
                                batch card can't breathe in a 260px sidebar. This sidebar
                                keeps only a one-line pointer when batches exist. */}
                            {(order.batches?.length ?? 0) > 0 && (
                                <button onClick={() => setTab("batches")}
                                    className="w-full mb-5 flex items-center justify-between text-xs font-semibold text-brand-600 border border-brand-100 bg-brand-50/60 rounded-xl px-3 py-2 hover:bg-brand-50 transition-colors">
                                    <span>🎨 {order.batches!.length} colourway batch{order.batches!.length === 1 ? "" : "es"}</span>
                                    <span aria-hidden="true">→</span>
                                </button>
                            )}
                            {/* Order #, quantity, due date, outlet, confirmation and creator
                                all live on the header card now — this section keeps only the
                                dates the header doesn't carry. */}
                            {((order as any).fitting_date || (order as any).collection_date || order.started_at || order.completed_at) && (
                                <>
                                    <SectionLabel>Key Dates</SectionLabel>
                                    {(order as any).fitting_date && (
                                        <InfoRow label="Fitting" value={<span className="font-semibold text-violet-700">{fmtDate((order as any).fitting_date)}</span>} />
                                    )}
                                    {(order as any).collection_date && (
                                        <InfoRow label="Collection" value={<span className="font-semibold text-emerald-700">{fmtDate((order as any).collection_date)}</span>} />
                                    )}
                                    {order.started_at && <InfoRow label="Started" value={fmtDate(order.started_at)} />}
                                    {order.completed_at && <InfoRow label="Completed" value={fmtDate(order.completed_at)} />}
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* Modals */}
            {modal === "edit"      && <EditOrderModal order={order} onClose={() => setModal(null)} onSaved={refresh} />}
            {modal === "batches"   && <BatchesModal order={order} onClose={() => setModal(null)} onSaved={refresh} />}
            {modal === "assign"    && <AssignModal order={order} onClose={() => setModal(null)} onSaved={refresh} />}
            {modal === "materials" && <IssueMaterialsModal order={order} onClose={() => setModal(null)} onSaved={refresh} />}
            {modal === "qc"        && <QCModal order={order} onClose={() => setModal(null)} onDone={refresh} />}
            {modal === "complete"  && <CompleteModal order={order} onClose={() => setModal(null)} onDone={refresh} />}

            {/* Cancel confirm modal */}
            {showCancelConfirm && (
                <Modal open={showCancelConfirm} onClose={() => { setShowCancelConfirm(false); setCancelReason(""); }}>
                    <div className="p-6 w-full max-w-md">
                        <div className="flex items-start gap-4 mb-4">
                            <div className="w-10 h-10 rounded-full bg-danger-light flex items-center justify-center shrink-0">
                                <svg className="w-5 h-5 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-base font-semibold text-surface-900">Cancel Production Order</h3>
                                <p className="text-sm text-surface-500 mt-1">
                                    This will cancel <span className="font-semibold text-surface-700">{order.order_number}</span>. This action cannot be undone.
                                </p>
                            </div>
                        </div>

                        <div className="mb-5">
                            <label className="block text-xs font-semibold text-surface-700 mb-1.5">
                                Reason <span className="text-surface-400 font-normal">(optional)</span>
                            </label>
                            <textarea
                                value={cancelReason}
                                onChange={e => setCancelReason(e.target.value)}
                                placeholder="e.g. Customer changed mind, duplicate order…"
                                rows={3}
                                className="w-full text-sm border border-surface-200 rounded-lg px-3 py-2 focus:outline-none focus:border-danger/60 focus:ring-1 focus:ring-danger/20 resize-none"
                                autoFocus
                            />
                        </div>

                        <div className="flex gap-3 justify-end">
                            <button
                                onClick={() => { setShowCancelConfirm(false); setCancelReason(""); }}
                                className="btn-secondary px-4 py-2 text-sm"
                                disabled={cancelMutation.isPending}
                            >
                                Keep Order
                            </button>
                            <button
                                onClick={() => cancelMutation.mutate(cancelReason)}
                                disabled={cancelMutation.isPending}
                                className="px-4 py-2 text-sm font-semibold bg-danger text-white rounded-lg hover:bg-danger/90 transition-colors disabled:opacity-50 flex items-center gap-2"
                            >
                                {cancelMutation.isPending && (
                                    <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                )}
                                {cancelMutation.isPending ? "Cancelling…" : "Cancel Order"}
                            </button>
                        </div>
                    </div>
                </Modal>
            )}
        </div>
    );
}