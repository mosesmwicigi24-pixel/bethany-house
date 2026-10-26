import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { payrollApi, kes, saveBlob, RUN_TONE, RUN_LABEL, RATE_LABEL, type PayrollRun, type PayrollLine, type RateTable, type RateCode } from "@/api/payroll";
import { PayslipItems } from "@/components/people/PayslipItems";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

type Method = "mpesa" | "bank" | "cash";

/**
 * Payroll (People PR 6). Runs are drafted from a locked pay period, approved
 * behind a step-up (which freezes the lines and posts one Salaries expense),
 * then paid line by line. The Rates tab holds the dated statutory tables.
 */
export default function PayrollPage() {
    const [tab, setTab] = useState<"runs" | "rates">("runs");
    const [open, setOpen] = useState<number | null>(null);
    return (
        <div className="space-y-5 animate-fade-in">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-xl font-bold text-surface-900">Payroll</h1>
                    <p className="text-sm text-surface-500">Pay runs from locked timesheets. Approving posts the labour cost to Expenses.</p>
                </div>
                <div className="flex rounded-xl border border-line bg-surface-50 p-0.5 text-xs font-semibold">
                    {(["runs", "rates"] as const).map(t => <button key={t} onClick={() => setTab(t)} className={clsx("px-3 py-1.5 rounded-lg", tab === t ? "bg-white shadow-sm text-surface-900" : "text-surface-500")}>{t === "runs" ? "Runs" : "Statutory rates"}</button>)}
                </div>
            </div>
            {tab === "runs" ? <Runs onOpen={setOpen} /> : <Rates />}
            {open !== null && <RunModal id={open} onClose={() => setOpen(null)} />}
        </div>
    );
}

