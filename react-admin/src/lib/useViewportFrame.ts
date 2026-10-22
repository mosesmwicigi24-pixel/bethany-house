/**
 * useViewportFrame — the app shell follows the phone's keyboard in ONE motion.
 *
 * What the screen recording showed (owner, 22 Oct 2026): with the keyboard up
 * the composer parked mid-screen above a dead grey band, the header slid
 * under the clock, and the page settled in visible steps. Four things were
 * moving it, none in step with the keyboard:
 *   1. iOS PANS the layout viewport to reveal the focused field, and the shell
 *      answered by scrolling the window back to zero — one jump;
 *   2. the shell was re-sized to visualViewport.height on three timers (at
 *      once, 150 ms, 400 ms) because iOS reports the viewport part-way through
 *      its animation — a staircase, each step a move;
 *   3. the thread scrolled to its bottom on a 100 ms timer — another jump;
 *   4. the dead band was the gap between a stale measurement and the real
 *      keyboard edge.
 *
 * This hook replaces the timers with a FRAME-SYNCED FOLLOW. From the moment
 * anything can move the keyboard (focus in/out, a visual-viewport resize or
 * scroll, a window resize) it re-reads the visual viewport on every animation
 * frame for the length of the keyboard's animation and writes the result the
 * instant it changes:
 *   --vh      1% of the visible height (the long-standing h-screen-safe unit)
 *   --vv-h    the visible height itself
 *   --kb-h    how far the keyboard intrudes below the visible area (0 closed)
 * It also pins the window at scroll 0 (an app shell has nothing to scroll) and,
 * while iOS has the page panned, translates the shell DOWN by the pan so it
 * stays exactly where it was on screen instead of sliding under the status
 * bar. The transform is removed the moment the pan is gone, so modals that
 * position themselves against the viewport are never caught by it.
 *
 * Nothing here animates. The keyboard is the only animation; the shell simply
 * tracks it frame by frame, and the thread's own resize anchoring keeps the
 * newest message in view with no scroll timer at all.
 */

import { useEffect, type RefObject } from "react";

/** How long after a trigger to keep re-reading, frame by frame. iOS keyboard animations run ~250–400 ms. */
const FOLLOW_MS = 700;

export function useViewportFrame(shell: RefObject<HTMLElement | null>): void {
    useEffect(() => {
        const root = document.documentElement;
        const vv   = window.visualViewport;
        let raf = 0;
        let followUntil = 0;
        let last = { h: -1, top: -1, kb: -1 };

        const apply = () => {
            const h   = vv?.height ?? window.innerHeight;
            const top = vv?.offsetTop ?? 0;
            const kb  = Math.max(0, window.innerHeight - h - top);

            if (h !== last.h) {
                root.style.setProperty("--vh", `${h * 0.01}px`);
                root.style.setProperty("--vv-h", `${h}px`);
            }
            if (kb !== last.kb) root.style.setProperty("--kb-h", `${kb}px`);
            if (top !== last.top && shell.current) {
                // Keep the shell where it was while iOS pans the page; a clean
                // zero removes the transform entirely (see the docblock).
                shell.current.style.transform = top > 0 ? `translate3d(0, ${top}px, 0)` : "";
            }
            last = { h, top, kb };

            // An app shell never scrolls the window; every pane scrolls inside.
            if (window.scrollY > 0 || root.scrollTop > 0) window.scrollTo(0, 0);
        };

        const tick = () => {
            apply();
            raf = performance.now() < followUntil ? requestAnimationFrame(tick) : 0;
        };
        const follow = () => {
            followUntil = performance.now() + FOLLOW_MS;
            if (!raf) raf = requestAnimationFrame(tick);
        };

        apply();
        window.addEventListener("resize", follow);
        vv?.addEventListener("resize", follow);
        vv?.addEventListener("scroll", follow);
        document.addEventListener("focusin", follow);
        document.addEventListener("focusout", follow);
        // Returning from the app switcher or rotating the phone.
        window.addEventListener("orientationchange", follow);
        document.addEventListener("visibilitychange", follow);

        return () => {
            if (raf) cancelAnimationFrame(raf);
            window.removeEventListener("resize", follow);
            vv?.removeEventListener("resize", follow);
            vv?.removeEventListener("scroll", follow);
            document.removeEventListener("focusin", follow);
            document.removeEventListener("focusout", follow);
            window.removeEventListener("orientationchange", follow);
            document.removeEventListener("visibilitychange", follow);
            if (shell.current) shell.current.style.transform = "";
        };
    }, [shell]);
}
