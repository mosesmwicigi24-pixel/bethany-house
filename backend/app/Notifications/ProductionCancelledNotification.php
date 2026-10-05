<?php

namespace App\Notifications;

class ProductionCancelledNotification extends BaseNotification
{
    public function __construct(
        private int $productionOrderId,
        private string $orderNumber,
        private string $productName,
        private ?string $reason = null
    ) {}

    public function toArray($notifiable): array
    {
        return $this->payload(
            title:     "Production order cancelled - {$this->productName}",
            body:      "Production order #{$this->orderNumber} was cancelled. Stop work on it."
                       . ($this->reason ? " Reason: {$this->reason}" : ''),
            actionUrl: "/production/orders/{$this->productionOrderId}",
            icon:      'production',
            extra:     ['production_order_id' => $this->productionOrderId]
        );
    }
}
