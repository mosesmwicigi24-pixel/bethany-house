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
                'processing' => NeemaEventEmitter::emit($order, 'order.production_started'),
                'shipped'    => NeemaEventEmitter::emit($order, 'order.shipped', [
                    'tracking' => $this->trackingOf($order),
                ]),
                'completed'  => NeemaEventEmitter::emit($order, 'order.delivered'),
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
