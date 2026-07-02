<?php

namespace App\Events;

use App\Models\Comment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 2 - Broadcast a new comment to all users viewing the same thread.
 *
 * Broadcast channel: private-thread.{modelKey}.{modelId}
 *   e.g.  private-thread.Order.42
 *         private-thread.ProductionOrder.6
 *
 * The channel auth is handled by routes/channels.php:
 *   Broadcast::channel('thread.{model}.{id}', fn ($user) => $user->canAccessAdmin());
 *
 * Frontend (Laravel Echo):
 *   Echo.private(`thread.Order.${order.id}`)
 *       .listen('.comment.posted', (e) => { ... });
 */
class CommentPosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Comment $comment,
        public readonly string  $modelKey,   // e.g. "Order"
        public readonly int     $modelId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("thread.{$this->modelKey}.{$this->modelId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'comment.posted';
    }

    public function broadcastWith(): array
    {
        $user = $this->comment->user;
        return [
            'id'          => $this->comment->id,
            'parent_id'   => $this->comment->parent_id,
            'type'        => $this->comment->type,
            'body'        => $this->comment->body,
            'plain_body'  => $this->comment->plain_body,
            'is_internal' => $this->comment->is_internal,
            'mentions'    => $this->comment->mentions,
            'edited_at'   => $this->comment->edited_at?->toIso8601String(),
            'created_at'  => $this->comment->created_at->toIso8601String(),
            'user'        => $user ? [
                'id'       => $user->id,
                'name'     => trim("{$user->first_name} {$user->last_name}"),
                'initials' => strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)),
            ] : null,
            'replies'     => [],
        ];
    }
}