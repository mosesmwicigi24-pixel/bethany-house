/**
 * PWAInstallBanner.tsx - All PWA-related UI prompts in one component.
 *
 * Renders:
 *   1. Android/Desktop install banner  (bottom of screen, dismissible)
 *   2. iOS "Add to Home Screen" guide  (bottom sheet with step-by-step)
 *   3. Offline indicator bar           (top of screen, auto-shows/hides)
 *   4. Update available banner         (top of screen, with reload button)
 *
 * Mount once inside <AdminLayout> (or App.tsx), after authentication.
 */

import { useState } from "react";
import { clsx } from "clsx";
import { usePWA } from "@/lib/usePWA";

// ── Offline bar ───────────────────────────────────────────────────────────────

function OfflineBar({ isOnline }: { isOnline: boolean }) {
    if (isOnline) return null;
    return (
        <div
            role="status"
            aria-live="polite"
            className={clsx(
                "fixed top-0 left-0 right-0 z-[9999]",
                "flex items-center justify-center gap-2",
                "bg-amber-500 text-white text-xs font-medium py-1.5 px-4",
                "shadow-sm",
            )}
        >
            {/* Pulse dot */}
            <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full rounded-full bg-white opacity-75 animate-ping" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-white" />
            </span>
            You're offline - some features may be unavailable. Changes will sync
            when reconnected.
        </div>
    );
}

// ── Update banner ─────────────────────────────────────────────────────────────

function UpdateBanner({
    needsUpdate,
    applyUpdate,
}: {
    needsUpdate: boolean;
    applyUpdate: () => void;
}) {
    const [dismissed, setDismissed] = useState(false);
    if (!needsUpdate || dismissed) return null;

    return (
        <div
            role="alert"
            className={clsx(
                "fixed top-0 left-0 right-0 z-[9998]",
                "flex items-center justify-between gap-3",
                "bg-brand-600 text-white text-xs font-medium py-2 px-4",
                "shadow-sm",
            )}
        >
            <div className="flex items-center gap-2">
                <svg
                    className="w-4 h-4 shrink-0"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                    />
                </svg>
                A new version of the app is ready.
            </div>
            <div className="flex items-center gap-2 shrink-0">
                <button
                    onClick={applyUpdate}
                    className="bg-white text-brand-700 rounded-md px-3 py-1 text-xs font-semibold hover:bg-brand-50 transition-colors"
                >
                    Reload now
                </button>
                <button
                    onClick={() => setDismissed(true)}
                    aria-label="Dismiss update notification"
                    className="text-white/70 hover:text-white transition-colors p-0.5"
                >
                    <svg
                        className="w-4 h-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={2}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>
        </div>
    );
}

// ── Android / Desktop install banner ─────────────────────────────────────────

