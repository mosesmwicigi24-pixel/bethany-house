import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { timeClockApi } from "@/api/attendance";
import { leaveApi } from "@/api/leave";
import { peopleC1Api } from "@/api/people-c1";
import { updatesApi } from "@/api/updates";
import { formsApi } from "@/api/forms";
import { signingApi } from "@/api/signing";
import { coursesApi } from "@/api/courses";
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
    const { data: feed } = useQuery({ queryKey: ["updates", "", 1], queryFn: () => updatesApi.feed(1), staleTime: 60_000 });
    const { data: forms } = useQuery({ queryKey: ["forms-today", ""], queryFn: () => formsApi.today(), staleTime: 60_000 });
    const formsDue = (forms?.data ?? []).filter(r => r.template.kind === "checklist" && !["submitted", "late"].includes(r.state));
    const { data: signing } = useQuery({ queryKey: ["my-signing"], queryFn: () => signingApi.mine(), staleTime: 60_000 });
    const { data: courses } = useQuery({ queryKey: ["courses"], queryFn: () => coursesApi.list(), staleTime: 60_000 });
    const active = status?.active_entry ?? null;
    const annual = leave?.balances.find(b => b.type.code === "annual") ?? leave?.balances.find(b => b.available !== null);
    const hm = (m: number) => `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, "0")}m`;
    const items: { to: string; label: string; hint?: string }[] = [
        { to: "/me/shifts", label: "My shifts", hint: status?.today_shift ? `today ${status.today_shift.starts_at}–${status.today_shift.ends_at}` : "no shift today" },
        { to: "/me/leave", label: "My leave", hint: annual && annual.available !== null ? `${annual.available} days left` : undefined },
        { to: "/me/timesheet", label: "My hours", hint: status ? `${hm(status.week_worked_minutes)} this week` : undefined },
        { to: "/me/payslips", label: "My pay", hint: "payslips" },
        { to: "/forms", label: "Checklists & forms", hint: formsDue.length ? `${formsDue.length} to do` : forms ? "all done" : undefined },
        { to: "/handbook", label: "Handbook", hint: "policies, how-tos" },
        { to: "/courses", label: "Training", hint: courses ? (courses.to_do ? `${courses.to_do} to complete` : "all done") : undefined },
        ...(signing && signing.pending > 0 ? [{ to: "/me/sign", label: "To sign", hint: `${signing.pending} waiting` }] : []),
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
            {feed && (
                <Link to="/updates" className={clsx("block rounded-2xl p-4 shadow-sm border", feed.must_read_pending > 0 ? "bg-danger/5 border-danger/30" : "bg-white border-line")}>
                    <div className="flex items-center justify-between"><p className="text-sm font-semibold text-surface-900">Updates</p><span className="text-xs text-surface-400">{feed.must_read_pending > 0 ? <span className="text-danger font-semibold">{feed.must_read_pending} must-read{feed.must_read_pending > 1 ? "s" : ""} waiting</span> : feed.data.filter(p => !p.me.read_at).length > 0 ? `${feed.data.filter(p => !p.me.read_at).length} new` : "all read"} ›</span></div>
                    {feed.data.slice(0, 3).map(p => <p key={p.id} className={clsx("text-sm truncate mt-1", p.me.read_at ? "text-surface-500" : "text-surface-800 font-medium")}>{p.kind === "recognition" ? `⭐ ${p.author?.name ?? ""} recognised ${p.recipient?.name ?? ""}` : p.kind === "celebration" ? `🎉 ${p.title}` : p.kind === "event" ? `📅 ${p.title}` : `📣 ${p.title}`}</p>)}
                    {feed.data.length === 0 && <p className="text-sm text-surface-500 mt-1">Nothing posted yet.</p>}
                </Link>
            )}
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
