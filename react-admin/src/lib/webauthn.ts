/**
 * Passkeys in the browser (owner, 2026-10-21): the two WebAuthn ceremonies
 * the hub uses for "Confirm it's you" and for enrolling a passkey under
 * Profile. The server (App\Services\Auth\Passkeys) mints the options and
 * checks the signed answer; this file only talks to the device and ships
 * the result back as base64url JSON.
 */

const b64url = {
    encode(buf: ArrayBuffer): string {
        const bytes = new Uint8Array(buf);
        let s = "";
        for (const b of bytes) s += String.fromCharCode(b);
        return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    },
    decode(s: string): ArrayBuffer {
        const pad = s.length % 4 === 0 ? "" : "=".repeat(4 - (s.length % 4));
        const bin = atob(s.replace(/-/g, "+").replace(/_/g, "/") + pad);
        const out = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out.buffer;
    },
};

/** Can this browser and device do passkeys at all? */
export function passkeysSupported(): boolean {
    return typeof window !== "undefined" && !!window.PublicKeyCredential && !!navigator.credentials;
}

/** Does the device have a built-in authenticator (Touch ID, Face ID, Windows Hello)? */
export async function platformAuthenticatorAvailable(): Promise<boolean> {
    try {
        return passkeysSupported() && (await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable());
    } catch {
        return false;
    }
}

type ServerCreationOptions = Record<string, any>;
type ServerRequestOptions = Record<string, any>;

function descriptors(list: any[] | undefined): PublicKeyCredentialDescriptor[] | undefined {
    return list?.map((d) => ({ type: d.type, id: b64url.decode(d.id), transports: d.transports })) ?? undefined;
}

/** Ask the device to make a new passkey from the server's options. */
export async function createPasskey(options: ServerCreationOptions): Promise<Record<string, unknown>> {
    const publicKey: PublicKeyCredentialCreationOptions = {
        ...(options as PublicKeyCredentialCreationOptions),
        challenge: b64url.decode(options.challenge),
        user: { ...options.user, id: b64url.decode(options.user.id) },
        excludeCredentials: descriptors(options.excludeCredentials),
    };
    const cred = (await navigator.credentials.create({ publicKey })) as PublicKeyCredential | null;
    if (!cred) throw new Error("The device did not make a passkey.");
    return credentialToJson(cred);
}

/** Ask the device to sign the server's challenge with a registered passkey. */
export async function assertPasskey(options: ServerRequestOptions): Promise<Record<string, unknown>> {
    const publicKey: PublicKeyCredentialRequestOptions = {
        ...(options as PublicKeyCredentialRequestOptions),
        challenge: b64url.decode(options.challenge),
        allowCredentials: descriptors(options.allowCredentials),
    };
    const cred = (await navigator.credentials.get({ publicKey })) as PublicKeyCredential | null;
    if (!cred) throw new Error("The device did not answer.");
    return credentialToJson(cred);
}

/** The credential as the server reads it: every binary field base64url. */
function credentialToJson(cred: PublicKeyCredential): Record<string, unknown> {
    const anyCred = cred as any;
    if (typeof anyCred.toJSON === "function") return anyCred.toJSON();
    const r = cred.response as any;
    const response: Record<string, unknown> = { clientDataJSON: b64url.encode(r.clientDataJSON) };
    if (r.attestationObject) {
        response.attestationObject = b64url.encode(r.attestationObject);
        response.transports = typeof r.getTransports === "function" ? r.getTransports() : [];
    } else {
        response.authenticatorData = b64url.encode(r.authenticatorData);
        response.signature = b64url.encode(r.signature);
        response.userHandle = r.userHandle ? b64url.encode(r.userHandle) : null;
    }
    return {
        id: cred.id,
        rawId: b64url.encode(cred.rawId),
        type: cred.type,
        authenticatorAttachment: anyCred.authenticatorAttachment ?? null,
        clientExtensionResults: cred.getClientExtensionResults(),
        response,
    };
}

/** A sentence for the person when the device said no. */
export function passkeyErrorMessage(e: unknown): string {
    const err = e as { name?: string; message?: string };
    switch (err?.name) {
        case "NotAllowedError":
            return "The passkey was not confirmed — cancelled or timed out.";
        case "InvalidStateError":
            return "This device already holds a passkey for this account.";
        case "SecurityError":
            return "Passkeys only work on the hub's own address over HTTPS.";
        default:
            return err?.message || "The device could not use a passkey.";
    }
}
