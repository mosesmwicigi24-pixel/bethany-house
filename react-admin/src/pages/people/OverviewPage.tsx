import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { clsx } from "clsx";
import { peopleC1Api, REQUEST_LABEL, type Overview, type Person } from "@/api/people-c1";
import { Spinner } from "@/components/ui/Spinner";

/** People › Overview (C1): the team's day. Who is in, who is late or missing, who is off, what waits on you, where the pay period stands. */
function Avatar({ p, size = "w-7 h-7" }: { p: Person; size?: string }) {
    const initials = p.name.split(" ").map(s => s[0]).filter(Boolean).slice(0, 2).join("").toUpperCase();
    return p.avatar_url ? <img src={p.avatar_url} alt="" className={clsx(size, "rounded-full object-cover shrink-0")} /> : <span className={clsx(size, "rounded-full bg-brand-100 text-brand-700 text-2xs font-bold flex items-center justify-center shrink-0")}>{initials}</span>;
}

export function TeamNow({ o, compact = false }: { o: Overview; compact?: boolean }) {
    const t = (iso: string) => new Date(iso).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
    const Group = ({ title, tone, children, count }: { title: string; tone: string; children: React.ReactNode; count: number }) => count === 0 ? null : (
        <div className="space-y-1">
            <p className={clsx("text-2xs font-semibold uppercase tracking-wider", tone)}>{title} · {count}</p>
            <ul className="space-y-1">{children}</ul>
        </div>
    );
    return (
        <div className={clsx("grid gap-4", compact ? "grid-cols-1" : "grid-cols-1 md:grid-cols-2")}>
            <Group title="In now" tone="text-success" count={o.now.in_count}>
                {o.now.in.map(x => <li key={x.entry_id} className="flex items-center gap-2 text-sm"><Avatar p={x.person} /><span className="flex-1 min-w-0 truncate">{x.person.name}<span className="text-surface-400"> · since {t(x.since)}{x.outlet ? ` · ${x.outlet}` : ""}</span></span>{x.on_break && <span className="text-2xs px-1.5 rounded-full bg-surface-100 text-surface-600">break</span>}{x.left_site_at && <span className="text-2xs px-1.5 rounded-full bg-warning-light text-warning">away since {t(x.left_site_at)}</span>}{x.flags.length > 0 && !x.left_site_at && <span className="text-2xs px-1.5 rounded-full bg-warning-light text-warning">{x.flags.length} note{x.flags.length > 1 ? "s" : ""}</span>}</li>)}
            </Group>
            <Group title="Late" tone="text-danger" count={o.now.late.length}>
                {o.now.late.map(x => <li key={x.shift_id} className="flex items-center gap-2 text-sm"><Avatar p={x.person} /><span className="flex-1 min-w-0 truncate">{x.person.name}<span className="text-surface-400"> · shift {x.shift}</span></span><span className="text-2xs text-danger font-semibold">{x.minutes_late} min</span></li>)}
            </Group>
            <Group title="Did not show" tone="text-danger" count={o.now.absent.length}>
                {o.now.absent.map(x => <li key={x.shift_id} className="flex items-center gap-2 text-sm"><Avatar p={x.person} /><span className="flex-1 min-w-0 truncate">{x.person.name}<span className="text-surface-400"> · shift {x.shift}</span></span></li>)}
            </Group>
            <Group title="Due later" tone="text-surface-500" count={o.now.expected.length}>
                {o.now.expected.map(x => <li key={x.shift_id} className="flex items-center gap-2 text-sm"><Avatar p={x.person} /><span className="flex-1 min-w-0 truncate">{x.person.name}<span className="text-surface-400"> · {x.shift}</span></span></li>)}
            </Group>
            <Group title="On leave" tone="text-info" count={o.now.on_leave.length}>
                {o.now.on_leave.map((x, i) => <li key={i} className="flex items-center gap-2 text-sm"><Avatar p={x.person} /><span className="flex-1 min-w-0 truncate">{x.person.name}<span className="text-surface-400"> · {x.type ?? "leave"} until {x.until}</span></span></li>)}
            </Group>
            {o.now.in_count + o.now.late.length + o.now.absent.length + o.now.expected.length + o.now.on_leave.length === 0 && <p className="text-sm text-surface-500">Nobody is clocked in and nothing is rostered today.</p>}
        </div>
    );
}

