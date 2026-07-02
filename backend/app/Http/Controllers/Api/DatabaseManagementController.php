<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DatabaseManagementService;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

/**
 * DatabaseManagementController
 *
 * super_admin only (see routes/api.php). Covers:
 *  - GET    /admin/database/stats                  DB size, largest tables, pg_dump availability
 *  - GET    /admin/database/clearable-tables        registry of tables eligible for date-bounded clear
 *  - POST   /admin/database/clear-preview           dry-run row counts for a set of tables + cutoff date
 *  - POST   /admin/database/clear                   actually delete rows older than cutoff (auto-backs up first)
 *  - GET    /admin/database/backups                 list backup history
 *  - POST   /admin/database/backups                 trigger a manual backup
 *  - GET    /admin/database/backups/{id}/download   stream the dump file
 *  - POST   /admin/database/backups/{id}/restore    restore from a backup (guarded)
 *  - DELETE /admin/database/backups/{id}            delete a backup record + file
 *  - GET    /admin/database/schedule                current scheduled-backup config
 *  - PUT    /admin/database/schedule                update scheduled-backup config
 *  - GET    /admin/database/storage-settings         backup storage destination config (local/s3)
 *  - PUT    /admin/database/storage-settings         update backup storage destination config
 *  - POST   /admin/database/storage-settings/test    verify the configured destination is reachable
 *  - POST   /admin/database/wipe                    full factory-reset wipe (extremely guarded)
 */
class DatabaseManagementController extends Controller
{
    public function __construct(private DatabaseManagementService $service)
    {
    }

    // ─────────────────────────────────────────────────────────────────────
    // STATS
    // ─────────────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        try {
            return response()->json($this->service->databaseStats());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to load database stats.', 'error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // TRANSACTION CLEAR-BY-DATE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/database/clearable-tables
     * Returns the registry of tables the UI can offer as checkboxes, with
     * current row counts so the operator sees scale before picking a date.
     */
    public function clearableTables(): JsonResponse
    {
        $tables = [];
        foreach (DatabaseManagementService::CLEARABLE_TABLES as $key => $config) {
            $tables[] = [
                'key'       => $key,
                'label'     => $config['label'],
                'group'     => $config['group'],
                'children'  => array_keys($config['children']),
                'row_count' => DB::table($key)->count(),
            ];
        }

        return response()->json(['data' => $tables]);
    }

    private function validateClearRequest(Request $request): array
    {
        return $request->validate([
            'tables'      => 'required|array|min:1',
            'tables.*'    => 'string|in:' . implode(',', array_keys(DatabaseManagementService::CLEARABLE_TABLES)),
            'before_date' => 'required|date|before:tomorrow',
        ]);
    }

