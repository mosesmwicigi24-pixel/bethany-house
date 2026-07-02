/**
 * usePullToRefresh.ts - Pull-to-refresh for mobile list pages.
 *
 * Place at: src/lib/usePullToRefresh.ts
 *
 * Attaches to a scrollable container. When the user pulls down from the top
 * beyond the threshold, onRefresh() is called. A visual indicator renders
 * during the pull and while the refresh is in progress.
 *
 * Usage:
 *   const { containerRef, isPulling, isRefreshing, pullProgress } = usePullToRefresh({ onRefresh })
 *   <div ref={containerRef} className="overflow-y-auto">...</div>
 *
 *   // Render indicator above the container:
 *   {(isPulling || isRefreshing) && <PullIndicator progress={pullProgress} refreshing={isRefreshing} />}
 */

import { useRef, useState, useCallback, useEffect } from "react";

interface Options {
    onRefresh: () => Promise<void> | void;
    /** Pull distance in px before triggering refresh. Default: 72 */
    threshold?: number;
    /** Only activate when scrolled to the very top. Default: true */
    onlyAtTop?: boolean;
}

interface PullResult {
    containerRef: React.RefObject<HTMLDivElement>;
    isPulling: boolean;
    isRefreshing: boolean;
    /** 0–1 representing how far through the pull threshold the user is */
    pullProgress: number;
}

export function usePullToRefresh({
    onRefresh,
    threshold = 72,
    onlyAtTop = true,
}: Options): PullResult {
    const containerRef  = useRef<HTMLDivElement>(null);
    const startY        = useRef(0);
    const pulling       = useRef(false);
    const [isPulling,    setIsPulling]    = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [pullProgress, setPullProgress] = useState(0);

    const isAtTop = useCallback(() => {
        if (!onlyAtTop) return true;
        return (containerRef.current?.scrollTop ?? 0) === 0;
    }, [onlyAtTop]);

    const handleTouchStart = useCallback((e: TouchEvent) => {
        if (!isAtTop()) return;
        startY.current = e.touches[0].clientY;
        pulling.current = true;
    }, [isAtTop]);

    const handleTouchMove = useCallback((e: TouchEvent) => {
        if (!pulling.current || isRefreshing) return;
        const delta = e.touches[0].clientY - startY.current;
        if (delta <= 0) { setPullProgress(0); setIsPulling(false); return; }

        // Prevent native scroll bounce competing with our pull indicator
        if (isAtTop() && delta > 0) e.preventDefault();

        const progress = Math.min(delta / threshold, 1);
        setPullProgress(progress);
        setIsPulling(true);
    }, [isRefreshing, isAtTop, threshold]);

    const handleTouchEnd = useCallback(async () => {
        if (!pulling.current) return;
        pulling.current = false;

        if (pullProgress >= 1) {
            setIsPulling(false);
            setIsRefreshing(true);
            setPullProgress(0);
            navigator.vibrate?.(40);
            try {
                await onRefresh();
            } finally {
                setIsRefreshing(false);
            }
        } else {
            setIsPulling(false);
            setPullProgress(0);
        }
    }, [pullProgress, onRefresh]);

    useEffect(() => {
        const el = containerRef.current;
        if (!el) return;

        el.addEventListener("touchstart", handleTouchStart, { passive: true });
        el.addEventListener("touchmove",  handleTouchMove,  { passive: false });
        el.addEventListener("touchend",   handleTouchEnd,   { passive: true });

        return () => {
            el.removeEventListener("touchstart", handleTouchStart);
            el.removeEventListener("touchmove",  handleTouchMove);
            el.removeEventListener("touchend",   handleTouchEnd);
        };
    }, [handleTouchStart, handleTouchMove, handleTouchEnd]);

    return { containerRef, isPulling, isRefreshing, pullProgress };
}