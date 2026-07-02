<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * BaseNotification
 *
 * All app notifications extend this. They are stored only in the database channel.
 * The data returned by toDatabase() must match the shape read by NotificationController:
 *   { title, body, action_url, icon, data: {} }
 *
 * Icon values must match the NotifIcon map in Topbar.tsx:
 *   payment | orders | production | tasks | shipment | stock | qc | bell
 */
abstract class BaseNotification extends Notification
{
    public function via($notifiable): array
    {
        return ['database'];
    }

    abstract public function toArray($notifiable): array;

    public function toDatabase($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    /**
     * Helper: build a consistent payload structure.
     */
    protected function payload(
        string $title,
        string $body,
        string $actionUrl,
        string $icon = 'bell',
        array $extra = []
    ): array {
        return [
            'title'      => $title,
            'body'       => $body,
            'action_url' => $actionUrl,
            'icon'       => $icon,
            'data'       => $extra,
        ];
    }
}