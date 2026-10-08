import { useEffect, useRef, useState } from "react";
import { authApi } from "@/api/auth";
import { useAuthStore } from "@/store/auth.store";
import { pinUnlocked, useSecurityPrompts } from "@/store/security.store";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

/**
 * A clerk's session left idle is held by the server until their terminal PIN
 * is entered (Phase 4C). This covers the screen without unmounting it — the
 * sale being rung and the open register stay exactly as they were — and the
 * requests that hit the lock are sent again once it is released.
 */
export function PinLockOverlay() {
    const locked = useSecurityPrompts((s) => s.pinLocked);
    const user = useAuthStore((s) => s.user);
    const logout = useAuthStore((s) => s.logout);
    const [pin, setPin] = useState("");
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (locked) {
            setPin("");
            setError(null);
            setTimeout(() => input.current?.focus(), 50);
        }
    }, [locked]);

    if (!locked) return null;

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (pin.length < 4 || busy) return;
        setBusy(true);
        setError(null);
        try {
            await authApi.unlockWithPin(pin);
            pinUnlocked();
        } catch (err) {
            const apiErr = err as ApiError;
            // 401: too many wrong PINs — the client has already signed out.
            setError(apiErr.errors?.pin?.[0] ?? apiErr.message ?? "That PIN did not work.");
            setPin("");
            input.current?.focus();
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-surface-900/80 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="pin-lock-title">
            <form onSubmit={submit} className="card w-full max-w-xs p-6 text-center space-y-4">
                <div>
                    <h2 id="pin-lock-title" className="font-display text-lg font-bold text-surface-900">Session locked</h2>
                    <p className="mt-1 text-sm text-surface-500">
                        {user ? `${user.first_name}, enter` : "Enter"} your PIN to carry on. Your sale and register are as you left them.
                    </p>
                </div>
                <input
                    ref={input}
                    type="password"
                    inputMode="numeric"
                    autoComplete="off"
                    maxLength={6}
                    value={pin}
                    onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))}
                    className="input text-center font-mono text-2xl tracking-[0.5em]"
                    placeholder="••••"
                    aria-label="Terminal PIN"
                />
                {error && <p className="field-error">{error}</p>}
                <button type="submit" disabled={busy || pin.length < 4} className="btn-primary w-full h-10">
                    {busy ? <Spinner size="sm" className="border-white/30 border-t-white" /> : "Unlock"}
                </button>
                <button type="button" onClick={() => logout()} className="text-sm text-surface-500 hover:text-surface-700">
                    Not you? Sign out
                </button>
            </form>
        </div>
    );
}
