import { tokenStorage } from "./client";

/**
 * Attachments (payment proofs, shipment papers, chat files, expense receipts)
 * are handed out as signed links that live five minutes at most (role
 * hardening 4D). The endpoint the console already knows for each file is the
 * ISSUER: called with the staff token, it runs the record check and answers
 * `{ url, expires_at, name }`. The file itself is then fetched from `url` with
 * NO credentials — the signature is the permission.
 *
 * Always ask for a fresh link when a file is opened: a link kept from earlier
 * has expired. Chat messages store the issuer URL in their text, so old
 * messages keep working — each view issues a new link.
 */

export interface SignedFile {
    blob: Blob;
    contentType: string;
    name: string | null;
}

interface IssuedLink {
    url: string;
    expires_at?: string;
    name?: string | null;
}

/** Absolute issuer URL from a full URL or an /api/... path. */
function resolveIssuer(issuer: string): string {
    if (/^https?:\/\//i.test(issuer)) {
        // Old chat messages carry http:// links; the https page would block them.
        return window.location.protocol === "https:" ? issuer.replace(/^http:\/\//i, "https://") : issuer;
    }
    const base = (import.meta.env.VITE_API_URL ?? "").replace(/\/api\/?$/, "");
    const path = issuer.startsWith("/") ? issuer : `/${issuer}`;
    return `${base}${path}`;
}

/** Ask the issuer for a fresh signed link (throws on refusal). */
export async function issueSignedLink(issuer: string): Promise<IssuedLink> {
    const token = tokenStorage.get();
    const res = await fetch(resolveIssuer(issuer), {
        headers: {
            Authorization: token ? `Bearer ${token}` : "",
            Accept: "application/json",
        },
    });
    if (!res.ok) {
        throw new Error(res.status === 404 ? "File not found" : `${res.status} ${res.statusText}`);
    }
    const data = (await res.json()) as IssuedLink;
    if (!data?.url) throw new Error("No file link was issued");
    return data;
}

/** Issue a fresh link and download the file behind it. */
export async function fetchSignedFile(issuer: string): Promise<SignedFile> {
    const link = await issueSignedLink(issuer);
    const file = await fetch(resolveIssuer(link.url), { credentials: "omit" });
    if (!file.ok) {
        throw new Error(`${file.status} ${file.statusText}`);
    }
    const contentType = file.headers.get("Content-Type") ?? "application/octet-stream";
    return { blob: await file.blob(), contentType, name: link.name ?? null };
}

/**
 * Open a file in a new tab. The tab is opened synchronously (inside the click)
 * so popup blockers allow it, then pointed at the fresh link once issued.
 */
export async function openSignedFile(issuer: string): Promise<void> {
    const tab = window.open("about:blank", "_blank");
    try {
        const link = await issueSignedLink(issuer);
        const url = resolveIssuer(link.url);
        if (tab) {
            tab.opener = null;
            tab.location.href = url;
        } else {
            window.location.assign(url);
        }
    } catch (e) {
        tab?.close();
        throw e;
    }
}
