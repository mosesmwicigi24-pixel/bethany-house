import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { advancesApi, kes, saveBlob, ADVANCE_TONE, ADVANCE_LABEL, RETURN_LABEL, type Advance, type ReturnKind } from "@/api/payroll";
import { peopleApi } from "@/api/people";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

type Method = "mpesa" | "bank" | "cash";

/** Payroll › Advances (People PR 7): decide requests, record payouts and cash repayments, see open balances. */
export function AdvancesPanel() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [status, setStatus] = useState("");
    const { data, isLoading } = useQuery({ queryKey: ["advances", status], queryFn: () => advancesApi.list(status || undefined) });
    const [act, setAct] = useState<{ a: Advance; what: "approve" | "decline" | "payout" | "repay" } | null>(null);
    const [form, setForm] = useState<{ note: string; instalment: string; method: Method; reference: string; amount: string }>({ note: "", instalment: "", method: "mpesa", reference: "", amount: "" });
    const [adding, setAdding] = useState(false);
    const [newForm, setNewForm] = useState<{ user_id: string; kind: "advance" | "loan"; amount: string; instalment: string; reason: string }>({ user_id: "", kind: "advance", amount: "", instalment: "", reason: "" });
    // The people list allows at most 100 a page (PeopleController); asking for
    // 200 was refused and the Person dropdown stayed empty (owner, 2026-10-27).
    const { data: people, isLoading: peopleLoading, isError: peopleFailed } = useQuery({ queryKey: ["people", "all-for-advances"], queryFn: () => peopleApi.list({ per_page: "100" }), enabled: adding });
    const refresh = () => void qc.invalidateQueries({ queryKey: ["advances"] });
    const onErr = (e: ApiError) => toast.error(e.message);
    const done = (r: { message: string }) => { toast.success(r.message); setAct(null); setAdding(false); refresh(); };
    const approve = useMutation({ mutationFn: (p: { id: number; instalment?: number; note?: string }) => advancesApi.approve(p.id, { instalment: p.instalment, note: p.note }), onSuccess: done, onError: onErr });
    const decline = useMutation({ mutationFn: (p: { id: number; note: string }) => advancesApi.decline(p.id, p.note), onSuccess: done, onError: onErr });
    const payout = useMutation({ mutationFn: (p: { id: number; method: Method; reference: string }) => advancesApi.payout(p.id, { payment_method: p.method, payment_reference: p.reference || undefined }), onSuccess: done, onError: onErr });
    const repay = useMutation({ mutationFn: (p: { id: number; amount: number; reference: string }) => advancesApi.repay(p.id, { amount: p.amount, reference: p.reference || undefined }), onSuccess: done, onError: onErr });
    const create = useMutation({ mutationFn: () => advancesApi.create({ user_id: Number(newForm.user_id), kind: newForm.kind, amount: Number(newForm.amount), instalment: newForm.kind === "loan" && newForm.instalment ? Number(newForm.instalment) : undefined, reason: newForm.reason }), onSuccess: done, onError: onErr });
    const fmtD = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "—");
    const open = (a: Advance, what: "approve" | "decline" | "payout" | "repay") => { setForm({ note: "", instalment: String(a.instalment), method: "mpesa", reference: "", amount: String(a.balance) }); setAct({ a, what }); };

    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-surface-600">Open balances to recover through payroll: <b className="tabular-nums">{kes(data.open_balance)}</b>. An advance is cash out and a receivable from the person, not an expense; the wage it draws on is expensed by the run that earns it.</p>
                <div className="flex items-center gap-2">
                    <select className="input input-sm" value={status} onChange={e => setStatus(e.target.value)}>
                        <option value="">All</option><option value="requested">Waiting</option><option value="approved">Approved, not paid</option><option value="paid">Paid out, repaying</option><option value="settled">Settled</option><option value="declined">Declined</option>
                    </select>
                    {data.can_run && <button onClick={() => setAdding(true)} className="btn-primary btn-sm">Record one for a person</button>}
                </div>
            </div>
            <div className="card">
                {data.data.length === 0 ? <p className="p-6 text-sm text-surface-500 text-center">Nothing here.</p> : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead><tr className="text-2xs uppercase tracking-wider text-surface-400 text-left"><th className="px-4 py-2">Person</th><th className="px-4 py-2">Kind</th><th className="px-4 py-2">Why</th><th className="px-4 py-2 text-right">Amount</th><th className="px-4 py-2 text-right">Per run</th><th className="px-4 py-2 text-right">Balance</th><th className="px-4 py-2">Status</th><th className="px-4 py-2"></th></tr></thead>
                            <tbody>
                                {data.data.map(a => (
                                    <tr key={a.id} className="border-t border-line">
                                        <td className="px-4 py-2 font-medium text-surface-900">{a.user?.name ?? `#${a.user?.id}`}<span className="block text-2xs text-surface-400">asked {fmtD(a.requested_at)}{a.decided_by ? ` · ${a.status === "declined" ? "declined" : "approved"} by ${a.decided_by}` : ""}</span></td>
                                        <td className="px-4 py-2">{a.kind === "loan" ? "Loan" : "Advance"}</td>
                                        <td className="px-4 py-2 text-surface-600 max-w-[16rem] truncate" title={a.reason ?? ""}>{a.reason}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{kes(a.amount)}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{kes(a.instalment)}</td>
                                        <td className="px-4 py-2 text-right tabular-nums font-semibold">{a.status === "paid" || a.status === "settled" ? kes(a.balance) : "—"}</td>
                                        <td className="px-4 py-2"><span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", ADVANCE_TONE[a.status])}>{ADVANCE_LABEL[a.status]}</span>{a.paid_at && <span className="block text-2xs text-surface-400">paid {fmtD(a.paid_at)} by {a.payment_method}</span>}</td>
                                        <td className="px-4 py-2 text-right whitespace-nowrap">
                                            {data.can_run && a.status === "requested" && <><button onClick={() => open(a, "approve")} className="btn-primary btn-sm">Approve</button> <button onClick={() => open(a, "decline")} className="btn-ghost btn-sm text-danger">Decline</button></>}
                                            {data.can_run && a.status === "approved" && <button onClick={() => open(a, "payout")} className="btn-primary btn-sm">Record payout</button>}
                                            {data.can_run && a.status === "paid" && <button onClick={() => open(a, "repay")} className="btn-secondary btn-sm">Cash repayment</button>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {act && (
                <Modal open onClose={() => setAct(null)} size="sm" title={`${{ approve: "Approve", decline: "Decline", payout: "Record payout", repay: "Cash repayment" }[act.what]} · ${act.a.user?.name ?? ""}`}
                    footer={<button className="btn-primary btn-sm" disabled={approve.isPending || decline.isPending || payout.isPending || repay.isPending} onClick={() => {
                        if (act.what === "approve") approve.mutate({ id: act.a.id, instalment: act.a.kind === "loan" ? Number(form.instalment) : undefined, note: form.note || undefined });
                        if (act.what === "decline") decline.mutate({ id: act.a.id, note: form.note });
                        if (act.what === "payout") payout.mutate({ id: act.a.id, method: form.method, reference: form.reference });
                        if (act.what === "repay") repay.mutate({ id: act.a.id, amount: Number(form.amount), reference: form.reference });
                    }}>{act.what === "decline" ? "Decline" : "Confirm"}</button>}>
                    <div className="space-y-2">
                        <p className="text-sm text-surface-600">{act.a.kind === "loan" ? "Loan" : "Advance"} of {kes(act.a.amount)} · {act.a.reason}</p>
                        {act.what === "approve" && act.a.kind === "loan" && <label className="text-xs text-surface-500">Repay per pay run (KES)<input type="number" min={1} className="input mt-1" value={form.instalment} onChange={e => setForm(f => ({ ...f, instalment: e.target.value }))} /></label>}
                        {(act.what === "approve" || act.what === "decline") && <label className="text-xs text-surface-500">Note to the person{act.what === "decline" ? "" : " (optional)"}<input className="input mt-1" maxLength={300} value={form.note} onChange={e => setForm(f => ({ ...f, note: e.target.value }))} /></label>}
                        {act.what === "payout" && <>
                            <p className="text-xs text-surface-500">Records that the money went out; it does not send it. From here payroll takes {act.a.kind === "loan" ? `${kes(act.a.instalment)} per run` : "the whole amount off the next payslip"}.</p>
                            <label className="text-xs text-surface-500">Paid by<select className="input mt-1" value={form.method} onChange={e => setForm(f => ({ ...f, method: e.target.value as Method }))}><option value="mpesa">M-Pesa</option><option value="bank">Bank transfer</option><option value="cash">Cash</option></select></label>
                            <label className="text-xs text-surface-500">Reference (optional)<input className="input mt-1" maxLength={80} value={form.reference} onChange={e => setForm(f => ({ ...f, reference: e.target.value }))} /></label>
                        </>}
                        {act.what === "repay" && <>
                            <p className="text-xs text-surface-500">Money handed back outside payroll. Balance {kes(act.a.balance)}.</p>
                            <label className="text-xs text-surface-500">Amount (KES)<input type="number" min={0.01} step="0.01" className="input mt-1" value={form.amount} onChange={e => setForm(f => ({ ...f, amount: e.target.value }))} /></label>
                            <label className="text-xs text-surface-500">Reference (optional)<input className="input mt-1" maxLength={80} value={form.reference} onChange={e => setForm(f => ({ ...f, reference: e.target.value }))} /></label>
                        </>}
                    </div>
                </Modal>
            )}
            {adding && (
                <Modal open onClose={() => setAdding(false)} size="sm" title="Record an advance or loan"
                    footer={<button className="btn-primary btn-sm" disabled={create.isPending || !newForm.user_id || !newForm.amount || !newForm.reason} onClick={() => create.mutate()}>Record as approved</button>}>
                    <div className="space-y-2">
                        <label className="text-xs text-surface-500">Person<select className="input mt-1" value={newForm.user_id} onChange={e => setNewForm(f => ({ ...f, user_id: e.target.value }))}><option value="">{peopleLoading ? "Loading people…" : peopleFailed ? "Couldn't load people — close and try again" : "Choose a person"}</option>{(people?.data ?? []).map(p => <option key={p.id} value={p.id}>{p.name}{p.job_title ? ` — ${p.job_title}` : ""}</option>)}</select></label>
                        <label className="text-xs text-surface-500">Kind<select className="input mt-1" value={newForm.kind} onChange={e => setNewForm(f => ({ ...f, kind: e.target.value as "advance" | "loan" }))}><option value="advance">Advance (whole amount off next pay)</option><option value="loan">Loan (instalments)</option></select></label>
                        <label className="text-xs text-surface-500">Amount (KES)<input type="number" min={1} className="input mt-1" value={newForm.amount} onChange={e => setNewForm(f => ({ ...f, amount: e.target.value }))} /></label>
                        {newForm.kind === "loan" && <label className="text-xs text-surface-500">Repay per pay run (KES)<input type="number" min={1} className="input mt-1" value={newForm.instalment} onChange={e => setNewForm(f => ({ ...f, instalment: e.target.value }))} /></label>}
                        <label className="text-xs text-surface-500">Why<input className="input mt-1" maxLength={300} value={newForm.reason} onChange={e => setNewForm(f => ({ ...f, reason: e.target.value }))} /></label>
                    </div>
                </Modal>
            )}
        </div>
    );
}

/** Payroll › Returns (People PR 7): working CSVs per month for the statutory filings. */
export function ReturnsPanel() {
    const toast = useToastStore();
    const [month, setMonth] = useState(() => { const d = new Date(); d.setMonth(d.getMonth() - 1); return d.toISOString().slice(0, 7); });
    const [busy, setBusy] = useState<ReturnKind | null>(null);
    const pull = async (kind: ReturnKind) => {
        setBusy(kind);
        try { saveBlob(await advancesApi.returns(month, kind), `${kind}-return-${month}.csv`); } catch (e) { const err = e as ApiError; if (err?.message) toast.error(err.message); } finally { setBusy(null); }
    };
    return (
        <div className="space-y-4">
            <p className="text-sm text-surface-600">One row per person for every approved or paid run whose period ends in the month, with the identifiers from the employee file. These are the Hub's working files to fill the authorities' templates from, not the upload templates themselves. Each download asks you to confirm it is you and is recorded.</p>
            <div className="card"><div className="card-body space-y-3">
                <label className="text-xs text-surface-500">Month<input type="month" className="input mt-1 max-w-xs" value={month} onChange={e => setMonth(e.target.value)} /></label>
                <div className="flex flex-wrap gap-2">
                    {(["paye", "nssf", "shif", "housing_levy"] as ReturnKind[]).map(k => <button key={k} onClick={() => void pull(k)} disabled={busy !== null} className="btn-secondary btn-sm">{busy === k && <Spinner size="xs" />}{RETURN_LABEL[k]} CSV</button>)}
                </div>
            </div></div>
        </div>
    );
}
