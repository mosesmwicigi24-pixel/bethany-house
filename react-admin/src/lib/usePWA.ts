/**
 * usePWA.ts — Central PWA state hook.
 *
 * Zero extra dependencies — uses only the native browser Service Worker API.
 * workbox-window and virtual:pwa-register are NOT imported here; both require
 * packages/virtual modules that aren't reliably available in the app bundle.
 *
 * SW registration is handled via navigator.serviceWorker directly.
 * Update detection listens for the native "updatefound" + statechange events.
 */

import { useState, useEffect, useCallback, useRef } from "react";

// ── Types ─────────────────────────────────────────────────────────────────────

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

export interface PWAState {
    isInstallable: boolean;
    isInstalled: boolean;
    isIos: boolean;
    showIosInstructions: boolean;
    dismissIosInstructions: () => void;
    triggerInstallPrompt: () => Promise<"accepted" | "dismissed" | null>;
    needsUpdate: boolean;
    applyUpdate: () => void;
    isOnline: boolean;
}

// ── Hook ──────────────────────────────────────────────────────────────────────

export function usePWA(): PWAState {

    // ── SW registration + update detection ───────────────────────────────────
    const [needsUpdate, setNeedsUpdate] = useState(false);
    const waitingSWRef = useRef<ServiceWorker | null>(null);

    useEffect(() => {
        if (!("serviceWorker" in navigator)) return;
        // vite-plugin-pwa registers the SW automatically via its own script.
        // We just listen for update events on the existing registration.
        const checkRegistration = async () => {
            try {
                // Use import.meta.env.BASE_URL so this works under any base path (e.g. /admin/)
        const reg = await navigator.serviceWorker.getRegistration(import.meta.env.BASE_URL);
                if (!reg) return;

                // A SW is already waiting — show update banner immediately
                if (reg.waiting) {
                    waitingSWRef.current = reg.waiting;
                    setNeedsUpdate(true);
                }

                // Listen for a new SW installing in the future
                reg.addEventListener("updatefound", () => {
                    const newSW = reg.installing;
                    if (!newSW) return;
                    newSW.addEventListener("statechange", () => {
                        // "installed" + an active controller = update is ready
                        if (
                            newSW.state === "installed" &&
                            navigator.serviceWorker.controller
                        ) {
                            waitingSWRef.current = newSW;
                            setNeedsUpdate(true);
                        }
                    });
                });

                // Check for updates every 60 minutes
                const interval = setInterval(() => reg.update(), 60 * 60 * 1000);
                return () => clearInterval(interval);
            } catch (err) {
                console.error("[PWA] SW registration check failed:", err);
            }
        };

        checkRegistration();
    }, []);

    const applyUpdate = useCallback(() => {
        const sw = waitingSWRef.current;
        if (!sw) return;
        // Tell the waiting SW to skip waiting
        sw.postMessage({ type: "SKIP_WAITING" });
        // Reload once the new SW takes control
        navigator.serviceWorker.addEventListener("controllerchange", () => {
            window.location.reload();
        }, { once: true });
    }, []);

    // ── Install prompt (Android / Desktop Chrome) ─────────────────────────────
    const deferredPrompt = useRef<BeforeInstallPromptEvent | null>(null);
    const [isInstallable, setIsInstallable] = useState(false);

    useEffect(() => {
        const handler = (e: Event) => {
            e.preventDefault();
            deferredPrompt.current = e as BeforeInstallPromptEvent;
            setIsInstallable(true);
        };
        window.addEventListener("beforeinstallprompt", handler);
        window.addEventListener("appinstalled", () => {
            deferredPrompt.current = null;
            setIsInstallable(false);
        });
        return () => window.removeEventListener("beforeinstallprompt", handler);
    }, []);

    const triggerInstallPrompt = useCallback(async () => {
        if (!deferredPrompt.current) return null;
        await deferredPrompt.current.prompt();
        const { outcome } = await deferredPrompt.current.userChoice;
        deferredPrompt.current = null;
        setIsInstallable(false);
        return outcome;
    }, []);

    // ── Installed / standalone detection ──────────────────────────────────────
    const isInstalled =
        window.matchMedia("(display-mode: standalone)").matches ||
        (window.navigator as Navigator & { standalone?: boolean }).standalone === true;

    // ── iOS detection ─────────────────────────────────────────────────────────
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);

    const [showIosInstructions, setShowIosInstructions] = useState(() => {
        if (!isIos || isInstalled) return false;
        return sessionStorage.getItem("ios-install-dismissed") !== "true";
    });

    const dismissIosInstructions = useCallback(() => {
        sessionStorage.setItem("ios-install-dismissed", "true");
        setShowIosInstructions(false);
    }, []);

    // ── Online / offline ──────────────────────────────────────────────────────
    const [isOnline, setIsOnline] = useState(navigator.onLine);

    useEffect(() => {
        const onOnline  = () => setIsOnline(true);
        const onOffline = () => setIsOnline(false);
        window.addEventListener("online",  onOnline);
        window.addEventListener("offline", onOffline);
        return () => {
            window.removeEventListener("online",  onOnline);
            window.removeEventListener("offline", onOffline);
        };
    }, []);

    return {
        isInstallable,
        isInstalled,
        isIos,
        showIosInstructions,
        dismissIosInstructions,
        triggerInstallPrompt,
        needsUpdate,
        applyUpdate,
        isOnline,
    };
}