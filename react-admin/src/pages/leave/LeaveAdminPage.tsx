import { useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { leaveApi, STATUS_TONE, fmtSpan, type LeaveRequest, type LeaveType } from "@/api/leave";
import { peopleApi } from "@/api/people";
import { usePermissions } from "@/hooks/usePermissions";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * Leave, team side (People PR 4): requests waiting, who is off this month,
 * and the policy. Read with attendance.view_team; each decision is checked
 * per person on the server (their manager, or leave.approve at their outlet).
 */
type Tab = "requests" | "calendar" | "policy";

function monthIso(d: Date) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`; }

export default function LeaveAdminPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const [tab, setTab] = useState<Tab>("requests");
    const [status, setStatus] = useState("pending");
    const [month, setMonth] = useState(monthIso(new Date()));
    const onErr = (e: ApiError) => toast.error(e.message);
    const refresh = () => { qc.invalidateQueries({ queryKey: ["leave-requests"] }); qc.invalidateQueries({ queryKey: ["leave-calendar"] }); };

    const { data: reqs, isLoading } = useQuery({ queryKey: ["leave-requests", status], queryFn: () => leaveApi.requests({ status }) });
    const from = `${month}-01`;
    const to = new Date(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0).toISOString().slice(0, 10);
    const { data: cal } = useQuery({ queryKey: ["leave-calendar", month], queryFn: () => leaveApi.calendar(from, to), enabled: tab === "calendar" });
    const { data: types } = useQuery({ queryKey: ["leave-types"], queryFn: () => leaveApi.types() });
    const { data: people } = useQuery({ queryKey: ["people", "", "", 1], queryFn: () => peopleApi.list({ per_page: "100" }), enabled: tab === "requests" });

    const approve = useMutation({ mutationFn: (p: { id: number; note?: string }) => leaveApi.approve(p.id, p.note),
        onSuccess: r => { toast.success(r.message + (r.roster_clash?.length ? ` ${r.roster_clash.length} rostered shift(s) in that span need re-rostering.` : "")); refresh(); }, onError: onErr });
    const decline = useMutation({ mutationFn: (p: { id: number; note?: string }) => leaveApi.decline(p.id, p.note), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const cancel = useMutation({ mutationFn: (id: number) => leaveApi.cancel(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });

    const [recording, setRecording] = useState(false);
    const [rec, setRec] = useState({ user_id: 0, leave_type_id: 0, starts_on: "", ends_on: "", reason: "" });
    const record = useMutation({ mutationFn: () => leaveApi.record({ ...rec, reason: rec.reason || undefined }), onSuccess: r => { toast.success(r.message); setRecording(false); refresh(); }, onError: onErr });

    const [editType, setEditType] = useState<LeaveType | null>(null);
    const saveType = useMutation({ mutationFn: (t: LeaveType) => leaveApi.updateType(t.id, { name: t.name, days_per_year: t.days_per_year, accrual: t.accrual, is_paid: t.is_paid, counts_calendar_days: t.counts_calendar_days, evidence_after_days: t.evidence_after_days, is_active: t.is_active }),
        onSuccess: r => { toast.success(r.message); setEditType(null); qc.invalidateQueries({ queryKey: ["leave-types"] }); }, onError: onErr });

    const askNote = (verb: string) => { const n = window.prompt(`${verb} with a note? (optional)`); return n === null ? undefined : (n || undefined); };

    return (
        <div className="space-y-4 animate-fade-in">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Leave</h1>
                    <p className="page-subtitle">Requests from your people, who is off, and the policy. A person's own manager decides; nobody decides their own.</p>
                </div>
                <button onClick={() => setRecording(true)} className="btn-secondary btn-sm self-start">Record leave for someone</button>
            </div>

            <div className="flex gap-1 border-b border-line">
                {(["requests", "calendar", "policy"] as Tab[]).map(t => (
                    <button key={t} onClick={() => setTab(t)} className={clsx("px-3 py-2 text-sm font-medium capitalize border-b-2 -mb-px", tab === t ? "border-brand-500 text-brand-700" : "border-transparent text-surface-500")}>{t}</button>
                ))}
            </div>

            {tab === "requests" && (
                <div className="space-y-3">
                    <select className="input w-44" value={status} onChange={e => setStatus(e.target.value)} aria-label="Status">
                        <option value="pending">Waiting</option><option value="approved">Approved</option><option value="declined">Declined</option><option value="cancelled">Cancelled</option>
                    </select>
                    <div className="card overflow-hidden">
                        {isLoading ? <div className="flex items-center justify-center h-32"><Spinner size="lg" /></div>
                            : !reqs?.data.length ? <p className="p-8 text-center text-sm text-surface-400">Nothing {status}.</p>
                            : (
                                <ul className="divide-y divide-line">
                                    {reqs.data.map(r => (
                                        <li key={r.id} className="px-4 py-3 flex flex-col sm:flex-row sm:items-center gap-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-surface-900">
                                                    <Link to={`/people/${r.user?.id}`} className="hover:underline">{r.user?.name}</Link> · {r.type?.name} · {fmtSpan(r)} <span className="font-normal text-surface-400">· {r.days} day{r.days === 1 ? "" : "s"}</span>
                                                </p>
                                                {r.reason && <p className="text-xs text-surface-500">“{r.reason}”</p>}
                                                {!!r.roster_clash?.length && <p className="text-xs text-warning">Rostered {r.roster_clash.map(s => `${s.date} ${s.starts_at}–${s.ends_at}`).join(", ")} inside this leave.</p>}
                                                {r.decided_by && <p className="text-2xs text-surface-400">{r.status} by {r.decided_by}{r.decision_note ? ` — ${r.decision_note}` : ""}</p>}
                                            </div>
                                            <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full self-start", STATUS_TONE[r.status])}>{r.status}</span>
                                            {r.status === "pending" && r.can_decide && (
                                                <div className="flex gap-2">
                                                    <button onClick={() => decline.mutate({ id: r.id, note: askNote("Decline") })} className="btn-secondary btn-sm">Decline</button>
                                                    <button onClick={() => approve.mutate({ id: r.id })} className="btn-primary btn-sm">Approve</button>
                                                </div>
                                            )}
                                            {r.status === "approved" && r.can_decide !== false && (
                                                <button onClick={() => { if (window.confirm("Cancel this approved leave? The days return to the balance.")) cancel.mutate(r.id); }} className="btn-ghost btn-sm text-xs text-surface-400 hover:text-danger">Cancel</button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                    </div>
                </div>
            )}

            {tab === "calendar" && (
                <div className="space-y-3">
                    <div className="flex items-center gap-2">
                        <input type="month" className="input w-44" value={month} onChange={e => setMonth(e.target.value)} aria-label="Month" />
                    </div>
                    <div className="card overflow-hidden">
                        {!cal ? <div className="flex items-center justify-center h-32"><Spinner size="lg" /></div>
                            : cal.data.length === 0 ? <p className="p-8 text-center text-sm text-surface-400">Nobody is off this month.</p>
                            : (
                                <ul className="divide-y divide-line">
                                    {cal.data.map(r => (
                                        <li key={r.id} className="px-4 py-3 flex items-center gap-3">
                                            <span className="w-44 shrink-0 text-sm tabular-nums text-surface-600">{fmtSpan(r)}</span>
                                            <span className="flex-1 text-sm text-surface-900"><b>{r.user?.name}</b> · {r.type?.name} · {r.days} day{r.days === 1 ? "" : "s"}</span>
                                            <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", STATUS_TONE[r.status])}>{r.status}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                    </div>
                </div>
            )}

            {tab === "policy" && (
                <div className="card overflow-hidden">
                    <table className="w-full text-sm">
                        <thead><tr className="border-b border-line text-2xs font-semibold uppercase tracking-wider text-surface-400">
                            <th className="text-left px-4 py-2">Type</th><th className="text-right px-2 py-2">Days / year</th><th className="text-left px-2 py-2">Accrual</th><th className="text-left px-2 py-2">Counts</th><th className="text-left px-2 py-2">Paid</th><th className="text-left px-2 py-2">Evidence after</th><th />
                        </tr></thead>
                        <tbody className="divide-y divide-line">
                            {(types?.data ?? []).map(t => (
                                <tr key={t.id} className={clsx(!t.is_active && "opacity-50")}>
                                    <td className="px-4 py-2 font-medium text-surface-900">{t.name}{!t.is_active && <span className="ml-2 text-2xs text-surface-400">off</span>}</td>
                                    <td className="px-2 py-2 text-right tabular-nums">{t.days_per_year ?? "—"}</td>
                                    <td className="px-2 py-2">{t.days_per_year === null ? "—" : t.accrual === "monthly" ? "A twelfth a month" : "1 January"}</td>
                                    <td className="px-2 py-2">{t.counts_calendar_days ? "Calendar days" : "Working days"}</td>
                                    <td className="px-2 py-2">{t.is_paid ? "Yes" : "No"}</td>
                                    <td className="px-2 py-2">{t.evidence_after_days !== null ? `${t.evidence_after_days} days` : "—"}</td>
                                    <td className="px-2 py-2 text-right">{can("hr.edit") && <button onClick={() => setEditType({ ...t })} className="btn-ghost btn-sm text-xs">Edit</button>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="px-4 py-3 text-2xs text-surface-400">Seeded at the Employment Act floor: 21 working days annual (earned monthly), 14 sick, 90 calendar days maternity, 14 paternity, 5 compassionate, unpaid untracked. No carry-over unless added as an adjustment on the person's file.</p>
                </div>
            )}

            <Modal open={recording} onClose={() => setRecording(false)} title="Record leave for someone" size="sm"
                footer={<><button onClick={() => setRecording(false)} className="btn-secondary btn-sm">Cancel</button><button onClick={() => record.mutate()} disabled={record.isPending || !rec.user_id || !rec.leave_type_id || !rec.starts_on || !rec.ends_on} className="btn-primary btn-sm">Record as approved</button></>}>
                <div className="space-y-3 text-sm">
                    <p className="text-xs text-surface-500">For sick leave someone phoned in, or leave agreed in person. Approved at once; they are told.</p>
                    <select className="input" value={rec.user_id} onChange={e => setRec({ ...rec, user_id: Number(e.target.value) })} aria-label="Person">
                        <option value={0}>Who?</option>{(people?.data ?? []).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                    <select className="input" value={rec.leave_type_id} onChange={e => setRec({ ...rec, leave_type_id: Number(e.target.value) })} aria-label="Type">
                        <option value={0}>Type</option>{(types?.data ?? []).filter(t => t.is_active).map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                    <div className="grid grid-cols-2 gap-2">
                        <input type="date" className="input" value={rec.starts_on} onChange={e => setRec({ ...rec, starts_on: e.target.value, ends_on: rec.ends_on || e.target.value })} aria-label="From" />
                        <input type="date" className="input" value={rec.ends_on} min={rec.starts_on} onChange={e => setRec({ ...rec, ends_on: e.target.value })} aria-label="To" />
                    </div>
                    <input className="input" value={rec.reason} onChange={e => setRec({ ...rec, reason: e.target.value })} placeholder="Reason (optional)" maxLength={500} />
                </div>
            </Modal>

            <Modal open={!!editType} onClose={() => setEditType(null)} title={`Edit ${editType?.name ?? ""}`} size="sm"
                footer={<><button onClick={() => setEditType(null)} className="btn-secondary btn-sm">Cancel</button><button onClick={() => editType && saveType.mutate(editType)} disabled={saveType.isPending} className="btn-primary btn-sm">Save</button></>}>
                {editType && (
                    <div className="space-y-3 text-sm">
                        <label className="text-xs text-surface-500 block">Name<input className="input mt-1" value={editType.name} onChange={e => setEditType({ ...editType, name: e.target.value })} maxLength={60} /></label>
                        <label className="text-xs text-surface-500 block">Days per year (blank = not tracked)<input type="number" step="0.5" className="input mt-1" value={editType.days_per_year ?? ""} onChange={e => setEditType({ ...editType, days_per_year: e.target.value === "" ? null : Number(e.target.value) })} /></label>
                        <label className="text-xs text-surface-500 block">Accrual
                            <select className="input mt-1" value={editType.accrual} onChange={e => setEditType({ ...editType, accrual: e.target.value as LeaveType["accrual"] })}><option value="monthly">A twelfth each month</option><option value="front">All on 1 January</option></select>
                        </label>
                        <label className="text-xs text-surface-500 block">Evidence after (days, blank = never)<input type="number" className="input mt-1" value={editType.evidence_after_days ?? ""} onChange={e => setEditType({ ...editType, evidence_after_days: e.target.value === "" ? null : Number(e.target.value) })} /></label>
                        <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={editType.counts_calendar_days} onChange={e => setEditType({ ...editType, counts_calendar_days: e.target.checked })} />Counts calendar days (weekends and holidays included)</label>
                        <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={editType.is_paid} onChange={e => setEditType({ ...editType, is_paid: e.target.checked })} />Paid</label>
                        <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={editType.is_active} onChange={e => setEditType({ ...editType, is_active: e.target.checked })} />In use</label>
                        <p className="text-2xs text-surface-400">Changes apply to balances from now on; approved leave is never recalculated.</p>
                    </div>
                )}
            </Modal>
        </div>
    );
}
