<?php

namespace App\Notifications;

class ProductionAssignedNotification extends BaseNotification
{
    public function __construct(
        private int $productionOrderId,
        private string $orderNumber,
        private string $productName
    ) {}

    public function toArray($notifiable): array
    {
        return $this->payload(
            title:     "Production task assigned - {$this->productName}",
            body:      "You have been assigned to production order #{$this->orderNumber}.",
            // FIX: was "/production/{$this->productionOrderId}". That path isn't
            // a registered route - App.tsx only has /production/orders/:id for the
            // individual order, while bare /production redirects to the LIST page
            // (/production/orders). The old URL was falling through the catch-all
            // route and bouncing to /dashboard instead of opening the order.
            actionUrl: "/production/orders/{$this->productionOrderId}",
            icon:      'production',
            extra:     ['production_order_id' => $this->productionOrderId]
        );
    }
}