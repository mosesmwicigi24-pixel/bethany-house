import { useState, useRef } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { get, post, put } from "@/api/client";
import {
    shipmentsApi,
    SHIPMENT_STATUS_LABELS,
    TRACKING_MILESTONES,
    type ShipmentDetail,
    type ShipmentStatus,
    type TrackingEvent,
} from "@/api/shipments";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import { PdfDownloadButton } from "@/hooks/usePdfDownload";

// ── Status config ─────────────────────────────────────────────────────────────

const STATUS_BADGE: Record<string, string> = {
    order_confirmed:    "bg-blue-50 text-blue-700",
    processing:         "bg-blue-50 text-blue-700",
    ready_to_ship:      "bg-purple-50 text-purple-700",
    picked_up:          "bg-purple-50 text-purple-700",
    in_transit:         "bg-amber-50 text-amber-700",
    out_for_delivery:   "bg-amber-50 text-amber-700",
    delivery_attempted: "bg-red-50 text-red-700",
    delivered:          "bg-emerald-50 text-emerald-700",
    exception:          "bg-red-50 text-red-700",
    cancelled:          "bg-surface-100 text-surface-500",
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const fmtDate = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleDateString("en-KE", { day: "2-digit", month: "short", year: "numeric" }) : "-";

const fmtDateTime = (d: string | null | undefined) =>
    d ? new Date(d).toLocaleString("en-KE", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "-";

// ── Audit Trail ───────────────────────────────────────────────────────────────

interface AuditEntry {
    id: number; event: string; label: string; description: string;
    properties: Record<string, any>; actor_name: string; created_at: string;
}

function AuditTrail({ shipmentId }: { shipmentId: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ["shipment-audit", shipmentId],
        queryFn: () => get<{ logs: AuditEntry[] }>(`/v1/admin/shipments/${shipmentId}/audit-log`),
        staleTime: 30_000,
    });
    const logs = data?.logs ?? [];
    if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;
    if (!logs.length) return <div className="text-center py-12 text-xs text-surface-400">No audit entries yet.</div>;
    return (
        <div className="divide-y divide-surface-50">
            {logs.map((entry) => (
                <div key={entry.id} className="flex gap-3 py-3.5">
                    <div className="w-7 h-7 rounded-full bg-brand-100 flex items-center justify-center shrink-0 mt-0.5">
                        <svg className="w-3 h-3 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div className="flex-1 min-w-0">
                        <div className="flex items-start justify-between gap-2">
                            <span className="text-xs font-semibold text-surface-800">
                                {entry.label}
                                <span className="font-normal text-surface-500 ml-1">· {entry.actor_name}</span>
                            </span>
                            <span className="text-2xs text-surface-400 shrink-0">{fmtDateTime(entry.created_at)}</span>
                        </div>
                        <p className="text-xs text-surface-600 mt-0.5">{entry.description}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Add Tracking Event Modal ──────────────────────────────────────────────────

const ALL_STATUSES: ShipmentStatus[] = [
    "processing", "ready_to_ship", "picked_up",
    "in_transit", "out_for_delivery", "delivery_attempted",
    "delivered", "exception",
];

function AddTrackingModal({ shipmentId, onClose, onDone }: {
    shipmentId: number; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();
    const [form, setForm] = useState({
        status: "in_transit" as ShipmentStatus,
        description: "",
        location: "",
        is_public: true,
    });
    // Each pending file paired with its own customer-visibility flag.
    const [files, setFiles] = useState<{ file: File; isPublic: boolean }[]>([]);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const mutation = useMutation({
        mutationFn: async () => {
            const res = await shipmentsApi.addTracking(shipmentId, {
                status:      form.status,
                // Optional - send undefined rather than an empty string so the
                // backend's nullable validation treats a blank field as "no note".
                description: form.description.trim() || undefined,
                location:    form.location || undefined,
                is_public:   form.is_public,
            });
            if (files.length > 0) {
                // The tracking event id isn't returned by addTracking - fetch
                // the shipment detail and find the event we just created by
                // matching status (mirrors the existing pattern in ShipmentSection).
                const detail = await get<any>(`/v1/admin/shipments/${shipmentId}`);
                const history = detail?.tracking_history ?? [];
                const latest  = [...history].reverse().find((e: any) => e.status === form.status);
                if (latest?.id) {
                    await shipmentsApi.uploadTrackingAttachment(shipmentId, latest.id, files);
                }
            }
            return res;
        },
        onSuccess: () => { toast.success("Tracking event added"); onDone(); onClose(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed to add tracking event"),
    });

    const set = (key: string, val: any) => setForm(f => ({ ...f, [key]: val }));

    const addFiles = (fileList: FileList | null) => {
        if (!fileList) return;
        const next = Array.from(fileList).map(file => ({ file, isPublic: false }));
        setFiles(prev => [...prev, ...next]);
    };

    const toggleFilePublic = (idx: number) =>
        setFiles(prev => prev.map((f, i) => i === idx ? { ...f, isPublic: !f.isPublic } : f));

    const removeFile = (idx: number) =>
        setFiles(prev => prev.filter((_, i) => i !== idx));

    return (
        <Modal open title="Add Tracking Event" onClose={onClose}>
            <div className="p-5 space-y-4">
                <div>
                    <label className="label">Status</label>
                    <select className="input" value={form.status} onChange={e => set("status", e.target.value)}>
                        {ALL_STATUSES.map(s => (
                            <option key={s} value={s}>{SHIPMENT_STATUS_LABELS[s]}</option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="label">Description <span className="text-surface-400">(optional)</span></label>
                    <input className="input" value={form.description}
                        onChange={e => set("description", e.target.value)}
                        placeholder="e.g. Package scanned at sorting facility" />
                    <p className="text-2xs text-surface-400 mt-1">
                        Shown to the customer on the tracking page if you add one and leave "Visible to customer" checked below.
                    </p>
                </div>
                <div>
                    <label className="label">Location</label>
                    <input className="input" value={form.location}
                        onChange={e => set("location", e.target.value)}
                        placeholder="e.g. Nairobi Sorting Hub" />
                </div>
                <div className="flex items-center gap-2">
                    <input type="checkbox" id="is_public" checked={form.is_public}
                        onChange={e => set("is_public", e.target.checked)}
                        className="rounded border-surface-300" />
                    <label htmlFor="is_public" className="text-xs text-surface-700">
                        Visible to customer on public tracking page
                    </label>
                </div>

                {/* Attachments */}
                <div>
                    <label className="label">Attachments <span className="text-surface-400">(optional)</span></label>
                    <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        className="w-full border-2 border-dashed border-surface-200 rounded-xl p-3 text-xs text-surface-500 hover:border-surface-300 transition-colors"
                    >
                        + Add photo or document
                    </button>
                    <input
                        ref={fileInputRef}
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                        className="hidden"
                        onChange={e => { addFiles(e.target.files); e.target.value = ""; }}
                    />
                    {files.length > 0 && (
                        <div className="mt-2 space-y-1.5">
                            {files.map((f, idx) => (
                                <div key={idx} className="flex items-center gap-2 p-2 bg-surface-50 rounded-lg border border-surface-100">
                                    <span className="text-xs text-surface-700 truncate flex-1">{f.file.name}</span>
                                    <label className="flex items-center gap-1.5 text-2xs text-surface-500 shrink-0">
                                        <input type="checkbox" checked={f.isPublic}
                                            onChange={() => toggleFilePublic(idx)}
                                            className="rounded border-surface-300" />
                                        Visible to customer
                                    </label>
                                    <button type="button" onClick={() => removeFile(idx)}
                                        className="text-surface-300 hover:text-danger shrink-0" aria-label="Remove">
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Saving…" : "Add Event"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Edit Shipment Modal ───────────────────────────────────────────────────────
// Gated by shipment.edit - see the permission check in the main component.
// Exported so ShipmentSection.tsx (the order-detail sidebar widget) can
// reuse this exact component rather than duplicating it.

export function EditShipmentModal({ shipment, onClose, onDone }: {
    shipment: any; onClose: () => void; onDone: () => void;
}) {
    const toast = useToastStore();
    const [form, setForm] = useState({
        carrier:                  shipment.carrier ?? "",
        tracking_number:          shipment.tracking_number ?? "",
        carrier_tracking_url:     shipment.carrier_tracking_url ?? "",
        estimated_delivery_date:  shipment.estimated_delivery_date
            ? new Date(shipment.estimated_delivery_date).toISOString().split("T")[0]
            : "",
        notes:                    shipment.notes ?? "",
    });

    const set = (key: string, val: string) => setForm(f => ({ ...f, [key]: val }));

    const mutation = useMutation({
        mutationFn: () => put(`/v1/admin/shipments/${shipment.id}`, {
            carrier:                 form.carrier,
            tracking_number:         form.tracking_number || undefined,
            carrier_tracking_url:    form.carrier_tracking_url || undefined,
            estimated_delivery_date: form.estimated_delivery_date || undefined,
            notes:                   form.notes || undefined,
        }),
        onSuccess: () => { toast.success("Shipment updated"); onDone(); onClose(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed to update shipment"),
    });

    return (
        <Modal open title="Edit Shipment" onClose={onClose}>
            <div className="p-5 space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <div className="col-span-2 sm:col-span-1">
                        <label className="label">Carrier <span className="text-danger">*</span></label>
                        <input className="input" value={form.carrier}
                            onChange={e => set("carrier", e.target.value)} placeholder="e.g. DHL, G4S, Sendy" />
                    </div>
                    <div className="col-span-2 sm:col-span-1">
                        <label className="label">Tracking Number</label>
                        <input className="input font-mono" value={form.tracking_number}
                            onChange={e => set("tracking_number", e.target.value)} placeholder="Carrier tracking ref" />
                    </div>
                </div>
                <div>
                    <label className="label">Est. Delivery Date</label>
                    <input type="date" className="input" value={form.estimated_delivery_date}
                        onChange={e => set("estimated_delivery_date", e.target.value)} />
                </div>
                <div>
                    <label className="label">Carrier Tracking URL</label>
                    <input type="url" className="input" value={form.carrier_tracking_url}
                        onChange={e => set("carrier_tracking_url", e.target.value)}
                        placeholder="https://track.carrier.com/…" />
                </div>
                <div>
                    <label className="label">Notes <span className="text-surface-400">(internal)</span></label>
                    <textarea className="input resize-none" rows={2} value={form.notes}
                        onChange={e => set("notes", e.target.value)} placeholder="Any dispatch notes…" />
                </div>
                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button onClick={() => mutation.mutate()} disabled={mutation.isPending || !form.carrier.trim()}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Saving…" : "Save Changes"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

function MilestoneBar({ currentStatus, milestoneIndex }: { currentStatus: string; milestoneIndex: number }) {
    if (currentStatus === "cancelled" || currentStatus === "exception") {
        return (
            <div className={clsx("inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold",
                currentStatus === "cancelled" ? "bg-surface-200 text-surface-600" : "bg-red-100 text-red-700")}>
                {currentStatus === "cancelled" ? "🚫 Cancelled" : "⚠️ Exception"}
            </div>
        );
    }

    return (
        <div className="flex items-center gap-0 w-full max-w-xs sm:max-w-md overflow-x-auto no-scrollbar">
            {TRACKING_MILESTONES.map((status, idx) => {
                const done    = idx < milestoneIndex;
                const current = idx === milestoneIndex;
                const upcoming = idx > milestoneIndex;
                return (
                    <div key={status} className="flex items-center flex-1 last:flex-none">
                        <div title={SHIPMENT_STATUS_LABELS[status]}
                            className={clsx("w-5 h-5 rounded-full shrink-0 flex items-center justify-center border-2 transition-all",
                                done    ? "bg-emerald-500 border-emerald-500" :
                                current ? "bg-white border-brand-500" :
                                "bg-white border-surface-200")}>
                            {done ? (
                                <svg className="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            ) : current ? (
                                <div className="w-2 h-2 rounded-full bg-brand-500" />
                            ) : null}
                        </div>
                        <div className={clsx("flex-1 h-0.5 last:hidden transition-all", done ? "bg-emerald-400" : "bg-surface-200")} />
                    </div>
                );
            })}
        </div>
    );
}

// ── Tracking Timeline ─────────────────────────────────────────────────────────

function TrackingTimeline({ events }: { events: TrackingEvent[] }) {
    if (!events.length) return (
        <div className="text-center py-10 text-xs text-surface-400">No tracking events yet.</div>
    );

    // Show newest first
    const sorted = [...events].reverse();

    return (
        <div className="relative">
            {/* Vertical line */}
            <div className="absolute left-3.5 top-3 bottom-3 w-px bg-surface-200" />

            <div className="space-y-4">
                {sorted.map((event, idx) => {
                    const isLatest = idx === 0;
                    const isDelivered = event.status === "delivered";
                    return (
                        <div key={idx} className="flex gap-4 relative">
                            <div className={clsx(
                                "w-7 h-7 rounded-full flex items-center justify-center shrink-0 z-10 border-2",
                                isDelivered ? "bg-emerald-500 border-emerald-500" :
                                isLatest    ? "bg-brand-500 border-brand-500" :
                                "bg-white border-surface-300"
                            )}>
                                {isDelivered ? (
                                    <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                ) : (
                                    <div className={clsx("w-2 h-2 rounded-full", isLatest ? "bg-white" : "bg-surface-400")} />
                                )}
                            </div>

                            <div className={clsx("flex-1 pb-4 border-b border-surface-50 last:border-0", isLatest && "")}>
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <span className={clsx("text-xs font-semibold",
                                            isDelivered ? "text-emerald-700" : isLatest ? "text-brand-700" : "text-surface-700")}>
                                            {SHIPMENT_STATUS_LABELS[event.status] ?? event.status}
                                        </span>
                                        {event.location && (
                                            <span className="ml-2 text-2xs text-surface-400">📍 {event.location}</span>
                                        )}
                                        {!event.is_public && (
                                            <span className="ml-2 text-2xs bg-surface-100 text-surface-500 px-1.5 py-0.5 rounded">Admin only</span>
                                        )}
                                    </div>
                                    <span className="text-2xs text-surface-400 shrink-0 tabular-nums">
                                        {fmtDateTime(event.event_time)}
                                    </span>
                                </div>
                                {event.description && (
                                    <p className="text-xs text-surface-600 mt-0.5">{event.description}</p>
                                )}
                                {event.added_by_name && (
                                    <p className="text-2xs text-surface-400 mt-0.5">Added by {event.added_by_name}</p>
                                )}
                                {(event.attachments ?? []).map((a) => (
                                    <a key={a.id} href={a.url} target="_blank" rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 mt-1.5 mr-2 text-2xs text-brand-600 hover:underline">
                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                        </svg>
                                        {a.name}
                                        {!a.is_public && (
                                            <span className="text-surface-400">(internal)</span>
                                        )}
                                    </a>
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// ── Sidebar InfoRow ───────────────────────────────────────────────────────────

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

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function ShipmentDetailPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    const toast = useToastStore();
    const qc = useQueryClient();
    const { can } = usePermissions();
    const [tab, setTab] = useState<"tracking" | "audit">("tracking");
    const [showAddTracking, setShowAddTracking] = useState(false);
    const [showEditShipment, setShowEditShipment] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ["shipment-detail", id],
        queryFn: () => shipmentsApi.get(Number(id)),
        enabled: !!id,
        staleTime: 30_000,
    });

    const shipment = data;
    const refresh = () => {
        qc.invalidateQueries({ queryKey: ["shipment-detail", id] });
        qc.invalidateQueries({ queryKey: ["shipment-audit", Number(id)] });
    };

    const deliverMutation = useMutation({
        mutationFn: () => shipmentsApi.markDelivered(Number(id)),
        onSuccess: () => { toast.success("Shipment marked as delivered"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed"),
    });

    const cancelMutation = useMutation({
        mutationFn: (reason: string) => shipmentsApi.cancel(Number(id), reason),
        onSuccess: () => { toast.success("Shipment cancelled"); refresh(); },
        onError: (e: any) => toast.error(e?.message ?? "Failed"),
    });

    const copyTrackingUrl = () => {
        const url = shipment?.tracking_url;
        if (!url) { toast.error("No tracking URL available"); return; }
        navigator.clipboard.writeText(url).then(() => toast.success("Tracking link copied!"));
    };

    if (isLoading) return <div className="flex items-center justify-center h-64"><Spinner /></div>;
    if (!shipment) return (
        <div className="text-center py-16 text-surface-400 text-sm">
            Shipment not found.
            <button onClick={() => navigate("/sales/shipments")} className="block mt-3 btn-secondary mx-auto">Back</button>
        </div>
    );

    const s = (shipment as any).shipment ?? shipment;
    const trackingHistory: TrackingEvent[] = (shipment as any).tracking_history ?? [];
    const milestoneIdx: number = (shipment as any).milestone_index ?? 0;
    const trackingUrl: string | null = (shipment as any).tracking_url ?? null;

    const isDelivered  = s.status === "delivered";
    const isCancelled  = s.status === "cancelled";
    const canDeliver   = !isDelivered && !isCancelled;
    const canCancel    = !isDelivered && !isCancelled;
    const canEdit      = can("shipment.edit") && !isDelivered && !isCancelled;
    const customerName = [s.customer_first_name, s.customer_last_name].filter(Boolean).join(" ") || null;

    return (
        <div className="max-w-5xl mx-auto">
            <button onClick={() => navigate("/sales/shipments")}
                className="flex items-center gap-1.5 text-xs text-surface-500 hover:text-surface-800 mb-4 transition-colors">
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Shipments
            </button>

            <div className="bg-white rounded-2xl shadow-sm border border-surface-200 overflow-hidden">

                {/* Header band */}
                <div className={clsx("px-5 py-5 sm:px-8 sm:py-6 bg-gradient-to-r",
                    isDelivered  ? "from-emerald-800 to-emerald-700" :
                    isCancelled  ? "from-slate-700 to-slate-600" :
                    "from-purple-800 to-purple-700")}>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:flex-wrap">
                        <div>
                            <p className="text-white/60 text-xs font-semibold uppercase tracking-widest mb-1">Shipment</p>
                            <h1 className="text-2xl font-bold text-white font-mono">{s.shipment_number}</h1>
                            <div className="flex items-center gap-2 mt-2 flex-wrap">
                                <span className={clsx("px-2.5 py-1 rounded-full text-xs font-semibold", STATUS_BADGE[s.status] ?? "bg-surface-100 text-surface-600")}>
                                    {SHIPMENT_STATUS_LABELS[s.status as ShipmentStatus] ?? s.status}
                                </span>
                                <Link to={`/sales/orders/${s.order_id}`}
                                    className="text-xs text-white/80 hover:text-white font-mono underline-offset-2 hover:underline">
                                    Order {s.order_number}
                                </Link>
                            </div>
                        </div>

                        <div className="flex flex-col items-start gap-2 sm:items-end">
                            {/* Milestone progress */}
                            <MilestoneBar currentStatus={s.status} milestoneIndex={milestoneIdx} />
                            {/* Tracking URL */}
                            {trackingUrl && (
                                <button onClick={copyTrackingUrl}
                                    className="flex items-center gap-1.5 text-2xs text-white/70 hover:text-white transition-colors">
                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                    </svg>
                                    Copy tracking link
                                </button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Action bar */}
                <div className="px-5 py-3 bg-slate-50 border-b border-surface-100 flex flex-wrap items-center gap-2 sm:px-8">
                    <button onClick={() => setShowAddTracking(true)}
                        className="btn-sm bg-brand-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-brand-700">
                        + Add Tracking Event
                    </button>
                    {canEdit && (
                        <button onClick={() => setShowEditShipment(true)}
                            className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-surface-300">
                            Edit Details
                        </button>
                    )}
                    {canDeliver && (
                        <button onClick={() => deliverMutation.mutate()} disabled={deliverMutation.isPending}
                            className="btn-sm bg-emerald-600 text-white rounded-xl px-3 py-1.5 text-xs font-semibold hover:bg-emerald-700">
                            Mark Delivered
                        </button>
                    )}
                    {trackingUrl && (
                        <a href={trackingUrl} target="_blank" rel="noopener noreferrer"
                            className="btn-sm bg-white border border-surface-200 rounded-xl px-3 py-1.5 text-xs font-semibold text-surface-700 hover:border-surface-300">
                            View Public Tracking ↗
                        </a>
                    )}
                    <PdfDownloadButton type="shipments" id={s.id} label="Download PDF" />
                    {canCancel && (
                        <button onClick={() => {
                            const reason = window.prompt("Reason for cancellation:");
                            if (reason) cancelMutation.mutate(reason);
                        }} disabled={cancelMutation.isPending}
                            className="btn-sm bg-white text-danger border border-danger/30 rounded-xl px-3 py-1.5 text-xs font-semibold ml-auto">
                            Cancel Shipment
                        </button>
                    )}
                </div>

                {/* Body */}
                <div className="px-5 py-5 grid grid-cols-1 lg:grid-cols-[1fr_240px] gap-6 lg:gap-8 sm:px-8 sm:py-6 lg:divide-x divide-surface-100">

                    {/* Left */}
                    <div className="space-y-4 lg:pr-8">
                        <div className="flex border-b border-surface-100">
                            {(["tracking", "audit"] as const).map((t) => (
                                <button key={t} onClick={() => setTab(t)}
                                    className={clsx("px-4 py-2.5 text-xs font-semibold border-b-2 transition-all",
                                        tab === t ? "border-brand-500 text-brand-600" : "border-transparent text-surface-400 hover:text-surface-700")}>
                                    {t === "tracking" ? `📍 Tracking Events (${trackingHistory.length})` : "🕐 Audit Trail"}
                                </button>
                            ))}
                        </div>

                        {tab === "tracking" && <TrackingTimeline events={trackingHistory} />}
                        {tab === "audit" && <AuditTrail shipmentId={s.id} />}

                        {s.notes && (
                            <div className="p-4 bg-surface-50 rounded-xl border border-surface-100">
                                <SectionLabel>Notes</SectionLabel>
                                <p className="text-xs text-surface-700 whitespace-pre-wrap">{s.notes}</p>
                            </div>
                        )}

                        {/* Waybill / attachments */}
                        {((s as any).attachments ?? []).length > 0 && (
                            <div className="p-4 bg-surface-50 rounded-xl border border-surface-100 space-y-1.5">
                                <SectionLabel>Waybill / Documents</SectionLabel>
                                {((s as any).attachments ?? []).map((a: any) => (
                                    <a key={a.id} href={a.url} target="_blank" rel="noopener noreferrer"
                                        className="flex items-center gap-2 text-xs text-brand-600 hover:underline">
                                        <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" />
                                        </svg>
                                        <span className="truncate">{a.name}</span>
                                        {!a.is_public && (
                                            <span className="text-2xs text-surface-400 shrink-0">(internal only)</span>
                                        )}
                                    </a>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Right sidebar */}
                    <div className="lg:pl-8 space-y-6">
                        {/* Carrier */}
                        <div>
                            <SectionLabel>Carrier</SectionLabel>
                            <div className="p-3 bg-surface-50 rounded-xl">
                                <p className="text-sm font-bold text-surface-800">{s.carrier}</p>
                                {s.tracking_number && (
                                    <p className="text-xs text-surface-500 font-mono mt-0.5">{s.tracking_number}</p>
                                )}
                                {s.carrier_tracking_url && (
                                    <a href={s.carrier_tracking_url} target="_blank" rel="noopener noreferrer"
                                        className="text-2xs text-brand-600 hover:underline mt-1 block">
                                        Carrier tracking ↗
                                    </a>
                                )}
                            </div>
                        </div>

                        {/* Customer / Ship To */}
                        {(customerName || s.shipping_address_line1) && (
                            <div>
                                <SectionLabel>Ship To</SectionLabel>
                                <div className="p-3 bg-surface-50 rounded-xl space-y-0.5">
                                    {customerName && <p className="text-sm font-semibold text-surface-800">{customerName}</p>}
                                    {s.customer_email && <p className="text-xs text-surface-500">{s.customer_email}</p>}
                                    {s.customer_phone && <p className="text-xs text-surface-500">{s.customer_phone}</p>}
                                    {s.shipping_address_line1 && <p className="text-xs text-surface-500 mt-1">{s.shipping_address_line1}</p>}
                                    {s.shipping_city && <p className="text-xs text-surface-500">{s.shipping_city}{s.shipping_country_code ? `, ${s.shipping_country_code}` : ""}</p>}
                                </div>
                            </div>
                        )}

                        {/* Dates */}
                        <div>
                            <SectionLabel>Key Dates</SectionLabel>
                            <InfoRow label="Shipped" value={fmtDate(s.shipped_at)} />
                            <InfoRow label="Est. Delivery" value={fmtDate(s.estimated_delivery_date)} />
                            {s.delivered_at && <InfoRow label="Delivered" value={fmtDate(s.delivered_at)} />}
                        </div>

                        {/* Linked order */}
                        <div>
                            <SectionLabel>Linked Order</SectionLabel>
                            <Link to={`/sales/orders/${s.order_id}`}
                                className="flex items-center justify-between p-3 bg-brand-50 rounded-xl border border-brand-100 hover:bg-brand-100 transition-colors">
                                <span className="text-sm font-mono font-semibold text-brand-700">{s.order_number}</span>
                                <svg className="w-3.5 h-3.5 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            {showAddTracking && (
                <AddTrackingModal
                    shipmentId={s.id}
                    onClose={() => setShowAddTracking(false)}
                    onDone={refresh}
                />
            )}

            {showEditShipment && (
                <EditShipmentModal
                    shipment={s}
                    onClose={() => setShowEditShipment(false)}
                    onDone={refresh}
                />
            )}
        </div>
    );
}