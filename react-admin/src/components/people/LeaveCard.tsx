import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { leaveApi, STATUS_TONE, fmtSpan } from "@/api/leave";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** Leave on a person's file (People PR 4): balances, this year's requests, adjustments for hr.edit. */
export function LeaveCard({ userId }: { userId: number }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const year = new Date().getFullYear();
    const { data } = useQuery({ queryKey: ["person-leave", userId, year], queryFn: () => leaveApi.forPerson(userId, year) });
    const [adj, setAdj] = useState<{ leave_type_id: number; days: string; reason: string } | null>(null);
    const adjust = useMutation({
        mutationFn: () => leaveApi.adjust({ user_id: userId, leave_type_id: adj!.leave_type_id, year, days: Number(adj!.days), reason: adj!.reason }),
        onSuccess: r => { toast.success(r.message); setAdj(null); qc.invalidateQueries({ queryKey: ["person-leave", userId, year] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    if (!data) return null;
    const tracked = data.balances.filter(b => b.available !== null);

    return (
        <div className="card">
            <div className="card-header flex items-center justify-between">
                <h3 className="font-semibold text-sm text-surface-900">Leave {year}</h3>
                {data.can_adjust && !adj && <button onClick={() => setAdj({ leave_type_id: tracked[0]?.type.id ?? 0, days: "", reason: "" })} className="btn-secondary btn-sm">Adjust balance</button>}
            </div>
            <div className="card-body space-y-3">
                <dl className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    {tracked.map(b => (
                        <div key={b.type.id}>
                            <dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400 truncate">{b.type.name}</dt>
                            <dd className="text-lg font-bold tabular-nums text-surface-900">{b.available}<span className="text-xs font-medium text-surface-400"> / {b.entitled + b.adjusted}</span></dd>
                            <dd className="text-2xs text-surface-500">{b.used} used{b.requested ? ` · ${b.requested} asked` : ""}{b.adjusted ? ` · ${b.adjusted > 0 ? "+" : ""}${b.adjusted} adj` : ""}</dd>
                        </div>
                    ))}
                </dl>
                {adj && (
                    <div className="rounded-xl border border-line bg-surface-50 p-3 grid grid-cols-1 sm:grid-cols-4 gap-2 items-end">
                        <select className="input" value={adj.leave_type_id} onChange={e => setAdj({ ...adj, leave_type_id: Number(e.target.value) })} aria-label="Type">{tracked.map(b => <option key={b.type.id} value={b.type.id}>{b.type.name}</option>)}</select>
                        <input type="number" step="0.5" className="input" value={adj.days} onChange={e => setAdj({ ...adj, days: e.target.value })} placeholder="+ days or − days" aria-label="Days" />
                        <input className="input" value={adj.reason} onChange={e => setAdj({ ...adj, reason: e.target.value })} placeholder="Reason (carry-over, correction…)" maxLength={300} aria-label="Reason" />
                        <div className="flex gap-2 justify-end">
                            <button onClick={() => setAdj(null)} className="btn-secondary btn-sm">Cancel</button>
                            <button onClick={() => adjust.mutate()} disabled={adjust.isPending || !Number(adj.days) || adj.reason.trim().length < 3} className="btn-primary btn-sm">Save</button>
                        </div>
                    </div>
                )}
                {data.requests.length > 0 && (
                    <ul className="divide-y divide-line rounded-lg border border-line text-sm">
                        {data.requests.map(r => (
                            <li key={r.id} className="flex items-center gap-3 px-3 py-2">
                                <span className="flex-1">{fmtSpan(r)} · {r.type?.name} · {r.days} day{r.days === 1 ? "" : "s"}{r.reason ? <span className="text-surface-400"> · {r.reason}</span> : null}</span>
                                <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", STATUS_TONE[r.status])}>{r.status}</span>
                            </li>
                        ))}
                    </ul>
                )}
                {data.adjustments.length > 0 && (
                    <ul className="text-2xs text-surface-500 space-y-0.5">
                        {data.adjustments.map(a => <li key={a.id}>{a.days > 0 ? "+" : ""}{a.days} {a.type}: {a.reason}</li>)}
                    </ul>
                )}
            </div>
        </div>
    );
}
