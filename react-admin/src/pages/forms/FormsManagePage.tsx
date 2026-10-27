import { useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { formsApi, STATE_LABEL, type FormItem, type FormTemplate, type SubmissionBrief, type TemplateInput } from "@/api/forms";
import { outletsApi } from "@/api/setup";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * People › Checklists (/people/forms, forms.manage): the day per outlet —
 * done, late, missed, skipped, reports — a submission's answers with its
 * photos and signature, the history, and the forms themselves.
 */
const DAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
const hhmm = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" }) : "—");

export default function FormsManagePage() {
    const [params, setParams] = useSearchParams();
    const date = params.get("date") ?? new Date().toISOString().slice(0, 10);
    const tab = (params.get("tab") ?? "day") as "day" | "history" | "forms";
    const setTab = (t: string) => setParams(p => { p.set("tab", t); return p; });
    const [open, setOpen] = useState<number | null>(null);
    return (
        <div className="space-y-5 animate-fade-in">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div><h1 className="page-title">Checklists & forms</h1><p className="page-subtitle">Opening and closing lists, incident and damage reports: what was done, when, and what was skipped.</p></div>
                <div className="flex items-center gap-2">
                    {(["day", "history", "forms"] as const).map(t => <button key={t} onClick={() => setTab(t)} className={clsx("btn-sm rounded-full border px-3", tab === t ? "bg-brand-600 text-white border-brand-600" : "bg-white border-line text-surface-700")}>{t === "day" ? "The day" : t === "history" ? "History" : "Forms"}</button>)}
                    <Link to="/forms" className="btn-secondary btn-sm">Fill one in</Link>
                </div>
            </div>
            {tab === "day" && <DayView date={date} setDate={d => setParams(p => { p.set("date", d); return p; })} onOpen={setOpen} />}
            {tab === "history" && <HistoryView onOpen={setOpen} />}
            {tab === "forms" && <TemplatesView />}
            {open && <SubmissionModal id={open} onClose={() => setOpen(null)} />}
        </div>
    );
}

function StateChip({ state, skipped }: { state: string; skipped?: number }) {
    const cls = state === "submitted" ? "bg-success-light text-success" : state === "late" ? "bg-warning-light text-warning" : state === "missed" || state === "overdue" ? "bg-danger/10 text-danger" : "bg-surface-100 text-surface-600";
    return <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", cls)}>{STATE_LABEL[state as keyof typeof STATE_LABEL] || state}{skipped ? ` · ${skipped} skipped` : ""}</span>;
}

function DayView({ date, setDate, onOpen }: { date: string; setDate: (d: string) => void; onOpen: (id: number) => void }) {
    const q = useQuery({ queryKey: ["forms-overview", date], queryFn: () => formsApi.overview(date), refetchInterval: 60_000 });
    const shift = (n: number) => { const d = new Date(date); d.setDate(d.getDate() + n); setDate(d.toISOString().slice(0, 10)); };
    return (
        <div className="space-y-4">
            <div className="flex items-center gap-2 text-sm"><button onClick={() => shift(-1)} className="btn-ghost btn-sm">‹</button><input type="date" className="input px-2 py-1 text-sm" value={date} onChange={e => setDate(e.target.value)} /><button onClick={() => shift(1)} className="btn-ghost btn-sm">›</button></div>
            {q.isLoading ? <Spinner /> : (q.data?.outlets ?? []).map(o => (
                <div key={o.outlet.id} className="card">
                    <div className="card-header flex flex-wrap items-center justify-between gap-2">
                        <h3 className="font-semibold text-sm text-surface-900">{o.outlet.name}</h3>
                        <p className="text-xs text-surface-500">{o.summary.done} done · {o.summary.late} late · {o.summary.missed} missed · {o.summary.pending} pending · {o.summary.skipped} skipped · {o.summary.reports} report{o.summary.reports === 1 ? "" : "s"}</p>
                    </div>
                    <div className="card-body space-y-2 text-sm">
                        {o.checklists.length === 0 && <p className="text-surface-500">No checklist due.</p>}
                        {o.checklists.map(c => (
                            <div key={c.template.id} className="flex items-center justify-between gap-3">
                                <span><b>{c.template.name}</b>{c.template.due_time ? <span className="text-surface-400"> · due {c.template.due_time}</span> : null}{c.submission && <span className="text-surface-500"> · {c.submission.user?.name ?? "—"} at {hhmm(c.submission.submitted_at)}</span>}</span>
                                <span className="flex items-center gap-2"><StateChip state={c.state} skipped={c.submission?.skipped_required} />{c.submission && <button onClick={() => onOpen(c.submission!.id)} className="btn-ghost btn-sm text-xs">Open</button>}</span>
                            </div>
                        ))}
                        {o.reports.map(r => (
                            <div key={r.id} className="flex items-center justify-between gap-3 rounded-xl bg-danger/5 px-3 py-2">
                                <span>🚩 <b>{r.template?.name}</b> <span className="text-surface-500">· {r.user?.name ?? "—"} at {hhmm(r.submitted_at)}</span></span>
                                <button onClick={() => onOpen(r.id)} className="btn-ghost btn-sm text-xs">Open</button>
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

function HistoryView({ onOpen }: { onOpen: (id: number) => void }) {
    const [status, setStatus] = useState("");
    const [page, setPage] = useState(1);
    const q = useQuery({ queryKey: ["forms-history", status, page], queryFn: () => formsApi.list({ status: status || undefined, page }) });
    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2 text-sm"><select className="input px-2 py-1 text-sm" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}><option value="">Everything</option><option value="flagged">Flagged (skipped, late, reports)</option><option value="late">Late</option><option value="missed">Missed</option></select></div>
            <div className="card overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-surface-50 text-xs uppercase text-surface-500"><tr><th className="px-4 py-2 text-left">Date</th><th className="px-4 py-2 text-left">Form</th><th className="px-4 py-2 text-left">Outlet</th><th className="px-4 py-2 text-left">By</th><th className="px-4 py-2 text-left">State</th><th /></tr></thead>
                    <tbody className="divide-y divide-line">
                        {(q.data?.data ?? []).map((s: SubmissionBrief) => (
                            <tr key={s.id}><td className="px-4 py-2">{s.for_date}</td><td className="px-4 py-2">{s.template?.name}</td><td className="px-4 py-2">{s.outlet.name}</td><td className="px-4 py-2">{s.user?.name ?? "—"}</td><td className="px-4 py-2"><StateChip state={s.status} skipped={s.skipped_required} /></td><td className="px-4 py-2 text-right">{s.status !== "missed" && <button onClick={() => onOpen(s.id)} className="btn-ghost btn-sm text-xs">Open</button>}</td></tr>
                        ))}
                        {q.data && q.data.data.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-surface-500">Nothing here.</td></tr>}
                    </tbody>
                </table>
            </div>
            <div className="flex gap-2">{page > 1 && <button onClick={() => setPage(p => p - 1)} className="btn-secondary btn-sm">Newer</button>}{q.data?.has_more && <button onClick={() => setPage(p => p + 1)} className="btn-secondary btn-sm">Older</button>}</div>
        </div>
    );
}

function SubmissionModal({ id, onClose }: { id: number; onClose: () => void }) {
    const q = useQuery({ queryKey: ["forms-submission", id], queryFn: () => formsApi.show(id) });
    const s = q.data?.submission;
    const files = useQuery({
        queryKey: ["forms-files", id, s?.photo_keys.join(","), s?.has_signature],
        enabled: !!s,
        queryFn: async () => { const out: Record<string, string> = {}; for (const k of [...(s!.photo_keys), ...(s!.has_signature ? ["signature"] : [])]) out[k] = (await formsApi.fileLink(id, k)).url; return out; },
        staleTime: 60_000,
    });
    return (
        <Modal open onClose={onClose} size="lg" title={s ? `${s.template?.name} · ${s.outlet.name} · ${s.for_date}` : "…"}>
            {!s ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : (
                <div className="space-y-3 text-sm">
                    <p className="text-xs text-surface-500">{s.user?.name ?? "—"} · {hhmm(s.submitted_at)}{s.due_at ? ` (due ${hhmm(s.due_at)})` : ""} · <StateChip state={s.status} skipped={s.skipped_required} /></p>
                    <ul className="divide-y divide-line rounded-xl border border-line">
                        {(s.items ?? []).map((i: FormItem) => {
                            const v = s.answers?.[i.key];
                            const blank = i.type === "photo" ? !s.photo_keys.includes(i.key) : i.type === "check" ? v !== true : v === null || v === undefined || v === "";
                            return (
                                <li key={i.key} className={clsx("px-3 py-2 flex items-start justify-between gap-3", blank && i.required && "bg-danger/5")}>
                                    <span className="text-surface-800">{i.label}{i.required && <span className="text-danger"> *</span>}</span>
                                    <span className="text-right shrink-0 max-w-[55%]">
                                        {i.type === "check" ? (v === true ? "✓" : <span className="text-danger">not ticked</span>) : i.type === "photo" ? (files.data?.[i.key] ? <img src={files.data[i.key]} alt="" className="max-h-40 rounded-lg" /> : blank ? <span className="text-danger">no photo</span> : "…") : blank ? <span className="text-danger">blank</span> : <span className="font-medium">{String(v)}</span>}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                    {s.notes && <p className="text-surface-700"><b>Notes:</b> {s.notes}</p>}
                    {s.has_signature && <div><p className="text-xs text-surface-500 mb-1">Signature</p>{files.data?.signature ? <img src={files.data.signature} alt="Signature" className="h-20 rounded-lg border border-line bg-white" /> : <Spinner size="sm" />}</div>}
                </div>
            )}
        </Modal>
    );
}

function TemplatesView() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const q = useQuery({ queryKey: ["forms-templates"], queryFn: () => formsApi.templates() });
    const [editing, setEditing] = useState<FormTemplate | "new" | null>(null);
    const retire = useMutation({ mutationFn: (id: number) => formsApi.retireTemplate(id), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["forms-templates"] }); }, onError: (e: ApiError) => toast.error(e.message) });
    return (
        <div className="space-y-3">
            <div className="flex justify-end"><button onClick={() => setEditing("new")} className="btn-primary btn-sm">New form</button></div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                {(q.data?.data ?? []).map(t => (
                    <div key={t.id} className="card p-4 space-y-1">
                        <div className="flex items-start justify-between gap-2"><h3 className="font-semibold text-surface-900">{t.name}</h3><span className="text-2xs uppercase font-semibold text-surface-500">{t.kind}</span></div>
                        <p className="text-xs text-surface-500">{t.outlet?.name ?? "Every outlet"}{t.kind === "checklist" ? ` · daily${t.due_time ? ` by ${t.due_time}` : ""}${t.weekdays ? ` · ${t.weekdays.map(d => DAYS[d - 1]).join(" ")}` : ""}` : " · whenever it happens"}{t.requires_signature ? " · signed" : ""}</p>
                        <p className="text-xs text-surface-600">{t.items.length} item{t.items.length === 1 ? "" : "s"}: {t.items.map(i => i.label).join(", ")}</p>
                        <div className="flex gap-1 pt-1"><button onClick={() => setEditing(t)} className="btn-ghost btn-sm text-xs">Edit</button><button onClick={() => { if (confirm(`Retire "${t.name}"? Past submissions are kept.`)) retire.mutate(t.id); }} className="btn-ghost btn-sm text-xs text-danger">Retire</button></div>
                    </div>
                ))}
            </div>
            {editing && <TemplateModal t={editing === "new" ? null : editing} onClose={() => setEditing(null)} />}
        </div>
    );
}

function TemplateModal({ t, onClose }: { t: FormTemplate | null; onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const outlets = useQuery({ queryKey: ["outlets-for-forms"], queryFn: () => outletsApi.list() });
    const [d, setD] = useState<TemplateInput>(t ? { name: t.name, kind: t.kind, outlet_id: t.outlet?.id ?? null, schedule: t.schedule, weekdays: t.weekdays, due_time: t.due_time, requires_signature: t.requires_signature, items: t.items } : { name: "", kind: "checklist", outlet_id: null, schedule: "daily", weekdays: null, due_time: "09:00", requires_signature: false, items: [{ key: "item_1", label: "", type: "check", required: true }] });
    const save = useMutation({ mutationFn: () => (t ? formsApi.updateTemplate(t.id, d) : formsApi.createTemplate(d)), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["forms-templates"] }); onClose(); }, onError: (e: ApiError) => toast.error(e.message) });
    const setItem = (i: number, patch: Partial<FormItem>) => setD(x => ({ ...x, items: x.items.map((it, j) => (j === i ? { ...it, ...patch } : it)) }));
    const keyFor = (label: string, i: number) => (label.toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_|_$/g, "").slice(0, 30) || `item_${i + 1}`);
    return (
        <Modal open onClose={onClose} size="lg" title={t ? `Edit ${t.name}` : "New form"} footer={<div className="flex justify-end gap-2"><button onClick={onClose} className="btn-secondary btn-sm">Cancel</button><button onClick={() => save.mutate()} disabled={save.isPending || !d.name.trim() || d.items.some(i => !i.label.trim())} className="btn-primary btn-sm">Save</button></div>}>
            <div className="space-y-3 text-sm">
                <div className="grid grid-cols-2 gap-2">
                    <label className="block col-span-2">Name<input className="input w-full" value={d.name} onChange={e => setD({ ...d, name: e.target.value })} maxLength={80} /></label>
                    <label className="block">Kind<select className="input w-full" value={d.kind} onChange={e => setD({ ...d, kind: e.target.value as "checklist" | "report", schedule: e.target.value === "report" ? "none" : "daily" })}><option value="checklist">Checklist (daily, with a due time)</option><option value="report">Report (whenever it happens)</option></select></label>
                    <label className="block">For<select className="input w-full" value={d.outlet_id ?? ""} onChange={e => setD({ ...d, outlet_id: e.target.value ? Number(e.target.value) : null })}><option value="">Every outlet</option>{(outlets.data?.data ?? []).map(o => <option key={o.id} value={o.id}>{o.name}</option>)}</select></label>
                    {d.kind === "checklist" && <>
                        <label className="block">Due by<input type="time" className="input w-full" value={d.due_time ?? ""} onChange={e => setD({ ...d, due_time: e.target.value || null })} /></label>
                        <div className="block"><span>Days</span><div className="flex gap-1 mt-1">{DAYS.map((n, i) => { const on = !d.weekdays || d.weekdays.includes(i + 1); return <button key={n} type="button" onClick={() => { const cur = d.weekdays ?? [1, 2, 3, 4, 5, 6, 7]; const next = on ? cur.filter(x => x !== i + 1) : [...cur, i + 1].sort(); setD({ ...d, weekdays: next.length === 7 ? null : next }); }} className={clsx("px-2 py-0.5 rounded-full text-xs border", on ? "bg-brand-600 text-white border-brand-600" : "bg-white border-line text-surface-500")}>{n}</button>; })}</div><span className="text-2xs text-surface-400">All days = the attendance working days.</span></div>
                    </>}
                    <label className="inline-flex items-center gap-2 col-span-2"><input type="checkbox" checked={!!d.requires_signature} onChange={e => setD({ ...d, requires_signature: e.target.checked })} />Needs a signature</label>
                </div>
                <div className="space-y-2">
                    <p className="font-medium text-surface-900">Items</p>
                    {d.items.map((it, i) => (
                        <div key={i} className="flex flex-wrap items-center gap-2 rounded-xl border border-line p-2">
                            <input className="input flex-1 min-w-[160px] px-2 py-1" placeholder="What to check or fill in" value={it.label} onChange={e => setItem(i, { label: e.target.value, key: t ? it.key : keyFor(e.target.value, i) })} maxLength={140} />
                            <select className="input px-2 py-1" value={it.type} onChange={e => setItem(i, { type: e.target.value as FormItem["type"] })}><option value="check">Tick</option><option value="number">Number</option><option value="text">Text</option><option value="photo">Photo</option></select>
                            <label className="inline-flex items-center gap-1 text-xs"><input type="checkbox" checked={it.required} onChange={e => setItem(i, { required: e.target.checked })} />required</label>
                            <button type="button" onClick={() => setD(x => ({ ...x, items: x.items.filter((_, j) => j !== i) }))} className="text-surface-400 hover:text-danger" aria-label="Remove item">✕</button>
                        </div>
                    ))}
                    <button type="button" onClick={() => setD(x => ({ ...x, items: [...x.items, { key: `item_${x.items.length + 1}`, label: "", type: "check", required: true }] }))} className="btn-secondary btn-sm">+ Item</button>
                </div>
            </div>
        </Modal>
    );
}
