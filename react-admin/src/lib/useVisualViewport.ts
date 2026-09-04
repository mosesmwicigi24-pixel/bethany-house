/**
 * useVisualViewport.ts - Tracks the visual viewport height and offset.
 *
 * Place at: src/lib/useVisualViewport.ts
 *
 * On mobile browsers, opening the software keyboard shrinks the visible area
 * but does NOT change window.innerHeight or 100vh. This causes fixed-bottom
 * composers to be hidden behind the keyboard.
 *
 * The Visual Viewport API gives us the accurate visible height in real time.
 * We expose it as a CSS custom property (--visual-vh) AND as a React state
 * so components can react to keyboard open/close.
 *
 * Usage:
 *   useVisualViewport()  - call once at the CommsHub root, sets CSS vars
 *
 *   In CSS: bottom: calc(100vh - var(--visual-viewport-height, 100vh))
 *   Or use the returned keyboardHeight: number directly.
 */

import { useState, useEffect } from "react";

interface VisualViewportState {
    /** Height of the visible viewport (shrinks when keyboard opens) */
    viewportHeight: number;
    /** How much the keyboard is pushing content up (0 when closed) */
    keyboardHeight: number;
    /** True when the software keyboard is likely open */
    keyboardOpen: boolean;
}

/** Is the focused element one that summons the software keyboard? */
function editableFocused(): boolean {
    const el = document.activeElement;
    if (!el) return false;
    if (el instanceof HTMLTextAreaElement) return !el.readOnly && !el.disabled;
    if (el instanceof HTMLInputElement) {
        // Types that never raise a keyboard don't count.
        const silent = ["checkbox", "radio", "button", "submit", "reset", "file", "range", "color"];
        return !el.readOnly && !el.disabled && !silent.includes(el.type);
    }
    return el instanceof HTMLElement && el.isContentEditable;
}

export function useVisualViewport(): VisualViewportState {
    const [state, setState] = useState<VisualViewportState>({
        viewportHeight: window.innerHeight,
        keyboardHeight: 0,
        keyboardOpen:   false,
    });

    useEffect(() => {
        const vv = window.visualViewport;
        if (!vv) return;

        // The old detector compared innerHeight against vv.height. That works
        // in a Safari TAB, where innerHeight holds still while vv shrinks —
        // but in the installed home-screen PWA (and on Android, where the
        // keyboard resizes the whole layout viewport) BOTH shrink together,
        // the difference reads ~0, and "keyboard open" never fired: the tab
        // bar stayed up while typing, exactly where the hub is used most.
        // A focused editable on a touch screen IS the keyboard, however the
        // browser chooses to report its geometry — so that is the primary
        // signal, with the viewport difference kept as a second trigger for
        // browsers that report it. Known trade: an iPad typing on a hardware
        // keyboard reads as "keyboard open" while a field is focused — a
        // hidden tab bar there is accepted for layouts that are correct on
        // every phone.
        const coarse = window.matchMedia?.("(pointer: coarse)").matches ?? false;

        const update = () => {
            const viewportHeight = vv.height;
            const keyboardHeight = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
            const keyboardOpen   = (coarse && editableFocused())
                || keyboardHeight > 60; // 60px threshold filters toolbar resizes

            // Expose as CSS custom properties so pure-CSS layouts can react
            document.documentElement.style.setProperty(
                "--visual-viewport-height", `${viewportHeight}px`
            );
            document.documentElement.style.setProperty(
                "--keyboard-height", `${keyboardHeight}px`
            );

            setState({ viewportHeight, keyboardHeight, keyboardOpen });
        };

        // focusin/focusout drive the primary signal; a short delay on focusout
        // lets focus land on the next field before declaring the keyboard gone,
        // so tapping between inputs doesn't flash the tab bar in and out.
        let blurTimer: number | undefined;
        const onFocusIn  = () => { window.clearTimeout(blurTimer); update(); };
        const onFocusOut = () => { blurTimer = window.setTimeout(update, 120); };

        update();
        vv.addEventListener("resize", update);
        vv.addEventListener("scroll", update);
        document.addEventListener("focusin", onFocusIn);
        document.addEventListener("focusout", onFocusOut);

        return () => {
            window.clearTimeout(blurTimer);
            vv.removeEventListener("resize", update);
            vv.removeEventListener("scroll", update);
            document.removeEventListener("focusin", onFocusIn);
            document.removeEventListener("focusout", onFocusOut);
        };
    }, []);

    return state;
}