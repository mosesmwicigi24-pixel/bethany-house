import { useEffect, useRef, useState } from "react";
import { clsx } from "clsx";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import { DOCUMENT_KINDS, type DocumentKind, type StaffDocument } from "@/api/people";
import type { ApiError } from "@/types";
import { CardHead, EmptyLine, Icon, fmtDay, parseDay } from "./fileKit";

/**
 * Staff documents (People PR 2): the list, a viewer, and — for a file keeper —
 * upload and remove. The same card serves the manager's view of a person and
 * the person's own "My employment" tab (read-only there).
 */
export function kindLabel(kind: DocumentKind) {
    return DOCUMENT_KINDS.find(k => k.value === kind)?.label ?? kind;
}

const ACCEPT = "image/*,application/pdf";
const MAX_BYTES = 10 * 1024 * 1024;

/** Days until a document expires (negative once expired), or null. */
export function daysToExpiry(d: StaffDocument, today = new Date()): number | null {
    const e = parseDay(d.expires_at);
    if (!e) return null;
    const t = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    return Math.round((e.getTime() - t.getTime()) / 86_400_000);
}

function expiryTone(d: StaffDocument): { label: string; cls: string } | null {
    const days = daysToExpiry(d);
    if (days === null) return null;
    if (d.expired || days < 0) return { label: `Expired ${fmtDay(d.expires_at)}`, cls: "badge-danger" };
    if (days === 0) return { label: "Expires today", cls: "badge-danger" };
    if (days <= 60) return { label: `Expires in ${days} day${days === 1 ? "" : "s"}`, cls: "badge-warning" };
    return { label: `Valid to ${fmtDay(d.expires_at)}`, cls: "badge-neutral" };
}

