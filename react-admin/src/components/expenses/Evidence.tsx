import { useEffect, useRef, useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import { compressImage } from "@/utils/compressImage";
import {
    EVIDENCE_KINDS,
    expensesApi,
    type EvidenceKind,
    type ExpenseAttachment,
} from "@/api/expenses";

/**
 * Expense evidence (owner, 2026-10-16): a photo of the receipt, the paper
 * quotation, the water bill, the meter. Photos are shrunk on the phone
 * (≤1600 px JPEG) before they go up, so they upload quickly on mobile data.
 * The server keeps every file: a wrong one can be withdrawn only until
 * someone acts on the expense, and it stays listed as removed.
 */

export interface PendingEvidence {
    key: string;
    file: File;
    kind: EvidenceKind;
    preview: string | null;
}

const MAX_FILES = 10;
const ACCEPT_FILES = "image/*,application/pdf";

async function prepare(file: File): Promise<File> {
    return file.type.startsWith("image/") ? compressImage(file, 1600, 0.8).catch(() => file) : file;
}

/** A sensible first label: a PDF is usually a bill; a photo, the receipt. */
function guessKind(file: File, existing: number): EvidenceKind {
    if (file.type === "application/pdf") return "invoice";
    return existing === 0 ? "receipt" : "quotation";
}

function kindLabel(kind: EvidenceKind): string {
    return EVIDENCE_KINDS.find((k) => k.value === kind)?.label ?? kind;
}

function AddButtons({ onFiles, disabled }: { onFiles: (files: File[]) => void; disabled?: boolean }) {
    const camera = useRef<HTMLInputElement>(null);
    const picker = useRef<HTMLInputElement>(null);
    const take = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(e.target.files ?? []);
        e.target.value = "";
        if (files.length) onFiles(files);
    };

    return (
        <div className="grid grid-cols-2 gap-2">
            {/* capture opens the camera straight away on a phone; a desktop shows a file picker. */}
            <input ref={camera} type="file" accept="image/*" capture="environment" className="hidden" onChange={take} />
            <input ref={picker} type="file" accept={ACCEPT_FILES} multiple className="hidden" onChange={take} />
            <button type="button" onClick={() => camera.current?.click()} disabled={disabled} className="btn-secondary btn-sm justify-center whitespace-nowrap">
                📷 Take photo
            </button>
            <button type="button" onClick={() => picker.current?.click()} disabled={disabled} className="btn-ghost btn-sm justify-center whitespace-nowrap border border-line">
                Choose file
            </button>
        </div>
    );
}

