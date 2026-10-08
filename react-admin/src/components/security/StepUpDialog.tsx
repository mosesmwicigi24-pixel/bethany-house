import { useEffect, useState } from "react";
import { authApi } from "@/api/auth";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { stepUpFinished, useSecurityPrompts } from "@/store/security.store";
import type { ApiError } from "@/types";

/**
 * "Confirm it's you" before a privileged action (Phase 4C step-up): the 2FA
 * code when two-step sign-in is on, otherwise the password. One confirmation
 * covers this session for five minutes; the action that asked is then sent
 * again automatically.
 */
export function StepUpDialog() {
    const prompt = useSecurityPrompts((s) => s.stepUp);
    const [value, setValue] = useState("");
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const totp = prompt?.method === "totp";

    useEffect(() => {
        setValue("");
        setError(null);
    }, [prompt]);

    const cancel = () => stepUpFinished(false);

    const confirm = async (e?: React.FormEvent) => {
        e?.preventDefault();
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
                    <button type="button" onClick={() => confirm()} disabled={busy || !value} className="btn-primary btn-sm">
                        {busy && <Spinner size="xs" className="border-white/30 border-t-white" />}
                        Confirm
                    </button>
                </>
            }
        >
            <form onSubmit={confirm} className="space-y-3 text-sm">
                <p className="text-surface-600">
                    This action is protected. {totp ? "Enter the 6-digit code from your authenticator app." : "Enter your password."}
                    {" "}You won’t be asked again for five minutes on this device.
                </p>
                {totp ? (
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
            </form>
        </Modal>
    );
}
