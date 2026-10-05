<?php

namespace App\Services;

use App\Events\ChannelMessageSent;
use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Posting a message into a channel — the one path every sender uses.
 *
 * Lifted out of ChannelController::sendMessage so that a tailor's "Add note"
 * on My Tasks lands in the order's thread as an ordinary message, with the
 * same mentions, broadcast and member notifications as one typed in the chat,
 * instead of a second, parallel note store that nobody reads.
 *
 * Callers own the access check (membership, record visibility) and input
 * validation; this only writes and fans out.
 */
final class ChannelPosting
{
    public static function post(Channel $channel, User $poster, string $body, ?int $replyToId = null): ChannelMessage
    {
        $mentionedIds   = ChannelMessage::parseMentions($body);
        $linkedEntities = ChannelMessage::parseLinkedEntities($body);

        $message = ChannelMessage::create([
            'channel_id'      => $channel->id,
            'user_id'         => $poster->id,
            'reply_to_id'     => $replyToId,
            'type'            => 'text',
            'body'            => $body,
            'mentions'        => $mentionedIds,
            'linked_entities' => $linkedEntities ?: null,
        ]);

        // Update channel last activity
        $channel->update([
            'last_message_id'  => $message->id,
            'last_activity_at' => now(),
        ]);

        $message->load('user:id,first_name,last_name', 'replyTo.user:id,first_name,last_name');

        // Broadcast to channel members via Reverb (real-time).
        // Wrapped in try/catch - if Reverb is unreachable the message is
        // still saved to DB and clients receive it on their next poll.
        try {
            broadcast(new ChannelMessageSent($message));
        } catch (\Exception $e) {
            Log::warning('Reverb broadcast failed: ' . $e->getMessage());
        }

        // ── Notify @mentioned users + offline members ─────────────────────────
        try {
            $posterName  = trim("{$poster->first_name} {$poster->last_name}");
            $channelName = $channel->name ?? 'a conversation';
            $preview     = mb_substr(strip_tags($body), 0, 100);

            $notified = collect($mentionedIds);

            foreach ($mentionedIds as $uid) {
                if ($uid === $poster->id) continue;
                NotificationService::channelMention(
                    $uid, $posterName, $channelName, $preview,
                    "/comms/{$channel->id}", $message->id
                );
            }

            // Notify other channel members who weren't mentioned
            $memberIds = DB::table('channel_members')
                ->where('channel_id', $channel->id)
                ->where('user_id', '!=', $poster->id)
                ->whereNotIn('user_id', $notified->toArray())
                ->pluck('user_id');

            foreach ($memberIds as $uid) {
                NotificationService::channelMessage(
                    $uid, $posterName, $channelName, $preview,
                    "/comms/{$channel->id}", $message->id
                );
            }
        } catch (\Exception $e) {
            Log::warning('Channel notification failed: ' . $e->getMessage());
        }

        return $message;
    }
}
