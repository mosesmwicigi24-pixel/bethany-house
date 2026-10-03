<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A finalized till is a locked batch (Phase 4B): nothing changes its money,
 * whoever asks. Thrown by CashRegister before the write reaches the database
 * (where a trigger refuses it again), and rendered as 409 so every endpoint
 * that would have moved it answers the same way.
 */
class TillFinalizedException extends \RuntimeException
{
    public function __construct(
        string $message = 'This till has been verified and finalized. Its figures can no longer change — finance can open a linked correction instead.',
        public readonly string $errorCode = 'TILL_FINALIZED',
    ) {
        parent::__construct($message);
    }

    public function render($request = null): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code'    => $this->errorCode,
        ], 409);
    }
}
