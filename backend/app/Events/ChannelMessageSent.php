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

    /**
     * Must mirror ChannelController::formatMessage() — the client renders a
     * broadcast message with the same component as a fetched one.
     *
     * `linked_entities` + `entity_previews` were previously omitted here, so any
     * client receiving the live socket event (including the SENDER, since this is
     * broadcast without ->toOthers()) rendered the message with its order/entity
     * chip missing — the token is stripped at render and the chips come solely
     * from these fields. The sender's broadcast also beat the HTTP response, and
     * the client's first-write-wins de-dupe then discarded the complete payload,
     * so the chip never appeared until a reload.
     */
    public function broadcastWith(): array
    {
        $user  = $this->message->user;
        $reply = $this->message->replyTo;

        return [
            'id'           => $this->message->id,
            'channel_id'   => $this->message->channel_id,
            'reply_to_id'  => $this->message->reply_to_id,
            'reply_to'     => $reply ? [
                'id'        => $reply->id,
                'body'      => mb_substr($reply->body, 0, 80),
                'user_name' => $reply->user ? trim("{$reply->user->first_name} {$reply->user->last_name}") : null,
            ] : null,
            'type'         => $this->message->type,
            'body'         => $this->message->body,
            'mentions'     => $this->message->mentions,
            'linked_entities' => $this->message->linked_entities ?? [],
            'entity_previews' => !empty($this->message->linked_entities)
                ? \App\Services\IntelligenceService::entityChipPreviews($this->message->linked_entities)
                : [],
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