import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { clsx } from "clsx";
import { databaseApi, type ClearableTable, type DatabaseBackup, type BackupScheduleFormData } from "@/api/database";
import { useToastStore } from "@/store/toast.store";
import { useAuthStore } from "@/store/auth.store";
import { Spinner } from "@/components/ui/Spinner";
import { Modal } from "@/components/ui/Modal";
import { ConfirmDialog } from "@/components/setup/FormComponents";
import { tokenStorage } from "@/api/client";

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatDate(dateStr: string | null): string {
    if (!dateStr) return "—";
    return new Date(dateStr).toLocaleString(undefined, {
        year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit",
    });
}

const TYPE_LABEL: Record<string, string> = {
    manual: "Manual",
    scheduled: "Scheduled",
    pre_clear: "Pre-Clear Safety",
    pre_wipe: "Pre-Wipe Safety",
};

const STATUS_STYLE: Record<string, string> = {
    success: "bg-success-light text-success",
    running: "bg-info-light text-info",
    pending: "bg-surface-100 text-surface-500",
    failed: "bg-danger-light text-danger",
};

// ── Tabs shell ────────────────────────────────────────────────────────────────

type TabKey = "backups" | "schedule" | "storage" | "cleanup" | "danger";

const TABS: { key: TabKey; label: string }[] = [
    { key: "backups", label: "Backups" },
    { key: "schedule", label: "Backup Schedule" },
    { key: "storage", label: "Storage Destination" },
    { key: "cleanup", label: "Transaction Cleanup" },
    { key: "danger", label: "Danger Zone" },
];

