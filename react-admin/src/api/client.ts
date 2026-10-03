import axios, {
    type AxiosInstance,
    type AxiosRequestConfig,
    type AxiosResponse,
    type InternalAxiosRequestConfig,
} from "axios";
import type { ApiError } from "@/types";
import {
    waitForPinUnlock,
    waitForStepUp,
    type StepUpMethod,
} from "@/store/security.store";

// ─── Token storage ────────────────────────────────────────────────────────────
// Uses localStorage so the token persists across PWA restarts, tab closes,
// and device sleep — essential for a mobile-first PWA experience.
// XSS risk is acceptable here: the app is an internal admin tool served
// over HTTPS with no user-generated HTML rendering.

const TOKEN_KEY = "bh_admin_token";

export const tokenStorage = {
    get: (): string | null => localStorage.getItem(TOKEN_KEY),
    set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
    remove: () => localStorage.removeItem(TOKEN_KEY),
};

// ─── Activity (idle limits) ───────────────────────────────────────────────────
// The server ends (or, for a clerk, PIN-locks) a session that has been idle
// past its role's limit (Phase 4C). Screens poll in the background, which must
// not count as the person being there: a request sent when nobody has touched
// the screen for a while is marked as background and does not reset the clock.

const BACKGROUND_AFTER_MS = 30_000;
let lastInteraction = Date.now();

if (typeof window !== "undefined") {
    const touch = () => { lastInteraction = Date.now(); };
    for (const ev of ["pointerdown", "keydown", "touchstart", "wheel"]) {
        window.addEventListener(ev, touch, { passive: true, capture: true });
    }
}

type RetryableConfig = InternalAxiosRequestConfig & { _securityRetries?: number };

// ─── Client factory ───────────────────────────────────────────────────────────

