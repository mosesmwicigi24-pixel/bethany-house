import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { activityLogApi } from "@/api/profile";
import type { ActivityLogEntry, RequestLogEntry } from "@/api/profile";
import { useTableState } from "@/hooks/useTableState";
import { DataTable, Pagination } from "@/components/ui/DataTable";
import { Modal } from "@/components/ui/Modal";
import { ActionBadge, EntryDetails, subjectLabel } from "@/components/audit/AuditParts";

// The audit trail (super admins only). Two records:
//   Changes         — activity_log: every change, login, approval, with before/after
//   Staff activity  — request_logs: every staff API call, i.e. what was looked at
// It is permanent: there is no "clear" here, and the server refuses one.

const ACTION_OPTIONS = [
    "created",
    "updated",
    "deleted",
    "admin_login",
    "admin_login_failed",
    "admin_login_2fa_failed",
    "logout",
    "settings_updated",
    "role_changed",
    "permissions_synced",
    "status_changed",
    "password_changed",
    "payment_recorded",
    "pos_payment_recorded",
    "database_restored",
    "database_full_wipe",
    "audit_clear_refused",
    "audit_verification_failed",
];

type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number; from: number; to: number };

function when(iso: string) {
    const d = new Date(iso);
    return (
        <div>
            <p className="text-xs text-surface-700">{d.toLocaleDateString("en-GB")}</p>
            <p className="text-xs text-surface-400">
                {d.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}
            </p>
        </div>
    );
}

function who(name?: string | null, email?: string | null) {
    return (
        <div>
            <p className="text-sm text-surface-800">{name?.trim() || "System"}</p>
            {email && <p className="text-xs text-surface-400">{email}</p>}
        </div>
    );
}

function Pager<T>({ page, isLoading, onPage }: { page?: Paginated<T>; isLoading: boolean; onPage: (p: number) => void }) {
    if (!page) return null;
    return (
        <Pagination
            page={page.current_page}
            lastPage={page.last_page}
            total={page.total}
            from={page.from}
            to={page.to}
            isLoading={isLoading}
            onPage={onPage}
        />
    );
}

// ── Integrity badge ─────────────────────────────────────────────────────────

