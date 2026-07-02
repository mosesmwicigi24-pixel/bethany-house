<?php

namespace App\Notifications;

class UserWelcomeNotification extends BaseNotification
{
    public function __construct(private string $firstName) {}

    public function toArray($notifiable): array
    {
        return $this->payload(
            title:     "Welcome to Bethany House, {$this->firstName}!",
            body:      "Your account is set up and ready. You can now access the platform.",
            actionUrl: "/dashboard",
            icon:      'bell',
            extra:     []
        );
    }
}