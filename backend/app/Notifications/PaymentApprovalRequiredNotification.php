<?php

namespace App\Notifications;

class PaymentApprovalRequiredNotification extends BaseNotification
{
    public function __construct(
        private int $paymentId,
        private string $paymentNumber,
        private int $orderId,
        private string $orderNumber,
        private float $amount,
        private string $currency,
        private string $countryCode
    ) {}

    public function toArray($notifiable): array
    {
        $body = $this->amount > 0
            ? "Payment of {$this->currency} " . number_format($this->amount, 2)
              . " for order #{$this->orderNumber} is awaiting approval."
              . ($this->countryCode ? " (from {$this->countryCode})" : '')
            : "Payment proof has been uploaded for order #{$this->orderNumber}. Please review.";

        return $this->payload(
            title:     "Payment approval required - #{$this->orderNumber}",
            body:      $body,
            actionUrl: "/approvals",
            icon:      'payment',
            extra:     [
                'payment_id'   => $this->paymentId,
                'order_id'     => $this->orderId,
                'order_number' => $this->orderNumber,
                'amount'       => $this->amount,
                'currency'     => $this->currency,
                'country_code' => $this->countryCode,
            ]
        );
    }
}