import axios from "axios";
import { get, post, put, del } from "./client";

/**
 * People C2 — automations (the alerts the hub sends the manager), the
 * clock-in kiosk and message by work status.
 */

export interface Automation {
    key: "missed_shift" | "outside_fence" | "overtime_soon" | "document_expiring";
    label: string;
    unit: "minutes" | "days" | null;
    min: number;
    max: number;
    enabled: boolean;
    threshold: number;
    recipients: "managers" | "hr" | "owner";
    extra_user_ids: number[];
    has_recipients: boolean;
    text: string;
}

export interface Kiosk {
    id: number;
    name: string;
    outlet_id: number;
    outlet: string | null;
    set_up_by: string | null;
    created_at: string | null;
    last_used_at: string | null;
}

export interface Audience {
    key: "clocked_in" | "on_roster";
    label: string;
    people: { id: number; name: string }[];
}

export const automationsApi = {
    list: () => get<{ automations: Automation[]; people: { id: number; name: string }[]; recognition_point_kes: number }>("/v1/admin/attendance/automations"),
    setPointValue: (kes: number) => put<{ message: string; recognition_point_kes: number }>("/v1/admin/attendance/recognition-points", { kes }),
    save: (key: Automation["key"], data: Partial<Pick<Automation, "enabled" | "threshold" | "recipients" | "extra_user_ids">>) =>
        put<{ message: string; automation: Automation }>(`/v1/admin/attendance/automations/${key}`, data),
};

export const kiosksApi = {
    list: () => get<{ data: Kiosk[] }>("/v1/admin/attendance/kiosks"),
    setUp: (outlet_id: number, name: string) => post<{ message: string; token: string; kiosk: Kiosk }>("/v1/admin/attendance/kiosks", { outlet_id, name }),
    remove: (id: number) => del<{ message: string }>(`/v1/admin/attendance/kiosks/${id}`),
    photoLink: (entryId: number, which: "in" | "out") => get<{ url: string; expires_at: string }>(`/v1/admin/attendance/entries/${entryId}/photo/${which}`),
};

export const audiencesApi = {
    list: () => get<{ audiences: Audience[]; date: string }>("/v1/admin/channels/audiences"),
};

// ─── The tablet ───────────────────────────────────────────────────────────────
// The kiosk's token lives apart from the manager's session (bh_admin_token) and
// reaches only /kiosk/* on the server. A plain client: no step-up, no PIN lock,
// no device key.

const KIOSK_KEY = "bh_kiosk_token";

export const kioskToken = {
    get: (): string | null => { try { return localStorage.getItem(KIOSK_KEY); } catch { return null; } },
    set: (t: string) => { try { localStorage.setItem(KIOSK_KEY, t); } catch { /* storage blocked */ } },
    remove: () => { try { localStorage.removeItem(KIOSK_KEY); } catch { /* storage blocked */ } },
};

export interface KioskPerson {
    id: number;
    name: string;
    initials: string;
    has_pin: boolean;
    state: "in" | "break" | "out";
    since: string | null;
    elsewhere: string | null;
}

export interface KioskBoard {
    kiosk: Kiosk;
    people: KioskPerson[];
    rules: { clock_in_without_shift: "allow" | "flag" | "refuse" };
    server_time: string;
}

const kioskClient = axios.create({
    baseURL: import.meta.env.VITE_API_URL ?? "/api",
    timeout: 20_000,
    headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
});
kioskClient.interceptors.request.use(cfg => {
    const t = kioskToken.get();
    if (t) cfg.headers.Authorization = `Bearer ${t}`;
    return cfg;
});

export class KioskError extends Error {
    status: number;
    constructor(message: string, status: number) { super(message); this.status = status; }
}

async function kioskCall<T>(p: Promise<{ data: T }>): Promise<T> {
    try {
        return (await p).data;
    } catch (e) {
        const err = e as { response?: { status?: number; data?: { message?: string } }; message?: string };
        throw new KioskError(err.response?.data?.message ?? (err.response ? "The hub refused that." : "Can't reach the hub. Check the tablet's connection."), err.response?.status ?? 0);
    }
}

export const kioskApi = {
    board: () => kioskCall<KioskBoard>(kioskClient.get("/v1/admin/kiosk/board")),
    punch: (data: { user_id: number; pin: string; action: "in" | "out"; photo?: string | null }) =>
        kioskCall<{ message: string; entry_id: number; at: string }>(kioskClient.post("/v1/admin/kiosk/punch", data)),
    leave: () => kioskCall<{ message: string }>(kioskClient.delete("/v1/admin/kiosk/token")),
};
