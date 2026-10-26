import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { payrollApi, kes, KIND_LABEL, type Allowance, type StructureKind } from "@/api/payroll";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * Pay structure on the employee file (People PR 6). Loaded only when the
 * viewer asks: the server wants hr.view_sensitive and a step-up, and logs the
 * read. A change is a new dated row; the history stays.
 */
export function PayStructureCard({ userId, canView }: { userId: number; canView: boolean }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [opened, setOpened] = useState(false);
    const [adding, setAdding] = useState(false);
    const [draft, setDraft] = useState<{ effective_from: string; kind: StructureKind; base_amount: string; allowances: Allowance[]; notes: string }>({ effective_from: new Date().toISOString().slice(0, 10), kind: "monthly", base_amount: "", allowances: [], notes: "" });
    const { data, isFetching, error } = useQuery({ queryKey: ["pay-structures", userId], queryFn: () => payrollApi.structures(userId), enabled: opened, retry: false });
    const save = useMutation({
        mutationFn: () => payrollApi.addStructure(userId, { effective_from: draft.effective_from, kind: draft.kind, base_amount: Number(draft.base_amount), allowances: draft.allowances.filter(a => a.name && a.amount > 0), notes: draft.notes || undefined }),
        onSuccess: r => { toast.success(r.message); setAdding(false); void qc.invalidateQueries({ queryKey: ["pay-structures", userId] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    if (!canView) return null;
    const setA = (i: number, patch: Partial<Allowance>) => setDraft(d => ({ ...d, allowances: d.allowances.map((a, k) => (k === i ? { ...a, ...patch } : a)) }));

    return (
        <div className="card">
            <div className="card-header flex items-center justify-between">
                <h3 className="font-semibold text-sm text-surface-900">Pay</h3>
                {opened && data?.can_edit && !adding && <button onClick={() => setAdding(true)} className="btn-secondary btn-sm">New rate from a date</button>}
            </div>
            <div className="card-body">
                {!opened ? (
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-sm text-surface-500">Salary or rate and allowances. Opening this asks you to confirm it is you and is recorded.</p>
                        <button onClick={() => setOpened(true)} className="btn-secondary btn-sm shrink-0">Show pay</button>
                    </div>
                ) : isFetching && !data ? <Spinner size="sm" /> : error ? (
                    <p className="text-sm text-danger">{(error as ApiError).message}</p>
                ) : (
                    <div className="space-y-3">
                        {data?.data.length === 0 && !adding && <p className="text-sm text-surface-500">No pay structure yet; payroll will list this person as unpayable until one is set.</p>}
                        {data?.data.map((s, i) => (
                            <div key={s.id} className={i === 0 ? "rounded-xl border border-brand-200 bg-brand-50/40 p-3" : "rounded-xl border border-line p-3 opacity-70"}>
                                <div className="flex items-baseline justify-between gap-3">
                                    <div>
                                        <p className="text-sm font-semibold text-surface-900">{KIND_LABEL[s.kind]} · KES {kes(s.base_amount)}</p>
                                        <p className="text-2xs text-surface-500">From {s.effective_from}{i === 0 ? " · current" : ""}</p>
                                    </div>
                                </div>
                                {s.allowances.length > 0 && (
                                    <ul className="mt-2 text-xs text-surface-700 space-y-0.5">
                                        {s.allowances.map((a, k) => <li key={k} className="flex justify-between"><span>{a.name}{a.taxable ? "" : " (non-taxable)"}</span><span className="tabular-nums">{kes(a.amount)}</span></li>)}
                                    </ul>
                                )}
                                {s.notes && <p className="mt-1 text-2xs text-surface-500">{s.notes}</p>}
                            </div>
                        ))}
                        {adding && (
                            <div className="rounded-xl border border-line bg-surface-50 p-3 space-y-2">
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                    <label className="text-xs text-surface-500">From<input type="date" className="input mt-1" value={draft.effective_from} onChange={e => setDraft(d => ({ ...d, effective_from: e.target.value }))} /></label>
                                    <label className="text-xs text-surface-500">Kind
                                        <select className="input mt-1" value={draft.kind} onChange={e => setDraft(d => ({ ...d, kind: e.target.value as StructureKind }))}>
                                            <option value="monthly">Monthly salary</option><option value="daily">Daily rate</option><option value="hourly">Hourly rate</option>
                                        </select>
                                    </label>
                                    <label className="text-xs text-surface-500">{draft.kind === "monthly" ? "Salary (KES)" : draft.kind === "daily" ? "Per day (KES)" : "Per hour (KES)"}
                                        <input type="number" inputMode="decimal" min={0} step="0.01" className="input mt-1" value={draft.base_amount} onChange={e => setDraft(d => ({ ...d, base_amount: e.target.value }))} /></label>
                                </div>
                                <div className="space-y-1">
                                    {draft.allowances.map((a, i) => (
                                        <div key={i} className="grid grid-cols-[1fr_auto_auto_auto] gap-2 items-center">
                                            <input className="input" placeholder="Allowance (House, Transport…)" value={a.name} maxLength={40} onChange={e => setA(i, { name: e.target.value })} />
                                            <input type="number" inputMode="decimal" min={0} className="input w-28" value={a.amount || ""} onChange={e => setA(i, { amount: Number(e.target.value) })} />
                                            <label className="text-2xs text-surface-500 flex items-center gap-1"><input type="checkbox" checked={!!a.taxable} onChange={e => setA(i, { taxable: e.target.checked })} />taxable</label>
                                            <button onClick={() => setDraft(d => ({ ...d, allowances: d.allowances.filter((_, k) => k !== i) }))} className="btn-ghost btn-sm text-xs">✕</button>
                                        </div>
                                    ))}
                                    {draft.allowances.length < 10 && <button onClick={() => setDraft(d => ({ ...d, allowances: [...d.allowances, { name: "", amount: 0, taxable: true }] }))} className="btn-ghost btn-sm text-xs">+ Allowance</button>}
                                </div>
                                <input className="input" placeholder="Note (optional): why the change" maxLength={300} value={draft.notes} onChange={e => setDraft(d => ({ ...d, notes: e.target.value }))} />
                                <div className="flex justify-end gap-2">
                                    <button onClick={() => setAdding(false)} className="btn-secondary btn-sm">Cancel</button>
                                    <button onClick={() => save.mutate()} disabled={save.isPending || !draft.base_amount} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Save from {draft.effective_from}</button>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
