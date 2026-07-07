<?php

namespace App\Support;

/**
 * Order status state machine (audit ST-1).
 *
 * OrderController::updateStatus previously validated only the *target* status,
 * never the current→target transition, so a delivered order could be sent back
 * to pending, a cancelled order could be shipped, etc. This table encodes the
 * legal transitions.
 *
 * Policy: generous forward movement (stages may be skipped forward), the
 * processing↔confirmed pair may move laterally, and orders may be cancelled up
 * to shipment. Blocked: any backward move, any exit from a terminal state
 * (cancelled/refunded), and cancelling/altering a completed order (use a refund).
 * Adjust the table if the business needs additional transitions.
 */
class OrderStatusMachine
{
    public const TRANSITIONS = [
        'pending'    => ['processing', 'confirmed', 'shipped', 'delivered', 'completed', 'cancelled'],
        'processing' => ['confirmed', 'shipped', 'delivered', 'completed', 'cancelled'],
        'confirmed'  => ['processing', 'shipped', 'delivered', 'completed', 'cancelled'],
        'shipped'    => ['delivered', 'completed', 'cancelled'],
        'delivered'  => ['completed', 'refunded'],
        'completed'  => ['refunded'],
        'cancelled'  => [],
        'refunded'   => [],
    ];

    /**
     * Is moving from $from to $to allowed? A no-op (same status) is always
     * allowed; an unknown current status fails open (legacy/dirty data is not
     * this guard's job to police).
     */
    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        if (! array_key_exists($from, self::TRANSITIONS)) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Abort with 422 if the transition is illegal.
     */
    public static function assertCanTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            abort(422, "Illegal order status transition: {$from} → {$to}.");
        }
    }
}