function IntegrityBadge() {
    const { data } = useQuery({ queryKey: ["audit-integrity"], queryFn: () => activityLogApi.integrity() });
    if (!data) return null;

    const seals = Object.entries(data.tables)
        .map(([t, s]) => `${t}: ${s.last_seal ? `sealed to #${s.last_seal.last_id} (${s.last_seal.hash.slice(0, 12)}…)` : "not yet sealed"}, ${s.unsealed_rows} awaiting`)
        .join("\n");

    if (!data.last_check) {
        return (
            <span title={seals} className="inline-flex items-center gap-1.5 rounded-full bg-surface-100 px-3 py-1 text-xs text-surface-600">
                ● Awaiting first seal (nightly 00:20)
            </span>
        );
    }
    const at = new Date(data.last_check.checked_at).toLocaleString("en-GB");
    return data.last_check.ok ? (
        <span title={seals} className="inline-flex items-center gap-1.5 rounded-full bg-success-light px-3 py-1 text-xs font-medium text-success">
            ✓ Verified intact · {at}
        </span>
    ) : (
        <span title={seals} className="inline-flex items-center gap-1.5 rounded-full bg-danger-light px-3 py-1 text-xs font-semibold text-danger">
            ✕ Integrity check FAILED · {at}
        </span>
    );
}

// ── Changes (activity_log) ──────────────────────────────────────────────────

function ChangesTab() {
    const table = useTableState({ defaultSortBy: "created_at", defaultPerPage: 30 });
    const [detail, setDetail] = useState<ActivityLogEntry | null>(null);
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
        queryFn: () => activityLogApi.list(params) as unknown as Promise<Paginated<ActivityLogEntry>>,
    });

    return (
        <>
            <div className="card p-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <input
                        className="input"
                        placeholder="Search description or person…"
                        value={table.state.search}
                        onChange={(e) => table.setSearch(e.target.value)}
                    />
                    <select className="input" value={actionFilter} onChange={(e) => setActionFilter(e.target.value)}>
                        <option value="">All actions</option>
                        {ACTION_OPTIONS.map((a) => (
                            <option key={a} value={a}>{a.split("_").map((w) => w[0].toUpperCase() + w.slice(1)).join(" ")}</option>
                        ))}
                    </select>
                    <input className="input" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                    <div className="flex gap-2">
                        <input className="input flex-1" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                        <button
                            onClick={() => { table.setSearch(""); setActionFilter(""); setDateFrom(""); setDateTo(""); }}
                            className="btn-ghost btn-sm text-xs shrink-0"
                            title="Reset filters"
                        >✕</button>
                    </div>
                </div>
            </div>

            <div className="card">
                <DataTable
                    columns={[
                        { key: "action", label: "Action", render: (r) => <ActionBadge action={(r as unknown as ActivityLogEntry).action} /> },
                        {
                            key: "description", label: "What happened",
                            render: (r) => {
                                const e = r as unknown as ActivityLogEntry;
                                const s = subjectLabel(e.subject_type, e.subject_id);
                                return (
                                    <div className="max-w-md">
                                        <p className="text-sm text-surface-700 truncate">{e.description || "-"}</p>
                                        {s && <p className="text-xs text-surface-400">{s}</p>}
                                    </div>
                                );
                            },
                        },
                        { key: "user_name", label: "Who", render: (r) => { const e = r as unknown as ActivityLogEntry; return who(e.user_name, e.user_email); } },
                        { key: "ip_address", label: "Where", render: (r) => <span className="text-xs font-mono text-surface-500">{(r as unknown as ActivityLogEntry).ip_address || "-"}</span> },
                        { key: "created_at", label: "When", render: (r) => when((r as unknown as ActivityLogEntry).created_at) },
                        {
                            key: "id", label: "", width: "60px",
                            render: (r) => <button onClick={() => setDetail(r as unknown as ActivityLogEntry)} className="btn-ghost btn-sm text-xs">View</button>,
                        },
                    ]}
                    data={(data?.data ?? []) as unknown as Record<string, unknown>[]}
                    isLoading={isLoading}
                    sortBy={table.state.sortBy}
                    sortDir={table.state.sortDir}
                    onSort={table.setSort}
                    emptyMessage="No activity recorded for these filters."
                />
                <Pager page={data} isLoading={isLoading} onPage={table.setPage} />
            </div>

            <Modal
                open={!!detail}
                onClose={() => setDetail(null)}
                title="Log entry"
                size="lg"
                footer={<button onClick={() => setDetail(null)} className="btn-secondary btn-sm">Close</button>}
            >
                {detail && (
                    <div className="space-y-4 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <ActionBadge action={detail.action} />
                            <span className="text-surface-800">{detail.description}</span>
                        </div>
                        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div><dt className="text-xs text-surface-400">Performed by</dt><dd className="text-surface-800">{detail.user_name?.trim() || "System"}{detail.user_email ? ` (${detail.user_email})` : ""}</dd></div>
                            <div><dt className="text-xs text-surface-400">When</dt><dd className="text-surface-700">{new Date(detail.created_at).toLocaleString("en-GB")}</dd></div>
                            <div><dt className="text-xs text-surface-400">Record</dt><dd className="text-surface-700">{subjectLabel(detail.subject_type, detail.subject_id) ?? "—"}</dd></div>
                            <div><dt className="text-xs text-surface-400">IP address</dt><dd className="font-mono text-surface-700">{detail.ip_address || "—"}</dd></div>
                            <div className="sm:col-span-2"><dt className="text-xs text-surface-400">Device</dt><dd className="text-xs text-surface-600 break-all">{detail.user_agent || "—"}</dd></div>
                            <div className="sm:col-span-2"><dt className="text-xs text-surface-400">Request id / log id</dt><dd className="font-mono text-xs text-surface-500">{detail.request_id || "—"} · #{detail.id}</dd></div>
                        </dl>
                        <EntryDetails entry={detail} />
                    </div>
                )}
            </Modal>
        </>
    );
}

// ── Staff activity (request_logs) ───────────────────────────────────────────

const METHOD_STYLE: Record<string, string> = {
    GET: "bg-surface-100 text-surface-600",
    POST: "bg-success-light text-success",
    PUT: "bg-info-light text-info",
    PATCH: "bg-info-light text-info",
    DELETE: "bg-danger-light text-danger",
};

