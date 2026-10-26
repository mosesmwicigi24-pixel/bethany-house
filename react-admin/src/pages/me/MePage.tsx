import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { timeClockApi } from "@/api/attendance";
import { leaveApi } from "@/api/leave";
import { peopleC1Api } from "@/api/people-c1";
import { useAuthStore } from "@/store/auth.store";
import { usePermissions } from "@/hooks/usePermissions";
import { TeamNow } from "@/pages/people/OverviewPage";

/** Me (C1): the phone's home for a staff member. Today first, then everything that is theirs. A manager also sees the team now. */
export default function MePage() {
    const user = useAuthStore(s => s.user);
    const { can } = usePermissions();
    const { data: status } = useQuery({ queryKey: ["time-clock-status"], queryFn: () => timeClockApi.status(), refetchInterval: 60_000 });
    const { data: leave } = useQuery({ queryKey: ["my-leave"], queryFn: () => leaveApi.mine(), staleTime: 300_000 });
    const teamView = can("attendance.view_team");
    const { data: overview } = useQuery({ queryKey: ["people-overview"], queryFn: () => peopleC1Api.overview(), enabled: teamView, refetchInterval: 60_000 });
    const active = status?.active_entry ?? null;
    const annual = leave?.balances.find(b => b.type.code === "annual") ?? leave?.balances.find(b => b.available !== null);
    const hm = (m: number) => `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, "0")}m`;
    const items: { to: string; label: string; hint?: string }[] = [
        { to: "/me/shifts", label: "My shifts", hint: status?.today_shift ? `today ${status.today_shift.starts_at}–${status.today_shift.ends_at}` : "no shift today" },
        { to: "/me/leave", label: "My leave", hint: annual && annual.available !== null ? `${annual.available} days left` : undefined },
        { to: "/me/timesheet", label: "My hours", hint: status ? `${hm(status.week_worked_minutes)} this week` : undefined },
        { to: "/me/payslips", label: "My pay", hint: "payslips" },
        { to: "/me/advances", label: "Advances & loans" },
        { to: "/profile", label: "My file", hint: "contact, documents, identifiers" },
    ];
    return (
        <div className="mx-auto w-full max-w-lg px-4 py-4 sm:py-8 space-y-4">
            <div>
                <p className="text-xs font-semibold uppercase tracking-wider text-surface-400">{new Date().toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long" })}</p>
                <h1 className="text-xl font-bold text-surface-900">Hi {user?.first_name ?? "there"}</h1>
            </div>
            <Link to="/me/clock" className={clsx("block rounded-2xl p-4 shadow-sm border", active ? "bg-success-light border-success/30" : "bg-white border-line")}>
                <p className="text-2xs font-semibold uppercase tracking-wider text-surface-400">Today</p>
                <p className="text-lg font-bold text-surface-900">{active ? `Clocked in since ${new Date(active.clock_in_at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" })}${active.on_break ? " · on break" : ""}` : "Not clocked in"}</p>
                <p className="text-xs text-surface-500">{active ? `${hm(active.elapsed_minutes)} so far · tap to clock out` : status?.today_shift ? `Shift ${status.today_shift.starts_at}–${status.today_shift.ends_at} · tap to clock in` : "Tap to clock in"}</p>
            </Link>
            <ul className="rounded-2xl bg-white border border-line shadow-sm divide-y divide-line">
                {items.map(it => (
                    <li key={it.to}><Link to={it.to} className="flex items-center justify-between px-4 py-3 text-sm"><span className="font-medium text-surface-900">{it.label}</span><span className="text-xs text-surface-400">{it.hint ?? ""} ›</span></Link></li>
                ))}
            </ul>
            {teamView && overview && (
                <div className="rounded-2xl bg-white border border-line shadow-sm p-4 space-y-3">
                    <div className="flex items-center justify-between"><p className="text-sm font-semibold text-surface-900">Your team now</p><Link to="/people/overview" className="text-xs text-brand-600">Overview ›</Link></div>
                    <TeamNow o={overview} compact />
                    {overview.requests.count > 0 && <Link to="/people/requests" className="block rounded-xl bg-warning-light text-warning text-sm px-3 py-2 font-medium">{overview.requests.count} request{overview.requests.count > 1 ? "s" : ""} waiting on you ›</Link>}
                </div>
            )}
        </div>
    );
}
