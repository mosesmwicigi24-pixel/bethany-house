import { useState } from "react";
import { useAuthStore } from "@/store/auth.store";
import { useToastStore } from "@/store/toast.store";
import { profileApi } from "@/api/profile";
import type { ApiError } from "@/types";
import { Modal } from "@/components/ui/Modal";
import { PinLockOverlay } from "./PinLockOverlay";
import { StepUpDialog } from "./StepUpDialog";
import { TerminalPinForm } from "./TerminalPinForm";

/**
 * Console-wide sign-in safety (Phase 4C): the PIN lock, the step-up dialog,
 * and — for someone whose session PIN-locks but who has no PIN yet — a prompt
 * to set one (without it the idle limit signs them out instead).
 */
export function SecurityPrompts() {
    const user = useAuthStore((s) => s.user);
    const [dismissed, setDismissed] = useState(false);
    const needsPin = !!user && user.session_policy?.on_idle === "pin_lock" && user.terminal_pin_set === false;
    const pinPromptOpen = needsPin && !dismissed;

    return (
        <>
            <PinLockOverlay />
            <StepUpDialog />
            <Modal open={needsPin && !dismissed} onClose={() => setDismissed(true)} title="Set your terminal PIN" size="sm">
                <div className="space-y-3 text-sm">
                    <p className="text-surface-600">
                        After {user?.session_policy?.idle_minutes ?? 5} minutes without use the till locks. With a PIN you unlock it in a
                        second and your sale stays open; without one you have to sign in again with your password.
                    </p>
                    <TerminalPinForm onSaved={() => setDismissed(true)} />
                </div>
            </Modal>
            {/* One prompt at a time: the device name waits for the PIN prompt. */}
            {!pinPromptOpen && <NameThisDevice />}
        </>
    );
}

const NOT_NOW_KEY = "bh_device_name_not_now";

/**
 * Named sign-in devices: after a sign-in on a device with no name, ask for
 * one ("Moses' MacBook", "Till 1 — Nairobi shop"), so sign-in alerts and
 * device lists say whose machine it is. "Not now" asks again next session.
 */
function NameThisDevice() {
    const user = useAuthStore((s) => s.user);
    const setUser = useAuthStore((s) => s.setUser);
    const toast = useToastStore();
    const device = user?.current_device ?? null;
    const [notNow, setNotNow] = useState(() => {
        try { return sessionStorage.getItem(NOT_NOW_KEY) === String(device?.id ?? ""); } catch { return false; }
    });
    const [name, setName] = useState("");
    const [saving, setSaving] = useState(false);

    if (!user || !device || !device.needs_name || notNow) return null;
    const suggestion = `${user.first_name}'s ${device.kind_label}`;

    const skip = () => {
        try { sessionStorage.setItem(NOT_NOW_KEY, String(device.id)); } catch { /* storage blocked: asks again on reload */ }
        setNotNow(true);
    };
    const save = async () => {
        const chosen = (name.trim() || suggestion).slice(0, 60);
        setSaving(true);
        try {
            const res = await profileApi.renameDevice(device.id, chosen);
            setUser({ ...user, current_device: res.device });
            toast.success(`This device is now “${chosen}”.`);
        } catch (err) {
            toast.error((err as ApiError).message ?? "Could not name this device.");
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open onClose={skip} title="Name this device" size="sm">
            <div className="space-y-3 text-sm">
                <p className="text-surface-600">
                    This is a <strong>{device.facts}</strong> we haven’t got a name for. Give it one so sign-in alerts and your
                    device list say whose machine it is — for example your own laptop, or a shop till.
                </p>
                <input
                    autoFocus
                    value={name}
                    maxLength={60}
                    onChange={(e) => setName(e.target.value)}
                    onKeyDown={(e) => { if (e.key === "Enter") void save(); }}
                    className="input w-full"
                    placeholder={suggestion}
                />
                <p className="text-2xs text-surface-400">A website can’t read a device’s own name, so it’s kept here. You can change it later under Profile → Sessions.</p>
                <div className="flex justify-end gap-2">
                    <button onClick={skip} className="btn-ghost btn-sm">Not now</button>
                    <button onClick={() => void save()} disabled={saving || (name.trim().length > 0 && name.trim().length < 2)} className="btn-primary btn-sm">
                        {name.trim() ? "Save" : `Save as “${suggestion}”`}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