export default function OverviewPage() {
    const { data: o, isLoading } = useQuery({ queryKey: ["people-overview"], queryFn: () => peopleC1Api.overview(), refetchInterval: 30_000 });
    if (isLoading || !o) return <div className="flex items-center justify-center h-64"><Spinner size="lg" /></div>;
    const Stat = ({ label, value, to, tone }: { label: string; value: string | number; to: string; tone?: string }) => (
        <Link to={to} className="rounded-xl border border-line bg-white px-3 py-2 hover:bg-surface-50">
            <p className="text-2xs font-semibold uppercase tracking-wider text-surface-400">{label}</p>
            <p className={clsx("text-lg font-bold tabular-nums", tone ?? "text-surface-900")}>{value}</p>
        </Link>
    );
    return (
        <div className="space-y-5 animate-fade-in">
            <div>
                <h1 className="page-title">Overview</h1>
                <p className="page-subtitle">Your team today. {o.now.people} people in your outlets.</p>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
                <Stat label="In now" value={o.now.in_count} to="/settings/attendance" tone="text-success" />
                <Stat label="Late" value={o.now.late.length} to="/roster" tone={o.now.late.length ? "text-danger" : undefined} />
                <Stat label="Did not show" value={o.now.absent.length} to="/roster" tone={o.now.absent.length ? "text-danger" : undefined} />
                <Stat label="Waiting on you" value={o.requests.count} to="/people/requests" tone={o.requests.count ? "text-warning" : undefined} />
                <Stat label="Open shifts this week" value={o.week.open_shifts} to="/roster" />
                <Stat label="Flagged punches (14 d)" value={o.week.flagged_punches} to="/settings/attendance?status=flagged" tone={o.week.flagged_punches ? "text-warning" : undefined} />
            </div>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
                <div className="card lg:col-span-2"><div className="card-header"><h3 className="font-semibold text-sm text-surface-900">Your team now</h3></div><div className="card-body"><TeamNow o={o} /></div></div>
                <div className="space-y-5">
                    <div className="card">
                        <div className="card-header flex items-center justify-between"><h3 className="font-semibold text-sm text-surface-900">Needs you</h3><Link to="/people/requests" className="text-xs text-brand-600 hover:underline">All requests</Link></div>
                        <div className="card-body">
                            {o.requests.items.length === 0 ? <p className="text-sm text-surface-500">Nothing waiting.</p> : (
                                <ul className="space-y-2">{o.requests.items.map(it => <li key={`${it.kind}-${it.id}`} className="text-sm"><Link to={it.href} className="hover:underline"><span className="text-2xs font-semibold text-surface-400 uppercase mr-1">{REQUEST_LABEL[it.kind]}</span>{it.person} · {it.summary}</Link></li>)}</ul>
                            )}
                        </div>
                    </div>
                    {o.period && (
                        <div className="card">
                            <div className="card-header flex items-center justify-between"><h3 className="font-semibold text-sm text-surface-900">Pay period</h3><Link to="/timesheets" className="text-xs text-brand-600 hover:underline">Timesheets</Link></div>
                            <div className="card-body text-sm text-surface-700 space-y-1">
                                <p>{o.period.starts_on} to {o.period.ends_on} · <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", o.period.status === "locked" ? "bg-success-light text-success" : "bg-surface-100 text-surface-600")}>{o.period.status}</span></p>
                                <p>{o.period.days_left} day{o.period.days_left === 1 ? "" : "s"} left · {o.period.approved} of {o.period.people} approved, {o.period.submitted} submitted</p>
                            </div>
                        </div>
                    )}
                    <p className="text-2xs text-surface-400">Location on shift: {o.rules.location_mode === "trail" ? "trail while clocked in" : o.rules.location_mode === "punch" ? "punches only" : "off"}. Clock-in without a shift: {o.rules.clock_in_without_shift}. <Link to="/settings/attendance-rules" className="text-brand-600 underline">Rules</Link></p>
                </div>
            </div>
        </div>
    );
}