function AndroidInstallBanner({
    isInstallable,
    onInstall,
    onDismiss,
}: {
    isInstallable: boolean;
    onInstall: () => void;
    onDismiss: () => void;
}) {
    const [dismissed, setDismissed] = useState(false);
    if (!isInstallable || dismissed) return null;

    const handleDismiss = () => {
        setDismissed(true);
        onDismiss();
    };

    return (
        <div
            role="complementary"
            aria-label="Install app prompt"
            className={clsx(
                "fixed bottom-0 left-0 right-0 z-[9990]",
                "bg-white border-t border-surface-200 shadow-xl",
                "px-4 py-3 sm:px-5",
                "animate-slide-up",
            )}
        >
            <div className="flex items-center gap-3 max-w-lg mx-auto">
                {/* App icon */}
                <div className="w-12 h-12 rounded-xl bg-brand-600 flex items-center justify-center shrink-0 shadow-sm">
                    <svg
                        className="w-6 h-6 text-white"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.75}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                        />
                    </svg>
                </div>

                {/* Text */}
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-surface-900">
                        Install Bethany House
                    </p>
                    <p className="text-xs text-surface-500 mt-0.5">
                        Add to your home screen for quick access and offline use
                    </p>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2 shrink-0">
                    <button
                        onClick={handleDismiss}
                        className="text-surface-400 hover:text-surface-600 transition-colors p-1"
                        aria-label="Dismiss"
                    >
                        <svg
                            className="w-5 h-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                    <button
                        onClick={onInstall}
                        className="btn-primary btn-sm shrink-0"
                    >
                        Install
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── iOS manual install bottom sheet ──────────────────────────────────────────

function IosInstallSheet({ onDismiss }: { onDismiss: () => void }) {
    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 z-[9991] bg-black/30"
                onClick={onDismiss}
                aria-hidden="true"
            />

            {/* Sheet */}
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Install app on iOS"
                className={clsx(
                    "fixed bottom-0 left-0 right-0 z-[9992]",
                    "bg-white rounded-t-2xl shadow-2xl",
                    "px-5 pt-4 pb-8",
                    "animate-slide-up",
                )}
            >
                {/* Handle */}
                <div className="w-10 h-1 rounded-full bg-surface-300 mx-auto mb-4" />

                <div className="flex items-start justify-between mb-4">
                    <div>
                        <h2 className="text-base font-semibold text-surface-900">
                            Add to Home Screen
                        </h2>
                        <p className="text-xs text-surface-500 mt-0.5">
                            Install Bethany House for the best experience
                        </p>
                    </div>
                    <button
                        onClick={onDismiss}
                        className="p-1.5 rounded-lg text-surface-400 hover:text-surface-600 hover:bg-surface-100 transition-colors -mt-0.5"
                        aria-label="Close"
                    >
                        <svg
                            className="w-5 h-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                {/* Steps */}
                <ol className="space-y-3">
                    <IosStep number={1}>
                        Tap the{" "}
                        <span className="inline-flex items-center gap-1 font-medium text-surface-800">
                            <ShareIcon />
                            Share
                        </span>{" "}
                        button at the bottom of Safari
                    </IosStep>

                    <IosStep number={2}>
                        Scroll down and tap{" "}
                        <span className="font-medium text-surface-800">
                            "Add to Home Screen"
                        </span>
                    </IosStep>

                    <IosStep number={3}>
                        Tap{" "}
                        <span className="font-medium text-surface-800">
                            "Add"
                        </span>{" "}
                        in the top-right corner - the app will appear on your
                        home screen
                    </IosStep>
                </ol>

                <p className="mt-4 text-2xs text-surface-400 text-center">
                    You'll get push notifications and offline access after
                    installing
                </p>
            </div>
        </>
    );
}

function IosStep({
    number,
    children,
}: {
    number: number;
    children: React.ReactNode;
}) {
    return (
        <li className="flex items-start gap-3">
            <span className="w-6 h-6 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center shrink-0 mt-0.5">
                {number}
            </span>
            <p className="text-sm text-surface-600 leading-relaxed">
                {children}
            </p>
        </li>
    );
}

function ShareIcon() {
    return (
        <svg
            className="w-4 h-4 inline-block"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth={2}
        >
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8M16 6l-4-4-4 4M12 2v13"
            />
        </svg>
    );
}

// ── Main component ────────────────────────────────────────────────────────────

export function PWAInstallBanner() {
    const {
        isInstallable,
        isOnline,
        needsUpdate,
        applyUpdate,
        showIosInstructions,
        dismissIosInstructions,
        triggerInstallPrompt,
    } = usePWA();

    const [androidDismissed, setAndroidDismissed] = useState(false);

    const handleInstall = async () => {
        const outcome = await triggerInstallPrompt();
        if (outcome === "accepted") {
            setAndroidDismissed(true);
        }
    };

    return (
        <>
            <OfflineBar isOnline={isOnline} />
            <UpdateBanner needsUpdate={needsUpdate} applyUpdate={applyUpdate} />

            {!androidDismissed && (
                <AndroidInstallBanner
                    isInstallable={isInstallable}
                    onInstall={handleInstall}
                    onDismiss={() => setAndroidDismissed(true)}
                />
            )}

            {showIosInstructions && (
                <IosInstallSheet onDismiss={dismissIosInstructions} />
            )}
        </>
    );
}
