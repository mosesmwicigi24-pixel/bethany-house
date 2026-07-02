import React, { useState, useCallback, useRef, useEffect } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, put } from "@/api/client";
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

interface ProductionOrder {
    id: number;
    order_number: string;
    product_id: number;
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

// ── Assign Tasks Modal ────────────────────────────────────────────────────────

function AssignModal({ order, onClose, onSaved }: { order: ProductionOrder; onClose: () => void; onSaved: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [assignments, setAssignments] = useState<Record<number, string>>({});
    const [hours, setHours] = useState<Record<number, string>>({});

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
        .sort((a, b) => (a.stage?.sort_order ?? 0) - (b.stage?.sort_order ?? 0));

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
                        <div className="grid grid-cols-12 gap-3 px-3 text-2xs font-bold text-surface-400 uppercase tracking-wide min-w-[480px]">
                            <span className="col-span-4">Stage</span>
                            <span className="col-span-5">Assign to</span>
                            <span className="col-span-3">Est. hours</span>
                        </div>
                        {activeTasks.map(task => {
                            const currentId = typeof task.assigned_to === "object" ? (task.assigned_to as any)?.id?.toString() ?? "" : task.assigned_to?.toString() ?? "";
                            return (
                                <div key={task.id} className="grid grid-cols-12 gap-3 items-center p-3 rounded-xl bg-surface-50 border border-surface-100">
                                    <div className="col-span-4">
                                        <p className="text-sm font-semibold text-surface-900 flex items-center gap-1.5">
                                            <StageIcon slug={task.stage?.slug} className="w-3.5 h-3.5 text-surface-500" />
                                            {task.stage?.name ?? `Stage ${task.production_stage_id}`}
                                        </p>
                                        <span className={clsx("text-2xs font-medium mt-0.5", task.status === "in_progress" ? "text-brand-600" : "text-surface-400")}>
                                            {task.status === "in_progress" ? "In progress" : "Pending"}
                                        </span>
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

function StagesPipeline({
    tasks,
    currentUserId,
    onTaskAction,
    taskActionPending,
}: {
    tasks: Task[];
    currentUserId: number | null;
    onTaskAction: (taskId: number, action: "start" | "complete" | "pause") => void;
    taskActionPending: boolean;
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

    return (
        <div className="space-y-2">
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
                const canStart    = isMyTask && (task.status === "pending" || task.status === "paused");
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
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    {isMyTask && (
                                        <span className="text-2xs font-semibold text-brand-600 bg-brand-50 border border-brand-200 px-1.5 py-0.5 rounded-full">
                                            My task
                                        </span>
                                    )}
                                    <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", badgeColor)}>
                                        {badgeLabel}
                                    </span>
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
                                {task.estimated_hours != null && (
                                    <span className="flex items-center gap-1">
                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Est. {task.estimated_hours}h
                                        {task.actual_hours != null && ` · Actual ${task.actual_hours}h`}
                                    </span>
                                )}
                                {task.started_at && <span>Started {fmtDate(task.started_at)}</span>}
                                {task.completed_at && <span>Completed {fmtDate(task.completed_at)}</span>}
                            </div>

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
                                className="pointer-events-none text-xs leading-5 whitespace-pre-wrap break-words select-none w-full"
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

function KeyValueGrid({ data, colorClass = "bg-surface-50" }: { data: Record<string, string>; colorClass?: string }) {
    const entries = Object.entries(data).filter(([, v]) => v);
    if (!entries.length) return null;
    return (
        <div className={clsx("rounded-xl p-3 space-y-1.5", colorClass)}>
            {entries.map(([k, v]) => (
                <div key={k} className="flex gap-3 text-xs">
                    <span className="text-surface-400 w-36 shrink-0 capitalize">{k.replace(/_/g, " ")}</span>
                    <span className="font-medium text-surface-800 flex-1">{v}</span>
                </div>
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
    const [tab, setTab] = useState<"stages" | "materials" | "specs" | "activity" | "audit">("stages");
    const [modal, setModal] = useState<"assign" | "materials" | "qc" | "complete" | null>(null);
    const [showCancelConfirm, setShowCancelConfirm] = useState(false);
    const [cancelReason, setCancelReason] = useState("");

    // Current user — to detect which stages they are assigned to
    const currentUserId = useAuthStore(s => s.user?.id ?? null);

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
    const sortedTasks = [...(order.tasks ?? [])].sort((a, b) => (a.stage?.sort_order ?? 0) - (b.stage?.sort_order ?? 0));
    const allocations = order.material_allocations ?? [];
    const isCustomer  = !!order.customer_order_id;
    const days        = daysUntil(order.due_date);
    const hasSpecs    = !!(order.measurements || order.specifications || order.customer_preferences);

    const { can } = usePermissions();
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
    const canOpenWIP  = ["pending", "in_progress", "on_hold", "qc_pending", "qc_passed", "qc_failed"].includes(order.status);

    const tabs = [
        { key: "stages",    label: `⚙️ Stages (${sortedTasks.length})` },
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
                <div className={clsx("px-5 py-5 sm:px-8 sm:py-6 bg-gradient-to-r",
                    order.status === "completed" ? "from-emerald-800 to-teal-700" :
                    order.status === "cancelled" ? "from-slate-700 to-slate-600" :
                    order.status === "qc_failed" ? "from-red-800 to-red-700" :
                    "from-slate-800 to-slate-700")}>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
                        <div>
                            <p className="text-slate-400 text-xs font-semibold uppercase tracking-widest mb-1">Production Order</p>
                            <h1 className="text-2xl font-bold text-white font-mono">{order.order_number}</h1>
                            <p className="text-lg text-white/80 mt-0.5">{order.product_name}</p>
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
                        </div>
                        <div className="sm:text-right">
                            <p className="text-slate-400 text-2xs uppercase tracking-widest mb-1">Quantity</p>
                            <p className="text-4xl font-bold text-white tabular-nums">{order.quantity}</p>
                            <p className={clsx("text-sm mt-1 font-medium", days < 0 ? "text-red-300" : days <= 2 ? "text-amber-300" : "text-slate-400")}>
                                {days < 0 ? `${Math.abs(days)}d overdue` : days === 0 ? "Due today" : `${days}d until due`}
                            </p>
                            <p className="text-slate-400 text-xs">{fmtDate(order.due_date)}</p>
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
                <div className="px-5 py-3 bg-slate-50 border-b border-surface-100 flex flex-wrap items-center gap-2 sm:px-8">
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

                        {/* Linked sales order */}
                        {isCustomer && (
                            <div className="flex items-center gap-3 p-3 rounded-xl border bg-indigo-50 border-indigo-100">
                                <div className="w-8 h-8 rounded-lg bg-indigo-500 text-white flex items-center justify-center shrink-0">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                    </svg>
                                </div>
                                <div className="flex-1">
                                    <p className="text-2xs text-indigo-500 font-semibold uppercase tracking-widest">Linked Sales Order</p>
                                    {order.customer_order ? (
                                        <Link to={`/sales/orders/${order.customer_order_id}`}
                                            className="text-sm font-bold text-indigo-800 font-mono hover:underline">
                                            {order.customer_order.order_number}
                                        </Link>
                                    ) : (
                                        <p className="text-sm font-bold text-indigo-800">Order #{order.customer_order_id}</p>
                                    )}
                                    {order.customer_order && (order.customer_order.customer_first_name || order.customer_order.customer_last_name) && (
                                        <p className="text-xs text-indigo-700 font-medium mt-0.5">{[order.customer_order.customer_first_name, order.customer_order.customer_last_name].filter(Boolean).join(" ")}</p>
                                    )}
                                    {order.customer_order?.customer_phone && (
                                        <p className="text-xs text-indigo-500">{order.customer_order.customer_phone}</p>
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
                            currentUserId={currentUserId}
                            onTaskAction={(taskId, action) => taskMutation.mutate({ taskId, action })}
                            taskActionPending={taskMutation.isPending}
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
                                {order.measurements && Object.keys(order.measurements).length > 0 && (
                                    <div><SectionLabel>Measurements</SectionLabel><KeyValueGrid data={order.measurements} colorClass="bg-blue-50" /></div>
                                )}
                                {order.specifications && Object.keys(order.specifications).length > 0 && (
                                    <div><SectionLabel>Specifications</SectionLabel><KeyValueGrid data={order.specifications} colorClass="bg-surface-50" /></div>
                                )}
                                {order.customer_preferences && Object.keys(order.customer_preferences).length > 0 && (
                                    <div><SectionLabel>Customer Preferences</SectionLabel><KeyValueGrid data={order.customer_preferences} colorClass="bg-indigo-50" /></div>
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
                                <img src={order.product_image} alt={order.product_name} className="w-full h-40 object-cover" />
                            </div>
                        )}

                        {/* Stage progress mini */}
                        <div>
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
                            <SectionLabel>Order Info</SectionLabel>
                            <InfoRow label="Order #" value={<span className="font-mono text-2xs">{order.order_number}</span>} />
                            <InfoRow label="Quantity" value={<strong>{order.quantity}</strong>} />
                            <InfoRow label="Due Date" value={
                                <span className={clsx("font-semibold", days < 0 ? "text-red-600" : days <= 2 ? "text-amber-600" : "")}>
                                    {fmtDate(order.due_date)}
                                </span>
                            } />
                            {order.outlet && <InfoRow label="Outlet" value={order.outlet.name} />}
                            {order.started_at && <InfoRow label="Started" value={fmtDate(order.started_at)} />}
                            {order.completed_at && <InfoRow label="Completed" value={fmtDate(order.completed_at)} />}
                            {order.confirmed_at && <InfoRow label="Confirmed" value={fmtDate(order.confirmed_at)} />}
                            {order.created_by && <InfoRow label="Created By" value={`${order.created_by.first_name} ${order.created_by.last_name}`.trim()} />}
                        </div>
                    </div>
                </div>
            </div>

            {/* Modals */}
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