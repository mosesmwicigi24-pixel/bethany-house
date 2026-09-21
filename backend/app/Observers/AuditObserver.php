<?php

namespace App\Observers;

use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every create / update / delete of the models listed in
 * config('audit.observed_models') — with the exact before-and-after value of
 * each changed field — without any controller having to remember to.
 *
 * Before this, the trail held what 48 controllers chose to announce by hand
 * ("pos_payment_recorded", "shipping_fee_updated"); a price edited, a customer's
 * phone changed or an order's total rewritten left nothing unless someone had
 * written a log line for that exact path. Those hand-written events stay: they
 * carry business meaning. This underneath them makes the trail complete.
 *
 * Limits, stated plainly:
 *  - Query-builder mass updates (Model::where(...)->update()) fire no model
 *    events and are invisible here. The request log still records the call.
 *  - Values of sensitive fields are never stored: the entry says the field
 *    changed and shows [REDACTED] (config audit.redacted_*).
 *  - A failure here never fails the save: ActivityLogService writes inside a
 *    savepoint and swallows its own errors into the application log.
 */
class AuditObserver
{
    /** Long text is summarised, not copied: the trail is not a second database. */
    private const MAX_VALUE_LENGTH = 500;

    public function created(Model $model): void
    {
        $this->record('created', $model, ['attributes' => $this->snapshot($model, $model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = [];
        foreach ($model->getChanges() as $key => $new) {
            if (in_array($key, (array) config('audit.ignored_attributes', []), true)) {
                continue;
            }
            $old = $model->getOriginal($key);
            if ($this->same($old, $new)) {
                continue;
            }
            $changes[$key] = $this->isRedacted($model, $key)
                ? ['old' => '[REDACTED]', 'new' => '[REDACTED]']
                : ['old' => $this->clip($old), 'new' => $this->clip($new)];
        }

        if ($changes === []) {
            return;   // only timestamps moved
        }

        $this->record('updated', $model, ['changes' => $changes]);
    }

    public function deleted(Model $model): void
    {
        $soft = method_exists($model, 'isForceDeleting') && !$model->isForceDeleting();
        $this->record('deleted', $model, [
            'soft_delete' => $soft,
            'attributes'  => $this->snapshot($model, $model->getAttributes()),
        ]);
    }

    public function restored(Model $model): void
    {
        $this->record('restored', $model, []);
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function record(string $event, Model $model, array $properties): void
    {
        ActivityLogService::recordModelChange($event, $model, $properties, $this->describe($event, $model, $properties));
    }

    /** "Updated Order #412: status pending → confirmed, total_amount 1000 → 1200 (+2 more)" */
    private function describe(string $event, Model $model, array $properties): string
    {
        $what = class_basename($model) . ' #' . $model->getKey();

        if ($event !== 'updated') {
            return ucfirst($event) . ' ' . $what;
        }

        $parts = [];
        foreach (array_slice($properties['changes'], 0, 3, true) as $key => $c) {
            $parts[] = $key . ' ' . $this->short($c['old']) . ' → ' . $this->short($c['new']);
        }
        $more = count($properties['changes']) - count($parts);

        return "Updated {$what}: " . implode(', ', $parts) . ($more > 0 ? " (+{$more} more)" : '');
    }

    private function snapshot(Model $model, array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            $out[$key] = $this->isRedacted($model, $key) ? '[REDACTED]' : $this->clip($value);
        }
        return $out;
    }

    private function isRedacted(Model $model, string $key): bool
    {
        $columns = (array) (config('audit.redacted_columns')[get_class($model)] ?? []);
        if (in_array($key, $columns, true)) {
            return true;
        }
        // A key/value settings row keeps its secret in `value`.
        if ($key === 'value' && is_string($model->getAttribute('key'))
            && ActivityLogService::isSensitiveName($model->getAttribute('key'))) {
            return true;
        }
        return ActivityLogService::isSensitiveName($key);
    }

    private function clip($value)
    {
        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '… [' . mb_strlen($value) . ' chars, sha256 '
                . substr(hash('sha256', $value), 0, 16) . ']';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        return is_scalar($value) || $value === null ? $value : json_decode(json_encode($value), true);
    }

    private function short($value): string
    {
        if ($value === null) return '∅';
        if (is_bool($value)) return $value ? 'true' : 'false';
        $s = is_scalar($value) ? (string) $value : json_encode($value);
        return mb_strlen($s) > 40 ? mb_substr($s, 0, 40) . '…' : $s;
    }

    /**
     * "1000.00" vs 1000, "1" vs true: a cast difference is not a change.
     * But "0712345678" → "712345678" IS one (a phone, a SKU): only plain
     * decimals without a leading zero are compared as numbers.
     */
    private function same($old, $new): bool
    {
        if ($old === $new) return true;
        $decimal = '/^-?(0|[1-9]\d*)(\.\d+)?$/';
        if (is_numeric($old) && is_numeric($new)
            && preg_match($decimal, (string) $old) && preg_match($decimal, (string) $new)) {
            return (float) $old === (float) $new;
        }
        if (is_bool($old) || is_bool($new)) return (bool) $old === (bool) $new;
        return (string) json_encode($old) === (string) json_encode($new);
    }
}
