// src/pages/reports/reportShared.tsx
// Shared components, constants, types, and hooks used across all report tab pages.

import { useState, useCallback, useRef } from "react";
import { useSearchParams, useNavigate } from "react-router-dom";
import { tokenStorage } from "@/api/client";
import { useToastStore } from "@/store/toast.store";
import { usePermissions } from "@/hooks/usePermissions";
import { clsx } from "clsx";
import dayjs from "dayjs";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
    reportsApi,
    DATE_PRESETS,
    datePresetRange,
    type DatePreset,
    type ReportType,
    type ScheduleFrequency,
    type ExportFormat,
    type SchedulePayload,
} from "@/api/reports";
import { heldFromFetch } from "@/api/downloads";

// ─── Constants ────────────────────────────────────────────────────────────────

// Full-width KPI row (the owner's rule: always use the screen). 2-up on
// phones, 4-up on laptops; on big displays auto-fit packs as many ~210px
// cards as fit (8+ across) and stretches a short row to fill the width —
// no ghost columns either way.
export const KPI_GRID = "grid grid-cols-2 md:grid-cols-4 2xl:grid-cols-[repeat(auto-fit,minmax(210px,1fr))] gap-3";

export const CHART_COLORS = [
    // Derived from the design tokens, so a palette change happens in
    // tailwind.config.js and not here.
    // Ordered for MAXIMUM ADJACENT SEPARATION: six saturated hues first, then
    // light tints, then neutral. An earlier version paired 600 and 700 rungs of
    // the same family (success-600 vs success-700) — adjacent series in a chart
    // then differed by a couple of CIELAB units and were effectively the same
    // colour, which defeats the only job a categorical scale has.
    "#f05423", // brand 500    — orange
    "#2563eb", // info 600     — blue
    "#16a34a", // success 600  — green
    "#9333ea", // accent 600   — purple
    "#f59e0b", // amber 500    — amber
    "#b91c1c", // danger 700   — deep red (700 not 600: at 600 it sat
               //                ΔE 16.8 from the brand orange above)
    "#93c5fd", // info 300     — light blue
    "#86efac", // success 300  — light green
    "#d8b4fe", // accent 300   — light purple
    "#565c54", // surface 600  — neutral
];

export const TH =
    "px-4 py-3 text-left   text-xs font-semibold text-surface-500 uppercase tracking-wider whitespace-nowrap";
export const TH_R =
    "px-4 py-3 text-right  text-xs font-semibold text-surface-500 uppercase tracking-wider whitespace-nowrap";

// ─── Formatting helpers ───────────────────────────────────────────────────────

export function fmtPct(value: number | null | undefined, decimals = 1): string {
    if (value === null || value === undefined) return "-";
    const sign = value > 0 ? "+" : "";
    return `${sign}${Number(value).toFixed(decimals)}%`;
}

export function fmtHours(hours: number | null | undefined): string {
    if (!hours) return "-";
    if (hours < 1) return `${Math.round(hours * 60)}m`;
    return `${Number(hours).toFixed(1)}h`;
}

// ─── Change indicator ─────────────────────────────────────────────────────────

export function ChangeBadge({ pct }: { pct: number | null | undefined }) {
    if (pct === null || pct === undefined) return null;
    const positive = pct >= 0;
    return (
        <span
            className={clsx(
                "inline-flex items-center gap-0.5 text-xs font-medium px-1.5 py-0.5 rounded-full",
                positive
                    ? "bg-success-light text-success"
                    : "bg-danger-light text-danger",
            )}
        >
            {positive ? "▲" : "▼"} {Math.abs(pct)}%
        </span>
    );
}

// ─── Shared Layout Components ─────────────────────────────────────────────────

export function SectionHeader({
    title,
    children,
}: {
    title: string;
    children?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between mb-4">
            <h3 className="font-semibold text-surface-900">{title}</h3>
            {children && (
                <div className="flex items-center gap-2">{children}</div>
            )}
        </div>
    );
}

export function KpiCard({
    label,
    value,
    sub,
    color = "",
    comparison,
    drill,
}: {
    label: string;
    value: string | number;
    sub?: string;
    color?: string;
    comparison?: number | null;
    /** A metric the backend can drill (MetricEngine::drill). Only pass one whose
     *  definition is the SAME as this card's figure — a drill that lists other
     *  rows than the number sums is worse than none. */
    drill?: string;
}) {
    const [, setSp] = useSearchParams();
    const open = drill
        ? () => setSp(prev => { const p = new URLSearchParams(prev); p.set("drill", drill); return p; })
        : undefined;
    // A zero never wears an alarm (or a success) colour: "QC Failed 0" in red
    // drew the eye to nothing. Colour is for figures that say something.
    const isZero = /^(KES\s*)?0(\.0+)?%?$/.test(String(value).trim());
    return (
        <div
            className={clsx("card card-body flex flex-col gap-1", open && "cursor-pointer hover:ring-1 hover:ring-brand-300 transition")}
            onClick={open}
            role={open ? "button" : undefined}
            tabIndex={open ? 0 : undefined}
            onKeyDown={open ? (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); open(); } } : undefined}
            title={open ? "Show the records behind this number" : undefined}
        >
            <div className="flex items-start justify-between gap-2">
                <p className="text-xs text-surface-500">{label}</p>
                {/* Visible, not hover-only: on a phone there is no hover, and a
                    figure nobody knows they can open is a dead end. */}
                {open && <span className="shrink-0 text-2xs font-medium text-brand-600" aria-hidden="true">records ›</span>}
            </div>
            <div className="flex items-baseline gap-2 flex-wrap">
                <p
                    className={clsx(
                        "text-xl font-bold tabular-nums",
                        isZero ? "text-surface-400" : (color || "text-surface-900"),
                    )}
                >
                    {value}
                </p>
                {comparison !== undefined && <ChangeBadge pct={comparison} />}
            </div>
            {sub && <p className="text-xs text-surface-400 mt-0.5">{sub}</p>}
        </div>
    );
}

/**
 * What an empty section says instead of nothing: what is missing, and what to
 * try. A blank card under a tab read as a broken page.
 */
export function EmptyNote({ title, hint }: { title: string; hint?: string }) {
    return (
        <div className="card card-body text-center py-10">
            <p className="text-sm font-medium text-surface-700">{title}</p>
            {hint && <p className="text-xs text-surface-500 mt-1">{hint}</p>}
        </div>
    );
}

