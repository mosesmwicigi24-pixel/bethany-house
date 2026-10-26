import { get, post, put, del } from "./client";

/** Time off (Bethany People PR 4). */

export interface LeaveType {
    id: number;
    code: string;
    name: string;
    days_per_year: number | null;
    accrual: "front" | "monthly";
    is_paid: boolean;
    counts_calendar_days: boolean;
    evidence_after_days: number | null;
    is_active: boolean;
}

export interface LeaveBalance {
    type: LeaveType;
    entitled: number;
    used: number;
    requested: number;
    adjusted: number;
    available: number | null;
}

export type LeaveStatus = "pending" | "approved" | "declined" | "cancelled";

export interface LeaveRequest {
    id: number;
    user: { id: number; name: string } | null;
    type: LeaveType | null;
    starts_on: string;
    ends_on: string;
    days: number;
    reason: string | null;
    status: LeaveStatus;
    evidence_document_id: number | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    created_at: string | null;
    can_decide?: boolean;
    roster_clash?: { id: number; date: string; starts_at: string; ends_at: string }[];
}

export interface MyLeave {
    year: number;
    balances: LeaveBalance[];
    requests: LeaveRequest[];
    types: LeaveType[];
    approvers: string[];
}

export interface PersonLeave {
    year: number;
    balances: LeaveBalance[];
    requests: LeaveRequest[];
    adjustments: { id: number; type: string | null; days: number; reason: string; created_at: string | null }[];
    can_adjust: boolean;
    can_record: boolean;
}

export const leaveApi = {
    mine: (year?: number) => get<MyLeave>("/v1/admin/me/leave", { params: year ? { year: String(year) } : undefined }),
    preview: (leave_type_id: number, starts_on: string, ends_on: string) =>
        get<{ days: number; holidays_skipped: string[]; available: number | null; enough: boolean }>("/v1/admin/me/leave/preview", { params: { leave_type_id: String(leave_type_id), starts_on, ends_on } }),
    request: (data: { leave_type_id: number; starts_on: string; ends_on: string; reason?: string; evidence_document_id?: number | null }) =>
        post<{ message: string; request: LeaveRequest }>("/v1/admin/me/leave", data),
    cancelMine: (id: number) => del<{ message: string; request: LeaveRequest }>(`/v1/admin/me/leave/${id}`),

    requests: (params?: { status?: string; outlet_id?: string; year?: string }) =>
        get<{ data: LeaveRequest[] }>("/v1/admin/leave/requests", { params }),
    calendar: (from: string, to: string, outlet_id?: string) =>
        get<{ from: string; to: string; data: LeaveRequest[] }>("/v1/admin/leave/calendar", { params: { from, to, ...(outlet_id && { outlet_id }) } }),
    approve: (id: number, note?: string) => post<{ message: string; request: LeaveRequest; roster_clash: LeaveRequest["roster_clash"] }>(`/v1/admin/leave/requests/${id}/approve`, { note }),
    decline: (id: number, note?: string) => post<{ message: string; request: LeaveRequest }>(`/v1/admin/leave/requests/${id}/decline`, { note }),
    record: (data: { user_id: number; leave_type_id: number; starts_on: string; ends_on: string; reason?: string }) =>
        post<{ message: string; request: LeaveRequest }>("/v1/admin/leave/requests", data),
    cancel: (id: number, note?: string) => del<{ message: string; request: LeaveRequest }>(`/v1/admin/leave/requests/${id}`, { data: { note } }),

    forPerson: (userId: number, year?: number) => get<PersonLeave>(`/v1/admin/people/${userId}/leave`, { params: year ? { year: String(year) } : undefined }),
    adjust: (data: { user_id: number; leave_type_id: number; year: number; days: number; reason: string }) =>
        post<{ message: string }>("/v1/admin/leave/adjustments", data),

    types: () => get<{ data: LeaveType[] }>("/v1/admin/leave/types"),
    updateType: (id: number, data: Partial<Omit<LeaveType, "id" | "code">>) =>
        put<{ message: string; type: LeaveType }>(`/v1/admin/leave/types/${id}`, data),
};

export const STATUS_TONE: Record<LeaveStatus, string> = {
    pending: "bg-warning-light text-warning",
    approved: "bg-success-light text-success",
    declined: "bg-danger-light text-danger",
    cancelled: "bg-surface-100 text-surface-500",
};

export function fmtSpan(r: { starts_on: string; ends_on: string }): string {
    const f = (iso: string) => new Date(iso + "T00:00:00").toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
    return r.starts_on === r.ends_on ? f(r.starts_on) : `${f(r.starts_on)} – ${f(r.ends_on)}`;
}
