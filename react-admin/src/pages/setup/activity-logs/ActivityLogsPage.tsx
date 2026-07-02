import { useState } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { activityLogApi } from "@/api/profile";
import { useToastStore } from "@/store/toast.store";
import { useTableState } from "@/hooks/useTableState";
import { DataTable, Pagination } from "@/components/ui/DataTable";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import type { ActivityLogEntry } from "@/api/profile";
import type { ApiError } from "@/types";

// ── Helpers ────────────────────────────────────────────────────────────────────

function actionMeta(action?: string | null): { bg: string; text: string } {
    if (!action) return { bg: "bg-surface-100", text: "text-surface-500" };
    if (action.includes("login"))
        return { bg: "bg-brand-50", text: "text-brand-600" };
    if (action.includes("created"))
        return { bg: "bg-success-light", text: "text-success" };
    if (action.includes("deleted"))
        return { bg: "bg-danger-light", text: "text-danger" };
    if (action.includes("updated") || action.includes("settings"))
        return { bg: "bg-info-light", text: "text-info" };
    if (action.includes("password"))
        return { bg: "bg-warning-light", text: "text-warning" };
    if (action.includes("role"))
        return { bg: "bg-purple-50", text: "text-purple-600" };
    return { bg: "bg-surface-100", text: "text-surface-500" };
}

function actionLabel(action?: string | null): string {
    if (!action) return "Unknown";
    return action
        .split("_")
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(" ");
}

const ACTION_OPTIONS = [
    "login",
    "logout",
    "user_created",
    "user_updated",
    "user_deleted",
    "user_role_changed",
    "user_status_changed",
    "password_reset",
    "settings_updated",
    "logo_uploaded",
    "bulk_status_update",
];

// ── Component ──────────────────────────────────────────────────────────────────

