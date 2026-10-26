import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { peopleApi, type Holiday } from "@/api/people";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * Public holidays (People PR 2). Kenya's fixed dates and the Easter pair are
 * seeded; Eid al-Fitr, Eid al-Adha and Diwali are gazetted each year, so a
 * keeper adds them here when the gazette is out. The roster and leave (PRs 3
 * and 4) read this table.
 */
export default function HolidaysPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const canEdit = can("hr.edit");
    const [year, setYear] = useState(new Date().getFullYear());
    const { data, isLoading } = useQuery({ queryKey: ["holidays", year], queryFn: () => peopleApi.holidays(year) });
    const [form, setForm] = useState<{ id: number | null; date: string; name: string; is_confirmed: boolean; notes: string } | null>(null);

    const done = (msg: string) => { qc.invalidateQueries({ queryKey: ["holidays"] }); setForm(null); toast.success(msg); };
    const save = useMutation({
        mutationFn: (f: NonNullable<typeof form>) => f.id
            ? peopleApi.updateHoliday(f.id, { date: f.date, name: f.name, is_confirmed: f.is_confirmed, notes: f.notes || null })
            : peopleApi.addHoliday({ date: f.date, name: f.name, is_confirmed: f.is_confirmed, notes: f.notes || null }),
        onSuccess: r => done(r.message), onError: (e: ApiError) => toast.error(e.message),
    });
    const remove = useMutation({ mutationFn: (id: number) => peopleApi.removeHoliday(id), onSuccess: r => done(r.message), onError: (e: ApiError) => toast.error(e.message) });

    const rows = data?.data ?? [];
    const edit = (h: Holiday) => setForm({ id: h.id, date: h.date, name: h.name, is_confirmed: h.is_confirmed, notes: h.notes ?? "" });
    const fmt = (iso: string) => new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "long" });

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Public holidays</h1>
                    <p className="page-subtitle">Kenya's holidays, read by the roster and leave. Eid and Diwali are gazetted each year: add them when the gazette is out.</p>
                </div>
                <div className="flex items-center gap-2 self-start">
                    <button onClick={() => setYear(y => y - 1)} className="btn-ghost btn-sm" aria-label="Previous year">←</button>
                    <span className="text-sm font-bold tabular-nums w-12 text-center">{year}</span>
                    <button onClick={() => setYear(y => y + 1)} className="btn-ghost btn-sm" aria-label="Next year">→</button>
                    {canEdit && <button onClick={() => setForm({ id: null, date: `${year}-01-01`, name: "", is_confirmed: true, notes: "" })} className="btn-primary btn-sm ml-2">Add holiday</button>}
                </div>
            </div>

            {form && canEdit && (
                <div className="card"><div className="card-body grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                    <label className="text-xs text-surface-500">Date<input type="date" className="input mt-1" value={form.date} onChange={e => setForm({ ...form, date: e.target.value })} /></label>
                    <label className="text-xs text-surface-500 sm:col-span-2">Name<input className="input mt-1" value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} maxLength={120} placeholder="Eid al-Fitr" /></label>
                    <label className="flex items-center gap-2 text-xs text-surface-600 pb-2"><input type="checkbox" checked={form.is_confirmed} onChange={e => setForm({ ...form, is_confirmed: e.target.checked })} />Gazetted / confirmed</label>
                    <label className="text-xs text-surface-500 sm:col-span-3">Notes<input className="input mt-1" value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} maxLength={255} /></label>
                    <div className="flex justify-end gap-2">
                        <button onClick={() => setForm(null)} className="btn-secondary btn-sm">Cancel</button>
                        <button onClick={() => save.mutate(form)} disabled={save.isPending || !form.date || form.name.trim().length < 2} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Save</button>
                    </div>
                </div></div>
            )}

            <div className="card overflow-hidden">
                {isLoading ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div>
                    : rows.length === 0 ? <p className="p-8 text-center text-sm text-surface-400">No holidays entered for {year}.</p>
                    : (
                        <ul className="divide-y divide-line">
                            {rows.map(h => (
                                <li key={h.id} className="flex items-center gap-3 px-4 py-3">
                                    <span className="w-40 shrink-0 text-sm tabular-nums text-surface-600">{fmt(h.date)}</span>
                                    <span className="flex-1 text-sm font-medium text-surface-900">{h.name}{h.notes ? <span className="ml-2 text-2xs text-surface-400">{h.notes}</span> : null}</span>
                                    <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", h.is_confirmed ? "bg-success-light text-success" : "bg-warning-light text-warning")}>{h.is_confirmed ? "Confirmed" : "To be gazetted"}</span>
                                    {canEdit && (
                                        <span className="flex gap-1">
                                            <button onClick={() => edit(h)} className="btn-ghost btn-sm text-xs">Edit</button>
                                            <button onClick={() => { if (window.confirm(`Remove ${h.name}?`)) remove.mutate(h.id); }} className="btn-ghost btn-sm text-xs text-surface-400 hover:text-danger">Remove</button>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
            </div>
        </div>
    );
}
