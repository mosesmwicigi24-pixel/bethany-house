<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CommentController - Phase 1 unified comment threads.
 *
 * Routes (registered under /api/v1/admin/comments):
 *
 *   GET    /comments?model=Order&id=42        list thread for a model
 *   POST   /comments                          post a new comment
 *   PATCH  /comments/{id}                     edit own comment (body only, within 10 min)
 *   DELETE /comments/{id}                     soft-delete own comment (or admin)
 *
 * Supported models:
 *   Order, ProductionOrder, PurchaseOrder, GoodsReceivedNote,
 *   PurchaseReturn, OrderReturn, InventoryTransfer, StockAdjustment
 *
 * @mention syntax: @[Display Name](user:42)
 *   Parsed server-side; mentioned users and existing thread participants
 *   receive in-app notifications via NotificationService.
 */
class CommentController extends Controller
{
    // Map of short model keys → fully-qualified class names
    private const MODEL_MAP = [
        'Order'              => \App\Models\Order::class,
        'ProductionOrder'    => \App\Models\ProductionOrder::class,
        'PurchaseOrder'      => \App\Models\PurchaseOrder::class,
        'GoodsReceivedNote'  => \App\Models\GoodsReceivedNote::class,
        'PurchaseReturn'     => \App\Models\PurchaseReturn::class,
        'OrderReturn'        => \App\Models\OrderReturn::class,
        'InventoryTransfer'  => \App\Models\InventoryTransfer::class,
    ];

    // ─── GET /comments?model=Order&id=42 ─────────────────────────────────────

