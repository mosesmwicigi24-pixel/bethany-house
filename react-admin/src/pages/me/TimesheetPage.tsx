import { useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { timesheetsApi, SHEET_TONE, SHEET_LABEL } from "@/api/timesheets";
import { peopleC1Api } from "@/api/people-c1";
import { TimesheetDays, TimesheetTotals } from "@/components/people/TimesheetDays";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/** My timesheet (People PR 5): this period's hours, day by day, and Submit. Phone first. */
export default function TimesheetPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const { data, isLoading } = useQuery({ queryKey: ["my-timesheet", date], queryFn: () => timesheetsApi.mine(date) });
    const [note, setNote] = useState("");
    const { data: fixes } = useQuery({ queryKey: ["my-fixes"], queryFn: () => peopleC1Api.myFixes() });
    const [fixDate, setFixDate] = useState<string | null>(null);
    const [fixMsg, setFixMsg] = useState("");
    const requestFix = useMutation({ mutationFn: () => peopleC1Api.requestFix(fixDate!, fixMsg), onSuccess: r => { toast.success(r.message); setFixDate(null); setFixMsg(""); qc.invalidateQueries({ queryKey: ["my-fixes"] }); }, onError: (e: ApiError) => toast.error(e.message) });
    const fixByDate = Object.fromEntries((fixes?.data ?? []).map(f => [f.date, f.status])) as Record<string, "requested" | "approved" | "declined">;
    const submit = useMutation({ mutationFn: () => timesheetsApi.submit(note || undefined, date), onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["my-timesheet"] }); }, onError: (e: ApiError) => toast.error(e.message) });
    const fmtD = (iso: string) => new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { day: "numeric", month: "short" });
    // A weekly period is 7 days long; anything longer steps by a month.
    const move = (dir: 1 | -1) => setDate(d => {
        const x = new Date(d + "T00:00:00");
        const weekly = !!data && (new Date(data.period.ends_on + "T00:00:00").getTime() - new Date(data.period.starts_on + "T00:00:00").getTime()) < 8 * 86_400_000;
        if (weekly) x.setDate(x.getDate() + 7 * dir); else x.setMonth(x.getMonth() + dir);
        return x.toISOString().slice(0, 10);
    });

    if (isLoading || !data) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;

    return (
        <div className="mx-auto w-full max-w-lg px-4 py-4 sm:py-8 space-y-4">
            <div className="flex items-end justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-surface-400">{fmtD(data.period.starts_on)} – {fmtD(data.period.ends_on)}</p>
                    <h1 className="text-xl font-bold text-surface-900">My hours</h1>
                </div>
                <div className="flex items-center gap-1">
                    <button onClick={() => move(-1)} className="btn-ghost btn-sm" aria-label="Previous period">←</button>
                    <button onClick={() => move(1)} className="btn-ghost btn-sm" aria-label="Next period">→</button>
                </div>
            </div>
            <div className="flex items-center gap-2">
                <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", SHEET_TONE[data.status])}>{SHEET_LABEL[data.status]}{data.approved_by ? ` by ${data.approved_by}` : ""}</span>
                {data.period.status === "locked" && <span className="text-2xs font-semibold px-2 py-0.5 rounded-full bg-success-light text-success">Period locked</span>}
            </div>
            {data.sent_back_note && data.status === "sent_back" && <p className="rounded-xl bg-warning-light text-warning text-sm px-3 py-2">Your manager sent this back: {data.sent_back_note}</p>}

            <TimesheetTotals t={data.totals} compact />
            <TimesheetDays d={data} onRequestFix={d => { setFixDate(d); setFixMsg(""); }} fixes={fixByDate} />
            {fixDate && (
                <div className="rounded-2xl bg-white border border-line shadow-sm p-4 space-y-2">
                    <p className="text-sm font-semibold text-surface-900">Something wrong on {fmtD(fixDate)}?</p>
                    <p className="text-xs text-surface-500">Say what happened (forgot to clock out, phone was off, wrong outlet). Your manager corrects the punch and you are told.</p>
                    <input className="input" value={fixMsg} onChange={e => setFixMsg(e.target.value)} maxLength={300} placeholder="I forgot to clock out; I left at 17:10" />
                    <div className="flex gap-2">
                        <button onClick={() => setFixDate(null)} className="btn-secondary flex-1">Cancel</button>
                        <button onClick={() => requestFix.mutate()} disabled={requestFix.isPending || fixMsg.length < 3} className="btn-primary flex-1">Send to my manager</button>
                    </div>
                </div>
            )}

            {data.can_submit && (
                <div className="rounded-2xl bg-white border border-line shadow-sm p-4 space-y-2">
                    <p className="text-sm text-surface-700">Checked your days? Submit them to your manager. A wrong punch is corrected by the manager in Attendance; say so in the note.</p>
                    <input className="input" value={note} onChange={e => setNote(e.target.value)} placeholder="Note to your manager (optional)" maxLength={500} />
                    <button onClick={() => submit.mutate()} disabled={submit.isPending} className="btn-primary w-full">Submit my hours</button>
                </div>
            )}
            <Link to="/me/clock" className="block text-center text-xs text-brand-600 underline">Back to the clock</Link>
        </div>
    );
}
