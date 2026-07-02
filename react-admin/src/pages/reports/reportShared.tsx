// src/pages/reports/reportShared.tsx
// Shared components, constants, types, and hooks used across all report tab pages.

import { useState, useCallback, useRef } from "react";
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

// ─── Constants ────────────────────────────────────────────────────────────────

export const CHART_COLORS = [
    "#6366F1",
    "#8B5CF6",
    "#EC4899",
    "#F59E0B",
    "#10B981",
    "#3B82F6",
    "#EF4444",
    "#06B6D4",
    "#84CC16",
    "#F97316",
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
}: {
    label: string;
    value: string | number;
    sub?: string;
    color?: string;
    comparison?: number | null;
}) {
    return (
        <div className="card card-body flex flex-col gap-1">
            <p className="text-xs text-surface-500">{label}</p>
            <div className="flex items-baseline gap-2 flex-wrap">
                <p
                    className={clsx(
                        "text-xl font-bold tabular-nums",
                        color || "text-surface-900",
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

// ─── Export Button (hidden until Nginx proxy header forwarding is fixed) ──────
// TODO: re-enable once Authorization header passes through the proxy correctly.

export function ExportCsvButton(_props: {
    path: string;
    params: Record<string, any>;
    label?: string;
}) {
    return null;
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
                <div className="flex items-center justify-between px-6 py-4 border-b border-surface-100">
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

                <div className="px-6 py-4 border-t border-surface-100 flex justify-end gap-3">
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
                    className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between p-3 rounded-lg bg-surface-50 border border-surface-100"
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
                <div className="mt-3 p-4 rounded-xl border border-surface-100 bg-surface-50">
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
}: {
    title: string;
    subtitle: string;
    reportType: ReportType;
    exportPath: string;
    params: Record<string, any>;
    preset: DatePreset;
    start: string;
    end: string;
    onPresetChange: (p: DatePreset) => void;
    onStartChange: (d: string) => void;
    onEndChange: (d: string) => void;
    compare?: boolean;
    onCompareChange?: (v: boolean) => void;
    extra?: React.ReactNode;
}) {
    const [showSchedule, setShowSchedule] = useState(false);
    const [showSchedules, setShowSchedules] = useState(false);
    const { can } = usePermissions();
    const canExport = can("reports.export");

    return (
        <div className="card overflow-hidden">
            {/* ── Top row: title + controls ── */}
            <div className="px-5 pt-4 pb-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                {/* Left: breadcrumb + title */}
                <div className="min-w-0">
                    <div className="flex items-center gap-1.5 mb-1.5">
                        <Link
                            to="/reports"
                            className="text-xs text-surface-400 hover:text-brand-500 transition-colors"
                        >
                            Reports
                        </Link>
                        <svg className="w-3 h-3 text-surface-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                        <span className="text-xs text-surface-600 font-medium">{title}</span>
                    </div>
                    <h1 className="text-lg font-semibold text-surface-900 leading-tight">{title}</h1>
                    <p className="text-sm text-surface-400 mt-0.5">{subtitle}</p>
                </div>

                {/* Right: date picker + compare + extras */}
                <div className="flex flex-col items-start gap-2 sm:items-end shrink-0">
                    {/* Date picker row */}
                    <div className="flex items-center gap-2 flex-wrap">
                        <select
                            className="input input-sm w-36 text-sm"
                            value={preset}
                            onChange={e => onPresetChange(e.target.value as DatePreset)}
                        >
                            {DATE_PRESETS.map(p => (
                                <option key={p.value} value={p.value}>{p.label}</option>
                            ))}
                        </select>
                        {preset === "custom" ? (
                            <>
                                <input type="date" className="input input-sm w-36 text-sm" value={start} onChange={e => onStartChange(e.target.value)} />
                                <span className="text-surface-400 text-sm">to</span>
                                <input type="date" className="input input-sm w-36 text-sm" value={end} onChange={e => onEndChange(e.target.value)} />
                            </>
                        ) : (
                            <span className="text-sm text-surface-500 whitespace-nowrap tabular-nums">
                                {dayjs(start).format("D MMM YYYY")} – {dayjs(end).format("D MMM YYYY")}
                            </span>
                        )}
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
            <div className="border-t border-surface-100 px-4 py-2 flex items-center gap-0.5 flex-wrap">
                {/* Export CSV */}
                <ExportCsvButton
                    path={exportPath}
                    params={params}
                    label="Export CSV"
                />

                {/* Download PDF */}
                <ReportPdfButton type={reportType as any} params={params} compact />

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

                {/* Schedule */}
                {canExport && (
                <button
                    onClick={() => setShowSchedule(true)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-surface-600 hover:bg-surface-100 hover:text-surface-900 transition-colors"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Schedule
                </button>
                )}

                <button
                    onClick={() => setShowSchedules(s => !s)}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-surface-600 hover:bg-surface-100 hover:text-surface-900 transition-colors"
                >
                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                    </svg>
                    Schedules {showSchedules ? "▲" : "▼"}
                </button>
            </div>

            {/* Schedules list (inline) */}
            {showSchedules && (
                <div className="border-t border-surface-100 px-5 py-4 bg-surface-50/60">
                    <p className="text-xs font-semibold text-surface-400 uppercase tracking-wider mb-3">Active Schedules</p>
                    <SchedulesList reportType={reportType} />
                </div>
            )}

            {/* Schedule create modal */}
            {showSchedule && (
                <ScheduleModal
                    reportType={reportType}
                    params={params}
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
 * Self-contained date filter hook - each report section owns its own date range.
 */
export function useDateRange(defaultPreset: DatePreset = "this_month") {
    const initial = datePresetRange(defaultPreset);
    const [preset, setPreset] = useState<DatePreset>(defaultPreset);
    const [start, setStart] = useState(initial.start);
    const [end, setEnd] = useState(initial.end);

    function handlePreset(p: DatePreset) {
        setPreset(p);
        if (p !== "custom") {
            const r = datePresetRange(p);
            setStart(r.start);
            setEnd(r.end);
        }
    }

    return {
        preset,
        start,
        end,
        setStart,
        setEnd,
        handlePreset,
        params: { start_date: start, end_date: end },
    };
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
.badge-grey{background:#f3f4f6;color:#6b7280}
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