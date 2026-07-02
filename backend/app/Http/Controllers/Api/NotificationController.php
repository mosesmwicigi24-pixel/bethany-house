<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 - In-App Notification Bell
 *
 * GET    /admin/notifications           - paginated list (newest first)
 * GET    /admin/notifications/unread-count - single integer for the badge
 * POST   /admin/notifications/{id}/read - mark one as read
 * POST   /admin/notifications/read-all  - mark all as read
 * DELETE /admin/notifications/{id}      - delete one
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = DB::table('notifications')
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id',   $user->id)
            ->orderBy('created_at', 'desc');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $paginated = $query->paginate((int) $request->get('per_page', 20));

        // Parse the JSON data column
        $paginated->getCollection()->transform(function ($n) {
            $payload        = json_decode($n->data, true) ?? [];
            $n->title       = $payload['title']      ?? $n->type;
            $n->body        = $payload['body']        ?? null;
            $n->action_url  = $payload['action_url']  ?? null;
            $n->icon        = $payload['icon']        ?? 'bell';
            $n->extra       = $payload['data']        ?? [];
            $n->is_read     = !is_null($n->read_at);
            return $n;
        });

        return response()->json([
            'data'         => $paginated->items(),
            'meta'         => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
            ],
            'unread_count' => DB::table('notifications')
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id',   $user->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $user  = $request->user();
        $count = DB::table('notifications')
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id',   $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request, string $id)
    {
        $user = $request->user();

        $updated = DB::table('notifications')
            ->where('id',              $id)
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id',   $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => $updated ? 'Marked as read.' : 'Already read or not found.',
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();

        $count = DB::table('notifications')
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id',   $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => "{$count} notification(s) marked as read.",
            'count'   => $count,
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();

        DB::table('notifications')
            ->where('id',              $id)
            ->where('notifiable_type', get_class($user))
            ->where('notifiable_id',   $user->id)
            ->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}