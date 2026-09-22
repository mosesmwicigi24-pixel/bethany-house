<?php

namespace App\Notifications;

/** Bell notification for imprest events (low balance, top-ups, counts, unresolved spends). */
class ImprestNotification extends BaseNotification
{
    public function __construct(private string $title, private string $body, private string $url) {}

    public function toArray($notifiable): array
    {
        return $this->payload(title: $this->title, body: $this->body, actionUrl: $this->url, icon: 'payment');
    }
}
