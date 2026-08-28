<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a member's read pointer advances in a channel.
 *
 * Channel : private-channel.{channelId}   (same channel as ChannelMessageSent)
 * Event   : read.updated
 *
 * The payload is the member's new high-water mark, not a per-message list:
 * a message is "read by user X" exactly when X's pointer ≥ the message id,
 * so one integer updates the ticks on every message at once. Only sent when
 * the pointer actually moves — reopening an already-read thread is silent.
 */
class ChannelReadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $channelId,
        public readonly int $userId,
        public readonly int $lastReadMessageId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("channel.{$this->channelId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'read.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'channel_id'           => $this->channelId,
            'user_id'              => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}
