import { useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { peopleC1Api, REQUEST_LABEL, type RequestItem } from "@/api/people-c1";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** People › Requests (C1): everything waiting on you, one list. Decisions happen on the owning screen; a timesheet fix is decided here. */
export default function RequestsPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { data, isLoading } = useQuery({ queryKey: ["people-requests"], queryFn: () => peopleC1Api.requests(), refetchInterval: 60_000 });
    const [fix, setFix] = useState<{ item: RequestItem; decision: "approve" | "decline"; note: string } | null>(null);
    const decide = useMutation({
        mutationFn: () => peopleC1Api.decideFix(fix!.item.id, fix!.decision, fix!.note || undefined),
        onSuccess: r => { toast.success(r.message); setFix(null); qc.invalidateQueries({ queryKey: ["people-requests"] }); qc.invalidateQueries({ queryKey: ["people-requests-count"] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    const ago = (iso: string | null) => { if (!iso) return ""; const h = Math.round((Date.now() - new Date(iso).getTime()) / 3_600_000); return h < 1 ? "just now" : h < 24 ? `${h} h ago` : `${Math.round(h / 24)} d ago`; };
    const TONE: Record<RequestItem["kind"], string> = { leave: "bg-info-light text-info", swap: "bg-brand-100 text-brand-700", advance: "bg-warning-light text-warning", fix: "bg-surface-100 text-surface-600" };
    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    return (
        <div className="space-y-5 animate-fade-in">
            <div>
                <h1 className="page-title">Requests</h1>
                <p className="page-subtitle">{data.count === 0 ? "Nothing is waiting on you." : `${data.count} waiting on you: ${Object.entries(data.counts).filter(([, n]) => n).map(([k, n]) => `${n} ${REQUEST_LABEL[k as RequestItem["kind"]].toLowerCase()}`).join(", ")}.`}</p>
            </div>
            <div className="card">
                {data.data.length === 0 ? <p className="p-8 text-center text-sm text-surface-500">All clear.</p> : (
                    <ul className="divide-y divide-line">
                        {data.data.map(it => (
                            <li key={`${it.kind}-${it.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full shrink-0", TONE[it.kind])}>{REQUEST_LABEL[it.kind]}</span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-surface-900">{it.person} <span className="font-normal text-surface-600">· {it.summary}</span></p>
                                    {it.note && <p className="text-xs text-surface-500 truncate">{it.note}</p>}
                                </div>
                                <span className="text-2xs text-surface-400 shrink-0">{ago(it.asked_at)}</span>
                                {it.kind === "fix" ? (
                                    <div className="flex gap-1 shrink-0">
                                        <Link to={it.href} className="btn-secondary btn-sm">Open the day</Link>
                                        <button onClick={() => setFix({ item: it, decision: "approve", note: "" })} className="btn-primary btn-sm">Corrected</button>
                                        <button onClick={() => setFix({ item: it, decision: "decline", note: "" })} className="btn-ghost btn-sm text-danger">Decline</button>
                                    </div>
                                ) : <Link to={it.href} className="btn-primary btn-sm shrink-0">Decide</Link>}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {fix && (
                <Modal open onClose={() => setFix(null)} size="sm" title={fix.decision === "approve" ? `Corrected · ${fix.item.person}` : `Decline · ${fix.item.person}`}
                    footer={<button onClick={() => decide.mutate()} disabled={decide.isPending || (fix.decision === "decline" && fix.note.length < 2)} className="btn-primary btn-sm">{fix.decision === "approve" ? "Mark corrected" : "Decline"}</button>}>
                    <div className="space-y-2">
                        <p className="text-sm text-surface-600">{fix.item.summary}: “{fix.item.note}”</p>
                        {fix.decision === "approve" && <p className="text-xs text-surface-500">Only mark it once you have corrected the punch in Attendance. The person is told.</p>}
                        <label className="text-xs text-surface-500">Note to the person{fix.decision === "approve" ? " (optional)" : ""}<input className="input mt-1" maxLength={300} value={fix.note} onChange={e => setFix({ ...fix, note: e.target.value })} /></label>
                    </div>
                </Modal>
            )}
        </div>
    );
}
