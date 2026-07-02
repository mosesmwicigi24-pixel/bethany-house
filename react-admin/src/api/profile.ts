import { get, post, put } from "./client";
import type { UserSetup } from "@/types/setup";

export interface ProfileData {
    id: number;
    uuid: string;
    first_name: string;
    last_name: string;
    name: string;
    email: string;
    phone: string | null;
    user_type: string;
    status: string;
    preferred_language: string;
    preferred_currency: string;
    two_factor_enabled: boolean;
    must_setup_2fa: boolean;
    last_login_at: string | null;
    last_login_ip: string | null;
    email_verified_at: string | null;
    created_at: string;
    roles: { id: number; name: string; display_name: string }[];
    outlet: { id: number; name: string } | null;
    active_sessions_count: number;
}

export interface ActivityLogEntry {
    id: number;
    user_id: number;
    action: string;
    description: string;
    ip_address: string | null;
    created_at: string;
    user_name?: string;
    user_email?: string;
}

export const profileApi = {
    // Get current user's profile
    get: () =>
        get<{ user: ProfileData; stats: Record<string, unknown> }>(
            "/v1/admin/profile",
        ),

    // Update profile info
    update: (data: {
        first_name?: string;
        last_name?: string;
        phone?: string;
        preferred_language?: string;
        preferred_currency?: string;
    }) =>
        put<{ message: string; user: ProfileData }>("/v1/admin/profile", data),

    // Change own password
    changePassword: (data: {
        current_password: string;
        password: string;
        password_confirmation: string;
    }) => post<{ message: string }>("/v1/admin/profile/password", data),

    // Upload avatar
    uploadAvatar: (file: File) => {
        const form = new FormData();
        form.append("avatar", file);
        return post<{ message: string; url: string }>(
            "/v1/admin/profile/avatar",
            form,
            {
                headers: { "Content-Type": undefined },
            },
        );
    },

    // Get active sessions
    sessions: () =>
        get<{
            data: {
                id: string;
                ip: string;
                agent: string;
                last_used: string;
                is_current: boolean;
            }[];
        }>("/v1/admin/profile/sessions"),

    // Revoke a session
    revokeSession: (tokenId: string) =>
        post<{ message: string }>(
            `/v1/admin/profile/sessions/${tokenId}/revoke`,
        ),

    // Revoke all other sessions
    revokeAllSessions: () =>
        post<{ message: string }>("/v1/admin/profile/sessions/revoke-all"),

    // 2FA setup
    setup2fa: () =>
        post<{ secret_key: string; qr_code_url: string; message: string }>(
            "/v1/admin/auth/2fa/enable",
        ),

    // Confirm 2FA setup with a TOTP code (uses the authenticated session,
    // not the pre-auth /2fa/verify route used during login - that one needs
    // a user_id and is a different flow entirely).
    verify2fa: (code: string) =>
        post<{ message: string }>("/v1/admin/auth/2fa/confirm", { code }),

    disable2fa: (password: string) =>
        post<{ message: string }>("/v1/admin/auth/2fa/disable", { password }),

    // Activity log for current user
    myActivity: (params?: Record<string, string>) =>
        get<{ data: ActivityLogEntry[]; meta: Record<string, unknown> }>(
            "/v1/admin/profile/activity",
            { params },
        ),
};

export const activityLogApi = {
    // Admin: all activity logs
    list: (params?: Record<string, string>) =>
        get<{ data: ActivityLogEntry[]; meta: Record<string, unknown> }>(
            "/v1/admin/activity-logs",
            { params },
        ),

    // Get single log entry
    get: (id: number) =>
        get<{ log: ActivityLogEntry }>(`/v1/admin/activity-logs/${id}`),

    // Clear old logs
    clear: (days: number) =>
        post<{ message: string; deleted_count: number }>(
            "/v1/admin/activity-logs/clear",
            { days },
        ),

    // Export logs
    export: (params?: Record<string, string>) =>
        get<{ data: ActivityLogEntry[] }>("/v1/admin/activity-logs/export", {
            params,
        }),
};