<?php

namespace App\Notifications;

class LowStockAlertNotification extends BaseNotification
{
    public function __construct(
        private int $variantId,
        private string $productName,
        private string $sku,
        private int $currentQty,
        private int $threshold,
        private ?string $outletName = null
    ) {}

    public function toArray($notifiable): array
    {
        $location = $this->outletName ? " at {$this->outletName}" : '';
        return $this->payload(
            title:     "Low stock - {$this->productName}",
            body:      "SKU {$this->sku}{$location} has only {$this->currentQty} unit(s) remaining (threshold: {$this->threshold}).",
            actionUrl: "/inventory/low-stock",
            icon:      'stock',
            extra:     [
                'variant_id'  => $this->variantId,
                'product_name'=> $this->productName,
                'sku'         => $this->sku,
                'qty'         => $this->currentQty,
                'threshold'   => $this->threshold,
                'outlet'      => $this->outletName,
            ]
        );
    }
}