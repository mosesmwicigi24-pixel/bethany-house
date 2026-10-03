import { get, post } from "./client";

// Acting on another person's sign-in (Phase 4C). The server decides who may:
// system_admin for managers and operators, super_admin for anyone else;
// a clerk's PIN by their outlet manager. Resetting 2FA asks for step-up.

export interface AdminSessionRow {
    id: string;
    ip: string;
    agent: string;
    signed_in: string;
    last_used: string;
    last_active: string | null;
    locked: boolean;
}

export const userSecurityApi = {
    unlock: (userId: number) =>
        post<{ message: string }>(`/v1/admin/users/${userId}/unlock`),

    resetTwoFactor: (userId: number, reason: string) =>
        post<{ message: string }>(`/v1/admin/users/${userId}/2fa/reset`, { reason }),

    sessions: (userId: number) =>
        get<{ data: AdminSessionRow[] }>(`/v1/admin/users/${userId}/sessions`),

    revokeSession: (userId: number, tokenId: string) =>
        post<{ message: string }>(`/v1/admin/users/${userId}/sessions/${tokenId}/revoke`),

    revokeAllSessions: (userId: number) =>
        post<{ message: string; revoked_count: number }>(`/v1/admin/users/${userId}/sessions/revoke-all`),

    resetTerminalPin: (userId: number) =>
        post<{ message: string }>(`/v1/admin/users/${userId}/terminal-pin/reset`),
};

/** Mirrors App\Services\Auth\StaffAuthority::tierOf — for showing buttons only; the server decides. */
const TIERS: Record<string, number> = {
    super_admin: 0, admin: 1, finance_manager: 1, system_admin: 1,
    outlet_manager: 2, accountant: 2, procurement_manager: 2,
    pos_clerk: 3, tailor: 3, procurement_officer: 3,
};

export function tierOf(roleNames: string[]): number {
    if (roleNames.length === 0) return 3;
    return Math.min(...roleNames.map((r) => TIERS[r] ?? 1));
}
