import { useEffect, useState } from "react";
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
import { CardHead, EmptyLine, Icon, Meter } from "./fileKit";

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : "");

/** Training on the file (People C4 part 2). */
export function TrainingCard({ courses, self, titleId }: { courses: FileCourse[]; self?: boolean; titleId?: string }) {
    const passed = courses.filter(c => c.passed).length;
    const dueRequired = courses.filter(c => c.required && !c.passed).length;
    return (
        <div className="card h-full" aria-labelledby={titleId}>
            <CardHead icon="cap" tone="info" titleId={titleId} title="Training"
                subtitle={courses.length ? `${passed} of ${courses.length} passed${dueRequired ? ` · ${dueRequired} required still open` : ""}` : "Courses this person can take."}
                actions={<Link to="/courses" className="btn-ghost btn-sm">Courses<Icon name="chevronRight" className="w-3.5 h-3.5" /></Link>} />
            <div className="card-body space-y-4">
                {courses.length === 0 ? (
                    <EmptyLine icon="cap" text={self ? "No courses for you yet." : "No courses yet. Courses published for their role or outlet show up here."}
                        action={!self ? <Link to="/courses" className="btn-secondary btn-sm">Open courses</Link> : undefined} />
                ) : (<>
                    <Meter total={courses.length} label={`${passed} of ${courses.length} courses passed`} parts={[{ value: passed, className: "bg-success-500" }]} />
                    <ul className="divide-y divide-line text-sm">
                        {courses.map(c => (
                            <li key={c.id} className="py-2.5 flex items-center justify-between gap-3">
                                <span className="flex items-center gap-2 min-w-0">
                                    <span className={clsx("w-5 h-5 rounded-full flex items-center justify-center shrink-0", c.passed ? "bg-success-100 text-success-700" : "bg-surface-100 text-surface-400")}>
                                        {c.passed ? <Icon name="check" className="w-3 h-3" strokeWidth={2.6} /> : <span className="w-1.5 h-1.5 rounded-full bg-current" />}
                                    </span>
                                    <span className="truncate text-surface-900">{c.title}</span>
                                    {c.required && !c.passed && <span className="badge-danger shrink-0">Required</span>}
                                </span>
                                <span className={clsx("text-xs font-medium text-right shrink-0", c.passed ? "text-success-700" : "text-surface-500")}>{c.passed ? `Passed ${c.best_score}% · ${when(c.completed_at)}` : c.attempts ? `${c.attempts} attempt${c.attempts === 1 ? "" : "s"} · best ${c.best_score ?? 0}%` : "Not started"}</span>
                            </li>
                        ))}
                    </ul>
                </>)}
            </div>
        </div>
    );
}

/** Signing requests on the file (People C4 part 2): HR asks; the list shows what waits and what was signed. */
export function SigningCard({ userId, signing, documents, canAsk, onChanged, titleId, openSignal }: { userId: number; signing: FileSigning[]; documents: StaffDocument[]; canAsk: boolean; onChanged: () => void; titleId?: string; openSignal?: number }) {
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
    useEffect(() => { if (openSignal && canAsk) setOpen(true); }, [openSignal, canAsk]);
    const waiting = signing.filter(s => s.status === "pending").length;
    return (
        <div className="card h-full" aria-labelledby={titleId}>
            <CardHead icon="pen" tone="amber" titleId={titleId} title="Signatures"
                subtitle={signing.length ? `${waiting ? `${waiting} waiting · ` : ""}${signing.filter(s => s.status === "signed").length} signed` : "Contracts, policies and notes to sign."}
                actions={canAsk && signing.length > 0 ? <button onClick={() => setOpen(true)} className="btn-secondary btn-sm"><Icon name="pen" className="w-3.5 h-3.5" />Ask to sign</button> : undefined} />
            <div className="card-body">
                {signing.length === 0 ? (
                    <EmptyLine icon="pen" text="Nothing asked for yet. Send a contract, a handbook article or a short note to sign on their phone."
                        action={canAsk ? <button onClick={() => setOpen(true)} className="btn-primary btn-sm"><Icon name="pen" className="w-3.5 h-3.5" />Ask to sign</button> : undefined} />
                ) : (
                    <ul className="divide-y divide-line text-sm">
                        {signing.map(s => (
                            <li key={s.id} className="py-2.5 flex items-center justify-between gap-3">
                                <span className="min-w-0 text-surface-900">{s.title}<span className="block text-xs text-surface-500">{s.requested_by ? `Asked by ${s.requested_by}` : ""}{s.due_at && s.status === "pending" ? ` · by ${when(s.due_at)}` : ""}{s.signed_at ? ` · signed ${when(s.signed_at)}` : ""}{s.status === "declined" && s.note ? ` · "${s.note}"` : ""}</span></span>
                                <span className="flex items-center gap-2"><span className={clsx("capitalize", s.status === "pending" ? "badge-warning" : s.status === "signed" ? "badge-success" : s.status === "declined" ? "badge-danger" : "badge-neutral")}>{s.status === "pending" ? "Waiting" : s.status}</span>{canAsk && s.status === "pending" && <button onClick={() => cancel.mutate(s.id)} className="btn-ghost btn-sm text-xs text-danger">Cancel</button>}</span>
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
