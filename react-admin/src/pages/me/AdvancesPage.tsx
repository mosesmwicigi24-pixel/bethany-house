import { useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { advancesApi, kes, ADVANCE_TONE, ADVANCE_LABEL } from "@/api/payroll";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** My advances and loans (People PR 7): ask, see the decision, watch the balance come down. Phone first. */
export default function AdvancesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["my-advances"], queryFn: () => advancesApi.mine() });
    const [asking, setAsking] = useState(false);
    const [form, setForm] = useState<{ kind: "advance" | "loan"; amount: string; instalment: string; reason: string }>({ kind: "advance", amount: "", instalment: "", reason: "" });
    const refresh = () => void qc.invalidateQueries({ queryKey: ["my-advances"] });
    const ask = useMutation({
        mutationFn: () => advancesApi.request({ kind: form.kind, amount: Number(form.amount), instalment: form.kind === "loan" && form.instalment ? Number(form.instalment) : undefined, reason: form.reason }),
        onSuccess: r => { toast.success(r.message); setAsking(false); setForm({ kind: "advance", amount: "", instalment: "", reason: "" }); refresh(); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const cancel = useMutation({ mutationFn: (id: number) => advancesApi.cancelMine(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: (e: ApiError) => toast.error(e.message) });
    const fmtD = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short" }) : "");
    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    const waiting = data.data.some(a => a.status === "requested");

    return (
        <div className="mx-auto w-full max-w-lg px-4 py-4 sm:py-8 space-y-4">
            <div className="flex items-end justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-surface-400">Bethany House</p>
                    <h1 className="text-xl font-bold text-surface-900">Advances &amp; loans</h1>
                </div>
                {!asking && !waiting && <button onClick={() => setAsking(true)} className="btn-primary btn-sm">Ask for one</button>}
            </div>
            {data.open_balance > 0 && <p className="rounded-2xl bg-white border border-line shadow-sm p-4 text-sm text-surface-700">Still to repay: <b className="tabular-nums">{kes(data.open_balance)}</b>. It comes off your pay each run until it is cleared.</p>}

            {asking && (
                <div className="rounded-2xl bg-white border border-line shadow-sm p-4 space-y-3">
                    <div className="flex rounded-xl border border-line bg-surface-50 p-0.5 text-xs font-semibold">
                        {(["advance", "loan"] as const).map(k => <button key={k} onClick={() => setForm(f => ({ ...f, kind: k }))} className={clsx("flex-1 px-3 py-1.5 rounded-lg", form.kind === k ? "bg-white shadow-sm text-surface-900" : "text-surface-500")}>{k === "advance" ? "Advance" : "Loan"}</button>)}
                    </div>
                    <p className="text-xs text-surface-500">{form.kind === "advance" ? "An advance on this period's pay. The whole amount comes off your next payslip." : "A loan repaid in instalments, one per pay run, until cleared."}</p>
                    <label className="text-xs text-surface-500">Amount (KES)<input type="number" inputMode="decimal" min={1} className="input mt-1" value={form.amount} onChange={e => setForm(f => ({ ...f, amount: e.target.value }))} /></label>
                    {form.kind === "loan" && <label className="text-xs text-surface-500">Repay per pay run (KES)<input type="number" inputMode="decimal" min={1} className="input mt-1" value={form.instalment} onChange={e => setForm(f => ({ ...f, instalment: e.target.value }))} placeholder="Payroll may change this" /></label>}
                    <label className="text-xs text-surface-500">Why<input className="input mt-1" maxLength={300} value={form.reason} onChange={e => setForm(f => ({ ...f, reason: e.target.value }))} placeholder="School fees, rent, medical…" /></label>
                    <div className="flex gap-2">
                        <button onClick={() => setAsking(false)} className="btn-secondary flex-1">Cancel</button>
                        <button onClick={() => ask.mutate()} disabled={ask.isPending || !form.amount || !form.reason} className="btn-primary flex-1">{ask.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Send request</button>
                    </div>
                </div>
            )}

            {data.data.length === 0 && !asking && <p className="rounded-2xl bg-white border border-line shadow-sm p-4 text-sm text-surface-500">Nothing yet. Ask for an advance on this period's pay or a loan repaid over several runs.</p>}
            {data.data.map(a => (
                <div key={a.id} className="rounded-2xl bg-white border border-line shadow-sm p-4 space-y-2">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-surface-900">{a.kind === "loan" ? "Loan" : "Advance"} · {kes(a.amount)}</p>
                            <p className="text-2xs text-surface-500">{a.reason}{a.requested_at ? ` · asked ${fmtD(a.requested_at)}` : ""}</p>
                        </div>
                        <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full shrink-0", ADVANCE_TONE[a.status])}>{ADVANCE_LABEL[a.status]}</span>
                    </div>
                    {a.decision_note && <p className="text-xs text-surface-600">Payroll: {a.decision_note}</p>}
                    {a.status === "paid" && <p className="text-xs text-surface-700">Paid out {fmtD(a.paid_at)} by {a.payment_method}. Balance <b className="tabular-nums">{kes(a.balance)}</b>{a.kind === "loan" ? ` · ${kes(a.instalment)} per run` : ""}.</p>}
                    {a.repayments.length > 0 && (
                        <ul className="text-2xs text-surface-500 space-y-0.5">
                            {a.repayments.map(r => <li key={r.id} className="flex justify-between"><span>{r.kind === "payroll" ? `Off payslip ${r.reference ?? ""}` : `Repaid in cash${r.reference ? ` · ${r.reference}` : ""}`} · {r.on}</span><span className="tabular-nums">{kes(r.amount)}</span></li>)}
                        </ul>
                    )}
                    {a.status === "requested" && <button onClick={() => cancel.mutate(a.id)} className="btn-ghost btn-sm text-xs text-danger">Withdraw request</button>}
                </div>
            ))}
            <Link to="/me/payslips" className="block text-center text-xs text-brand-600 underline">My payslips</Link>
        </div>
    );
}
