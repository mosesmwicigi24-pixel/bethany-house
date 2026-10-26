import { useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { rosterApi, fmtDay, fmtHours, type Shift } from "@/api/roster";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** My shifts (People PR 3): the next two weeks, open shifts to claim, swap requests. Phone first. */

function ShiftRow({ s, right }: { s: Shift; right?: React.ReactNode }) {
    const isToday = s.date === new Date().toISOString().slice(0, 10);
    return (
        <li className="flex items-center gap-3 px-4 py-3">
            <div className={clsx("w-12 shrink-0 text-center rounded-xl py-1", isToday ? "bg-brand-500 text-white" : "bg-surface-100 text-surface-700")}>
                <p className="text-2xs font-semibold uppercase leading-none">{fmtDay(s.date, { weekday: "short" })}</p>
                <p className="text-lg font-bold leading-tight">{fmtDay(s.date, { day: "numeric" })}</p>
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-surface-900 tabular-nums">{s.starts_at} – {s.ends_at}{s.overnight ? " (next day)" : ""} <span className="font-normal text-surface-400">· {fmtHours(s.minutes)}</span></p>
                <p className="text-xs text-surface-500 truncate">{s.outlet?.name}{s.label ? ` · ${s.label}` : ""}{s.notes ? ` · ${s.notes}` : ""}</p>
            </div>
            {right}
        </li>
    );
}

export default function ShiftsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["my-shifts"], queryFn: () => rosterApi.mine() });
    const refresh = () => qc.invalidateQueries({ queryKey: ["my-shifts"] });
    const onErr = (e: ApiError) => toast.error(e.message);
    const [swap, setSwap] = useState<Shift | null>(null);
    const [note, setNote] = useState("");

    const claim = useMutation({ mutationFn: (id: number) => rosterApi.claim(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });
    const ask = useMutation({ mutationFn: (id: number) => rosterApi.requestSwap(id, { note: note || undefined }), onSuccess: r => { toast.success(r.message); setSwap(null); setNote(""); refresh(); }, onError: onErr });
    const withdraw = useMutation({ mutationFn: (id: number) => rosterApi.withdrawSwap(id), onSuccess: r => { toast.success(r.message); refresh(); }, onError: onErr });

    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    const pendingShiftIds = new Set(data.pending_swaps.map(r => r.shift?.id));

    return (
        <div className="mx-auto w-full max-w-lg px-4 py-4 sm:py-8 space-y-4">
            <div className="flex items-end justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-surface-400">Next two weeks</p>
                    <h1 className="text-xl font-bold text-surface-900">My shifts</h1>
                </div>
                <Link to="/me/clock" className="btn-secondary btn-sm">Clock</Link>
            </div>

            {data.shifts.length === 0 ? (
                <div className="rounded-2xl bg-white border border-line p-6 text-center text-sm text-surface-400">No shifts published for you yet.</div>
            ) : (
                <ul className="rounded-2xl bg-white border border-line shadow-sm divide-y divide-line">
                    {data.shifts.map(s => (
                        <ShiftRow key={s.id} s={s} right={
                            pendingShiftIds.has(s.id)
                                ? <span className="text-2xs font-semibold px-2 py-0.5 rounded-full bg-warning-light text-warning">swap asked</span>
                                : <button onClick={() => setSwap(s)} className="btn-ghost btn-sm text-xs text-brand-600">Swap</button>
                        } />
                    ))}
                </ul>
            )}

            {data.pending_swaps.length > 0 && (
                <div className="rounded-2xl bg-white border border-line shadow-sm">
                    <p className="px-4 pt-3 text-xs font-semibold uppercase tracking-wider text-surface-400">Waiting on the manager</p>
                    <ul className="divide-y divide-line">
                        {data.pending_swaps.map(r => (
                            <li key={r.id} className="flex items-center gap-3 px-4 py-3 text-sm">
                                <span className="flex-1">{r.shift ? `${fmtDay(r.shift.date)} ${r.shift.starts_at}–${r.shift.ends_at}` : "Shift"} → {r.to_user?.name ?? "anyone"}{r.note ? <span className="block text-xs text-surface-500">“{r.note}”</span> : null}</span>
                                <button onClick={() => withdraw.mutate(r.id)} className="btn-ghost btn-sm text-xs">Withdraw</button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-surface-400 px-1 mb-2">Open shifts you can take</p>
                {data.open_shifts.length === 0 ? (
                    <p className="text-sm text-surface-400 px-1">None right now.</p>
                ) : (
                    <ul className="rounded-2xl bg-white border border-line shadow-sm divide-y divide-line">
                        {data.open_shifts.map(s => (
                            <ShiftRow key={s.id} s={s} right={<button onClick={() => claim.mutate(s.id)} disabled={claim.isPending} className="btn-primary btn-sm">Take it</button>} />
                        ))}
                    </ul>
                )}
            </div>

            <Modal open={!!swap} onClose={() => setSwap(null)} title="Ask to swap" size="sm"
                footer={<><button onClick={() => setSwap(null)} className="btn-secondary btn-sm">Cancel</button><button onClick={() => swap && ask.mutate(swap.id)} disabled={ask.isPending} className="btn-primary btn-sm">Send to manager</button></>}>
                {swap && (
                    <div className="space-y-3 text-sm">
                        <p>{fmtDay(swap.date, { weekday: "long", day: "numeric", month: "long" })} {swap.starts_at}–{swap.ends_at} at {swap.outlet?.name}. The manager decides who takes it; the shift stays yours until then.</p>
                        <label className="text-xs text-surface-500 block">Why? (optional)<input className="input mt-1" value={note} onChange={e => setNote(e.target.value)} maxLength={300} placeholder="Clinic that morning" /></label>
                    </div>
                )}
            </Modal>
        </div>
    );
}
