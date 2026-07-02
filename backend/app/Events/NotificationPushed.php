<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 2 - Push a notification to a specific user's private channel.
 *
 * Channel: private-user.{userId}
 *
 * Frontend listens on this channel to update the notification bell badge
 * and prepend new notifications to the list - eliminating the 30-second poll.
 *
 * Fired from InAppNotification::toDatabase() after the DB row is written.
 */
class NotificationPushed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $userId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl,
        public readonly string $icon,
        public readonly array  $data = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->userId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.pushed';
    }

    public function broadcastWith(): array
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