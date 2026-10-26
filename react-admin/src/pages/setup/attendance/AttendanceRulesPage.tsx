import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { peopleC1Api, type AttendanceRules } from "@/api/people-c1";
import { usePermissions } from "@/hooks/usePermissions";
import { Spinner } from "@/components/ui/Spinner";
import { useToastStore } from "@/store/toast.store";
import type { ApiError } from "@/types";

/**
 * Setup › Attendance rules (People C1). One page, short sections, a plain
 * sentence per rule. Read with attendance.view_team; save with settings.edit.
 */
const DAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

export default function AttendanceRulesPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const canEdit = can("settings.edit");
    const { data, isLoading } = useQuery({ queryKey: ["attendance-rules"], queryFn: () => peopleC1Api.rules() });
    const [r, setR] = useState<AttendanceRules | null>(null);
    useEffect(() => { if (data && !r) setR(data.rules); }, [data, r]);
    const save = useMutation({
        mutationFn: () => peopleC1Api.saveRules(r!),
        onSuccess: res => { toast.success(res.message); setR(res.rules); qc.invalidateQueries({ queryKey: ["attendance-rules"] }); qc.invalidateQueries({ queryKey: ["time-clock-status"] }); },
        onError: (e: ApiError) => toast.error(e.message),
    });
    if (isLoading || !r) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    const set = <K extends keyof AttendanceRules>(k: K, v: AttendanceRules[K]) => setR(x => (x ? { ...x, [k]: v } : x));
    const dirty = JSON.stringify(r) !== JSON.stringify(data?.rules);
    const Num = ({ k, min, max, unit, off }: { k: keyof AttendanceRules; min: number; max: number; unit: string; off?: string }) => (
        <span className="inline-flex items-center gap-1">
            <input type="number" min={min} max={max} className="input w-20 px-2 py-1 text-sm" disabled={!canEdit} value={Number(r[k])} onChange={e => set(k, Number(e.target.value) as never)} />
            <span className="text-xs text-surface-500">{unit}{off && Number(r[k]) === 0 ? ` (${off})` : ""}</span>
        </span>
    );
    const Section = ({ title, children }: { title: string; children: React.ReactNode }) => (
        <div className="card"><div className="card-header"><h3 className="font-semibold text-sm text-surface-900">{title}</h3></div><div className="card-body space-y-3 text-sm text-surface-700">{children}</div></div>
    );
    const Row = ({ children }: { children: React.ReactNode }) => <div className="flex flex-wrap items-center gap-x-2 gap-y-1">{children}</div>;

    return (
        <div className="space-y-5 animate-fade-in max-w-3xl">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="page-title">Attendance rules</h1>
                    <p className="page-subtitle">How the clock, the timesheet and the location on shift behave. Pay rhythm and hours per weekday are on the <Link to="/timesheets" className="text-brand-600 underline">pay groups</Link>.</p>
                </div>
                {canEdit && <button onClick={() => save.mutate()} disabled={!dirty || save.isPending} className="btn-primary btn-sm">{save.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}Save rules</button>}
            </div>
            {!canEdit && <p className="rounded-xl bg-surface-100 text-surface-600 text-xs px-3 py-2">You can read these rules. Changing them needs the Edit settings permission.</p>}

            <Section title="Working days">
                <Row><span>Working days are</span>
                    {DAYS.map((d, i) => <button key={d} type="button" disabled={!canEdit} onClick={() => set("working_days", r.working_days.includes(i + 1) ? r.working_days.filter(x => x !== i + 1) : [...r.working_days, i + 1].sort())} className={clsx("px-2 py-0.5 rounded-full text-xs font-semibold border", r.working_days.includes(i + 1) ? "bg-brand-600 text-white border-brand-600" : "bg-white text-surface-500 border-line")}>{d}</button>)}
                    <span className="text-xs text-surface-500">. Other days are rest days; hours on them are paid at the rest-day rate.</span></Row>
            </Section>

            <Section title="Clock-in">
                <Row><span>Someone with no shift on the roster today</span>
                    <select className="input w-auto px-2 py-1 text-sm" disabled={!canEdit} value={r.clock_in_without_shift} onChange={e => set("clock_in_without_shift", e.target.value as AttendanceRules["clock_in_without_shift"])}>
                        <option value="allow">can clock in</option><option value="flag">can clock in, and the punch is noted for the manager</option><option value="refuse">cannot clock in</option>
                    </select>.</Row>
                <Row><span>With a rostered shift, clock-in opens</span><Num k="earliest_clock_in_minutes" min={0} max={240} unit="minutes before it starts" off="no limit" />.</Row>
                <Row><label className="flex items-center gap-2"><input type="checkbox" disabled={!canEdit} checked={r.remind_clock_in} onChange={e => set("remind_clock_in", e.target.checked)} />Nudge a person not clocked in 5 minutes after their shift starts.</label></Row>
                <Row><label className="flex items-center gap-2"><input type="checkbox" disabled={!canEdit} checked={r.remind_clock_out} onChange={e => set("remind_clock_out", e.target.checked)} />Nudge a person still clocked in when their shift ends.</label></Row>
                <p className="text-xs text-surface-500">A fixed device such as the shop computer or a kiosk tablet may punch without a position once it is marked "location-free" under Devices.</p>
            </Section>

            <Section title="Limits and automatic clock-out">
                <Row><span>Tell the manager when someone has worked</span><Num k="daily_limit_hours" min={0} max={24} unit="hours in a day" off="off" />.</Row>
                <Row><span>Close a shift still open at</span><input type="time" className="input w-auto px-2 py-1 text-sm" disabled={!canEdit} value={r.auto_clock_out_time} onChange={e => set("auto_clock_out_time", e.target.value)} /><span>or after</span><Num k="auto_clock_out_after_hours" min={0} max={24} unit="hours" off="no limit" /><span>, whichever comes first. The punch is marked for the manager.</span></Row>
            </Section>

            <Section title="Timesheet marks">
                <Row><span>Mark a day amber when worked is</span><Num k="over_scheduled_minutes" min={0} max={600} unit="minutes more than rostered" off="off" /><span>or</span><Num k="under_scheduled_minutes" min={0} max={600} unit="minutes less" off="off" />.</Row>
                <p className="text-xs text-surface-500">Rounding, the overtime thresholds and the unpaid lunch are per pay group.</p>
            </Section>

            <Section title="Location on shift">
                <Row><span>The store app records position</span>
                    <select className="input w-auto px-2 py-1 text-sm" disabled={!canEdit} value={r.location_mode} onChange={e => set("location_mode", e.target.value as AttendanceRules["location_mode"])}>
                        <option value="off">never</option><option value="punch">at clock-in and clock-out only</option><option value="trail">while clocked in (a trail)</option>
                    </select>.</Row>
                {r.location_mode === "trail" && <>
                    <Row><span>A point every</span><Num k="trail_interval_minutes" min={1} max={60} unit="minutes" /><span>or on moving</span><Num k="trail_distance_meters" min={10} max={2000} unit="m" />.</Row>
                    <Row><span>Someone further than the clock-in zone plus</span><Num k="leave_site_distance_meters" min={0} max={5000} unit="m" /><span>for</span><Num k="leave_site_grace_minutes" min={1} max={120} unit="minutes" /><span>is</span>
                        <select className="input w-auto px-2 py-1 text-sm" disabled={!canEdit} value={r.leave_site_action} onChange={e => set("leave_site_action", e.target.value as AttendanceRules["leave_site_action"])}>
                            <option value="none">left alone</option><option value="flag">noted for the manager</option><option value="clock_out">clocked out at the moment they left</option>
                        </select>. They are told first and can clock out themselves.</Row>
                    <Row><span>Trails are deleted after</span><Num k="trail_retention_days" min={7} max={365} unit="days" />.</Row>
                </>}
                <p className="text-xs text-surface-500">Nothing is recorded off shift or from the web app. The <Link to="/privacy" className="text-brand-600 underline">privacy page</Link> says the same to staff; keep the two in step.</p>
            </Section>
        </div>
    );
}
