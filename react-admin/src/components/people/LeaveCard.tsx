import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { leaveApi, STATUS_TONE, fmtSpan } from "@/api/leave";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";
import { CardHead, EmptyLine, Meter } from "./fileKit";

/**
 * Leave on a person's file (People PR 4): balances, the year's requests,
 * adjustments for hr.edit. Each tracked type reads as a bar — used, asked
 * for, and what is left — and the year can be stepped back to past years
 * (GET /people/{id}/leave?year=).
 */
export function LeaveCard({ userId, titleId }: { userId: number; titleId?: string }) {
    const qc = useQueryClient();
    const toast = useToastStore();
    const thisYear = new Date().getFullYear();
    const [year, setYear] = useState(thisYear);
    const { data, isLoading, isError } = useQuery({ queryKey: ["person-leave", userId, year], queryFn: () => leaveApi.forPerson(userId, year) });
    const [adj, setAdj] = useState<{ leave_type_id: number; days: string; reason: string } | null>(null);
    const adjust = useMutation({
        mutationFn: () => leaveApi.adjust({ user_id: userId, leave_type_id: adj!.leave_type_id, year, days: Number(adj!.days), reason: adj!.reason }),
        onSuccess: r => { toast.success(r.message); setAdj(null); qc.invalidateQueries({ queryKey: ["person-leave", userId, year] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const tracked = data?.balances.filter(b => b.available !== null) ?? [];

    return (
        <div className="card" aria-labelledby={titleId}>
            <CardHead icon="sun" tone="amber" titleId={titleId} title="Leave"
                subtitle={data ? `${data.requests.length} request${data.requests.length === 1 ? "" : "s"} in ${year}` : " "}
                actions={<>
                    <label className="sr-only" htmlFor={`leave-year-${userId}`}>Year</label>
                    <select id={`leave-year-${userId}`} value={year} onChange={e => { setYear(Number(e.target.value)); setAdj(null); }}
                        className="rounded-full border border-line bg-white pl-3 pr-8 py-2 text-sm font-medium text-surface-700 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 min-h-[40px]">
                        {[thisYear + 1, thisYear, thisYear - 1, thisYear - 2].map(y => <option key={y} value={y}>{y}</option>)}
                    </select>
                    {data?.can_adjust && !adj && tracked.length > 0 && <button onClick={() => setAdj({ leave_type_id: tracked[0].type.id, days: "", reason: "" })} className="btn-secondary btn-sm">Adjust</button>}
                </>} />
            <div className="card-body space-y-5">
                {isError ? (
                    <p className="text-sm text-surface-500">Leave could not be loaded just now.</p>
                ) : isLoading || !data ? (
                    <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4" aria-busy="true">
                        {[0, 1, 2].map(i => <div key={i} className="rounded-2xl border border-line p-4 space-y-3"><div className="skeleton h-3 w-24" /><div className="skeleton h-6 w-16" /><div className="skeleton h-2 w-full" /></div>)}
                    </div>
                ) : tracked.length === 0 ? (
                    <EmptyLine icon="sun" text="No leave types with a yearly allowance are set up yet." />
                ) : (
                    <ul className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        {tracked.map(b => {
                            const total = b.entitled + b.adjusted;
                            const left = b.available ?? 0;
                            const low = total > 0 && left / total <= 0.2;
                            return (
                                <li key={b.type.id} className="rounded-2xl border border-line p-4">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <p className="text-xs font-semibold text-surface-600 truncate">{b.type.name}</p>
                                        {!b.type.is_paid && <span className="text-2xs text-surface-400">unpaid</span>}
                                    </div>
                                    <p className="mt-1 flex items-baseline gap-1.5">
                                        <span className={clsx("text-2xl font-extrabold tabular-nums tracking-tight", low ? "text-amber-700" : "text-surface-900")}>{left}</span>
                                        <span className="text-xs text-surface-500">of {total} day{total === 1 ? "" : "s"} left</span>
                                    </p>
                                    <div className="mt-3">
                                        <Meter total={total} label={`${b.used} used, ${b.requested} requested, ${left} left of ${total}`}
                                            parts={[{ value: b.used, className: "bg-amber-500" }, { value: b.requested, className: "bg-amber-200" }]} />
                                    </div>
                                    <p className="mt-2 text-2xs text-surface-500 flex flex-wrap gap-x-3 gap-y-0.5">
                                        <span className="inline-flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-amber-500" />{b.used} used</span>
                                        {b.requested > 0 && <span className="inline-flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-amber-200" />{b.requested} asked</span>}
                                        {b.adjusted !== 0 && <span>{b.adjusted > 0 ? "+" : ""}{b.adjusted} adjusted</span>}
                                    </p>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {adj && (
                    <div className="rounded-2xl border border-line bg-surface-50 p-4 space-y-3 animate-fade-in">
                        <p className="text-sm font-semibold text-surface-900">Adjust a {year} balance</p>
                        <div className="grid grid-cols-1 sm:grid-cols-[1fr_8rem_2fr] gap-2">
                            <select className="input" value={adj.leave_type_id} onChange={e => setAdj({ ...adj, leave_type_id: Number(e.target.value) })} aria-label="Leave type">{tracked.map(b => <option key={b.type.id} value={b.type.id}>{b.type.name}</option>)}</select>
                            <input type="number" step="0.5" className="input" value={adj.days} onChange={e => setAdj({ ...adj, days: e.target.value })} placeholder="± days" aria-label="Days, plus or minus" />
                            <input className="input" value={adj.reason} onChange={e => setAdj({ ...adj, reason: e.target.value })} placeholder="Reason (carry-over, correction…)" maxLength={300} aria-label="Reason" />
                        </div>
                        <div className="flex gap-2 justify-end">
                            <button onClick={() => setAdj(null)} className="btn-ghost btn-sm">Cancel</button>
                            <button onClick={() => adjust.mutate()} disabled={adjust.isPending || !Number(adj.days) || adj.reason.trim().length < 3} className="btn-primary btn-sm">Save adjustment</button>
                        </div>
                    </div>
                )}

                {data && (data.requests.length > 0 ? (
                    <div>
                        <h4 className="text-2xs font-semibold uppercase tracking-wider text-surface-500 mb-2">Requests</h4>
                        <ul className="divide-y divide-line rounded-2xl border border-line text-sm">
                            {data.requests.map(r => (
                                <li key={r.id} className="flex items-center gap-3 px-4 py-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium text-surface-900">{fmtSpan(r)} <span className="font-normal text-surface-500">· {r.type?.name} · {r.days} day{r.days === 1 ? "" : "s"}</span></p>
                                        {r.reason && <p className="text-xs text-surface-500 truncate">{r.reason}</p>}
                                    </div>
                                    <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full capitalize", STATUS_TONE[r.status])}>{r.status}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : tracked.length > 0 && <p className="text-sm text-surface-500">No leave asked for in {year}.</p>)}

                {data && data.adjustments.length > 0 && (
                    <div>
                        <h4 className="text-2xs font-semibold uppercase tracking-wider text-surface-500 mb-1.5">Adjustments</h4>
                        <ul className="text-xs text-surface-600 space-y-1">
                            {data.adjustments.map(a => <li key={a.id}><span className="font-semibold tabular-nums">{a.days > 0 ? "+" : ""}{a.days}</span> {a.type} — {a.reason}</li>)}
                        </ul>
                    </div>
                )}
            </div>
        </div>
    );
}