export default function ActivityLogsPage() {
    const toast = useToastStore();
    const table = useTableState({
        defaultSortBy: "created_at",
        defaultPerPage: 30,
    });

    const [clearModal, setClearModal] = useState(false);
    const [clearDays, setClearDays] = useState(90);
    const [detailEntry, setDetailEntry] = useState<ActivityLogEntry | null>(
        null,
    );
    const [dateFrom, setDateFrom] = useState("");
    const [dateTo, setDateTo] = useState("");
    const [actionFilter, setActionFilter] = useState("");

    const params: Record<string, string> = {
        ...table.toParams(),
        ...(actionFilter && { action: actionFilter }),
        ...(dateFrom && { start_date: dateFrom }),
        ...(dateTo && { end_date: dateTo }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["activity-logs", params],
        queryFn: () => activityLogApi.list(params),
    });

    const logs = data?.data ?? [];
    const meta = data?.meta as any;

    const clearMutation = useMutation({
        mutationFn: () => activityLogApi.clear(clearDays),
        onSuccess: (res) => {
            toast.success(res.message);
            setClearModal(false);
        },
        onError: (err: ApiError) => toast.error(err.message),
    });

    const resetFilters = () => {
        table.setSearch("");
        setActionFilter("");
        setDateFrom("");
        setDateTo("");
    };

    return (
        <div className="space-y-5 animate-fade-in">
            {/* Header */}
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Activity Logs</h1>
                    <p className="page-subtitle">
                        Audit trail of all actions performed in the system.
                    </p>
                </div>
                <button
                    onClick={() => setClearModal(true)}
                    className="btn-secondary btn-sm text-danger"
                >
                    Clear old logs
                </button>
            </div>

            {/* Filters */}
            <div className="card p-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <input
                        className="input"
                        placeholder="Search description or user…"
                        value={table.state.search}
                        onChange={(e) => table.setSearch(e.target.value)}
                    />
                    <select
                        className="input"
                        value={actionFilter}
                        onChange={(e) => setActionFilter(e.target.value)}
                    >
                        <option value="">All actions</option>
                        {ACTION_OPTIONS.map((a) => (
                            <option key={a} value={a}>
                                {actionLabel(a)}
                            </option>
                        ))}
                    </select>
                    <input
                        className="input"
                        type="date"
                        value={dateFrom}
                        onChange={(e) => setDateFrom(e.target.value)}
                        placeholder="From date"
                    />
                    <div className="flex gap-2">
                        <input
                            className="input flex-1"
                            type="date"
                            value={dateTo}
                            onChange={(e) => setDateTo(e.target.value)}
                            placeholder="To date"
                        />
                        <button
                            onClick={resetFilters}
                            className="btn-ghost btn-sm text-xs shrink-0"
                            title="Reset filters"
                        >
                            ✕
                        </button>
                    </div>
                </div>
            </div>

            {/* Table */}
            <div className="card">
                <DataTable
                    columns={[
                        {
                            key: "action",
                            label: "Action",
                            render: (row) => {
                                const entry =
                                    row as unknown as ActivityLogEntry;
                                const m = actionMeta(entry.action);
                                return (
                                    <span
                                        className={`inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium ${m.bg} ${m.text}`}
                                    >
                                        {actionLabel(entry.action)}
                                    </span>
                                );
                            },
                        },
                        {
                            key: "description",
                            label: "Description",
                            render: (row) => (
                                <p className="text-sm text-surface-700 truncate max-w-xs">
                                    {(row as unknown as ActivityLogEntry)
                                        .description || "-"}
                                </p>
                            ),
                        },
                        {
                            key: "user_name",
                            label: "User",
                            render: (row) => {
                                const entry =
                                    row as unknown as ActivityLogEntry;
                                return (
                                    <div>
                                        <p className="text-sm text-surface-800">
                                            {entry.user_name || "System"}
                                        </p>
                                        {entry.user_email && (
                                            <p className="text-xs text-surface-400">
                                                {entry.user_email}
                                            </p>
                                        )}
                                    </div>
                                );
                            },
                        },
                        {
                            key: "ip_address",
                            label: "IP",
                            render: (row) => (
                                <span className="text-xs font-mono text-surface-500">
                                    {(row as unknown as ActivityLogEntry)
                                        .ip_address || "-"}
                                </span>
                            ),
                        },
                        {
                            key: "created_at",
                            label: "Time",
                            render: (row) => {
                                const d = new Date(
                                    (row as unknown as ActivityLogEntry)
                                        .created_at,
                                );
                                return (
                                    <div>
                                        <p className="text-xs text-surface-700">
                                            {d.toLocaleDateString("en-GB")}
                                        </p>
                                        <p className="text-xs text-surface-400">
                                            {d.toLocaleTimeString("en-GB", {
                                                hour: "2-digit",
                                                minute: "2-digit",
                                            })}
                                        </p>
                                    </div>
                                );
                            },
                        },
                        {
                            key: "id",
                            label: "",
                            width: "60px",
                            render: (row) => (
                                <button
                                    onClick={() =>
                                        setDetailEntry(
                                            row as unknown as ActivityLogEntry,
                                        )
                                    }
                                    className="btn-ghost btn-sm text-xs"
                                >
                                    View
                                </button>
                            ),
                        },
                    ]}
                    data={logs as unknown as Record<string, unknown>[]}
                    isLoading={isLoading}
                    sortBy={table.state.sortBy}
                    sortDir={table.state.sortDir}
                    onSort={table.setSort}
                    emptyMessage="No activity logs found."
                />
                {meta && (
                    <Pagination
                        page={meta.current_page}
                        lastPage={meta.last_page}
                        total={meta.total}
                        from={meta.from}
                        to={meta.to}
                        isLoading={isLoading}
                        onPage={table.setPage}
                    />
                )}
            </div>

            {/* Detail modal */}
            <Modal
                open={!!detailEntry}
                onClose={() => setDetailEntry(null)}
                title="Log Entry Detail"
                size="sm"
                footer={
                    <button
                        onClick={() => setDetailEntry(null)}
                        className="btn-secondary btn-sm"
                    >
                        Close
                    </button>
                }
            >
                {detailEntry && (
                    <dl className="space-y-3 text-sm">
                        <div>
                            <dt className="text-xs text-surface-400 mb-0.5">
                                Action
                            </dt>
                            <dd>
                                <span
                                    className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${actionMeta(detailEntry.action).bg} ${actionMeta(detailEntry.action).text}`}
                                >
                                    {actionLabel(detailEntry.action)}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-surface-400 mb-0.5">
                                Description
                            </dt>
                            <dd className="text-surface-800">
                                {detailEntry.description || "-"}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-surface-400 mb-0.5">
                                Performed by
                            </dt>
                            <dd className="text-surface-800">
                                {detailEntry.user_name || "System"}
                                {detailEntry.user_email
                                    ? ` (${detailEntry.user_email})`
                                    : ""}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-surface-400 mb-0.5">
                                IP Address
                            </dt>
                            <dd className="font-mono text-surface-700">
                                {detailEntry.ip_address || "-"}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-surface-400 mb-0.5">
                                Timestamp
                            </dt>
                            <dd className="text-surface-700">
                                {new Date(
                                    detailEntry.created_at,
                                ).toLocaleString("en-GB")}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-surface-400 mb-0.5">
                                Log ID
                            </dt>
                            <dd className="font-mono text-xs text-surface-500">
                                #{detailEntry.id}
                            </dd>
                        </div>
                    </dl>
                )}
            </Modal>

            {/* Clear modal */}
            <Modal
                open={clearModal}
                onClose={() => setClearModal(false)}
                title="Clear Old Activity Logs"
                size="sm"
                footer={
                    <>
                        <button
                            onClick={() => setClearModal(false)}
                            className="btn-secondary btn-sm"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={() => clearMutation.mutate()}
                            disabled={clearMutation.isPending}
                            className="btn-danger btn-sm"
                        >
                            {clearMutation.isPending && (
                                <Spinner
                                    size="xs"
                                    className="border-white/30 border-t-white"
                                />
                            )}
                            Clear Logs
                        </button>
                    </>
                }
            >
                <div className="space-y-3">
                    <p className="text-sm text-surface-600">
                        Permanently delete activity logs older than the
                        specified number of days. This cannot be undone.
                    </p>
                    <div>
                        <label className="label">
                            Delete logs older than (days)
                        </label>
                        <input
                            type="number"
                            min={30}
                            className="input mt-1"
                            value={clearDays}
                            onChange={(e) =>
                                setClearDays(Number(e.target.value))
                            }
                        />
                        <p className="text-xs text-surface-400 mt-1">
                            Minimum 30 days. Recommended: 90 days.
                        </p>
                    </div>
                </div>
            </Modal>
        </div>
    );
}