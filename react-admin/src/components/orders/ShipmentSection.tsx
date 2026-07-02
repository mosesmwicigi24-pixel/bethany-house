/**
 * ShipmentSection
 *
 * Dropped into the right column of OrderDetailPage.
 * Shows active shipment status, the public tracking URL (copy + email),
 * and an "Add Tracking Event" form for admin users.
 *
 * Usage:
 *   import { ShipmentSection } from "@/components/orders/ShipmentSection";
 *   <ShipmentSection orderId={order.id} orderStatus={order.status} />
 */

import { useState, useEffect } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { shipmentsApi, SHIPMENT_STATUS_LABELS, TRACKING_MILESTONES } from "@/api/shipments";
import type { ShipmentStatus, TrackingEvent, ShipmentAttachment } from "@/api/shipments";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { tokenStorage } from "@/api/client";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

// ── Authenticated attachment fetch ────────────────────────────────────────────
// Attachment URLs point at /admin/shipments/... routes, which require a
// Bearer token (this app has no cookie/session auth - see api/client.ts).
// A plain <a href> or <img src> never sends that token, so both inline
// previews AND downloads would silently 401. Every other place in this app
// that serves protected binary content (ProofViewer, usePdfDownload) works
// around this the same way: fetch() with the token attached, then turn the
// response into a local blob: URL the browser can render or save normally.

async function fetchAttachmentBlob(url: string): Promise<{ blobUrl: string; mimeType: string }> {
    const token = tokenStorage.get();
    const res = await fetch(url, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
    });
    if (!res.ok) {
        throw new Error(`Failed to load attachment (${res.status})`);
    }
    const mimeType = res.headers.get("Content-Type") ?? "application/octet-stream";
    const blob = await res.blob();
    return { blobUrl: URL.createObjectURL(blob), mimeType };
}

function triggerBlobDownload(blobUrl: string, filename: string) {
    const a = document.createElement("a");
    a.href = blobUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
    order_confirmed:     "badge-info",
    processing:          "badge-info",
    ready_to_ship:       "bg-purple-100 text-purple-700",
    picked_up:           "bg-purple-100 text-purple-700",
    in_transit:          "badge-warning",
    out_for_delivery:    "badge-warning",
    delivery_attempted:  "badge-danger",
    delivered:           "badge-success",
    exception:           "badge-danger",
    cancelled:           "badge-neutral",
};

// Status options shown in the "Add Event" form - ordered by pipeline flow
const TRACKING_STATUS_OPTIONS: { value: ShipmentStatus; label: string }[] = [
    { value: "order_confirmed",     label: "Order Confirmed" },
    { value: "processing",          label: "Processing" },
    { value: "ready_to_ship",       label: "Ready to Ship" },
    { value: "picked_up",           label: "Picked Up by Courier" },
    { value: "in_transit",          label: "In Transit" },
    { value: "out_for_delivery",    label: "Out for Delivery" },
    { value: "delivery_attempted",  label: "Delivery Attempted" },
    { value: "delivered",           label: "Delivered" },
    { value: "exception",           label: "Exception / Issue" },
];

// ── Attachment preview modal (admin view) ─────────────────────────────────────
// Images show enlarged with a Download button; PDFs show an embedded preview
// (via <iframe>) with the same Download button; anything else falls back to
// a simple "no inline preview available" message plus Download.
//
// The attachment is fetched once as an authenticated blob (see
// fetchAttachmentBlob above) and that blob: URL is used for both the inline
// preview and the download - the protected /admin/shipments/... URL itself
// is never used directly in the DOM.

