<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelMessage;
use App\Models\User;
use App\Events\ChannelMessageSent;
use App\Events\ChannelReactionUpdated;
use App\Services\NotificationService;
use App\Services\IntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ChannelController - Phase 3 DMs and Spaces.
 *
 * Routes (all under /api/v1/admin/channels):
 *
 *   GET    /channels                         list my channels with unread counts
 *   POST   /channels                         create a space
 *   GET    /channels/{id}                    channel detail + members
 *   PATCH  /channels/{id}                    update space name/description
 *   DELETE /channels/{id}                    archive a space (admin only)
 *   POST   /channels/dm                      open or find a DM with a user
 *   POST   /channels/{id}/members            add member to space
 *   DELETE /channels/{id}/members/{userId}   remove member from space
 *   GET    /channels/{id}/messages           paginated message history
 *   POST   /channels/{id}/messages           send a message
 *   PATCH  /channels/{id}/messages/{msgId}   edit own message (10 min window)
 *   DELETE /channels/{id}/messages/{msgId}   soft-delete own message
 *   POST   /channels/{id}/messages/{msgId}/react  add/remove reaction
 *   POST   /channels/{id}/read              mark channel as read
 */
class ChannelController extends Controller
{
    // ── GET /channels ─────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user = $request->user();

        $channels = Channel::whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->with([
                'members:id,first_name,last_name',
                'lastMessage.user:id,first_name,last_name',
            ])
            ->orderByDesc('last_activity_at')
            ->get()
            ->filter(function ($channel) use ($user) {
                // Only order/production-order context channels can be
                // dismissed — DMs and manually-created Spaces are unaffected,
                // since "stale order chat cleanup" is specifically about the
                // auto-created context threads that accumulate over time.
                if ($channel->context_type === null) {
                    return true;
                }

                $dismissedAt = DB::table('channel_members')
                    ->where('channel_id', $channel->id)
                    ->where('user_id', $user->id)
                    ->value('dismissed_at');

                if (!$dismissedAt) {
                    return true;
                }

                // A dismissal auto-clears once a new message has landed after
                // it — this is the "until a new message is sent" behaviour.
                // last_activity_at is bumped by sendMessage() on every new
                // message, so comparing the two timestamps is sufficient;
                // no write is needed anywhere on the send path for this.
                $lastActivity = $channel->last_activity_at;
                return $lastActivity && $lastActivity->gt($dismissedAt);
            })
            ->map(function ($channel) use ($user) {
                $unread = $channel->unreadCountFor($user->id);
                $data   = $this->formatChannel($channel, $user->id);
                $data['unread_count'] = $unread;
                return $data;
            })
            ->values();

