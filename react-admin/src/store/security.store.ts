import { create } from "zustand";

/**
 * Sign-in safety prompts (Phase 4C) that can interrupt any request:
 *
 *   PIN lock   the server holds a clerk's idle session (423 pin_locked) until
 *              the terminal PIN is entered. Every request made meanwhile waits
 *              and is repeated once the session is unlocked.
 *   step-up    a privileged action needs the person to confirm it is them
 *              (403 step_up_required). The request waits for the dialog and is
 *              repeated after a successful confirmation, or fails if cancelled.
 *
 * api/client.ts opens these; PinLockOverlay and StepUpDialog answer them.
 */

/** How the person confirms it's them: a passkey (Touch ID / Face ID / key) when one is enrolled, else the code or the password. */
export type StepUpMethod = "password" | "totp" | "passkey";

interface SecurityPromptState {
    pinLocked: boolean;
    /** `fallback` is the code-or-password way when `method` is a passkey the device cannot use right now. */
    stepUp: { method: StepUpMethod; fallback: Exclude<StepUpMethod, "passkey"> } | null;
}

export const useSecurityPrompts = create<SecurityPromptState>(() => ({
    pinLocked: false,
    stepUp: null,
}));

type Waiter<T> = { resolve: (v: T) => void; reject: (e: unknown) => void };

let pinWaiters: Waiter<void>[] = [];
let stepUpWaiters: Waiter<boolean>[] = [];

/** Resolves when the session is unlocked; rejects if the person signs out instead. */
export function waitForPinUnlock(): Promise<void> {
    useSecurityPrompts.setState({ pinLocked: true });
    return new Promise<void>((resolve, reject) => pinWaiters.push({ resolve, reject }));
}

export function pinUnlocked(): void {
    const waiting = pinWaiters;
    pinWaiters = [];
    useSecurityPrompts.setState({ pinLocked: false });
    waiting.forEach((w) => w.resolve());
}

export function pinUnlockAbandoned(reason: unknown): void {
    const waiting = pinWaiters;
    pinWaiters = [];
    useSecurityPrompts.setState({ pinLocked: false });
    waiting.forEach((w) => w.reject(reason));
}

/** Resolves true once confirmed, false if the person cancels. */
export function waitForStepUp(method: StepUpMethod, fallback: Exclude<StepUpMethod, "passkey"> = "password"): Promise<boolean> {
    useSecurityPrompts.setState({ stepUp: { method, fallback } });
    return new Promise<boolean>((resolve, reject) => stepUpWaiters.push({ resolve, reject }));
}

export function stepUpFinished(confirmed: boolean): void {
    const waiting = stepUpWaiters;
    stepUpWaiters = [];
    useSecurityPrompts.setState({ stepUp: null });
    waiting.forEach((w) => w.resolve(confirmed));
}

/** Signed out: nothing is waiting any more. */
export function resetSecurityPrompts(): void {
    pinUnlockAbandoned({ status: 401, message: "Signed out.", errors: {} });
    stepUpFinished(false);
}
