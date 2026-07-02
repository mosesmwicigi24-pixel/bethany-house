<?php

namespace App\Notifications;

class PaymentReceivedNotification extends BaseNotification
{
    public function __construct(
        private int $paymentId,
        private string $paymentNumber,
        private int $orderId,
        private string $orderNumber,
        private float $amount,
        private string $currency,
        private string $method
    ) {}

    public function toArray($notifiable): array
    {
        $formatted = number_format($this->amount, 2);
        $methodLabel = strtoupper(str_replace('_', ' ', $this->method));
        return $this->payload(
            title:     "Payment received - {$this->currency} {$formatted}",
            body:      "Payment for order #{$this->orderNumber} confirmed via {$methodLabel}.",
            // FIX: was "/orders/{$this->orderId}" - that route doesn't exist, so
            // clicking a payment notification fell through the catch-all and
            // bounced to /dashboard instead of opening the order. Sales orders
            // live under /sales/orders/:id.
            actionUrl: "/sales/orders/{$this->orderId}",
            icon:      'payment',
            extra:     [
                'payment_id'     => $this->paymentId,
                'order_id'       => $this->orderId,
                'order_number'   => $this->orderNumber,
                'amount'         => $this->amount,
                'currency'       => $this->currency,
            ]
        );
    }
}