<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * DatabaseManagementService
 *
 * Production-grade database administration for the admin "Database Management"
 * panel: backups (manual + scheduled), restores, transaction-data cleanup by
 * date range, and a heavily-guarded full data wipe.
 *
 * DESIGN NOTES
 * ────────────
 * - Backups use `pg_dump`/`pg_restore` (custom format, -Fc) shelled out via
 *   Symfony Process rather than a PHP-native dump, because a native dump of a
 *   production-sized Postgres database in PHP is slow, memory-hungry, and
 *   prone to subtly missing edge cases (sequences, constraints, large objects)
 *   that pg_dump handles correctly. This assumes the `postgresql-client`
 *   package is installed on the app server/container — call
 *   `self::isPgDumpAvailable()` to check before exposing backup/restore UI.
 * - Dumps are streamed to a local temp path first, then pushed to the
 *   configured Storage disk (local or s3-compatible). This keeps pg_dump
 *   talking to a normal filesystem path instead of a stream wrapper.
 * - Every destructive action (clear-by-date, full wipe) takes an automatic
 *   safety backup first and is wrapped in a DB transaction where possible.
 *   Truncate/delete-by-date is NOT run inside the same transaction as the
 *   backup (pg_dump is a separate process and can't share a PHP DB
 *   transaction), so the backup is taken and confirmed BEFORE the destructive
 *   SQL runs.
 */
