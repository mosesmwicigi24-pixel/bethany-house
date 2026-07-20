<?php

namespace App\Notifications;

class LeadCapturedNotification extends BaseNotification
{
    public function __construct(
        private int $leadId,
        private string $who,
        private string $intent,
    ) {}

    public function toArray($notifiable): array
    {
        return $this->payload(
            title:     'New storefront lead',
            body:      ucfirst($this->intent) . " enquiry from {$this->who} — follow up.",
            actionUrl: "/sales/leads/{$this->leadId}",
            icon:      'customers',
            extra:     ['lead_id' => $this->leadId, 'intent' => $this->intent],
        );
    }
}
