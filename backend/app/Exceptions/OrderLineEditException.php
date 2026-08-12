<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A refusal from OrderLineEditor, carrying a stable machine-readable `reason`
 * alongside the human message — the same `{message, reason}` 422 shape the rest
 * of the order endpoints already return (setShippingFee, raiseItemProduction,
 * addPayment), so the admin UI can tell "this needs a confirmation" apart from
 * "this will never be allowed".
 */
class OrderLineEditException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function render($request = null): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'reason'  => $this->reason,
        ], $this->status);
    }
}
