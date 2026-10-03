import { useState } from "react";
import { useAuthStore } from "@/store/auth.store";
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
        </>
    );
}
