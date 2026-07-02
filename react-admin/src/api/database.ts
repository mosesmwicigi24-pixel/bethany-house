import { get, post, put, del } from "@/api/client";

// ─── Types ────────────────────────────────────────────────────────────────────

export interface DatabaseStats {
    database_size_bytes: number;
    database_size_human: string;
    largest_tables: {
        table: string;
        row_estimate: number;
        size_bytes: number;
        size_human: string;
    }[];
    pg_dump_available: boolean;
}

export type ClearableTableGroup = "ledger" | "business";

export interface ClearableTable {
    key: string;
    label: string;
    group: ClearableTableGroup;
    children: string[];
    row_count: number;
}

export interface ClearPreviewResponse {
    preview: Record<string, number>;
    total_rows: number;
    before_date: string;
}

export interface ClearResponse {
    message: string;
    deleted_counts: Record<string, number>;
    total_deleted: number;
    safety_backup: { id: number; filename: string } | null;
}

export type BackupType = "manual" | "scheduled" | "pre_clear" | "pre_wipe";
export type BackupStatus = "pending" | "running" | "success" | "failed";

export interface DatabaseBackup {
    id: number;
    type: BackupType;
    status: BackupStatus;
    filename: string;
    disk: "local" | "s3";
    path: string;
    size_bytes: number | null;
    checksum_sha256: string | null;
    db_driver: string | null;
    triggered_by: "user" | "schedule" | "system";
    created_by: number | null;
    created_by_name: string | null;
    error_message: string | null;
    duration_seconds: number | null;
    created_at: string;
    updated_at: string;
}

export interface BackupSchedule {
    id: number;
    is_enabled: boolean;
    frequency: "daily" | "weekly" | "monthly";
    run_at: string; // "HH:mm:ss"
    day_of_week: number | null;
    day_of_month: number | null;
    retain_count: number;
    disk: "local" | "s3";
    last_run_at: string | null;
    last_run_status: "success" | "failed" | null;
    last_run_error: string | null;
}

export interface BackupScheduleFormData {
    is_enabled: boolean;
    frequency: "daily" | "weekly" | "monthly";
    run_at: string; // "HH:mm"
    day_of_week?: number | null;
    day_of_month?: number | null;
    retain_count: number;
    disk: "local" | "s3";
}

export interface BackupStorageSettings {
    backup_storage_disk: "local" | "s3";
    backup_local_retain_count: string;
    backup_s3_bucket: string;
    backup_s3_region: string;
    backup_s3_endpoint: string;
    backup_s3_key: string;
    backup_s3_secret: string; // masked when read from the server
    backup_s3_prefix: string;
    backup_s3_use_path_style: string;
    backup_notify_email: string;
}

interface PaginatedBackups {
    data: DatabaseBackup[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

// ─── API ──────────────────────────────────────────────────────────────────────

export const databaseApi = {
    stats: () => get<DatabaseStats>("/v1/admin/database/stats"),

    // Transaction clear-by-date
    clearableTables: () =>
        get<{ data: ClearableTable[] }>("/v1/admin/database/clearable-tables"),

    clearPreview: (tables: string[], beforeDate: string) =>
        post<ClearPreviewResponse>("/v1/admin/database/clear-preview", {
            tables,
            before_date: beforeDate,
        }),

    clear: (params: {
        tables: string[];
        before_date: string;
        confirm: true;
        skip_auto_backup?: boolean;
    }) => post<ClearResponse>("/v1/admin/database/clear", params),

    // Backups
    backups: (params?: { page?: number; type?: BackupType; status?: BackupStatus }) =>
        get<PaginatedBackups>("/v1/admin/database/backups", { params }),

    createBackup: (disk?: "local" | "s3") =>
        post<{ message: string; backup: DatabaseBackup }>(
            "/v1/admin/database/backups",
            { disk },
        ),

    downloadBackupUrl: (id: number) =>
        `${import.meta.env.VITE_API_URL ?? "/api"}/v1/admin/database/backups/${id}/download`,

    restoreBackup: (id: number, confirmPhrase: string, takeSafetyBackup = true) =>
        post<{ message: string; result: { backup_id: number; duration_seconds: number; warnings: string | null } }>(
            `/v1/admin/database/backups/${id}/restore`,
            { confirm_phrase: confirmPhrase, take_safety_backup: takeSafetyBackup },
        ),

    deleteBackup: (id: number) =>
        del<{ message: string }>(`/v1/admin/database/backups/${id}`),

    // Schedule
    getSchedule: () => get<{ schedule: BackupSchedule | null }>("/v1/admin/database/schedule"),

    updateSchedule: (data: BackupScheduleFormData) =>
        put<{ message: string; schedule: BackupSchedule }>(
            "/v1/admin/database/schedule",
            data,
        ),

    // Storage destination settings
    getStorageSettings: () =>
        get<{ settings: BackupStorageSettings }>("/v1/admin/database/storage-settings"),

    updateStorageSettings: (data: Partial<BackupStorageSettings>) =>
        put<{ message: string }>("/v1/admin/database/storage-settings", data),

    testStorageSettings: (disk: "local" | "s3") =>
        post<{ message: string; ok: boolean }>("/v1/admin/database/storage-settings/test", { disk }),

    // Full wipe
    wipeAllData: (confirmPhrase: string, password: string) =>
        post<{ message: string; truncated_tables: string[]; safety_backup: { id: number; filename: string } }>(
            "/v1/admin/database/wipe",
            { confirm_phrase: confirmPhrase, password },
        ),
};
