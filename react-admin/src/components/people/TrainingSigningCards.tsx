import { useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { signingApi } from "@/api/signing";
import { handbookApi } from "@/api/handbook";
import type { FileCourse, FileSigning, StaffDocument } from "@/api/people";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");

/** Training on the file (People C4 part 2). */
export function TrainingCard({ courses, self }: { courses: FileCourse[]; self?: boolean }) {
    return (
        <div className="card">
            <div className="card-header flex items-center justify-between"><h3 className="font-semibold text-sm text-surface-900">Training</h3>{self && <Link to="/courses" className="text-xs text-brand-600">Courses ›</Link>}</div>
            <div className="card-body">
                {courses.length === 0 ? <p className="text-sm text-surface-500">No courses yet.</p> : (
                    <ul className="divide-y divide-line text-sm">
                        {courses.map(c => <li key={c.id} className="py-2 flex items-center justify-between gap-3"><span>{c.title}{c.required && !c.passed && <span className="ml-2 text-2xs font-bold uppercase text-danger">required</span>}</span><span className={clsx("text-xs font-medium", c.passed ? "text-success" : "text-surface-500")}>{c.passed ? `Passed ${c.best_score}% · ${when(c.completed_at)}` : c.attempts ? `${c.attempts} attempt${c.attempts === 1 ? "" : "s"} · best ${c.best_score ?? 0}%` : "Not started"}</span></li>)}
                    </ul>
                )}
            </div>
        </div>
    );
}

/** Signing requests on the file (People C4 part 2): HR asks; the list shows what waits and what was signed. */
export function SigningCard({ userId, signing, documents, canAsk, onChanged }: { userId: number; signing: FileSigning[]; documents: StaffDocument[]; canAsk: boolean; onChanged: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [open, setOpen] = useState(false);
    const [kind, setKind] = useState<"document" | "article" | "text">("document");
    const [docId, setDocId] = useState("");
    const [articleId, setArticleId] = useState("");
    const [title, setTitle] = useState("");
    const [body, setBody] = useState("");
    const [due, setDue] = useState("");
    const articles = useQuery({ queryKey: ["handbook", ""], queryFn: () => handbookApi.list(), enabled: open && kind === "article" });
    const ask = useMutation({
        mutationFn: () => signingApi.request(userId, { kind, staff_document_id: kind === "document" ? Number(docId) : undefined, article_id: kind === "article" ? Number(articleId) : undefined, title: title || undefined, body: kind === "text" ? body : undefined, due_at: due || null }),
        onSuccess: r => { toast.success(r.message); setOpen(false); setTitle(""); setBody(""); qc.invalidateQueries({ queryKey: ["person", userId] }); onChanged(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const cancel = useMutation({ mutationFn: (id: number) => signingApi.cancel(userId, id), onSuccess: r => { toast.success(r.message); onChanged(); }, onError: (e: ApiError) => toast.error(e.message) });
    const ok = kind === "document" ? !!docId : kind === "article" ? !!articleId : !!title.trim() && !!body.trim();
    return (
        <div className="card">
            <div className="card-header flex items-center justify-between"><h3 className="font-semibold text-sm text-surface-900">Signatures</h3>{canAsk && <button onClick={() => setOpen(true)} className="btn-secondary btn-sm">Ask to sign</button>}</div>
            <div className="card-body">
                {signing.length === 0 ? <p className="text-sm text-surface-500">Nothing asked for yet.</p> : (
                    <ul className="divide-y divide-line text-sm">
                        {signing.map(s => (
                            <li key={s.id} className="py-2 flex items-center justify-between gap-3">
                                <span>{s.title}<span className="block text-xs text-surface-500">{s.requested_by ? `Asked by ${s.requested_by}` : ""}{s.due_at && s.status === "pending" ? ` · by ${when(s.due_at)}` : ""}{s.signed_at ? ` · signed ${when(s.signed_at)}` : ""}{s.status === "declined" && s.note ? ` · "${s.note}"` : ""}</span></span>
                                <span className="flex items-center gap-2"><span className={clsx("text-2xs font-bold uppercase rounded-full px-2 py-0.5", s.status === "pending" ? "bg-warning-light text-warning" : s.status === "signed" ? "bg-success-light text-success" : "bg-surface-100 text-surface-600")}>{s.status}</span>{canAsk && s.status === "pending" && <button onClick={() => cancel.mutate(s.id)} className="btn-ghost btn-sm text-xs text-danger">Cancel</button>}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {open && (
                <Modal open onClose={() => setOpen(false)} title="Ask to sign" footer={<div className="flex justify-end gap-2"><button onClick={() => setOpen(false)} className="btn-secondary btn-sm">Cancel</button><button onClick={() => ask.mutate()} disabled={!ok || ask.isPending} className="btn-primary btn-sm">{ask.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Send</button></div>}>
                    <div className="space-y-3 text-sm">
                        <div className="flex gap-2 text-xs">{(["document", "article", "text"] as const).map(k => <button key={k} onClick={() => setKind(k)} className={clsx("px-3 py-1 rounded-full border", kind === k ? "bg-brand-600 text-white border-brand-600" : "bg-white border-line text-surface-600")}>{k === "document" ? "A document on file" : k === "article" ? "A handbook article" : "Text"}</button>)}</div>
                        {kind === "document" && <label className="block">Document<select className="input w-full" value={docId} onChange={e => setDocId(e.target.value)}><option value="">Choose…</option>{documents.filter(d => d.kind !== "signed").map(d => <option key={d.id} value={d.id}>{d.title}</option>)}</select></label>}
                        {kind === "article" && <label className="block">Article<select className="input w-full" value={articleId} onChange={e => setArticleId(e.target.value)}><option value="">Choose…</option>{(articles.data?.data ?? []).filter(a => a.published_at).map(a => <option key={a.id} value={a.id}>{a.title}</option>)}</select></label>}
                        {kind === "text" ? <><label className="block">Title<input className="input w-full" value={title} onChange={e => setTitle(e.target.value)} maxLength={140} /></label><label className="block">The words to sign<textarea rows={6} className="input w-full" value={body} onChange={e => setBody(e.target.value)} /></label></> : <label className="block">Title <span className="text-xs text-surface-400">(optional)</span><input className="input w-full" value={title} onChange={e => setTitle(e.target.value)} maxLength={140} /></label>}
                        <label className="block">Sign by <span className="text-xs text-surface-400">(optional)</span><input type="date" className="input w-full" value={due} onChange={e => setDue(e.target.value)} /></label>
                    </div>
                </Modal>
            )}
        </div>
    );
}
