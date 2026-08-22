// src/pages/insights/AudiencePanel.tsx
//
// Devices and operating systems in ONE panel. This was a 200px donut plus a
// 220px recharts bar chart across two cards — roughly 450px of vertical space
// to communicate three percentages and a short list. Segmented bars and an
// inline list say the same thing in a third of the room, and both read at a
// glance instead of needing a legend.
import { clsx } from "clsx";
import { CHART_COLORS } from "@/pages/reports/reportShared";
import { fmtInt } from "./insightsShared";

export const POWER_SIGNAL_NOTE =
    "Device mix is a rough purchasing-power read, not a precise measure: mostly-desktop traffic skews higher-income and diaspora, mostly-mobile skews local mass-market.";

interface Segment { name: string; value: number }

/** Fixed order and colour per device, so the bar does not reshuffle between periods. */
const DEVICE_ORDER = ["mobile", "desktop", "tablet"];
const DEVICE_COLOR: Record<string, string> = {
    mobile: CHART_COLORS[0],   // brand orange
    desktop: CHART_COLORS[1],  // info blue
    tablet: CHART_COLORS[3],   // accent purple
};
const DEVICE_OTHER = CHART_COLORS[9]; // neutral

function pct(value: number, total: number) {
    return total > 0 ? (value / total) * 100 : 0;
}

export function AudiencePanel({
    devices,
    os,
    mobileShare,
}: {
    devices: Segment[];
    os: Segment[];
    mobileShare: number;
}) {
    const deviceTotal = devices.reduce((sum, d) => sum + d.value, 0);
    const ordered = [...devices].sort((a, b) => {
        const ai = DEVICE_ORDER.indexOf(a.name.toLowerCase());
        const bi = DEVICE_ORDER.indexOf(b.name.toLowerCase());
        return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
    });

    const osTotal = os.reduce((sum, o) => sum + o.value, 0);
    const osTop = os.slice(0, 6);

    const signal =
        mobileShare >= 75
            ? { label: "Mostly mobile — mass-market reach", tone: "text-info-dark" }
            : mobileShare <= 40
              ? { label: "Desktop-heavy — higher-intent / diaspora", tone: "text-brand-700" }
              : { label: "Balanced mobile and desktop", tone: "text-success-dark" };

    return (
        <div className="card overflow-hidden">
            <div className="flex flex-col gap-1 px-5 pt-5 sm:flex-row sm:items-baseline sm:justify-between">
                <h3 className="font-semibold text-surface-900">How they browse</h3>
                <p className={clsx("text-xs font-medium", signal.tone)} title={POWER_SIGNAL_NOTE}>
                    {signal.label}
                    <span className="ml-1 cursor-help text-surface-500">ⓘ</span>
                </p>
            </div>

            {/* 2-up from md, so iPad portrait (768px — the hub's most common
                tablet width) gets both columns side by side. */}
            <div className="grid grid-cols-1 gap-6 px-5 py-4 md:grid-cols-2 md:gap-8">
                {/* ── Devices: one segmented bar, then the readout ── */}
                <div>
                    <p className="text-2xs font-semibold uppercase tracking-wider text-surface-500">Devices</p>
                    {deviceTotal === 0 ? (
                        <p className="py-6 text-sm text-surface-400">No device data in this period.</p>
                    ) : (
                        <>
                            <div className="mt-2.5 flex h-2.5 w-full overflow-hidden rounded-full bg-surface-100">
                                {ordered.map((segment) => (
                                    <div
                                        key={segment.name}
                                        className="h-full first:rounded-l-full last:rounded-r-full"
                                        style={{
                                            width: `${pct(segment.value, deviceTotal)}%`,
                                            backgroundColor: DEVICE_COLOR[segment.name.toLowerCase()] ?? DEVICE_OTHER,
                                        }}
                                    />
                                ))}
                            </div>
                            <ul className="mt-3 space-y-1.5">
                                {ordered.map((segment) => (
                                    <li key={segment.name} className="flex items-center gap-2 text-sm">
                                        <span
                                            className="h-2 w-2 shrink-0 rounded-full"
                                            style={{ backgroundColor: DEVICE_COLOR[segment.name.toLowerCase()] ?? DEVICE_OTHER }}
                                        />
                                        <span className="flex-1 capitalize text-surface-700">{segment.name}</span>
                                        <span className="tabular-nums text-surface-500">{fmtInt(segment.value)}</span>
                                        <span className="w-10 text-right font-semibold tabular-nums text-surface-900">
                                            {Math.round(pct(segment.value, deviceTotal))}%
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>

                {/* ── Operating systems: list with the bar behind the label ── */}
                <div>
                    <p className="text-2xs font-semibold uppercase tracking-wider text-surface-500">Operating systems</p>
                    {osTotal === 0 ? (
                        <p className="py-6 text-sm text-surface-400">No OS data in this period.</p>
                    ) : (
                        <ul className="mt-2.5 space-y-1">
                            {osTop.map((row) => (
                                <li key={row.name} className="relative flex items-center gap-2 overflow-hidden rounded px-2 py-1 text-sm">
                                    <span
                                        aria-hidden="true"
                                        className="absolute inset-y-0 left-0 rounded bg-info-light"
                                        style={{ width: `${pct(row.value, osTotal)}%` }}
                                    />
                                    <span className="relative flex-1 truncate text-surface-700">{row.name}</span>
                                    <span className="relative tabular-nums text-surface-500">{fmtInt(row.value)}</span>
                                    <span className="relative w-10 text-right font-semibold tabular-nums text-surface-900">
                                        {Math.round(pct(row.value, osTotal))}%
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
