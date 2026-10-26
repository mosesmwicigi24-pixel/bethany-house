import { clsx } from "clsx";
import { hm, type TimesheetDetail } from "@/api/timesheets";

/** The day-by-day table of a timesheet (People PR 5), shared by the manager's drawer and the person's phone view. */
export function TimesheetTotals({ t, compact = false }: { t: TimesheetDetail["totals"]; compact?: boolean }) {
    const cells: [string, string][] = [
        ["Regular", hm(t.regular_minutes)], ["Overtime", hm(t.overtime_minutes)], ["Rest days", hm(t.rest_day_minutes)], ["Holidays", hm(t.holiday_minutes)],
        ["Paid leave", `${t.paid_leave_days} d`], ["Unpaid leave", `${t.unpaid_leave_days} d`], ["Days worked", String(t.days_worked)],
    ];
    return (
        <dl className={clsx("grid gap-2", compact ? "grid-cols-2" : "grid-cols-2 sm:grid-cols-4 lg:grid-cols-7")}>
            {cells.map(([k, v]) => (
                <div key={k} className="rounded-xl bg-surface-50 px-3 py-2">
                    <dt className="text-2xs font-semibold uppercase tracking-wider text-surface-400">{k}</dt>
                    <dd className="text-base font-bold tabular-nums text-surface-900">{v}</dd>
                </div>
            ))}
        </dl>
    );
}

export function TimesheetDays({ d }: { d: TimesheetDetail }) {
    const fmt = (iso: string) => new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { weekday: "short", day: "numeric" });
    return (
        <div className="space-y-3">
            {d.flags.length > 0 && (
                <div className="rounded-xl bg-danger-light text-danger text-xs px-3 py-2">
                    {d.flags.length} punch{d.flags.length === 1 ? "" : "es"} need attention before approval: {d.flags.map(f => `${f.date} (${f.why})`).join("; ")}
                </div>
            )}
            {d.absences.length > 0 && (
                <div className="rounded-xl bg-warning-light text-warning text-xs px-3 py-2">
                    Rostered but no punch: {d.absences.map(a => `${a.date} ${a.shift}`).join(", ")}
                </div>
            )}
            <ul className="divide-y divide-line rounded-xl border border-line overflow-hidden">
                {d.days.map(day => {
                    const idle = day.worked_minutes === 0 && !day.leave;
                    return (
                        <li key={day.date} className={clsx("flex items-center gap-3 px-3 py-2 text-sm", day.class === "rest" && "bg-surface-50", day.class === "holiday" && "bg-warning-light/40", idle && "text-surface-400")}>
                            <span className="w-16 shrink-0 tabular-nums font-medium">{fmt(day.date)}</span>
                            <span className="flex-1 min-w-0 truncate">
                                {day.holiday ? <span className="text-warning font-semibold">{day.holiday}</span> : day.leave ? <span className="text-surface-600">{day.leave}</span>
                                    : day.punches.length ? day.punches.map(p => `${p.in}–${p.out ?? "…"}${p.status === "flagged" ? " ⚠" : p.status === "active" ? " (open)" : ""}`).join(", ")
                                    : day.rostered.length ? <span className="text-warning">rostered {day.rostered.join(", ")}, no punch</span> : day.class === "rest" ? "rest day" : ""}
                            </span>
                            <span className="w-16 text-right tabular-nums">{day.worked_minutes ? hm(day.worked_minutes) : ""}</span>
                            <span className="w-20 text-right text-2xs tabular-nums text-surface-500">
                                {day.overtime_minutes ? `OT ${hm(day.overtime_minutes)}` : day.rest_day_minutes ? "rest ×" : day.holiday_minutes ? "holiday ×" : ""}
                            </span>
                        </li>
                    );
                })}
            </ul>
            {d.group && (
                <p className="text-2xs text-surface-400">
                    {d.group.name}: {d.group.overtime_after_daily_hours ? `overtime after ${d.group.overtime_after_daily_hours} h a day` : ""}{d.group.overtime_after_weekly_hours ? `${d.group.overtime_after_daily_hours ? ", " : "overtime "}after ${d.group.overtime_after_weekly_hours} h a week` : ""} at ×{d.group.overtime_multiplier}; rest days ×{d.group.rest_day_multiplier}; holidays ×{d.group.holiday_multiplier}{d.group.round_to_minutes > 1 ? `; punches rounded to ${d.group.round_to_minutes} min` : ""}. {d.snapshot ? "Figures as approved." : "Live figures until approved."}
                </p>
            )}
        </div>
    );
}
