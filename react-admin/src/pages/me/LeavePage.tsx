import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { leaveApi, STATUS_TONE, fmtSpan } from "@/api/leave";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** My leave (People PR 4): balances, a request, and what happened to it. Phone first. */
export default function LeavePage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["my-leave"], queryFn: () => leaveApi.mine() });
    const refresh = () => qc.invalidateQueries({ queryKey: ["my-leave"] });
    const onErr = (e: ApiError) => toast.error(e.message);

    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({ leave_type_id: 0, starts_on: "", ends_on: "", reason: "" });
    useEffect(() => { if (data && !form.leave_type_id && data.types[0]) setForm(f => ({ ...f, leave_type_id: data.types[0].id })); }, [data, form.leave_type_id]);
    const canPreview = form.leave_type_id > 0 && form.starts_on && form.ends_on && form.ends_on >= form.starts_on;
    const { data: preview } = useQuery({
        queryKey: ["leave-preview", form.leave_type_id, form.starts_on, form.ends_on],
        queryFn: () => leaveApi.preview(form.leave_type_id, form.starts_on, form.ends_on),
        enabled: !!canPreview,
    });

    const ask = useMutation({
        mutationFn: () => leaveApi.request({ leave_type_id: form.leave_type_id, starts_on: form.starts_on, ends_on: form.ends_on, reason: form.reason || undefined }),
        onSuccess: r => { toast.success(r.message); setOpen(false); setForm(f => ({ ...f, starts_on: "", ends_on: "", reason: "" })); refresh(); },
        onError: onErr,
    });
    const cancel = useMutation({ mutationFn: (id: number) => leaveApi.cancelMine(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });

    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    const tracked = data.balances.filter(b => b.available !== null);

    return (
        <div className="mx-auto w-full max-w-lg px-4 py-4 sm:py-8 space-y-4">
            <div className="flex items-end justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-surface-400">{data.year}</p>
                    <h1 className="text-xl font-bold text-surface-900">My leave</h1>
                </div>
                <button onClick={() => setOpen(true)} className="btn-primary btn-sm">Request leave</button>
            </div>

            <div className="grid grid-cols-2 gap-2">
                {tracked.map(b => (
                    <div key={b.type.id} className="rounded-2xl bg-white border border-line shadow-sm px-3 py-3">
                        <p className="text-2xs font-semibold uppercase tracking-wider text-surface-400 truncate">{b.type.name}</p>
                        <p className="text-2xl font-extrabold text-surface-900 tabular-nums leading-tight">{b.available}<span className="text-xs font-medium text-surface-400"> / {b.entitled + b.adjusted}</span></p>
                        <p className="text-2xs text-surface-500">{b.used} used{b.requested ? ` · ${b.requested} asked` : ""}{b.type.accrual === "monthly" ? " · earns monthly" : ""}</p>
                    </div>
                ))}
            </div>
            <p className="text-2xs text-surface-400 px-1">
                {data.approvers.length ? `Decided by ${data.approvers.join(" or ")}.` : "Nobody is set to approve your leave yet; ask the owner to set who you report to."} Days are working days; public holidays do not count.
            </p>

            <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-surface-400 px-1 mb-2">Requests</p>
                {data.requests.length === 0 ? <p className="text-sm text-surface-400 px-1">None yet.</p> : (
                    <ul className="rounded-2xl bg-white border border-line shadow-sm divide-y divide-line">
                        {data.requests.map(r => (
                            <li key={r.id} className="px-4 py-3 flex items-start gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-surface-900">{fmtSpan(r)} <span className="font-normal text-surface-400">· {r.days} day{r.days === 1 ? "" : "s"}</span></p>
                                    <p className="text-xs text-surface-500 truncate">{r.type?.name}{r.reason ? ` · ${r.reason}` : ""}</p>
                                    {r.decision_note && <p className="text-xs text-surface-500 italic">“{r.decision_note}”{r.decided_by ? ` — ${r.decided_by}` : ""}</p>}
                                </div>
                                <div className="flex flex-col items-end gap-1">
                                    <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", STATUS_TONE[r.status])}>{r.status}</span>
                                    {(r.status === "pending" || (r.status === "approved" && r.starts_on > new Date().toISOString().slice(0, 10))) && (
                                        <button onClick={() => { if (window.confirm("Cancel this request?")) cancel.mutate(r.id); }} className="text-2xs text-surface-400 underline">Cancel</button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <Link to="/me/clock" className="block text-center text-xs text-brand-600 underline">Back to the clock</Link>

            <Modal open={open} onClose={() => setOpen(false)} title="Request leave" size="sm"
                footer={<><button onClick={() => setOpen(false)} className="btn-secondary btn-sm">Cancel</button>
                    <button onClick={() => ask.mutate()} disabled={ask.isPending || !canPreview || (preview ? !preview.enough || preview.days <= 0 : true)} className="btn-primary btn-sm">{ask.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Send</button></>}>
                <div className="space-y-3 text-sm">
                    <label className="text-xs text-surface-500 block">Type
                        <select className="input mt-1" value={form.leave_type_id} onChange={e => setForm({ ...form, leave_type_id: Number(e.target.value) })}>
                            {data.types.map(t => <option key={t.id} value={t.id}>{t.name}{t.is_paid ? "" : " (unpaid)"}</option>)}
                        </select>
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                        <label className="text-xs text-surface-500 block">From<input type="date" className="input mt-1" value={form.starts_on} onChange={e => setForm({ ...form, starts_on: e.target.value, ends_on: form.ends_on || e.target.value })} /></label>
                        <label className="text-xs text-surface-500 block">To<input type="date" className="input mt-1" value={form.ends_on} min={form.starts_on} onChange={e => setForm({ ...form, ends_on: e.target.value })} /></label>
                    </div>
                    <label className="text-xs text-surface-500 block">Reason (optional)<input className="input mt-1" value={form.reason} onChange={e => setForm({ ...form, reason: e.target.value })} maxLength={500} /></label>
                    {preview && (
                        <div className={clsx("rounded-xl px-3 py-2 text-xs", preview.enough && preview.days > 0 ? "bg-success-light text-success" : "bg-danger-light text-danger")}>
                            {preview.days <= 0 ? "Those dates contain no working days." : <>
                                <b>{preview.days} day{preview.days === 1 ? "" : "s"}</b>{preview.holidays_skipped.length ? ` (skipping ${preview.holidays_skipped.join(", ")})` : ""}
                                {preview.available !== null && <> · {preview.available} available{!preview.enough ? " — not enough" : ""}</>}
                            </>}
                        </div>
                    )}
                </div>
            </Modal>
        </div>
    );
}
