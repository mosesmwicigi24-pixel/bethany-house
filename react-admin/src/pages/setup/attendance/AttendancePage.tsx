import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { attendanceApi } from "@/api/attendance";
import { outletsApi } from "@/api/setup";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { Modal } from "@/components/ui/Modal";
import { Spinner } from "@/components/ui/Spinner";
import { Field, FieldInput, FieldSelect, FieldTextarea } from "@/components/setup/FormComponents";
import type { TimeEntry } from "@/api/attendance";
import type { ApiError } from "@/types";

// ── Status display ────────────────────────────────────────────────────────────

const STATUS_CONFIG = {
    active:    { label: "Clocked in", bg: "bg-info-light",    text: "text-info" },
    completed: { label: "Completed",  bg: "bg-success-light", text: "text-success" },
    flagged:   { label: "Flagged",    bg: "bg-danger-light",  text: "text-danger" },
} as const;

function formatTime(iso: string | null) {
    if (!iso) return "—";
    return new Date(iso).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
}

function formatDate(iso: string | null) {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString("en-GB", { day: "numeric", month: "short" });
}

function formatMinutes(min: number | null) {
    if (min === null || min === undefined) return "—";
    const h = Math.floor(min / 60);
    const m = min % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

// ── Correction modal ───────────────────────────────────────────────────────────

function CorrectionModal({ entry, onClose }: { entry: TimeEntry; onClose: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();

    const toLocalInput = (iso: string | null) => {
        if (!iso) return "";
        const d = new Date(iso);
        const pad = (n: number) => String(n).padStart(2, "0");
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };

    const [clockIn, setClockIn] = useState(toLocalInput(entry.clock_in_at));
    const [clockOut, setClockOut] = useState(toLocalInput(entry.clock_out_at));
    const [status, setStatus] = useState(entry.status);
    const [notes, setNotes] = useState(entry.notes ?? "");

    const mutation = useMutation({
        mutationFn: () =>
            attendanceApi.update(entry.id, {
                clock_in_at: new Date(clockIn).toISOString(),
                clock_out_at: clockOut ? new Date(clockOut).toISOString() : null,
                status,
                notes: notes || null,
            }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["attendance-entries"] });
            toast.success("Time entry updated.");
            onClose();
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={`Correct Entry #${entry.id}`}
            size="md"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm">Cancel</button>
                    <button
                        onClick={() => mutation.mutate()}
                        disabled={mutation.isPending}
                        className="btn-primary btn-sm"
                    >
                        {mutation.isPending && <Spinner size="xs" className="border-white/30 border-t-white" />}
                        Save Correction
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                {entry.clock_in_method === "override" && (
                    <div className="rounded-lg bg-warning-light text-warning text-xs px-3 py-2">
                        This shift started with a geofence override
                        {entry.overridden_by ? ` approved by ${entry.overridden_by}` : ""}.
                    </div>
                )}
                {entry.flagged_reason && (
                    <div className="rounded-lg bg-danger-light text-danger text-xs px-3 py-2">
                        Flagged: {entry.flagged_reason}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Field label="Clock in">
                        <FieldInput
                            className="input"
                            type="datetime-local"
                            value={clockIn}
                            onChange={(e) => setClockIn(e.target.value)}
                        />
                    </Field>
                    <Field label="Clock out" hint="Leave blank if still active">
                        <FieldInput
                            className="input"
                            type="datetime-local"
                            value={clockOut}
                            onChange={(e) => setClockOut(e.target.value)}
                        />
                    </Field>
                </div>

                <Field label="Status">
                    <FieldSelect
                        className="input"
                        value={status}
                        onChange={(e) => setStatus(e.target.value as TimeEntry["status"])}
                    >
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="flagged">Flagged</option>
                    </FieldSelect>
                </Field>

                <Field label="Notes" hint="Visible to the staff member and other managers">
                    <FieldTextarea
                        className="input"
                        rows={3}
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Reason for the correction…"
                    />
                </Field>
            </div>
        </Modal>
    );
}

// ── Main page ───────────────────────────────────────────────────────────────────

export default function AttendancePage() {
    const table = useTableState({ defaultSortBy: "clock_in_at", defaultPerPage: 25 });

    const [outletFilter, setOutletFilter] = useState("");
    const [statusFilter, setStatusFilter] = useState("");
    const [fromDate, setFromDate] = useState("");
    const [toDate, setToDate] = useState("");
    const [editing, setEditing] = useState<TimeEntry | null>(null);

    const params: Record<string, string> = {
        page: String(table.state.page),
        per_page: String(table.state.perPage),
        ...(outletFilter && { outlet_id: outletFilter }),
        ...(statusFilter && { status: statusFilter }),
        ...(fromDate && { from: fromDate }),
        ...(toDate && { to: toDate }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["attendance-entries", params],
        queryFn: () => attendanceApi.entries(params),
    });

    const { data: outletsData } = useQuery({
        queryKey: ["outlets"],
        queryFn: () => outletsApi.list(),
    });

    const { data: flaggedData } = useQuery({
        queryKey: ["attendance-flagged"],
        queryFn: () => attendanceApi.flagged(),
        refetchInterval: 60_000,
    });

    const entries = data?.data ?? [];
    const meta = data as unknown as { current_page: number; last_page: number; total: number; from: number; to: number } | undefined;
    const outlets = outletsData?.data ?? [];
    const flaggedCount = flaggedData?.data?.length ?? 0;

    const activeNow = entries.filter((e) => e.status === "active").length;

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Attendance</h1>
                    <p className="page-subtitle">
                        Clock-in records for outlet and workshop staff. Geofence-flagged entries need review.
                    </p>
                </div>
            </div>

            {/* Quick stats */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div className="card p-4 text-center">
                    <p className="text-2xl font-bold text-info">{activeNow}</p>
                    <p className="text-xs text-surface-500 mt-0.5">Clocked in now (page)</p>
                </div>
                <button
                    onClick={() => setStatusFilter(statusFilter === "flagged" ? "" : "flagged")}
                    className={clsx(
                        "card p-4 text-center transition-all hover:shadow-sm",
                        statusFilter === "flagged" ? "ring-2 ring-danger/40" : "",
                    )}
                >
                    <p className="text-2xl font-bold text-danger">{flaggedCount}</p>
                    <p className="text-xs text-surface-500 mt-0.5">Flagged — needs review</p>
                </button>
                <div className="card p-4 text-center hidden sm:block">
                    <p className="text-2xl font-bold text-surface-900">{meta?.total ?? 0}</p>
                    <p className="text-xs text-surface-500 mt-0.5">Entries (filtered range)</p>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <select className="input flex-1 sm:w-48 sm:flex-none" value={outletFilter} onChange={(e) => setOutletFilter(e.target.value)}>
                    <option value="">All outlets / workshops</option>
                    {outlets.map((o) => (
                        <option key={o.id} value={o.id}>{o.name}</option>
                    ))}
                </select>
                <select className="input flex-1 sm:w-40 sm:flex-none" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                    <option value="">All statuses</option>
                    <option value="active">Clocked in</option>
                    <option value="completed">Completed</option>
                    <option value="flagged">Flagged</option>
                </select>
                <input className="input flex-1 sm:w-36 sm:flex-none" type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} />
                <input className="input flex-1 sm:w-36 sm:flex-none" type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} />
                {(outletFilter || statusFilter || fromDate || toDate) && (
                    <button
                        onClick={() => { setOutletFilter(""); setStatusFilter(""); setFromDate(""); setToDate(""); }}
                        className="btn-ghost btn-sm text-xs"
                    >
                        ✕ Clear
                    </button>
                )}
            </div>

            {/* Table */}
            <div className="card overflow-hidden">
                {isLoading ? (
                    <div className="flex justify-center py-16"><Spinner size="lg" /></div>
                ) : entries.length === 0 ? (
                    <div className="text-center py-16">
                        <p className="text-surface-400 text-sm">
                            {outletFilter || statusFilter || fromDate || toDate
                                ? "No entries match your filters."
                                : "No clock-in records yet."}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px]">
                            <thead>
                                <tr className="border-b border-surface-100 bg-surface-50/50">
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">Staff</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider hidden sm:table-cell">Outlet</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">Clock in</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wider">Clock out</th>
                                    <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider hidden md:table-cell">Worked</th>
                                    <th className="px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase tracking-wider">Status</th>
                                    <th className="px-4 py-3 w-16" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-50">
                                {entries.map((entry) => {
                                    const status = STATUS_CONFIG[entry.status];
                                    return (
                                        <tr key={entry.id} className="hover:bg-surface-50/50 transition-colors">
                                            <td className="px-4 py-3">
                                                <p className="text-sm font-medium text-surface-900">{entry.user?.name ?? "—"}</p>
                                            </td>
                                            <td className="px-4 py-3 hidden sm:table-cell">
                                                <p className="text-sm text-surface-600">{entry.outlet?.name ?? "—"}</p>
                                            </td>
                                            <td className="px-4 py-3">
                                                <p className="text-sm text-surface-800">{formatTime(entry.clock_in_at)}</p>
                                                <div className="flex items-center gap-1 text-xs text-surface-400">
                                                    <span>{formatDate(entry.clock_in_at)}</span>
                                                    {entry.clock_in_method === "override" && (
                                                        <span className="badge badge-warning text-2xs">override</span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <p className="text-sm text-surface-800">{formatTime(entry.clock_out_at)}</p>
                                                {entry.on_break && entry.status === "active" && (
                                                    <span className="text-2xs text-warning">on break</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center hidden md:table-cell">
                                                <span className="text-sm tabular-nums text-surface-700">
                                                    {formatMinutes(entry.status === "active" ? entry.elapsed_minutes : entry.worked_minutes)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <span className={clsx("text-xs font-medium px-2.5 py-1 rounded-full", status.bg, status.text)}>
                                                    {status.label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <button
                                                    onClick={() => setEditing(entry)}
                                                    className="btn-ghost btn-sm text-xs text-brand-600 hover:bg-brand-50"
                                                >
                                                    Correct
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Pagination */}
                {meta && meta.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-surface-100 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-surface-500">Showing {meta.from}–{meta.to} of {meta.total}</p>
                        <div className="flex gap-1">
                            <button disabled={meta.current_page === 1} onClick={() => table.setPage(meta.current_page - 1)} className="btn-ghost btn-sm text-xs disabled:opacity-40">← Prev</button>
                            <button disabled={meta.current_page === meta.last_page} onClick={() => table.setPage(meta.current_page + 1)} className="btn-ghost btn-sm text-xs disabled:opacity-40">Next →</button>
                        </div>
                    </div>
                )}
            </div>

            {editing && <CorrectionModal entry={editing} onClose={() => setEditing(null)} />}
        </div>
    );
}