    /**
     * POST /admin/database/clear-preview
     * Dry run — shows how many rows in each selected table are older than the cutoff.
     */
    public function clearPreview(Request $request): JsonResponse
    {
        $validated = $this->validateClearRequest($request);

        try {
            $preview = $this->service->previewClear(
                $validated['tables'],
                Carbon::parse($validated['before_date'])->endOfDay()
            );

            return response()->json([
                'preview'     => $preview,
                'total_rows'  => array_sum($preview),
                'before_date' => $validated['before_date'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /admin/database/clear
     * Deletes transaction rows older than the cutoff from the selected
     * tables only. Takes an automatic safety backup first by default.
     * Does NOT touch any table outside the explicit `tables` list.
     */
    public function clear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tables'           => 'required|array|min:1',
            'tables.*'         => 'string|in:' . implode(',', array_keys(DatabaseManagementService::CLEARABLE_TABLES)),
            'before_date'      => 'required|date|before:tomorrow',
            'confirm'          => 'required|accepted', // checkbox: "I understand this cannot be undone"
            'skip_auto_backup' => 'sometimes|boolean',
        ]);

        $cutoff     = Carbon::parse($validated['before_date'])->endOfDay();
        $skipBackup = $validated['skip_auto_backup'] ?? false;

        try {
            $backupInfo = null;
            if (!$skipBackup) {
                if (!DatabaseManagementService::isPgDumpAvailable()) {
                    return response()->json([
                        'message' => 'Automatic safety backup could not be created because pg_dump is unavailable on this server. ' .
                                     'Either install postgresql-client, or explicitly opt out of the safety backup to proceed at your own risk.',
                    ], 422);
                }
                $backup     = $this->service->createBackup('pre_clear', 'user', $request->user());
                $backupInfo = ['id' => $backup->id, 'filename' => $backup->filename];
            }

            $results = $this->service->clearTransactionsBefore($validated['tables'], $cutoff, $request->user());

            return response()->json([
                'message'        => 'Transaction data cleared successfully.',
                'deleted_counts' => $results,
                'total_deleted'  => array_sum($results),
                'safety_backup'  => $backupInfo,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to clear transaction data.', 'error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // BACKUPS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/database/backups
     */
    public function backupsIndex(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $query = DB::table('database_backups')
            ->leftJoin('users', 'database_backups.created_by', '=', 'users.id')
            ->select(
                'database_backups.*',
                DB::raw("CONCAT(users.first_name, ' ', users.last_name) as created_by_name")
            )
            ->orderByDesc('database_backups.created_at');

        if ($request->filled('type')) {
            $query->where('database_backups.type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('database_backups.status', $request->status);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * POST /admin/database/backups
     * Trigger a manual backup. Runs synchronously — for very large databases
     * consider dispatching this to a queued job instead (see RunScheduledBackups
     * command for the async pattern) and polling /backups for status.
     */
    public function backupsStore(Request $request): JsonResponse
    {
        $request->validate([
            'disk' => 'sometimes|nullable|string|in:local,s3',
        ]);

        try {
            $backup = $this->service->createBackup('manual', 'user', $request->user(), $request->input('disk'));
            return response()->json(['message' => 'Backup created successfully.', 'backup' => $backup], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Backup failed.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /admin/database/backups/{id}/download
     */
    public function backupsDownload(int $id)
    {
        try {
            [$localPath, $filename, $shouldDelete] = $this->service->prepareDownload($id);

            ActivityLogService::log('database_backup_downloaded', null, [
                'backup_id' => $id,
            ], "Downloaded database backup #{$id}");

            return Response::download($localPath, $filename, [
                'Content-Type' => 'application/octet-stream',
            ])->deleteFileAfterSend($shouldDelete);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to prepare backup download.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/database/backups/{id}/restore
     * Extremely destructive — overwrites the live database. Requires the same
     * confirm-phrase guard as the full wipe.
     */
    public function backupsRestore(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'confirm_phrase'      => 'required|string',
            'take_safety_backup'  => 'sometimes|boolean',
        ]);

        if ($request->input('confirm_phrase') !== 'RESTORE DATABASE') {
            return response()->json(['message' => 'Confirmation phrase did not match. Type RESTORE DATABASE exactly to proceed.'], 422);
        }

        try {
            if ($request->boolean('take_safety_backup', true) && DatabaseManagementService::isPgDumpAvailable()) {
                $this->service->createBackup('manual', 'user', $request->user());
            }

            $result = $this->service->restoreBackup($id, $request->user());

            return response()->json([
                'message' => 'Database restored successfully.',
                'result'  => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Restore failed.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /admin/database/backups/{id}
     */
    public function backupsDestroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->service->deleteBackup($id, $request->user());
            return response()->json(['message' => 'Backup deleted.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to delete backup.', 'error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // SCHEDULE CONFIG
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/database/schedule
     */
    public function scheduleShow(): JsonResponse
    {
        return response()->json(['schedule' => DB::table('backup_schedules')->first()]);
    }

    /**
     * PUT /admin/database/schedule
     */
    public function scheduleUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled'   => 'required|boolean',
            'frequency'    => 'required|in:daily,weekly,monthly',
            'run_at'       => 'required|date_format:H:i',
            'day_of_week'  => 'nullable|integer|min:0|max:6|required_if:frequency,weekly',
            'day_of_month' => 'nullable|integer|min:1|max:28|required_if:frequency,monthly',
            'retain_count' => 'required|integer|min:1|max:365',
            'disk'         => 'required|in:local,s3',
        ]);

        $existing = DB::table('backup_schedules')->first();

        if ($existing) {
            DB::table('backup_schedules')->where('id', $existing->id)->update(array_merge($validated, [
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]));
        } else {
            DB::table('backup_schedules')->insert(array_merge($validated, [
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        ActivityLogService::log('backup_schedule_updated', null, $validated, 'Backup schedule configuration updated', $request->user());

        return response()->json([
            'message'  => 'Backup schedule saved.',
            'schedule' => DB::table('backup_schedules')->first(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // STORAGE DESTINATION SETTINGS
    // ─────────────────────────────────────────────────────────────────────

    private const STORAGE_SETTING_KEYS = [
        'backup_storage_disk', 'backup_local_retain_count', 'backup_s3_bucket',
        'backup_s3_region', 'backup_s3_endpoint', 'backup_s3_key', 'backup_s3_secret',
        'backup_s3_prefix', 'backup_s3_use_path_style', 'backup_notify_email',
    ];

    /**
     * GET /admin/database/storage-settings
     * The secret is returned masked — the UI never round-trips the real
     * secret value back through a GET; it's only ever written via PUT.
     */
    public function storageSettingsShow(): JsonResponse
    {
        $rows = DB::table('settings')
            ->whereIn('key', self::STORAGE_SETTING_KEYS)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->key => $r->value]);

        $settings = [];
        foreach (self::STORAGE_SETTING_KEYS as $key) {
            $settings[$key] = $rows[$key] ?? '';
        }

        if (!empty($settings['backup_s3_secret'])) {
            $settings['backup_s3_secret'] = '••••••••' . substr($settings['backup_s3_secret'], -4);
        }

        return response()->json(['settings' => $settings]);
    }

    /**
     * PUT /admin/database/storage-settings
     */
    public function storageSettingsUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'backup_storage_disk'       => 'required|in:local,s3',
            'backup_local_retain_count' => 'required|integer|min:1|max:365',
            'backup_s3_bucket'          => 'required_if:backup_storage_disk,s3|nullable|string|max:255',
            'backup_s3_region'          => 'nullable|string|max:50',
            'backup_s3_endpoint'        => 'nullable|string|max:255',
            'backup_s3_key'             => 'nullable|string|max:255',
            'backup_s3_secret'          => 'nullable|string|max:255',
            'backup_s3_prefix'          => 'nullable|string|max:255',
            'backup_s3_use_path_style'  => 'sometimes|boolean',
            'backup_notify_email'       => 'nullable|email',
        ]);

        // Don't overwrite the stored secret with the masked placeholder if the
        // operator didn't actually change it.
        if (isset($validated['backup_s3_secret']) && str_starts_with($validated['backup_s3_secret'], '••••••••')) {
            unset($validated['backup_s3_secret']);
        }

        if (array_key_exists('backup_s3_use_path_style', $validated)) {
            $validated['backup_s3_use_path_style'] = $validated['backup_s3_use_path_style'] ? '1' : '0';
        }

        $now = now();
        DB::transaction(function () use ($validated, $now) {
            foreach ($validated as $key => $value) {
                // NOTE: do not use updateOrInsert() with COALESCE(created_at, ...)
                // here. On Postgres, the INSERT branch fails with "column
                // created_at does not exist" because COALESCE can't reference
                // a column that isn't part of the row being inserted yet —
                // that trick only works inside an UPDATE, where the row (and
                // column) already exist. Check existence explicitly instead
                // so created_at is set once on insert and left untouched on
                // every subsequent update.
                $exists = DB::table('settings')->where('key', $key)->exists();

                if ($exists) {
                    DB::table('settings')->where('key', $key)->update([
                        'value'      => (string) $value,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('settings')->insert([
                        'key'        => $key,
                        'value'      => (string) $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });

        ActivityLogService::log('backup_storage_settings_updated', null, [
            'changed_keys'   => array_keys(array_diff_key($validated, ['backup_s3_secret' => true])),
            'secret_changed' => array_key_exists('backup_s3_secret', $validated),
        ], 'Backup storage destination settings updated', $request->user());

        return response()->json(['message' => 'Backup storage settings saved.']);
    }

    /**
     * POST /admin/database/storage-settings/test
     * Verify the configured S3 destination is reachable before relying on it.
     */
    public function storageSettingsTest(Request $request): JsonResponse
    {
        $disk = $request->input('disk', 'local');

        if ($disk === 'local') {
            return response()->json(['message' => 'Local disk is always available on this server.', 'ok' => true]);
        }

        try {
            $s3 = $this->service->buildS3Disk();
            $testKey = 'connection-test/' . now()->timestamp . '.txt';
            $s3->put($testKey, 'connection test from ' . config('app.name'));
            $s3->delete($testKey);

            return response()->json(['message' => 'Successfully connected to the configured S3 destination.', 'ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not connect: ' . $e->getMessage(), 'ok' => false], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // FULL DATA WIPE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /admin/database/wipe
     * The most dangerous endpoint in the system. Requires:
     *  - super_admin role (route middleware)
     *  - exact confirmation phrase
     *  - the operator's current password (re-authentication)
     *  - an automatic pre-wipe backup (cannot be skipped)
     */
    public function wipe(Request $request): JsonResponse
    {
        $request->validate([
            'confirm_phrase' => 'required|string',
            'password'       => 'required|string',
        ]);

        if ($request->input('confirm_phrase') !== 'DELETE ALL DATA') {
            return response()->json(['message' => 'Confirmation phrase did not match. Type DELETE ALL DATA exactly to proceed.'], 422);
        }

        if (!Hash::check($request->input('password'), $request->user()->password)) {
            return response()->json(['message' => 'Password is incorrect.'], 422);
        }

        if (!DatabaseManagementService::isPgDumpAvailable()) {
            return response()->json([
                'message' => 'A full data wipe cannot proceed because the mandatory safety backup could not be created ' .
                             '(pg_dump is unavailable on this server). Install postgresql-client first.',
            ], 422);
        }

        try {
            $backup    = $this->service->createBackup('pre_wipe', 'user', $request->user());
            $truncated = $this->service->wipeAllData($request->user());

            return response()->json([
                'message'          => 'All application data has been wiped. Core configuration (users, roles, settings) was preserved.',
                'truncated_tables' => $truncated,
                'safety_backup'    => ['id' => $backup->id, 'filename' => $backup->filename],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Data wipe failed.', 'error' => $e->getMessage()], 500);
        }
    }
}