export default function DatabaseManagementPage() {
    const [activeTab, setActiveTab] = useState<TabKey>("backups");

    const { data: statsData } = useQuery({
        queryKey: ["database", "stats"],
        queryFn: () => databaseApi.stats(),
        staleTime: 30_000,
    });

    const pgDumpMissing = statsData && !statsData.pg_dump_available;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 className="text-xl font-bold text-surface-900 flex items-center gap-2">
                        <span className="w-8 h-8 rounded-xl bg-brand-100 flex items-center justify-center">
                            <svg className="w-4 h-4 text-brand-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M20 7c0 1.657-3.582 3-8 3S4 8.657 4 7m16 0c0-1.657-3.582-3-8-3S4 5.343 4 7m16 0v10c0 1.657-3.582 3-8 3s-8-1.343-8-3V7m16 5c0 1.657-3.582 3-8 3s-8-1.343-8-3" />
                            </svg>
                        </span>
                        Database Management
                    </h1>
                    <p className="text-sm text-surface-500 mt-0.5">
                        {statsData ? (
                            <>Database size: <span className="font-medium text-surface-700">{statsData.database_size_human}</span></>
                        ) : "Loading database statistics…"}
                    </p>
                </div>
            </div>

            {pgDumpMissing && (
                <div className="rounded-xl bg-warning-light border border-warning/30 px-4 py-3 text-sm text-surface-700">
                    <p className="font-semibold">Backups are unavailable on this server.</p>
                    <p className="mt-0.5">
                        The <code className="bg-white/60 px-1 rounded">pg_dump</code> / <code className="bg-white/60 px-1 rounded">pg_restore</code> tools
                        weren't found. Install the <code className="bg-white/60 px-1 rounded">postgresql-client</code> package on the application
                        server to enable backups, restores, and the safety-backups that precede destructive operations.
                    </p>
                </div>
            )}

            {/* Tabs */}
            <div className="card overflow-hidden">
                <div className="flex border-b border-surface-100 overflow-x-auto no-scrollbar">
                    {TABS.map((tab) => (
                        <button
                            key={tab.key}
                            onClick={() => setActiveTab(tab.key)}
                            className={clsx(
                                "px-5 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap shrink-0",
                                activeTab === tab.key
                                    ? tab.key === "danger"
                                        ? "border-danger text-danger bg-danger-light/40"
                                        : "border-brand-500 text-brand-700 bg-brand-50/50"
                                    : "border-transparent text-surface-500 hover:text-surface-800 hover:bg-surface-50",
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>

                <div className="p-5">
                    {activeTab === "backups" && <BackupsTab pgDumpAvailable={!pgDumpMissing} />}
                    {activeTab === "schedule" && <ScheduleTab />}
                    {activeTab === "storage" && <StorageTab />}
                    {activeTab === "cleanup" && <CleanupTab />}
                    {activeTab === "danger" && <DangerZoneTab />}
                </div>
            </div>
        </div>
    );
}

// ── Backups Tab ───────────────────────────────────────────────────────────────

function BackupsTab({ pgDumpAvailable }: { pgDumpAvailable: boolean }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [page, setPage] = useState(1);
    const [confirmDelete, setConfirmDelete] = useState<DatabaseBackup | null>(null);
    const [restoreTarget, setRestoreTarget] = useState<DatabaseBackup | null>(null);
    const [downloadingId, setDownloadingId] = useState<number | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ["database", "backups", page],
        queryFn: () => databaseApi.backups({ page }),
        refetchInterval: 10_000, // poll while a backup might be "running"
    });

    const createMutation = useMutation({
        mutationFn: () => databaseApi.createBackup(),
        onSuccess: () => {
            toast.success("Backup created successfully.");
            qc.invalidateQueries({ queryKey: ["database"] });
        },
        onError: (err: any) => toast.error(err?.message ?? "Backup failed."),
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => databaseApi.deleteBackup(id),
        onSuccess: () => {
            toast.success("Backup deleted.");
            setConfirmDelete(null);
            qc.invalidateQueries({ queryKey: ["database", "backups"] });
        },
        onError: (err: any) => toast.error(err?.message ?? "Failed to delete backup."),
    });

    const handleDownload = async (backup: DatabaseBackup) => {
        setDownloadingId(backup.id);
        try {
            const token = tokenStorage.get() ?? "";
            const res = await fetch(databaseApi.downloadBackupUrl(backup.id), {
                headers: { Authorization: `Bearer ${token}` },
            });
            if (!res.ok) {
                const err = await res.json().catch(() => ({ message: "Download failed." }));
                toast.error(err.message ?? "Download failed.");
                return;
            }
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = backup.filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 5000);
        } catch {
            toast.error("An error occurred while downloading the backup.");
        } finally {
            setDownloadingId(null);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between flex-wrap gap-3">
                <p className="text-sm text-surface-500">
                    Manual and scheduled backups, plus automatic safety snapshots taken before destructive operations.
                </p>
                <button
                    onClick={() => createMutation.mutate()}
                    disabled={createMutation.isPending || !pgDumpAvailable}
                    className="btn-primary btn-sm flex items-center gap-1.5"
                    title={!pgDumpAvailable ? "pg_dump is unavailable on this server" : undefined}
                >
                    {createMutation.isPending ? <Spinner size="xs" className="border-white/30 border-t-white" /> : (
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    )}
                    Create Backup Now
                </button>
            </div>

            {isLoading ? (
                <div className="flex justify-center py-10"><Spinner /></div>
            ) : !data || data.data.length === 0 ? (
                <div className="text-center py-10 text-sm text-surface-400">No backups yet. Create one to get started.</div>
            ) : (
                <div className="overflow-x-auto -mx-5">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-2xs uppercase text-surface-400 border-b border-surface-100">
                                <th className="px-5 py-2 font-medium">Filename</th>
                                <th className="px-5 py-2 font-medium">Type</th>
                                <th className="px-5 py-2 font-medium">Status</th>
                                <th className="px-5 py-2 font-medium">Size</th>
                                <th className="px-5 py-2 font-medium">Disk</th>
                                <th className="px-5 py-2 font-medium">Created</th>
                                <th className="px-5 py-2 font-medium">By</th>
                                <th className="px-5 py-2 font-medium text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-surface-50">
                            {data.data.map((b) => (
                                <tr key={b.id} className="hover:bg-surface-50/60">
                                    <td className="px-5 py-2.5 font-mono text-2xs text-surface-700">{b.filename}</td>
                                    <td className="px-5 py-2.5 text-surface-600">{TYPE_LABEL[b.type] ?? b.type}</td>
                                    <td className="px-5 py-2.5">
                                        <span className={clsx("text-2xs font-semibold px-2 py-0.5 rounded-full", STATUS_STYLE[b.status])}>
                                            {b.status}
                                        </span>
                                    </td>
                                    <td className="px-5 py-2.5 text-surface-600">
                                        {b.size_bytes ? `${(b.size_bytes / 1024 / 1024).toFixed(2)} MB` : "—"}
                                    </td>
                                    <td className="px-5 py-2.5 text-surface-600 uppercase text-2xs">{b.disk}</td>
                                    <td className="px-5 py-2.5 text-surface-600">{formatDate(b.created_at)}</td>
                                    <td className="px-5 py-2.5 text-surface-600">{b.created_by_name?.trim() || (b.triggered_by === "schedule" ? "Scheduler" : "—")}</td>
                                    <td className="px-5 py-2.5">
                                        <div className="flex items-center justify-end gap-1.5">
                                            {b.status === "success" && (
                                                <>
                                                    <button
                                                        onClick={() => handleDownload(b)}
                                                        disabled={downloadingId === b.id}
                                                        className="p-1.5 rounded hover:bg-surface-100 text-surface-500"
                                                        title="Download"
                                                    >
                                                        {downloadingId === b.id ? <Spinner size="xs" /> : (
                                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a1 1 0 001 1h16a1 1 0 001-1v-3" />
                                                            </svg>
                                                        )}
                                                    </button>
                                                    <button
                                                        onClick={() => setRestoreTarget(b)}
                                                        className="p-1.5 rounded hover:bg-warning-light text-warning"
                                                        title="Restore from this backup"
                                                    >
                                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                        </svg>
                                                    </button>
                                                </>
                                            )}
                                            <button
                                                onClick={() => setConfirmDelete(b)}
                                                className="p-1.5 rounded hover:bg-danger-light text-danger"
                                                title="Delete"
                                            >
                                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between text-xs text-surface-500">
                    <span>{((data.current_page - 1) * data.per_page) + 1}–{Math.min(data.current_page * data.per_page, data.total)} of {data.total}</span>
                    <div className="flex gap-1">
                        <button onClick={() => setPage((p) => p - 1)} disabled={data.current_page === 1} className="px-2.5 py-1 rounded border border-surface-200 disabled:opacity-40 hover:bg-surface-50">← Prev</button>
                        <button onClick={() => setPage((p) => p + 1)} disabled={data.current_page === data.last_page} className="px-2.5 py-1 rounded border border-surface-200 disabled:opacity-40 hover:bg-surface-50">Next →</button>
                    </div>
                </div>
            )}

            {/* Confirm delete */}
            <ConfirmDialog
                open={!!confirmDelete}
                onClose={() => setConfirmDelete(null)}
                onConfirm={() => confirmDelete && deleteMutation.mutate(confirmDelete.id)}
                isLoading={deleteMutation.isPending}
                title="Delete Backup"
                message={`Permanently delete "${confirmDelete?.filename}"? The backup file will be removed from storage and cannot be recovered.`}
                confirmLabel="Delete Backup"
            />

            {/* Restore flow */}
            <RestoreBackupDialog backup={restoreTarget} onClose={() => setRestoreTarget(null)} />
        </div>
    );
}

// ── Restore dialog (separate component — needs its own confirm-phrase state) ──

function RestoreBackupDialog({ backup, onClose }: { backup: DatabaseBackup | null; onClose: () => void }) {
    const toast = useToastStore();
    const qc = useQueryClient();
    const [phrase, setPhrase] = useState("");
    const [takeSafetyBackup, setTakeSafetyBackup] = useState(true);

    const restoreMutation = useMutation({
        mutationFn: () => databaseApi.restoreBackup(backup!.id, phrase, takeSafetyBackup),
        onSuccess: (res) => {
            toast.success(res.message);
            setPhrase("");
            onClose();
            qc.invalidateQueries({ queryKey: ["database"] });
        },
        onError: (err: any) => toast.error(err?.message ?? "Restore failed."),
    });

    if (!backup) return null;

    return (
        <Modal
            open={!!backup}
            onClose={onClose}
            title="Restore Database"
            size="md"
            footer={
                <>
                    <button onClick={onClose} className="btn-secondary btn-sm" disabled={restoreMutation.isPending}>Cancel</button>
                    <button
                        onClick={() => restoreMutation.mutate()}
                        disabled={restoreMutation.isPending || phrase !== "RESTORE DATABASE"}
                        className="btn-danger btn-sm"
                    >
                        {restoreMutation.isPending ? <Spinner size="xs" className="border-white/30 border-t-white" /> : "Restore Database"}
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="rounded-lg bg-danger-light border border-danger/30 px-3 py-2.5 text-sm text-surface-700">
                    <p className="font-semibold text-danger">This will overwrite the live database.</p>
                    <p className="mt-1">
                        Every record created or changed after <span className="font-medium">{formatDate(backup.created_at)}</span> (when this backup was taken)
                        will be permanently lost. This cannot be undone once it starts.
                    </p>
                </div>

                <label className="flex items-start gap-2 text-sm text-surface-600">
                    <input
                        type="checkbox"
                        checked={takeSafetyBackup}
                        onChange={(e) => setTakeSafetyBackup(e.target.checked)}
                        className="mt-0.5"
                    />
                    Take a fresh backup of the current database before restoring (recommended)
                </label>

                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">
                        Type <span className="font-mono bg-surface-100 px-1 rounded">RESTORE DATABASE</span> to confirm
                    </label>
                    <input
                        type="text"
                        value={phrase}
                        onChange={(e) => setPhrase(e.target.value)}
                        placeholder="RESTORE DATABASE"
                        className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-danger/30 focus:border-danger"
                    />
                </div>
            </div>
        </Modal>
    );
}

// ── Schedule Tab ──────────────────────────────────────────────────────────────

function ScheduleTab() {
    const toast = useToastStore();
    const qc = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ["database", "schedule"],
        queryFn: () => databaseApi.getSchedule(),
    });

    const [form, setForm] = useState<BackupScheduleFormData | null>(null);

    // Initialize local form state once the schedule loads.
    if (data && !form) {
        const s = data.schedule;
        setForm({
            is_enabled: s?.is_enabled ?? false,
            frequency: s?.frequency ?? "daily",
            run_at: s ? s.run_at.slice(0, 5) : "02:00",
            day_of_week: s?.day_of_week ?? 0,
            day_of_month: s?.day_of_month ?? 1,
            retain_count: s?.retain_count ?? 14,
            disk: s?.disk ?? "local",
        });
    }

    const saveMutation = useMutation({
        mutationFn: (payload: BackupScheduleFormData) => databaseApi.updateSchedule(payload),
        onSuccess: () => {
            toast.success("Backup schedule saved.");
            qc.invalidateQueries({ queryKey: ["database", "schedule"] });
        },
        onError: (err: any) => toast.error(err?.message ?? "Failed to save schedule."),
    });

    if (isLoading || !form) {
        return <div className="flex justify-center py-10"><Spinner /></div>;
    }

    const schedule = data?.schedule;

    return (
        <div className="max-w-xl space-y-5">
            <p className="text-sm text-surface-500">
                Automatically create a backup on a recurring schedule. A background job checks this configuration every minute and only runs once per configured slot — changing the time here takes effect on the next tick without a server restart.
            </p>

            {schedule?.last_run_at && (
                <div className={clsx(
                    "rounded-lg px-3 py-2 text-xs",
                    schedule.last_run_status === "failed" ? "bg-danger-light text-danger" : "bg-surface-50 text-surface-600",
                )}>
                    Last run: {formatDate(schedule.last_run_at)} —{" "}
                    <span className="font-medium">{schedule.last_run_status === "failed" ? "Failed" : "Success"}</span>
                    {schedule.last_run_error && <div className="mt-1">{schedule.last_run_error}</div>}
                </div>
            )}

            <label className="flex items-center justify-between rounded-lg border border-surface-200 px-4 py-3">
                <span className="text-sm font-medium text-surface-700">Enable scheduled backups</span>
                <input
                    type="checkbox"
                    checked={form.is_enabled}
                    onChange={(e) => setForm({ ...form, is_enabled: e.target.checked })}
                    className="w-4 h-4"
                />
            </label>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">Frequency</label>
                    <select
                        value={form.frequency}
                        onChange={(e) => setForm({ ...form, frequency: e.target.value as BackupScheduleFormData["frequency"] })}
                        className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                    >
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">Time of day</label>
                    <input
                        type="time"
                        value={form.run_at}
                        onChange={(e) => setForm({ ...form, run_at: e.target.value })}
                        className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                    />
                </div>
            </div>

            {form.frequency === "weekly" && (
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">Day of week</label>
                    <select
                        value={form.day_of_week ?? 0}
                        onChange={(e) => setForm({ ...form, day_of_week: Number(e.target.value) })}
                        className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                    >
                        {["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"].map((d, i) => (
                            <option key={i} value={i}>{d}</option>
                        ))}
                    </select>
                </div>
            )}

            {form.frequency === "monthly" && (
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">Day of month (1–28)</label>
                    <input
                        type="number"
                        min={1}
                        max={28}
                        value={form.day_of_month ?? 1}
                        onChange={(e) => setForm({ ...form, day_of_month: Number(e.target.value) })}
                        className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                    />
                    <p className="text-2xs text-surface-400 mt-1">Capped at 28 so it runs reliably in every month, including February.</p>
                </div>
            )}

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">Keep last N backups</label>
                    <input
                        type="number"
                        min={1}
                        max={365}
                        value={form.retain_count}
                        onChange={(e) => setForm({ ...form, retain_count: Number(e.target.value) })}
                        className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                    />
                    <p className="text-2xs text-surface-400 mt-1">Older scheduled backups beyond this count are pruned automatically.</p>
                </div>
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">Storage destination</label>
                    <select
                        value={form.disk}
                        onChange={(e) => setForm({ ...form, disk: e.target.value as "local" | "s3" })}
                        className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                    >
                        <option value="local">Local disk</option>
                        <option value="s3">S3 / S3-compatible</option>
                    </select>
                    <p className="text-2xs text-surface-400 mt-1">Configure connection details in the "Storage Destination" tab.</p>
                </div>
            </div>

            <div className="flex justify-end">
                <button
                    onClick={() => saveMutation.mutate(form)}
                    disabled={saveMutation.isPending}
                    className="btn-primary btn-sm"
                >
                    {saveMutation.isPending ? <Spinner size="xs" className="border-white/30 border-t-white" /> : "Save Schedule"}
                </button>
            </div>
        </div>
    );
}

// ── Storage Destination Tab ──────────────────────────────────────────────────

function StorageTab() {
    const toast = useToastStore();
    const qc = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ["database", "storage-settings"],
        queryFn: () => databaseApi.getStorageSettings(),
    });

    const [form, setForm] = useState<Record<string, string> | null>(null);
    if (data && !form) {
        setForm({ ...data.settings });
    }

    const saveMutation = useMutation({
        mutationFn: (payload: Record<string, string>) => databaseApi.updateStorageSettings(payload),
        onSuccess: () => {
            toast.success("Storage settings saved.");
            qc.invalidateQueries({ queryKey: ["database", "storage-settings"] });
        },
        onError: (err: any) => toast.error(err?.message ?? "Failed to save storage settings."),
    });

    const testMutation = useMutation({
        mutationFn: (disk: "local" | "s3") => databaseApi.testStorageSettings(disk),
        onSuccess: (res) => (res.ok ? toast.success(res.message) : toast.error(res.message)),
        onError: (err: any) => toast.error(err?.message ?? "Connection test failed."),
    });

    if (isLoading || !form) {
        return <div className="flex justify-center py-10"><Spinner /></div>;
    }

    const isS3 = form.backup_storage_disk === "s3";

    return (
        <div className="max-w-xl space-y-5">
            <p className="text-sm text-surface-500">
                Choose where backup files are stored. Local disk is simplest but is lost if the server is destroyed —
                an off-site S3-compatible destination (AWS S3, DigitalOcean Spaces, MinIO, Backblaze B2, etc.) is recommended for production.
            </p>

            <div>
                <label className="text-xs font-medium text-surface-600 mb-1 block">Default storage disk for manual backups</label>
                <select
                    value={form.backup_storage_disk}
                    onChange={(e) => setForm({ ...form, backup_storage_disk: e.target.value })}
                    className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                >
                    <option value="local">Local disk</option>
                    <option value="s3">S3 / S3-compatible</option>
                </select>
            </div>

            <div>
                <label className="text-xs font-medium text-surface-600 mb-1 block">Local backups to retain</label>
                <input
                    type="number"
                    min={1}
                    max={365}
                    value={form.backup_local_retain_count}
                    onChange={(e) => setForm({ ...form, backup_local_retain_count: e.target.value })}
                    className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                />
            </div>

            {isS3 && (
                <div className="rounded-xl border border-surface-200 p-4 space-y-4">
                    <p className="text-xs font-semibold text-surface-600 uppercase">S3 Connection</p>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="text-xs font-medium text-surface-600 mb-1 block">Bucket</label>
                            <input value={form.backup_s3_bucket} onChange={(e) => setForm({ ...form, backup_s3_bucket: e.target.value })} className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm" placeholder="my-app-backups" />
                        </div>
                        <div>
                            <label className="text-xs font-medium text-surface-600 mb-1 block">Region</label>
                            <input value={form.backup_s3_region} onChange={(e) => setForm({ ...form, backup_s3_region: e.target.value })} className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm" placeholder="eu-west-1" />
                        </div>
                    </div>

                    <div>
                        <label className="text-xs font-medium text-surface-600 mb-1 block">
                            Custom endpoint <span className="text-surface-400">(leave blank for AWS S3)</span>
                        </label>
                        <input value={form.backup_s3_endpoint} onChange={(e) => setForm({ ...form, backup_s3_endpoint: e.target.value })} className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm" placeholder="https://nyc3.digitaloceanspaces.com" />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="text-xs font-medium text-surface-600 mb-1 block">Access Key</label>
                            <input value={form.backup_s3_key} onChange={(e) => setForm({ ...form, backup_s3_key: e.target.value })} className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm font-mono" />
                        </div>
                        <div>
                            <label className="text-xs font-medium text-surface-600 mb-1 block">Secret Key</label>
                            <input
                                type="password"
                                value={form.backup_s3_secret}
                                onChange={(e) => setForm({ ...form, backup_s3_secret: e.target.value })}
                                className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm font-mono"
                                placeholder="Leave unchanged to keep current secret"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="text-xs font-medium text-surface-600 mb-1 block">Key prefix</label>
                        <input value={form.backup_s3_prefix} onChange={(e) => setForm({ ...form, backup_s3_prefix: e.target.value })} className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm" placeholder="backups/" />
                    </div>

                    <label className="flex items-center gap-2 text-sm text-surface-600">
                        <input
                            type="checkbox"
                            checked={form.backup_s3_use_path_style === "1"}
                            onChange={(e) => setForm({ ...form, backup_s3_use_path_style: e.target.checked ? "1" : "0" })}
                        />
                        Use path-style endpoint (required by some self-hosted S3-compatible providers like MinIO)
                    </label>

                    <button
                        onClick={() => testMutation.mutate("s3")}
                        disabled={testMutation.isPending}
                        className="btn-secondary btn-sm"
                    >
                        {testMutation.isPending ? <Spinner size="xs" /> : "Test Connection"}
                    </button>
                </div>
            )}

            <div>
                <label className="text-xs font-medium text-surface-600 mb-1 block">
                    Notify on scheduled backup failure <span className="text-surface-400">(optional)</span>
                </label>
                <input
                    type="email"
                    value={form.backup_notify_email}
                    onChange={(e) => setForm({ ...form, backup_notify_email: e.target.value })}
                    className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                    placeholder="ops@example.com"
                />
            </div>

            <div className="flex justify-end">
                <button
                    onClick={() => saveMutation.mutate(form)}
                    disabled={saveMutation.isPending}
                    className="btn-primary btn-sm"
                >
                    {saveMutation.isPending ? <Spinner size="xs" className="border-white/30 border-t-white" /> : "Save Storage Settings"}
                </button>
            </div>
        </div>
    );
}

// ── Transaction Cleanup Tab ──────────────────────────────────────────────────

function CleanupTab() {
    const toast = useToastStore();
    const qc = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ["database", "clearable-tables"],
        queryFn: () => databaseApi.clearableTables(),
    });

    const [selected, setSelected] = useState<string[]>([]);
    const [beforeDate, setBeforeDate] = useState<string>(() => new Date().toISOString().slice(0, 10));
    const [preview, setPreview] = useState<{ preview: Record<string, number>; total_rows: number } | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmChecked, setConfirmChecked] = useState(false);
    const [skipBackup, setSkipBackup] = useState(false);

    const toggle = (key: string) => {
        setSelected((prev) => (prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]));
        setPreview(null);
    };

    const previewMutation = useMutation({
        mutationFn: () => databaseApi.clearPreview(selected, beforeDate),
        onSuccess: (res) => setPreview(res),
        onError: (err: any) => toast.error(err?.message ?? "Failed to preview."),
    });

    const clearMutation = useMutation({
        mutationFn: () => databaseApi.clear({ tables: selected, before_date: beforeDate, confirm: true, skip_auto_backup: skipBackup }),
        onSuccess: (res) => {
            toast.success(`${res.message} ${res.total_deleted} row(s) removed.`);
            setConfirmOpen(false);
            setConfirmChecked(false);
            setPreview(null);
            setSelected([]);
            qc.invalidateQueries({ queryKey: ["database"] });
        },
        onError: (err: any) => toast.error(err?.message ?? "Failed to clear transaction data."),
    });

    const tables = data?.data ?? [];
    const ledgerTables = tables.filter((t) => t.group === "ledger");
    const businessTables = tables.filter((t) => t.group === "business");

    if (isLoading) {
        return <div className="flex justify-center py-10"><Spinner /></div>;
    }

    return (
        <div className="space-y-5">
            <p className="text-sm text-surface-500 max-w-2xl">
                Permanently remove old transaction records to keep the database lean. Pick which tables to include and a
                cutoff date — only rows older than that date are affected. An automatic safety backup is taken first
                unless you opt out below.
            </p>

            <TableGroup
                title="Transaction Ledgers"
                hint="Low-risk, append-only logs. Safe to clear regularly."
                tables={ledgerTables}
                selected={selected}
                onToggle={toggle}
            />

            <TableGroup
                title="Business Records"
                hint="Primary records like Orders and Payments. Only clear these if you're certain — clearing an order also removes its items, status history, shipments, returns, and payments."
                tables={businessTables}
                selected={selected}
                onToggle={toggle}
                emphasize
            />

            <div className="grid grid-cols-2 gap-4 max-w-md">
                <div>
                    <label className="text-xs font-medium text-surface-600 mb-1 block">Delete everything before</label>
                    <input
                        type="date"
                        value={beforeDate}
                        onChange={(e) => { setBeforeDate(e.target.value); setPreview(null); }}
                        max={new Date().toISOString().slice(0, 10)}
                        className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                    />
                </div>
                <div className="flex items-end">
                    <button
                        onClick={() => previewMutation.mutate()}
                        disabled={selected.length === 0 || previewMutation.isPending}
                        className="btn-secondary btn-sm w-full"
                    >
                        {previewMutation.isPending ? <Spinner size="xs" /> : "Preview Impact"}
                    </button>
                </div>
            </div>

            {preview && (
                <div className="rounded-xl border border-surface-200 p-4 max-w-md">
                    <p className="text-sm font-semibold text-surface-700 mb-2">
                        {preview.total_rows === 0 ? "Nothing to delete" : `${preview.total_rows} row(s) will be deleted`}
                    </p>
                    <ul className="space-y-1 text-xs text-surface-600">
                        {Object.entries(preview.preview).map(([table, count]) => (
                            <li key={table} className="flex justify-between">
                                <span>{labelForTable(tables, table)}</span>
                                <span className="font-mono">{count}</span>
                            </li>
                        ))}
                    </ul>

                    {preview.total_rows > 0 && (
                        <button
                            onClick={() => setConfirmOpen(true)}
                            className="btn-danger btn-sm w-full mt-3"
                        >
                            Delete These Rows
                        </button>
                    )}
                </div>
            )}

            {/* Confirm clear modal */}
            <Modal
                open={confirmOpen}
                onClose={() => { setConfirmOpen(false); setConfirmChecked(false); }}
                title="Confirm Transaction Cleanup"
                size="md"
                footer={
                    <>
                        <button onClick={() => setConfirmOpen(false)} className="btn-secondary btn-sm" disabled={clearMutation.isPending}>Cancel</button>
                        <button
                            onClick={() => clearMutation.mutate()}
                            disabled={!confirmChecked || clearMutation.isPending}
                            className="btn-danger btn-sm"
                        >
                            {clearMutation.isPending ? <Spinner size="xs" className="border-white/30 border-t-white" /> : "Delete Permanently"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <p className="text-sm text-surface-600">
                        You're about to permanently delete <span className="font-semibold">{preview?.total_rows ?? 0}</span> row(s)
                        from {selected.length} table{selected.length !== 1 ? "s" : ""} dated before <span className="font-semibold">{beforeDate}</span>.
                        This cannot be undone.
                    </p>

                    <label className="flex items-start gap-2 text-sm text-surface-600">
                        <input
                            type="checkbox"
                            checked={skipBackup}
                            onChange={(e) => setSkipBackup(e.target.checked)}
                            className="mt-0.5"
                        />
                        Skip the automatic safety backup (not recommended)
                    </label>

                    <label className="flex items-start gap-2 text-sm font-medium text-surface-700">
                        <input
                            type="checkbox"
                            checked={confirmChecked}
                            onChange={(e) => setConfirmChecked(e.target.checked)}
                            className="mt-0.5"
                        />
                        I understand this cannot be undone and want to proceed
                    </label>
                </div>
            </Modal>
        </div>
    );
}

function labelForTable(tables: ClearableTable[], key: string): string {
    return tables.find((t) => t.key === key)?.label ?? key;
}

function TableGroup({
    title, hint, tables, selected, onToggle, emphasize,
}: {
    title: string;
    hint: string;
    tables: ClearableTable[];
    selected: string[];
    onToggle: (key: string) => void;
    emphasize?: boolean;
}) {
    if (tables.length === 0) return null;
    return (
        <div className={clsx("rounded-xl border p-4", emphasize ? "border-warning/30 bg-warning-light/30" : "border-surface-200")}>
            <p className="text-sm font-semibold text-surface-700">{title}</p>
            <p className="text-xs text-surface-500 mt-0.5 mb-3">{hint}</p>
            <div className="grid sm:grid-cols-2 gap-2">
                {tables.map((t) => (
                    <label key={t.key} className="flex items-start gap-2 text-sm text-surface-700 rounded-lg border border-surface-100 bg-white px-3 py-2 cursor-pointer hover:border-surface-300">
                        <input
                            type="checkbox"
                            checked={selected.includes(t.key)}
                            onChange={() => onToggle(t.key)}
                            className="mt-0.5"
                        />
                        <span>
                            <span className="block">{t.label}</span>
                            <span className="block text-2xs text-surface-400">{t.row_count.toLocaleString()} rows currently{t.children.length > 0 ? ` · cascades to ${t.children.length} related table(s)` : ""}</span>
                        </span>
                    </label>
                ))}
            </div>
        </div>
    );
}

// ── Danger Zone Tab ───────────────────────────────────────────────────────────

function DangerZoneTab() {
    const toast = useToastStore();
    const qc = useQueryClient();
    const user = useAuthStore((s) => s.user);
    const isSuperAdmin = (user?.roles?.map((r) => r.name) ?? []).includes("super_admin");

    const [modalOpen, setModalOpen] = useState(false);
    const [phrase, setPhrase] = useState("");
    const [password, setPassword] = useState("");

    const wipeMutation = useMutation({
        mutationFn: () => databaseApi.wipeAllData(phrase, password),
        onSuccess: (res) => {
            toast.success(res.message);
            setModalOpen(false);
            setPhrase("");
            setPassword("");
            qc.invalidateQueries();
        },
        onError: (err: any) => toast.error(err?.message ?? "Data wipe failed."),
    });

    return (
        <div className="max-w-xl space-y-5">
            <div className="rounded-xl border border-danger/30 bg-danger-light/40 p-5">
                <h3 className="text-base font-bold text-danger flex items-center gap-2">
                    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    Full Data Wipe (Factory Reset)
                </h3>
                <p className="text-sm text-surface-700 mt-2">
                    Permanently deletes every product, order, customer, inventory record, production order, expense,
                    and all other business data. Your account, other staff accounts, roles, permissions, and system
                    settings are preserved so you can keep using the app immediately afterward — this clears the data,
                    not the configuration.
                </p>
                <p className="text-sm text-surface-700 mt-2">
                    A full backup is taken automatically immediately before the wipe and cannot be skipped. Restoring
                    from it afterward will bring everything back exactly as it was.
                </p>

                {!isSuperAdmin ? (
                    <p className="text-sm font-medium text-danger mt-4">Only a Super Admin can perform a full data wipe.</p>
                ) : (
                    <button
                        onClick={() => setModalOpen(true)}
                        className="btn-danger btn-sm mt-4"
                    >
                        Wipe All Data…
                    </button>
                )}
            </div>

            <Modal
                open={modalOpen}
                onClose={() => { setModalOpen(false); setPhrase(""); setPassword(""); }}
                title="Confirm Full Data Wipe"
                size="md"
                footer={
                    <>
                        <button onClick={() => setModalOpen(false)} className="btn-secondary btn-sm" disabled={wipeMutation.isPending}>Cancel</button>
                        <button
                            onClick={() => wipeMutation.mutate()}
                            disabled={phrase !== "DELETE ALL DATA" || !password || wipeMutation.isPending}
                            className="btn-danger btn-sm"
                        >
                            {wipeMutation.isPending ? <Spinner size="xs" className="border-white/30 border-t-white" /> : "Wipe All Data"}
                        </button>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="rounded-lg bg-danger-light border border-danger/30 px-3 py-2.5 text-sm text-surface-700">
                        <p className="font-semibold text-danger">This action is irreversible without a restore.</p>
                        <p className="mt-1">All business data will be deleted immediately. A safety backup will be created first, but the live application will be empty the moment this completes.</p>
                    </div>

                    <div>
                        <label className="text-xs font-medium text-surface-600 mb-1 block">
                            Type <span className="font-mono bg-surface-100 px-1 rounded">DELETE ALL DATA</span> to confirm
                        </label>
                        <input
                            type="text"
                            value={phrase}
                            onChange={(e) => setPhrase(e.target.value)}
                            placeholder="DELETE ALL DATA"
                            className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-danger/30 focus:border-danger"
                        />
                    </div>

                    <div>
                        <label className="text-xs font-medium text-surface-600 mb-1 block">Confirm your password</label>
                        <input
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="w-full rounded-lg border border-surface-200 px-3 py-2 text-sm"
                            autoComplete="current-password"
                        />
                    </div>
                </div>
            </Modal>
        </div>
    );
}