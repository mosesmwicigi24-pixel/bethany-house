import { get, post, put } from "./client";
import type { PaginatedResponse } from "@/types";

// ─── Types ──────────────────────────────────────────────────────────────────

export interface TimeClockOutlet {
    id: number;
    name: string;
    outlet_type: "store" | "warehouse" | "outlet" | "workshop";
    latitude: number | null;
    longitude: number | null;
    geofence_radius_meters: number | null;
}

export interface TimeEntry {
    id: number;
    outlet: { id: number; name: string; outlet_type: string } | null;
    user?: { id: number; name: string };
    clock_in_at: string;
    clock_out_at: string | null;
    status: "active" | "completed" | "flagged";
    clock_in_method: "gps" | "override";
    clock_in_distance_meters: number | null;
    total_break_minutes: number | null;
    worked_minutes: number | null;
    elapsed_minutes: number;
    on_break: boolean;
    flagged_reason: string | null;
    notes: string | null;
    // detailed view only
    breaks?: { started_at: string; ended_at: string | null }[];
    clock_in_latitude?: number;
    clock_in_longitude?: number;
    clock_out_latitude?: number | null;
    clock_out_longitude?: number | null;
    clock_out_distance_meters?: number | null;
    overridden_by?: string | null;
    corrected_by?: string | null;
}

export interface TimeClockStatus {
    active_entry: TimeEntry | null;
    today_entries: TimeEntry[];
    today_worked_minutes: number;
    outlets: TimeClockOutlet[];
}

export interface GeofenceRejection {
    message: string;
    distance_meters: number;
    allowed_radius_meters: number;
    requires_override: true;
}

// ─── Self-service (Time Clock) ───────────────────────────────────────────────

export const timeClockApi = {
    status: () => get<TimeClockStatus>("/v1/admin/time-clock/status"),

    outlets: () =>
        get<{ data: TimeClockOutlet[] }>("/v1/admin/time-clock/outlets"),

    clockIn: (data: {
        outlet_id: number;
        latitude: number;
        longitude: number;
        force?: boolean;
        reason?: string;
    }) =>
        post<{ message: string; entry: TimeEntry }>(
            "/v1/admin/time-clock/clock-in",
            data,
        ),

    clockOut: (data: { latitude: number; longitude: number }) =>
        post<{ message: string; entry: TimeEntry }>(
            "/v1/admin/time-clock/clock-out",
            data,
        ),

    startBreak: () =>
        post<{ message: string; entry: TimeEntry }>(
            "/v1/admin/time-clock/break/start",
        ),

    endBreak: () =>
        post<{ message: string; entry: TimeEntry }>(
            "/v1/admin/time-clock/break/end",
        ),

    myEntries: (params?: { from?: string; to?: string; per_page?: string }) =>
        get<PaginatedResponse<TimeEntry>>("/v1/admin/time-clock/my-entries", {
            params,
        }),
};

// ─── Team oversight (Attendance) ─────────────────────────────────────────────

export const attendanceApi = {
    entries: (params?: {
        outlet_id?: string;
        user_id?: string;
        status?: string;
        from?: string;
        to?: string;
        page?: string;
        per_page?: string;
    }) =>
        get<PaginatedResponse<TimeEntry>>("/v1/admin/attendance/entries", {
            params,
        }),

    show: (id: number) =>
        get<{ entry: TimeEntry }>(`/v1/admin/attendance/entries/${id}`),

    flagged: () =>
        get<{ data: TimeEntry[] }>("/v1/admin/attendance/flagged"),

    update: (
        id: number,
        data: Partial<{
            clock_in_at: string;
            clock_out_at: string | null;
            status: "active" | "completed" | "flagged";
            notes: string | null;
        }>,
    ) =>
        put<{ message: string; entry: TimeEntry }>(
            `/v1/admin/attendance/entries/${id}`,
            data,
        ),
};
