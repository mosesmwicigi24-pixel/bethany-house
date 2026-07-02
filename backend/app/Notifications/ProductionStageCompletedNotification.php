<?php

namespace App\Notifications;

class ProductionStageCompletedNotification extends BaseNotification
{
    public function __construct(
        private int $productionOrderId,
        private string $orderNumber,
        private string $stageName,
        private string $productName
    ) {}

    public function toArray($notifiable): array
    {
        return $this->payload(
            title:     "Stage '{$this->stageName}' completed",
            body:      "Production #{$this->orderNumber} ({$this->productName}) has finished the '{$this->stageName}' stage.",
            // FIX: was "/production/{$this->productionOrderId}". That path isn't
            // a registered route - bare /production redirects to the LIST page
            // (/production/orders), and there is no /production/:id route. The
            // individual order detail page lives at /production/orders/:id.
            actionUrl: "/production/orders/{$this->productionOrderId}",
            icon:      'production',
            extra:     ['production_order_id' => $this->productionOrderId, 'stage' => $this->stageName]
        );
    }
}