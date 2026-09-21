<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * ActivityLogService
 *
 * Writes structured audit trail entries to the activity_log table.
 * Works with or without spatie/laravel-activitylog - uses raw DB inserts
 * that match Spatie's column schema so the AuditLogController query works
 * for both approaches.
 *
 * Usage:
 *   ActivityLogService::log('created', $order, ['status' => 'pending'], null, $request->user());
 *   ActivityLogService::log('settings_updated', null, ['key' => 'tax_inclusive', 'old' => false, 'new' => true]);
 *   ActivityLogService::auth('login', $user);
 */
class ActivityLogService
{
    /**
     * Write an activity log entry.
     *
     * @param  string       $event      e.g. 'created', 'updated', 'deleted', 'login', 'payment_confirmed'
     * @param  mixed|null   $subject    Eloquent model instance (optional)
     * @param  array        $properties Additional context: old/new values, metadata, etc.
     * @param  string|null  $description Human-readable summary. Auto-generated if null.
     * @param  mixed|null   $causer     User model. Defaults to Auth::user().
     */
    public static function log(
        string $event,
        $subject = null,
        array $properties = [],
        ?string $description = null,
        $causer = null
    ): void {
        try {
            $causer    = $causer ?? Auth::user();
            $causerId  = $causer?->id;
            $causerType = $causer ? get_class($causer) : null;

            $subjectId   = $subject?->getKey();
            $subjectType = $subject ? get_class($subject) : null;

            // The AuditObserver records every create/update/delete of observed
            // models with exact before-and-after values. A controller's older
            // generic call for the same save would write the same change twice.
            if (in_array($event, self::MODEL_EVENTS, true)
                && self::context()?->wasObserved($subjectType, $subjectId, $event)) {
                return;
            }

            // Auto-generate description from event + subject
            if ($description === null) {
                $modelName = $subjectType ? class_basename($subjectType) : 'Record';
                $label     = ucfirst(str_replace(['_', '-'], ' ', $event));
                $description = $subjectId
                    ? "{$label} {$modelName} #{$subjectId}"
                    : $label;
            }

            self::write([
                'log_name'     => 'default',
                'description'  => mb_substr($description, 0, 1000),
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'event'        => $event,
                'causer_type'  => $causerType,
                'causer_id'    => $causerId,
                // Also fill the legacy column used by AuditLogController
                'action'       => $event,
                'properties'   => json_encode(self::redactSensitive($properties), JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            // Logging failures must never crash the application
            Log::error('ActivityLogService failed: ' . $e->getMessage());
        }
    }

    /** Generic model events the AuditObserver owns for observed models. */
    private const MODEL_EVENTS = ['created', 'updated', 'deleted'];

    /**
     * Record a model change observed by App\Observers\AuditObserver. Separate
     * from log() so the de-duplication above never suppresses the observer's
     * own write, and so the observer marks the change as recorded.
     */
    public static function recordModelChange(string $event, $model, array $properties, string $description): void
    {
        try {
            $causer = Auth::user();
            self::write([
                'log_name'     => 'model',
                'description'  => mb_substr($description, 0, 1000),
                'subject_type' => get_class($model),
                'subject_id'   => $model->getKey(),
                'event'        => $event,
                'causer_type'  => $causer ? get_class($causer) : null,
                'causer_id'    => $causer?->id,
                'action'       => $event,
                'properties'   => json_encode($properties, JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
            self::context()?->markObserved(get_class($model), $model->getKey(), $event);
        } catch (\Throwable $e) {
            Log::error('ActivityLogService::recordModelChange failed: ' . $e->getMessage());
        }
    }

    /**
     * The single INSERT every audit entry goes through.
     *
     * Inside a transaction the insert runs in its own SAVEPOINT. Postgres
     * aborts the WHOLE transaction on any failed statement — a caught
     * exception does not undo that — so an audit write that failed inside a
     * POS sale used to take the sale down with it (PrivilegeEscalationTest
     * documents the case). The savepoint confines a failure to the log entry.
     */
    private static function write(array $row): void
    {
        $row += [
            'ip_address' => Request::ip(),
            'user_agent' => mb_substr((string) Request::userAgent(), 0, 500) ?: null,
            'request_id' => self::context()?->requestId(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $insert = fn () => DB::table('activity_log')->insert($row);

        if (DB::transactionLevel() > 0) {
            DB::transaction($insert);   // nested → SAVEPOINT, rolled back alone on failure
        } else {
            $insert();
        }
    }

    private static function context(): ?\App\Support\Audit\AuditContext
    {
        return app()->bound(\App\Support\Audit\AuditContext::class)
            ? app(\App\Support\Audit\AuditContext::class)
            : null;
    }

    /**
     * Convenience method for model create events.
     * Records the new attribute values as properties.
     */
    public static function logCreated($model, $causer = null): void
    {
        self::log(
            event:       'created',
            subject:     $model,
            properties:  ['attributes' => self::safeAttributes($model)],
            description: 'Created ' . class_basename($model) . ' #' . $model->getKey(),
            causer:      $causer
        );
    }

    /**
     * Convenience method for model update events.
     * Records old + new values for changed attributes.
     */
    public static function logUpdated($model, array $oldValues, array $newValues, $causer = null): void
    {
        $changes = [];
        foreach ($newValues as $key => $new) {
            $old = $oldValues[$key] ?? null;
            if ($old != $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        if (empty($changes)) return;

        self::log(
            event:       'updated',
            subject:     $model,
            properties:  ['changes' => $changes],
            description: 'Updated ' . class_basename($model) . ' #' . $model->getKey(),
            causer:      $causer
        );
    }

    /**
     * Convenience method for model delete events.
     */
    public static function logDeleted($model, $causer = null): void
    {
        self::log(
            event:       'deleted',
            subject:     $model,
            properties:  ['attributes' => self::safeAttributes($model)],
            description: 'Deleted ' . class_basename($model) . ' #' . $model->getKey(),
            causer:      $causer
        );
    }

    /**
     * Log an authentication event (login / logout / 2fa etc.)
     */
    public static function auth(string $event, $user): void
    {
        self::log(
            event:       $event,
            subject:     $user,
            properties:  ['ip' => Request::ip(), 'user_agent' => Request::userAgent()],
            description: ucfirst($event) . ' - ' . ($user->email ?? 'unknown'),
            causer:      $user
        );
    }

    /**
     * Log a settings change.
     */
    public static function settingsChanged(string $key, $oldValue, $newValue, $causer = null): void
    {
        // Settings are key/value rows: a secret lives in `value` under an
        // innocent column name, so redaction has to look at the KEY.
        if (self::isSensitiveName($key)) {
            $oldValue = $oldValue === null || $oldValue === '' ? null : '[REDACTED]';
            $newValue = $newValue === null || $newValue === '' ? null : '[REDACTED]';
        }

        self::log(
            event:       'settings_updated',
            subject:     null,
            properties:  ['key' => $key, 'old' => $oldValue, 'new' => $newValue],
            description: "Setting '{$key}' updated",
            causer:      $causer
        );
    }

    /**
     * Record every settings key whose value actually changed, as ONE entry
     * with old → new per key. Callers snapshot the old values before writing:
     *
     *   $before = ActivityLogService::settingsSnapshot(array_keys($validated));
     *   ...updateOrInsert each key...
     *   ActivityLogService::settingsSaved($before, $validated, 'general');
     */
    public static function settingsSnapshot(array $keys): array
    {
        try {
            return DB::table('settings')->whereIn('key', $keys)->pluck('value', 'key')->all();
        } catch (\Throwable $e) {
            Log::error('ActivityLogService::settingsSnapshot failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function settingsSaved(array $before, array $after, string $group, $causer = null): void
    {
        $changes = [];
        foreach ($after as $key => $new) {
            $newStr = is_bool($new) ? ($new ? '1' : '0') : (is_array($new) ? json_encode($new) : (string) ($new ?? ''));
            $oldStr = array_key_exists($key, $before) ? (string) ($before[$key] ?? '') : null;
            if ($oldStr === $newStr) {
                continue;
            }
            $changes[$key] = self::isSensitiveName($key)
                ? ['old' => $oldStr ? '[REDACTED]' : null, 'new' => $newStr !== '' ? '[REDACTED]' : null]
                : ['old' => $oldStr, 'new' => $newStr];
        }

        if ($changes === []) {
            return;
        }

        self::log(
            event:       'settings_updated',
            subject:     null,
            properties:  ['group' => $group, 'changes' => $changes],
            description: ucfirst($group) . ' settings changed: ' . implode(', ', array_keys($changes)),
            causer:      $causer
        );
    }

    public static function isSensitiveName(string $name): bool
    {
        $name = strtolower($name);
        foreach (array_merge(self::SENSITIVE_PATTERNS, (array) config('audit.redacted_attributes', [])) as $p) {
            if (str_contains($name, $p)) {
                return true;
            }
        }
        return false;
    }

    private const SENSITIVE_PATTERNS = [
        '_key', '_secret', '_passkey', '_token', '_password',
        'password', 'secret', 'api_key', 'access_token', 'refresh_token',
    ];

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Redact sensitive keys from property arrays before writing to the log.
     */
    /**
     * Redact every value under a sensitive key — including a whole branch, so
     * ['password' => ['old' => …, 'new' => …]] cannot leak (array_walk_recursive,
     * used here before, only ever saw the leaves and missed exactly that shape).
     */
    public static function redactSensitive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveName($key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redactSensitive($value);
            }
        }

        return $data;
    }

    /**
     * Extract a safe subset of model attributes (skip binary / large fields).
     */
    private static function safeAttributes($model): array
    {
        $skip = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];
        return collect($model->getAttributes())
            ->except($skip)
            ->toArray();
    }
}