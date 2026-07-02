/**
 * DateRangePicker.tsx
 *
 * A compact date-range selector used on the Dashboard and Reports pages.
 * Provides preset ranges (Today, This Week, This Month, Last Month, Custom)
 * via a dropdown. When "Custom" is selected, two date inputs appear.
 *
 * Usage:
 *   const [range, setRange] = useState<DateRange>(PRESET_RANGES[1]); // "This Week"
 *
 *   <DateRangePicker value={range} onChange={setRange} />
 *
 *   // Use range.from and range.to in your API call:
 *   const { data } = useQuery({
 *     queryKey: ["dashboard", range.from, range.to],
 *     queryFn: () => get("/v1/admin/dashboard", { params: { from: range.from, to: range.to } }),
 *   });
 */

import { useState, useRef, useEffect } from "react";
import { clsx } from "clsx";
import { format, startOfDay, endOfDay, startOfWeek, endOfWeek,
         startOfMonth, endOfMonth, subMonths, subDays } from "date-fns";

// ─── Types ────────────────────────────────────────────────────────────────────

export interface DateRange {
    label: string;
    from: string;   // ISO date string YYYY-MM-DD
    to: string;     // ISO date string YYYY-MM-DD
    preset?: string;
}

// ─── Preset ranges ────────────────────────────────────────────────────────────

function iso(d: Date) {
    return format(d, "yyyy-MM-dd");
}

export function buildPresets(): DateRange[] {
    const today = new Date();
    return [
        {
            label: "Today",
            preset: "today",
            from: iso(startOfDay(today)),
            to: iso(endOfDay(today)),
        },
        {
            label: "Yesterday",
            preset: "yesterday",
            from: iso(startOfDay(subDays(today, 1))),
            to: iso(endOfDay(subDays(today, 1))),
        },
        {
            label: "This Week",
            preset: "this_week",
            from: iso(startOfWeek(today, { weekStartsOn: 1 })),
            to: iso(endOfWeek(today, { weekStartsOn: 1 })),
        },
        {
            label: "This Month",
            preset: "this_month",
            from: iso(startOfMonth(today)),
            to: iso(endOfMonth(today)),
        },
        {
            label: "Last Month",
            preset: "last_month",
            from: iso(startOfMonth(subMonths(today, 1))),
            to: iso(endOfMonth(subMonths(today, 1))),
        },
        {
            label: "Last 7 Days",
            preset: "last_7",
            from: iso(subDays(today, 6)),
            to: iso(today),
        },
        {
            label: "Last 30 Days",
            preset: "last_30",
            from: iso(subDays(today, 29)),
            to: iso(today),
        },
        {
            label: "Last 90 Days",
            preset: "last_90",
            from: iso(subDays(today, 89)),
            to: iso(today),
        },
    ];
}

// ─── Component ────────────────────────────────────────────────────────────────

interface DateRangePickerProps {
    value: DateRange;
    onChange: (range: DateRange) => void;
    className?: string;
}

export function DateRangePicker({ value, onChange, className }: DateRangePickerProps) {
    const [open, setOpen] = useState(false);
    const [customFrom, setCustomFrom] = useState(value.from);
    const [customTo, setCustomTo] = useState(value.to);
    const [showCustom, setShowCustom] = useState(value.preset === "custom");
    const ref = useRef<HTMLDivElement>(null);

    const presets = buildPresets();

    // Close on outside click
    useEffect(() => {
        const h = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener("mousedown", h);
        return () => document.removeEventListener("mousedown", h);
    }, []);

    const selectPreset = (preset: DateRange) => {
        setShowCustom(false);
        onChange(preset);
        setOpen(false);
    };

    const applyCustom = () => {
        if (!customFrom || !customTo) return;
        onChange({
            label: `${customFrom} – ${customTo}`,
            preset: "custom",
            from: customFrom,
            to: customTo,
        });
        setOpen(false);
    };

    const displayLabel = value.preset === "custom"
        ? `${value.from} – ${value.to}`
        : value.label;

    return (
        <div className={clsx("relative", className)} ref={ref}>
            {/* Trigger button */}
            <button
                onClick={() => setOpen((v) => !v)}
                className={clsx(
                    "inline-flex items-center gap-2 px-3 py-2 rounded-lg border text-sm font-medium transition-colors",
                    open
                        ? "bg-surface-100 border-surface-300 text-surface-900"
                        : "bg-white border-surface-200 text-surface-700 hover:bg-surface-50",
                )}
            >
                <svg className="w-4 h-4 text-surface-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <span>{displayLabel}</span>
                <svg
                    className={clsx("w-3.5 h-3.5 text-surface-400 transition-transform", open && "rotate-180")}
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {/* Dropdown */}
            {open && (
                <div className="absolute right-0 top-full mt-1.5 w-56 bg-white rounded-xl shadow-card-lg border border-surface-100 py-1.5 z-50 animate-slide-down">
                    {presets.map((p) => (
                        <button
                            key={p.preset}
                            onClick={() => selectPreset(p)}
                            className={clsx(
                                "w-full text-left px-4 py-2 text-sm transition-colors",
                                value.preset === p.preset
                                    ? "bg-brand-50 text-brand-700 font-medium"
                                    : "text-surface-700 hover:bg-surface-50",
                            )}
                        >
                            {p.label}
                        </button>
                    ))}

                    {/* Divider */}
                    <div className="my-1 border-t border-surface-100" />

                    {/* Custom range toggle */}
                    <button
                        onClick={() => setShowCustom((v) => !v)}
                        className={clsx(
                            "w-full text-left px-4 py-2 text-sm transition-colors",
                            showCustom
                                ? "bg-brand-50 text-brand-700 font-medium"
                                : "text-surface-700 hover:bg-surface-50",
                        )}
                    >
                        Custom range…
                    </button>

                    {showCustom && (
                        <div className="px-3 py-3 border-t border-surface-100 space-y-2">
                            <div>
                                <label className="label">From</label>
                                <input
                                    type="date"
                                    value={customFrom}
                                    max={customTo || undefined}
                                    onChange={(e) => setCustomFrom(e.target.value)}
                                    className="input text-xs"
                                />
                            </div>
                            <div>
                                <label className="label">To</label>
                                <input
                                    type="date"
                                    value={customTo}
                                    min={customFrom || undefined}
                                    onChange={(e) => setCustomTo(e.target.value)}
                                    className="input text-xs"
                                />
                            </div>
                            <button
                                onClick={applyCustom}
                                disabled={!customFrom || !customTo}
                                className="btn btn-primary btn-sm w-full"
                            >
                                Apply
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}