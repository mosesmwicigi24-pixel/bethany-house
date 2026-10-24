import { useRef, useState } from "react";
import { clsx } from "clsx";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import { DOCUMENT_KINDS, type DocumentKind, type StaffDocument } from "@/api/people";
import type { ApiError } from "@/types";

/**
 * Staff documents (People PR 2): the list, a viewer, and — for a file keeper —
 * upload and remove. The same card serves the manager's view of a person and
 * the person's own "My employment" tab (read-only there).
 */
export function kindLabel(kind: DocumentKind) {
    return DOCUMENT_KINDS.find(k => k.value === kind)?.label ?? kind;
}

function fmtDate(iso: string | null) {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

function expiryTone(d: StaffDocument): { label: string; cls: string } | null {
    if (!d.expires_at) return null;
    if (d.expired) return { label: `Expired ${fmtDate(d.expires_at)}`, cls: "bg-danger-light text-danger" };
    const days = Math.ceil((new Date(d.expires_at).getTime() - Date.now()) / 86_400_000);
    if (days <= 60) return { label: `Expires in ${days} day${days === 1 ? "" : "s"}`, cls: "bg-warning-light text-warning" };
    return { label: `Expires ${fmtDate(d.expires_at)}`, cls: "bg-surface-100 text-surface-600" };
}

export function DocumentsCard({ documents, fetchBlob, onUpload, onRemove, title = "Documents" }: {
    documents: StaffDocument[];
    fetchBlob: (docId: number) => Promise<{ url: string; mimeType: string }>;
    /** Present only for a file keeper (hr.edit). */
    onUpload?: (file: File, kind: DocumentKind, title: string, expiresAt?: string) => Promise<void>;
    onRemove?: (doc: StaffDocument) => Promise<void>;
    title?: string;
}) {
    const toast = useToastStore();
    const [view, setView] = useState<{ url: string; mimeType: string; title: string } | null>(null);
    const [opening, setOpening] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);
    const [kind, setKind] = useState<DocumentKind>("national_id");
    const [docTitle, setDocTitle] = useState("");
    const [expires, setExpires] = useState("");
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);

    const open = async (d: StaffDocument) => {
        setOpening(d.id);
        try { const b = await fetchBlob(d.id); setView({ ...b, title: d.title }); }
        catch { toast.error("Could not open the file."); }
        finally { setOpening(null); }
    };
    const close = () => { if (view) URL.revokeObjectURL(view.url); setView(null); };

    const submit = async () => {
        if (!file || !onUpload) return;
        setBusy(true);
        try {
            await onUpload(file, kind, docTitle.trim() || kindLabel(kind), expires || undefined);
            setAdding(false); setDocTitle(""); setExpires(""); setFile(null);
            if (fileRef.current) fileRef.current.value = "";
        } catch (err) {
            toast.error((err as ApiError)?.message ?? "Could not upload the file.");
        } finally { setBusy(false); }
    };

    return (
        <div className="card">
            <div className="card-header flex items-center justify-between">
                <h3 className="font-semibold text-sm text-surface-900">{title}</h3>
                {onUpload && !adding && <button onClick={() => setAdding(true)} className="btn-secondary btn-sm">Add document</button>}
            </div>
            <div className="card-body space-y-3">
                {adding && onUpload && (
                    <div className="rounded-xl border border-line p-3 space-y-2 bg-surface-50">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <select className="input" value={kind} onChange={e => setKind(e.target.value as DocumentKind)} aria-label="Document kind">
                                {DOCUMENT_KINDS.map(k => <option key={k.value} value={k.value}>{k.label}</option>)}
                            </select>
                            <input className="input" value={docTitle} onChange={e => setDocTitle(e.target.value)} placeholder="Title (optional)" maxLength={120} aria-label="Title" />
                            <label className="text-xs text-surface-500">Expires (optional)
                                <input type="date" className="input mt-1" value={expires} onChange={e => setExpires(e.target.value)} aria-label="Expires" />
                            </label>
                            <label className="text-xs text-surface-500">File (JPG, PNG, PDF, up to 10 MB)
                                <input ref={fileRef} type="file" accept="image/*,application/pdf" className="input mt-1" onChange={e => setFile(e.target.files?.[0] ?? null)} aria-label="File" />
                            </label>
                        </div>
                        <div className="flex justify-end gap-2">
                            <button onClick={() => setAdding(false)} className="btn-secondary btn-sm">Cancel</button>
                            <button onClick={() => void submit()} disabled={!file || busy} className="btn-primary btn-sm">
                                {busy && <Spinner size="xs" className="border-white/30 border-t-white" />}Upload
                            </button>
                        </div>
                    </div>
                )}

                {documents.length === 0 ? (
                    <p className="text-sm text-surface-400">No documents on file.</p>
                ) : (
                    <ul className="divide-y divide-line rounded-lg border border-line">
                        {documents.map(d => {
                            const tone = expiryTone(d);
                            return (
                                <li key={d.id} className="flex items-center gap-3 p-2.5">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-surface-800 truncate">{d.title}</p>
                                        <p className="text-2xs text-surface-500 truncate">
                                            {kindLabel(d.kind)}{d.user ? ` · ${d.user.name}` : ""} · added {fmtDate(d.uploaded_at)}
                                        </p>
                                        {tone && <span className={clsx("inline-block mt-1 text-2xs font-semibold px-1.5 py-0.5 rounded-full", tone.cls)}>{tone.label}</span>}
                                    </div>
                                    <button onClick={() => void open(d)} disabled={opening === d.id} className="btn-ghost btn-sm text-xs text-brand-600">
                                        {opening === d.id ? "Opening…" : "View"}
                                    </button>
                                    {onRemove && (
                                        <button onClick={() => { if (window.confirm(`Remove "${d.title}"? It stays on record as removed.`)) void onRemove(d); }}
                                            className="btn-ghost btn-sm text-xs text-surface-400 hover:text-danger">Remove</button>
                                    )}
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
