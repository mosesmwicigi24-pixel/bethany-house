<?php

namespace App\Notifications;

class OrderStatusChangedNotification extends BaseNotification
{
    public function __construct(
        private int $orderId,
        private string $orderNumber,
        private string $oldStatus,
        private string $newStatus
    ) {}

    public function toArray($notifiable): array
    {
        $label = ucfirst(str_replace('_', ' ', $this->newStatus));
        return $this->payload(
            title:     "Order #{$this->orderNumber} - {$label}",
            body:      "Status changed from " . ucfirst(str_replace('_', ' ', $this->oldStatus)) . " to {$label}.",
            // FIX: was "/orders/{$this->orderId}" - that route doesn't exist, so
            // clicking this notification fell through the catch-all and bounced
            // to /dashboard. Sales orders live under /sales/orders/:id.
            actionUrl: "/sales/orders/{$this->orderId}",
            icon:      'orders',
            extra:     [
                'order_id'   => $this->orderId,
                'old_status' => $this->oldStatus,
                'new_status' => $this->newStatus,
            ]
        );
    }
}