function Runs({ onOpen }: { onOpen: (id: number) => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [year, setYear] = useState(new Date().getFullYear());
    const { data, isLoading } = useQuery({ queryKey: ["payroll-runs", year], queryFn: () => payrollApi.runs(year) });
    const draft = useMutation({
        mutationFn: (periodId: number) => payrollApi.draft(periodId),
        onSuccess: r => { toast.success(r.message); void qc.invalidateQueries({ queryKey: ["payroll-runs"] }); onOpen(r.run.id); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const fmtD = (iso: string) => new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { day: "numeric", month: "short" });
    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    return (
        <div className="space-y-4">
            {data.rates_unconfirmed.length > 0 && (
                <p className="rounded-xl bg-warning-light text-warning text-sm px-3 py-2">
                    The {data.rates_unconfirmed.map(c => RATE_LABEL[c]).join(", ")} table{data.rates_unconfirmed.length > 1 ? "s have" : " has"} not been confirmed against the authority. Check the figures under Statutory rates before approving a run.
                </p>
            )}
            {data.can_run && data.ready_periods.length > 0 && (
                <div className="card"><div className="card-body">
                    <p className="text-xs font-semibold uppercase tracking-wider text-surface-400 mb-2">Locked periods without a run</p>
                    <div className="flex flex-wrap gap-2">
                        {data.ready_periods.map(p => (
                            <button key={p.id} onClick={() => draft.mutate(p.id)} disabled={draft.isPending} className="btn-primary btn-sm">
                                Start run · {p.group ?? "Default"} {fmtD(p.starts_on)} – {fmtD(p.ends_on)}
                            </button>
                        ))}
                    </div>
                </div></div>
            )}
            <div className="card">
                <div className="card-header flex items-center justify-between">
                    <h3 className="font-semibold text-sm text-surface-900">Runs in {year}</h3>
                    <div className="flex items-center gap-1">
                        <button onClick={() => setYear(y => y - 1)} className="btn-ghost btn-sm" aria-label="Previous year">←</button>
                        <button onClick={() => setYear(y => y + 1)} className="btn-ghost btn-sm" aria-label="Next year">→</button>
                    </div>
                </div>
                {data.runs.length === 0 ? <p className="p-6 text-sm text-surface-500 text-center">No runs yet. Lock a pay period under Timesheets, then start a run here.</p> : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead><tr className="text-2xs uppercase tracking-wider text-surface-400 text-left"><th className="px-4 py-2">Run</th><th className="px-4 py-2">Period</th><th className="px-4 py-2">Status</th><th className="px-4 py-2 text-right">People</th><th className="px-4 py-2 text-right">Gross</th><th className="px-4 py-2 text-right">Net</th><th className="px-4 py-2 text-right">Employer cost</th></tr></thead>
                            <tbody>
                                {data.runs.map(r => (
                                    <tr key={r.id} onClick={() => onOpen(r.id)} className="border-t border-line hover:bg-surface-50 cursor-pointer">
                                        <td className="px-4 py-2 font-medium text-surface-900">{r.reference}<span className="block text-2xs text-surface-400">{r.group?.name}</span></td>
                                        <td className="px-4 py-2">{r.period ? `${fmtD(r.period.starts_on)} – ${fmtD(r.period.ends_on)}` : "—"}</td>
                                        <td className="px-4 py-2"><span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", RUN_TONE[r.status])}>{RUN_LABEL[r.status]}</span>{r.unpayable.length > 0 && <span className="ml-1 text-2xs text-warning">{r.unpayable.length} unpayable</span>}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{r.people}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{kes(r.totals.gross)}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{kes(r.totals.net)}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{kes(r.totals.employer_cost)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}

function RunModal({ id, onClose }: { id: number; onClose: () => void }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["payroll-run", id], queryFn: () => payrollApi.show(id) });
    const [line, setLine] = useState<PayrollLine | null>(null);
    const [paying, setPaying] = useState<{ line: PayrollLine | null; method: Method; reference: string } | null>(null);
    const refresh = () => { void qc.invalidateQueries({ queryKey: ["payroll-run", id] }); void qc.invalidateQueries({ queryKey: ["payroll-runs"] }); };
    const onErr = (e: ApiError) => toast.error(e.message);
    const recalc = useMutation({ mutationFn: () => payrollApi.recalculate(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const discard = useMutation({ mutationFn: () => payrollApi.discard(id), onSuccess: r => { toast.success(r.message); refresh(); onClose(); }, onError: onErr });
    const approve = useMutation({ mutationFn: () => payrollApi.approve(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const pay = useMutation({ mutationFn: (lines: { line_id: number; payment_method: Method; payment_reference?: string }[]) => payrollApi.pay(id, lines), onSuccess: r => { toast.success(r.message); refresh(); setPaying(null); }, onError: onErr });
    const slip = async (l: PayrollLine) => {
        try { saveBlob(await payrollApi.payslipPdf(id, l.id), `payslip-${data?.run.reference}-${l.user.id}.pdf`); } catch (e) { const err = e as ApiError; if (err?.message) toast.error(err.message); }
    };
    const run: PayrollRun | undefined = data?.run;
    const unpaid = run?.lines?.filter(l => !l.paid_at) ?? [];
    const canRun = !!run && (run.status === "draft" || unpaid.length > 0);
    const targets = paying ? (paying.line ? [paying.line] : unpaid) : [];

    return (
        <Modal open onClose={onClose} size="xl" title={run ? <span>{run.reference} <span className={clsx("ml-2 text-2xs font-semibold px-2 py-0.5 rounded-full align-middle", RUN_TONE[run.status])}>{RUN_LABEL[run.status]}</span></span> : "Payroll run"}>
            {isLoading || !run ? <div className="flex items-center justify-center h-40"><Spinner size="lg" /></div> : (
                <div className="space-y-4">
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
                        <Stat label="People" value={String(run.people)} />
                        <Stat label="Gross" value={kes(run.totals.gross)} />
                        <Stat label="Net pay" value={kes(run.totals.net)} />
                        <Stat label="Employer cost" value={kes(run.totals.employer_cost)} hint="gross + employer NSSF + levy" />
                        <Stat label="PAYE" value={kes(run.totals.paye)} />
                        <Stat label="NSSF (both)" value={kes(run.totals.nssf_employee + run.totals.nssf_employer)} />
                        <Stat label="SHIF" value={kes(run.totals.shif)} />
                        <Stat label="Housing Levy (both)" value={kes(run.totals.housing_employee + run.totals.housing_employer)} />
                    </div>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-surface-500">
                        {run.period && <span>Period {run.period.starts_on} – {run.period.ends_on} · {run.group?.name}</span>}
                        {run.rates_used && <span>Rates: {run.rates_used.map(r => `${RATE_LABEL[r.code]} from ${r.effective_from}${r.is_confirmed ? "" : " (unconfirmed)"}`).join(" · ")}</span>}
                        {run.approved_by && <span>Approved by {run.approved_by}</span>}
                        {run.expense && <span>Expense {run.expense.reference ?? `#${run.expense.id}`}{run.expense.status ? ` · ${run.expense.status}` : ""}</span>}
                        {run.reconciles === false && <span className="text-danger font-semibold">Does not reconcile: {run.breaks?.join("; ")}</span>}
                        {run.reconciles && run.status !== "draft" && <span className="text-success">Ties: lines = run = expense</span>}
                    </div>
                    {run.unpayable.length > 0 && (
                        <p className="rounded-xl bg-warning-light text-warning text-xs px-3 py-2">Not on this run: {run.unpayable.map(u => `${u.name} (${u.why.toLowerCase()})`).join(", ")}. Fix the timesheet or pay structure and recalculate.</p>
                    )}
                    <div className="overflow-x-auto rounded-xl border border-line">
                        <table className="w-full text-sm">
                            <thead><tr className="text-2xs uppercase tracking-wider text-surface-400 text-left bg-surface-50"><th className="px-3 py-2">Person</th><th className="px-3 py-2 text-right">Gross</th><th className="px-3 py-2 text-right">PAYE</th><th className="px-3 py-2 text-right">NSSF</th><th className="px-3 py-2 text-right">SHIF</th><th className="px-3 py-2 text-right">Levy</th><th className="px-3 py-2 text-right">Net</th><th className="px-3 py-2">Paid</th><th className="px-3 py-2"></th></tr></thead>
                            <tbody>
                                {run.lines?.map(l => (
                                    <tr key={l.id} className="border-t border-line">
                                        <td className="px-3 py-2"><button onClick={() => setLine(l)} className="font-medium text-brand-700 hover:underline text-left">{l.user.name ?? `#${l.user.id}`}</button><span className="block text-2xs text-surface-400">{l.structure.kind}{l.structure.service_fraction !== null && l.structure.service_fraction < 1 ? ` · ${Math.round(l.structure.service_fraction * 100)}% of period` : ""}</span></td>
                                        <td className="px-3 py-2 text-right tabular-nums">{kes(l.gross)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{kes(l.paye)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{kes(l.nssf_employee)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{kes(l.shif)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{kes(l.housing_employee)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums font-semibold">{kes(l.net)}</td>
                                        <td className="px-3 py-2 text-2xs text-surface-500">{l.paid_at ? `${l.payment_method}${l.payment_reference ? ` · ${l.payment_reference}` : ""}` : run.status === "draft" ? "" : <button onClick={() => setPaying({ line: l, method: "mpesa", reference: "" })} className="text-brand-700 hover:underline">Record payment</button>}</td>
                                        <td className="px-3 py-2 text-right">{run.status !== "draft" && <button onClick={() => void slip(l)} className="btn-ghost btn-sm text-xs">Payslip</button>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {canRun && (
                        <div className="flex flex-wrap justify-end gap-2">
                            {run.status === "draft" && <>
                                <button onClick={() => { if (confirm("Discard this draft? Nothing has been posted.")) discard.mutate(); }} className="btn-secondary btn-sm text-danger">Discard draft</button>
                                <button onClick={() => recalc.mutate()} disabled={recalc.isPending} className="btn-secondary btn-sm">Recalculate</button>
                                <button onClick={() => approve.mutate()} disabled={approve.isPending || run.people === 0} className="btn-primary btn-sm">{approve.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Approve and post {kes(run.totals.employer_cost)} to Expenses</button>
                            </>}
                            {run.status === "approved" && unpaid.length > 0 && <button onClick={() => setPaying({ line: null, method: "mpesa", reference: "" })} className="btn-primary btn-sm">Record payment for all {unpaid.length} unpaid</button>}
                        </div>
                    )}
                    {run.status === "draft" && <p className="text-2xs text-surface-500">Approving freezes every line and posts one expense of the employer cost under Salaries &amp; wages, dated the last day of the period. Net pay and statutory remittances are then recorded as they are paid out.</p>}
                </div>
            )}

            {line && (
                <Modal open onClose={() => setLine(null)} title={`${line.user.name ?? "Payslip"} · ${run?.reference ?? ""}`} size="md">
                    <PayslipItems line={line} />
                </Modal>
            )}
            {paying && (
                <Modal open onClose={() => setPaying(null)} size="sm" title={paying.line ? `Record payment · ${paying.line.user.name ?? ""}` : `Record payment · ${unpaid.length} people`}
                    footer={<button onClick={() => pay.mutate(targets.map(l => ({ line_id: l.id, payment_method: paying.method, payment_reference: paying.reference || undefined })))} disabled={pay.isPending} className="btn-primary btn-sm">{pay.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Record as paid</button>}>
                    <div className="space-y-2">
                        <p className="text-sm text-surface-600">Net {kes(targets.reduce((s, l) => s + l.net, 0))}{paying.line ? "" : ` across ${targets.length} people`}. This records that the money went out; it does not send it.</p>
                        <label className="text-xs text-surface-500">Paid by
                            <select className="input mt-1" value={paying.method} onChange={e => setPaying({ ...paying, method: e.target.value as Method })}>
                                <option value="mpesa">M-Pesa</option><option value="bank">Bank transfer</option><option value="cash">Cash</option>
                            </select>
                        </label>
                        <label className="text-xs text-surface-500">Reference (optional)<input className="input mt-1" maxLength={80} value={paying.reference} onChange={e => setPaying({ ...paying, reference: e.target.value })} placeholder="M-Pesa code, batch or bank reference" /></label>
                    </div>
                </Modal>
            )}
        </Modal>
    );
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="rounded-xl border border-line bg-surface-50 px-3 py-2">
            <p className="text-2xs font-semibold uppercase tracking-wider text-surface-400">{label}</p>
            <p className="text-base font-bold text-surface-900 tabular-nums">{value}</p>
            {hint && <p className="text-2xs text-surface-400">{hint}</p>}
        </div>
    );
}

function Rates() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["payroll-rates"], queryFn: () => payrollApi.rates() });
    const [adding, setAdding] = useState<RateCode | null>(null);
    const [form, setForm] = useState<{ effective_from: string; json: string; reduces: boolean; source: string }>({ effective_from: "", json: "", reduces: true, source: "" });
    const confirmRate = useMutation({ mutationFn: (id: number) => payrollApi.confirmRate(id), onSuccess: r => { toast.success(r.message); void qc.invalidateQueries({ queryKey: ["payroll-rates"] }); void qc.invalidateQueries({ queryKey: ["payroll-runs"] }); }, onError: (e: ApiError) => toast.error(e.message) });
    const add = useMutation({
        mutationFn: () => {
            let config: Record<string, unknown>;
            try { config = JSON.parse(form.json) as Record<string, unknown>; } catch { return Promise.reject({ message: "The figures must be valid JSON.", errors: {}, status: 422 } as ApiError); }
            return payrollApi.addRate({ code: adding!, effective_from: form.effective_from, config, reduces_taxable_pay: form.reduces, source: form.source || undefined });
        },
        onSuccess: r => { toast.success(r.message); setAdding(null); void qc.invalidateQueries({ queryKey: ["payroll-rates"] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    const byCode = (c: RateCode) => data.data.filter(r => r.code === c);
    const startAdd = (c: RateCode) => { const latest = byCode(c)[0]; setForm({ effective_from: "", json: JSON.stringify(latest?.config ?? {}, null, 2), reduces: latest?.reduces_taxable_pay ?? false, source: "" }); setAdding(c); };
    return (
        <div className="space-y-4">
            <p className="text-sm text-surface-600">Each table is a dated row: a change is a new row from a date, never an edit, so old runs keep the rates they used. <b>Confirm</b> a row once you have checked it against KRA, NSSF or SHA. Whether SHIF and the Housing Levy reduce taxable pay is the <i>reduces taxable pay</i> switch on each row.</p>
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                {(["paye", "nssf", "shif", "housing_levy"] as RateCode[]).map(code => (
                    <div key={code} className="card">
                        <div className="card-header flex items-center justify-between">
                            <h3 className="font-semibold text-sm text-surface-900">{RATE_LABEL[code]}</h3>
                            <button onClick={() => startAdd(code)} className="btn-secondary btn-sm">New row from a date</button>
                        </div>
                        <div className="card-body space-y-2">
                            {byCode(code).map((r, i) => <RateRow key={r.id} r={r} current={i === 0} onConfirm={() => confirmRate.mutate(r.id)} />)}
                        </div>
                    </div>
                ))}
            </div>
            {adding && (
                <Modal open onClose={() => setAdding(null)} title={`New ${RATE_LABEL[adding]} table`} size="md"
                    footer={<button onClick={() => add.mutate()} disabled={add.isPending || !form.effective_from} className="btn-primary btn-sm">Save (asks you to confirm it’s you)</button>}>
                    <div className="space-y-2">
                        <label className="text-xs text-surface-500">Effective from<input type="date" className="input mt-1" value={form.effective_from} onChange={e => setForm(f => ({ ...f, effective_from: e.target.value }))} /></label>
                        <label className="text-xs text-surface-500">Figures (JSON)<textarea className="input mt-1 font-mono text-xs" rows={8} value={form.json} onChange={e => setForm(f => ({ ...f, json: e.target.value }))} /></label>
                        <label className="flex items-center gap-2 text-sm text-surface-700"><input type="checkbox" checked={form.reduces} onChange={e => setForm(f => ({ ...f, reduces: e.target.checked }))} />Employee's share reduces taxable pay</label>
                        <label className="text-xs text-surface-500">Source (gazette, circular, URL)<input className="input mt-1" maxLength={300} value={form.source} onChange={e => setForm(f => ({ ...f, source: e.target.value }))} /></label>
                    </div>
                </Modal>
            )}
        </div>
    );
}

function RateRow({ r, current, onConfirm }: { r: RateTable; current: boolean; onConfirm: () => void }) {
    const pct = (v: unknown) => `${(Number(v) * 100).toFixed(2).replace(/\.?0+$/, "")}%`;
    const c = r.config as Record<string, unknown>;
    let summary: string;
    if (r.code === "paye") {
        const bands = (c.bands as { upto: number | null; rate: number }[] | undefined) ?? [];
        summary = bands.map(b => `${pct(b.rate)} ${b.upto === null ? "above" : `to ${kes(b.upto)}`}`).join(" · ") + ` · relief ${kes(Number(c.personal_relief ?? 0))}`;
    } else if (r.code === "nssf") summary = `${pct(c.employee_rate)} employee + ${pct(c.employer_rate)} employer on pay up to ${kes(Number(c.tier2_limit ?? 0))}`;
    else if (r.code === "shif") summary = `${pct(c.rate)} of gross, minimum ${kes(Number(c.minimum ?? 0))}`;
    else summary = `${pct(c.employee_rate)} employee + ${pct(c.employer_rate)} employer`;
    return (
        <div className={clsx("rounded-xl border p-3", current ? "border-brand-200 bg-brand-50/40" : "border-line opacity-70")}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-semibold text-surface-900">From {r.effective_from}{current ? " · in force" : ""}</p>
                    <p className="text-xs text-surface-700 mt-0.5">{summary}</p>
                    <p className="text-2xs text-surface-500 mt-0.5">{r.reduces_taxable_pay ? "Reduces taxable pay" : "Does not reduce taxable pay"}{r.source ? ` · ${r.source}` : ""}</p>
                </div>
                {r.is_confirmed ? <span className="text-2xs font-semibold px-2 py-0.5 rounded-full bg-success-light text-success shrink-0">Confirmed</span> : <button onClick={onConfirm} className="btn-secondary btn-sm shrink-0">Confirm</button>}
            </div>
        </div>
    );
}
