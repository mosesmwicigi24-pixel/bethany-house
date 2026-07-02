<?php

namespace App\Notifications;

class ProductionOverdueNotification extends BaseNotification
{
    public function __construct(
        private int $productionOrderId,
        private string $orderNumber,
        private string $productName,
        private string $dueDate
    ) {}

    public function toArray($notifiable): array
    {
        return $this->payload(
            title:     "Production overdue - #{$this->orderNumber}",
            body:      "{$this->productName} was due on {$this->dueDate} and has not been completed.",
            // FIX: was "/production/{$this->productionOrderId}". That path isn't
            // a registered route - bare /production redirects to the LIST page
            // (/production/orders), and there is no /production/:id route. The
            // individual order detail page lives at /production/orders/:id.
            actionUrl: "/production/orders/{$this->productionOrderId}",
            icon:      'production',
            extra:     ['production_order_id' => $this->productionOrderId, 'due_date' => $this->dueDate]
        );
    }
}