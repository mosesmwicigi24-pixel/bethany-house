import { useEffect, useRef, useState } from "react";
import { authApi } from "@/api/auth";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { stepUpFinished, useSecurityPrompts, type StepUpMethod } from "@/store/security.store";
import { assertPasskey, passkeyErrorMessage, passkeysSupported } from "@/lib/webauthn";
import type { ApiError } from "@/types";

/**
 * "Confirm it's you" before a privileged action (Phase 4C step-up): a
 * passkey (Touch ID / Face ID / security key) when one is enrolled, else the
 * 2FA code when two-step sign-in is on, otherwise the password. One
 * confirmation covers this session for five minutes; the action that asked
 * is then sent again automatically.
 */
export function StepUpDialog() {
    const prompt = useSecurityPrompts((s) => s.stepUp);
    const [method, setMethod] = useState<StepUpMethod>("password");
    const [value, setValue] = useState("");
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const attempted = useRef(false);
    const totp = method === "totp";
    const passkey = method === "passkey";

    useEffect(() => {
        setValue("");
        setError(null);
        attempted.current = false;
        // A passkey the browser cannot use falls straight back to the code or password.
        setMethod(prompt?.method === "passkey" && !passkeysSupported() ? prompt.fallback : (prompt?.method ?? "password"));
    }, [prompt]);

    const cancel = () => stepUpFinished(false);

    const usePasskey = async () => {
        if (busy) return;
        setBusy(true);
        setError(null);
        try {
            const { options } = await authApi.passkeyStepUpOptions();
            const credential = await assertPasskey(options);
            await authApi.passkeyStepUp(credential);
            stepUpFinished(true);
        } catch (err) {
            const apiErr = err as ApiError;
            setError(apiErr?.errors?.credential?.[0] ?? apiErr?.message ?? passkeyErrorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    // Ask the device as soon as the dialog opens on a passkey.
    useEffect(() => {
        if (prompt && passkey && !attempted.current) {
            attempted.current = true;
            void usePasskey();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [prompt, passkey]);

    const confirm = async (e?: React.FormEvent) => {
        e?.preventDefault();
        if (passkey) return usePasskey();
        if (!value || busy) return;
        setBusy(true);
        setError(null);
        try {
            await authApi.stepUp(totp ? { code: value } : { password: value });
            stepUpFinished(true);
        } catch (err) {
            const apiErr = err as ApiError;
            setError(apiErr.errors?.code?.[0] ?? apiErr.errors?.password?.[0] ?? apiErr.message ?? "That did not work.");
            setValue("");
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal
            open={!!prompt}
            onClose={cancel}
            title="Confirm it’s you"
            size="sm"
            closeOnBackdrop={false}
            footer={
                <>
                    <button type="button" onClick={cancel} className="btn-secondary btn-sm">Cancel</button>
                    <button type="button" onClick={() => confirm()} disabled={busy || (!passkey && !value)} className="btn-primary btn-sm">
                        {busy && <Spinner size="xs" className="border-white/30 border-t-white" />}
                        {passkey ? (busy ? "Waiting for the device…" : "Use passkey") : "Confirm"}
                    </button>
                </>
            }
        >
            <form onSubmit={confirm} className="space-y-3 text-sm">
                <p className="text-surface-600">
                    This action is protected.{" "}
                    {passkey
                        ? "Confirm with your passkey — Touch ID, Face ID or your security key."
                        : totp ? "Enter the 6-digit code from your authenticator app." : "Enter your password."}
                    {" "}You won’t be asked again for five minutes on this device.
                </p>
                {passkey ? (
                    <div className="flex items-center justify-center py-3">
                        <span className="w-12 h-12 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center text-2xl" aria-hidden>
                            {busy ? <Spinner size="sm" /> : "🔐"}
                        </span>
                    </div>
                ) : totp ? (
                    <input
                        type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={6} autoFocus
                        value={value} onChange={(e) => setValue(e.target.value.replace(/\D/g, ""))}
                        className="input text-center font-mono text-xl tracking-[0.5em]" placeholder="000000" aria-label="Authenticator code"
                    />
                ) : (
                    <input
                        type="password" autoComplete="current-password" autoFocus
                        value={value} onChange={(e) => setValue(e.target.value)}
                        className="input" placeholder="Your password" aria-label="Password"
                    />
                )}
                {error && <p className="field-error">{error}</p>}
                {passkey && prompt && (
                    <button type="button" className="text-xs text-brand-600 hover:underline" onClick={() => { setMethod(prompt.fallback); setError(null); }}>
                        {prompt.fallback === "totp" ? "Use the authenticator code instead" : "Use my password instead"}
                    </button>
                )}
                {!passkey && prompt?.method === "passkey" && passkeysSupported() && (
                    <button type="button" className="text-xs text-brand-600 hover:underline" onClick={() => { setMethod("passkey"); setError(null); }}>
                        Use my passkey instead
                    </button>
                )}
            </form>
        </Modal>
    );
}