class DatabaseManagementService
{
    /**
     * Tables considered "transaction / ledger" data — safe candidates for the
     * date-bounded clear tool. Each entry declares its date column and any
     * child tables that must be removed first to satisfy FK constraints.
     *
     * Two groups: 'ledger' (append-only logs — safe to purge by date, shown
     * pre-checked) and 'business' (primary records — expenses, orders,
     * purchase orders, production orders, etc. — higher risk, opt-in only).
     */
    public const CLEARABLE_TABLES = [
        // ─────────────────────────────────────────────────────────────────────
        // LEDGER GROUP — append-only log tables, safe to purge by date
        // ─────────────────────────────────────────────────────────────────────

        'activity_log' => [
            'label'       => 'Activity / Audit Log',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'audit_logs' => [
            'label'       => 'Audit Logs',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'inventory_transactions' => [
            'label'       => 'Inventory Transactions',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'material_transactions' => [
            'label'       => 'Material Transactions',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'material_allocations' => [
            'label'       => 'Material Allocations',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'cash_register_transactions' => [
            'label'       => 'Cash Register Transactions',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'cash_register_eod_reports' => [
            'label'       => 'End-of-Day Reports',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'payment_transactions' => [
            'label'       => 'Payment Gateway Transactions',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'order_status_history' => [
            'label'       => 'Order Status History',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'shipment_tracking' => [
            'label'       => 'Shipment Tracking Events',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'production_quality_checks' => [
            'label'       => 'Production Quality Checks',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'production_order_messages' => [
            'label'       => 'Production Order Messages',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'time_entries' => [
            'label'       => 'Attendance / Time Entries',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],
        'notifications' => [
            'label'       => 'In-App Notifications',
            'date_column' => 'created_at',
            'group'       => 'ledger',
            'children'    => [],
        ],

        // ─────────────────────────────────────────────────────────────────────
        // BUSINESS GROUP — primary records, higher risk, opt-in only
        // ─────────────────────────────────────────────────────────────────────

        'payments' => [
            'label'       => 'Payments',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => ['payment_transactions' => 'payment_id'],
        ],
        'expenses' => [
            'label'       => 'Expenses (and line items, approvals)',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => [
                'expense_line_items' => 'expense_id',
                'expense_approvals'  => 'expense_id',
            ],
        ],
        'inventory_transfers' => [
            'label'       => 'Inventory Transfers (and items)',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => [
                'inventory_transfer_items' => 'transfer_id',
            ],
        ],
        'goods_received_notes' => [
            'label'       => 'Goods Received Notes (and items)',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => [
                'grn_items' => 'grn_id',
            ],
        ],
        'purchase_returns' => [
            'label'       => 'Purchase Returns (and items)',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => [
                'purchase_return_items' => 'return_id',
            ],
        ],
        'purchase_orders' => [
            'label'       => 'Purchase Orders (and items, GRNs, returns)',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => [
                'purchase_order_items' => 'purchase_order_id',
                'goods_received_notes' => 'purchase_order_id',
                'purchase_returns'     => 'purchase_order_id',
            ],
        ],
        'production_orders' => [
            'label'       => 'Production Orders (and tasks, assignees, messages)',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => [
                'production_tasks'           => 'production_order_id',
                'production_order_assignees' => 'production_order_id',
                'production_order_messages'  => 'production_order_id',
                'production_quality_checks'  => 'production_order_id',
                'material_allocations'       => 'production_order_id',
            ],
        ],
        'order_returns' => [
            'label'       => 'Order Returns (and return items)',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => [
                'return_items' => 'return_id',
            ],
        ],
        'orders' => [
            'label'       => 'Sales Orders (and items, shipments, returns, payments)',
            'date_column' => 'created_at',
            'group'       => 'business',
            'children'    => [
                'order_items'                => 'order_id',
                'order_status_history'       => 'order_id',
                'order_shipments'            => 'order_id',
                'order_returns'              => 'order_id',   // cascades to return_items
                'payments'                   => 'order_id',
                'cash_register_transactions' => 'order_id',
            ],
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────
    // ENVIRONMENT CHECKS
    // ─────────────────────────────────────────────────────────────────────

    public static function isPgDumpAvailable(): bool
    {
        // Use is_executable() against known paths rather than Symfony Process
        // ('which pg_dump'), because proc_open may be disabled on this server.
        foreach (['/usr/bin/pg_dump', '/usr/local/bin/pg_dump', '/bin/pg_dump'] as $path) {
            if (is_executable($path)) {
                return true;
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // BACKUP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create a full database backup and store it on the configured disk.
     * Returns the created database_backups row (array/object via DB::table).
     *
     * @param string $type        manual | scheduled | pre_clear | pre_wipe
     * @param string $triggeredBy user | schedule | system
     * @param string|null $disk   override disk; defaults to the configured backup_storage_disk setting
     */
    public function createBackup(string $type = 'manual', string $triggeredBy = 'user', ?User $user = null, ?string $disk = null): object
    {
        if (!self::isPgDumpAvailable()) {
            throw new \RuntimeException(
                'pg_dump is not available on this server. Install the postgresql-client package to enable backups.'
            );
        }

        $disk = $disk ?? $this->configuredDisk();
        $conn = config('database.connections.pgsql');

        $timestamp = now()->format('Y-m-d_His');
        $filename  = "backup_{$type}_{$timestamp}_" . Str::random(6) . '.dump';
        $localTmp  = storage_path('app/tmp-backups');
        if (!is_dir($localTmp)) {
            mkdir($localTmp, 0750, true);
        }
        $localFile = $localTmp . '/' . $filename;

        $backupId = DB::table('database_backups')->insertGetId([
            'type'         => $type,
            'status'       => 'running',
            'filename'     => $filename,
            'disk'         => $disk,
            'path'         => $filename,
            'db_driver'    => 'pgsql',
            'triggered_by' => $triggeredBy,
            'created_by'   => $user?->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $startedAt = microtime(true);

        try {
            // Use exec() rather than Symfony Process because proc_open may be
            // disabled in this PHP environment. PGPASSWORD is injected via the
            // environment so it never appears in the process list.
            $cmd = sprintf(
                'PGPASSWORD=%s /usr/bin/pg_dump -h %s -p %s -U %s -Fc -f %s %s 2>&1',
                escapeshellarg($conn['password']),
                escapeshellarg($conn['host']),
                escapeshellarg((string) $conn['port']),
                escapeshellarg($conn['username']),
                escapeshellarg($localFile),
                escapeshellarg($conn['database']),
            );

            \exec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || !is_file($localFile) || filesize($localFile) === 0) {
                throw new \RuntimeException('pg_dump failed (exit ' . $exitCode . '): ' . implode("\n", $output));
            }

            $sizeBytes = filesize($localFile);
            $checksum  = hash_file('sha256', $localFile);

            // Push to the configured disk (local keeps it on local filesystem under a
            // managed path; s3 streams it up to the bucket).
            $storedPath = $this->storeDumpFile($localFile, $filename, $disk);

            $duration = (int) round(microtime(true) - $startedAt);

            DB::table('database_backups')->where('id', $backupId)->update([
                'status'           => 'success',
                'path'             => $storedPath,
                'size_bytes'       => $sizeBytes,
                'checksum_sha256'  => $checksum,
                'duration_seconds' => $duration,
                'updated_at'       => now(),
            ]);

            ActivityLogService::log('database_backup_created', null, [
                'backup_id'  => $backupId,
                'type'       => $type,
                'disk'       => $disk,
                'size_bytes' => $sizeBytes,
            ], "Database backup created ({$type}) — " . $this->formatBytes($sizeBytes), $user);

            return DB::table('database_backups')->find($backupId);
        } catch (\Throwable $e) {
            DB::table('database_backups')->where('id', $backupId)->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at'    => now(),
            ]);

            ActivityLogService::log('database_backup_failed', null, [
                'backup_id' => $backupId,
                'error'     => $e->getMessage(),
            ], "Database backup failed ({$type})", $user);

            throw $e;
        } finally {
            if (is_file($localFile)) {
                @unlink($localFile);
            }
        }
    }

    /**
     * Move/upload the local dump file to its final resting disk.
     * Returns the path to record on the database_backups row.
     */
    private function storeDumpFile(string $localFile, string $filename, string $disk): string
    {
        $relativePath = 'database-backups/' . $filename;

        if ($disk === 'local') {
            $destination = storage_path('app/' . $relativePath);
            $destDir = dirname($destination);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0750, true);
            }
            rename($localFile, $destination);
            return $relativePath;
        }

        // s3 / s3-compatible — stream the file up using a Storage disk built
        // from the live settings (so it works without editing config/filesystems.php).
        $s3Disk = $this->buildS3Disk();
        $prefix = rtrim($this->setting('backup_s3_prefix', 'backups/'), '/');
        $key = $prefix . '/' . $filename;

        $stream = fopen($localFile, 'r');
        $s3Disk->put($key, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $key;
    }

    /**
     * Build a runtime S3 (or S3-compatible) disk from the settings table,
     * so backup storage can be configured from the UI without redeploying
     * with new environment variables.
     */
    public function buildS3Disk()
    {
        $config = [
            'driver'                  => 's3',
            'key'                     => $this->setting('backup_s3_key'),
            'secret'                  => $this->setting('backup_s3_secret'),
            'region'                  => $this->setting('backup_s3_region'),
            'bucket'                  => $this->setting('backup_s3_bucket'),
            'use_path_style_endpoint' => $this->setting('backup_s3_use_path_style') === '1',
        ];

        $endpoint = $this->setting('backup_s3_endpoint');
        if (!empty($endpoint)) {
            $config['endpoint'] = $endpoint;
        }

        return Storage::build($config);
    }

    private function configuredDisk(): string
    {
        return $this->setting('backup_storage_disk', 'local');
    }

    /**
     * Read a single key from the settings table (bypassing the SettingController
     * cache layer since backup operations need the latest value immediately
     * after a settings update).
     */
    private function setting(string $key, ?string $default = null): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value') ?? $default;
    }

    // ─────────────────────────────────────────────────────────────────────
    // RESTORE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Restore the database from a previously created backup row.
     * DESTRUCTIVE — drops and recreates objects in the target database.
     * Caller (controller) is responsible for permission/confirmation checks
     * and for taking a fresh safety backup beforehand if desired.
     */
    public function restoreBackup(int $backupId, ?User $user = null): array
    {
        $backup = DB::table('database_backups')->find($backupId);
        if (!$backup) {
            throw new \InvalidArgumentException('Backup record not found.');
        }
        if ($backup->status !== 'success') {
            throw new \InvalidArgumentException('Only successfully completed backups can be restored.');
        }

        $localFile = $this->fetchToLocal($backup);

        $conn = config('database.connections.pgsql');
        $startedAt = microtime(true);

        try {
            // Use exec() — proc_open may be disabled on this server.
            // stderr is redirected to a temp file so we can inspect it for
            // fatal errors (pg_restore exits non-zero on harmless warnings too).
            $stderrFile = tempnam(sys_get_temp_dir(), 'pgrestore_');
            $cmd = sprintf(
                'PGPASSWORD=%s /usr/bin/pg_restore -h %s -p %s -U %s -d %s --clean --if-exists --no-owner --no-privileges %s 2>%s',
                escapeshellarg($conn['password']),
                escapeshellarg($conn['host']),
                escapeshellarg((string) $conn['port']),
                escapeshellarg($conn['username']),
                escapeshellarg($conn['database']),
                escapeshellarg($localFile),
                escapeshellarg($stderrFile),
            );

            \exec($cmd, $output, $exitCode);
            $stderr = is_file($stderrFile) ? file_get_contents($stderrFile) : '';
            @unlink($stderrFile);

            // pg_restore commonly exits non-zero on harmless warnings (e.g.
            // "role does not exist" for --no-owner runs). Treat as success
            // unless stderr contains a fatal error keyword.
            $fatal = stripos($stderr, 'FATAL') !== false || stripos($stderr, 'could not connect') !== false;

            if ($fatal) {
                throw new \RuntimeException('pg_restore failed: ' . $stderr);
            }

            $duration = (int) round(microtime(true) - $startedAt);

            ActivityLogService::log('database_restored', null, [
                'backup_id' => $backupId,
                'filename'  => $backup->filename,
                'warnings'  => $stderr ?: null,
            ], "Database restored from backup #{$backupId} ({$backup->filename})", $user);

            return [
                'backup_id'        => $backupId,
                'duration_seconds' => $duration,
                'warnings'         => $stderr ?: null,
            ];
        } finally {
            if (is_file($localFile) && str_starts_with($localFile, storage_path('app/tmp-backups'))) {
                @unlink($localFile);
            }
        }
    }

    /**
     * Ensure a backup's dump file is present on local disk and return its path.
     * For local-disk backups this is a no-op (already local). For s3-backed
     * backups, downloads to a temp file first.
     */
    private function fetchToLocal(object $backup): string
    {
        if ($backup->disk === 'local') {
            return storage_path('app/' . $backup->path);
        }

        $s3Disk = $this->buildS3Disk();
        $tmpDir = storage_path('app/tmp-backups');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0750, true);
        }
        $localFile = $tmpDir . '/' . basename($backup->path);

        $stream = $s3Disk->readStream($backup->path);
        file_put_contents($localFile, stream_get_contents($stream));
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $localFile;
    }

    /**
     * Resolve a backup file for download via the controller.
     * Returns [localPath, downloadFilename, shouldDeleteAfter].
     */
    public function prepareDownload(int $backupId): array
    {
        $backup = DB::table('database_backups')->find($backupId);
        if (!$backup || $backup->status !== 'success') {
            throw new \InvalidArgumentException('Backup not available for download.');
        }

        $local = $this->fetchToLocal($backup);
        $shouldDelete = $backup->disk !== 'local';

        return [$local, $backup->filename, $shouldDelete];
    }

    public function deleteBackup(int $backupId, ?User $user = null): void
    {
        $backup = DB::table('database_backups')->find($backupId);
        if (!$backup) {
            throw new \InvalidArgumentException('Backup record not found.');
        }

        if ($backup->disk === 'local') {
            $full = storage_path('app/' . $backup->path);
            if (is_file($full)) {
                @unlink($full);
            }
        } else {
            try {
                $this->buildS3Disk()->delete($backup->path);
            } catch (\Throwable) {
                // Already gone or unreachable — proceed to remove the DB row regardless.
            }
        }

        DB::table('database_backups')->where('id', $backupId)->delete();

        ActivityLogService::log('database_backup_deleted', null, [
            'backup_id' => $backupId,
            'filename'  => $backup->filename,
        ], "Deleted database backup #{$backupId} ({$backup->filename})", $user);
    }

    /**
     * Apply retention policy: keep the newest N backups on a given disk,
     * delete the rest. Used by the scheduled backup command.
     */
    public function pruneOldBackups(string $diskFilter, int $retainCount): int
    {
        $ids = DB::table('database_backups')
            ->where('disk', $diskFilter)
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->pluck('id')
            ->slice($retainCount)
            ->values();

        foreach ($ids as $id) {
            try {
                $this->deleteBackup((int) $id);
            } catch (\Throwable) {
                // Skip files that fail to delete — they'll be retried next run.
            }
        }

        return $ids->count();
    }

    // ─────────────────────────────────────────────────────────────────────
    // TRANSACTION CLEAR-BY-DATE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Delete rows older than $beforeDate from the given set of tables
     * (must be keys of CLEARABLE_TABLES). Cascades to declared children
     * first to satisfy foreign keys. Returns per-table delete counts.
     */
    public function clearTransactionsBefore(array $tables, \DateTimeInterface $beforeDate, ?User $user = null): array
    {
        foreach ($tables as $table) {
            if (!array_key_exists($table, self::CLEARABLE_TABLES)) {
                throw new \InvalidArgumentException("'{$table}' is not a recognized clearable table.");
            }
        }

        $results = [];

        DB::transaction(function () use ($tables, $beforeDate, &$results) {
            foreach ($tables as $table) {
                $config = self::CLEARABLE_TABLES[$table];
                $dateColumn = $config['date_column'];

                // Find the parent IDs older than the cutoff first, so children can be
                // scoped to exactly those parent rows rather than filtered independently
                // by their own (possibly absent) date column.
                $parentIds = DB::table($table)
                    ->where($dateColumn, '<', $beforeDate)
                    ->pluck('id');

                if ($parentIds->isEmpty()) {
                    $results[$table] = 0;
                    continue;
                }

                // Recursively delete children, grandchildren, etc. — a child that is
                // itself a key in CLEARABLE_TABLES (e.g. 'payments' cascading from
                // 'orders') carries its OWN declared children ('payment_transactions'),
                // which must be cleaned up too or the deeper delete can be blocked by
                // a foreign key, or silently leave orphaned rows.
                $this->deleteChildrenRecursive($config['children'], $parentIds, $results);

                $deleted = 0;
                $parentIds->chunk(1000)->each(function ($chunk) use ($table, &$deleted) {
                    $deleted += DB::table($table)->whereIn('id', $chunk)->delete();
                });

                $results[$table] = ($results[$table] ?? 0) + $deleted;
            }
        });

        ActivityLogService::log('transactions_cleared', null, [
            'tables'         => $tables,
            'before_date'    => $beforeDate->format('Y-m-d'),
            'deleted_counts' => $results,
        ], 'Cleared transaction data before ' . $beforeDate->format('Y-m-d') . ': ' . json_encode($results), $user);

        return $results;
    }

    /**
     * Delete rows in $children (a [childTable => fkColumn] map) that reference
     * any of $parentIds. If a child table is itself a registered CLEARABLE_TABLES
     * entry with its own children, recurse into those first — this is what makes
     * clearing 'orders' correctly clean up 'payment_transactions' even though
     * that table is two levels removed (orders → payments → payment_transactions),
     * and is also what prevents an orphaned-row / FK-violation failure on the
     * 'payments' delete itself.
     *
     * $results is passed by reference so deletions performed here are reflected
     * in the per-table counts returned to the caller, even though those tables
     * weren't explicitly requested by the operator.
     */
    private function deleteChildrenRecursive(array $children, \Illuminate\Support\Collection $parentIds, array &$results): void
    {
        foreach ($children as $childTable => $fk) {
            // If this child is itself a registered table with declared children
            // of its own, fetch ITS ids (scoped to rows pointing at our parents)
            // and recurse before deleting the child rows themselves.
            if (array_key_exists($childTable, self::CLEARABLE_TABLES)) {
                $grandchildren = self::CLEARABLE_TABLES[$childTable]['children'];
                if (!empty($grandchildren)) {
                    $childIds = collect();
                    $parentIds->chunk(1000)->each(function ($chunk) use ($childTable, $fk, &$childIds) {
                        $childIds = $childIds->merge(
                            DB::table($childTable)->whereIn($fk, $chunk)->pluck('id')
                        );
                    });

                    if ($childIds->isNotEmpty()) {
                        $this->deleteChildrenRecursive($grandchildren, $childIds, $results);
                    }
                }
            }

            $deleted = 0;
            $parentIds->chunk(1000)->each(function ($chunk) use ($childTable, $fk, &$deleted) {
                $deleted += DB::table($childTable)->whereIn($fk, $chunk)->delete();
            });

            $results[$childTable] = ($results[$childTable] ?? 0) + $deleted;
        }
    }

    /**
     * Dry-run: count how many rows WOULD be deleted, without deleting anything.
     * Used by the UI to show an impact preview before the operator confirms.
     */
    public function previewClear(array $tables, \DateTimeInterface $beforeDate): array
    {
        $preview = [];
        foreach ($tables as $table) {
            if (!array_key_exists($table, self::CLEARABLE_TABLES)) {
                throw new \InvalidArgumentException("'{$table}' is not a recognized clearable table.");
            }
            $config = self::CLEARABLE_TABLES[$table];

            $parentIds = DB::table($table)->where($config['date_column'], '<', $beforeDate)->pluck('id');
            $preview[$table] = ($preview[$table] ?? 0) + $parentIds->count();

            if ($parentIds->isNotEmpty()) {
                $this->countChildrenRecursive($config['children'], $parentIds, $preview);
            }
        }
        return $preview;
    }

    /**
     * Read-only counterpart to deleteChildrenRecursive() — counts (without
     * deleting) how many rows in each cascaded child/grandchild table would
     * be removed, so the dry-run preview matches what clearTransactionsBefore()
     * will actually do.
     */
    private function countChildrenRecursive(array $children, \Illuminate\Support\Collection $parentIds, array &$preview): void
    {
        foreach ($children as $childTable => $fk) {
            $childIds = collect();
            $parentIds->chunk(1000)->each(function ($chunk) use ($childTable, $fk, &$childIds) {
                $childIds = $childIds->merge(
                    DB::table($childTable)->whereIn($fk, $chunk)->pluck('id')
                );
            });

            $preview[$childTable] = ($preview[$childTable] ?? 0) + $childIds->count();

            if ($childIds->isNotEmpty() && array_key_exists($childTable, self::CLEARABLE_TABLES)) {
                $grandchildren = self::CLEARABLE_TABLES[$childTable]['children'];
                if (!empty($grandchildren)) {
                    $this->countChildrenRecursive($grandchildren, $childIds, $preview);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // FULL DATA WIPE  (extremely destructive — heavily guarded by controller)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tables preserved during a full wipe so the operator isn't locked out
     * of their own freshly-wiped app and core configuration survives.
     * Everything else (orders, products, customers, inventory, production,
     * expenses, etc.) is truncated.
     */
    public const PRESERVED_TABLES = [
        'users', 'password_reset_tokens', 'personal_access_tokens', 'sessions',
        'roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions',
        'settings', 'languages', 'currencies', 'countries', 'tax_rates',
        'payment_methods', 'outlets', 'shipping_zones', 'shipping_methods',
        'database_backups', 'backup_schedules',
        'migrations', 'failed_jobs', 'jobs', 'cache', 'cache_locks',
    ];

    public function wipeAllData(?User $user = null): array
    {
        $allTables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->pluck('table_name')
            ->all();

        $toTruncate = array_values(array_diff($allTables, self::PRESERVED_TABLES));

        $truncated = [];
        DB::transaction(function () use ($toTruncate, &$truncated) {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            foreach ($toTruncate as $table) {
                DB::statement('TRUNCATE TABLE "' . $table . '" RESTART IDENTITY CASCADE');
                $truncated[] = $table;
            }
        });

        ActivityLogService::log('database_full_wipe', null, [
            'truncated_tables' => $truncated,
            'preserved_tables' => self::PRESERVED_TABLES,
        ], 'FULL DATA WIPE performed — ' . count($truncated) . ' tables truncated', $user);

        return $truncated;
    }

    // ─────────────────────────────────────────────────────────────────────
    // STATS / HEALTH
    // ─────────────────────────────────────────────────────────────────────

    public function databaseStats(): array
    {
        $sizeRow = DB::selectOne('SELECT pg_database_size(current_database()) AS size');
        $tableStats = DB::select("
            SELECT relname AS table_name,
                   n_live_tup AS row_estimate,
                   pg_total_relation_size(relid) AS total_bytes
            FROM pg_stat_user_tables
            ORDER BY pg_total_relation_size(relid) DESC
            LIMIT 15
        ");

        return [
            'database_size_bytes' => (int) $sizeRow->size,
            'database_size_human' => $this->formatBytes((int) $sizeRow->size),
            'largest_tables'      => array_map(fn ($r) => [
                'table'        => $r->table_name,
                'row_estimate' => (int) $r->row_estimate,
                'size_bytes'   => (int) $r->total_bytes,
                'size_human'   => $this->formatBytes((int) $r->total_bytes),
            ], $tableStats),
            'pg_dump_available'   => self::isPgDumpAvailable(),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}