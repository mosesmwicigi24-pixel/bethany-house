import { get, post, del, api } from "./client";

// Download approval (App\Http\Middleware\DownloadGate on the server).
// Every file a staff member takes needs the owner's approval — or a manager he
// delegates to — except invoices, quotations and receipts.

export type DownloadStatus =
    | "held" | "pending" | "approved" | "denied" | "expired"
    | "cancelled" | "downloaded" | "auto" | "failed";

export interface DownloadRequest {
    id: number;
    uuid: string;
    user_id: number;
    method: string;
    path: string;
    payload: Record<string, unknown> | null;
    label: string;
    category: "gated" | "never_attach" | "exempt";
    reason: string | null;
    status: DownloadStatus;
    auto_approved: boolean;
    shadow: boolean;
    decided_at: string | null;
    decision_note: string | null;
    downloaded_at: string | null;
    download_ip: string | null;
    export_id: string | null;
    file_name: string | null;
    file_bytes: number | null;
    file_sha256: string | null;
    archive_path: string | null;
    owner_notified_at: string | null;
    created_at: string;
    updated_at: string;
    user?: { id: number; first_name: string; last_name: string; email: string } | null;
    decider?: { id: number; first_name: string; last_name: string } | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number;
    to: number;
}

export interface DownloadCapabilities {
    can_approve: boolean;
    is_owner: boolean;
    enforcing: boolean;
    pending: number;
}

/** The body of a 403 the gate returns when a download is held. */
export interface HeldDownload {
    code: "download_approval_required";
    message: string;
    held: string;
    download: { label: string; method: string; path: string; payload: Record<string, unknown>; category: string };
}

export const downloadsApi = {
    capabilities: () => get<DownloadCapabilities>("/v1/admin/downloads/capabilities"),
    mine: (params?: Record<string, string>) => get<Paginated<DownloadRequest>>("/v1/admin/downloads/mine", { params }),
    ask: (held: string, reason: string) =>
        post<{ message: string; request: DownloadRequest }>("/v1/admin/downloads/requests", { held, reason }),
    cancel: (uuid: string) => post<{ message: string }>(`/v1/admin/downloads/requests/${uuid}/cancel`),
    token: (uuid: string) =>
        post<{ token: string; header: string; method: string; path: string; payload: Record<string, unknown>; label: string }>(
            `/v1/admin/downloads/requests/${uuid}/token`,
        ),
    // approvers
    queue: (params?: Record<string, string>) => get<Paginated<DownloadRequest>>("/v1/admin/downloads/requests", { params }),
    approve: (uuid: string, note?: string) => post<{ message: string }>(`/v1/admin/downloads/requests/${uuid}/approve`, { note }),
    deny: (uuid: string, note: string) => post<{ message: string }>(`/v1/admin/downloads/requests/${uuid}/deny`, { note }),
    // owner
    all: (params?: Record<string, string>) => get<Paginated<DownloadRequest>>("/v1/admin/downloads/all", { params }),
    approvers: () =>
        get<{
            owner: { id: number; first_name: string; last_name: string; email: string } | null;
            delegates: { id: number; user: { id: number; first_name: string; last_name: string; email: string } }[];
        }>("/v1/admin/downloads/approvers"),
    addApprover: (userId: number) => post<{ message: string }>("/v1/admin/downloads/approvers", { user_id: userId }),
    removeApprover: (userId: number) => del<{ message: string }>(`/v1/admin/downloads/approvers/${userId}`),
};

/** "attachment; filename=\"orders__BH-EXP-000012.csv\"" → the name. */
export function filenameFrom(disposition: string | null | undefined, fallback: string): string {
    if (!disposition) return fallback;
    const star = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
    if (star) return decodeURIComponent(star[1].replace(/"/g, ""));
    const plain = /filename="?([^";]+)"?/i.exec(disposition);
    return plain ? plain[1] : fallback;
}

function saveBlob(blob: Blob, name: string) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

/**
 * Take an approved download: fetch a single-use link and replay exactly the
 * request that was approved. The server checks it is the same request, the
 * same filters and the same person.
 */
export async function takeApprovedDownload(req: DownloadRequest): Promise<void> {
    const t = await downloadsApi.token(req.uuid);
    const url = t.path.replace(/^\/api/, "");
    const isGet = t.method.toUpperCase() === "GET";
    const res = await api.request<Blob>({
        url,
        method: t.method,
        params: isGet ? t.payload : undefined,
        data: isGet ? undefined : t.payload,
        responseType: "blob",
        headers: { [t.header]: t.token },
    });
    const name = filenameFrom(res.headers["content-disposition"] as string | undefined, `${req.export_id ?? "download"}`);
    saveBlob(res.data, name);
}

/** The owner opening the server's archived copy of a download. */
export async function openArchivedCopy(req: DownloadRequest): Promise<void> {
    const res = await api.get<Blob>(`/v1/admin/downloads/${req.uuid}/archive`, { responseType: "blob" });
    saveBlob(res.data, filenameFrom(res.headers["content-disposition"] as string | undefined, req.file_name ?? "download"));
}

/** Console-wide signal that a download was held; DownloadApprovalDialog listens. */
export const DOWNLOAD_HELD_EVENT = "download:held";

export function announceHeld(body: HeldDownload): void {
    window.dispatchEvent(new CustomEvent<HeldDownload>(DOWNLOAD_HELD_EVENT, { detail: body }));
}

/** For fetch()-based downloads: true (and the dialog opens) when the gate held it. */
export async function heldFromFetch(res: Response): Promise<boolean> {
    if (res.status !== 403) return false;
    try {
        const body = await res.clone().json();
        if (body?.code === "download_approval_required") {
            announceHeld(body as HeldDownload);
            return true;
        }
    } catch {
        /* not JSON — an ordinary refusal */
    }
    return false;
}

/**
 * Download a file the ordinary way (GET, as a Blob) and save it. If the server
 * holds it for approval, api/client.ts opens the approval dialog and this
 * returns false quietly.
 */
export async function downloadFile(url: string, params: object, fallbackName: string): Promise<boolean> {
    try {
        const res = await api.get<Blob>(url, { params, responseType: "blob" });
        saveBlob(res.data, filenameFrom(res.headers["content-disposition"] as string | undefined, fallbackName));
        return true;
    } catch (e: any) {
        if (e?.reason === "download_approval_required") return false;   // the dialog has it
        throw e;
    }
}
