import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { todosApi, type Todo, type TodoScope } from "@/api/todos";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** /todos — "do this by then" (People C5): mine, the ones I gave, and the team's for a manager. */
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "");

export default function TodosPage() {
    const [params, setParams] = useSearchParams();
    const scope = (params.get("scope") as TodoScope | null) ?? "mine";
    const [status, setStatus] = useState<"open" | "done" | "all">("open");
    const [adding, setAdding] = useState(false);
    const [doing, setDoing] = useState<Todo | null>(null);
    const qc = useQueryClient();
    const toast = useToastStore();
    const q = useQuery({ queryKey: ["todos", scope, status], queryFn: () => todosApi.list(scope, status) });
    const refresh = () => qc.invalidateQueries({ queryKey: ["todos"] });
    const update = useMutation({ mutationFn: (a: { id: number; d: Parameters<typeof todosApi.update>[1] }) => todosApi.update(a.id, a.d), onSuccess: r => { toast.success(r.message); refresh(); }, onError: (e: ApiError) => toast.error(e.message) });
    const remove = useMutation({ mutationFn: (id: number) => todosApi.remove(id), onSuccess: refresh, onError: (e: ApiError) => toast.error(e.message) });
    const rows = q.data?.data ?? [];
    return (
        <div className="mx-auto w-full max-w-2xl px-4 py-4 sm:py-8 space-y-4 animate-fade-in">
            <div className="flex items-end justify-between gap-3">
                <div><h1 className="page-title">To-dos</h1><p className="page-subtitle">{q.data ? (q.data.overdue ? `${q.data.overdue} overdue · ${q.data.open} open` : `${q.data.open} open`) : ""}</p></div>
                <button onClick={() => setAdding(true)} className="btn-primary btn-sm">{q.data?.can_give ? "New to-do" : "Add"}</button>
            </div>
            <div className="flex flex-wrap gap-2 text-xs">
                {([["mine", "Mine"], ["given", "I gave"], ...(q.data?.can_give ? [["team", "The team"]] : [])] as [TodoScope, string][]).map(([s, l]) => <button key={s} onClick={() => setParams(p => { p.set("scope", s); return p; })} className={clsx("px-3 py-1 rounded-full border", scope === s ? "bg-brand-600 text-white border-brand-600" : "bg-white border-line text-surface-600")}>{l}</button>)}
                <span className="ml-auto flex gap-1">{(["open", "done", "all"] as const).map(s => <button key={s} onClick={() => setStatus(s)} className={clsx("px-2 py-1 rounded-full border", status === s ? "bg-surface-800 text-white border-surface-800" : "bg-white border-line text-surface-500")}>{s}</button>)}</span>
            </div>
            {q.isLoading ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : rows.length === 0 ? <p className="text-sm text-surface-500 py-8 text-center">Nothing here.</p> : (
                <ul className="rounded-2xl bg-white border border-line divide-y divide-line">
                    {rows.map(t => (
                        <li key={t.id} className={clsx("px-4 py-3 flex items-start gap-3", t.status !== "open" && "opacity-60")}>
                            <button onClick={() => (t.status === "open" ? setDoing(t) : (t.mine || t.gave) && update.mutate({ id: t.id, d: { status: "open" } }))} disabled={t.status === "cancelled" || (!t.mine && !t.gave && t.status !== "open")} className={clsx("mt-0.5 w-6 h-6 rounded-full border-2 flex items-center justify-center shrink-0", t.status === "done" ? "bg-success border-success text-white" : t.overdue ? "border-danger" : "border-surface-300")} aria-label={t.status === "open" ? "Mark done" : "Reopen"}>{t.status === "done" ? "✓" : ""}</button>
                            <div className="min-w-0 flex-1">
                                <p className={clsx("text-sm font-medium text-surface-900", t.status === "done" && "line-through")}>{t.title}</p>
                                {t.details && <p className="text-xs text-surface-600 whitespace-pre-line">{t.details}</p>}
                                <p className="text-xs text-surface-500">{scope !== "mine" && t.user ? `${t.user.name} · ` : ""}{t.creator && !t.gave && t.creator.id !== t.user?.id ? `from ${t.creator.name} · ` : ""}{t.due_at ? <span className={t.overdue ? "text-danger font-semibold" : ""}>{t.overdue ? "overdue · " : "by "}{when(t.due_at)}</span> : "no due time"}{t.status === "done" ? ` · done ${when(t.done_at)}${t.done_note ? ` — ${t.done_note}` : ""}` : t.status === "cancelled" ? " · cancelled" : ""}</p>
                            </div>
                            {t.status === "open" && t.gave && !t.mine && <button onClick={() => update.mutate({ id: t.id, d: { status: "cancelled" } })} className="btn-ghost btn-sm text-xs text-danger">Cancel</button>}
                            {t.status !== "open" && (t.gave || (t.mine && (!t.creator || t.creator.id === t.user?.id))) && <button onClick={() => remove.mutate(t.id)} className="btn-ghost btn-sm text-xs text-surface-400">Remove</button>}
                        </li>
                    ))}
                </ul>
            )}
            {adding && <AddModal canGive={!!q.data?.can_give} onClose={() => setAdding(false)} onDone={() => { setAdding(false); refresh(); }} />}
            {doing && <DoneModal t={doing} onClose={() => setDoing(null)} onDone={(note) => { update.mutate({ id: doing.id, d: { status: "done", done_note: note || null } }); setDoing(null); }} />}
        </div>
    );
}

