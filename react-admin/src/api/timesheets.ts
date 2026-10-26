import { get, post, put } from "./client";

/** Timesheets (Bethany People PR 5). */

export interface PayGroup {
    id: number;
    name: string;
    frequency: "monthly" | "weekly";
    standard_hours_per_day: number;
    overtime_after_daily_hours: number | null;
    overtime_after_weekly_hours: number | null;
    overtime_multiplier: number;
    rest_day_multiplier: number;
    holiday_multiplier: number;
    round_to_minutes: number;
    is_default: boolean;
    is_active: boolean;
    /** C1: the period ends on this day of the month (Bethany: 10 → 11th to 10th); null = calendar month. */
    period_end_day?: number | null;
    pay_date_day?: number | null;
    /** C1: {"1": 12, …, "6": 6}; null = standard every day. */
    hours_by_weekday?: Record<string, number> | null;
    unpaid_break_minutes?: number;
    unpaid_break_after_hours?: number | null;
}

export interface PayPeriod {
    id: number;
    pay_group_id: number;
    starts_on: string;
    ends_on: string;
    status: "open" | "locked";
    locked_at: string | null;
    unlock_reason: string | null;
}

export interface Totals {
    regular_minutes: number;
    overtime_minutes: number;
    rest_day_minutes: number;
    holiday_minutes: number;
    worked_minutes: number;
    paid_leave_days: number;
    unpaid_leave_days: number;
    days_worked: number;
}

export type SheetStatus = "draft" | "submitted" | "sent_back" | "approved";

export interface TimesheetDay {
    date: string;
    class: "working" | "rest" | "holiday";
    holiday: string | null;
    worked_minutes: number;
    regular_minutes: number;
    overtime_minutes: number;
    rest_day_minutes: number;
    holiday_minutes: number;
    leave: string | null;
    rostered: string[];
    rostered_minutes?: number;
    vs_scheduled_minutes?: number | null;
    highlight?: "over" | "under" | null;
    unpaid_break_minutes?: number;
    punches: { id: number; in: string; out: string | null; minutes: number; status: string; outlet: string | null; flags: string[] }[];
}

export interface TimesheetDetail {
    person: { id: number; name: string };
    period: PayPeriod;
    status: SheetStatus;
    note: string | null;
    sent_back_note: string | null;
    submitted_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    totals: Totals;
    group: { name: string; overtime_after_daily_hours: number | null; overtime_after_weekly_hours: number | null; overtime_multiplier: number; rest_day_multiplier: number; holiday_multiplier: number; round_to_minutes: number } | null;
    days: TimesheetDay[];
    flags: { date: string; entry_id: number; why: string }[];
    absences: { date: string; shift: string }[];
    snapshot: boolean;
    can_manage?: boolean;
    can_submit?: boolean;
}

export interface TimesheetIndex {
    group: { id: number; name: string; frequency: string };
    groups: { id: number; name: string; frequency: string }[];
    period: PayPeriod;
    rows: { user: { id: number; name: string; job_title: string | null }; timesheet_id: number | null; status: SheetStatus; totals: Totals; flags: number; absences: number; submitted_at: string | null; approved_by: string | null }[];
    summary: { people: number; approved: number; submitted: number; flagged: number };
    can_manage: boolean;
    can_lock: boolean;
}

export const timesheetsApi = {
    index: (params: { pay_group_id?: string; date?: string; outlet_id?: string }) => get<TimesheetIndex>("/v1/admin/timesheets", { params }),
    show: (userId: number, date?: string) => get<TimesheetDetail>(`/v1/admin/timesheets/person/${userId}`, { params: date ? { date } : undefined }),
    approve: (userId: number, date?: string) => post<TimesheetDetail & { message: string }>(`/v1/admin/timesheets/person/${userId}/approve`, { date }),
    sendBack: (userId: number, note: string, date?: string) => post<TimesheetDetail & { message: string }>(`/v1/admin/timesheets/person/${userId}/send-back`, { date, note }),
    lock: (periodId: number, force = false) => post<{ message: string; period: PayPeriod }>(`/v1/admin/pay-periods/${periodId}/lock`, { force }),
    unlock: (periodId: number, reason: string) => post<{ message: string; period: PayPeriod }>(`/v1/admin/pay-periods/${periodId}/unlock`, { reason }),
    exportCsv: (periodId: number) => get<Blob>("/v1/admin/timesheets/export", { params: { pay_period_id: String(periodId) }, responseType: "blob" }),
    groups: () => get<{ data: PayGroup[] }>("/v1/admin/pay-groups"),
    createGroup: (data: Partial<PayGroup> & { name: string; frequency: "monthly" | "weekly" }) => post<{ message: string; group: PayGroup }>("/v1/admin/pay-groups", data),
    updateGroup: (id: number, data: Partial<PayGroup>) => put<{ message: string; group: PayGroup }>(`/v1/admin/pay-groups/${id}`, data),
    mine: (date?: string) => get<TimesheetDetail>("/v1/admin/me/timesheet", { params: date ? { date } : undefined }),
    submit: (note?: string, date?: string) => post<TimesheetDetail & { message: string }>("/v1/admin/me/timesheet/submit", { note, date }),
};

export function hm(min: number | null | undefined): string {
    if (min === null || min === undefined) return "—";
    const h = Math.floor(min / 60), m = min % 60;
    return m ? `${h}h ${String(m).padStart(2, "0")}m` : `${h}h`;
}
export const SHEET_TONE: Record<SheetStatus, string> = {
    draft: "bg-surface-100 text-surface-600",
    submitted: "bg-info-light text-info",
    sent_back: "bg-warning-light text-warning",
    approved: "bg-success-light text-success",
};
export const SHEET_LABEL: Record<SheetStatus, string> = { draft: "Not submitted", submitted: "Submitted", sent_back: "Sent back", approved: "Approved" };
