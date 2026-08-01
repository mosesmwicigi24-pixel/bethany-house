<?php

namespace App\Services\Production;

use RuntimeException;

/**
 * A refused movement, with a code the floor screen can act on.
 *
 * These are not server errors — every one of them is a thing that legitimately
 * happens on a shop floor: two tailors reaching for the same ten pieces, a
 * worker trying to sew something that was never cut, a supervisor reversing a
 * movement somebody already reversed. The code is what lets the client decide
 * between "roll back the optimistic update and refetch" and "show this message".
 */
class MovementException extends RuntimeException
{
    /** Someone else took the pieces first, or there were never that many. */
    public const INSUFFICIENT_QUANTITY = 'INSUFFICIENT_QUANTITY';
    /** The workflow graph does not permit this hop. */
    public const INVALID_TRANSITION = 'INVALID_TRANSITION';
    /** The actor's role may not perform this kind of movement. */
    public const NOT_AUTHORISED = 'NOT_AUTHORISED';
    /** Rework, scrap and hold must always say why. */
    public const REASON_REQUIRED = 'REASON_REQUIRED';
    /** The reason given does not belong to this kind of movement. */
    public const REASON_INVALID = 'REASON_INVALID';
    /** A movement may be reversed at most once. */
    public const ALREADY_REVERSED = 'ALREADY_REVERSED';
    /**
     * A legacy client tried to lower a stage counter. Going backwards is a
     * correction, not progress, and corrections are supervisor reversals — the
     * ledger has no way to un-move pieces that have already gone forward.
     */
    public const REQUIRES_REVERSAL = 'REQUIRES_REVERSAL';
    /** Finished and scrapped pieces do not move again. */
    public const TERMINAL_STAGE = 'TERMINAL_STAGE';
    /** End of the line, or no destination could be resolved. */
    public const NO_NEXT_STAGE = 'NO_NEXT_STAGE';
    public const BATCH_NOT_FOUND = 'BATCH_NOT_FOUND';
    public const MOVEMENT_NOT_FOUND = 'MOVEMENT_NOT_FOUND';
    /** The batch has no workflow pinned, so there is no line to walk. */
    public const WORKFLOW_NOT_PINNED = 'WORKFLOW_NOT_PINNED';
    public const BAD_QUANTITY = 'BAD_QUANTITY';

    /**
     * Named `errorCode` rather than `code` because \Exception already owns an
     * integer `$code`, and these are strings the client switches on.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $detail = [],
    ) {
        parent::__construct($message);
    }

    /**
     * How the API should answer. A refused move is a 422 by default — the
     * request was well-formed, the floor just says no. Authorisation is 403,
     * a missing row is 404, and a double-reversal is a 409 because the client's
     * view of the world is simply stale.
     */
    public function status(): int
    {
        return match ($this->errorCode) {
            self::NOT_AUTHORISED    => 403,
            self::BATCH_NOT_FOUND,
            self::MOVEMENT_NOT_FOUND => 404,
            // Conflicts: the request was valid, the world moved underneath it.
            // The client corrects itself from `detail` rather than refetching.
            self::INSUFFICIENT_QUANTITY,
            self::ALREADY_REVERSED,
            self::REQUIRES_REVERSAL => 409,
            default                 => 422,
        };
    }

    public function toArray(): array
    {
        return array_filter([
            'message' => $this->getMessage(),
            'code'    => $this->errorCode,
            'detail'  => $this->detail ?: null,
        ], fn ($v) => $v !== null);
    }
}
