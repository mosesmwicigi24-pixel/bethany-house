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
 * It also pins the window at scroll 0 (an app shell has nothing to scroll)
 * and, when iOS pans the visual viewport to reveal the focused field, pans
 * it back with scrollIntoView on the shell (scrollTo cannot move the visual
 * viewport). Because the shell has already shrunk to the visible height the
 * field is on screen without the pan.
 *
 * NO TRANSFORM. The first version of this hook also translated the shell down
 * by the pan "to hold it in place". The owner's second recording (22 Oct
 * 2026, 22:19) showed what that does: the pan is cancelled by the scroll
 * reset while the translate stays for a frame, so the shell drops under the
 * status bar and the composer under the keyboard, then snaps back — a shake
 * on every keystroke. Worse, WebKit paints a textarea's caret at its
 * UNTRANSFORMED position when an ancestor is translated, which is why the
 * caret sat inside the text and, in one frame, below the composer entirely.
 * Never put a transform on the shell while a field is focused.
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
            last = { h, top, kb };

            // An app shell never scrolls the window; every pane scrolls inside.
            if (window.scrollY > 0 || root.scrollTop > 0) window.scrollTo(0, 0);

            // iOS pans the VISUAL viewport to reveal the focused field. That
            // shows up as visualViewport.offsetTop with window.scrollY still
            // 0, and scrollTo does nothing against it (the owner's third
            // recording, 22 Oct 2026 22:49: header under the address bar, a
            // dead band under the composer the height of the pan). The one
            // call that moves the visual viewport is scrollIntoView — the
            // same call #360 took out of the thread because it panned the
            // page. Pointed at the shell's top it pans the page BACK, and
            // since the shell is already the visible height, the composer
            // lands on the keyboard with no band.
            if (top > 0 && shell.current) {
                shell.current.scrollIntoView({ block: "start", inline: "nearest", behavior: "instant" });
            }
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
        };
    }, [shell]);
}
