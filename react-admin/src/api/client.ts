import axios, {
    type AxiosInstance,
    type AxiosRequestConfig,
    type AxiosResponse,
    type InternalAxiosRequestConfig,
} from "axios";
import type { ApiError } from "@/types";

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

            if (status === 401) {
                tokenStorage.remove();
                // Redirect to login without hard reload to preserve SPA history
                window.dispatchEvent(new CustomEvent("auth:expired"));
                return Promise.reject({
                    message: "Your session has expired. Please log in again.",
                    errors: {},
                } satisfies ApiError);
            }

            if (status === 403) {
                // The download gate holds files for approval with a 403 whose
                // body says so. File downloads ask for a Blob, so the body may
                // need reading first. Announce it (DownloadApprovalDialog opens)
                // instead of flattening it into "no permission".
                return readJson(data).then((body) => {
                    if (body?.code === "download_approval_required") {
                        window.dispatchEvent(new CustomEvent("download:held", { detail: body }));
                        return Promise.reject({
                            message: "This download needs approval. Add a reason to send the request.",
                            errors: {},
                            reason: "download_approval_required",
                        } satisfies ApiError);
                    }
                    if (body?.code === "download_token_invalid") {
                        return Promise.reject({
                            message: body.message ?? "This download link cannot be used.",
                            errors: {},
                            reason: "download_token_invalid",
                        } satisfies ApiError);
                    }
                    return Promise.reject({
                        message: body?.message && body.message !== "This action is unauthorized."
                            ? body.message
                            : "You do not have permission to perform this action.",
                        errors: {},
                    } satisfies ApiError);
                });
            }

            if (status === 422 && data.errors) {
                return Promise.reject({
                    message: data.message ?? "Validation failed.",
                    errors: data.errors,
                    reason: data.reason,
                } satisfies ApiError);
            }

            if (status === 429) {
                return Promise.reject({
                    message:
                        "Too many requests. Please wait a moment and try again.",
                    errors: {},
                } satisfies ApiError);
            }

            // Carry the API's `reason` code through. Several endpoints refuse
            // with {message, reason} and no `errors` bag; without this the
            // caller only ever saw the prose and had to guess what happened.
            return Promise.reject({
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
