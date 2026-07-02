<?php

namespace App\Notifications;

class OrderPlacedNotification extends BaseNotification
{
    public function __construct(
        private int $orderId,
        private string $orderNumber
    ) {}

    public function toArray($notifiable): array
    {
        return $this->payload(
            title:     "New order placed",
            body:      "Order #{$this->orderNumber} has been placed and needs attention.",
            // FIX: was "/orders/{$this->orderId}" - that route doesn't exist, so
            // clicking this notification fell through the catch-all and bounced
            // to /dashboard. Sales orders live under /sales/orders/:id.
            actionUrl: "/sales/orders/{$this->orderId}",
            icon:      'orders',
            extra:     ['order_id' => $this->orderId, 'order_number' => $this->orderNumber]
        );
    }
}