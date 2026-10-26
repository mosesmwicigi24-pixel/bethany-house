import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { timesheetsApi, hm, SHEET_TONE, SHEET_LABEL, type PayGroup } from "@/api/timesheets";
import { TimesheetDays, TimesheetTotals } from "@/components/people/TimesheetDays";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * Timesheets (People PR 5): a pay group's period, one row per person with
 * live figures until approved; a drawer with the days; approve / send back
 * (attendance.manage); lock and export (timesheets.lock); pay groups (hr.edit).
 */
const today = () => new Date().toISOString().slice(0, 10);
const shift = (iso: string, months: number) => { const d = new Date(iso + "T00:00:00"); d.setMonth(d.getMonth() + months); return d.toISOString().slice(0, 10); };
const shiftDays = (iso: string, days: number) => { const d = new Date(iso + "T00:00:00"); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10); };
const fmtD = (iso: string) => new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });

export default function TimesheetsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const [groupId, setGroupId] = useState<string>("");
    const [date, setDate] = useState(today());
    const [open, setOpen] = useState<number | null>(null);
    const [groupsOpen, setGroupsOpen] = useState(false);
    const onErr = (e: ApiError) => toast.error(e.message);

    const { data, isLoading } = useQuery({ queryKey: ["timesheets", groupId, date], queryFn: () => timesheetsApi.index({ pay_group_id: groupId || undefined, date }) });
    const { data: detail } = useQuery({ queryKey: ["timesheet", open, date], queryFn: () => timesheetsApi.show(open!, date), enabled: open !== null });
    const refresh = () => { qc.invalidateQueries({ queryKey: ["timesheets"] }); qc.invalidateQueries({ queryKey: ["timesheet"] }); };

    const approve = useMutation({ mutationFn: (uid: number) => timesheetsApi.approve(uid, date), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const sendBack = useMutation({ mutationFn: (p: { uid: number; note: string }) => timesheetsApi.sendBack(p.uid, p.note, date), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const lock = useMutation({ mutationFn: (force: boolean) => timesheetsApi.lock(data!.period.id, force), onSuccess: r => { toast.success(r.message); refresh(); },
        onError: (e: ApiError & { missing?: { name: string }[] }) => { if (e.status === 422 && window.confirm(`${e.message}\n\nLock anyway and leave them out?`)) lock.mutate(true); else toast.error(e.message); } });
    const unlock = useMutation({ mutationFn: (reason: string) => timesheetsApi.unlock(data!.period.id, reason), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const exportCsv = async () => {
        try {
            const blob = await timesheetsApi.exportCsv(data!.period.id);
            const url = URL.createObjectURL(blob); const a = document.createElement("a"); a.href = url; a.download = `timesheets-${data!.period.starts_on}.csv`; a.click(); URL.revokeObjectURL(url);
        } catch (e) { const err = e as ApiError; if (err?.message) toast.error(err.message); }
    };

    const step = (dir: 1 | -1) => setDate(d => (data?.group.frequency === "weekly" ? shiftDays(d, 7 * dir) : shift(d, dir)));
    const locked = data?.period.status === "locked";

    return (
        <div className="space-y-4 animate-fade-in">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 className="page-title">Timesheets</h1>
                    <p className="page-subtitle">Hours from the clock, leave from the calendar, overtime by the pay group's rule. Approve each person; finance locks the period.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <select className="input w-44" value={groupId} onChange={e => setGroupId(e.target.value)} aria-label="Pay group">
                        {(data?.groups ?? []).map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
                    </select>
                    <div className="flex items-center gap-1">
                        <button onClick={() => step(-1)} className="btn-ghost btn-sm" aria-label="Previous period">←</button>
                        <button onClick={() => setDate(today())} className="btn-ghost btn-sm text-xs">Current</button>
                        <button onClick={() => step(1)} className="btn-ghost btn-sm" aria-label="Next period">→</button>
                    </div>
                    {can("hr.edit") && <button onClick={() => setGroupsOpen(true)} className="btn-secondary btn-sm">Pay groups</button>}
                    {data?.can_lock && !locked && <button onClick={() => lock.mutate(false)} disabled={lock.isPending} className="btn-primary btn-sm">Lock period</button>}
                    {data?.can_lock && locked && <button onClick={() => void exportCsv()} className="btn-primary btn-sm">Export CSV</button>}
                    {locked && can("settings.edit") && <button onClick={() => { const r = window.prompt("Why unlock this period? (recorded)"); if (r && r.length >= 5) unlock.mutate(r); }} className="btn-ghost btn-sm text-xs">Unlock</button>}
                </div>
            </div>

            {isLoading || !data ? <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div> : (
                <>
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <span className="font-semibold text-surface-800">{fmtD(data.period.starts_on)} – {fmtD(data.period.ends_on)}</span>
                        <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", locked ? "bg-success-light text-success" : "bg-surface-100 text-surface-600")}>{locked ? "Locked" : "Open"}</span>
                        <span className="text-xs text-surface-500">{data.summary.approved} of {data.summary.people} approved · {data.summary.submitted} submitted{data.summary.flagged ? ` · ${data.summary.flagged} flagged punch(es)` : ""}</span>
                    </div>
                    <div className="card overflow-x-auto">
                        <table className="w-full text-sm min-w-[820px]">
                            <thead><tr className="border-b border-line text-2xs font-semibold uppercase tracking-wider text-surface-400">
                                <th className="text-left px-4 py-2">Person</th><th className="text-right px-2 py-2">Regular</th><th className="text-right px-2 py-2">Overtime</th><th className="text-right px-2 py-2">Rest / holiday</th><th className="text-right px-2 py-2">Leave</th><th className="text-right px-2 py-2">Days</th><th className="text-left px-2 py-2">Status</th><th />
                            </tr></thead>
                            <tbody className="divide-y divide-line">
                                {data.rows.map(r => (
                                    <tr key={r.user.id} className="hover:bg-surface-50 cursor-pointer" onClick={() => setOpen(r.user.id)}>
                                        <td className="px-4 py-2"><p className="font-semibold text-surface-900">{r.user.name}</p><p className="text-2xs text-surface-400">{r.user.job_title ?? ""}</p></td>
                                        <td className="px-2 py-2 text-right tabular-nums">{hm(r.totals.regular_minutes)}</td>
                                        <td className={clsx("px-2 py-2 text-right tabular-nums", r.totals.overtime_minutes && "text-brand-700 font-semibold")}>{hm(r.totals.overtime_minutes)}</td>
                                        <td className="px-2 py-2 text-right tabular-nums">{hm(r.totals.rest_day_minutes + r.totals.holiday_minutes)}</td>
                                        <td className="px-2 py-2 text-right tabular-nums">{r.totals.paid_leave_days + r.totals.unpaid_leave_days ? `${r.totals.paid_leave_days + r.totals.unpaid_leave_days} d` : "—"}</td>
                                        <td className="px-2 py-2 text-right tabular-nums">{r.totals.days_worked}</td>
                                        <td className="px-2 py-2">
                                            <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", SHEET_TONE[r.status])}>{SHEET_LABEL[r.status]}</span>
                                            {r.flags > 0 && <span className="ml-1 text-2xs font-semibold px-1.5 py-0.5 rounded-full bg-danger-light text-danger">{r.flags} flag{r.flags === 1 ? "" : "s"}</span>}
                                            {r.absences > 0 && <span className="ml-1 text-2xs font-semibold px-1.5 py-0.5 rounded-full bg-warning-light text-warning">{r.absences} absent</span>}
                                        </td>
                                        <td className="px-2 py-2 text-right" onClick={e => e.stopPropagation()}>
                                            {data.can_manage && !locked && r.status !== "approved" && (
                                                <button onClick={() => approve.mutate(r.user.id)} disabled={approve.isPending || r.flags > 0} className="btn-primary btn-sm" title={r.flags > 0 ? "Resolve the flagged punches first" : ""}>Approve</button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {data.rows.length === 0 && <tr><td colSpan={8} className="p-8 text-center text-sm text-surface-400">Nobody in this pay group at your outlets.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </>
            )}

            <Modal open={open !== null} onClose={() => setOpen(null)} title={detail ? `${detail.person.name} · ${fmtD(detail.period.starts_on)} – ${fmtD(detail.period.ends_on)}` : "Timesheet"} size="lg"
                footer={detail && (
                    <>
                        <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full mr-auto", SHEET_TONE[detail.status])}>{SHEET_LABEL[detail.status]}{detail.approved_by ? ` by ${detail.approved_by}` : ""}</span>
                        {detail.can_manage && !locked && detail.status !== "approved" && <>
                            <button onClick={() => { const n = window.prompt("What should they check?"); if (n && n.length >= 3) sendBack.mutate({ uid: detail.person.id, note: n }); }} className="btn-secondary btn-sm">Send back</button>
                            <button onClick={() => approve.mutate(detail.person.id)} disabled={detail.flags.length > 0 || approve.isPending} className="btn-primary btn-sm">Approve</button>
                        </>}
                        {detail.can_manage && !locked && detail.status === "approved" && <button onClick={() => { const n = window.prompt("Why reopen it?"); if (n && n.length >= 3) sendBack.mutate({ uid: detail.person.id, note: n }); }} className="btn-secondary btn-sm">Reopen</button>}
                        <button onClick={() => setOpen(null)} className="btn-secondary btn-sm">Close</button>
                    </>
                )}>
                {!detail ? <div className="flex items-center justify-center h-32"><Spinner size="lg" /></div> : (
                    <div className="space-y-4">
                        {detail.note && <p className="text-sm text-surface-600 italic">“{detail.note}” — {detail.person.name}</p>}
                        {detail.sent_back_note && detail.status === "sent_back" && <p className="text-sm text-warning">Sent back: {detail.sent_back_note}</p>}
                        <TimesheetTotals t={detail.totals} />
                        <TimesheetDays d={detail} />
                    </div>
                )}
            </Modal>

            {groupsOpen && <PayGroupsModal onClose={() => setGroupsOpen(false)} />}
        </div>
    );
}

function PayGroupsModal({ onClose }: { onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data } = useQuery({ queryKey: ["pay-groups"], queryFn: () => timesheetsApi.groups() });
    const [edit, setEdit] = useState<Partial<PayGroup> & { name: string; frequency: "monthly" | "weekly" } | null>(null);
    const save = useMutation({
        mutationFn: (g: NonNullable<typeof edit>) => g.id ? timesheetsApi.updateGroup(g.id, g) : timesheetsApi.createGroup(g),
        onSuccess: r => { toast.success(r.message); setEdit(null); qc.invalidateQueries({ queryKey: ["pay-groups"] }); qc.invalidateQueries({ queryKey: ["timesheets"] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const num = (k: keyof PayGroup, nullable = false) => (e: React.ChangeEvent<HTMLInputElement>) => setEdit(g => g ? { ...g, [k]: e.target.value === "" ? (nullable ? null : undefined) : Number(e.target.value) } : g);
    return (
        <Modal open onClose={onClose} title="Pay groups" size="lg" footer={<button onClick={onClose} className="btn-secondary btn-sm">Close</button>}>
            <div className="space-y-3 text-sm">
                <p className="text-xs text-surface-500">Who is paid on which rhythm and under which overtime rule. The multipliers are the rates payroll will use; here they label the minutes. The default group applies to anyone whose file names none.</p>
                <ul className="divide-y divide-line rounded-xl border border-line">
                    {(data?.data ?? []).map(g => (
                        <li key={g.id} className="flex items-center gap-3 px-3 py-2">
                            <span className="flex-1"><b>{g.name}</b>{g.is_default ? <span className="ml-2 text-2xs text-brand-600 font-semibold">default</span> : null}{!g.is_active ? <span className="ml-2 text-2xs text-surface-400">off</span> : null}
                                <span className="block text-2xs text-surface-500">{g.frequency} · OT ×{g.overtime_multiplier} {g.overtime_after_daily_hours ? `after ${g.overtime_after_daily_hours} h/day` : ""}{g.overtime_after_weekly_hours ? ` after ${g.overtime_after_weekly_hours} h/week` : ""} · rest ×{g.rest_day_multiplier} · holiday ×{g.holiday_multiplier}{g.round_to_minutes > 1 ? ` · rounds to ${g.round_to_minutes} min` : ""}</span></span>
                            <button onClick={() => setEdit({ ...g })} className="btn-ghost btn-sm text-xs">Edit</button>
                        </li>
                    ))}
                </ul>
                {!edit && <button onClick={() => setEdit({ name: "", frequency: "monthly", standard_hours_per_day: 8, overtime_after_daily_hours: 8, overtime_after_weekly_hours: null, overtime_multiplier: 1.5, rest_day_multiplier: 2, holiday_multiplier: 2, round_to_minutes: 1, is_default: false, is_active: true })} className="btn-secondary btn-sm">Add pay group</button>}
                {edit && (
                    <div className="rounded-xl border border-line bg-surface-50 p-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <label className="text-xs text-surface-500 col-span-2">Name<input className="input mt-1" value={edit.name} onChange={e => setEdit({ ...edit, name: e.target.value })} maxLength={60} /></label>
                        <label className="text-xs text-surface-500">Paid<select className="input mt-1" value={edit.frequency} onChange={e => setEdit({ ...edit, frequency: e.target.value as "monthly" | "weekly" })}><option value="monthly">Monthly</option><option value="weekly">Weekly</option></select></label>
                        <label className="text-xs text-surface-500">OT after h/day<input type="number" step="0.5" className="input mt-1" value={edit.overtime_after_daily_hours ?? ""} onChange={num("overtime_after_daily_hours", true)} /></label>
                        <label className="text-xs text-surface-500">OT after h/week<input type="number" step="0.5" className="input mt-1" value={edit.overtime_after_weekly_hours ?? ""} onChange={num("overtime_after_weekly_hours", true)} /></label>
                        <label className="text-xs text-surface-500">Round punches to (min)<select className="input mt-1" value={edit.round_to_minutes ?? 1} onChange={e => setEdit({ ...edit, round_to_minutes: Number(e.target.value) })}>{[1, 5, 10, 15, 30].map(n => <option key={n} value={n}>{n === 1 ? "No rounding" : n}</option>)}</select></label>
                        <label className="text-xs text-surface-500">Overtime ×<input type="number" step="0.25" className="input mt-1" value={edit.overtime_multiplier ?? ""} onChange={num("overtime_multiplier")} /></label>
                        <label className="text-xs text-surface-500">Rest day ×<input type="number" step="0.25" className="input mt-1" value={edit.rest_day_multiplier ?? ""} onChange={num("rest_day_multiplier")} /></label>
                        <label className="text-xs text-surface-500">Holiday ×<input type="number" step="0.25" className="input mt-1" value={edit.holiday_multiplier ?? ""} onChange={num("holiday_multiplier")} /></label>
                        <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={!!edit.is_default} onChange={e => setEdit({ ...edit, is_default: e.target.checked })} />Default group</label>
                        <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={edit.is_active !== false} onChange={e => setEdit({ ...edit, is_active: e.target.checked })} />In use</label>
                        <div className="flex justify-end gap-2 col-span-2 sm:col-span-1">
                            <button onClick={() => setEdit(null)} className="btn-secondary btn-sm">Cancel</button>
                            <button onClick={() => save.mutate(edit)} disabled={save.isPending || edit.name.trim().length < 2} className="btn-primary btn-sm">Save</button>
                        </div>
                    </div>
                )}
            </div>
        </Modal>
    );
}
