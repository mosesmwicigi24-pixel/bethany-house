<?php

namespace App\Exceptions;

use App\Models\Order;
use App\Support\PaymentGate;
use Illuminate\Http\JsonResponse;

/**
 * A pay session was asked for on a dead order.
 *
 * Every door refuses before it gets here (Order::isPayable). This is the
 * backstop inside PaymentLinkService::mint, so a door added later that forgets
 * to ask still cannot hand a cancelled order a fresh /pay/{token} link — it
 * fails loudly as the same 422 the staff doors give.
 */
class OrderNotPayableException extends \RuntimeException
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct(PaymentGate::staffMessage($order));
    }

    public function render($request = null): JsonResponse
    {
        return response()->json([
            'message'      => $this->getMessage(),
            'reason'       => PaymentGate::REASON,
            'order_status' => $this->order->status,
        ], 422);
    }
}