    public function index(Request $request)
    {
        $request->validate([
            'model' => 'required|string|in:' . implode(',', array_keys(self::MODEL_MAP)),
            'id'    => 'required|integer',
        ]);

        $modelClass = self::MODEL_MAP[$request->model];

        $comments = Comment::with('user:id,first_name,last_name')
            ->where('commentable_type', $modelClass)
            ->where('commentable_id', $request->id)
            ->whereNull('parent_id')        // top-level only; replies nested below
            ->with(['replies.user:id,first_name,last_name'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($c) => $this->formatComment($c, true));

        return response()->json(['comments' => $comments]);
    }

    // ─── POST /comments ───────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'model'       => 'required|string|in:' . implode(',', array_keys(self::MODEL_MAP)),
            'id'          => 'required|integer',
            'body'        => 'required|string|max:5000',
            'type'        => 'sometimes|in:comment,note',
            'is_internal' => 'sometimes|boolean',
            'parent_id'   => 'sometimes|nullable|integer|exists:comments,id',
        ]);

        $modelClass = self::MODEL_MAP[$validated['model']];

        // Verify the model exists
        $modelInstance = $modelClass::findOrFail($validated['id']);

        $mentionedIds = Comment::parseMentions($validated['body']);

        $comment = Comment::create([
            'commentable_type' => $modelClass,
            'commentable_id'   => $validated['id'],
            'user_id'          => $request->user()->id,
            'parent_id'        => $validated['parent_id'] ?? null,
            'type'             => $validated['type'] ?? 'comment',
            'body'             => $validated['body'],
            'is_internal'      => $validated['is_internal'] ?? true,
            'mentions'         => $mentionedIds,
        ]);

        // ── Notifications ─────────────────────────────────────────────────────

        try {
            $poster       = $request->user();
            $posterName   = trim("{$poster->first_name} {$poster->last_name}");
            $modelLabel   = $this->modelLabel($validated['model'], $modelInstance);
            $actionUrl    = $this->actionUrl($validated['model'], $validated['id']);
            $bodyPreview  = mb_substr(strip_tags($validated['body']), 0, 120);

            // 1. Notify @mentioned users
            $notified = collect($mentionedIds);
            foreach ($mentionedIds as $uid) {
                if ($uid === $poster->id) continue;
                NotificationService::commentMention(
                    $uid, $posterName, $modelLabel, $bodyPreview, $actionUrl,
                    $comment->id
                );
            }

            // 2. Notify other thread participants (subscribers) - everyone who
            //    previously commented on this thread, excluding the poster and
            //    already-notified @mentioned users.
            $subscribers = Comment::where('commentable_type', $modelClass)
                ->where('commentable_id', $validated['id'])
                ->where('user_id', '!=', $poster->id)
                ->whereNotNull('user_id')
                ->whereNotIn('user_id', $notified->toArray())
                ->distinct()
                ->pluck('user_id');

            foreach ($subscribers as $uid) {
                NotificationService::commentReply(
                    $uid, $posterName, $modelLabel, $bodyPreview, $actionUrl,
                    $comment->id
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Comment notification failed: ' . $e->getMessage());
        }

        // ── Activity log ──────────────────────────────────────────────────────
        try {
            ActivityLogService::log('comment_posted', $modelInstance, [
                'comment_id'   => $comment->id,
                'model'        => $validated['model'],
                'model_id'     => $validated['id'],
                'body_preview' => mb_substr($validated['body'], 0, 80),
                'mentions'     => $mentionedIds,
            ]);
        } catch (\Exception) {}

        $comment->load('user:id,first_name,last_name');

        return response()->json([
            'comment' => $this->formatComment($comment, false),
        ], 201);
    }

    // ─── PATCH /comments/{id} ────────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $comment = Comment::findOrFail($id);

        // Only the author can edit, and only within 10 minutes
        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only edit your own comments.'], 403);
        }

        if ($comment->created_at->diffInMinutes(now()) > 10) {
            return response()->json(['message' => 'Comments can only be edited within 10 minutes of posting.'], 422);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $mentionedIds = Comment::parseMentions($validated['body']);

        $comment->update([
            'body'      => $validated['body'],
            'mentions'  => $mentionedIds,
            'edited_at' => now(),
        ]);

        $comment->load('user:id,first_name,last_name');

        return response()->json(['comment' => $this->formatComment($comment, false)]);
    }

    // ─── DELETE /comments/{id} ───────────────────────────────────────────────

    public function destroy(Request $request, $id)
    {
        $comment = Comment::findOrFail($id);
        $user    = $request->user();

        // Author can always delete their own; admins/super_admins can delete any
        $isAdmin = $user->hasRole(['admin', 'super_admin']);
        if ($comment->user_id !== $user->id && !$isAdmin) {
            return response()->json(['message' => 'You do not have permission to delete this comment.'], 403);
        }

        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }

    // ─── GET /comments/users?q=john ── user search for @mention autocomplete ─

    public function users(Request $request)
    {
        $q = $request->get('q', '');

        $users = User::where('status', 'active')
            ->where(function ($query) use ($q) {
                $query->where('first_name', 'ilike', "%{$q}%")
                      ->orWhere('last_name', 'ilike', "%{$q}%")
                      ->orWhere('email', 'ilike', "%{$q}%");
            })
            ->whereHas('roles')         // only staff, not customers
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn ($u) => [
                'id'      => $u->id,
                'name'    => trim("{$u->first_name} {$u->last_name}"),
                'email'   => $u->email,
                'initials'=> strtoupper(substr($u->first_name, 0, 1) . substr($u->last_name, 0, 1)),
            ]);

        return response()->json(['users' => $users]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function formatComment(Comment $comment, bool $includeReplies = false): array
    {
        $user = $comment->user;
        $formatted = [
            'id'          => $comment->id,
            'parent_id'   => $comment->parent_id,
            'type'        => $comment->type,
            'body'        => $comment->body,
            'plain_body'  => $comment->plain_body,
            'is_internal' => $comment->is_internal,
            'mentions'    => $comment->mentions,
            'edited_at'   => $comment->edited_at?->toIso8601String(),
            'created_at'  => $comment->created_at->toIso8601String(),
            'user'        => $user ? [
                'id'       => $user->id,
                'name'     => trim("{$user->first_name} {$user->last_name}"),
                'initials' => strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)),
            ] : null,
        ];

        if ($includeReplies) {
            $formatted['replies'] = $comment->replies->map(
                fn ($r) => $this->formatComment($r, false)
            )->values()->toArray();
        }

        return $formatted;
    }

    private function modelLabel(string $model, $instance): string
    {
        return match ($model) {
            'Order'             => "Order #{$instance->order_number}",
            'ProductionOrder'   => "Production #{$instance->order_number}",
            'PurchaseOrder'     => "PO {$instance->po_number}",
            'GoodsReceivedNote' => "GRN {$instance->grn_number}",
            'PurchaseReturn'    => "Purchase Return #{$instance->return_number}",
            'OrderReturn'       => "Return #{$instance->return_number}",
            'InventoryTransfer' => "Transfer #{$instance->transfer_number}",
            default             => $model,
        };
    }

    private function actionUrl(string $model, int $id): string
    {
        return match ($model) {
            'Order'             => "/sales/orders/{$id}",
            'ProductionOrder'   => "/production/orders/{$id}",
            'PurchaseOrder'     => "/procurement/purchase-orders/{$id}",
            'GoodsReceivedNote' => "/procurement/goods-receipt/{$id}",
            'PurchaseReturn'    => "/procurement/returns/{$id}",
            'OrderReturn'       => "/sales/returns/{$id}",
            'InventoryTransfer' => "/inventory/transfers/{$id}",
            default             => "/",
        };
    }
}