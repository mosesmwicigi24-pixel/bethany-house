/**
 * useSwipeToDelete.ts - Swipe-left-to-delete/action hook.
 *
 * Place at: src/lib/useSwipeToDelete.ts
 *
 * Returns ref + style props to attach to any element.
 * When the user swipes left past the threshold, onAction fires.
 * The element snaps back automatically if released before threshold.
 *
 * Usage:
 *   const { ref, style, isSwiping, progress } = useSwipeToDelete({ onDelete: () => removeItem(idx) })
 *   <div ref={ref} style={style}>...</div>
 *
 * Works on both touch (mobile) and pointer events (desktop drag).
 */

import { useRef, useCallback, CSSProperties } from "react";

interface Options {
    /** Called when the swipe crosses the delete threshold */
    onDelete: () => void;
    /** How far (px) the user must swipe before triggering. Default: 80 */
    threshold?: number;
    /** Whether swiping is enabled. Useful to disable during edit modes. */
    enabled?: boolean;
}

interface SwipeResult {
    ref: React.RefObject<HTMLDivElement>;
    /** Apply to the swipeable element for smooth translate */
    style: CSSProperties;
    isSwiping: boolean;
}

export function useSwipeToDelete({
    onDelete,
    threshold = 80,
    enabled = true,
}: Options): SwipeResult {
    const ref        = useRef<HTMLDivElement>(null);
    const startX     = useRef(0);
    const currentX   = useRef(0);
    const swiping    = useRef(false);
    const triggered  = useRef(false);

    // We use a direct DOM style mutation during the swipe for 60fps performance,
    // then sync back to React state on release.
    const applyTranslate = (x: number) => {
        if (!ref.current) return;
        const clamped = Math.min(0, x); // only allow left swipe
        ref.current.style.transform     = `translateX(${clamped}px)`;
        ref.current.style.transition    = "none";
        ref.current.style.opacity       = String(1 - Math.abs(clamped) / (threshold * 2));
    };

    const reset = useCallback(() => {
        if (!ref.current) return;
        ref.current.style.transition = "transform 250ms ease, opacity 250ms ease";
        ref.current.style.transform  = "translateX(0)";
        ref.current.style.opacity    = "1";
        swiping.current  = false;
        triggered.current = false;
    }, []);

    const onPointerDown = useCallback((e: React.PointerEvent) => {
        if (!enabled) return;
        startX.current  = e.clientX;
        currentX.current = e.clientX;
        swiping.current  = true;
        triggered.current = false;
        ref.current?.setPointerCapture(e.pointerId);
    }, [enabled]);

    const onPointerMove = useCallback((e: React.PointerEvent) => {
        if (!swiping.current || !enabled) return;
        currentX.current = e.clientX;
        const delta = currentX.current - startX.current;
        applyTranslate(delta);

        // Trigger once at threshold
        if (delta < -threshold && !triggered.current) {
            triggered.current = true;
            // Light haptic
            navigator.vibrate?.(30);
        }
    }, [enabled, threshold]);

    const onPointerUp = useCallback(() => {
        if (!swiping.current) return;
        const delta = currentX.current - startX.current;
        swiping.current = false;

        if (delta < -threshold) {
            // Animate out fully then call delete
            if (ref.current) {
                ref.current.style.transition = "transform 200ms ease, opacity 200ms ease";
                ref.current.style.transform  = "translateX(-100%)";
                ref.current.style.opacity    = "0";
            }
            // Wait for animation before calling delete so the UI transition is clean
            setTimeout(onDelete, 200);
        } else {
            reset();
        }
    }, [threshold, onDelete, reset]);

    return {
        ref,
        style: {}, // direct DOM mutation handles style, no React re-renders during drag
        isSwiping: swiping.current,
        // Spread these onto the element:
        // onPointerDown, onPointerMove, onPointerUp
        // Exported separately because React spreads need explicit names.
        ...(enabled ? { onPointerDown, onPointerMove, onPointerUp } : {}),
    } as SwipeResult & {
        onPointerDown?: React.PointerEventHandler;
        onPointerMove?: React.PointerEventHandler;
        onPointerUp?: React.PointerEventHandler;
    };
}