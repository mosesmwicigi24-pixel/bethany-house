<?php

namespace App\Notifications;

class PaymentApprovalDecisionNotification extends BaseNotification
{
    public function __construct(
        private int $paymentId,
        private string $paymentNumber,
        private int $orderId,
        private string $orderNumber,
        private string $decision, // 'approved' | 'rejected'
        private string $reason = ''
    ) {}

    public function toArray($notifiable): array
    {
        $isApproved = $this->decision === 'approved';
        $body = $isApproved
            ? "Payment {$this->paymentNumber} for order #{$this->orderNumber} has been approved."
            : "Payment {$this->paymentNumber} for order #{$this->orderNumber} was rejected."
              . ($this->reason ? " Reason: {$this->reason}" : '');

        return $this->payload(
            title:     $isApproved
                ? "Payment approved - #{$this->orderNumber}"
                : "Payment rejected - #{$this->orderNumber}",
            body:      $body,
            // FIX: was "/orders/{$this->orderId}" - that route doesn't exist, so
            // clicking this notification fell through the catch-all and bounced
            // to /dashboard. Sales orders live under /sales/orders/:id.
            actionUrl: "/sales/orders/{$this->orderId}",
            icon:      'payment',
            extra:     [
                'payment_id'   => $this->paymentId,
                'order_id'     => $this->orderId,
                'decision'     => $this->decision,
                'reason'       => $this->reason,
            ]
        );
    }
}