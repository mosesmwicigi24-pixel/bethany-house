/**
 * PullRefreshIndicator.tsx - Visual indicator for pull-to-refresh.
 *
 * Place at: src/components/pwa/PullRefreshIndicator.tsx
 *
 * Renders a circular spinner that grows as the user pulls down.
 * Transitions to a spinning animation while the refresh is in progress.
 *
 * Usage:
 *   <PullRefreshIndicator progress={pullProgress} refreshing={isRefreshing} />
 */

import { clsx } from "clsx";

interface Props {
    /** 0–1, how far through the threshold the pull is */
    progress: number;
    /** True while the async refresh is running */
    refreshing: boolean;
}

export function PullRefreshIndicator({ progress, refreshing }: Props) {
    if (!refreshing && progress === 0) return null;

    const size   = 32;
    const radius = 12;
    const circ   = 2 * Math.PI * radius;
    const dash   = refreshing ? circ : circ * progress;

    return (
        <div
            className="flex items-center justify-center py-2 shrink-0"
            style={{
                height:    `${Math.round(progress * 48 + (refreshing ? 48 : 0))}px`,
                overflow:  "hidden",
                transition: "height 150ms ease",
            }}
            aria-live="polite"
            aria-label={refreshing ? "Refreshing…" : "Pull to refresh"}
        >
            <div
                className={clsx(
                    "rounded-full bg-white shadow-md flex items-center justify-center border border-surface-100",
                    refreshing && "animate-spin",
                )}
                style={{ width: size, height: size }}
            >
                <svg
                    width={size}
                    height={size}
                    viewBox={`0 0 ${size} ${size}`}
                    className="text-brand-500"
                >
                    {/* Track */}
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="currentColor"
                        strokeOpacity={0.15}
                        strokeWidth={2.5}
                    />
                    {/* Progress arc */}
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={2.5}
                        strokeDasharray={`${dash} ${circ}`}
                        strokeLinecap="round"
                        transform={`rotate(-90 ${size / 2} ${size / 2})`}
                        style={{ transition: refreshing ? "none" : "stroke-dasharray 80ms linear" }}
                    />
                </svg>
            </div>
        </div>
    );
}