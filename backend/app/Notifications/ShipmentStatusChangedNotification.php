<?php

namespace App\Notifications;

class ShipmentStatusChangedNotification extends BaseNotification
{
    public function __construct(
        private int $orderId,
        private string $orderNumber,
        private string $newStatus
    ) {}

    public function toArray($notifiable): array
    {
        $label = ucfirst(str_replace('_', ' ', $this->newStatus));
        return $this->payload(
            title:     "Shipment update - #{$this->orderNumber}",
            body:      "Your shipment for order #{$this->orderNumber} is now: {$label}.",
            // FIX: was "/orders/{$this->orderId}" - that route doesn't exist, so
            // clicking this notification fell through the catch-all and bounced
            // to /dashboard. Sales orders live under /sales/orders/:id.
            actionUrl: "/sales/orders/{$this->orderId}",
            icon:      'shipment',
            extra:     ['order_id' => $this->orderId, 'status' => $this->newStatus]
        );
    }
}