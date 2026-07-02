/**
 * /track/:token - Public shipment tracking page
 * No authentication required.
 *
 * Redesigned to match the PaymentLinkPage design language:
 *   - Tailwind + clsx (no inline style objects)
 *   - bg-gray-50 page, white rounded-2xl cards, border border-gray-100 shadow-sm
 *   - Shared Card / Btn / Spinner / Field / ErrMsg primitives
 *   - Bethany House logo + branding consistent with PaymentLinkPage header
 *   - Status-coloured accent bar at top only — keeps the page calm
 */

import { useState, useEffect } from "react";
import { useParams } from "react-router-dom";
import { useQuery, useMutation } from "@tanstack/react-query";
import { clsx } from "clsx";
import { shipmentsApi } from "@/api/shipments";
import type { PublicTrackingEvent, TrackingMilestone } from "@/api/shipments";

// ── Status config ──────────────────────────────────────────────────────────────

const STATUS_CONFIG: Record<string, { label: string; tw: string; bar: string }> = {
    order_confirmed:    { label: "Order Confirmed",    tw: "text-blue-600 bg-blue-50 border-blue-200",    bar: "bg-blue-600"   },
    processing:         { label: "Processing",         tw: "text-violet-600 bg-violet-50 border-violet-200", bar: "bg-violet-600" },
    ready_to_ship:      { label: "Ready to Ship",      tw: "text-cyan-600 bg-cyan-50 border-cyan-200",     bar: "bg-cyan-600"   },
    picked_up:          { label: "Picked Up",          tw: "text-cyan-600 bg-cyan-50 border-cyan-200",     bar: "bg-cyan-600"   },
    in_transit:         { label: "In Transit",         tw: "text-amber-600 bg-amber-50 border-amber-200",  bar: "bg-amber-500"  },
    out_for_delivery:   { label: "Out for Delivery",   tw: "text-orange-600 bg-orange-50 border-orange-200", bar: "bg-orange-500" },
    delivery_attempted: { label: "Delivery Attempted", tw: "text-red-600 bg-red-50 border-red-200",        bar: "bg-red-500"    },
    delivered:          { label: "Delivered",          tw: "text-green-700 bg-green-50 border-green-200",  bar: "bg-green-600"  },
    exception:          { label: "Exception",          tw: "text-red-600 bg-red-50 border-red-200",        bar: "bg-red-500"    },
    cancelled:          { label: "Cancelled",          tw: "text-gray-500 bg-gray-100 border-gray-200",    bar: "bg-gray-400"   },
};

// Dot fill for pipeline — index-based tailwind won't work with dynamic; use hex fallback
const STATUS_HEX: Record<string, string> = {
    order_confirmed: "#2563eb", processing: "#7c3aed", ready_to_ship: "#0891b2",
    picked_up: "#0891b2", in_transit: "#d97706", out_for_delivery: "#ea580c",
    delivery_attempted: "#dc2626", delivered: "#16a34a", exception: "#dc2626", cancelled: "#9ca3af",
};

const PIPELINE = [
    "order_confirmed", "processing", "ready_to_ship",
    "picked_up", "in_transit", "out_for_delivery", "delivered",
];

// ── Shared primitives (matching PaymentLinkPage) ───────────────────────────────

function Spinner({ size = "md" }: { size?: "sm" | "md" | "lg" }) {
    const s = { sm: "w-4 h-4 border-2", md: "w-6 h-6 border-2", lg: "w-10 h-10 border-[3px]" }[size];
    return <div className={clsx("rounded-full animate-spin border-gray-200 border-t-blue-600", s)} />;
}

function Card({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <div className={clsx("bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden", className)}>
            {children}
        </div>
    );
}

function SectionLabel({ children }: { children: React.ReactNode }) {
    return (
        <p className="text-[10px] font-bold tracking-widest uppercase text-gray-400 mb-3">
            {children}
        </p>
    );
}

