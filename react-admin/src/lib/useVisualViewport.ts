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

export function useVisualViewport(): VisualViewportState {
    const [state, setState] = useState<VisualViewportState>({
        viewportHeight: window.innerHeight,
        keyboardHeight: 0,
        keyboardOpen:   false,
    });

    useEffect(() => {
        const vv = window.visualViewport;
        if (!vv) return;

        const update = () => {
            const viewportHeight = vv.height;
            const keyboardHeight = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
            const keyboardOpen   = keyboardHeight > 60; // 60px threshold to filter toolbar resize

            // Expose as CSS custom properties so pure-CSS layouts can react
            document.documentElement.style.setProperty(
                "--visual-viewport-height", `${viewportHeight}px`
            );
            document.documentElement.style.setProperty(
                "--keyboard-height", `${keyboardHeight}px`
            );

            setState({ viewportHeight, keyboardHeight, keyboardOpen });
        };

        update();
        vv.addEventListener("resize", update);
        vv.addEventListener("scroll", update);

        return () => {
            vv.removeEventListener("resize", update);
            vv.removeEventListener("scroll", update);
        };
    }, []);

    return state;
}