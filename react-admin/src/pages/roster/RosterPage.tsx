import { useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { rosterApi, mondayOf, addDays, toIso, fmtDay, fmtHours, type Shift, type ShiftInput } from "@/api/roster";
import { outletsApi } from "@/api/setup";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * The weekly roster (People PR 3): one outlet, one week, a row per person
 * plus a row of open shifts. Managers draft, publish, copy last week and
 * decide swaps. Reading needs attendance.view_team; the buttons need
 * roster.manage (the server tells us with can_manage).
 */

const dow = (iso: string) => new Date(iso + "T00:00:00").getDay();

function ShiftChip({ s, onClick }: { s: Shift; onClick?: () => void }) {
    return (
        <button type="button" onClick={onClick} disabled={!onClick}
            className={clsx("w-full text-left rounded-lg px-2 py-1 text-2xs leading-tight border transition-colors",
                s.status === "draft" ? "border-dashed border-surface-300 bg-surface-50 text-surface-600" : "border-brand-200 bg-brand-50 text-brand-800",
                onClick && "hover:border-brand-400")}>
            <span className="font-bold tabular-nums">{s.starts_at}–{s.ends_at}{s.overnight ? "⁺" : ""}</span>
            {s.label && <span className="block truncate">{s.label}</span>}
        </button>
    );
}

export default function RosterPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [params, setParams] = useSearchParams();
    const { data: outletsData } = useQuery({ queryKey: ["outlets"], queryFn: () => outletsApi.list() });
    const outlets = outletsData?.data ?? [];
    const outletId = Number(params.get("outlet_id")) || outlets[0]?.id || 0;
    const week = mondayOf(params.get("week") || toIso(new Date()));
    const setQuery = (next: { outlet_id?: number; week?: string }) => {
        const p = new URLSearchParams(params);
        if (next.outlet_id) p.set("outlet_id", String(next.outlet_id));
        if (next.week) p.set("week", next.week);
        setParams(p, { replace: true });
    };
    useEffect(() => { if (!params.get("outlet_id") && outlets[0]) setQuery({ outlet_id: outlets[0].id, week }); /* eslint-disable-line react-hooks/exhaustive-deps */ }, [outlets.length]);

    const { data, isLoading } = useQuery({ queryKey: ["roster", outletId, week], queryFn: () => rosterApi.week(outletId, week), enabled: outletId > 0 });
    const refresh = () => qc.invalidateQueries({ queryKey: ["roster", outletId, week] });
    const onErr = (e: ApiError) => toast.error(e.message);

    const templates = useQuery({ queryKey: ["shift-templates", outletId], queryFn: () => rosterApi.templates(outletId), enabled: outletId > 0, staleTime: 300_000 });
    const [showTemplates, setShowTemplates] = useState(false);
    const [editing, setEditing] = useState<{ shift?: Shift; user_id: number | null; date: string } | null>(null);
    const [form, setForm] = useState({ starts_at: "08:00", ends_at: "17:00", label: "", notes: "" });
    useEffect(() => {
        if (editing?.shift) setForm({ starts_at: editing.shift.starts_at, ends_at: editing.shift.ends_at, label: editing.shift.label ?? "", notes: editing.shift.notes ?? "" });
        else if (editing) setForm(f => ({ ...f, label: "", notes: "" }));
    }, [editing]);

    const add = useMutation({ mutationFn: (d: ShiftInput) => rosterApi.addShift(d), onSuccess: r => { toast.success(r.message); setEditing(null); refresh(); }, onError: onErr });
    const upd = useMutation({ mutationFn: (p: { id: number; d: Partial<ShiftInput> }) => rosterApi.updateShift(p.id, p.d), onSuccess: r => { toast.success(r.message); setEditing(null); refresh(); }, onError: onErr });
    const rem = useMutation({ mutationFn: (id: number) => rosterApi.removeShift(id), onSuccess: r => { toast.success(r.message); setEditing(null); refresh(); }, onError: onErr });
    const pub = useMutation({ mutationFn: () => rosterApi.publish(outletId, week), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const copy = useMutation({ mutationFn: () => rosterApi.copyWeek(outletId, addDays(week, -7), week), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const approve = useMutation({ mutationFn: (id: number) => rosterApi.approveSwap(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const decline = useMutation({ mutationFn: (id: number) => rosterApi.declineSwap(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });

    const byCell = useMemo(() => {
        const m = new Map<string, Shift[]>();
        for (const s of data?.shifts ?? []) {
            const k = `${s.user_id ?? "open"}:${s.date}`;
            m.set(k, [...(m.get(k) ?? []), s]);
        }
        return m;
    }, [data]);

    const submit = () => {
        if (!editing) return;
        if (editing.shift) upd.mutate({ id: editing.shift.id, d: { starts_at: form.starts_at, ends_at: form.ends_at, label: form.label || null, notes: form.notes || null } });
        else add.mutate({ outlet_id: outletId, user_id: editing.user_id, date: editing.date, starts_at: form.starts_at, ends_at: form.ends_at, label: form.label || null, notes: form.notes || null });
    };
    const can = data?.can_manage ?? false;
    const todayIso = toIso(new Date());

    return (
        <div className="space-y-4 animate-fade-in">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 className="page-title">Roster</h1>
                    <p className="page-subtitle">Who works when. Drafts are grey until you publish; published shifts reach the person's phone.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <select className="input w-44" value={outletId} onChange={e => setQuery({ outlet_id: Number(e.target.value) })} aria-label="Outlet">
                        {outlets.map(o => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                    <div className="flex items-center gap-1">
                        <button onClick={() => setQuery({ week: addDays(week, -7) })} className="btn-ghost btn-sm" aria-label="Previous week">←</button>
                        <button onClick={() => setQuery({ week: mondayOf(todayIso) })} className="btn-ghost btn-sm text-xs">This week</button>
                        <button onClick={() => setQuery({ week: addDays(week, 7) })} className="btn-ghost btn-sm" aria-label="Next week">→</button>
                    </div>
                    {can && (
                        <>
                            <button onClick={() => copy.mutate()} disabled={copy.isPending} className="btn-secondary btn-sm">Copy last week</button>
                            <button onClick={() => setShowTemplates(true)} className="btn-secondary btn-sm">Templates</button>
                            <button onClick={() => pub.mutate()} disabled={pub.isPending || !data?.draft_count} className="btn-primary btn-sm">
                                Publish{data?.draft_count ? ` ${data.draft_count}` : ""}
                            </button>
                        </>
                    )}
                </div>
            </div>

            {isLoading || !data ? <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div> : (
                <>
                    <p className="text-sm font-semibold text-surface-700">Week of {fmtDay(data.week_start, { day: "numeric", month: "long" })} – {fmtDay(data.week_end, { day: "numeric", month: "long", year: "numeric" })}
                        <span className="ml-2 text-xs font-normal text-surface-400">{data.shifts.length} shift{data.shifts.length === 1 ? "" : "s"}{data.open_count ? ` · ${data.open_count} open` : ""}{data.draft_count ? ` · ${data.draft_count} draft` : ""}</span>
                    </p>

                    <div className="card overflow-x-auto">
                        <table className="w-full text-sm min-w-[860px]">
                            <thead>
                                <tr className="border-b border-line">
                                    <th className="text-left px-3 py-2 w-44 text-2xs font-semibold uppercase tracking-wider text-surface-400">Person</th>
                                    {data.days.map(d => (
                                        <th key={d} className={clsx("px-2 py-2 text-center text-2xs font-semibold uppercase tracking-wider", d === todayIso ? "text-brand-600" : "text-surface-400", dow(d) === 0 && "bg-surface-50")}>
                                            {fmtDay(d, { weekday: "short" })}<br /><span className="text-sm font-bold tabular-nums text-surface-800">{fmtDay(d, { day: "numeric" })}</span>
                                            {data.holidays[d] && <span className="block text-[9px] normal-case tracking-normal text-warning truncate" title={data.holidays[d]}>{data.holidays[d]}</span>}
                                        </th>
                                    ))}
                                    <th className="px-2 py-2 text-right text-2xs font-semibold uppercase tracking-wider text-surface-400 w-16">Hours</th>
                                    {data.cost && <th className="px-2 py-2 text-right text-2xs font-semibold uppercase tracking-wider text-surface-400 w-24" title={data.cost.basis}>Cost (est.)</th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                <tr className="bg-warning-light/40">
                                    <td className="px-3 py-2 text-xs font-bold text-warning">Open shifts</td>
                                    {data.days.map(d => (
                                        <td key={d} className="px-1 py-1 align-top">
                                            <div className="space-y-1">
                                                {(byCell.get(`open:${d}`) ?? []).map(s => <ShiftChip key={s.id} s={s} onClick={can ? () => setEditing({ shift: s, user_id: null, date: d }) : undefined} />)}
                                                {can && <button onClick={() => setEditing({ user_id: null, date: d })} className="w-full text-2xs text-surface-300 hover:text-brand-600 py-0.5" aria-label={`Add open shift on ${d}`}>+</button>}
                                            </div>
                                        </td>
                                    ))}
                                    <td />
                                </tr>
                                {data.members.map(m => (
                                    <tr key={m.id}>
                                        <td className="px-3 py-2 align-top">
                                            <p className="font-semibold text-surface-900 truncate">{m.name}</p>
                                            <p className="text-2xs text-surface-400 truncate">{m.job_title ?? ""}</p>
                                        </td>
                                        {data.days.map(d => (
                                            <td key={d} className={clsx("px-1 py-1 align-top", dow(d) === 0 && "bg-surface-50/60")}>
                                                <div className="space-y-1">
                                                    {data.leave?.[String(m.id)]?.[d] && (
                                                        <div className={clsx("rounded-lg px-2 py-1 text-2xs leading-tight border", data.leave[String(m.id)][d].status === "approved" ? "border-surface-300 bg-surface-100 text-surface-600" : "border-dashed border-warning bg-warning-light/50 text-warning")}>
                                                            {data.leave[String(m.id)][d].status === "approved" ? "Off" : "Leave asked"}{data.leave[String(m.id)][d].type ? ` · ${data.leave[String(m.id)][d].type}` : ""}
                                                        </div>
                                                    )}
                                                    {(byCell.get(`${m.id}:${d}`) ?? []).map(s => <ShiftChip key={s.id} s={s} onClick={can ? () => setEditing({ shift: s, user_id: m.id, date: d }) : undefined} />)}
                                                    {can && <button onClick={() => setEditing({ user_id: m.id, date: d })} className="w-full text-2xs text-surface-300 hover:text-brand-600 py-0.5" aria-label={`Add shift for ${m.name} on ${d}`}>+</button>}
                                                </div>
                                            </td>
                                        ))}
                                        <td className="px-2 py-2 text-right tabular-nums text-surface-700 align-top">{data.hours_by_user[String(m.id)] ?? 0}</td>
                                        {data.cost && <td className="px-2 py-2 text-right tabular-nums text-surface-500 align-top text-xs">{data.cost.by_person[String(m.id)] != null ? kes(data.cost.by_person[String(m.id)]) : "—"}</td>}
                                    </tr>
                                ))}
                                {data.cost && (
                                    <tr className="bg-surface-50 text-xs">
                                        <td className="px-3 py-2 font-semibold text-surface-700">Labour cost (estimate)</td>
                                        {data.days.map(d => <td key={d} className="px-1 py-2 text-center tabular-nums text-surface-600">{data.cost!.by_day[d] != null ? kes(data.cost!.by_day[d]) : ""}</td>)}
                                        <td />
                                        <td className="px-2 py-2 text-right tabular-nums font-semibold text-surface-800">{kes(data.cost.total)}{data.cost.uncosted_shifts ? <span className="block font-normal text-surface-400" title="Shifts for people without a pay structure">{data.cost.uncosted_shifts} uncosted</span> : null}</td>
                                    </tr>
                                )}
                                {data.members.length === 0 && <tr><td colSpan={10} className="p-6 text-center text-sm text-surface-400">Nobody is assigned to this outlet yet (Settings › Users).</td></tr>}
                            </tbody>
                        </table>
                    </div>

                    {data.pending_swaps.length > 0 && (
                        <div className="card">
                            <div className="card-header"><h3 className="font-semibold text-sm text-surface-900">Swap requests waiting</h3></div>
                            <ul className="divide-y divide-line">
                                {data.pending_swaps.map(r => (
                                    <li key={r.id} className="flex flex-col sm:flex-row sm:items-center gap-2 px-4 py-3">
                                        <div className="flex-1 text-sm">
                                            <b>{r.from_user?.name}</b> asks to hand {r.shift ? `${fmtDay(r.shift.date)} ${r.shift.starts_at}–${r.shift.ends_at}` : "a shift"} to <b>{r.to_user?.name ?? "anyone"}</b>
                                            {r.note && <span className="block text-xs text-surface-500">“{r.note}”</span>}
                                        </div>
                                        {can && (
                                            <div className="flex gap-2">
                                                <button onClick={() => decline.mutate(r.id)} className="btn-secondary btn-sm">Decline</button>
                                                <button onClick={() => approve.mutate(r.id)} className="btn-primary btn-sm">Approve</button>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </>
            )}

            {showTemplates && <TemplatesModal outletId={outletId} onClose={() => setShowTemplates(false)} />}
            <Modal open={!!editing} onClose={() => setEditing(null)} title={editing?.shift ? "Edit shift" : editing?.user_id ? "Add shift" : "Add open shift"} size="md"
                footer={editing && (
                    <>
                        {editing.shift && <button onClick={() => { if (window.confirm(editing.shift!.status === "published" ? "Cancel this published shift? The person will be told." : "Remove this draft?")) rem.mutate(editing.shift!.id); }} className="btn-ghost btn-sm text-danger mr-auto">{editing.shift.status === "published" ? "Cancel shift" : "Remove"}</button>}
                        <button onClick={() => setEditing(null)} className="btn-secondary btn-sm">Close</button>
                        <button onClick={submit} disabled={add.isPending || upd.isPending} className="btn-primary btn-sm">{(add.isPending || upd.isPending) && <Spinner size="xs" className="border-white/30 border-t-white" />}Save</button>
                    </>
                )}>
                {editing && (
                    <div className="space-y-3">
                        <p className="text-sm text-surface-600">
                            {editing.user_id ? data?.members.find(m => m.id === editing.user_id)?.name : "Open shift (anyone at the outlet can claim it once published)"} · {fmtDay(editing.date, { weekday: "long", day: "numeric", month: "long" })}
                            {editing.shift && <span className={clsx("ml-2 text-2xs font-semibold px-2 py-0.5 rounded-full", editing.shift.status === "published" ? "bg-success-light text-success" : "bg-surface-100 text-surface-600")}>{editing.shift.status}</span>}
                        </p>
                        {(templates.data?.data.length ?? 0) > 0 && (
                            <div className="flex flex-wrap gap-1">{templates.data!.data.map(t => <button key={t.id} type="button" onClick={() => setForm(f => ({ ...f, starts_at: t.starts_at, ends_at: t.ends_at, label: t.label ?? f.label }))} className={clsx("px-2 py-0.5 rounded-full text-xs border", form.starts_at === t.starts_at && form.ends_at === t.ends_at ? "bg-brand-600 text-white border-brand-600" : "bg-white border-line text-surface-600")} title={`${t.starts_at}–${t.ends_at}`}>{t.name}</button>)}</div>
                        )}
                        <div className="grid grid-cols-2 gap-3">
                            <label className="text-xs text-surface-500">Starts<input type="time" className="input mt-1" value={form.starts_at} onChange={e => setForm({ ...form, starts_at: e.target.value })} /></label>
                            <label className="text-xs text-surface-500">Ends<input type="time" className="input mt-1" value={form.ends_at} onChange={e => setForm({ ...form, ends_at: e.target.value })} /></label>
                            <label className="text-xs text-surface-500 col-span-2">Label<input className="input mt-1" value={form.label} onChange={e => setForm({ ...form, label: e.target.value })} maxLength={60} placeholder="Morning, Fitting room, Cutting…" /></label>
                            <label className="text-xs text-surface-500 col-span-2">Notes<input className="input mt-1" value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} maxLength={500} /></label>
                        </div>
                        <p className="text-2xs text-surface-400">An end time before the start means the shift runs into the next day.</p>
                    </div>
                )}
            </Modal>
        </div>
    );
}

const kes = (v: number) => "KES " + Math.round(v).toLocaleString("en-KE");

/** Shift templates (People C4): named start/end/label pairs the shift modal offers as chips. roster.manage adds and removes. */
function TemplatesModal({ outletId, onClose }: { outletId: number; onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const q = useQuery({ queryKey: ["shift-templates", outletId], queryFn: () => rosterApi.templates(outletId) });
    const [d, setD] = useState({ name: "", starts_at: "08:00", ends_at: "17:00", label: "", here: true });
    const refresh = () => qc.invalidateQueries({ queryKey: ["shift-templates", outletId] });
    const add = useMutation({ mutationFn: () => rosterApi.addTemplate({ outlet_id: d.here ? outletId : null, name: d.name.trim(), starts_at: d.starts_at, ends_at: d.ends_at, label: d.label || null }), onSuccess: r => { toast.success(r.message); setD(x => ({ ...x, name: "", label: "" })); refresh(); }, onError: (e: ApiError) => toast.error(e.message) });
    const rem = useMutation({ mutationFn: (id: number) => rosterApi.removeTemplate(id), onSuccess: refresh, onError: (e: ApiError) => toast.error(e.message) });
    return (
        <Modal open onClose={onClose} title="Shift templates" size="md">
            <div className="space-y-3 text-sm">
                <ul className="divide-y divide-line rounded-xl border border-line">
                    {(q.data?.data ?? []).map(t => <li key={t.id} className="flex items-center justify-between px-3 py-2"><span><b>{t.name}</b> <span className="text-surface-500">{t.starts_at}–{t.ends_at}{t.label ? ` · ${t.label}` : ""}{t.outlet_id ? "" : " · every outlet"}</span></span><button onClick={() => rem.mutate(t.id)} className="btn-ghost btn-sm text-xs text-danger">Remove</button></li>)}
                    {q.data && q.data.data.length === 0 && <li className="px-3 py-3 text-surface-500">No templates yet.</li>}
                </ul>
                <form onSubmit={e => { e.preventDefault(); if (d.name.trim()) add.mutate(); }} className="grid grid-cols-2 gap-2 rounded-xl bg-surface-50 p-3">
                    <label className="text-xs text-surface-500 col-span-2">Name<input className="input mt-1" value={d.name} onChange={e => setD({ ...d, name: e.target.value })} placeholder="Morning, Evening, Saturday…" maxLength={60} /></label>
                    <label className="text-xs text-surface-500">Starts<input type="time" className="input mt-1" value={d.starts_at} onChange={e => setD({ ...d, starts_at: e.target.value })} /></label>
                    <label className="text-xs text-surface-500">Ends<input type="time" className="input mt-1" value={d.ends_at} onChange={e => setD({ ...d, ends_at: e.target.value })} /></label>
                    <label className="text-xs text-surface-500 col-span-2">Label<input className="input mt-1" value={d.label} onChange={e => setD({ ...d, label: e.target.value })} maxLength={60} /></label>
                    <label className="inline-flex items-center gap-2 text-xs"><input type="checkbox" checked={d.here} onChange={e => setD({ ...d, here: e.target.checked })} />This outlet only</label>
                    <button type="submit" disabled={add.isPending || !d.name.trim()} className="btn-primary btn-sm justify-self-end">Add</button>
                </form>
            </div>
        </Modal>
    );
}