function AttachmentPreviewModal({
    attachment,
    onClose,
}: {
    attachment: ShipmentAttachment;
    onClose: () => void;
}) {
    const toast = useToastStore();
    const [blobUrl, setBlobUrl] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError]     = useState<string | null>(null);

    useEffect(() => {
        let revoked = false;
        let createdUrl: string | null = null;

        fetchAttachmentBlob(attachment.url)
            .then(({ blobUrl: url }) => {
                if (revoked) { URL.revokeObjectURL(url); return; }
                createdUrl = url;
                setBlobUrl(url);
            })
            .catch((e) => setError(e.message ?? "Failed to load attachment."))
            .finally(() => setLoading(false));

        return () => {
            revoked = true;
            if (createdUrl) URL.revokeObjectURL(createdUrl);
        };
    }, [attachment.url]);

    const isPdf = attachment.mime_type === "application/pdf";

    const handleDownload = () => {
        if (!blobUrl) return;
        triggerBlobDownload(blobUrl, attachment.name);
    };

    return (
        <Modal open title={attachment.name} onClose={onClose} size="lg">
            <div className="p-5 space-y-4">
                {loading ? (
                    <div className="flex items-center justify-center py-16">
                        <Spinner />
                    </div>
                ) : error ? (
                    <div className="rounded-xl border border-surface-100 bg-surface-50 py-12 flex flex-col items-center gap-2">
                        <p className="text-sm text-danger">{error}</p>
                        <button onClick={() => toast.error(error)} className="hidden" />
                    </div>
                ) : attachment.is_image ? (
                    <div className="rounded-xl border border-surface-100 bg-surface-50 flex items-center justify-center overflow-hidden" style={{ maxHeight: "70vh" }}>
                        <img src={blobUrl!} alt={attachment.name} className="max-w-full max-h-[70vh] object-contain" />
                    </div>
                ) : isPdf ? (
                    <iframe
                        src={blobUrl!}
                        title={attachment.name}
                        className="w-full rounded-xl border border-surface-100"
                        style={{ height: "70vh" }}
                    />
                ) : (
                    <div className="rounded-xl border border-surface-100 bg-surface-50 py-12 flex flex-col items-center gap-2">
                        <svg className="w-10 h-10 text-surface-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                        <p className="text-sm text-surface-400">No inline preview available for this file type.</p>
                    </div>
                )}

                <div className="flex items-center justify-between gap-3">
                    {!attachment.is_public && (
                        <span className="text-2xs text-surface-400">Internal only - not visible to customer</span>
                    )}
                    <button
                        type="button"
                        onClick={handleDownload}
                        disabled={!blobUrl}
                        className="btn-secondary btn-sm gap-1.5 text-xs ml-auto disabled:opacity-50"
                    >
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        Download
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Compact inline attachment link (admin view) ───────────────────────────────
// Used in the condensed "latest event" and "all events" summary views, where
// a full thumbnail row would be too heavy. Opens the same preview modal as
// AttachmentRow on click - never a direct <a href> to the protected URL.

function AttachmentInlineLink({
    attachment,
    showInternalTag = false,
}: {
    attachment: ShipmentAttachment;
    showInternalTag?: boolean;
}) {
    const [previewing, setPreviewing] = useState(false);
    return (
        <>
            <button
                type="button"
                onClick={() => setPreviewing(true)}
                className="inline-flex items-center gap-1 text-brand-600 hover:underline mt-1 mr-2"
            >
                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                </svg>
                {attachment.name}
                {showInternalTag && !attachment.is_public && (
                    <span className="text-surface-400">(internal)</span>
                )}
            </button>
            {previewing && <AttachmentPreviewModal attachment={attachment} onClose={() => setPreviewing(false)} />}
        </>
    );
}

// ── Shared attachment row (admin view) ────────────────────────────────────────
// Image attachments render as a small clickable thumbnail; everything else
// renders as a row with a type icon and a "Preview" action. Either opens
// AttachmentPreviewModal above. Used in the main shipment card and inside
// the Edit modal's "existing files" list.

function AttachmentRow({
    attachment,
    onRemove,
}: {
    attachment: ShipmentAttachment;
    /** If provided, shows a remove (×) button - used in the Edit modal. */
    onRemove?: () => void;
}) {
    const [previewing, setPreviewing] = useState(false);
    const [thumbUrl, setThumbUrl] = useState<string | null>(null);

    // Thumbnails need the same authenticated blob fetch as the full preview -
    // a plain <img src={attachment.url}> sends no Bearer token and 401s.
    useEffect(() => {
        if (!attachment.is_image) return;
        let revoked = false;
        let createdUrl: string | null = null;

        fetchAttachmentBlob(attachment.url)
            .then(({ blobUrl }) => {
                if (revoked) { URL.revokeObjectURL(blobUrl); return; }
                createdUrl = blobUrl;
                setThumbUrl(blobUrl);
            })
            .catch(() => { /* thumbnail just stays blank on failure */ });

        return () => {
            revoked = true;
            if (createdUrl) URL.revokeObjectURL(createdUrl);
        };
    }, [attachment.is_image, attachment.url]);

    if (attachment.is_image) {
        return (
            <>
                <div className="flex items-center gap-2.5">
                    <button
                        type="button"
                        onClick={() => setPreviewing(true)}
                        className="w-12 h-12 rounded-lg overflow-hidden border border-surface-200 hover:border-brand-400 transition-colors shrink-0 bg-surface-100"
                        title={attachment.name}
                    >
                        {thumbUrl && (
                            <img src={thumbUrl} alt={attachment.name} className="w-full h-full object-cover" />
                        )}
                    </button>
                    <span className="flex-1 truncate text-xs text-surface-600">{attachment.name}</span>
                    {!attachment.is_public && (
                        <span className="text-2xs text-surface-400 shrink-0">internal</span>
                    )}
                    <button type="button" onClick={() => setPreviewing(true)}
                        className="text-brand-600 hover:underline shrink-0 font-medium text-xs">View</button>
                    {onRemove && (
                        <button type="button" onClick={onRemove} className="text-surface-300 hover:text-danger shrink-0" aria-label="Remove">×</button>
                    )}
                </div>
                {previewing && <AttachmentPreviewModal attachment={attachment} onClose={() => setPreviewing(false)} />}
            </>
        );
    }

    return (
        <>
            <div className="flex items-center gap-2 text-xs border border-surface-100 rounded-xl px-3 py-2 bg-surface-50">
                {attachment.mime_type === "application/pdf" ? (
                    <svg className="w-4 h-4 text-red-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                ) : (
                    <svg className="w-4 h-4 text-surface-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                )}
                <span className="flex-1 truncate text-surface-600">{attachment.name}</span>
                {!attachment.is_public && (
                    <span className="text-2xs text-surface-400 shrink-0">internal</span>
                )}
                <button type="button" onClick={() => setPreviewing(true)}
                    className="text-brand-600 hover:underline shrink-0 font-medium">View</button>
                {onRemove && (
                    <button
                        type="button"
                        onClick={onRemove}
                        className="text-surface-300 hover:text-danger shrink-0"
                        aria-label="Remove"
                    >
                        ×
                    </button>
                )}
            </div>
            {previewing && <AttachmentPreviewModal attachment={attachment} onClose={() => setPreviewing(false)} />}
        </>
    );
}

// ── Add Tracking Event Modal ──────────────────────────────────────────────────

function AddTrackingModal({
    shipmentId,
    currentStatus,
    onClose,
    onDone,
}: {
    shipmentId: number;
    currentStatus: ShipmentStatus;
    onClose: () => void;
    onDone: () => void;
}) {
    const toast = useToastStore();

    // Default to the next logical status in the pipeline
    const currentIdx    = TRACKING_MILESTONES.indexOf(currentStatus);
    const defaultStatus = (TRACKING_MILESTONES[currentIdx + 1] ?? currentStatus) as ShipmentStatus;

    const [status,      setStatus]      = useState<ShipmentStatus>(defaultStatus);
    const [description, setDescription] = useState("");
    const [location,    setLocation]    = useState("");
    const [eventTime,   setEventTime]   = useState("");
    const [isPublic,    setIsPublic]    = useState(true);
    const [files,       setFiles]       = useState<{ file: File; isPublic: boolean }[]>([]);

    const mutation = useMutation({
        mutationFn: async () => {
            // Step 1: record the tracking event
            const res = await shipmentsApi.addTracking(shipmentId, {
                status,
                // Optional - omit entirely when blank rather than sending "".
                description: description.trim() || undefined,
                location:    location   || undefined,
                event_time:  eventTime  || undefined,
                is_public:   isPublic,
            });

            // Step 2: if attachments were chosen, upload them now, each with
            // its own visibility flag.
            // The backend returns the new tracking event id; fall back to
            // fetching the latest event for this shipment if it isn't returned.
            if (files.length > 0) {
                const detail = await shipmentsApi.get(shipmentId);
                const history = (detail as any).tracking_history ?? [];
                const latest  = [...history].reverse().find((e: any) => e.status === status);
                if (latest?.id) {
                    await shipmentsApi.uploadTrackingAttachment(shipmentId, latest.id, files);
                }
            }
            return res;
        },
        onSuccess: () => { toast.success("Tracking event added"); onDone(); onClose(); },
        onError:   (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title="Add Tracking Event" onClose={onClose}>
            <div className="space-y-4 p-5">
                <div>
                    <label className="label">Status</label>
                    <select value={status} onChange={(e) => setStatus(e.target.value as ShipmentStatus)}
                        className="input">
                        {TRACKING_STATUS_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className="label">Description <span className="text-surface-400">(optional)</span></label>
                    <textarea
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        className="input resize-none"
                        rows={2}
                        placeholder="e.g. Parcel departed Nairobi sorting facility"
                        autoFocus
                    />
                    <p className="text-2xs text-surface-400 mt-1">
                        Shown to the customer on the tracking page if you add one and leave "Visible on customer tracking page" on below.
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className="label">Location <span className="text-surface-400">(optional)</span></label>
                        <input type="text" value={location} onChange={(e) => setLocation(e.target.value)}
                            className="input" placeholder="e.g. Nairobi, Kenya" />
                    </div>
                    <div>
                        <label className="label">Event Time <span className="text-surface-400">(optional)</span></label>
                        <input type="datetime-local" value={eventTime}
                            onChange={(e) => setEventTime(e.target.value)}
                            className="input" />
                    </div>
                </div>

                {/* Attachments - each with its own customer-visibility flag */}
                <div>
                    <label className="label">Attachments <span className="text-surface-400">(optional)</span></label>
                    <input
                        type="file"
                        multiple
                        accept=".pdf,image/*"
                        onChange={(e) => {
                            const picked = Array.from(e.target.files ?? []).map((file) => ({ file, isPublic: false }));
                            setFiles((prev) => [...prev, ...picked]);
                            e.target.value = "";
                        }}
                        className="block w-full text-xs text-surface-500"
                    />
                    <p className="text-2xs text-surface-400 mt-1">e.g. delivery photo, signature scan - PDF or image, max 10 MB each</p>
                    {files.length > 0 && (
                        <div className="mt-2 space-y-1.5">
                            {files.map((f, idx) => (
                                <div key={idx} className="flex items-center gap-2 p-2 bg-surface-50 rounded-lg border border-surface-100">
                                    <span className="text-xs text-surface-700 truncate flex-1">{f.file.name}</span>
                                    <label className="flex items-center gap-1.5 text-2xs text-surface-500 shrink-0">
                                        <input
                                            type="checkbox"
                                            checked={f.isPublic}
                                            onChange={() =>
                                                setFiles((prev) =>
                                                    prev.map((p, i) => (i === idx ? { ...p, isPublic: !p.isPublic } : p)),
                                                )
                                            }
                                            className="rounded border-surface-300"
                                        />
                                        Visible to customer
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() => setFiles((prev) => prev.filter((_, i) => i !== idx))}
                                        className="text-surface-300 hover:text-danger shrink-0"
                                        aria-label="Remove"
                                    >
                                        ×
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <label className="flex items-center gap-3 cursor-pointer select-none">
                    <button
                        onClick={() => setIsPublic(!isPublic)}
                        className={clsx(
                            "relative inline-flex h-5 w-9 items-center rounded-full transition-colors shrink-0",
                            isPublic ? "bg-brand-500" : "bg-surface-300"
                        )}>
                        <span className={clsx(
                            "inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform",
                            isPublic ? "translate-x-4.5" : "translate-x-0.5"
                        )} />
                    </button>
                    <span className="text-xs text-surface-700">
                        {isPublic ? "Visible on customer tracking page" : "Internal note - hidden from customer"}
                    </span>
                </label>

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Adding…" : "Add Event"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Create Shipment Modal ─────────────────────────────────────────────────────

function CreateShipmentModal({
    orderId,
    onClose,
    onDone,
}: {
    orderId: number;
    onClose: () => void;
    onDone: () => void;
}) {
    const toast = useToastStore();
    const [form, setForm] = useState({
        carrier:                  "",
        tracking_number:          "",
        carrier_tracking_url:     "",
        estimated_delivery_date:  "",
        notes:                    "",
        // Optional - shown to the customer on the initial tracking event
        // created by the backend. Distinct from `notes`, which is internal.
        description:              "",
    });
    const [files, setFiles] = useState<{ file: File; isPublic: boolean }[]>([]);

    const set = (k: string, v: string) => setForm((p) => ({ ...p, [k]: v }));

    const mutation = useMutation({
        mutationFn: async () => {
            // Step 1: create the shipment record
            const res = await shipmentsApi.create(orderId, {
                carrier:                 form.carrier,
                tracking_number:         form.tracking_number    || undefined,
                carrier_tracking_url:    form.carrier_tracking_url || undefined,
                estimated_delivery_date: form.estimated_delivery_date || undefined,
                notes:                   form.notes               || undefined,
                description:             form.description.trim() || undefined,
            });

            // Step 2: upload attachments if any were chosen, each with its
            // own customer-visibility flag.
            if (files.length > 0 && res.shipment?.id) {
                await shipmentsApi.uploadAttachment(res.shipment.id, files);
            }

            return res;
        },
        onSuccess: (res) => {
            toast.success("Shipment created");
            navigator.clipboard?.writeText(res.tracking_url).catch(() => {});
            onDone();
            onClose();
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title="Create Shipment" onClose={onClose} size="lg">
            <div className="space-y-4 p-5">
                <div className="grid grid-cols-2 gap-3">
                    <div className="col-span-2 sm:col-span-1">
                        <label className="label">Carrier <span className="text-danger">*</span></label>
                        <input type="text" value={form.carrier} onChange={(e) => set("carrier", e.target.value)}
                            className="input" placeholder="e.g. DHL, G4S, Sendy" autoFocus />
                    </div>
                    <div className="col-span-2 sm:col-span-1">
                        <label className="label">Tracking Number <span className="text-surface-400">(optional)</span></label>
                        <input type="text" value={form.tracking_number}
                            onChange={(e) => set("tracking_number", e.target.value)}
                            className="input font-mono" placeholder="Carrier tracking ref" />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className="label">Est. Delivery Date <span className="text-surface-400">(optional)</span></label>
                        <input type="date" value={form.estimated_delivery_date}
                            onChange={(e) => set("estimated_delivery_date", e.target.value)}
                            className="input" />
                    </div>
                </div>

                <div>
                    <label className="label">Carrier Tracking URL <span className="text-surface-400">(optional)</span></label>
                    <input type="url" value={form.carrier_tracking_url}
                        onChange={(e) => set("carrier_tracking_url", e.target.value)}
                        className="input" placeholder="https://track.carrier.com/…" />
                    <p className="text-2xs text-surface-400 mt-1">
                        If provided, a "Track on carrier" link appears on the customer's tracking page.
                    </p>
                </div>

                <div>
                    <label className="label">Description <span className="text-surface-400">(optional)</span></label>
                    <input type="text" value={form.description}
                        onChange={(e) => set("description", e.target.value)}
                        className="input" placeholder="e.g. Hand-delivered by our own rider this Friday" />
                    <p className="text-2xs text-surface-400 mt-1">
                        Shown to the customer on the tracking page as the first update, if you add one.
                    </p>
                </div>

                <div>
                    <label className="label">Notes <span className="text-surface-400">(internal)</span></label>
                    <textarea value={form.notes} onChange={(e) => set("notes", e.target.value)}
                        className="input resize-none" rows={2} placeholder="Any dispatch notes…" />
                </div>

                <div>
                    <label className="label">Attachments <span className="text-surface-400">(optional)</span></label>
                    <input
                        type="file"
                        multiple
                        accept=".pdf,image/*"
                        onChange={(e) => {
                            const picked = Array.from(e.target.files ?? []).map((file) => ({ file, isPublic: false }));
                            setFiles((prev) => [...prev, ...picked]);
                            e.target.value = "";
                        }}
                        className="block w-full text-xs text-surface-500"
                    />
                    <p className="text-2xs text-surface-400 mt-1">e.g. waybill, dispatch note - PDF or image, max 10 MB each</p>
                    {files.length > 0 && (
                        <div className="mt-2 space-y-1.5">
                            {files.map((f, idx) => (
                                <div key={idx} className="flex items-center gap-2 p-2 bg-surface-50 rounded-lg border border-surface-100">
                                    <span className="text-xs text-surface-700 truncate flex-1">{f.file.name}</span>
                                    <label className="flex items-center gap-1.5 text-2xs text-surface-500 shrink-0">
                                        <input
                                            type="checkbox"
                                            checked={f.isPublic}
                                            onChange={() =>
                                                setFiles((prev) =>
                                                    prev.map((p, i) => (i === idx ? { ...p, isPublic: !p.isPublic } : p)),
                                                )
                                            }
                                            className="rounded border-surface-300"
                                        />
                                        Visible to customer
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() => setFiles((prev) => prev.filter((_, i) => i !== idx))}
                                        className="text-surface-300 hover:text-danger shrink-0"
                                        aria-label="Remove"
                                    >
                                        ×
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="flex gap-3">
                    <button onClick={onClose} className="btn-secondary flex-1">Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending || !form.carrier.trim()}
                        className="btn-primary flex-1">
                        {mutation.isPending ? "Creating…" : "Create Shipment"}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

// ── Edit Shipment Modal ───────────────────────────────────────────────────────
// Gated by shipment.edit - see the canEdit check in the main component below.
//
// `shipment` here is expected to be the full ShipmentDetail (from the
// `detail` query in the main component, NOT the flat list-query `data`) so
// that existing attachments and the first event's description are available
// to edit - the list endpoint doesn't return either of those.

function EditShipmentModal({
    shipment,
    onClose,
    onDone,
}: {
    shipment: any;
    onClose: () => void;
    onDone: () => void;
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
        // The customer-facing description - lives on the initial tracking
        // event server-side, but edited here alongside the rest of the
        // shipment's details for a single, coherent edit experience.
        description:              shipment.description ?? "",
    });

    // Existing attachments already on the shipment - staff can remove them.
    // Removed ones are tracked by id and excluded from the displayed list
    // immediately, with the actual delete call firing in parallel with save.
    const [existingAttachments, setExistingAttachments] = useState<ShipmentAttachment[]>(
        shipment.attachments ?? [],
    );
    const [removedIds, setRemovedIds] = useState<number[]>([]);

    // New files staff are adding in this edit session, each with its own
    // customer-visibility flag - same pattern as the other upload forms.
    const [newFiles, setNewFiles] = useState<{ file: File; isPublic: boolean }[]>([]);

    const set = (k: string, v: string) => setForm((p) => ({ ...p, [k]: v }));

    const removeExisting = (id: number) => {
        setExistingAttachments((prev) => prev.filter((a) => a.id !== id));
        setRemovedIds((prev) => [...prev, id]);
    };

    const mutation = useMutation({
        mutationFn: async () => {
            // Step 1: save the shipment's own fields + description
            const res = await shipmentsApi.update(shipment.id, {
                carrier:                 form.carrier,
                tracking_number:         form.tracking_number || undefined,
                carrier_tracking_url:    form.carrier_tracking_url || undefined,
                estimated_delivery_date: form.estimated_delivery_date || undefined,
                notes:                   form.notes || undefined,
                description:             form.description.trim() || undefined,
            });

            // Step 2: remove any attachments staff deleted
            await Promise.all(
                removedIds.map((id) => shipmentsApi.deleteAttachment(shipment.id, id)),
            );

            // Step 3: upload any newly-added files
            if (newFiles.length > 0) {
                await shipmentsApi.uploadAttachment(shipment.id, newFiles);
            }

            return res;
        },
        onSuccess: () => { toast.success("Shipment updated"); onDone(); onClose(); },
        onError:   (e: ApiError) => toast.error(e.message),
    });

    return (
        <Modal open title="Edit Shipment" onClose={onClose} size="lg">
            <div className="space-y-4 p-5">
                <div className="grid grid-cols-2 gap-3">
                    <div className="col-span-2 sm:col-span-1">
                        <label className="label">Carrier <span className="text-danger">*</span></label>
                        <input type="text" value={form.carrier} onChange={(e) => set("carrier", e.target.value)}
                            className="input" placeholder="e.g. DHL, G4S, Sendy" autoFocus />
                    </div>
                    <div className="col-span-2 sm:col-span-1">
                        <label className="label">Tracking Number <span className="text-surface-400">(optional)</span></label>
                        <input type="text" value={form.tracking_number}
                            onChange={(e) => set("tracking_number", e.target.value)}
                            className="input font-mono" placeholder="Carrier tracking ref" />
                    </div>
                </div>
                <div>
                    <label className="label">Est. Delivery Date <span className="text-surface-400">(optional)</span></label>
                    <input type="date" value={form.estimated_delivery_date}
                        onChange={(e) => set("estimated_delivery_date", e.target.value)}
                        className="input" />
                </div>
                <div>
                    <label className="label">Carrier Tracking URL <span className="text-surface-400">(optional)</span></label>
                    <input type="url" value={form.carrier_tracking_url}
                        onChange={(e) => set("carrier_tracking_url", e.target.value)}
                        className="input" placeholder="https://track.carrier.com/…" />
                </div>
                <div>
                    <label className="label">Description <span className="text-surface-400">(optional)</span></label>
                    <input type="text" value={form.description}
                        onChange={(e) => set("description", e.target.value)}
                        className="input" placeholder="e.g. Hand-delivered by our own rider this Friday" />
                    <p className="text-2xs text-surface-400 mt-1">
                        Shown to the customer on the tracking page as the first update.
                    </p>
                </div>
                <div>
                    <label className="label">Notes <span className="text-surface-400">(internal)</span></label>
                    <textarea value={form.notes} onChange={(e) => set("notes", e.target.value)}
                        className="input resize-none" rows={2} placeholder="Any dispatch notes…" />
                </div>

                {/* Attachments - existing files (removable) + add new ones */}
                <div>
                    <label className="label">Attachments <span className="text-surface-400">(optional)</span></label>

                    {existingAttachments.length > 0 && (
                        <div className="space-y-1.5 mb-2">
                            {existingAttachments.map((a) => (
                                <AttachmentRow key={a.id} attachment={a} onRemove={() => removeExisting(a.id)} />
                            ))}
                        </div>
                    )}

                    <input
                        type="file"
                        multiple
                        accept=".pdf,image/*"
                        onChange={(e) => {
                            const picked = Array.from(e.target.files ?? []).map((file) => ({ file, isPublic: false }));
                            setNewFiles((prev) => [...prev, ...picked]);
                            e.target.value = "";
                        }}
                        className="block w-full text-xs text-surface-500"
                    />
                    <p className="text-2xs text-surface-400 mt-1">e.g. waybill, dispatch note - PDF or image, max 10 MB each</p>

                    {newFiles.length > 0 && (
                        <div className="mt-2 space-y-1.5">
                            {newFiles.map((f, idx) => (
                                <div key={idx} className="flex items-center gap-2 p-2 bg-surface-50 rounded-lg border border-surface-100">
                                    <span className="text-xs text-surface-700 truncate flex-1">{f.file.name}</span>
                                    <label className="flex items-center gap-1.5 text-2xs text-surface-500 shrink-0">
                                        <input
                                            type="checkbox"
                                            checked={f.isPublic}
                                            onChange={() =>
                                                setNewFiles((prev) =>
                                                    prev.map((p, i) => (i === idx ? { ...p, isPublic: !p.isPublic } : p)),
                                                )
                                            }
                                            className="rounded border-surface-300"
                                        />
                                        Visible to customer
                                    </label>
                                    <button
                                        type="button"
                                        onClick={() => setNewFiles((prev) => prev.filter((_, i) => i !== idx))}
                                        className="text-surface-300 hover:text-danger shrink-0"
                                        aria-label="Remove"
                                    >
                                        ×
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
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

// ── Main component ────────────────────────────────────────────────────────────

export function ShipmentSection({
    orderId,
    orderStatus,
}: {
    orderId: number;
    orderStatus: string;
}) {
    const toast = useToastStore();
    const qc    = useQueryClient();
    const { can } = usePermissions();

    const [showCreateModal,   setShowCreateModal]   = useState(false);
    const [showTrackingModal, setShowTrackingModal] = useState(false);
    const [showEditModal,     setShowEditModal]     = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ["order-shipment", orderId],
        queryFn:  () =>
            shipmentsApi.list({ order_id: String(orderId) } as any).then((r) => r.data[0] ?? null),
        enabled: !!orderId,
        staleTime: 30_000,
    });

    const { data: detail } = useQuery({
        queryKey: ["shipment-detail", data?.id],
        queryFn:  () => shipmentsApi.get(data!.id),
        enabled:  !!data?.id,
        staleTime: 30_000,
    });

    const refresh = () => {
        qc.invalidateQueries({ queryKey: ["order-shipment", orderId] });
        qc.invalidateQueries({ queryKey: ["shipment-detail", data?.id] });
        qc.invalidateQueries({ queryKey: ["order", String(orderId)] });
    };

    // "confirmed" = fully paid, all approvals cleared - ready to fulfil.
    // "processing" = partial/deposit/pending-approval - NOT ready to ship yet.
    // "shipped" is included so staff can create a second shipment if needed (e.g. split delivery).
    const canShip = ["confirmed", "shipped"].includes(orderStatus);

    const copyTrackingUrl = () => {
        if (!detail?.tracking_url) return;
        navigator.clipboard.writeText(detail.tracking_url)
            .then(() => toast.success("Tracking link copied"))
            .catch(() => toast.error("Could not copy link"));
    };

    const emailTrackingUrl = () => {
        if (!detail?.tracking_url) return;
        const subject = encodeURIComponent("Your Bethany House order is on its way!");
        const body    = encodeURIComponent(
            `Hi,\n\nYour order is on its way. Track your delivery here:\n\n${detail.tracking_url}\n\nThank you for shopping with us!\n\nBethany House`
        );
        window.open(`mailto:?subject=${subject}&body=${body}`, "_blank");
    };

    // ── No shipment yet ───────────────────────────────────────────────────────
    if (isLoading) {
        return (
            <div className="card p-4 flex items-center justify-center h-24">
                <Spinner />
            </div>
        );
    }

    if (!data) {
        return (
            <>
                <div className="card p-4 space-y-3">
                    <div className="card-header">
                        <h3 className="font-semibold text-sm text-surface-900">Shipment</h3>
                    </div>
                    <div className="card-body">
                        {canShip ? (
                            <div className="text-center space-y-3 py-2">
                                <p className="text-xs text-surface-400">No shipment created yet.</p>
                                <button onClick={() => setShowCreateModal(true)} className="btn-primary btn-sm">
                                    + Create Shipment
                                </button>
                            </div>
                        ) : (
                            <p className="text-xs text-surface-400">
                                Shipping will be available once the order is Confirmed (fully paid).
                            </p>
                        )}
                    </div>
                </div>
                {showCreateModal && (
                    <CreateShipmentModal
                        orderId={orderId}
                        onClose={() => setShowCreateModal(false)}
                        onDone={refresh}
                    />
                )}
            </>
        );
    }

    // ── Active shipment ───────────────────────────────────────────────────────
    const latestPublicEvent = detail?.tracking_history
        ?.filter((e) => e.is_public !== false)
        .slice(-1)[0];

    const isTerminal = ["delivered", "cancelled"].includes(data.status);
    const canEdit    = can("shipment.edit") && !isTerminal;

    return (
        <>
            <div className="card overflow-hidden">
                <div className="card-header flex items-center justify-between">
                    <h3 className="font-semibold text-sm text-surface-900">Shipment</h3>
                    <div className="flex items-center gap-1.5">
                        {canEdit && (
                            <button onClick={() => setShowEditModal(true)}
                                className="btn-sm text-xs border border-surface-200 text-surface-600 bg-white hover:border-surface-300 rounded-lg px-2.5 py-1 font-medium">
                                Edit
                            </button>
                        )}
                        {!isTerminal && (
                            <button onClick={() => setShowTrackingModal(true)}
                                className="btn-sm text-xs border border-brand-200 text-brand-600 bg-brand-50 hover:bg-brand-100 rounded-lg px-2.5 py-1 font-medium">
                                + Add Event
                            </button>
                        )}
                    </div>
                </div>

                <div className="card-body space-y-3">
                    {/* Status */}
                    <div className="flex items-center justify-between">
                        <span className={clsx("badge text-2xs", STATUS_COLORS[data.status] ?? "badge-neutral")}>
                            {SHIPMENT_STATUS_LABELS[data.status] ?? data.status}
                        </span>
                        <span className="text-2xs text-surface-400 font-mono">{data.shipment_number}</span>
                    </div>

                    {/* Carrier + tracking number */}
                    <div className="space-y-1 text-xs">
                        <div className="flex justify-between">
                            <span className="text-surface-500">Carrier</span>
                            <span className="font-medium">{data.carrier}</span>
                        </div>
                        {data.tracking_number && (
                            <div className="flex justify-between">
                                <span className="text-surface-500">Tracking #</span>
                                <span className="font-mono font-medium">{data.tracking_number}</span>
                            </div>
                        )}
                        {data.estimated_delivery_date && (
                            <div className="flex justify-between">
                                <span className="text-surface-500">Est. delivery</span>
                                <span className="font-medium">
                                    {new Date(data.estimated_delivery_date).toLocaleDateString("en-KE", { dateStyle: "medium" })}
                                </span>
                            </div>
                        )}
                        {data.delivered_at && (
                            <div className="flex justify-between">
                                <span className="text-surface-500">Delivered</span>
                                <span className="font-medium text-success-dark">
                                    {new Date(data.delivered_at).toLocaleDateString("en-KE", { dateStyle: "medium" })}
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Latest public event */}
                    {latestPublicEvent && (
                        <div className="rounded-xl bg-surface-50 px-3 py-2 text-xs space-y-0.5">
                            {latestPublicEvent.description && (
                                <p className="font-medium text-surface-700">{latestPublicEvent.description}</p>
                            )}
                            {latestPublicEvent.location && (
                                <p className="text-surface-400"><span className="inline-flex items-center gap-1"><svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>{latestPublicEvent.location}</span></p>
                            )}
                            <p className="text-surface-300">
                                {new Date(latestPublicEvent.event_time).toLocaleString("en-KE", {
                                    dateStyle: "medium", timeStyle: "short",
                                })}
                            </p>
                            {(latestPublicEvent.attachments ?? []).map((a) => (
                                <AttachmentInlineLink key={a.id} attachment={a} />
                            ))}
                        </div>
                    )}

                    {/* Shipment-level attachments (waybill etc.) */}
                    {/* FIX: was reading from `data` (the list-query result,
                        which has no attachments field at all per the backend's
                        index() endpoint) instead of `detail` (the full
                        single-shipment fetch that actually includes them) -
                        this card silently never rendered before. */}
                    {((detail as any)?.attachments ?? []).length > 0 && (
                        <div className="space-y-1.5">
                            {((detail as any).attachments ?? []).map((a: ShipmentAttachment) => (
                                <AttachmentRow key={a.id} attachment={a} />
                            ))}
                        </div>
                    )}

                    {/* Full tracking history with attachment links */}
                    {detail?.tracking_history && detail.tracking_history.length > 1 && (
                        <details className="group">
                            <summary className="text-2xs text-brand-600 hover:underline cursor-pointer select-none list-none flex items-center gap-1">
                                <svg className="w-3 h-3 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                                All tracking events ({detail.tracking_history.length})
                            </summary>
                            <div className="mt-2 space-y-2 pl-2 border-l-2 border-surface-100">
                                {[...detail.tracking_history].reverse().map((event, i) => (
                                    <div key={i} className="text-xs space-y-0.5">
                                        <div className="flex items-center gap-2">
                                            <span className={clsx("badge text-2xs", STATUS_COLORS[event.status] ?? "badge-neutral")}>
                                                {SHIPMENT_STATUS_LABELS[event.status] ?? event.status}
                                            </span>
                                            {!event.is_public && (
                                                <span className="text-2xs text-surface-400 italic">internal</span>
                                            )}
                                        </div>
                                        {event.description && (
                                            <p className="text-surface-700">{event.description}</p>
                                        )}
                                        {event.location && <p className="text-surface-400"><span className="inline-flex items-center gap-1"><svg className="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>{event.location}</span></p>}
                                        <p className="text-surface-300">
                                            {new Date(event.event_time).toLocaleString("en-KE", { dateStyle: "short", timeStyle: "short" })}
                                            {event.added_by_name && ` · ${event.added_by_name}`}
                                        </p>
                                        {(event.attachments ?? []).map((a) => (
                                            <AttachmentInlineLink key={a.id} attachment={a} showInternalTag />
                                        ))}
                                    </div>
                                ))}
                            </div>
                        </details>
                    )}

                    {/* Tracking URL actions */}
                    {detail?.tracking_url && (
                        <div className="pt-2 border-t border-surface-100 space-y-2">
                            <p className="text-2xs text-surface-400">Customer tracking link</p>
                            <div className="flex items-center gap-2 p-2 bg-surface-50 rounded-lg">
                                <p className="flex-1 text-2xs font-mono text-surface-600 truncate">
                                    {detail.tracking_url}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <button onClick={copyTrackingUrl}
                                    className="flex-1 btn-secondary btn-sm gap-1.5 text-xs">
                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                    </svg>
                                    Copy Link
                                </button>
                                <button onClick={emailTrackingUrl}
                                    className="flex-1 btn-secondary btn-sm gap-1.5 text-xs">
                                    <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                    </svg>
                                    Email to Customer
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {showTrackingModal && (
                <AddTrackingModal
                    shipmentId={data.id}
                    currentStatus={data.status}
                    onClose={() => setShowTrackingModal(false)}
                    onDone={refresh}
                />
            )}

            {showEditModal && detail && (
                <EditShipmentModal
                    shipment={{
                        ...detail,
                        // The customer-facing description lives on the
                        // initial "order_confirmed" tracking event, not as a
                        // field on the shipment record itself - derive it
                        // here so the Edit modal can show it pre-filled.
                        description: detail.tracking_history?.find((e) => e.status === "order_confirmed")?.description ?? "",
                    }}
                    onClose={() => setShowEditModal(false)}
                    onDone={refresh}
                />
            )}
        </>
    );
}