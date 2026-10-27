import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { handbookApi, type Article, type ArticleInput } from "@/api/handbook";
import { updatesApi } from "@/api/updates";
import { outletsApi } from "@/api/setup";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * /handbook — policies and how-tos (People C4), searchable, grouped by
 * category. A must-read article is also a must-read post on the feed:
 * opening it here marks it read, and "I have read this" acknowledges it.
 * updates.post writes; drafts are visible to writers only.
 */
export default function HandbookPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [q, setQ] = useState("");
    const [editing, setEditing] = useState<Article | "new" | null>(null);
    const list = useQuery({ queryKey: ["handbook", q], queryFn: () => handbookApi.list(q), staleTime: 30_000 });
    const groups = (list.data?.data ?? []).reduce<Record<string, Article[]>>((acc, a) => { const k = a.category ?? "General"; (acc[k] ??= []).push(a); return acc; }, {});
    return (
        <div className="mx-auto w-full max-w-2xl px-4 py-4 sm:py-8 space-y-4 animate-fade-in">
            <div className="flex items-end justify-between gap-3">
                <div><h1 className="page-title">Handbook</h1><p className="page-subtitle">How we do things at Bethany House.</p></div>
                {list.data?.can_edit && <button onClick={() => setEditing("new")} className="btn-primary btn-sm">New article</button>}
            </div>
            <input className="input w-full" placeholder="Search policies and how-tos…" value={q} onChange={e => setQ(e.target.value)} />
            {list.isLoading ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : Object.keys(groups).length === 0 ? <p className="text-sm text-surface-500 py-8 text-center">Nothing here yet.</p> : Object.entries(groups).map(([cat, items]) => (
                <section key={cat} className="space-y-2">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-surface-400">{cat}</h2>
                    <ul className="rounded-2xl bg-white border border-line divide-y divide-line">
                        {items.map(a => (
                            <li key={a.id}><Link to={`/handbook/${a.id}`} className="flex items-center gap-3 px-4 py-3">
                                <span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-surface-900">{a.title}{!a.published_at && <span className="ml-2 text-2xs uppercase text-surface-400">draft</span>}</span><span className="block text-xs text-surface-500 truncate">{a.excerpt}</span></span>
                                {a.must_read && <span className={clsx("text-2xs font-bold uppercase rounded-full px-2 py-0.5", a.acknowledged_at ? "bg-success-light text-success" : "bg-danger text-white")}>{a.acknowledged_at ? "Read" : "Must read"}</span>}
                                <span className="text-surface-400">›</span>
                            </Link></li>
                        ))}
                    </ul>
                </section>
            ))}
            {id && <ArticleModal id={Number(id)} canEdit={!!list.data?.can_edit} onClose={() => navigate("/handbook")} onEdit={a => setEditing(a)} />}
            {editing && <EditModal a={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
        </div>
    );
}

function ArticleModal({ id, canEdit, onClose, onEdit }: { id: number; canEdit: boolean; onClose: () => void; onEdit: (a: Article) => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const q = useQuery({ queryKey: ["handbook-article", id], queryFn: () => handbookApi.show(id) });
    const a = q.data?.article;
    const ack = useMutation({ mutationFn: () => updatesApi.acknowledge(a!.post_id!), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["handbook"] }); qc.invalidateQueries({ queryKey: ["handbook-article", id] }); qc.invalidateQueries({ queryKey: ["updates"] }); }, onError: (e: ApiError) => toast.error(e.message) });
    const remove = useMutation({ mutationFn: () => handbookApi.remove(id), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["handbook"] }); onClose(); } });
    return (
        <Modal open onClose={onClose} size="lg" title={a?.title ?? "…"}>
            {!a ? <div className="flex items-center justify-center h-40">{q.error ? <p className="text-sm text-danger">{(q.error as ApiError).message}</p> : <Spinner size="lg" />}</div> : (
                <div className="space-y-3 text-sm">
                    <p className="text-xs text-surface-500">{a.category ?? "General"}{a.outlet ? ` · ${a.outlet.name}` : ""} · updated {a.updated_at ? new Date(a.updated_at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : ""}{!a.published_at && " · draft"}</p>
                    <div className="whitespace-pre-line text-surface-800 leading-relaxed">{a.body}</div>
                    {a.must_read && a.post_id && (a.acknowledged_at ? <p className="rounded-xl bg-success-light text-success px-3 py-2 text-xs font-medium">You acknowledged this on {new Date(a.acknowledged_at).toLocaleDateString("en-GB", { day: "numeric", month: "short" })}.</p> : <button onClick={() => ack.mutate()} disabled={ack.isPending} className="btn-primary w-full">I have read and understood this</button>)}
                    {canEdit && <div className="flex justify-end gap-2 pt-2 border-t border-line"><button onClick={() => { if (confirm("Remove this article?")) remove.mutate(); }} className="btn-ghost btn-sm text-xs text-danger">Remove</button><button onClick={() => onEdit(a)} className="btn-secondary btn-sm">Edit</button></div>}
                </div>
            )}
        </Modal>
    );
}