// ── Status badge ───────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
    const cfg = STATUS_CONFIG[status];
    if (!cfg) return null;
    return (
        <span className={clsx(
            "inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold tracking-wide border",
            cfg.tw,
        )}>
            {cfg.label}
        </span>
    );
}

// ── Progress track ─────────────────────────────────────────────────────────────

function ProgressTrack({ milestones, status }: { milestones: TrackingMilestone[]; status: string }) {
    const hex   = STATUS_HEX[status] ?? "#2563eb";
    const steps = milestones.filter(m => PIPELINE.includes(m.status));

    return (
        <div className="overflow-x-auto pb-1 -mx-1 px-1">
            <div className="flex items-start min-w-[420px]">
                {steps.map((m, i) => {
                    const isDone   = m.state === "done";
                    const isActive = m.state === "active";
                    const isLast   = i === steps.length - 1;

                    return (
                        <div key={m.status} className="flex-1 flex flex-col items-center">
                            {/* Connector + dot row */}
                            <div className="flex items-center w-full h-6">
                                {/* Left connector */}
                                <div
                                    className="flex-1 h-0.5 transition-colors duration-300"
                                    style={{ background: i === 0 ? "transparent" : (isDone || isActive) ? hex : "#e5e7eb" }}
                                />
                                {/* Dot */}
                                <div
                                    className="shrink-0 rounded-full transition-all duration-300"
                                    style={{
                                        width:      isActive ? 14 : 10,
                                        height:     isActive ? 14 : 10,
                                        background: isDone || isActive ? hex : "#e5e7eb",
                                        boxShadow:  isActive ? `0 0 0 4px ${hex}20` : "none",
                                    }}
                                />
                                {/* Right connector */}
                                <div
                                    className="flex-1 h-0.5 transition-colors duration-300"
                                    style={{ background: isLast ? "transparent" : isDone ? hex : "#e5e7eb" }}
                                />
                            </div>
                            {/* Label */}
                            <span
                                className={clsx(
                                    "mt-2 text-[10px] text-center leading-tight max-w-[68px]",
                                    isActive ? "font-bold" : isDone ? "font-medium text-gray-600" : "font-medium text-gray-300",
                                )}
                                style={{ color: isActive ? hex : undefined }}
                            >
                                {m.label}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

// ── Image lightbox ─────────────────────────────────────────────────────────────
// Full-screen image preview with a download button. Closes on backdrop click,
// the × button, or Escape.

function Lightbox({
    src,
    name,
    mimeType,
    onClose,
}: {
    src: string;
    name: string;
    mimeType?: string | null;
    onClose: () => void;
}) {
    const [downloading, setDownloading] = useState(false);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") onClose(); };
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [onClose]);

    const isImage = !mimeType || mimeType.startsWith("image/");
    const isPdf   = mimeType === "application/pdf";

    // FIX: the `download` attribute on an <a> is silently ignored by browsers
    // when the link target is cross-origin (this API and the frontend run on
    // different origins/ports) - the browser just navigates/displays the
    // resource instead of saving it, regardless of the server's
    // Content-Disposition header. Fetching the file as a blob first sidesteps
    // this entirely: a blob: URL is always same-origin, so `download` works.
    const handleDownload = async () => {
        setDownloading(true);
        try {
            const res = await fetch(src);
            if (!res.ok) throw new Error(`Download failed (${res.status})`);
            const blob = await res.blob();
            const blobUrl = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = blobUrl;
            a.download = name;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(blobUrl), 5000);
        } catch {
            // Fall back to opening the resource directly - not a forced
            // download, but better than nothing if the fetch itself fails.
            window.open(src, "_blank");
        } finally {
            setDownloading(false);
        }
    };

    return (
        <div
            className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4 animate-fade-in"
            onClick={onClose}
        >
            <div className="absolute top-4 right-4 flex items-center gap-2">
                <button
                    onClick={(e) => { e.stopPropagation(); handleDownload(); }}
                    disabled={downloading}
                    className="flex items-center gap-1.5 text-xs font-medium text-white bg-white/10 hover:bg-white/20 rounded-full px-3 py-2 transition-colors disabled:opacity-60"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    {downloading ? "Downloading…" : "Download"}
                </button>
                <button
                    onClick={onClose}
                    className="w-9 h-9 flex items-center justify-center text-white bg-white/10 hover:bg-white/20 rounded-full transition-colors"
                    aria-label="Close"
                >
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {isImage ? (
                <img
                    src={src}
                    alt={name}
                    onClick={(e) => e.stopPropagation()}
                    className="max-w-full max-h-full object-contain rounded-lg shadow-2xl"
                />
            ) : isPdf ? (
                <iframe
                    src={src}
                    title={name}
                    onClick={(e) => e.stopPropagation()}
                    className="bg-white rounded-lg shadow-2xl"
                    style={{ width: "min(90vw, 800px)", height: "85vh" }}
                />
            ) : (
                <div
                    onClick={(e) => e.stopPropagation()}
                    className="bg-white rounded-2xl shadow-2xl p-10 flex flex-col items-center gap-3 max-w-sm text-center"
                >
                    <svg className="w-10 h-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    <p className="text-sm font-medium text-gray-700 break-all">{name}</p>
                    <p className="text-xs text-gray-400">No inline preview available for this file type.</p>
                </div>
            )}
        </div>
    );
}

// ── Attachment gallery ──────────────────────────────────────────────────────────
// Image attachments render as small clickable thumbnails opening the
// lightbox above with the image enlarged. Non-image attachments (PDFs, etc)
// render as a labeled pill with a file-type icon, also opening the lightbox -
// PDFs preview inline there, everything else gets a "no preview" fallback,
// both with the same Download action.

type GalleryAttachment = { url: string; name: string; mime_type?: string | null; is_image?: boolean };

function FileTypeIcon({ mimeType }: { mimeType?: string | null }) {
    if (mimeType === "application/pdf") {
        return (
            <svg className="w-4 h-4 text-red-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
        );
    }
    return (
        <svg className="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
        </svg>
    );
}

function AttachmentGallery({ attachments }: { attachments: GalleryAttachment[] }) {
    const [previewing, setPreviewing] = useState<GalleryAttachment | null>(null);

    if (!attachments || attachments.length === 0) return null;

    const images = attachments.filter((a) => a.is_image);
    const files  = attachments.filter((a) => !a.is_image);

    return (
        <>
            <div className="flex flex-wrap items-center gap-2 mt-2">
                {images.map((a, i) => (
                    <button
                        key={`img-${i}`}
                        onClick={() => setPreviewing(a)}
                        className="w-14 h-14 rounded-lg overflow-hidden border border-gray-200 hover:border-blue-400 transition-colors shrink-0"
                        title={a.name}
                    >
                        <img src={a.url} alt={a.name} className="w-full h-full object-cover" loading="lazy" />
                    </button>
                ))}
                {files.map((a, i) => (
                    <button
                        key={`file-${i}`}
                        onClick={() => setPreviewing(a)}
                        className="inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 hover:underline bg-blue-50 rounded-lg px-2.5 py-1.5"
                    >
                        <FileTypeIcon mimeType={a.mime_type} />
                        {a.name}
                    </button>
                ))}
            </div>

            {previewing && (
                <Lightbox
                    src={previewing.url}
                    name={previewing.name}
                    mimeType={previewing.mime_type}
                    onClose={() => setPreviewing(null)}
                />
            )}
        </>
    );
}

// ── Timeline ───────────────────────────────────────────────────────────────────

function Timeline({ events }: { events: PublicTrackingEvent[] }) {
    const reversed = [...events].reverse();

    return (
        <div>
            {reversed.map((e, i) => {
                const isFirst = i === 0;
                const date    = new Date(e.event_time);
                const hex     = STATUS_HEX[e.status] ?? "#6b7280";
                const dayStr  = date.toLocaleDateString("en-KE", { day: "numeric", month: "short" });
                const timeStr = date.toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" });
                const isLast  = i === reversed.length - 1;

                return (
                    <div key={i} className="grid gap-x-4" style={{ gridTemplateColumns: "56px 1px 1fr", paddingBottom: isLast ? 0 : 24 }}>
                        {/* Date */}
                        <div className="text-right pt-0.5">
                            <p className={clsx("text-xs font-semibold", isFirst ? "text-gray-900" : "text-gray-400")}>{dayStr}</p>
                            <p className="text-[11px] text-gray-300 mt-0.5">{timeStr}</p>
                        </div>

                        {/* Spine */}
                        <div className="flex flex-col items-center">
                            <div
                                className="shrink-0 rounded-full mt-1"
                                style={{
                                    width:     isFirst ? 10 : 8,
                                    height:    isFirst ? 10 : 8,
                                    background: isFirst ? hex : "#d1d5db",
                                    boxShadow:  isFirst ? `0 0 0 3px ${hex}18` : "none",
                                }}
                            />
                            {!isLast && <div className="flex-1 w-px bg-gray-100 mt-1.5" />}
                        </div>

                        {/* Content */}
                        <div className="pt-0.5 min-w-0">
                            <div className="flex flex-wrap items-center gap-2 mb-1">
                                <span
                                    className={clsx("text-[11px] font-bold tracking-wider uppercase")}
                                    style={{ color: isFirst ? hex : "#9ca3af" }}
                                >
                                    {e.label ?? e.status}
                                </span>
                                {e.location && (
                                    <span className="flex items-center gap-1 text-[11px] text-gray-400">
                                        <svg className="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                        </svg>
                                        {e.location}
                                    </span>
                                )}
                            </div>
                            {e.description && (
                                <p className={clsx("text-sm leading-relaxed", isFirst ? "text-gray-700" : "text-gray-400")}>
                                    {e.description}
                                </p>
                            )}
                            {e.attachments.length > 0 && (
                                <AttachmentGallery attachments={e.attachments} />
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

// ── Contact / query form ───────────────────────────────────────────────────────

function QuerySection({ token }: { token: string }) {
    const [open,    setOpen]    = useState(false);
    const [name,    setName]    = useState("");
    const [email,   setEmail]   = useState("");
    const [message, setMessage] = useState("");
    const [done,    setDone]    = useState(false);

    const mutation = useMutation({
        mutationFn: () => shipmentsApi.submitQuery(token, { name, email, message }),
        onSuccess:  () => setDone(true),
    });

    const canSubmit = !!name.trim() && !!email.trim() && !!message.trim();

    if (done) {
        return (
            <div className="flex flex-col items-center py-6 gap-3">
                <div className="w-11 h-11 rounded-full bg-green-50 flex items-center justify-center">
                    <svg className="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>
                <div className="text-center">
                    <p className="text-sm font-semibold text-gray-800">Message sent</p>
                    <p className="text-xs text-gray-400 mt-0.5">We'll be in touch shortly.</p>
                </div>
            </div>
        );
    }

    if (!open) {
        return (
            <button
                onClick={() => setOpen(true)}
                className="flex items-center gap-2 text-sm text-gray-400 hover:text-gray-600 transition-colors group"
            >
                <div className="w-8 h-8 rounded-xl bg-gray-100 group-hover:bg-gray-200 flex items-center justify-center transition-colors shrink-0">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                </div>
                Have a question about this shipment?
            </button>
        );
    }

    const inputCls = "w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent";

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold text-gray-800">Send us a message</p>
                <button
                    onClick={() => setOpen(false)}
                    className="w-7 h-7 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-400 hover:text-gray-600 transition-colors"
                >
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                    <label className="block text-xs font-semibold text-gray-700">Your name</label>
                    <input value={name} onChange={e => setName(e.target.value)} placeholder="Jane Doe" className={inputCls} />
                </div>
                <div className="space-y-1.5">
                    <label className="block text-xs font-semibold text-gray-700">Email</label>
                    <input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="jane@example.com" className={inputCls} />
                </div>
            </div>

            <div className="space-y-1.5">
                <label className="block text-xs font-semibold text-gray-700">Message</label>
                <textarea
                    value={message}
                    onChange={e => setMessage(e.target.value)}
                    placeholder="Describe your question or concern…"
                    rows={3}
                    className={clsx(inputCls, "resize-none leading-relaxed")}
                />
            </div>

            {mutation.isError && (
                <p className="text-xs text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">
                    Something went wrong — please try again.
                </p>
            )}

            <button
                onClick={() => mutation.mutate()}
                disabled={mutation.isPending || !canSubmit}
                className="w-full py-2.5 px-5 rounded-xl text-sm font-semibold bg-gray-900 text-white hover:bg-gray-800 disabled:opacity-40 disabled:cursor-not-allowed transition-all flex items-center justify-center gap-2"
            >
                {mutation.isPending
                    ? <><div className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" /> Sending…</>
                    : "Send message"
                }
            </button>
        </div>
    );
}

// ── Full-page states ───────────────────────────────────────────────────────────

function PageShell({ children }: { children: React.ReactNode }) {
    return (
        <div className="min-h-screen bg-gray-50 font-sans antialiased text-gray-900">
            {children}
        </div>
    );
}

function LoadingScreen() {
    return (
        <PageShell>
            <div className="min-h-screen flex items-center justify-center">
                <div className="flex flex-col items-center gap-3">
                    <Spinner size="lg" />
                    <p className="text-sm text-gray-400">Loading shipment details…</p>
                </div>
            </div>
        </PageShell>
    );
}

function ErrorScreen() {
    return (
        <PageShell>
            <div className="min-h-screen flex items-center justify-center p-6">
                <Card className="max-w-sm w-full p-8 text-center">
                    <div className="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-4">
                        <svg className="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                        </svg>
                    </div>
                    <h1 className="text-lg font-semibold text-gray-900 mb-2">Tracking link not found</h1>
                    <p className="text-sm text-gray-500 mb-5 leading-relaxed">
                        We couldn't find a shipment for this link. Check the URL or reach out.
                    </p>
                    <a
                        href="mailto:hello@bethanyhouse.co.ke"
                        className="text-sm text-blue-600 hover:underline"
                    >
                        hello@bethanyhouse.co.ke
                    </a>
                </Card>
            </div>
        </PageShell>
    );
}

// ── Page header (matches PaymentLinkPage brand header) ────────────────────────

function BrandHeader({
    businessName,
    businessLogo,
    businessTagline,
}: {
    businessName:    string;
    businessLogo:    string | null;
    businessTagline: string | null;
}) {
    return (
        <header className="bg-white border-b border-gray-100">
            <div className="max-w-lg mx-auto px-4 py-4 flex items-center justify-between">
                {/* Logo / wordmark — mirrors PaymentLinkPage OrderCard header */}
                <div className="flex items-center gap-2.5">
                    {businessLogo ? (
                        <img
                            src={businessLogo}
                            alt={businessName}
                            className="h-8 object-contain"
                        />
                    ) : (
                        <>
                            <div className="w-8 h-8 bg-gray-900 rounded-lg flex items-center justify-center shrink-0">
                                <span className="text-white text-sm font-black tracking-tighter">
                                    {businessName.charAt(0)}
                                </span>
                            </div>
                            <span className="text-base font-bold tracking-tight text-gray-900">{businessName}</span>
                        </>
                    )}
                    {businessTagline && !businessLogo && (
                        <span className="hidden sm:block text-xs text-gray-400 border-l border-gray-200 pl-2.5 ml-0.5">
                            {businessTagline}
                        </span>
                    )}
                </div>
                {/* Contact link */}
                <a
                    href="mailto:hello@bethanyhouse.co.ke"
                    className="text-xs text-gray-400 hover:text-gray-700 transition-colors"
                >
                    Contact us
                </a>
            </div>
            {/* Tagline shown below logo when logo is present */}
            {businessTagline && businessLogo && (
                <div className="text-center pb-2 -mt-1">
                    <p className="text-xs text-gray-400">{businessTagline}</p>
                </div>
            )}
        </header>
    );
}

// ── Delivered / ETA / exception banner ────────────────────────────────────────

function StatusBanner({
    status, eta, deliveredAt,
}: {
    status: string; eta: string | null; deliveredAt?: string | null;
}) {
    const isDelivered = status === "delivered";
    const isException = status === "exception";
    const isAttempted = status === "delivery_attempted";
    const showEta     = !isDelivered && !isException && !isAttempted && !!eta;

    if (!isDelivered && !isException && !isAttempted && !showEta) return null;

    return (
        <Card>
            <div className={clsx("px-5 py-4 flex items-start gap-3", {
                "bg-green-50":  isDelivered,
                "bg-red-50":    isException || isAttempted,
                "bg-blue-50":   showEta,
            })}>
                {/* Icon */}
                <div className={clsx("w-9 h-9 rounded-xl flex items-center justify-center shrink-0 mt-0.5", {
                    "bg-green-100": isDelivered,
                    "bg-red-100":   isException || isAttempted,
                    "bg-blue-100":  showEta,
                })}>
                    {isDelivered && (
                        <svg className="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    )}
                    {(isException || isAttempted) && (
                        <svg className="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    )}
                    {showEta && (
                        <svg className="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                    )}
                </div>
                {/* Text */}
                <div>
                    {isDelivered && (
                        <>
                            <p className="text-sm font-semibold text-green-800">Your order has been delivered</p>
                            {deliveredAt && (
                                <p className="text-xs text-green-600 mt-0.5">
                                    {new Date(deliveredAt).toLocaleDateString("en-KE", { dateStyle: "long" })}
                                </p>
                            )}
                        </>
                    )}
                    {(isException || isAttempted) && (
                        <>
                            <p className="text-sm font-semibold text-red-700">
                                {isAttempted ? "Delivery was attempted" : "There's an issue with your shipment"}
                            </p>
                            <p className="text-xs text-red-500 mt-0.5">
                                Please use the contact form below — we'll sort it out.
                            </p>
                        </>
                    )}
                    {showEta && (
                        <>
                            <p className="text-[10px] font-bold tracking-widest uppercase text-blue-500">Estimated delivery</p>
                            <p className="text-sm font-semibold text-blue-800 mt-0.5">{eta}</p>
                        </>
                    )}
                </div>
            </div>
        </Card>
    );
}

// ── Main component ─────────────────────────────────────────────────────────────

export default function TrackingPage() {
    const { token } = useParams<{ token: string }>();

    const { data, isLoading, isError } = useQuery({
        queryKey:           ["public-track", token],
        queryFn:            () => shipmentsApi.publicTrack(token!),
        enabled:            !!token,
        staleTime:          2 * 60_000,
        refetchOnWindowFocus: true,
    });

    if (isLoading) return <LoadingScreen />;
    if (isError || !data) return <ErrorScreen />;

    const { shipment, events, milestones, status_label, business_name, business_tagline, business_logo, shipment_attachments } = data;

    const isDelivered = shipment.status === "delivered";
    const isException = shipment.status === "exception";
    const isAttempted = shipment.status === "delivery_attempted";
    const barCls      = STATUS_CONFIG[shipment.status]?.bar ?? "bg-blue-600";
    const eta         = shipment.estimated_delivery_date
        ? new Date(shipment.estimated_delivery_date).toLocaleDateString("en-KE", { weekday: "long", day: "numeric", month: "long" })
        : null;

    return (
        <PageShell>
            {/* Status-coloured accent bar — only brand colour on the page */}
            <div className={clsx("h-1 transition-colors duration-500", barCls)} />

            <BrandHeader
                businessName={business_name}
                businessLogo={business_logo}
                businessTagline={business_tagline}
            />

            <main className="max-w-lg mx-auto px-4 py-6 pb-16 space-y-4">

                {/* ── Order identity card ─────────────────────────────────────── */}
                <Card>
                    <div className="px-5 py-4">
                        {shipment.customer_first_name && (
                            <p className="text-xs text-gray-400 mb-1">Hi {shipment.customer_first_name},</p>
                        )}
                        <div className="flex items-center gap-3 flex-wrap">
                            <h1 className="text-2xl font-bold tracking-tight">{shipment.order_number}</h1>
                            <StatusBadge status={shipment.status} />
                        </div>
                        {shipment.carrier && (
                            <p className="text-xs text-gray-400 mt-1.5 flex items-center gap-1.5">
                                <svg className="w-3.5 h-3.5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />
                                </svg>
                                {shipment.carrier}
                                {shipment.tracking_number && (
                                    <span className="font-mono tracking-wide">{shipment.tracking_number}</span>
                                )}
                            </p>
                        )}
                    </div>
                </Card>

                {/* ── ETA / status banner ─────────────────────────────────────── */}
                <StatusBanner
                    status={shipment.status}
                    eta={eta}
                    deliveredAt={shipment.delivered_at}
                />

                {/* ── Progress pipeline ───────────────────────────────────────── */}
                {milestones.length > 0 && (
                    <Card>
                        <div className="px-5 py-4">
                            <SectionLabel>Shipment progress</SectionLabel>
                            <ProgressTrack milestones={milestones} status={shipment.status} />
                        </div>
                    </Card>
                )}

                {/* ── Tracking history ────────────────────────────────────────── */}
                <Card>
                    <div className="px-5 py-4">
                        <SectionLabel>Tracking history</SectionLabel>
                        {events.length > 0 ? (
                            <Timeline events={events} />
                        ) : (
                            <div className="py-8 text-center">
                                <div className="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-3">
                                    <svg className="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <p className="text-sm text-gray-400">No updates yet — check back soon.</p>
                            </div>
                        )}
                    </div>
                </Card>

                {/* ── Documents (e.g. waybill, made public by staff) ─────────── */}
                {shipment_attachments && shipment_attachments.length > 0 && (
                    <Card>
                        <div className="px-5 py-4">
                            <SectionLabel>Documents</SectionLabel>
                            <AttachmentGallery attachments={shipment_attachments} />
                        </div>
                    </Card>
                )}

                {/* ── Carrier link ────────────────────────────────────────────── */}
                {shipment.carrier_tracking_url && (
                    <div className="flex justify-center">
                        <a
                            href={shipment.carrier_tracking_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 text-xs text-gray-400 hover:text-gray-700 transition-colors"
                        >
                            Track directly on {shipment.carrier}
                            <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                        </a>
                    </div>
                )}

                {/* ── Contact / query ─────────────────────────────────────────── */}
                <Card>
                    <div className="px-5 py-4">
                        <QuerySection token={token!} />
                    </div>
                </Card>

                {/* ── Footer ──────────────────────────────────────────────────── */}
                <p className="text-center text-xs text-gray-300 flex items-center justify-center gap-1.5 pt-2">
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                    © {new Date().getFullYear()} {business_name}
                    <span className="text-gray-200">·</span>
                    <a href="mailto:hello@bethanyhouse.co.ke" className="hover:text-gray-500 transition-colors">
                        info@bethanyhouse.co.ke
                    </a>
                </p>

            </main>
        </PageShell>
    );
}