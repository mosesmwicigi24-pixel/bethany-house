<?php

namespace App\Support\Audit;

use Illuminate\Support\Str;

/**
 * Per-request (and per-queue-job) audit state.
 *
 * Bound as a SCOPED instance in AppServiceProvider, so Laravel forgets it
 * between queue jobs — a long-running worker must not carry one job's request
 * id, or its "already recorded" set, into the next.
 *
 *  - requestId links an activity_log row to the request_logs row of the call
 *    that caused it: "who changed this price" and "what else did that request
 *    do" become one query.
 *  - observed remembers which (model, id, event) the AuditObserver has already
 *    recorded, so a controller's older manual ActivityLogService::logUpdated()
 *    for the same save does not write the same change twice.
 */
class AuditContext
{
    private ?string $requestId = null;

    /** @var array<string, true> */
    private array $observed = [];

    public function requestId(): string
    {
        return $this->requestId ??= (string) Str::uuid();
    }

    public function setRequestId(string $id): void
    {
        $this->requestId = $id;
    }

    public function markObserved(?string $type, $id, string $event): void
    {
        if ($type !== null && $id !== null) {
            $this->observed["{$type}#{$id}:{$event}"] = true;
        }
    }

    public function wasObserved(?string $type, $id, string $event): bool
    {
        return $type !== null && $id !== null && isset($this->observed["{$type}#{$id}:{$event}"]);
    }
}