/** A rate is only a rate when there is something to measure: "—" and why, never a red 0%. */
export function rateOrDash(numerator: number, denominator: number, digits = 0): string {
    return denominator > 0 ? `${(Math.round((numerator / denominator) * 100 * 10 ** digits) / 10 ** digits).toFixed(digits)}%` : "—";
}

export function TableWrapper({ children }: { children: React.ReactNode }) {
    return <div className="overflow-x-auto">{children}</div>;
}

export function EmptyRow({
    cols,
    text = "No data for this period.",
}: {
    cols: number;
    text?: string;
}) {
    return (
        <tr>
            <td
                colSpan={cols}
                className="px-4 py-10 text-center text-sm text-surface-400"
            >
                {text}
            </td>
        </tr>
    );
}

// ─── Export CSV Button ────────────────────────────────────────────────────────
// Downloads via reportsApi.downloadCsv: an authenticated fetch + blob download
// (Bearer token in the request header), the same pattern ReportPdfButton uses.
// No proxy header forwarding involved — the old <a href> navigation that
// couldn't carry the Authorization header is gone.

export function ExportCsvButton({
    path,
    params,
    label = "Export CSV",
}: {
    path: string;
    params: Record<string, any>;
    label?: string;
}) {
    const [loading, setLoading] = useState(false);
    const toast = useToastStore();
    const { can } = usePermissions();

    const download = useCallback(async () => {
        setLoading(true);
        try {
            const ok = await reportsApi.downloadCsv(path, params);
            if (!ok) toast.error("CSV export failed. Please try again.");
        } catch {
            toast.error("CSV export failed. Please try again.");
        } finally {
            setLoading(false);
        }
    }, [path, params, toast]);

    // The server refuses a file without reports.export; a button that can
    // only fail ("CSV export failed") is worse than no button.
    if (!can("reports.export")) return null;

    return (
        <button
            onClick={download}
            disabled={loading}
            className="btn-ghost btn-sm inline-flex items-center gap-1.5 disabled:opacity-50"
        >
            {loading ? (
                <svg className="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                </svg>
            ) : (
                <svg
                    className="w-3.5 h-3.5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={1.75}
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
                    />
                </svg>
            )}
            <span>{loading ? "Exporting…" : label}</span>
        </button>
    );
}

export function PrintButton({ onClick }: { onClick: () => void }) {
    return (
        <button
            onClick={onClick}
            className="btn-ghost btn-sm inline-flex items-center gap-1.5"
        >
            <svg
                className="w-3.5 h-3.5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth={1.75}
            >
                <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z"
                />
            </svg>
            Print
        </button>
    );
}

// ─── Schedule Modal ───────────────────────────────────────────────────────────

interface ScheduleModalProps {
    reportType: ReportType;
    params: Record<string, any>;
    onClose: () => void;
}

