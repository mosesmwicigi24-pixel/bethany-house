import { useEffect, useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { Link } from "react-router-dom";
import { timeClockApi, type TimeClockOutlet, type TimeEntry, type Punch } from "@/api/attendance";
import { rosterApi } from "@/api/roster";
import { useGeolocation, distanceMeters } from "@/lib/useGeolocation";
import { readPendingPunch, savePendingPunch, clearPendingPunch, type PendingPunch } from "@/lib/pendingPunch";
import { usePermissions } from "@/hooks/usePermissions";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

/**
 * Clock in on the phone (Bethany People PR 1, owner 24 Oct 2026).
 *
 * One screen, one big button. The phone reports where it is and how sure it
 * is; the server decides inside or outside the outlet's zone and keeps the
 * evidence. The screen shows the distance live so nobody is surprised by a
 * refusal, and explains every state in words (locating, location off,
 * outside, offline) rather than greying the button silently.
 *
 * Location is read only while this screen is open. Nothing follows anyone.
 */

const APP_VERSION = (import.meta.env.VITE_APP_VERSION as string | undefined) ?? "pwa";

function fmtClock(d: Date) {
    return d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
}
function fmtMinutes(min: number | null | undefined) {
    if (min === null || min === undefined) return "—";
    const h = Math.floor(min / 60), m = min % 60;
    return h > 0 ? `${h} h ${m} m` : `${m} m`;
}
function fmtWhen(iso: string) {
    const d = new Date(iso);
    const today = new Date();
    const sameDay = d.toDateString() === today.toDateString();
    const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
    const day = sameDay ? "Today" : d.toDateString() === yesterday.toDateString() ? "Yesterday"
        : d.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
    return `${day} ${fmtClock(d)}`;
}

const FLAG_SHORT: Record<string, string> = {
    accuracy_low: "imprecise", edge: "near the edge", sent_late: "sent late", mock_location: "mock location", auto_clock_out: "auto closed",
};

function FlagChips({ entry }: { entry: TimeEntry }) {
    if (!entry.flags?.length) return null;
    return (
        <span className="inline-flex flex-wrap gap-1">
            {entry.flags.map(f => (
                <span key={f} className="text-2xs font-semibold px-1.5 py-0.5 rounded-full bg-warning-light text-warning" title={FLAG_SHORT[f]}>
                    {FLAG_SHORT[f] ?? f}
                </span>
            ))}
        </span>
    );
}

export default function ClockPage() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const { can } = usePermissions();
    const canOverride = can("attendance.manage") || can("outlets.all_access");

    const { data, isLoading, refetch } = useQuery({ queryKey: ["time-clock-status"], queryFn: () => timeClockApi.status(), refetchInterval: 60_000 });
    const { data: mine } = useQuery({ queryKey: ["my-shifts"], queryFn: () => rosterApi.mine(), staleTime: 60_000 });
    const active = data?.active_entry ?? null;

    // The wall clock ticks every second so "clocked in 2 h 14 m" moves.
    const [now, setNow] = useState(() => new Date());
    useEffect(() => { const t = setInterval(() => setNow(new Date()), 1000); return () => clearInterval(t); }, []);

    const { state: geo, retry } = useGeolocation(true);
    const fix = geo.status === "ok" ? geo.fix : null;

    // Which outlet: the active entry's, else the nearest, else the only one.
    const outlets = data?.outlets ?? [];
    const [chosenId, setChosenId] = useState<number | null>(null);
    const outlet: TimeClockOutlet | null = useMemo(() => {
        if (active?.outlet) return outlets.find(o => o.id === active.outlet!.id) ?? null;
        if (chosenId) return outlets.find(o => o.id === chosenId) ?? null;
        if (!outlets.length) return null;
        if (fix) {
            const withCoords = outlets.filter(o => o.latitude !== null && o.longitude !== null);
            if (withCoords.length) {
                return withCoords.reduce((best, o) => {
                    const d = distanceMeters(fix.latitude, fix.longitude, Number(o.latitude), Number(o.longitude));
                    const bd = distanceMeters(fix.latitude, fix.longitude, Number(best.latitude), Number(best.longitude));
                    return d < bd ? o : best;
                });
            }
        }
        return outlets[0];
    }, [outlets, active, chosenId, fix]);

    const distance = fix && outlet && outlet.latitude !== null && outlet.longitude !== null
        ? distanceMeters(fix.latitude, fix.longitude, Number(outlet.latitude), Number(outlet.longitude)) : null;
    const radius = outlet?.geofence_radius_meters ?? null;
    // Mirrors the server: inside, or just outside within the phone's uncertainty (capped at the radius).
    const inside = distance !== null && radius !== null ? distance <= radius : true;
    const nearEdge = !inside && distance !== null && radius !== null && fix !== null && distance <= radius + Math.min(fix.accuracy, radius);

    const [pending, setPending] = useState<PendingPunch | null>(() => readPendingPunch());
    const [reason, setReason] = useState("");
    const [askReason, setAskReason] = useState(false);

    const punchOf = (f: typeof fix): Punch => ({ latitude: f!.latitude, longitude: f!.longitude, accuracy: f!.accuracy });

    const keepForLater = (kind: "in" | "out", punch: Punch, outlet_id?: number) => {
        const p: PendingPunch = { kind, outlet_id, punch, recorded_at: new Date().toISOString() };
        savePendingPunch(p); setPending(p);
        toast.success(kind === "in" ? "No signal. Your clock-in is saved and will be sent when you are back online." : "No signal. Your clock-out is saved and will be sent when you are back online.");
    };
    const isNetwork = (e: ApiError) => !e.status;

    const clockIn = useMutation({
        mutationFn: (p: { punch: Punch; force?: boolean; reason?: string; recorded_at?: string }) =>
            timeClockApi.clockIn({ ...p.punch, outlet_id: outlet!.id, app_version: APP_VERSION, force: p.force, reason: p.reason, recorded_at: p.recorded_at }),
        onSuccess: r => { toast.success(r.message); setAskReason(false); setReason(""); qc.invalidateQueries({ queryKey: ["time-clock-status"] }); },
        onError: (e: ApiError, vars) => {
            if (isNetwork(e) && !vars.recorded_at) return keepForLater("in", vars.punch, outlet?.id);
            toast.error(e.message);
        },
    });
    const clockOut = useMutation({
        mutationFn: (p: { punch: Punch; recorded_at?: string }) => timeClockApi.clockOut({ ...p.punch, recorded_at: p.recorded_at }),
        onSuccess: r => { toast.success(r.message); qc.invalidateQueries({ queryKey: ["time-clock-status"] }); },
        onError: (e: ApiError, vars) => {
            if (isNetwork(e) && !vars.recorded_at) return keepForLater("out", vars.punch);
            toast.error(e.message);
        },
    });
    const breakStart = useMutation({ mutationFn: () => timeClockApi.startBreak(), onSuccess: () => refetch(), onError: (e: ApiError) => toast.error(e.message) });
    const breakEnd   = useMutation({ mutationFn: () => timeClockApi.endBreak(),   onSuccess: () => refetch(), onError: (e: ApiError) => toast.error(e.message) });

    // Send a kept punch as soon as we are online with the screen open.
    const sendPending = async () => {
        if (!pending) return;
        try {
            if (pending.kind === "in") {
                await timeClockApi.clockIn({ ...pending.punch, outlet_id: pending.outlet_id ?? outlet!.id, app_version: APP_VERSION, recorded_at: pending.recorded_at });
            } else {
                await timeClockApi.clockOut({ ...pending.punch, recorded_at: pending.recorded_at });
            }
            clearPendingPunch(); setPending(null);
            toast.success(`Your ${pending.kind === "in" ? "clock-in" : "clock-out"} from ${fmtWhen(pending.recorded_at)} has been sent.`);
            qc.invalidateQueries({ queryKey: ["time-clock-status"] });
        } catch (err) {
            const e = err as ApiError;
            if (!isNetwork(e)) { clearPendingPunch(); setPending(null); toast.error(`The saved punch could not be recorded: ${e.message}`); }
        }
    };
    useEffect(() => {
        if (!pending) return;
        if (navigator.onLine) void sendPending();
        const on = () => void sendPending();
        window.addEventListener("online", on);
        return () => window.removeEventListener("online", on);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pending?.recorded_at, outlet?.id]);

    const busy = clockIn.isPending || clockOut.isPending;
    const elapsed = active ? Math.max(0, Math.floor((now.getTime() - new Date(active.clock_in_at).getTime()) / 60000) - (active.total_break_minutes ?? 0)) : 0;

    // ── Words for every state ─────────────────────────────────────────────
    let where: { text: string; tone: "ok" | "warn" | "bad" | "mute" };
    if (geo.status === "unsupported") where = { text: "This browser cannot share location", tone: "bad" };
    else if (geo.status === "denied") where = { text: "Location is off for this site", tone: "bad" };
    else if (geo.status === "unavailable") where = { text: "Location unavailable", tone: "bad" };
    else if (geo.status === "asking" || !fix) where = { text: "Finding you…", tone: "mute" };
    else if (!outlet) where = { text: "No outlet to clock into", tone: "bad" };
    else if (distance === null) where = { text: `${outlet.name} has no location set`, tone: "warn" };
    else if (inside) where = { text: `${Math.round(distance)} m from ${outlet.name}`, tone: "ok" };
    else if (nearEdge) where = { text: `${Math.round(distance)} m away, phone ±${fix.accuracy} m`, tone: "warn" };
    else where = { text: `${Math.round(distance)} m away, outside the ${radius} m zone`, tone: "bad" };

    const canPunch = !!fix && !!outlet && !busy && (active ? true : (inside || nearEdge));
    const showForce = !active && fix && outlet && !inside && !nearEdge && canOverride;

    const onBigButton = () => {
        if (!fix || !outlet) return;
        if (active) clockOut.mutate({ punch: punchOf(fix) });
        else clockIn.mutate({ punch: punchOf(fix) });
    };

    return (
        <div className="mx-auto w-full max-w-md sm:max-w-lg px-4 py-4 sm:py-8">
            {/* Header: date and where you are */}
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-surface-400">{now.toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" })}</p>
                    <h1 className="text-xl font-bold text-surface-900">Time clock</h1>
                </div>
                <div className={clsx("text-xs font-semibold px-2.5 py-1 rounded-full max-w-[60%] text-right",
                    where.tone === "ok" && "bg-success-light text-success", where.tone === "warn" && "bg-warning-light text-warning",
                    where.tone === "bad" && "bg-danger-light text-danger", where.tone === "mute" && "bg-surface-100 text-surface-500")}
                    aria-live="polite">
                    {where.text}
                </div>
            </div>

            {outlets.length > 1 && !active && (
                <label className="block mt-3 text-xs text-surface-500">
                    Outlet
                    <select className="input mt-1" value={outlet?.id ?? ""} onChange={e => setChosenId(Number(e.target.value))} aria-label="Outlet">
                        {outlets.map(o => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                </label>
            )}

            {geo.status === "denied" && (
                <div className="mt-4 rounded-xl bg-danger-light text-danger text-sm px-4 py-3">
                    Clocking in needs your location, only at the moment you press the button. Allow location for this site in your phone's settings, then <button onClick={retry} className="underline font-semibold">try again</button>.
                </div>
            )}
            {geo.status === "unavailable" && (
                <div className="mt-4 rounded-xl bg-warning-light text-warning text-sm px-4 py-3">
                    {geo.message} <button onClick={retry} className="underline font-semibold">Try again</button>
                </div>
            )}

            {pending && (
                <div className="mt-4 rounded-xl bg-info-light text-info text-sm px-4 py-3 flex items-center gap-3">
                    <span className="flex-1">A {pending.kind === "in" ? "clock-in" : "clock-out"} from {fmtWhen(pending.recorded_at)} is waiting to be sent.</span>
                    <button onClick={() => void sendPending()} className="btn-secondary btn-sm">Send now</button>
                </div>
            )}

            {/* The clock and the button */}
            <div className="mt-6 rounded-2xl bg-white border border-line shadow-sm px-4 pt-6 pb-5 text-center">
                <p className="text-5xl font-extrabold tabular-nums text-surface-900 leading-none">{fmtClock(now)}</p>
                <p className="mt-2 text-sm text-surface-500" aria-live="polite">
                    {isLoading ? "Loading…"
                        : active ? (active.on_break ? `On break · clocked in at ${fmtClock(new Date(active.clock_in_at))}` : `Clocked in at ${fmtClock(new Date(active.clock_in_at))} · ${fmtMinutes(elapsed)}`)
                        : "Not clocked in"}
                </p>

                <button
                    type="button"
                    onClick={onBigButton}
                    disabled={!canPunch}
                    aria-label={active ? "Clock out" : "Clock in"}
                    className={clsx(
                        "mx-auto mt-6 w-44 h-44 rounded-full flex flex-col items-center justify-center text-white font-extrabold text-xl tracking-wide transition-transform active:scale-95 disabled:opacity-40 disabled:active:scale-100",
                        active ? "bg-surface-800 shadow-[0_10px_28px_rgba(0,0,0,0.25)]" : "bg-brand-500 shadow-[0_10px_28px_rgba(217,84,30,0.38)]",
                    )}
                >
                    {busy ? <Spinner size="md" className="border-white/30 border-t-white" /> : (
                        <>
                            <span>{active ? "CLOCK OUT" : "CLOCK IN"}</span>
                            <span className="text-xs font-medium opacity-90 mt-1 px-4 leading-tight">
                                {!fix ? "waiting for location" : active ? (outlet?.name ?? "") : inside ? `you are at ${outlet?.name ?? "the outlet"}` : nearEdge ? "near the zone · will be noted" : "outside the zone"}
                            </span>
                        </>
                    )}
                </button>

                {active && (
                    <div className="mt-4 flex justify-center gap-2">
                        {active.on_break
                            ? <button onClick={() => breakEnd.mutate()} disabled={breakEnd.isPending} className="btn-secondary btn-sm">End break</button>
                            : <button onClick={() => breakStart.mutate()} disabled={breakStart.isPending} className="btn-secondary btn-sm">Start break</button>}
                    </div>
                )}

                {showForce && !askReason && (
                    <button onClick={() => setAskReason(true)} className="mt-4 text-xs font-semibold text-brand-600 underline">Clock in anyway (manager override)</button>
                )}
                {showForce && askReason && (
                    <div className="mt-4 text-left">
                        <label className="text-xs text-surface-500">Why are you clocking in from outside the zone? This is written to the audit trail.
                            <input className="input mt-1" value={reason} onChange={e => setReason(e.target.value)} maxLength={255} placeholder="e.g. delivering to a client first" />
                        </label>
                        <div className="mt-2 flex gap-2 justify-end">
                            <button onClick={() => { setAskReason(false); setReason(""); }} className="btn-secondary btn-sm">Cancel</button>
                            <button onClick={() => fix && clockIn.mutate({ punch: punchOf(fix), force: true, reason })} disabled={reason.trim().length < 3 || busy} className="btn-primary btn-sm">Clock in with override</button>
                        </div>
                    </div>
                )}

                {fix && (
                    <p className="mt-4 text-2xs text-surface-400">Phone accuracy ±{fix.accuracy} m{radius ? ` · zone ${radius} m` : ""}. Location is read only while this screen is open.</p>
                )}
            </div>

            {/* Today, this week, last punch */}
            <div className="mt-4 rounded-2xl bg-white border border-line shadow-sm divide-y divide-line text-sm">
                <div className="flex justify-between gap-3 px-4 py-3">
                    <span className="text-surface-500">Today's shift</span>
                    <Link to="/me/shifts" className="text-right font-semibold tabular-nums text-surface-900">
                        {mine?.today ? `${mine.today.starts_at} – ${mine.today.ends_at}${mine.today.label ? ` · ${mine.today.label}` : ""}` : mine?.next ? `Next: ${new Date(mine.next.date + "T00:00:00").toLocaleDateString("en-GB", { weekday: "short" })} ${mine.next.starts_at}` : "None rostered"}
                    </Link>
                </div>
                <div className="flex justify-between px-4 py-3"><span className="text-surface-500">Today</span><span className="font-semibold tabular-nums text-surface-900">{fmtMinutes((data?.today_worked_minutes ?? 0) + (active ? Math.max(0, elapsed - active.elapsed_minutes) : 0))}</span></div>
                <div className="flex justify-between px-4 py-3"><span className="text-surface-500">This week</span><span className="font-semibold tabular-nums text-surface-900">{fmtMinutes(data?.week_worked_minutes ?? 0)}</span></div>
                <div className="flex justify-between gap-3 px-4 py-3">
                    <span className="text-surface-500">Last punch</span>
                    <span className="text-right text-surface-900">
                        {data?.last_entry ? (
                            <>
                                {fmtWhen(data.last_entry.clock_out_at ?? data.last_entry.clock_in_at)} · {data.last_entry.clock_out_at ? "out" : "in"}
                                {data.last_entry.clock_in_method === "override" && <span className="ml-1 text-2xs text-warning font-semibold">override</span>}
                                <span className="ml-1"><FlagChips entry={data.last_entry} /></span>
                            </>
                        ) : "—"}
                    </span>
                </div>
                {data?.rules?.auto_clock_out_time && (
                    <div className="px-4 py-3 text-2xs text-surface-400">A shift still open at {data.rules.auto_clock_out_time} is closed automatically and shown to your manager.</div>
                )}
            </div>

            {/* Today's entries */}
            {!!data?.today_entries?.length && (
                <div className="mt-4">
                    <p className="text-xs font-semibold uppercase tracking-wider text-surface-400 px-1 mb-2">Today's punches</p>
                    <ul className="rounded-2xl bg-white border border-line shadow-sm divide-y divide-line text-sm">
                        {data.today_entries.map(e => (
                            <li key={e.id} className="flex items-center justify-between gap-3 px-4 py-3">
                                <span className="text-surface-900">{fmtClock(new Date(e.clock_in_at))} – {e.clock_out_at ? fmtClock(new Date(e.clock_out_at)) : "…"}</span>
                                <span className="flex items-center gap-2 text-surface-500 tabular-nums">
                                    <FlagChips entry={e} />
                                    {fmtMinutes(e.status === "active" ? elapsed : e.worked_minutes)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
