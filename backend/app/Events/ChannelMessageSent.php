<?php

namespace App\Events;

use App\Models\ChannelMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 3 - Broadcast a new channel message to all channel members.
 *
 * Channel: private-channel.{channelId}
 *
 * Also fires a separate UserNotified event on private-user.{userId}
 * for each member not currently viewing the channel (unread badge update).
 */
class ChannelMessageSent implements ShouldBroadcastNow
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
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $user = $this->message->user;
        return [
            'id'           => $this->message->id,
            'channel_id'   => $this->message->channel_id,
            'reply_to_id'  => $this->message->reply_to_id,
            'type'         => $this->message->type,
            'body'         => $this->message->body,
            'mentions'     => $this->message->mentions,
            'attachments'  => $this->message->attachments,
            'reactions'    => $this->message->reactions,
            'edited_at'    => $this->message->edited_at?->toIso8601String(),
            'created_at'   => $this->message->created_at->toIso8601String(),
            'user'         => $user ? [
                'id'       => $user->id,
                'name'     => trim("{$user->first_name} {$user->last_name}"),
                'initials' => strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)),
            ] : null,
        ];
    }
}