function sizeWords(bytes: number | null): string {
    if (!bytes) return "";
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function TypeBadge({ doc }: { doc: StaffDocument }) {
    const pdf = doc.mime_type === "application/pdf" || /\.pdf$/i.test(doc.original_name ?? "");
    const img = doc.mime_type?.startsWith("image/");
    return (
        <span className={clsx("w-11 h-11 rounded-control flex flex-col items-center justify-center shrink-0",
            pdf ? "bg-danger-50 text-danger-700" : img ? "bg-info-50 text-info-700" : "bg-surface-100 text-surface-600")}>
            <Icon name={img ? "image" : "file"} className="w-5 h-5" />
            <span className="text-[9px] font-bold leading-none mt-0.5 tracking-wide">{pdf ? "PDF" : img ? "IMG" : "FILE"}</span>
        </span>
    );
}

export function DocumentsCard({ documents, fetchBlob, onUpload, onRemove, title = "Documents", titleId, openSignal }: {
    documents: StaffDocument[];
    fetchBlob: (docId: number) => Promise<{ url: string; mimeType: string }>;
    /** Present only for a file keeper (hr.edit). */
    onUpload?: (file: File, kind: DocumentKind, title: string, expiresAt?: string) => Promise<void>;
    onRemove?: (doc: StaffDocument) => Promise<void>;
    title?: string;
    titleId?: string;
    /** Bump to open the upload form from elsewhere on the page. */
    openSignal?: number;
}) {
    const toast = useToastStore();
    const [view, setView] = useState<{ url: string; mimeType: string; title: string } | null>(null);
    const [opening, setOpening] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);
    const [kind, setKind] = useState<DocumentKind>("national_id");
    const [docTitle, setDocTitle] = useState("");
    const [expires, setExpires] = useState("");
    const [file, setFile] = useState<File | null>(null);
    const [dragging, setDragging] = useState(false);
    const [busy, setBusy] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    useEffect(() => { if (openSignal && onUpload) setAdding(true); }, [openSignal, onUpload]);

    const open = async (d: StaffDocument) => {
        setOpening(d.id);
        try { const b = await fetchBlob(d.id); setView({ ...b, title: d.title }); }
        catch { toast.error("Could not open the file."); }
        finally { setOpening(null); }
    };
    const close = () => { if (view) URL.revokeObjectURL(view.url); setView(null); };

    const pick = (f: File | null | undefined) => {
        if (!f) return;
        if (!(f.type.startsWith("image/") || f.type === "application/pdf" || /\.(heic|heif|pdf|jpe?g|png|webp)$/i.test(f.name))) { toast.error("Choose a photo or a PDF."); return; }
        if (f.size > MAX_BYTES) { toast.error("That file is over 10 MB."); return; }
        setFile(f);
    };
    const reset = () => { setAdding(false); setDocTitle(""); setExpires(""); setFile(null); if (fileRef.current) fileRef.current.value = ""; };

    const submit = async () => {
        if (!file || !onUpload) return;
        setBusy(true);
        try {
            await onUpload(file, kind, docTitle.trim() || kindLabel(kind), expires || undefined);
            reset();
        } catch (err) {
            toast.error((err as ApiError)?.message ?? "Could not upload the file.");
        } finally { setBusy(false); }
    };

    const soon = documents.filter(d => { const n = daysToExpiry(d); return n !== null && n <= 60; }).length;

    return (
        <div className="card" aria-labelledby={titleId}>
            <CardHead icon="file" tone="info" titleId={titleId} title={title}
                subtitle={documents.length ? `${documents.length} on file${soon ? ` · ${soon} expiring or expired` : ""}` : "ID copy, contract, certificates."}
                actions={onUpload && !adding ? <button onClick={() => setAdding(true)} className="btn-secondary btn-sm"><Icon name="upload" className="w-3.5 h-3.5" />Add document</button> : undefined} />
            <div className="card-body space-y-4">
                {adding && onUpload && (
                    <div className="rounded-2xl border border-line bg-surface-50 p-4 space-y-3 animate-fade-in">
                        <div
                            role="button" tabIndex={0} aria-label="Choose a file to upload, or drop it here"
                            onClick={() => fileRef.current?.click()}
                            onKeyDown={e => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); fileRef.current?.click(); } }}
                            onDragOver={e => { e.preventDefault(); setDragging(true); }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={e => { e.preventDefault(); setDragging(false); pick(e.dataTransfer.files?.[0]); }}
                            className={clsx("flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-4 py-7 text-center cursor-pointer transition-colors",
                                dragging ? "border-brand-500 bg-brand-50" : file ? "border-success-400 bg-success-50/60" : "border-surface-300 bg-white hover:border-brand-400 hover:bg-brand-50/40")}>
                            <span className={clsx("w-11 h-11 rounded-full flex items-center justify-center", file ? "bg-success-100 text-success-700" : "bg-brand-50 text-brand-700")}>
                                <Icon name={file ? "check" : "upload"} className="w-5 h-5" strokeWidth={2} />
                            </span>
                            {file ? (
                                <p className="text-sm"><span className="font-semibold text-surface-900">{file.name}</span> <span className="text-surface-500">· {sizeWords(file.size)} · click to change</span></p>
                            ) : (
                                <>
                                    <p className="text-sm font-semibold text-surface-900">Drop a file here, or <span className="text-brand-700 underline">browse</span></p>
                                    <p className="text-xs text-surface-500">A photo or a PDF, up to 10 MB</p>
                                </>
                            )}
                            <input ref={fileRef} type="file" accept={ACCEPT} className="sr-only" tabIndex={-1} onChange={e => pick(e.target.files?.[0])} aria-hidden />
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <label className="text-xs font-semibold text-surface-700">Kind
                                <select className="input mt-1.5" value={kind} onChange={e => setKind(e.target.value as DocumentKind)}>
                                    {DOCUMENT_KINDS.map(k => <option key={k.value} value={k.value}>{k.label}</option>)}
                                </select>
                            </label>
                            <label className="text-xs font-semibold text-surface-700">Title <span className="font-normal text-surface-400">(optional)</span>
                                <input className="input mt-1.5" value={docTitle} onChange={e => setDocTitle(e.target.value)} placeholder={kindLabel(kind)} maxLength={120} />
                            </label>
                            <label className="text-xs font-semibold text-surface-700">Expires <span className="font-normal text-surface-400">(optional)</span>
                                <input type="date" className="input mt-1.5" value={expires} onChange={e => setExpires(e.target.value)} />
                            </label>
                        </div>
                        <div className="flex justify-end gap-2">
                            <button onClick={reset} disabled={busy} className="btn-ghost btn-sm">Cancel</button>
                            <button onClick={() => void submit()} disabled={!file || busy} className="btn-primary btn-sm">
                                {busy && <Spinner size="xs" className="border-white/30 border-t-white" />}Upload
                            </button>
                        </div>
                    </div>
                )}

                {documents.length === 0 ? (
                    !adding && <EmptyLine icon="file" text="No documents on file yet. Start with the ID copy and the signed contract."
                        action={onUpload ? <button onClick={() => setAdding(true)} className="btn-primary btn-sm"><Icon name="upload" className="w-3.5 h-3.5" />Add a document</button> : undefined} />
                ) : (
                    <ul className="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-3">
                        {documents.map(d => {
                            const tone = expiryTone(d);
                            return (
                                <li key={d.id} className="group rounded-2xl border border-line p-3.5 flex gap-3 hover:border-surface-300 transition-colors">
                                    <TypeBadge doc={d} />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-surface-900 truncate" title={d.title}>{d.title}</p>
                                        <p className="text-2xs text-surface-500 truncate">
                                            {kindLabel(d.kind)}{d.user ? ` · ${d.user.name}` : ""} · added {fmtDay(d.uploaded_at)}{d.size_bytes ? ` · ${sizeWords(d.size_bytes)}` : ""}
                                        </p>
                                        <div className="mt-2 flex items-center gap-1 flex-wrap">
                                            {tone && <span className={clsx(tone.cls, "mr-auto")}>{tone.label}</span>}
                                            {!tone && <span className="mr-auto" />}
                                            <button onClick={() => void open(d)} disabled={opening === d.id} className="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold text-brand-700 hover:bg-brand-50" aria-label={`View ${d.title}`}>
                                                {opening === d.id ? <Spinner size="xs" /> : <Icon name="eye" className="w-3.5 h-3.5" />}View
                                            </button>
                                            {onRemove && (
                                                <button onClick={() => { if (window.confirm(`Remove "${d.title}"? It stays on record as removed.`)) void onRemove(d); }}
                                                    className="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium text-surface-500 hover:text-danger hover:bg-danger-50" aria-label={`Remove ${d.title}`}>Remove</button>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            <Modal open={!!view} onClose={close} title={view?.title ?? ""} size="lg">
                {view && (view.mimeType.startsWith("image/")
                    ? <img src={view.url} alt={view.title} className="max-h-[75vh] w-full object-contain" />
                    : <iframe src={view.url} title={view.title} className="h-[75vh] w-full" />)}
            </Modal>
        </div>
    );
}
