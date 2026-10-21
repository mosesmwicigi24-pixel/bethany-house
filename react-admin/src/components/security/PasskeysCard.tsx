import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { profileApi, type PasskeyRow } from "@/api/profile";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import { createPasskey, passkeyErrorMessage, passkeysSupported, platformAuthenticatorAvailable } from "@/lib/webauthn";
import type { ApiError } from "@/types";

/**
 * Profile → Passkeys (owner, 2026-10-21): Touch ID, Face ID or a security
 * key as the way to confirm it's you before a privileged action, ahead of the
 * authenticator code. The person's own list; adding or removing one asks for
 * a fresh confirmation first (the server's step-up, handled by the client).
 */
export function PasskeysCard() {
    const qc = useQueryClient();
    const toast = useToastStore();
    const [name, setName] = useState("");
    const [adding, setAdding] = useState(false);
    const [platform, setPlatform] = useState<boolean | null>(null);
    const supported = passkeysSupported();

    useEffect(() => {
        void platformAuthenticatorAvailable().then(setPlatform);
    }, []);

    const { data, isLoading } = useQuery({ queryKey: ["profile-passkeys"], queryFn: () => profileApi.passkeys() });
    const keys: PasskeyRow[] = data?.data ?? [];

    const add = useMutation({
        mutationFn: async () => {
            const { options } = await profileApi.passkeyOptions();
            let credential: Record<string, unknown>;
            try {
                credential = await createPasskey(options);
            } catch (e) {
                throw { message: passkeyErrorMessage(e) } as ApiError;
            }
            return profileApi.addPasskey(name.trim() || defaultName(), credential);
        },
        onSuccess: (res) => {
            toast.success(res.message);
            setName("");
            setAdding(false);
            qc.invalidateQueries({ queryKey: ["profile-passkeys"] });
        },
        onError: (e: ApiError) => toast.error(e.errors?.credential?.[0] ?? e.message),
    });

    const remove = useMutation({
        mutationFn: (id: number) => profileApi.removePasskey(id),
        onSuccess: (res) => {
            toast.success(res.message);
            qc.invalidateQueries({ queryKey: ["profile-passkeys"] });
        },
        onError: (e: ApiError) => toast.error(e.message),
    });

    return (
        <div className="card">
            <div className="card-header flex items-center justify-between gap-3">
                <h3 className="font-semibold text-sm text-surface-900">Passkeys — Touch ID, Face ID, security key</h3>
                {supported && !adding && (
                    <button className="btn-primary btn-sm" onClick={() => setAdding(true)} disabled={keys.length >= 5}>Add passkey</button>
                )}
            </div>
            <div className="card-body space-y-3">
                <p className="text-xs text-surface-500">
                    A passkey lets this device confirm it&apos;s you with your fingerprint or face instead of typing a code.
                    Once you have one, every &quot;Confirm it&apos;s you&quot; prompt asks for it first; the authenticator code stays as the fallback.
                    {platform === false && supported && " This device has no built-in fingerprint or face sensor; a security key still works."}
                </p>
                {!supported && (
                    <p className="text-xs text-warning-dark bg-warning-light rounded-lg px-3 py-2">This browser cannot use passkeys. Try Safari or Chrome on a recent Mac, iPhone or Android phone.</p>
                )}

                {adding && (
                    <form
                        className="flex flex-col sm:flex-row gap-2 p-3 rounded-xl border border-line bg-surface-50"
                        onSubmit={(e) => { e.preventDefault(); add.mutate(); }}
                    >
                        <input
                            className="input flex-1" value={name} maxLength={80} autoFocus
                            placeholder={defaultName()} onChange={(e) => setName(e.target.value)} aria-label="Passkey name"
                        />
                        <div className="flex gap-2">
                            <button type="button" className="btn-secondary btn-sm" onClick={() => setAdding(false)} disabled={add.isPending}>Cancel</button>
                            <button type="submit" className="btn-primary btn-sm" disabled={add.isPending}>
                                {add.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                                {add.isPending ? "Waiting for the device…" : "Create on this device"}
                            </button>
                        </div>
                    </form>
                )}

                {isLoading ? (
                    <div className="flex justify-center py-3"><Spinner size="sm" /></div>
                ) : keys.length === 0 ? (
                    <p className="text-sm text-surface-400">No passkey yet.</p>
                ) : (
                    <ul className="divide-y divide-line rounded-xl border border-line">
                        {keys.map((k) => (
                            <li key={k.id} className="flex items-center justify-between gap-3 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-surface-900 truncate">{k.name}</p>
                                    <p className="text-xs text-surface-500">
                                        Added {k.created_at ? new Date(k.created_at).toLocaleDateString("en-KE") : "—"}
                                        {k.last_used_at ? ` · last used ${new Date(k.last_used_at).toLocaleString("en-KE")}` : " · never used"}
                                    </p>
                                </div>
                                <button
                                    className="btn-secondary btn-sm text-danger shrink-0"
                                    disabled={remove.isPending}
                                    onClick={() => { if (window.confirm(`Remove the passkey "${k.name}"?`)) remove.mutate(k.id); }}
                                >
                                    Remove
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function defaultName(): string {
    const ua = navigator.userAgent;
    if (/iPhone/.test(ua)) return "iPhone";
    if (/iPad/.test(ua)) return "iPad";
    if (/Macintosh/.test(ua)) return "Mac";
    if (/Android/.test(ua)) return "Android phone";
    if (/Windows/.test(ua)) return "Windows PC";
    return "This device";
}