function EditModal({ a, onClose }: { a: Article | null; onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const outlets = useQuery({ queryKey: ["outlets-for-handbook"], queryFn: () => outletsApi.list() });
    const [d, setD] = useState<ArticleInput>(a ? { title: a.title, body: a.body ?? "", category: a.category, outlet_id: a.outlet?.id ?? null, must_read: a.must_read, publish: !!a.published_at } : { title: "", body: "", category: "", outlet_id: null, must_read: false, publish: true });
    const save = useMutation({ mutationFn: () => (a ? handbookApi.update(a.id, d) : handbookApi.create(d)), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["handbook"] }); if (a) qc.invalidateQueries({ queryKey: ["handbook-article", a.id] }); onClose(); }, onError: (e: ApiError) => toast.error(e.message) });
    return (
        <Modal open onClose={onClose} size="lg" title={a ? "Edit article" : "New article"} footer={<div className="flex justify-end gap-2"><button onClick={onClose} className="btn-secondary btn-sm">Cancel</button><button onClick={() => save.mutate()} disabled={save.isPending || !d.title.trim() || !d.body.trim()} className="btn-primary btn-sm">{d.publish ? "Publish" : "Save draft"}</button></div>}>
            <div className="space-y-3 text-sm">
                <label className="block">Title<input className="input w-full" value={d.title} onChange={e => setD({ ...d, title: e.target.value })} maxLength={140} /></label>
                <div className="grid grid-cols-2 gap-2">
                    <label className="block">Category<input className="input w-full" value={d.category ?? ""} onChange={e => setD({ ...d, category: e.target.value || null })} placeholder="Till, Sales, Workshop, HR…" maxLength={40} /></label>
                    <label className="block">For<select className="input w-full" value={d.outlet_id ?? ""} onChange={e => setD({ ...d, outlet_id: e.target.value ? Number(e.target.value) : null })}><option value="">Everyone</option>{(outlets.data?.data ?? []).map(o => <option key={o.id} value={o.id}>{o.name}</option>)}</select></label>
                </div>
                <label className="block">Text<textarea rows={12} className="input w-full font-mono text-xs" value={d.body} onChange={e => setD({ ...d, body: e.target.value })} /></label>
                <div className="flex flex-wrap gap-4 text-xs">
                    <label className="inline-flex items-center gap-2"><input type="checkbox" checked={!!d.must_read} onChange={e => setD({ ...d, must_read: e.target.checked })} />Must read (goes on the feed; everyone presses "I have read this")</label>
                    <label className="inline-flex items-center gap-2"><input type="checkbox" checked={!!d.publish} onChange={e => setD({ ...d, publish: e.target.checked })} />Published</label>
                </div>
            </div>
        </Modal>
    );
}