export function ScheduleModal({
    reportType,
    params,
    onClose,
}: ScheduleModalProps) {
    const qc = useQueryClient();
    const [name, setName] = useState("");
    const [frequency, setFrequency] = useState<ScheduleFrequency>("weekly");
    const [format, setFormat] = useState<ExportFormat>("csv");
    const [recipients, setRecipients] = useState("");
    const [error, setError] = useState("");

    const saveMutation = useMutation({
        mutationFn: (payload: SchedulePayload) =>
            reportsApi.saveSchedule(payload),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["report-schedules"] });
            onClose();
        },
        onError: (e: any) =>
            setError(e?.response?.data?.message ?? "Failed to save schedule."),
    });

    function handleSave() {
        setError("");
        const emails = recipients
            .split(",")
            .map((e) => e.trim())
            .filter(Boolean);
        if (!name.trim()) return setError("Schedule name is required.");
        if (emails.length === 0)
            return setError("At least one recipient email is required.");
        saveMutation.mutate({
            name,
            report_type: reportType,
            frequency,
            recipients: emails,
            format,
            filters: params,
            is_active: true,
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
                <div className="flex items-center justify-between px-6 py-4 border-b border-line">
                    <h2 className="font-semibold text-surface-900">
                        Schedule Report
                    </h2>
                    <button
                        onClick={onClose}
                        className="text-surface-400 hover:text-surface-700 transition-colors"
                        aria-label="Close"
                    >
                        <svg
                            className="w-5 h-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                <div className="px-6 py-5 space-y-4">
                    <div>
                        <label className="label">Schedule Name</label>
                        <input
                            className="input w-full"
                            placeholder="e.g. Weekly Sales Summary"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label className="label">Frequency</label>
                            <select
                                className="input w-full"
                                value={frequency}
                                onChange={(e) =>
                                    setFrequency(
                                        e.target.value as ScheduleFrequency,
                                    )
                                }
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div>
                            <label className="label">Format</label>
                            <select
                                className="input w-full"
                                value={format}
                                onChange={(e) =>
                                    setFormat(e.target.value as ExportFormat)
                                }
                            >
                                <option value="csv">CSV</option>
                                <option value="pdf">PDF</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="label">
                            Recipients (comma-separated emails)
                        </label>
                        <textarea
                            className="input w-full"
                            rows={2}
                            placeholder="manager@example.com, owner@example.com"
                            value={recipients}
                            onChange={(e) => setRecipients(e.target.value)}
                        />
                    </div>

                    <p className="text-xs text-surface-400">
                        Current date range filters will be applied automatically
                        each time the report runs.
                    </p>

                    {error && <p className="text-sm text-danger">{error}</p>}
                </div>

                <div className="px-6 py-4 border-t border-line flex justify-end gap-3">
                    <button onClick={onClose} className="btn-ghost">
                        Cancel
                    </button>
                    <button
                        onClick={handleSave}
                        disabled={saveMutation.isPending}
                        className="btn-primary"
                    >
                        {saveMutation.isPending ? "Saving…" : "Save Schedule"}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ─── Schedules List (inline, used in a drawer or section) ────────────────────

export function SchedulesList({ reportType }: { reportType: ReportType }) {
    const qc = useQueryClient();
    const { can } = usePermissions();
    const canExport = can("reports.export");
    const { data } = useQuery({
        queryKey: ["report-schedules"],
        queryFn: () => reportsApi.listSchedules(),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: string) => reportsApi.deleteSchedule(id),
        onSuccess: () =>
            qc.invalidateQueries({ queryKey: ["report-schedules"] }),
    });

    const schedules = (data?.schedules ?? []).filter(
        (s: any) => !reportType || s.report_type === reportType,
    );

    if (schedules.length === 0) {
        return (
            <p className="text-sm text-surface-400">
                No schedules configured for this report.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            {schedules.map((s: any) => (
                <div
                    key={s.id}
                    className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between p-3 rounded-lg bg-surface-50 border border-line"
                >
                    <div>
                        <p className="text-sm font-medium text-surface-900">
                            {s.name}
                        </p>
                        <p className="text-xs text-surface-400 mt-0.5 capitalize">
                            {s.frequency} · {s.format.toUpperCase()} ·{" "}
                            {s.recipients?.join(", ")}
                        </p>
                    </div>
                    {canExport && (
                    <button
                        onClick={() => deleteMutation.mutate(s.id)}
                        className="text-surface-400 hover:text-danger transition-colors ml-4"
                        aria-label="Delete"
                    >
                        <svg
                            className="w-4 h-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth={2}
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                            />
                        </svg>
                    </button>
                    )}
                </div>
            ))}
        </div>
    );
}

// ─── Report Action Bar ────────────────────────────────────────────────────────
// Standard toolbar used at the top of each report section

export function ReportActionBar({
    reportType,
    exportPath,
    params,
}: {
    reportType: ReportType;
    exportPath: string;
    params: Record<string, any>;
}) {
    const [showSchedule, setShowSchedule] = useState(false);
    const [showSchedules, setShowSchedules] = useState(false);
    const { can } = usePermissions();
    const canExport = can("reports.export");

    return (
        <>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:flex-wrap">
                <ExportCsvButton path={exportPath} params={params} />
                <ReportPdfButton type={reportType as any} params={params} />

                <button
                    onClick={() => window.print()}
                    className="btn-ghost btn-sm inline-flex items-center gap-1.5"
                >
                    <svg
                        className="w-3.5 h-3.5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.75}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z"
                        />
                    </svg>
                    Print
                </button>

                {canExport && (
                <button
                    onClick={() => setShowSchedule(true)}
                    className="btn-ghost btn-sm inline-flex items-center gap-1.5"
                >
                    <svg
                        className="w-3.5 h-3.5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.75}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
                        />
                    </svg>
                    Schedule
                </button>
                )}

                <button
                    onClick={() => setShowSchedules((s) => !s)}
                    className="btn-ghost btn-sm inline-flex items-center gap-1.5"
                >
                    <svg
                        className="w-3.5 h-3.5"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.75}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"
                        />
                    </svg>
                    Schedules {showSchedules ? "▲" : "▼"}
                </button>
            </div>

            {showSchedules && (
                <div className="mt-3 p-4 rounded-xl border border-line bg-surface-50">
                    <p className="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-3">
                        Active Schedules
                    </p>
                    <SchedulesList reportType={reportType} />
                </div>
            )}

            {showSchedule && (
                <ScheduleModal
                    reportType={reportType}
                    params={params}
                    onClose={() => setShowSchedule(false)}
                />
            )}
        </>
    );
}


// ─── ReportPageHeader ─────────────────────────────────────────────────────────
//
// Unified header for all report pages. Replaces the old pattern of:
//   1. Separate title div
//   2. Floating DateRangePicker + ComparisonToggle
//   3. Orphaned ReportActionBar strip
//
// New layout: single card with:
//   top row  — breadcrumb + title/subtitle | controls (picker + compare)
//   divider
//   bottom   — action buttons (export, pdf, print, schedule)

import { Link } from "react-router-dom";

export function ReportPageHeader({
    title,
    subtitle,
    reportType,
    exportPath,
    params,
    // DateRangePicker props
    preset,
    start,
    end,
    onPresetChange,
    onStartChange,
    onEndChange,
    // Optional comparison toggle
    compare,
    onCompareChange,
    // Optional extra right-side controls
    extra,
    // Outlet filter (from useDateRange) — omit on a page whose endpoints are not outlet-scoped
    outlet,
    onOutletChange,
}: {
    title: string;
    subtitle: string;
    /** Omit for a page with no printed (PDF) version — no button that cannot work. */
    reportType?: ReportType;
    /** Omit for a page with nothing to export. */
    exportPath?: string;
    params?: Record<string, any>;
    /** Omit the four date props for a page that describes the present, not a period (Signals). */
    preset?: DatePreset;
    start?: string;
    end?: string;
    onPresetChange?: (p: DatePreset) => void;
    onStartChange?: (d: string) => void;
    onEndChange?: (d: string) => void;
    compare?: boolean;
    onCompareChange?: (v: boolean) => void;
    extra?: React.ReactNode;
    outlet?: string;
    onOutletChange?: (id: string) => void;
}) {
    const [showSchedule, setShowSchedule] = useState(false);
    const [showSchedules, setShowSchedules] = useState(false);
    // On a phone the filters fold into one line ("1 Sep – 30 Sep 2026 · All
    // outlets · Change"): seven controls stacked above the figures pushed every
    // number below the first screen. From sm up they are always shown.
    const [filtersOpen, setFiltersOpen] = useState(false);
    const { data: outletList } = useQuery({ queryKey: ["report-outlets"], queryFn: () => reportsApi.outlets(), staleTime: 300_000, enabled: !!onOutletChange });
    const outletName = outlet ? outletList?.data?.find((o) => String(o.id) === String(outlet))?.name : null;
    const { can } = usePermissions();
    const canExport = can("reports.export");
    const dated = !!(preset && start && end && onPresetChange && onStartChange && onEndChange);
    const hasFilters = dated || !!onOutletChange;

    return (
        <div className="card overflow-hidden">
            {/* ── Top row: title + controls ── */}
            {/* Title above the controls until the screen is wide: beside them at
                medium widths the outlet + date controls squeezed the title into
                one word per line (seen in the preview, 2026-10-01). */}
            <div className="px-5 pt-4 pb-3 flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                {/* Left: breadcrumb + title */}
                <div className="min-w-0">
                    <div className="flex items-center gap-1.5 mb-1.5">
                        <Link
                            to="/reports"
                            className="text-xs text-surface-400 hover:text-brand-500 transition-colors"
                        >
                            Reports
                        </Link>
                        <svg className="w-3 h-3 text-surface-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                        <span className="text-xs text-surface-600 font-medium">{title}</span>
                    </div>
                    <h1 className="text-lg font-semibold text-surface-900 leading-tight">{title}</h1>
                    <p className="text-sm text-surface-400 mt-0.5">{subtitle}</p>
                </div>

                {/* Phone: the filters as one line, opened on tap */}
                {hasFilters && <button type="button" onClick={() => setFiltersOpen((o) => !o)} aria-expanded={filtersOpen}
                    className="sm:hidden flex items-center justify-between gap-2 w-full rounded-lg border border-line px-3 py-2 text-left text-sm">
                    <span className="min-w-0 truncate text-surface-700 tabular-nums">
                        {dated ? `${dayjs(start).format("D MMM")} – ${dayjs(end).format("D MMM YYYY")}` : ""}
                        {onOutletChange && <span className="text-surface-500">{dated ? " · " : ""}{outletName ?? "All outlets"}</span>}
                    </span>
                    <span className="shrink-0 text-xs font-medium text-brand-600">{filtersOpen ? "Done" : "Change"}</span>
                </button>}

                {/* Right: date picker + compare + extras */}
                <div className={clsx("flex-col items-start gap-2 xl:items-end xl:shrink-0", filtersOpen ? "flex" : "hidden sm:flex")}>
                    {/* Date picker row */}
                    <div className="flex items-center gap-2 flex-wrap">
                        {onOutletChange && <OutletSelect value={outlet ?? ""} onChange={onOutletChange} />}
                        {dated && <>
                        <select
                            className="input input-sm w-40 text-sm"
                            aria-label="Period"
                            value={preset}
                            onChange={e => onPresetChange!(e.target.value as DatePreset)}
                        >
                            {DATE_PRESETS.map(p => (
                                <option key={p.value} value={p.value}>{p.label}</option>
                            ))}
                        </select>
                        {preset === "custom" ? (
                            <>
                                <input type="date" className="input input-sm w-36 text-sm" aria-label="From" value={start} onChange={e => onStartChange!(e.target.value)} />
                                <span className="text-surface-400 text-sm">to</span>
                                <input type="date" className="input input-sm w-36 text-sm" aria-label="To" value={end} onChange={e => onEndChange!(e.target.value)} />
                            </>
                        ) : (
                            <span className="text-sm text-surface-500 whitespace-nowrap tabular-nums">
                                {dayjs(start).format("D MMM YYYY")} – {dayjs(end).format("D MMM YYYY")}
                            </span>
                        )}
                        </>}
                    </div>

                    {/* Compare toggle + extras on same row */}
                    {(compare !== undefined || extra) && (
                        <div className="flex items-center gap-3">
                            {compare !== undefined && onCompareChange && (
                                <label className="flex items-center gap-1.5 text-xs text-surface-500 cursor-pointer select-none hover:text-surface-700 transition-colors">
                                    <input
                                        type="checkbox"
                                        checked={compare}
                                        onChange={e => onCompareChange(e.target.checked)}
                                        className="rounded accent-brand-500"
                                    />
                                    Compare to prior period
                                </label>
                            )}
                            {extra}
                        </div>
                    )}
                </div>
            </div>

            {/* ── Divider + action toolbar ── */}
            <div className="border-t border-line px-4 py-2 flex items-center gap-0.5 flex-wrap">
                {/* Export CSV */}
                {exportPath && <ExportCsvButton
                    path={exportPath}
                    params={params ?? {}}
                    label="Export CSV"
                />}

                {/* Download PDF */}
                {reportType && <ReportPdfButton type={reportType as any} params={params ?? {}} compact />}

                {/* Print */}
                <button
                    onClick={() => window.print()}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-surface-600 hover:bg-surface-100 hover:text-surface-900 transition-colors"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z" />
                    </svg>
                    Print
                </button>

                {/* Divider */}
                <span className="w-px h-4 bg-surface-200 mx-1" aria-hidden />

                {/* Schedule — only for a report the scheduler can produce */}
                {canExport && reportType && (
                <button
                    onClick={() => setShowSchedule(true)}
                    className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-surface-600 hover:bg-surface-100 hover:text-surface-900 transition-colors"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Schedule
                </button>
                )}

                {reportType && (
                <button
                    onClick={() => setShowSchedules(s => !s)}
                    className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-surface-600 hover:bg-surface-100 hover:text-surface-900 transition-colors"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                    </svg>
                    Schedules {showSchedules ? "▲" : "▼"}
                </button>
                )}

                {/* Currency: the slot the shell reserves for it. Every figure
                    is stated in KES at the owner's REPORTING rates (never a
                    customer's pricing rate), so there is one basis to choose;
                    a currency's own business is a slice, in the Explorer. */}
                <Link
                    to="/reports/explorer?by=currency"
                    className="ml-auto inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs text-surface-500 hover:bg-surface-100 hover:text-surface-800 transition-colors"
                    title="USD, GBP and ZMW are converted at the reporting rates set in Settings → Currencies"
                >
                    KES · reporting rates <span className="text-brand-600 font-medium">By currency →</span>
                </Link>
            </div>

            {/* Schedules list (inline) */}
            {showSchedules && (
                <div className="border-t border-line px-5 py-4 bg-surface-50/60">
                    <p className="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-3">Active Schedules</p>
                    {reportType && <SchedulesList reportType={reportType} />}
                </div>
            )}

            {/* The drill panel for every clickable number on this page */}
            {dated && <DrillHost start={start!} end={end!} outlet={outlet} />}

            {/* Schedule create modal */}
            {showSchedule && reportType && (
                <ScheduleModal
                    reportType={reportType}
                    params={params ?? {}}
                    onClose={() => setShowSchedule(false)}
                />
            )}
        </div>
    );
}

// ─── DateRangePicker ──────────────────────────────────────────────────────────

export function DateRangePicker({
    preset,
    start,
    end,
    onPresetChange,
    onStartChange,
    onEndChange,
}: {
    preset: DatePreset;
    start: string;
    end: string;
    onPresetChange: (p: DatePreset) => void;
    onStartChange: (d: string) => void;
    onEndChange: (d: string) => void;
}) {
    return (
        <div className="flex items-center gap-2 flex-wrap">
            <select
                className="input w-full sm:w-36 text-sm"
                value={preset}
                onChange={(e) => onPresetChange(e.target.value as DatePreset)}
            >
                {DATE_PRESETS.map((p) => (
                    <option key={p.value} value={p.value}>
                        {p.label}
                    </option>
                ))}
            </select>
            {preset === "custom" ? (
                <>
                    <input
                        type="date"
                        className="input w-36 text-sm"
                        value={start}
                        onChange={(e) => onStartChange(e.target.value)}
                    />
                    <span className="text-surface-400 text-sm">to</span>
                    <input
                        type="date"
                        className="input w-36 text-sm"
                        value={end}
                        onChange={(e) => onEndChange(e.target.value)}
                    />
                </>
            ) : (
                <span className="text-sm text-surface-500 whitespace-nowrap">
                    {dayjs(start).format("D MMM YYYY")} –{" "}
                    {dayjs(end).format("D MMM YYYY")}
                </span>
            )}
        </div>
    );
}

/**
 * The report's filters, held in the URL (reports build, 2026-10-01):
 * `?preset=` or `?preset=custom&from=&to=`, and `?outlet=`. A refresh or a
 * shared link reproduces the investigation; every section of a page reads the
 * same keys, so a headline and the table under it cannot answer for different
 * windows or shops. `params` carries exactly what the report endpoints accept.
 */
/** The period and outlet a reader last chose, for this browser session. */
const REPORT_FILTERS_KEY = "bh-report-filters";
type RememberedFilters = { preset?: string; from?: string; to?: string; outlet?: string };
function rememberedFilters(): RememberedFilters {
    try { return JSON.parse(sessionStorage.getItem(REPORT_FILTERS_KEY) ?? "{}") ?? {}; } catch { return {}; }
}

export function useDateRange(defaultPreset: DatePreset = "this_month") {
    const [sp, setSp] = useSearchParams();
    const known = (p: string | null | undefined): p is DatePreset => !!p && DATE_PRESETS.some((d) => d.value === p);

    // The period follows the reader from page to page: a report opened from the
    // menu (no dates in its link) uses the period and outlet last chosen in this
    // session, not each page's own default — September on Sales stayed
    // September on Customers. A link that carries its own dates still wins.
    const urlHasPeriod = sp.has("preset") || sp.has("from");
    const memo = urlHasPeriod ? {} : rememberedFilters();
    const urlPreset = sp.get("preset") ?? memo.preset ?? null;
    const preset: DatePreset = known(urlPreset) ? urlPreset : (sp.get("from") ?? memo.from) ? "custom" : defaultPreset;
    const fallback = datePresetRange(preset === "custom" ? defaultPreset : preset);
    const start = preset === "custom" ? (sp.get("from") ?? memo.from ?? fallback.start) : fallback.start;
    const end   = preset === "custom" ? (sp.get("to") ?? memo.to ?? fallback.end) : fallback.end;
    const outlet = sp.get("outlet") ?? (sp.has("outlet") ? "" : rememberedFilters().outlet ?? "");

    try {
        sessionStorage.setItem(REPORT_FILTERS_KEY, JSON.stringify({
            preset, ...(preset === "custom" ? { from: start, to: end } : {}), outlet,
        }));
    } catch { /* private mode: the period simply doesn't follow */ }

    const patch = (next: Record<string, string | null>) =>
        setSp((prev) => {
            const p = new URLSearchParams(prev);
            Object.entries(next).forEach(([k, v]) => (v ? p.set(k, v) : p.delete(k)));
            return p;
        }, { replace: true });

    function handlePreset(p: DatePreset) {
        if (p === "custom") patch({ preset: "custom", from: start, to: end });
        else patch({ preset: p, from: null, to: null });
    }

    return {
        preset,
        start,
        end,
        outlet,
        setStart: (d: string) => patch({ preset: "custom", from: d, to: end }),
        setEnd: (d: string) => patch({ preset: "custom", from: start, to: d }),
        setOutlet: (id: string) => patch({ outlet: id || null }),
        handlePreset,
        params: {
            start_date: start,
            end_date: end,
            ...(outlet ? { outlet_id: Number(outlet) } : {}),
        } as { start_date: string; end_date: string; outlet_id?: number },
    };
}

/**
 * The active tab, held in the URL (`?tab=`) — read on every render and written
 * on every change, so a refresh, the back button or a shared link lands on the
 * same tab. An unknown or forbidden tab falls back to `fallback`.
 */
export function useReportTab<T extends string>(tabs: readonly T[], fallback: T): [T, (t: T) => void] {
    const [sp, setSp] = useSearchParams();
    const t = sp.get("tab") as T | null;
    const active = t && tabs.includes(t) ? t : fallback;
    const set = (next: T) => setSp((prev) => {
        const p = new URLSearchParams(prev);
        if (next === fallback) p.delete("tab"); else p.set("tab", next);
        p.delete("drill");   // a drill belongs to the tab it was opened from
        return p;
    }, { replace: true });
    return [active, set];
}

/** The page's outlet filter, from the URL — for sections that call the engine directly. */
export function useReportOutlet(): number | undefined {
    const [sp] = useSearchParams();
    const v = sp.get("outlet");
    return v ? Number(v) : undefined;
}

// ─── Outlet filter ────────────────────────────────────────────────────────────
// Reports are business-wide (owner, 2026-09-30); an outlet narrows every query
// on the page at the database, never by hiding rows here.

/**
 * The window as the backend should hear it. A preset the backend knows is sent
 * by NAME, so an in-progress period is compared like for like (this month so
 * far against the same days last month); anything else as dates.
 */
const BACKEND_PERIODS: Partial<Record<DatePreset, string>> = {
    today: "today", yesterday: "yesterday", last_7_days: "last_7", last_30_days: "last_30",
    this_month: "this_month", last_month: "last_month", this_quarter: "this_quarter", this_year: "this_year",
};
export function periodParams(preset: DatePreset, start: string, end: string, outlet?: string): Record<string, string | number> {
    const key = BACKEND_PERIODS[preset];
    return {
        ...(key ? { period: key } : { period: "custom", from: start, to: end }),
        ...(outlet ? { outlet_id: Number(outlet) } : {}),
    };
}

export function OutletSelect({ value, onChange }: { value: string; onChange: (id: string) => void }) {
    const { data } = useQuery({ queryKey: ["report-outlets"], queryFn: () => reportsApi.outlets(), staleTime: 300_000 });
    const outlets = data?.data ?? [];
    if (outlets.length < 2 && !value) return null; // one shop: a filter with one choice is noise
    return (
        <select className="input input-sm w-44 text-sm" value={value} onChange={(e) => onChange(e.target.value)}
            aria-label="Outlet">
            <option value="">All outlets</option>
            {outlets.map((o) => <option key={o.id} value={String(o.id)}>{o.name}</option>)}
        </select>
    );
}

// ─── Drill-down ───────────────────────────────────────────────────────────────
// The rows behind a number. The backend says what the number IS (`definition`)
// and where each row may lead (`links`, already filtered by the viewer's
// permissions); this panel only renders them. Opened by `?drill=<metric>` so a
// refresh keeps it open and the rows always use the page's own filters.

const LINK_LABEL: Record<string, string> = {
    order: "Order", customer: "Customer", payment: "Payment", production: "Job", expense: "Expense",
};

export function DrillPanel({ metric, query, onClose, title, load }: {
    metric: string; query: Record<string, any>; onClose: () => void; title?: string;
    /** Another backend list in the same shape (the Explorer's rows); defaults to the metric drill. */
    load?: (query: Record<string, any>) => Promise<any>;
}) {
    const navigate = useNavigate();
    const [page, setPage] = useState(1);
    const { data, isLoading, isError } = useQuery({
        queryKey: ["drill", metric, query, page],
        queryFn: () => (load ?? ((q: Record<string, any>) => reportsApi.drillWith(metric, q)))({ ...query, page }),
        staleTime: 60_000,
    });
    const rows: any[] = data?.rows ?? [];
    const pages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;
    // The records keep their context: which period and outlet they belong to,
    // so a list opened from a figure never floats free of the figure.
    const { data: outletList } = useQuery({ queryKey: ["report-outlets"], queryFn: () => reportsApi.outlets(), staleTime: 300_000, enabled: !!query.outlet_id });
    const PERIOD_WORDS: Record<string, string> = {
        today: "Today", yesterday: "Yesterday", last_7: "Last 7 days", last_30: "Last 30 days", this_month: "This month",
        last_month: "Last month", this_quarter: "This quarter", this_year: "This year",
    };
    const when = query.from && query.to
        ? `${dayjs(query.from).format("D MMM")} – ${dayjs(query.to).format("D MMM YYYY")}`
        : PERIOD_WORDS[query.period] ?? null;
    const where = query.outlet_id
        ? outletList?.data?.find((o) => String(o.id) === String(query.outlet_id))?.name ?? "One outlet"
        : null;
    const context = [when, where].filter(Boolean).join(" · ");
    const money = rows.some((r) => r.currency);

    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-6" onClick={onClose}>
            <div className="bg-white w-full sm:max-w-2xl sm:rounded-2xl rounded-t-2xl shadow-xl max-h-[85vh] flex flex-col"
                onClick={(e) => e.stopPropagation()} role="dialog" aria-label="Records behind this number">
                <div className="px-4 py-3 border-b border-line flex items-start gap-3">
                    <div className="min-w-0">
                        <p className="text-sm font-bold text-surface-900">
                            {title ? `${title} · ` : ""}
                            {data ? `${data.total.toLocaleString()} record${data.total === 1 ? "" : "s"}` : "Loading…"}
                        </p>
                        {context && <p className="text-2xs font-medium text-surface-600 mt-0.5">{context}</p>}
                        {data?.definition && <p className="text-2xs text-surface-500 mt-0.5">{data.definition}</p>}
                    </div>
                    <button onClick={onClose} aria-label="Close"
                        className="ml-auto w-7 h-7 rounded-lg flex items-center justify-center text-surface-400 hover:bg-surface-100">✕</button>
                </div>
                <div className="flex-1 overflow-y-auto">
                    {isLoading ? (
                        <p className="text-center text-xs text-surface-400 py-12">Loading…</p>
                    ) : isError ? (
                        <p className="text-center text-xs text-danger py-12">These records could not be loaded.</p>
                    ) : rows.length === 0 ? (
                        <p className="text-center text-xs text-surface-400 py-12">No records for these filters.</p>
                    ) : (
                        <div className="divide-y divide-line">
                            {rows.map((r) => (
                                <div key={`${r.kind}-${r.id}`} className="flex items-center gap-3 px-4 py-2.5">
                                    <div className="flex-1 min-w-0">
                                        <p className="text-xs font-semibold text-surface-800 font-mono truncate">{r.ref}</p>
                                        <p className="text-2xs text-surface-400 truncate">
                                            {dayjs(r.at ?? r.date).format("D MMM YYYY")}
                                            {(r.who ?? r.customer) ? ` · ${r.who ?? r.customer}` : ""}{r.detail ? ` · ${r.detail}` : ""}
                                        </p>
                                        {r.links && Object.keys(r.links).length > 0 && (
                                            <div className="flex gap-2 mt-1">
                                                {Object.entries(r.links as Record<string, string>).map(([k, to]) => (
                                                    <button key={k} onClick={() => navigate(to)}
                                                        className="text-2xs font-medium text-brand-600 hover:underline">
                                                        {LINK_LABEL[k] ?? k} →
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    {r.amount != null && (
                                        <span className="text-right shrink-0">
                                            <span className="block text-xs font-bold tabular-nums text-surface-800">
                                                {money || load ? `KES ${Number(r.amount).toLocaleString()}` : Number(r.amount).toLocaleString()}
                                            </span>
                                            {r.currency && r.currency !== "KES" && r.amount_original != null && (
                                                <span className="block text-2xs text-surface-400 tabular-nums">
                                                    {r.currency} {Number(r.amount_original).toLocaleString()}
                                                </span>
                                            )}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
                {pages > 1 && (
                    <div className="px-4 py-2.5 border-t border-line flex items-center gap-2">
                        <button disabled={page <= 1} onClick={() => setPage((p) => p - 1)}
                            className="btn-secondary text-2xs px-2.5 py-1 disabled:opacity-40">← Prev</button>
                        <span className="text-2xs text-surface-400 tabular-nums">{page} / {pages}</span>
                        <button disabled={page >= pages} onClick={() => setPage((p) => p + 1)}
                            className="btn-secondary text-2xs px-2.5 py-1 disabled:opacity-40">Next →</button>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * Opens the drill named in `?drill=` with THIS page's own window and outlet —
 * passed in, never recomputed, so a page defaulting to the last 30 days cannot
 * drill over this month. One per page (ReportPageHeader renders it).
 */
/** What each drillable figure is called, so its records panel says whose records they are. */
const DRILL_TITLES: Record<string, string> = {
    revenue: "Sold", orders: "Orders", collected: "Collected", outstanding: "Outstanding",
    new_customers: "New customers", production_completed: "Production completed",
    production_overdue: "Overdue production", expenses: "Expenses", lost: "Lost sales",
};

export function DrillHost({ start, end, outlet }: { start: string; end: string; outlet?: string }) {
    const [sp, setSp] = useSearchParams();
    const metric = sp.get("drill");
    if (!metric) return null;
    const close = () => setSp((prev) => { const p = new URLSearchParams(prev); p.delete("drill"); return p; }, { replace: true });
    return (
        <DrillPanel metric={metric} title={DRILL_TITLES[metric]}
            query={{ period: "custom", from: start, to: end, ...(outlet ? { outlet_id: Number(outlet) } : {}) }}
            onClose={close} />
    );
}

// ─── Status Pills ─────────────────────────────────────────────────────────────

export function StatusPill({ status }: { status: string }) {
    const s = status?.toLowerCase();
    const cls =
        s === "completed" || s === "paid" || s === "received"
            ? "bg-success-light text-success"
            : s === "pending" || s === "ordered" || s === "in_progress"
              ? "bg-warning-light text-warning"
              : s === "cancelled" || s === "rejected"
                ? "bg-danger-light text-danger"
                : s === "approved"
                  ? "bg-info-light text-info"
                  : "bg-surface-100 text-surface-600";

    return (
        <span
            className={clsx(
                "px-2.5 py-0.5 rounded-full text-xs font-medium capitalize",
                cls,
            )}
        >
            {status?.replace(/_/g, " ")}
        </span>
    );
}

export function StockPill({ status }: { status: string }) {
    return (
        <span
            className={clsx(
                "px-2.5 py-0.5 rounded-full text-xs font-medium",
                status === "in_stock"
                    ? "bg-success-light text-success"
                    : status === "low_stock"
                      ? "bg-warning-light text-warning"
                      : "bg-danger-light text-danger",
            )}
        >
            {status === "in_stock"
                ? "In Stock"
                : status === "low_stock"
                  ? "Low Stock"
                  : "Out of Stock"}
        </span>
    );
}

// ─── Mini sparkline bar ───────────────────────────────────────────────────────

export function ProgressBar({
    value,
    max,
    color = "#6366F1",
}: {
    value: number;
    max: number;
    color?: string;
}) {
    const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
    return (
        <div className="h-1.5 bg-surface-100 rounded-full overflow-hidden w-full">
            <div
                className="h-full rounded-full transition-all"
                style={{ width: `${pct}%`, backgroundColor: color }}
            />
        </div>
    );
}

// ─── Comparison period label ──────────────────────────────────────────────────

export function ComparisonToggle({
    enabled,
    onChange,
}: {
    enabled: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <label className="flex items-center gap-2 text-sm text-surface-600 cursor-pointer select-none">
            <input
                type="checkbox"
                checked={enabled}
                onChange={(e) => onChange(e.target.checked)}
                className="rounded accent-brand-500"
            />
            Compare to prior period
        </label>
    );
}
// ─────────────────────────────────────────────────────────────────────────────
// REPORT PDF DOWNLOAD
// Calls the backend PDF endpoint (ReportPdfController) which runs the queries
// server-side and streams a real PDF binary — same approach as transaction PDFs.
// ─────────────────────────────────────────────────────────────────────────────


export type ReportPdfType =
    | "sales"
    | "financial"
    | "inventory"
    | "procurement"
    | "production"
    | "customers";

export function useReportPdf() {
    const [loading, setLoading] = useState(false);
    const toast = useToastStore();

    const download = useCallback(
        async (type: ReportPdfType, params: Record<string, any>): Promise<void> => {
            setLoading(true);
            try {
                const base = import.meta.env.VITE_API_URL ?? "http://localhost:8000/api";
                const qs = new URLSearchParams();
                Object.entries(params).forEach(([k, v]) => {
                    if (v !== undefined && v !== null && v !== "") qs.set(k, String(v));
                });
                const url = `${base}/v1/admin/reports/pdf/${type}?${qs.toString()}`;
                const token = tokenStorage.get() ?? "";

                const res = await fetch(url, {
                    method: "GET",
                    headers: {
                        Authorization: `Bearer ${token}`,
                        Accept: "application/pdf",
                    },
                });

                // Held for approval: the approval dialog takes over.
                if (await heldFromFetch(res)) return;

                if (!res.ok) {
                    const err = await res.json().catch(() => ({ message: "PDF generation failed." }));
                    toast.error(err.message ?? "Failed to generate report PDF.");
                    return;
                }

                const blob = await res.blob();
                const blobUrl = URL.createObjectURL(blob);

                // Derive filename from Content-Disposition or build one
                let filename = `${type}-report.pdf`;
                const cd = res.headers.get("Content-Disposition");
                if (cd) {
                    const match = cd.match(/filename="?([^";\n]+)"?/i);

                    if (match?.[1]) filename = match[1];
                }

                const a = document.createElement("a");
                a.href = blobUrl;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(() => URL.revokeObjectURL(blobUrl), 5000);
            } catch (err: any) {
                toast.error(err?.message ?? "An error occurred generating the PDF.");
            } finally {
                setLoading(false);
            }
        },
        [toast]
    );

    return { download, loading };
}

export function ReportPdfButton({
    type,
    params,
    label = "Download PDF",
    compact = false,
}: {
    type: ReportPdfType;
    params: Record<string, any>;
    label?: string;
    compact?: boolean;
}) {
    const { download, loading } = useReportPdf();
    const { can } = usePermissions();

    // A PDF is a file out of the building, like a CSV: reports.export
    // (owner, 2026-10-01). The server refuses it without; so does the button.
    if (!can("reports.export")) return null;

    return (
        <button
            onClick={() => download(type, params)}
            disabled={loading}
            className={compact ? "inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-surface-600 hover:bg-surface-100 hover:text-surface-900 transition-colors disabled:opacity-50" : "btn-ghost btn-sm inline-flex items-center gap-1.5 disabled:opacity-50"}
        >
            {loading ? (
                <svg className="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                </svg>
            ) : (
                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                    <path strokeLinecap="round" strokeLinejoin="round"
                        d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m.75 12l3 3m0 0l3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            )}
            <span>{loading ? "Generating…" : label}</span>
        </button>
    );
}




// ── Shared HTML shell ─────────────────────────────────────────────────────────

export function buildReportShell(
    title: string,
    dateRange: string,
    orgName: string,
    body: string,
): string {
    const year = new Date().getFullYear();
    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${title}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#111;background:#fff;line-height:1.45}
.page{padding:28px 32px;max-width:900px;margin:0 auto}
.top{display:table;width:100%;margin-bottom:10px}
.top-l{display:table-cell;vertical-align:top;width:60%}
.top-r{display:table-cell;vertical-align:top;text-align:right}
.report-title{font-size:20px;font-weight:700;color:#111;margin-bottom:2px}
.org-name{font-size:13px;font-weight:600;margin-bottom:2px}
.date-range{font-size:10px;color:#555}
.divider{border:none;border-top:2px solid #111;margin:10px 0 16px}
.section{margin-bottom:20px}
.section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid #ddd}
.kpi-grid{display:table;width:100%;margin-bottom:16px}
.kpi-cell{display:table-cell;width:25%;padding:0 8px 0 0;vertical-align:top}
.kpi-cell:last-child{padding-right:0}
.kpi-box{border:1px solid #ddd;padding:8px 10px;border-radius:3px}
.kpi-label{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#666;margin-bottom:3px}
.kpi-value{font-size:16px;font-weight:700;color:#111}
.kpi-sub{font-size:9px;color:#888;margin-top:2px}
table{border-collapse:collapse;width:100%}
th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:5px 8px;background:#f2f2f2;border-bottom:1px solid #bbb;text-align:left}
th.tr{text-align:right}
td{font-size:10.5px;padding:5px 8px;border-bottom:1px solid #e8e8e8;vertical-align:top}
td.tr{text-align:right}
td.mono{font-family:'Courier New',monospace}
tr:last-child td{border-bottom:none}
.total-row td{font-weight:700;background:#f8f8f8;border-top:2px solid #999}
.footer{margin-top:16px;padding-top:8px;border-top:1px solid #ddd;font-size:9px;color:#888;display:table;width:100%}
.footer-l{display:table-cell;text-align:left}
.footer-r{display:table-cell;text-align:right}
.badge{display:inline-block;padding:1px 7px;border-radius:3px;font-size:9px;font-weight:700;letter-spacing:.5px}
.badge-green{background:#dcfce7;color:#15803d}
.badge-amber{background:#fef9c3;color:#a16207}
.badge-red{background:#fee2e2;color:#b91c1c}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-grey{background:#f2f3f2;color:#757d72}
@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.page{padding:16px}}
</style>
</head>
<body>
<div class="page">
  <div class="top">
    <div class="top-l">
      <div class="org-name">${orgName}</div>
      <div class="report-title">${title}</div>
      <div class="date-range">${dateRange}</div>
    </div>
    <div class="top-r">
      <div style="font-size:10px;color:#555">Generated: ${new Date().toLocaleDateString("en-KE", { day: "2-digit", month: "short", year: "numeric" })}</div>
    </div>
  </div>
  <hr class="divider">
  ${body}
  <div class="footer">
    <div class="footer-l">${title} · ${dateRange}</div>
    <div class="footer-r">© ${year} ${orgName}</div>
  </div>
</div>
</body>
</html>`;
}

// ── Builder helpers ────────────────────────────────────────────────────────────

export function kpiGrid(
    kpis: Array<{ label: string; value: string | number; sub?: string }>,
): string {
    const cells = kpis
        .map(
            (k) =>
                `<div class="kpi-cell"><div class="kpi-box">
          <div class="kpi-label">${k.label}</div>
          <div class="kpi-value">${k.value ?? "—"}</div>
          ${k.sub ? `<div class="kpi-sub">${k.sub}</div>` : ""}
        </div></div>`,
        )
        .join("");
    return `<div class="kpi-grid">${cells}</div>`;
}

export function reportSection(title: string, content: string): string {
    return `<div class="section"><div class="section-title">${title}</div>${content}</div>`;
}

export function reportTable(
    headers: Array<{ label: string; right?: boolean }>,
    rows: string[][],
    totalRow?: string[],
): string {
    const ths = headers
        .map((h) => `<th${h.right ? ' class="tr"' : ""}>${h.label}</th>`)
        .join("");
    const trs = rows
        .map(
            (row) =>
                "<tr>" +
                row
                    .map(
                        (cell, i) =>
                            `<td${headers[i]?.right ? ' class="tr mono"' : ""}>${cell ?? "—"}</td>`,
                    )
                    .join("") +
                "</tr>",
        )
        .join("");
    const totalTr = totalRow
        ? `<tr class="total-row">${totalRow.map((c, i) => `<td${headers[i]?.right ? ' class="tr mono"' : ""}>${c}</td>`).join("")}</tr>`
        : "";
    return `<table><thead><tr>${ths}</tr></thead><tbody>${trs}${totalTr}</tbody></table>`;
}

export function statusBadge(status: string): string {
    const s = status?.toLowerCase().replace(/_/g, " ") ?? "";
    const cls =
        ["completed", "paid", "approved", "received", "active"].some((x) =>
            s.includes(x),
        )
            ? "badge-green"
            : ["pending", "processing", "in progress", "partial"].some((x) =>
                    s.includes(x),
                )
              ? "badge-amber"
              : ["cancelled", "rejected", "failed", "overdue"].some((x) =>
                      s.includes(x),
                  )
                ? "badge-red"
                : ["ordered", "shipped", "dispatched"].some((x) => s.includes(x))
                  ? "badge-blue"
                  : "badge-grey";
    return `<span class="badge ${cls}">${status?.replace(/_/g, " ").toUpperCase() ?? "—"}</span>`;
}