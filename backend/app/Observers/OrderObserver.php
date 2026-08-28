<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Neema\NeemaEventEmitter;

/**
 * One hook for EVERY order writer (admin UI, POS, payment webhooks): whenever
 * an order's status or payment_status transitions, Neema hears about it and
 * acts — celebrations instantly, disappointments to a human with context.
 *
 * Registered in AppServiceProvider::boot(). Best-effort by construction —
 * the emitter never throws.
 */
class OrderObserver
{
    /**
     * Run handlers only AFTER the surrounding transaction commits.
     *
     * The emitter makes an HTTP call with a 4-second timeout. Firing that
     * inside a transaction would hold row locks open on a third party's
     * latency, and would announce an order state that a later rollback erases.
     * This matters more now that the shipment writes below are Eloquent.
     */
    public $afterCommit = true;

    /** POS sales are often created already-paid — that first save counts. */
    public function created(Order $order): void
    {
        if ($order->payment_status === 'paid') {
            NeemaEventEmitter::emit($order, 'order.paid');
        }
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('payment_status')) {
            match ($order->payment_status) {
                'paid'           => NeemaEventEmitter::emit($order, 'order.paid'),
                'partially_paid' => NeemaEventEmitter::emit($order, 'payment.partial'),
                'refunded'       => NeemaEventEmitter::emit($order, 'refund.requested'),
                default          => null,
            };
        }

        if ($order->wasChanged('status')) {
            match ($order->status) {
                // 'confirmed' is the moment an order becomes INCOME, and it had
                // no arm here at all: in 30 days production emitted order.paid,
                // order.production_started and order.delivered — never a
                // confirmation. So Neema could not show a confirmed order as
                // confirmed, because nothing ever told her.
                'confirmed'  => NeemaEventEmitter::emit($order, 'order.confirmed'),
                'processing' => NeemaEventEmitter::emit($order, 'order.production_started'),
                'shipped'    => NeemaEventEmitter::emit($order, 'order.shipped', [
                    'tracking' => $this->trackingOf($order),
                ]),
                // Both terminal fulfilment states report as delivered. 'delivered'
                // is set by ShipmentController and likewise had no arm.
                'delivered'  => NeemaEventEmitter::emit($order, 'order.delivered'),
                'completed'  => NeemaEventEmitter::emit($order, 'order.delivered'),
                'cancelled'  => NeemaEventEmitter::emit($order, 'order.cancelled'),
                default      => null,
            };
        }
    }

    private function trackingOf(Order $order): ?string
    {
        try {
            return $order->shipments()->latest()->value('tracking_number');
        } catch (\Throwable) {
            return null;
        }
    }
}