function AddModal({ canGive, onClose, onDone }: { canGive: boolean; onClose: () => void; onDone: () => void }) {
    const toast = useToastStore();
    const [d, setD] = useState({ title: "", details: "", due_at: "", user_id: "" });
    const people = useQuery({ queryKey: ["todo-people"], queryFn: () => todosApi.people(), enabled: canGive });
    const save = useMutation({ mutationFn: () => todosApi.create({ title: d.title.trim(), details: d.details || null, due_at: d.due_at || null, user_id: d.user_id ? Number(d.user_id) : null }), onSuccess: r => { toast.success(r.message); onDone(); }, onError: (e: ApiError) => toast.error(e.message) });
    return (
        <Modal open onClose={onClose} title="New to-do" footer={<div className="flex justify-end gap-2"><button onClick={onClose} className="btn-secondary btn-sm">Cancel</button><button onClick={() => save.mutate()} disabled={!d.title.trim() || save.isPending} className="btn-primary btn-sm">{d.user_id ? "Give" : "Add"}</button></div>}>
            <div className="space-y-3 text-sm">
                <label className="block">What<input className="input w-full" value={d.title} onChange={e => setD({ ...d, title: e.target.value })} maxLength={140} autoFocus /></label>
                <label className="block">Details <span className="text-xs text-surface-400">(optional)</span><textarea rows={3} className="input w-full" value={d.details} onChange={e => setD({ ...d, details: e.target.value })} maxLength={2000} /></label>
                <div className="grid grid-cols-2 gap-2">
                    <label className="block">By when<input type="datetime-local" className="input w-full" value={d.due_at} onChange={e => setD({ ...d, due_at: e.target.value })} /></label>
                    {canGive && <label className="block">For<select className="input w-full" value={d.user_id} onChange={e => setD({ ...d, user_id: e.target.value })}><option value="">Me</option>{(people.data?.data ?? []).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>}
                </div>
            </div>
        </Modal>
    );
}

function DoneModal({ t, onClose, onDone }: { t: Todo; onClose: () => void; onDone: (note: string) => void }) {
    const [note, setNote] = useState("");
    return (
        <Modal open onClose={onClose} title={t.title} footer={<div className="flex justify-end gap-2"><button onClick={onClose} className="btn-secondary btn-sm">Not yet</button><button onClick={() => onDone(note.trim())} className="btn-primary btn-sm">Done</button></div>}>
            <label className="block text-sm">Anything to say about it? <span className="text-xs text-surface-400">(optional{t.creator && !t.gave ? `; ${t.creator.name} will see it` : ""})</span><textarea rows={3} className="input w-full mt-1" value={note} onChange={e => setNote(e.target.value)} maxLength={1000} autoFocus /></label>
        </Modal>
    );
}
