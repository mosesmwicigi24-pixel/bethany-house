<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Generic in-app notification.
 *
 * Stored in the `notifications` table via the 'database' channel.
 * Synchronous (no queue) so notifications are written immediately and
 * appear without needing a queue worker running.
 *
 * Usage:
 *   $user->notify(new InAppNotification(
 *       title:     'Production order #PRD-001 confirmed',
 *       body:      'Order for John Doe has entered the production queue.',
 *       actionUrl: '/production/orders/42',
 *       icon:      'production',
 *       data:      ['production_order_id' => 42],
 *   ));
 *
 *   // Bulk notify multiple users:
 *   Notification::send($users, new InAppNotification(...));
 */
class InAppNotification extends Notification
{

    public function __construct(
        public readonly string  $title,
        public readonly ?string $body      = null,
        public readonly ?string $actionUrl = null,
        public readonly ?string $icon      = null,
        public readonly array   $data      = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title'      => $this->title,
            'body'       => $this->body,
            'action_url' => $this->actionUrl,
            'icon'       => $this->icon,
            'data'       => $this->data,
        ];
    }
}