function RequestsTab() {
    const [page, setPage] = useState(1);
    const [path, setPath] = useState("");
    const [method, setMethod] = useState("");
    const [dateFrom, setDateFrom] = useState("");
    const [dateTo, setDateTo] = useState("");
    const [bulkOnly, setBulkOnly] = useState(false);

    const params: Record<string, string> = {
        page: String(page),
        ...(path && { path }),
        ...(method && { method }),
        ...(dateFrom && { start_date: dateFrom }),
        ...(dateTo && { end_date: dateTo }),
        ...(bulkOnly && { min_rows: "50" }),
    };

    const { data, isLoading } = useQuery({
        queryKey: ["request-logs", params],
        queryFn: () => activityLogApi.requests(params) as Promise<Paginated<RequestLogEntry>>,
    });

    const reset = (fn: () => void) => { fn(); setPage(1); };

    return (
        <>
            <div className="card p-4 space-y-3">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <input className="input" placeholder="Path contains… (e.g. customers)" value={path} onChange={(e) => reset(() => setPath(e.target.value))} />
                    <select className="input" value={method} onChange={(e) => reset(() => setMethod(e.target.value))}>
                        <option value="">All methods</option>
                        {["GET", "POST", "PUT", "PATCH", "DELETE"].map((m) => <option key={m} value={m}>{m}</option>)}
                    </select>
                    <input className="input" type="date" value={dateFrom} onChange={(e) => reset(() => setDateFrom(e.target.value))} />
                    <input className="input" type="date" value={dateTo} onChange={(e) => reset(() => setDateTo(e.target.value))} />
                </div>
                <label className="flex items-center gap-2 text-xs text-surface-600">
                    <input type="checkbox" checked={bulkOnly} onChange={(e) => reset(() => setBulkOnly(e.target.checked))} />
                    Bulk reads only — calls that returned 50 or more records (how data is copied without a download button)
                </label>
            </div>

            <div className="card">
                <DataTable
                    columns={[
                        { key: "occurred_at", label: "When", render: (r) => when((r as unknown as RequestLogEntry).occurred_at) },
                        { key: "user_name", label: "Who", render: (r) => { const e = r as unknown as RequestLogEntry; return who(e.user_name, e.user_email); } },
                        {
                            key: "path", label: "Request",
                            render: (r) => {
                                const e = r as unknown as RequestLogEntry;
                                return (
                                    <div className="max-w-md">
                                        <span className={`mr-2 inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold ${METHOD_STYLE[e.method] ?? METHOD_STYLE.GET}`}>{e.method}</span>
                                        <span className="font-mono text-xs text-surface-700 break-all">{e.path}</span>
                                        {e.query && <p className="font-mono text-[11px] text-surface-400 truncate">{e.query}</p>}
                                    </div>
                                );
                            },
                        },
                        {
                            key: "status", label: "Result",
                            render: (r) => { const s = (r as unknown as RequestLogEntry).status; return <span className={`text-xs font-mono ${s >= 400 ? "text-danger" : "text-surface-600"}`}>{s}</span>; },
                        },
                        {
                            key: "rows_returned", label: "Records",
                            render: (r) => { const n = (r as unknown as RequestLogEntry).rows_returned; return <span className={`text-xs ${n && n >= 50 ? "font-semibold text-warning" : "text-surface-500"}`}>{n ?? "—"}</span>; },
                        },
                        { key: "ip_address", label: "Where", render: (r) => <span className="text-xs font-mono text-surface-500">{(r as unknown as RequestLogEntry).ip_address || "-"}</span> },
                    ]}
                    data={(data?.data ?? []) as unknown as Record<string, unknown>[]}
                    isLoading={isLoading}
                    emptyMessage="No staff activity recorded for these filters."
                />
                <Pager page={data} isLoading={isLoading} onPage={setPage} />
            </div>
        </>
    );
}

// ── Page ────────────────────────────────────────────────────────────────────

export default function ActivityLogsPage() {
    const [tab, setTab] = useState<"changes" | "requests">("changes");

    return (
        <div className="space-y-5 animate-fade-in">
            <div className="page-header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="page-title">Activity Log</h1>
                    <p className="page-subtitle">
                        A permanent record of every change and every staff action. It cannot be edited or cleared.
                    </p>
                </div>
                <IntegrityBadge />
            </div>

            <div className="flex gap-1 border-b border-surface-200">
                {([["changes", "Changes"], ["requests", "Staff activity"]] as const).map(([k, label]) => (
                    <button
                        key={k}
                        onClick={() => setTab(k)}
                        className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px ${tab === k ? "border-brand-600 text-brand-700" : "border-transparent text-surface-500 hover:text-surface-700"}`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === "changes" ? <ChangesTab /> : <RequestsTab />}
        </div>
    );
}