function createApiClient(): AxiosInstance {
    const client = axios.create({
        baseURL: import.meta.env.VITE_API_URL ?? "/api",
        timeout: 30_000,
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
    });

    // ── Request: inject Bearer token ──────────────────────────────────────────
    client.interceptors.request.use(
        (config: InternalAxiosRequestConfig) => {
            const token = tokenStorage.get();
            if (token) {
                config.headers.Authorization = `Bearer ${token}`;
            }
            if (Date.now() - lastInteraction > BACKGROUND_AFTER_MS) {
                config.headers["X-Background-Request"] = "1";
            }
            // When the body is FormData, let the browser/Axios set Content-Type
            // automatically (it must include the multipart boundary). Removing the
            // hardcoded 'application/json' header here prevents it from overriding
            // the correct 'multipart/form-data; boundary=...' that Axios generates.
            if (config.data instanceof FormData) {
                delete config.headers["Content-Type"];
            }
            return config;
        },
        (error) => Promise.reject(error),
    );

    // ── Response: normalise errors, handle 401 ────────────────────────────────
    client.interceptors.response.use(
        (response: AxiosResponse) => response,
        (error) => {
            if (!error.response) {
                // Network / timeout error
                return Promise.reject({
                    message: "Network error. Please check your connection.",
                    errors: {},
                } satisfies ApiError);
            }

            const { status, data } = error.response;
            const config = error.config as RetryableConfig | undefined;
            const retries = config?._securityRetries ?? 0;

            // A clerk's idle session is held behind the terminal PIN. Wait for
            // the PIN (PinLockOverlay), then send the same request again. The
            // register and the sale on screen are untouched.
            if (status === 423 && config && retries < 3) {
                return readJson(data).then(async (body) => {
                    if (body?.reason !== "pin_locked") {
                        return Promise.reject({
                            status,
                            message: body?.message ?? "This account is locked.",
                            errors: body?.errors ?? {},
                            reason: body?.reason,
                        } satisfies ApiError);
                    }
                    await waitForPinUnlock();
                    config._securityRetries = retries + 1;
                    return client.request(config);
                });
            }

            if (status === 401) {
                tokenStorage.remove();
                // Redirect to login without hard reload to preserve SPA history
                window.dispatchEvent(new CustomEvent("auth:expired"));
                return Promise.reject({
                    status,
                    message: "Your session has expired. Please log in again.",
                    errors: {},
                } satisfies ApiError);
            }

            if (status === 403) {
                // The download gate holds files for approval with a 403 whose
                // body says so. File downloads ask for a Blob, so the body may
                // need reading first. Announce it (DownloadApprovalDialog opens)
                // instead of flattening it into "no permission".
                return readJson(data).then(async (body) => {
                    // A privileged action needs the person to confirm it is
                    // them (Phase 4C step-up). Ask (StepUpDialog), then send
                    // the same request again; cancelling fails it quietly.
                    if (body?.code === "step_up_required" && config && retries < 2) {
                        const confirmed = await waitForStepUp((body.method ?? "password") as StepUpMethod);
                        if (confirmed) {
                            config._securityRetries = retries + 1;
                            return client.request(config);
                        }
                        return Promise.reject({
                            status,
                            message: "Cancelled — the action needs you to confirm it’s you.",
                            errors: {},
                            reason: "step_up_cancelled",
                        } satisfies ApiError);
                    }
                    if (body?.code === "download_approval_required") {
                        window.dispatchEvent(new CustomEvent("download:held", { detail: body }));
                        return Promise.reject({
                    status,
                            message: "This download needs approval. Add a reason to send the request.",
                            errors: {},
                            reason: "download_approval_required",
                        } satisfies ApiError);
                    }
                    if (body?.code === "download_token_invalid") {
                        return Promise.reject({
                    status,
                            message: body.message ?? "This download link cannot be used.",
                            errors: {},
                            reason: "download_token_invalid",
                        } satisfies ApiError);
                    }
                    return Promise.reject({
                    status,
                        message: body?.message && body.message !== "This action is unauthorized."
                            ? body.message
                            : "You do not have permission to perform this action.",
                        errors: {},
                    } satisfies ApiError);
                });
            }

            if (status === 422 && data.errors) {
                return Promise.reject({
                    status,
                    message: data.message ?? "Validation failed.",
                    errors: data.errors,
                    reason: data.reason,
                } satisfies ApiError);
            }

            if (status === 429) {
                return Promise.reject({
                    status,
                    message:
                        "Too many requests. Please wait a moment and try again.",
                    errors: {},
                } satisfies ApiError);
            }

            // Carry the API's `reason` code through. Several endpoints refuse
            // with {message, reason} and no `errors` bag; without this the
            // caller only ever saw the prose and had to guess what happened.
            return Promise.reject({
                    status,
                message: data?.message ?? "An unexpected error occurred.",
                errors: data?.errors ?? {},
                reason: data?.reason,
            } satisfies ApiError);
        },
    );

    return client;
}

/** A response body as JSON, whether axios gave an object, a string or a Blob. */
async function readJson(data: unknown): Promise<any> {
    try {
        if (data instanceof Blob) return JSON.parse(await data.text());
        if (typeof data === "string") return JSON.parse(data);
        return data ?? null;
    } catch {
        return null;
    }
}

export const api = createApiClient();

// ─── Typed request helpers ────────────────────────────────────────────────────

export async function get<T>(
    url: string,
    config?: AxiosRequestConfig,
): Promise<T> {
    const { data } = await api.get<T>(url, config);
    return data;
}

export async function post<T>(
    url: string,
    body?: unknown,
    config?: AxiosRequestConfig,
): Promise<T> {
    const { data } = await api.post<T>(url, body, config);
    return data;
}

export async function put<T>(
    url: string,
    body?: unknown,
    config?: AxiosRequestConfig,
): Promise<T> {
    const { data } = await api.put<T>(url, body, config);
    return data;
}

export async function patch<T>(
    url: string,
    body?: unknown,
    config?: AxiosRequestConfig,
): Promise<T> {
    const { data } = await api.patch<T>(url, body, config);
    return data;
}

export async function del<T>(
    url: string,
    config?: AxiosRequestConfig,
): Promise<T> {
    const { data } = await api.delete<T>(url, config);
    return data;
}
