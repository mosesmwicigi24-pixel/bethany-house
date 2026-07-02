<?php

namespace App\Events;

use App\Models\ChannelMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever a reaction is toggled on a channel message.
 *
 * Channel : private-channel.{channelId}   (same channel as ChannelMessageSent)
 * Event   : reaction.updated
 *
 * Payload carries the message_id and the *complete* reactions map so the
 * frontend can do a clean replacement — no merging logic needed on the client.
 */
class ChannelReactionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly ChannelMessage $message,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("channel.{$this->message->channel_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'reaction.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'channel_id' => $this->message->channel_id,
            'reactions'  => $this->message->reactions ?? [],
        ];
    }
}