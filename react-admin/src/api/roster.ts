import { get, post, put, del } from "./client";

/** The weekly roster (Bethany People PR 3). */

export type ShiftStatus = "draft" | "published" | "cancelled";

export interface Shift {
    id: number;
    outlet_id: number;
    outlet: { id: number; name: string } | null;
    user_id: number | null;
    user: { id: number; name: string } | null;
    date: string;
    starts_at: string;
    ends_at: string;
    overnight: boolean;
    minutes: number;
    label: string | null;
    notes: string | null;
    status: ShiftStatus;
    published_at: string | null;
}

export interface SwapRequest {
    id: number;
    status: "pending" | "approved" | "declined" | "withdrawn";
    note: string | null;
    decision_note: string | null;
    from_user: { id: number; name: string } | null;
    to_user: { id: number; name: string } | null;
    shift: Shift | null;
    created_at: string | null;
    decided_at: string | null;
}

export interface RosterWeek {
    outlet: { id: number; name: string; operating_hours: Record<string, { open?: string; close?: string }> | null };
    week_start: string;
    week_end: string;
    days: string[];
    holidays: Record<string, string>;
    members: { id: number; name: string; job_title: string | null }[];
    shifts: Shift[];
    open_count: number;
    draft_count: number;
    hours_by_user: Record<string, number>;
    pending_swaps: SwapRequest[];
    can_manage: boolean;
}

export interface MyShifts {
    shifts: Shift[];
    open_shifts: Shift[];
    pending_swaps: SwapRequest[];
    today: Shift | null;
    next: Shift | null;
}

export interface ShiftInput {
    outlet_id: number;
    user_id?: number | null;
    date: string;
    starts_at: string;
    ends_at: string;
    label?: string | null;
    notes?: string | null;
    publish?: boolean;
}

export const rosterApi = {
    week: (outletId: number, week: string) =>
        get<RosterWeek>("/v1/admin/roster", { params: { outlet_id: String(outletId), week } }),
    addShift: (data: ShiftInput) => post<{ message: string; shift: Shift }>("/v1/admin/roster/shifts", data),
    updateShift: (id: number, data: Partial<Omit<ShiftInput, "outlet_id" | "publish">>) =>
        put<{ message: string; shift: Shift }>(`/v1/admin/roster/shifts/${id}`, data),
    removeShift: (id: number) => del<{ message: string; shift?: Shift }>(`/v1/admin/roster/shifts/${id}`),
    publish: (outletId: number, week: string) =>
        post<{ message: string; published: number }>("/v1/admin/roster/publish", { outlet_id: outletId, week }),
    copyWeek: (outletId: number, fromWeek: string, toWeek: string) =>
        post<{ message: string; copied: number }>("/v1/admin/roster/copy-week", { outlet_id: outletId, from_week: fromWeek, to_week: toWeek }),
    swaps: (status = "pending") => get<{ data: SwapRequest[] }>("/v1/admin/roster/swaps", { params: { status } }),
    approveSwap: (id: number, note?: string) => post<{ message: string; request: SwapRequest }>(`/v1/admin/roster/swaps/${id}/approve`, { note }),
    declineSwap: (id: number, note?: string) => post<{ message: string; request: SwapRequest }>(`/v1/admin/roster/swaps/${id}/decline`, { note }),

    // ── Me ──
    mine: (from?: string, to?: string) => get<MyShifts>("/v1/admin/me/shifts", { params: { ...(from && { from }), ...(to && { to }) } }),
    claim: (id: number) => post<{ message: string; shift: Shift }>(`/v1/admin/me/shifts/${id}/claim`),
    requestSwap: (id: number, data: { to_user_id?: number | null; note?: string }) =>
        post<{ message: string; request: SwapRequest }>(`/v1/admin/me/shifts/${id}/swap`, data),
    withdrawSwap: (id: number) => del<{ message: string }>(`/v1/admin/me/swaps/${id}`),
};

/** Monday of the week containing an ISO date (local calendar). */
export function mondayOf(iso: string): string {
    const d = new Date(iso + "T00:00:00");
    const day = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - day);
    return toIso(d);
}
export function toIso(d: Date): string {
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
export function addDays(iso: string, n: number): string {
    const d = new Date(iso + "T00:00:00"); d.setDate(d.getDate() + n); return toIso(d);
}
export function fmtDay(iso: string, opts: Intl.DateTimeFormatOptions = { weekday: "short", day: "numeric", month: "short" }): string {
    return new Date(iso + "T00:00:00").toLocaleDateString("en-GB", opts);
}
export function fmtHours(min: number): string {
    const h = Math.floor(min / 60), m = min % 60;
    return m ? `${h}h ${m}m` : `${h}h`;
}
