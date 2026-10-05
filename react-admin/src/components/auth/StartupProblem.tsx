import { useEffect, useRef, useState } from "react";
import { useAuthStore } from "@/store/auth.store";
import { Spinner } from "@/components/ui/Spinner";

// Waits between automatic attempts; the last one repeats.
const RETRY_DELAYS_MS = [5_000, 15_000, 30_000];

/**
 * Shown instead of the login page when the signed-in user could not be loaded
 * for a reason that is not the session's fault (no connection, server down or
 * busy). The session is kept: it tries again by itself — straight away when
 * the device comes back online — and the person can also retry or sign out.
 */
export function StartupProblem() {
    const { startupError, isLoading, fetchMe, logout } = useAuthStore();
    const attempt = useRef(0);
    // Sign-out first unregisters push, which can wait ~2s for the service
    // worker — show that it is happening.
    const [signingOut, setSigningOut] = useState(false);

    useEffect(() => {
        if (isLoading || signingOut) return;
        const delay = RETRY_DELAYS_MS[Math.min(attempt.current, RETRY_DELAYS_MS.length - 1)];
        const timer = window.setTimeout(() => {
            attempt.current += 1;
            fetchMe();
        }, delay);
        const onOnline = () => fetchMe();
        window.addEventListener("online", onOnline);
        return () => {
            window.clearTimeout(timer);
            window.removeEventListener("online", onOnline);
        };
    }, [isLoading, signingOut, fetchMe]);

    const offline = startupError === "offline";

    return (
        <div className="min-h-screen flex items-center justify-center bg-surface-50 px-4">
            <div className="card w-full max-w-sm p-6 text-center" role="alert">
                <h1 className="text-lg font-bold text-surface-900">
                    Can't reach Bethany House
                </h1>
                <p className="mt-2 text-sm text-surface-600">
                    {offline
                        ? "This device has no internet connection. You are still signed in — it will reconnect by itself when the connection is back."
                        : "The server isn't answering right now. You are still signed in — it will keep trying by itself."}
                </p>
                <div className="mt-5 flex flex-col gap-2">
                    <button
                        type="button"
                        className="btn btn-primary w-full min-h-[48px]"
                        onClick={() => fetchMe()}
                        disabled={isLoading || signingOut}
                    >
                        {isLoading ? (
                            <span className="inline-flex items-center gap-2">
                                <Spinner size="sm" /> Trying again…
                            </span>
                        ) : (
                            "Try again"
                        )}
                    </button>
                    <button
                        type="button"
                        className="btn btn-secondary w-full min-h-[48px]"
                        onClick={() => {
                            setSigningOut(true);
                            logout();
                        }}
                        disabled={isLoading || signingOut}
                    >
                        {signingOut ? "Signing out…" : "Sign out"}
                    </button>
                </div>
            </div>
        </div>
    );
}