        return response()->json(['channels' => $channels]);
    }

    // ── POST /channels - create a Space ──────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:80',
            'description' => 'nullable|string|max:500',
            'is_private'  => 'sometimes|boolean',
            'member_ids'  => 'sometimes|array',
            'member_ids.*'=> 'integer|exists:users,id',
        ]);

        $slug = Str::slug($validated['name']) . '-' . Str::random(4);

        $channel = Channel::create([
            'type'        => 'space',
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'slug'        => $slug,
            'is_private'  => $validated['is_private'] ?? false,
            'created_by'  => $request->user()->id,
        ]);

        // Creator is always an admin of the space
        $members = [$request->user()->id => ['role' => 'admin']];
        foreach ($validated['member_ids'] ?? [] as $uid) {
            if ($uid !== $request->user()->id) {
                $members[$uid] = ['role' => 'member'];
            }
        }
        $channel->members()->attach($members);

        // Post a system message
        $this->postSystemMessage($channel->id, "{$request->user()->first_name} created this space.");

        $channel->load('members:id,first_name,last_name');

        return response()->json(['channel' => $this->formatChannel($channel, $request->user()->id)], 201);
    }

    // ── GET /channels/{id} ────────────────────────────────────────────────────

    public function show(Request $request, $id)
    {
        $channel = $this->findAccessible($id, $request->user()->id);
        $channel->load('members:id,first_name,last_name', 'creator:id,first_name,last_name');
        return response()->json(['channel' => $this->formatChannel($channel, $request->user()->id, true)]);
    }

    // ── PATCH /channels/{id} ─────────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $channel = $this->findAccessible($id, $request->user()->id);
        $this->requireChannelAdmin($channel, $request->user()->id);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:80',
            'description' => 'sometimes|nullable|string|max:500',
            'is_private'  => 'sometimes|boolean',
        ]);

        $channel->update($validated);
        return response()->json(['channel' => $this->formatChannel($channel->fresh(), $request->user()->id)]);
    }

    // ── DELETE /channels/{id} ─────────────────────────────────────────────────

    public function destroy(Request $request, $id)
    {
        $channel = $this->findAccessible($id, $request->user()->id);
        $this->requireChannelAdmin($channel, $request->user()->id);

        $channel->delete();
        return response()->json(['message' => 'Space archived.']);
    }

    // ── POST /channels/dm ─────────────────────────────────────────────────────

    public function openDm(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        if ($validated['user_id'] === $request->user()->id) {
            return response()->json(['message' => 'Cannot open a DM with yourself.'], 422);
        }

        $channel = Channel::findOrCreateDm($request->user()->id, $validated['user_id']);
        $channel->load('members:id,first_name,last_name');

        return response()->json(['channel' => $this->formatChannel($channel, $request->user()->id)]);
    }

    // ── POST /channels/{id}/members ───────────────────────────────────────────

    public function addMember(Request $request, $id)
    {
        $channel = $this->findAccessible($id, $request->user()->id);
        $this->requireChannelAdmin($channel, $request->user()->id);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'role'    => 'sometimes|in:member,admin',
        ]);

        if ($channel->type === 'dm') {
            return response()->json(['message' => 'Cannot add members to a DM.'], 422);
        }

        $channel->members()->syncWithoutDetaching([
            $validated['user_id'] => ['role' => $validated['role'] ?? 'member'],
        ]);

        $newUser = User::find($validated['user_id']);
        $this->postSystemMessage($channel->id, "{$newUser->first_name} was added to this space.");

        return response()->json(['message' => 'Member added.']);
    }

    // ── DELETE /channels/{id}/members/{userId} ────────────────────────────────

    public function removeMember(Request $request, $id, $userId)
    {
        $channel = $this->findAccessible($id, $request->user()->id);

        // Can remove yourself, or channel admin can remove others
        if ((int) $userId !== $request->user()->id) {
            $this->requireChannelAdmin($channel, $request->user()->id);
        }

        $channel->members()->detach($userId);

        $removedUser = User::find($userId);
        $name = $removedUser ? $removedUser->first_name : 'A member';
        $this->postSystemMessage($channel->id, "{$name} left this space.");

        return response()->json(['message' => 'Member removed.']);
    }

    // ── GET /channels/{id}/messages ───────────────────────────────────────────

    public function messages(Request $request, $id)
    {
        $this->findAccessible($id, $request->user()->id);

        $perPage = min((int) ($request->get('per_page', 50)), 100);
        $before  = $request->get('before'); // cursor - message ID for pagination

        $query = ChannelMessage::with('user:id,first_name,last_name', 'replyTo.user:id,first_name,last_name')
            ->where('channel_id', $id)
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('created_at')
            ->limit($perPage);

        $messages = $query->get()->reverse()->values()->map(fn ($m) => $this->formatMessage($m));

        return response()->json([
            'messages'   => $messages,
            'has_more'   => $messages->count() === $perPage,
            'oldest_id'  => $messages->first() ? $messages->first()['id'] : null,
        ]);
    }

    // ── POST /channels/{id}/messages ─────────────────────────────────────────

    public function sendMessage(Request $request, $id)
    {
        $channel = $this->findAccessible($id, $request->user()->id);

        $validated = $request->validate([
            'body'        => 'required|string|max:10000',
            'reply_to_id' => 'sometimes|nullable|integer|exists:channel_messages,id',
        ]);

        $mentionedIds   = ChannelMessage::parseMentions($validated['body']);
        $linkedEntities = ChannelMessage::parseLinkedEntities($validated['body']);

        $message = ChannelMessage::create([
            'channel_id'     => $channel->id,
            'user_id'        => $request->user()->id,
            'reply_to_id'    => $validated['reply_to_id'] ?? null,
            'type'           => 'text',
            'body'           => $validated['body'],
            'mentions'       => $mentionedIds,
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
            \Illuminate\Support\Facades\Log::warning('Reverb broadcast failed: ' . $e->getMessage());
        }

        // ── Notify @mentioned users + offline members ─────────────────────────
        try {
            $poster     = $request->user();
            $posterName = trim("{$poster->first_name} {$poster->last_name}");
            $channelName = $channel->name ?? 'a conversation';
            $preview    = mb_substr(strip_tags($validated['body']), 0, 100);

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
            \Illuminate\Support\Facades\Log::warning('Channel notification failed: ' . $e->getMessage());
        }

        return response()->json(['message' => $this->formatMessage($message)], 201);
    }

    // ── PATCH /channels/{id}/messages/{msgId} ────────────────────────────────

    public function editMessage(Request $request, $channelId, $msgId)
    {
        $this->findAccessible($channelId, $request->user()->id);
        $message = ChannelMessage::findOrFail($msgId);

        if ($message->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only edit your own messages.'], 403);
        }
        if ($message->created_at->diffInMinutes(now()) > 10) {
            return response()->json(['message' => 'Messages can only be edited within 10 minutes.'], 422);
        }

        $validated = $request->validate(['body' => 'required|string|max:10000']);

        $message->update([
            'body'            => $validated['body'],
            'mentions'        => ChannelMessage::parseMentions($validated['body']),
            'linked_entities' => ChannelMessage::parseLinkedEntities($validated['body']) ?: null,
            'edited_at'       => now(),
        ]);

        return response()->json(['message' => $this->formatMessage($message->fresh()->load('user:id,first_name,last_name'))]);
    }

    // ── DELETE /channels/{id}/messages/{msgId} ────────────────────────────────

    public function deleteMessage(Request $request, $channelId, $msgId)
    {
        $this->findAccessible($channelId, $request->user()->id);
        $message = ChannelMessage::findOrFail($msgId);

        $isAdmin = $request->user()->hasRole(['admin', 'super_admin']);
        if ($message->user_id !== $request->user()->id && !$isAdmin) {
            return response()->json(['message' => 'You cannot delete this message.'], 403);
        }

        $message->delete();
        return response()->json(['message' => 'Message deleted.']);
    }

    // ── POST /channels/{id}/messages/{msgId}/react ────────────────────────────

    public function react(Request $request, $channelId, $msgId)
    {
        $this->findAccessible($channelId, $request->user()->id);
        $message = ChannelMessage::findOrFail($msgId);

        $validated = $request->validate(['emoji' => 'required|string|max:8']);

        $reactions = $message->reactions ?? [];
        $emoji     = $validated['emoji'];
        $userId    = $request->user()->id;

        if (!isset($reactions[$emoji])) {
            $reactions[$emoji] = [];
        }

        $idx = array_search($userId, $reactions[$emoji]);
        if ($idx !== false) {
            array_splice($reactions[$emoji], $idx, 1); // toggle off
            if (empty($reactions[$emoji])) unset($reactions[$emoji]);
        } else {
            $reactions[$emoji][] = $userId;             // toggle on
        }

        $message->update(['reactions' => $reactions]);

        // Broadcast to all channel members so every open tab updates in real-time.
        // Wrapped in try/catch — if Reverb is unreachable the DB write already
        // succeeded and the reactor sees the change via optimistic update anyway.
        try {
            broadcast(new ChannelReactionUpdated($message));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Reverb reaction broadcast failed: ' . $e->getMessage());
        }

        return response()->json([
            'message_id' => $message->id,
            'reactions'  => $reactions,
        ]);
    }

    // ── POST /channels/{id}/read ──────────────────────────────────────────────

    public function markRead(Request $request, $id)
    {
        $this->findAccessible($id, $request->user()->id);

        $lastMessageId = ChannelMessage::where('channel_id', $id)
            ->orderByDesc('id')
            ->value('id');

        DB::table('channel_members')
            ->where('channel_id', $id)
            ->where('user_id', $request->user()->id)
            ->update(['last_read_message_id' => $lastMessageId]);

        return response()->json(['message' => 'Marked as read.']);
    }

    // ── POST /channels/{id}/dismiss ───────────────────────────────────────────
    //
    // Hides an order/production-order thread from the current user's sidebar
    // until a new message arrives. Per-user only — does not affect other
    // members, and does not delete or archive anything (compare with
    // destroy() above, which is an admin-only hard-archive of a whole Space).

    public function dismiss(Request $request, $id)
    {
        $channel = $this->findAccessible($id, $request->user()->id);

        if ($channel->context_type === null) {
            return response()->json([
                'message' => 'Only order threads can be dismissed. DMs and Spaces stay in your sidebar.',
            ], 422);
        }

        DB::table('channel_members')
            ->where('channel_id', $channel->id)
            ->where('user_id', $request->user()->id)
            ->update(['dismissed_at' => now()]);

        return response()->json(['message' => 'Thread dismissed.']);
    }

    // ── POST /channels/{id}/undismiss ─────────────────────────────────────────
    //
    // Manual "Undo" — restores a thread immediately, without waiting for a
    // new message. Safe to call even if the thread was never dismissed, or
    // has already auto-cleared (just a harmless no-op update either way).

    public function undismiss(Request $request, $id)
    {
        $channel = $this->findAccessible($id, $request->user()->id);

        DB::table('channel_members')
            ->where('channel_id', $channel->id)
            ->where('user_id', $request->user()->id)
            ->update(['dismissed_at' => null]);

        return response()->json(['message' => 'Thread restored.']);
    }


    // ── POST /channels/attachments ────────────────────────────────────────────────

    public function uploadAttachment(Request $request)
    {
        $request->validate([
            'file' => [
                'required', 'file', 'max:10240',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,application/pdf,' .
                    'application/msword,' .
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document,' .
                    'application/vnd.ms-excel,' .
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,' .
                    'text/plain,text/csv',
            ],
        ]);

        $file      = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $filename  = Str::uuid() . '.' . $extension;
        $path      = 'channel-attachments/' . now()->format('Y/m') . '/' . $filename;

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $isImage = str_starts_with($file->getMimeType(), 'image/');

        return response()->json([
            'path'      => $path,
            'name'      => $file->getClientOriginalName(),
            'size'      => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'is_image'  => $isImage,
            'url'       => url('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path)),
        ], 201);
    }

    // ── GET /channels/attachments/serve?path= ─────────────────────────────────────

    public function serveAttachment(Request $request)
    {
        $path = $request->get('path', '');

        if (!$path || !str_starts_with($path, 'channel-attachments/')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $content  = Storage::disk('local')->get($path);
        $mimeType = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';
        $filename = basename($path);

        return response($content, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Cache-Control', 'private, max-age=3600');
    }

    // ── POST /channels/context ────────────────────────────────────────────────
    //
    // Find or create a context-scoped channel for a given entity.
    // Auto-joins the requesting user as a member.
    // Returns the channel in the same format as GET /channels/{id}.

    public function findOrCreateContext(Request $request)
    {
        $validated = $request->validate([
            'context_type' => 'required|in:production_order,order',
            'context_id'   => 'required|integer|min:1',
        ]);

        $user = $request->user();
        $type = $validated['context_type'];
        $id   = (int) $validated['context_id'];

        // Build a rich name and description so the sidebar item has enough context
        // without the user having to open the channel.
        $name        = '';
        $description = null;

        if ($type === 'production_order') {
            $po   = \App\Models\ProductionOrder::with(['product.translations' => fn($q) => $q->where('language_code', 'en')])
                        ->find($id);
            $num  = $po?->order_number ?? $id;
            $name = 'PRD · ' . $num;

            // Subtitle: product name · status · due date
            $productName = $po?->product?->translations?->first()?->name ?? null;
            $status      = $po ? ucfirst(str_replace('_', ' ', $po->status)) : null;
            $due         = $po?->due_date ? \Carbon\Carbon::parse($po->due_date)->format('d M Y') : null;

            $parts = array_filter([$productName, $status, $due ? "Due {$due}" : null]);
            $description = $parts ? implode(' · ', $parts) : null;

        } elseif ($type === 'order') {
            $order = \App\Models\Order::find($id);
            $num   = $order?->order_number ?? $id;
            $name  = 'Order · ' . $num;

            // Subtitle: customer name · status · total
            $customer = $order
                ? trim(($order->customer_first_name ?? '') . ' ' . ($order->customer_last_name ?? ''))
                : null;
            $status = $order ? ucfirst(str_replace('_', ' ', $order->status)) : null;
            $total  = $order ? ($order->currency_code ?? 'KES') . ' ' . number_format($order->total_amount, 0) : null;

            $parts = array_filter([$customer ?: null, $status, $total]);
            $description = $parts ? implode(' · ', $parts) : null;

        } else {
            $name = ucfirst(str_replace('_', ' ', $type)) . ' · ' . $id;
        }

        // Channel::findOrCreateContext is SELECT-first and handles concurrent
        // creation via UniqueConstraintViolationException — no outer DB::transaction()
        // needed. The transaction wrapper was causing PostgreSQL 25P02 ("current
        // transaction is aborted") because a failed INSERT inside a transaction
        // taints the entire block, making the subsequent SELECT also fail.
        $channel = Channel::findOrCreateContext($type, $id, $name, $user->id);

        // syncWithoutDetaching is idempotent — safe even if user is already a member.
        $channel->members()->syncWithoutDetaching([
            $user->id => ['role' => 'member'],
        ]);

        // Update description whenever it changes (e.g. status progresses after
        // the channel was first created) so the sidebar subtitle stays current.
        if ($description !== null && $channel->description !== $description) {
            $channel->update(['description' => $description]);
        }

        $channel->load('members:id,first_name,last_name');

        return response()->json(['channel' => $this->formatChannel($channel, $user->id)]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function findAccessible(int|string $id, int $userId): Channel
    {
        $channel = Channel::findOrFail($id);

        $isMember = DB::table('channel_members')
            ->where('channel_id', $channel->id)
            ->where('user_id', $userId)
            ->exists();

        if (!$isMember) {
            abort(403, 'You are not a member of this channel.');
        }

        return $channel;
    }

    private function requireChannelAdmin(Channel $channel, int $userId): void
    {
        $role = DB::table('channel_members')
            ->where('channel_id', $channel->id)
            ->where('user_id', $userId)
            ->value('role');

        // System admins can always manage channels
        $isSystemAdmin = User::find($userId)?->hasRole(['admin', 'super_admin']) ?? false;

        if ($role !== 'admin' && !$isSystemAdmin) {
            abort(403, 'Only channel admins can perform this action.');
        }
    }

    private function postSystemMessage(int $channelId, string $body): void
    {
        ChannelMessage::create([
            'channel_id' => $channelId,
            'user_id'    => null,
            'type'       => 'system',
            'body'       => $body,
        ]);
    }


    // ── GET /channels/entity-search ──────────────────────────────────────────
    //
    // Searches orders and production orders by query string.
    // Used by the # entity picker in the message composer.
    //
    // Query params:
    //   q      - search string (order number, product name, customer name)
    //   types  - array of entity types to include: order, production_order
    //            defaults to both when omitted
    //
    // Returns up to 8 results per type, merged and sorted by relevance.
    // A type is silently omitted from the results (not an error) if the
    // caller lacks the matching module permission - orders.view for
    // 'order', production.view for 'production_order' - so this endpoint
    // never exposes more than the user could already see elsewhere.

    public function entitySearch(Request $request)
    {
        $q     = trim($request->get('q', ''));
        $types = $request->get('types', ['order', 'production_order', 'eod_report']);
        if (is_string($types)) $types = explode(',', $types);

        $user    = $request->user();
        $results = [];

        // ── Sales orders ─────────────────────────────────────────────────────
        // Was reachable by any authenticated staff member regardless of
        // orders.view - with an empty query it listed the most recent
        // orders (customer names, totals, status) to anyone composing a
        // message. Gated the same way entity-previews next to this
        // endpoint already is: you can only search/browse what you'd
        // otherwise have visibility into.
        if (in_array('order', $types) && $user->can('orders.view')) {
            $query = \App\Models\Order::select('id', 'order_number', 'status', 'customer_first_name', 'customer_last_name', 'total_amount', 'currency_code')
                ->limit(8);

            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('order_number', 'ILIKE', "%{$q}%")
                      ->orWhere('customer_first_name', 'ILIKE', "%{$q}%")
                      ->orWhere('customer_last_name',  'ILIKE', "%{$q}%");
                });
            } else {
                $query->orderByDesc('created_at');
            }

            foreach ($query->get() as $order) {
                $results[] = [
                    'type'     => 'order',
                    'id'       => $order->id,
                    'label'    => '#' . $order->order_number,
                    'subtitle' => trim("{$order->customer_first_name} {$order->customer_last_name}"),
                    'status'   => $order->status,
                    'meta'     => $order->currency_code . ' ' . number_format($order->total_amount, 2),
                    'url'      => '/sales/orders/' . $order->id,
                ];
            }
        }

        // ── Production orders ─────────────────────────────────────────────────
        // Same scoping - production.view required, same reasoning as above.
        if (in_array('production_order', $types) && $user->can('production.view')) {
            $query = \App\Models\ProductionOrder::with([
                    'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
                ])
                ->select('id', 'order_number', 'status', 'priority', 'product_id', 'quantity')
                ->limit(8);

            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('order_number', 'ILIKE', "%{$q}%")
                      ->orWhereHas('product.translations', fn ($qt) =>
                          $qt->where('name', 'ILIKE', "%{$q}%")
                      );
                });
            } else {
                $query->orderByDesc('created_at');
            }

            foreach ($query->get() as $po) {
                $productName = $po->product?->translations?->first()?->name ?? 'Product';
                $results[] = [
                    'type'     => 'production_order',
                    'id'       => $po->id,
                    'label'    => '#' . $po->order_number,
                    'subtitle' => $productName,
                    'status'   => $po->status,
                    'meta'     => "Qty {$po->quantity} · {$po->priority}",
                    'url'      => '/production/orders/' . $po->id,
                ];
            }
        }

        // ── EoD reports ───────────────────────────────────────────────────────
        // So a day's report can be quoted into a channel and discussed where
        // people already are, instead of only on a page nobody visits. Gated on
        // settings.view, matching the report endpoints themselves — you can only
        // tag what you could already open.
        if (in_array('eod_report', $types) && $user->can('settings.view')) {
            $query = \Illuminate\Support\Facades\DB::table('cash_register_eod_reports as r')
                ->join('users as u',   'u.id', '=', 'r.user_id')
                ->join('outlets as o', 'o.id', '=', 'r.outlet_id')
                ->select([
                    'r.id', 'r.report_date', 'r.acknowledged_at', 'o.name as outlet_name',
                    \Illuminate\Support\Facades\DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as user_name"),
                ])
                ->limit(8);

            if ($q !== '') {
                $query->where(function ($w) use ($q) {
                    $w->where('u.first_name', 'ILIKE', "%{$q}%")
                      ->orWhere('u.last_name', 'ILIKE', "%{$q}%")
                      ->orWhere('o.name', 'ILIKE', "%{$q}%")
                      // Typing a date fragment is the natural way to reach for a
                      // day's report ("15 Jul" / "2026-07-15").
                      ->orWhere(\Illuminate\Support\Facades\DB::raw('r.report_date::text'), 'ILIKE', "%{$q}%");
                });
            }
            $query->orderByDesc('r.report_date');

            foreach ($query->get() as $r) {
                $first = explode(' ', trim($r->user_name))[0] ?: 'report';
                $results[] = [
                    'type'     => 'eod_report',
                    'id'       => $r->id,
                    'label'    => '#EOD-' . date('dMy', strtotime($r->report_date)) . '-' . $first,
                    'subtitle' => trim($r->user_name) . ' · ' . $r->outlet_name,
                    'status'   => $r->acknowledged_at ? 'read' : 'unread',
                    'meta'     => date('D, d M Y', strtotime($r->report_date)),
                    'url'      => '/pos/eod-reports?report=' . $r->id,
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    private function formatChannel(Channel $channel, int $currentUserId, bool $withMembers = false): array
    {
        $members = $channel->members ?? collect();

        // For DMs, the "name" is the other person's name
        $displayName = $channel->name;
        if ($channel->type === 'dm') {
            $other = $members->firstWhere('id', '!=', $currentUserId);
            $displayName = $other
                ? trim("{$other->first_name} {$other->last_name}")
                : 'Direct Message';
        }

        $lastMsg = $channel->lastMessage;
        $lastUser = $lastMsg?->user;

        // Plain-text preview for sidebar: strip markdown tokens before truncating
        $previewBody = null;
        if ($lastMsg) {
            $previewBody = $lastMsg->body;
            $previewBody = preg_replace('/!\[([^\]]*)\]\([^)]+\)/', '$1', $previewBody); // images
            $previewBody = preg_replace('/\[📎\s*([^\]]+)\]\([^)]+\)/', '$1', $previewBody); // files
            $previewBody = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $previewBody);      // any link
            $previewBody = preg_replace('/@\[([^\]]+)\]\(user:\d+\)/', '@$1', $previewBody); // mentions
            $previewBody = trim(preg_replace('/\s+/', ' ', $previewBody));
            $previewBody = mb_substr($previewBody, 0, 100);
        }

        $myPivot = $members->firstWhere('id', $currentUserId);

        return [
            'id'             => $channel->id,
            'type'           => $channel->type,
            'name'           => $displayName,
            'description'    => $channel->description,
            'slug'           => $channel->slug,
            'is_private'     => $channel->is_private,
            'context_type'   => $channel->context_type,
            'context_id'     => $channel->context_id,
            // Null when not dismissed, or once a new message has auto-cleared
            // a prior dismissal (index() already excludes those entirely, but
            // this is also returned by show()/dismiss()/undismiss() directly,
            // so the frontend has a single source of truth without
            // re-deriving it). $members is currently always loaded with
            // 'id,first_name,last_name' selects, but pivot attributes are
            // always included by Eloquent regardless of the parent model's
            // own column selection, so ->pivot->dismissed_at is available here.
            'dismissed_at'   => $myPivot?->pivot?->dismissed_at
                ? \Illuminate\Support\Carbon::parse($myPivot->pivot->dismissed_at)->toIso8601String()
                : null,
            'last_activity_at' => $channel->last_activity_at?->toIso8601String(),
            'last_message'   => $lastMsg ? [
                'id'         => $lastMsg->id,
                'body'       => $previewBody,
                'user_name'  => $lastUser ? trim("{$lastUser->first_name} {$lastUser->last_name}") : null,
                'created_at' => $lastMsg->created_at->toIso8601String(),
            ] : null,
            'members'        => $withMembers ? $members->map(fn ($u) => [
                'id'       => $u->id,
                'name'     => trim("{$u->first_name} {$u->last_name}"),
                'initials' => strtoupper(substr($u->first_name, 0, 1) . substr($u->last_name, 0, 1)),
                'role'     => $u->pivot->role ?? 'member',
            ])->values() : $members->count(),
        ];
    }

    private function formatMessage(ChannelMessage $message): array
    {
        $user  = $message->user;
        $reply = $message->replyTo;

        return [
            'id'          => $message->id,
            'channel_id'  => $message->channel_id,
            'reply_to_id' => $message->reply_to_id,
            'reply_to'    => $reply ? [
                'id'        => $reply->id,
                'body'      => mb_substr($reply->body, 0, 80),
                'user_name' => $reply->user ? trim("{$reply->user->first_name} {$reply->user->last_name}") : null,
            ] : null,
            'type'        => $message->type,
            'body'        => $message->body,
            'mentions'        => $message->mentions,
            'linked_entities'  => $message->linked_entities ?? [],
            'entity_previews'  => !empty($message->linked_entities)
                ? IntelligenceService::entityChipPreviews($message->linked_entities)
                : [],
            'attachments'      => $message->attachments,
            'reactions'        => $message->reactions,
            'edited_at'   => $message->edited_at?->toIso8601String(),
            'created_at'  => $message->created_at->toIso8601String(),
            'user'        => $user ? [
                'id'       => $user->id,
                'name'     => trim("{$user->first_name} {$user->last_name}"),
                'initials' => strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)),
            ] : null,
        ];
    }
}