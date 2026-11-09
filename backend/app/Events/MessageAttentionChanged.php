<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast to ONE person when what needs them changed by their own hand —
 * they read, replied, dismissed a notice or marked a conversation handled —
 * so every other tab and device they have open drops the notice at once.
 *
 * Channel : private-user.{userId}   (the per-person channel NotificationPushed uses)
 * Event   : attention.changed
 *
 * The payload only says which conversation; each client asks
 * GET /channels/attention again, so the server stays the one source of truth.
 */
class MessageAttentionChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly int $channelId,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'attention.changed';
    }

    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId, 'reason' => $this->reason];
    }

    /** Fire and forget: Reverb being down must never fail the action itself. */
    public static function send(int $userId, int $channelId, string $reason): void
    {
        try {
            broadcast(new self($userId, $channelId, $reason));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Reverb attention broadcast failed: ' . $e->getMessage());
        }
    }
}
