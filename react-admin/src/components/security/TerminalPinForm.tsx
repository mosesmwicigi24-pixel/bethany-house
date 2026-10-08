import { useState } from "react";
import { authApi } from "@/api/auth";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";
import { Spinner } from "@/components/ui/Spinner";
import type { ApiError } from "@/types";

/**
 * Set or change the terminal PIN (Phase 4C): 4–6 digits, confirmed with the
 * password. It unlocks the till after five idle minutes, and is the PIN an
 * approver enters on a clerk's terminal.
 */
export function TerminalPinForm({ onSaved }: { onSaved?: () => void }) {
    const toast = useToastStore();
    const user = useAuthStore((s) => s.user);
    const setUser = useAuthStore((s) => s.setUser);
    const [pin, setPin] = useState("");
    const [confirm, setConfirm] = useState("");
    const [password, setPassword] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (pin !== confirm) {
            setErrors({ pin_confirmation: "The two PINs do not match." });
            return;
        }
        setBusy(true);
        setErrors({});
        try {
            await authApi.setTerminalPin({ pin, pin_confirmation: confirm, current_password: password });
            toast.success("Your PIN is saved.");
            if (user) setUser({ ...user, terminal_pin_set: true });
            setPin(""); setConfirm(""); setPassword("");
            onSaved?.();
        } catch (err) {
            const apiErr = err as ApiError;
            const next: Record<string, string> = {};
            for (const [k, v] of Object.entries(apiErr.errors ?? {})) next[k] = (v as string[])[0];
            if (!Object.keys(next).length) next.pin = apiErr.message ?? "The PIN could not be saved.";
            setErrors(next);
        } finally {
            setBusy(false);
        }
    };

    const digits = (v: string) => v.replace(/\D/g, "").slice(0, 6);

    return (
        <form onSubmit={submit} className="space-y-3" noValidate>
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <label className="label" htmlFor="tp-pin">New PIN</label>
                    <input id="tp-pin" type="password" inputMode="numeric" autoComplete="off" value={pin} onChange={(e) => setPin(digits(e.target.value))} className="input font-mono tracking-widest" placeholder="4–6 digits" />
                    {errors.pin && <p className="field-error">{errors.pin}</p>}
                </div>
                <div>
                    <label className="label" htmlFor="tp-confirm">Repeat PIN</label>
                    <input id="tp-confirm" type="password" inputMode="numeric" autoComplete="off" value={confirm} onChange={(e) => setConfirm(digits(e.target.value))} className="input font-mono tracking-widest" />
                    {errors.pin_confirmation && <p className="field-error">{errors.pin_confirmation}</p>}
                </div>
            </div>
            <div>
                <label className="label" htmlFor="tp-password">Your password</label>
                <input id="tp-password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="input" />
                {errors.current_password && <p className="field-error">{errors.current_password}</p>}
            </div>
            <p className="text-xs text-surface-500">Not one digit repeated, and not a run like 1234. Nobody else can see it; your outlet manager can only clear it.</p>
            <button type="submit" disabled={busy || pin.length < 4 || !password} className="btn-primary btn-sm">
                {busy && <Spinner size="xs" className="border-white/30 border-t-white" />}
                Save PIN
            </button>
        </form>
    );
}
