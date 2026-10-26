import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { timeClockApi } from "@/api/attendance";
import { peopleC1Api } from "@/api/people-c1";
import { usePermissions } from "@/hooks/usePermissions";
import { TeamNow } from "@/pages/people/OverviewPage";

/**
 * Home dashboard, People subtitles (C1): "Your day" for everyone, "Your team
 * now" and "Needs you" for a manager. Small, above the money cards.
 */
export function PeopleDayBlock() {
    const { can } = usePermissions();
    const teamView = can("attendance.view_team");
    const { data: status } = useQuery({ queryKey: ["time-clock-status"], queryFn: () => timeClockApi.status(), refetchInterval: 60_000 });
    const { data: o } = useQuery({ queryKey: ["people-overview"], queryFn: () => peopleC1Api.overview(), enabled: teamView, refetchInterval: 60_000 });
    const active = status?.active_entry ?? null;
    const t = (iso: string) => new Date(iso).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
    return (
        <div className={clsx("grid gap-4", teamView ? "grid-cols-1 lg:grid-cols-3" : "grid-cols-1")}>
            <div className="card">
                <div className="card-header"><h3 className="font-semibold text-sm text-surface-900">Your day</h3></div>
                <div className="card-body flex flex-wrap items-center gap-3 text-sm">
                    <Link to="/me/clock" className={clsx("rounded-xl px-3 py-2 font-medium", active ? "bg-success-light text-success" : "bg-surface-100 text-surface-700")}>{active ? `Clocked in since ${t(active.clock_in_at)}` : "Not clocked in"}</Link>
                    <span className="text-surface-500">{status?.today_shift ? `Shift ${status.today_shift.starts_at}–${status.today_shift.ends_at}` : "No shift rostered today"}</span>
                    <Link to="/me" className="ml-auto text-xs text-brand-600 hover:underline">Everything that is mine ›</Link>
                </div>
            </div>
            {teamView && o && (
                <>
                    <div className="card">
                        <div className="card-header flex items-center justify-between"><h3 className="font-semibold text-sm text-surface-900">Your team now</h3><Link to="/people/overview" className="text-xs text-brand-600 hover:underline">Overview</Link></div>
                        <div className="card-body max-h-56 overflow-y-auto"><TeamNow o={o} compact /></div>
                    </div>
                    <div className="card">
                        <div className="card-header flex items-center justify-between"><h3 className="font-semibold text-sm text-surface-900">Needs you</h3><Link to="/people/requests" className="text-xs text-brand-600 hover:underline">{o.requests.count ? `All ${o.requests.count}` : "Requests"}</Link></div>
                        <div className="card-body">
                            {o.requests.items.length === 0 ? <p className="text-sm text-surface-500">Nothing waiting on you.</p> : (
                                <ul className="space-y-1.5">{o.requests.items.slice(0, 5).map(it => <li key={`${it.kind}-${it.id}`} className="text-sm truncate"><Link to={it.href} className="hover:underline">{it.person} · {it.summary}</Link></li>)}</ul>
                            )}
                            {o.period && <p className="mt-2 text-2xs text-surface-400">Pay period ends {o.period.ends_on} · {o.period.approved}/{o.period.people} timesheets approved</p>}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
