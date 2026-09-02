<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A LIVE order asked to deduct stock it has already given back.
 *
 * stock_unwound_at means the goods went back on the shelf. If such an order is
 * still live and now wants to commit, the two facts contradict: committing
 * would either deduct nothing (the guard in commitForOrder) or consume a
 * reservation that belongs to somebody else's order.
 *
 * Thrown rather than logged-and-skipped on purpose. A silent skip looks like a
 * completed sale while quietly breaking the shelf, which is the harder failure
 * to notice and the more expensive one to unpick.
 */
class StaleStockHoldException extends RuntimeException
{
    public function __construct(public readonly int $orderId, string $orderNumber = '')
    {
        parent::__construct(
            "Order {$orderNumber} (#{$orderId}) has a stale stock hold: its goods were returned "
            . 'to the shelf, so it cannot deduct them again. Re-save the order to take the stock afresh.'
        );
    }
}