/** In the New Expense form: files held until the expense is saved, then uploaded. */
export function EvidencePicker({ items, onChange }: { items: PendingEvidence[]; onChange: (items: PendingEvidence[]) => void }) {
    const toast = useToastStore();
    const [busy, setBusy] = useState(false);

    // Release thumbnails when the form forgets them.
    const urls = useRef(new Set<string>());
    useEffect(() => () => urls.current.forEach((u) => URL.revokeObjectURL(u)), []);

    const add = async (files: File[]) => {
        const room = MAX_FILES - items.length;
        if (room <= 0) {
            toast.error(`Up to ${MAX_FILES} files per expense.`);
            return;
        }
        setBusy(true);
        const next = [...items];
        for (const raw of files.slice(0, room)) {
            const file = await prepare(raw);
            const preview = file.type.startsWith("image/") ? URL.createObjectURL(file) : null;
            if (preview) urls.current.add(preview);
            next.push({ key: `${Date.now()}-${Math.random()}`, file, kind: guessKind(file, next.length), preview });
        }
        setBusy(false);
        onChange(next);
    };

    return (
        <div className="rounded-lg border border-surface-200 p-4 space-y-3">
            <div>
                <p className="text-sm font-medium text-surface-800">Evidence</p>
                <p className="text-xs text-surface-500">A photo of the receipt, the paper quotation, the bill or the meter. Optional, but approvers look for it.</p>
            </div>
            {items.length > 0 && (
                <ul className="space-y-2">
                    {items.map((it) => (
                        <li key={it.key} className="flex items-center gap-3">
                            {it.preview ? (
                                <img src={it.preview} alt="" className="h-12 w-12 rounded object-cover border border-line shrink-0" />
                            ) : (
                                <span className="h-12 w-12 rounded border border-line flex items-center justify-center text-2xs text-surface-500 shrink-0">PDF</span>
                            )}
                            <select
                                aria-label="What is this file?"
                                value={it.kind}
                                onChange={(e) => onChange(items.map((x) => (x.key === it.key ? { ...x, kind: e.target.value as EvidenceKind } : x)))}
                                className="input flex-1 min-w-0"
                            >
                                {EVIDENCE_KINDS.map((k) => <option key={k.value} value={k.value}>{k.label}</option>)}
                            </select>
                            <button
                                type="button"
                                onClick={() => onChange(items.filter((x) => x.key !== it.key))}
                                className="btn-ghost btn-sm text-danger shrink-0"
                                aria-label="Remove this file"
                            >
                                ✕
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {busy ? <Spinner size="sm" /> : <AddButtons onFiles={add} disabled={items.length >= MAX_FILES} />}
        </div>
    );
}

/**
 * After the expense is saved: send the held files. Returns how many failed —
 * the expense itself is already saved, so a failed photo is added later from
 * the expense page, never a reason to save the expense twice.
 */
export async function uploadPending(expenseId: number, items: PendingEvidence[]): Promise<number> {
    let failed = 0;
    for (const it of items) {
        try {
            await expensesApi.addAttachment(expenseId, it.file, it.kind, it.file.name);
        } catch {
            failed++;
        }
    }
    return failed;
}

/** On the expense page: the evidence on record, viewing, adding, withdrawing. */
export function EvidenceList({
    expenseId,
    attachments,
    canAdd,
    onChanged,
}: {
    expenseId: number;
    attachments: ExpenseAttachment[];
    canAdd: boolean;
    onChanged: () => void;
}) {
    const toast = useToastStore();
    const [kind, setKind] = useState<EvidenceKind>("receipt");
    const [busy, setBusy] = useState(false);
    const [view, setView] = useState<{ url: string; mimeType: string; title: string } | null>(null);
    const [opening, setOpening] = useState<number | null>(null);

    const live = attachments.filter((a) => !a.removed_at);

    const add = async (files: File[]) => {
        setBusy(true);
        let ok = 0;
        for (const raw of files.slice(0, Math.max(0, MAX_FILES - live.length))) {
            try {
                const file = await prepare(raw);
                await expensesApi.addAttachment(expenseId, file, kind, file.name);
                ok++;
            } catch (err: any) {
                toast.error(err?.message ?? "Upload failed.");
            }
        }
        setBusy(false);
        if (ok) {
            toast.success(ok === 1 ? `${kindLabel(kind)} added.` : `${ok} files added.`);
            onChanged();
        }
    };

    const open = async (a: ExpenseAttachment) => {
        setOpening(a.id);
        try {
            const blob = await expensesApi.fetchAttachmentBlob(expenseId, a.id);
            setView({ ...blob, title: kindLabel(a.kind) });
        } catch {
            toast.error("Could not open the file.");
        } finally {
            setOpening(null);
        }
    };

    const close = () => {
        if (view) URL.revokeObjectURL(view.url);
        setView(null);
    };

    const remove = async (a: ExpenseAttachment) => {
        if (!window.confirm(`Remove this ${kindLabel(a.kind).toLowerCase()}? It stays on record as removed.`)) return;
        try {
            const res = await expensesApi.removeAttachment(expenseId, a.id);
            toast.success(res.message);
            onChanged();
        } catch (err: any) {
            toast.error(err?.message ?? "Could not remove it.");
        }
    };

    return (
        <div className="space-y-3">
            {attachments.length === 0 ? (
                <p className="text-sm text-surface-400">No evidence yet.</p>
            ) : (
                <ul className="divide-y divide-line rounded-lg border border-line">
                    {attachments.map((a) => (
                        <li key={a.id} className={`flex items-center gap-2 p-2.5 ${a.removed_at ? "opacity-60" : ""}`}>
                            <div className="min-w-0 flex-1">
                                <p className={`text-sm font-medium text-surface-800 ${a.removed_at ? "line-through" : ""}`}>{kindLabel(a.kind)}</p>
                                <p className="text-2xs text-surface-500 truncate">
                                    {a.uploader ? `${a.uploader.first_name} ${a.uploader.last_name}` : "—"} · {new Date(a.created_at).toLocaleString()}
                                    {a.removed_at && ` · removed${a.remover ? ` by ${a.remover.first_name}` : ""}`}
                                </p>
                            </div>
                            <button type="button" onClick={() => void open(a)} disabled={opening === a.id} className="btn-ghost btn-sm shrink-0">
                                {opening === a.id ? <Spinner size="xs" /> : "View"}
                            </button>
                            {a.can_remove && (
                                <button type="button" onClick={() => void remove(a)} className="btn-ghost btn-sm text-danger shrink-0">Remove</button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {canAdd && live.length < MAX_FILES && (
                <div className="space-y-2">
                    <select aria-label="What are you adding?" value={kind} onChange={(e) => setKind(e.target.value as EvidenceKind)} className="input w-full">
                        {EVIDENCE_KINDS.map((k) => <option key={k.value} value={k.value}>Add: {k.label}</option>)}
                    </select>
                    {busy ? <Spinner size="sm" /> : <AddButtons onFiles={(f) => void add(f)} />}
                </div>
            )}

            <Modal open={!!view} onClose={close} title={view?.title ?? ""} size="lg">
                {view && (view.mimeType.startsWith("image/") ? (
                    <img src={view.url} alt={view.title} className="max-h-[75vh] w-full object-contain" />
                ) : (
                    <iframe src={view.url} title={view.title} className="h-[75vh] w-full" />
                ))}
            </Modal>
        </div>
    );
}
