<?php

namespace App\Services\Production;

use App\Models\User;

/**
 * One intended movement of pieces, as the floor expresses it.
 *
 * `clientRef` is generated on the device before the request leaves, not by the
 * server. That is what makes a double-tap, a retried offline outbox flush, or a
 * reconnect after a dead spot in the workshop safe: replaying the same ref
 * returns the original movement instead of moving the pieces a second time.
 */
class MoveCommand
{
    public function __construct(
        public readonly string $clientRef,
        public readonly int $batchId,
        public readonly int $fromStageId,
        public readonly int $quantity,
        public readonly string $type,
        public readonly User $actor,
        public readonly ?int $toStageId = null,   // resolved from the workflow when omitted
        public readonly ?string $reasonCode = null,
        public readonly ?string $note = null,
        public readonly ?string $deviceId = null,
    ) {}
}
