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

export type TimeEntryFlag = "accuracy_low" | "edge" | "sent_late" | "mock_location" | "auto_clock_out";

export interface TimeEntry {
    id: number;
    outlet: { id: number; name: string; outlet_type: string } | null;
    user?: { id: number; name: string };
    clock_in_at: string;
    clock_out_at: string | null;
    status: "active" | "completed" | "flagged";
    clock_in_method: "gps" | "override" | "device" | "kiosk";
    clock_in_distance_meters: number | null;
    total_break_minutes: number | null;
    worked_minutes: number | null;
    elapsed_minutes: number;
    on_break: boolean;
    flagged_reason: string | null;
    /** Why a manager should look: accuracy_low, edge, sent_late, mock_location, auto_clock_out. */
    flags: TimeEntryFlag[];
    flag_labels: string[];
    clock_in_accuracy_meters: number | null;
    auto_closed_at: string | null;
    notes: string | null;
    // detailed view only
    breaks?: { started_at: string; ended_at: string | null }[];
    clock_in_latitude?: number;
    clock_in_longitude?: number;
    clock_out_latitude?: number | null;
    clock_out_longitude?: number | null;
    clock_out_distance_meters?: number | null;
    clock_out_accuracy_meters?: number | null;
    clock_in_recorded_at?: string | null;
    clock_out_recorded_at?: string | null;
    device_info?: string | null;
    app_version?: string | null;
    left_site_at?: string | null;
    trail_points?: number;
    photos?: { in: boolean; out: boolean };
    overridden_by?: string | null;
    corrected_by?: string | null;
}

export interface TimeClockStatus {
    active_entry: TimeEntry | null;
    today_entries: TimeEntry[];
    today_worked_minutes: number;
    week_worked_minutes: number;
    last_entry: TimeEntry | null;
    outlets: TimeClockOutlet[];
    rules: { auto_clock_out_time: string; late_sync_minutes: number; location_mode?: "off" | "punch" | "trail"; trail_interval_minutes?: number; trail_distance_meters?: number; leave_site_action?: string; leave_site_grace_minutes?: number };
    /** C1: today's rostered shift, if any. */
    today_shift?: { id: number; starts_at: string; ends_at: string; outlet: string | null; label: string | null } | null;
    server_time: string;
}

/** What the phone sends with a punch: where it is and how sure it is. */
export interface Punch {
    latitude: number;
    longitude: number;
    accuracy?: number | null;
    /** The phone's clock when the punch was made, for one sent after being offline. */
    recorded_at?: string;
    mock_location?: boolean;
}

export interface LiveBoard {
    data: TimeEntry[];
    count: number;
    on_break: number;
    flagged: number;
    server_time: string;
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

    clockIn: (data: Punch & { outlet_id: number; app_version?: string; force?: boolean; reason?: string }) =>
        post<{ message: string; entry: TimeEntry }>(
            "/v1/admin/time-clock/clock-in",
            data,
        ),

    clockOut: (data: Punch) =>
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

    live: () => get<LiveBoard>("/v1/admin/attendance/live"),

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
