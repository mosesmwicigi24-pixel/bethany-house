import { get, post, put, del } from "./client";

/** People C1: attendance rules, requests queue, overview, trails, fix requests. */

export interface AttendanceRules {
    working_days: number[];
    auto_clock_out_time: string;
    auto_clock_out_after_hours: number;
    daily_limit_hours: number;
    over_scheduled_minutes: number;
    under_scheduled_minutes: number;
    clock_in_without_shift: "allow" | "flag" | "refuse";
    earliest_clock_in_minutes: number;
    remind_clock_in: boolean;
    remind_clock_out: boolean;
    location_mode: "off" | "punch" | "trail";
    trail_interval_minutes: number;
    trail_distance_meters: number;
    leave_site_action: "none" | "flag" | "clock_out";
    leave_site_grace_minutes: number;
    leave_site_distance_meters: number;
    trail_retention_days: number;
}

export interface RequestItem {
    kind: "leave" | "swap" | "advance" | "fix";
    id: number;
    person: string;
    summary: string;
    note: string | null;
    asked_at: string | null;
    href: string;
}

export interface Person { id: number; name: string; avatar_url: string | null }

export interface Overview {
    now: {
        in: { person: Person; since: string; on_break: boolean; flags: string[]; outlet: string | null; left_site_at: string | null; entry_id: number }[];
        in_count: number; on_break: number;
        late: { person: Person; shift: string; outlet: string | null; shift_id: number; minutes_late: number }[];
        absent: { person: Person; shift: string; outlet: string | null; shift_id: number }[];
        expected: { person: Person; shift: string; outlet: string | null; shift_id: number }[];
        on_leave: { person: Person; type: string | null; until: string }[];
        people: number;
    };
    week: { open_shifts: number; flagged_punches: number };
    period: { id: number; starts_on: string; ends_on: string; status: string; days_left: number; approved: number; submitted: number; people: number } | null;
    requests: { count: number; counts: Record<string, number>; items: RequestItem[] };
    rules: { location_mode: string; clock_in_without_shift: string };
    server_time: string;
}

export interface TrailPoint { latitude: number; longitude: number; accuracy: number | null; distance: number | null; mocked: boolean; at: string }
export interface Trail {
    outlet: { name: string; latitude: number | null; longitude: number | null; radius: number | null } | null;
    clock_in: { latitude: number; longitude: number; at: string } | null;
    clock_out: { latitude: number; longitude: number; at: string } | null;
    left_site_at: string | null;
    points: TrailPoint[];
    retention_days: number;
}

export interface FixRequest { id: number; date: string; message: string; status: "requested" | "approved" | "declined"; decided_at: string | null; decision_note: string | null; decided_by: string | null; created_at: string | null }

export const peopleC1Api = {
    rules: () => get<{ rules: AttendanceRules }>("/v1/admin/attendance/rules"),
    saveRules: (data: Partial<AttendanceRules>) => put<{ message: string; rules: AttendanceRules }>("/v1/admin/attendance/rules", data),
    requests: () => get<{ data: RequestItem[]; count: number; counts: Record<string, number> }>("/v1/admin/people/requests"),
    requestsCount: () => get<{ count: number }>("/v1/admin/people/requests/count"),
    decideFix: (id: number, decision: "approve" | "decline", note?: string) => post<{ message: string; fix: FixRequest }>(`/v1/admin/people/requests/fixes/${id}/${decision}`, { note }),
    overview: () => get<Overview>("/v1/admin/people/overview"),
    trail: (entryId: number) => get<Trail>(`/v1/admin/attendance/entries/${entryId}/trail`),
    myFixes: () => get<{ data: FixRequest[] }>("/v1/admin/me/timesheet/fixes"),
    requestFix: (date: string, message: string) => post<{ message: string; fix: FixRequest }>("/v1/admin/me/timesheet/fixes", { date, message }),
    cancelFix: (id: number) => del<{ message: string }>(`/v1/admin/me/timesheet/fixes/${id}`),
};

export const REQUEST_LABEL: Record<RequestItem["kind"], string> = { leave: "Leave", swap: "Shift swap", advance: "Advance", fix: "Timesheet fix" };
