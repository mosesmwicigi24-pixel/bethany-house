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

            // Auto-generate description from event + subject
            if ($description === null) {
                $modelName = $subjectType ? class_basename($subjectType) : 'Record';
                $label     = ucfirst(str_replace(['_', '-'], ' ', $event));
                $description = $subjectId
                    ? "{$label} {$modelName} #{$subjectId}"
                    : $label;
            }

            // Redact sensitive keys from properties
            $safeProperties = self::redactSensitive($properties);

            DB::table('activity_log')->insert([
                'log_name'     => 'default',
                'description'  => $description,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'event'        => $event,
                'causer_type'  => $causerType,
                'causer_id'    => $causerId,
                // Also fill the legacy column used by AuditLogController
                'action'       => $event,
                'properties'   => json_encode($safeProperties),
                'ip_address'   => Request::ip(),
                'user_agent'   => Request::userAgent(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Exception $e) {
            // Logging failures must never crash the application
            Log::error('ActivityLogService failed: ' . $e->getMessage());
        }
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
        self::log(
            event:       'settings_updated',
            subject:     null,
            properties:  ['key' => $key, 'old' => $oldValue, 'new' => $newValue],
            description: "Setting '{$key}' updated",
            causer:      $causer
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Redact sensitive keys from property arrays before writing to the log.
     */
    private static function redactSensitive(array $data): array
    {
        $sensitivePatterns = [
            '_key', '_secret', '_passkey', '_token', '_password',
            'password', 'secret', 'api_key', 'access_token', 'refresh_token',
        ];

        array_walk_recursive($data, function (&$value, $key) use ($sensitivePatterns) {
            foreach ($sensitivePatterns as $pattern) {
                if (str_contains(strtolower((string) $key), $pattern)) {
                    $value = '[REDACTED]';
                    break;
                }
            }
        });

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