<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostRead;
use App\Models\PostRsvp;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Support\SignedFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The Updates feed (People C3): notices with a photo and a must-read
 * acknowledgement, shout-outs (recognitions), the hub's own birthday and
 * anniversary posts, and events with an RSVP. Every staff member reads
 * the feed and may post a recognition; `updates.post` posts the rest,
 * gives points, schedules, and sees who has read what.
 */
class UpdatesController extends Controller
{
    private const PAGE = 20;

    /** GET /updates?kind=&page= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate(['kind' => ['nullable', Rule::in(Post::KINDS)], 'page' => 'nullable|integer|min:1']);
        $q = Post::with(['author:id,first_name,last_name,avatar_url', 'recipient:id,first_name,last_name,avatar_url', 'outlet:id,name'])
            ->withCount(['comments', 'reads', 'rsvps as rsvp_yes_count' => fn ($r) => $r->where('answer', 'yes')])
            ->published()->visibleTo($user)->orderByDesc('published_at')->orderByDesc('id');
        if (!empty($v['kind'])) $q->where('kind', $v['kind']);
        $page = $q->paginate(self::PAGE, ['*'], 'page', (int) ($v['page'] ?? 1));
        $mine = $this->myStates($user, $page->getCollection()->pluck('id'));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Post $p) => $this->present($p, $user, $mine))->values(),
            'has_more' => $page->hasMorePages(), 'page' => $page->currentPage(),
            'must_read_pending' => $this->pendingMustReads($user),
            'can_post' => $user->can('updates.post'),
            'scheduled' => $user->can('updates.post') ? Post::whereNull('published_at')->whereNotNull('publish_at')->count() : 0,
        ]);
    }

    /** GET /updates/scheduled — what waits to go out (updates.post). */
    public function scheduled(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = Post::with(['author:id,first_name,last_name,avatar_url', 'recipient:id,first_name,last_name,avatar_url', 'outlet:id,name'])
            ->whereNull('published_at')->whereNotNull('publish_at')->orderBy('publish_at')->get();
        return response()->json(['data' => $rows->map(fn (Post $p) => $this->present($p, $user, collect()))->values()]);
    }

    /** GET /updates/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $post = $this->visible($user, $id);
        $post->load(['comments.user:id,first_name,last_name,avatar_url']);
        $post->loadCount(['comments', 'reads', 'rsvps as rsvp_yes_count' => fn ($r) => $r->where('answer', 'yes')]);
        $data = $this->present($post, $user, $this->myStates($user, collect([$post->id])));
        $data['comments'] = $post->comments->map(fn (PostComment $c) => ['id' => $c->id, 'body' => $c->body, 'at' => $c->created_at?->toIso8601String(), 'mine' => $c->user_id === $user->id,
            'user' => $c->user ? ['id' => $c->user->id, 'name' => trim("{$c->user->first_name} {$c->user->last_name}"), 'avatar_url' => $c->user->avatar_url] : null])->values();
        if ($this->mayManage($user, $post)) $data['readers'] = $this->readers($post);

        return response()->json(['post' => $data]);
    }

    /** POST /updates — multipart (photo) or JSON. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'kind'             => ['required', Rule::in(['update', 'recognition', 'event'])],
            'title'            => 'nullable|string|max:140',
            'body'             => 'nullable|string|max:5000',
            'outlet_id'        => 'nullable|integer|exists:outlets,id',
            'must_read'        => 'sometimes|boolean',
            'comments_enabled' => 'sometimes|boolean',
            'publish_at'       => 'nullable|date',
            'event_at'         => 'nullable|date',
            'event_place'      => 'nullable|string|max:120',
            'recipient_id'     => 'nullable|integer|exists:users,id',
            'points'           => 'nullable|integer|between:0,100',
            'photo'            => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);
        $canPost = $user->can('updates.post');
        if ($v['kind'] !== 'recognition' && !$canPost) abort(403, 'Posting updates and events needs the Post updates permission; anyone may post a recognition.');
        if ($v['kind'] === 'recognition') {
            if (empty($v['recipient_id'])) return response()->json(['message' => 'Who are you recognising?', 'errors' => ['recipient_id' => ['Choose a person.']]], 422);
            if ((int) $v['recipient_id'] === $user->id) return response()->json(['message' => 'You cannot recognise yourself.'], 422);
            if (!empty($v['points']) && !$canPost) return response()->json(['message' => 'Points come from a manager; your shout-out goes without them.'], 422);
            if (trim((string) ($v['body'] ?? '')) === '') return response()->json(['message' => 'Say what they did.', 'errors' => ['body' => ['Say what they did.']]], 422);
        } else {
            if (trim((string) ($v['title'] ?? '')) === '') return response()->json(['message' => 'Give it a title.', 'errors' => ['title' => ['Give it a title.']]], 422);
            if ($v['kind'] === 'event' && empty($v['event_at'])) return response()->json(['message' => 'When is it?', 'errors' => ['event_at' => ['Set the date and time.']]], 422);
        }
        $publishAt = !empty($v['publish_at']) && $canPost ? \Carbon\Carbon::parse($v['publish_at']) : null;
        $post = Post::create([
            'kind' => $v['kind'], 'author_id' => $user->id, 'recipient_id' => $v['recipient_id'] ?? null, 'outlet_id' => $canPost ? ($v['outlet_id'] ?? null) : null,
            'title' => $v['title'] ?? null, 'body' => $v['body'] ?? null, 'must_read' => $canPost && $v['kind'] === 'update' && !empty($v['must_read']),
            'comments_enabled' => $v['comments_enabled'] ?? true, 'points' => $canPost && $v['kind'] === 'recognition' ? ($v['points'] ?? null) : null,
            'event_at' => $v['kind'] === 'event' ? $v['event_at'] : null, 'event_place' => $v['kind'] === 'event' ? ($v['event_place'] ?? null) : null,
            'publish_at' => $publishAt, 'published_at' => $publishAt && $publishAt->isFuture() ? null : now(),
        ]);
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $path = 'updates/' . now()->format('Y/m') . '/' . $post->id . '-' . Str::random(12) . '.' . strtolower($file->getClientOriginalExtension() ?: 'jpg');
            Storage::disk('private')->put($path, file_get_contents($file->getRealPath()));
            $post->forceFill(['photo_path' => $path])->save();
        }
        ActivityLogService::log('update_posted', $post, ['kind' => $post->kind, 'must_read' => $post->must_read, 'points' => $post->points, 'outlet_id' => $post->outlet_id, 'scheduled' => (bool) ($post->publish_at && !$post->published_at)],
            "{$user->first_name} posted " . ($post->kind === 'recognition' ? 'a recognition' : ($post->must_read ? 'a must-read' : "a{$this->n($post->kind)} {$post->kind}")) . ($post->title ? ": {$post->title}" : ''));
        if ($post->published_at) self::notify($post);

        return response()->json(['message' => $post->published_at ? 'Posted.' : 'Scheduled for ' . $post->publish_at->format('D j M H:i') . '.', 'post' => $this->present($post->fresh(['author', 'recipient', 'outlet']), $user, collect())], 201);
    }

    /** PUT /updates/{id} — author or updates.post: words, flags, timing. */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $post = Post::findOrFail($id);
        abort_unless($this->mayManage($user, $post), 403);
        $v = $request->validate([
            'title' => 'sometimes|nullable|string|max:140', 'body' => 'sometimes|nullable|string|max:5000',
            'must_read' => 'sometimes|boolean', 'comments_enabled' => 'sometimes|boolean',
            'publish_at' => 'sometimes|nullable|date', 'event_at' => 'sometimes|nullable|date', 'event_place' => 'sometimes|nullable|string|max:120',
        ]);
        if (array_key_exists('publish_at', $v)) {
            if ($post->published_at) unset($v['publish_at']);       // already out: timing is history
            else { $at = $v['publish_at'] ? \Carbon\Carbon::parse($v['publish_at']) : null; $v['publish_at'] = $at; if (!$at || !$at->isFuture()) { $v['published_at'] = now(); } }
        }
        if (!$user->can('updates.post')) unset($v['must_read']);
        $post->fill($v)->save();
        if ($post->published_at && !$post->notified_at) self::notify($post);
        ActivityLogService::log('update_edited', $post, ['changed' => array_keys($v)], "{$user->first_name} edited an update" . ($post->title ? ": {$post->title}" : ''));

        return response()->json(['message' => 'Saved.', 'post' => $this->present($post->fresh(['author', 'recipient', 'outlet']), $user, $this->myStates($user, collect([$post->id])))]);
    }

    /** DELETE /updates/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $post = Post::findOrFail($id);
        abort_unless($this->mayManage($user, $post), 403);
        $post->delete();
        ActivityLogService::log('update_removed', $post, ['kind' => $post->kind, 'must_read' => $post->must_read, 'points' => $post->points], "{$user->first_name} removed an update" . ($post->title ? ": {$post->title}" : ''));

        return response()->json(['message' => 'Removed.']);
    }

    /** POST /updates/{id}/read — opened it. */
    public function read(Request $request, int $id): JsonResponse
    {
        $post = $this->visible($request->user(), $id);
        PostRead::firstOrCreate(['post_id' => $post->id, 'user_id' => $request->user()->id], ['read_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** POST /updates/{id}/acknowledge — "I have read this" on a must-read. */
    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $post = $this->visible($request->user(), $id);
        abort_unless($post->must_read, 422, 'This post does not ask for an acknowledgement.');
        $r = PostRead::firstOrCreate(['post_id' => $post->id, 'user_id' => $request->user()->id], ['read_at' => now()]);
        if (!$r->acknowledged_at) $r->forceFill(['acknowledged_at' => now()])->save();
        return response()->json(['message' => 'Thank you.', 'acknowledged_at' => $r->acknowledged_at->toIso8601String(), 'must_read_pending' => $this->pendingMustReads($request->user())]);
    }

    /** POST /updates/{id}/comments {body} */
    public function comment(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $post = $this->visible($user, $id);
        abort_unless($post->comments_enabled, 422, 'Comments are off on this post.');
        $v = $request->validate(['body' => 'required|string|max:2000']);
        $c = PostComment::create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => trim($v['body'])]);
        PostRead::firstOrCreate(['post_id' => $post->id, 'user_id' => $user->id], ['read_at' => now()]);
        // The author hears about comments on their post; a recognised person about comments on theirs.
        $tell = User::whereIn('id', array_filter([$post->author_id, $post->recipient_id]))->where('status', 'active')->get()->reject(fn ($u) => $u->id === $user->id);
        if ($tell->isNotEmpty()) NotificationService::deliver($tell, new InAppNotification(title: "{$user->first_name} commented" . ($post->title ? " on \"{$post->title}\"" : ''), body: Str::limit($c->body, 120), actionUrl: "/updates/{$post->id}", icon: 'comment', data: ['post_id' => $post->id]));

        return response()->json(['message' => 'Posted.', 'comment' => ['id' => $c->id, 'body' => $c->body, 'at' => $c->created_at->toIso8601String(), 'mine' => true, 'user' => ['id' => $user->id, 'name' => trim("{$user->first_name} {$user->last_name}"), 'avatar_url' => $user->avatar_url]]], 201);
    }

    /** DELETE /updates/{id}/comments/{cid} — own, or updates.post. */
    public function removeComment(Request $request, int $id, int $cid): JsonResponse
    {
        $user = $request->user();
        $post = $this->visible($user, $id);
        $c = PostComment::where('post_id', $post->id)->findOrFail($cid);
        abort_unless($c->user_id === $user->id || $user->can('updates.post'), 403);
        $c->delete();
        return response()->json(['message' => 'Removed.']);
    }

    /** POST /updates/{id}/rsvp {answer} */
    public function rsvp(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $post = $this->visible($user, $id);
        abort_unless($post->kind === 'event', 422, 'Only an event takes an RSVP.');
        $v = $request->validate(['answer' => ['required', Rule::in(PostRsvp::ANSWERS)]]);
        PostRsvp::updateOrCreate(['post_id' => $post->id, 'user_id' => $user->id], ['answer' => $v['answer']]);
        PostRead::firstOrCreate(['post_id' => $post->id, 'user_id' => $user->id], ['read_at' => now()]);
        return response()->json(['message' => ['yes' => 'See you there.', 'no' => 'Noted.', 'maybe' => 'Noted — tell us when you know.'][$v['answer']], 'answer' => $v['answer'], 'rsvp' => $this->rsvpSummary($post)]);
    }

    /** GET /updates/{id}/readers — author or updates.post. */
    public function readersList(Request $request, int $id): JsonResponse
    {
        $post = Post::findOrFail($id);
        abort_unless($this->mayManage($request->user(), $post), 403);
        return response()->json(['readers' => $this->readers($post)]);
    }

    /** GET /updates/{id}/photo — a short-lived signed link. */
    public function photoLink(Request $request, int $id): JsonResponse
    {
        $post = $this->visible($request->user(), $id);
        abort_unless($post->photo_path, 404, 'No photo on this post.');
        return SignedFiles::issue($request, 'files.update-photo', ['post' => $post->id], basename($post->photo_path));
    }

    /** GET /updates/people — who can be recognised (active staff but me). */
    public function people(Request $request): JsonResponse
    {
        return response()->json(['data' => User::where('status', 'active')->where('user_type', 'staff')->where('id', '!=', $request->user()->id)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])->values()]);
    }

    // ── Publishing (shared with updates:publish and people:celebrations) ───

    /** Stamp notified_at and tell the audience; once per post. */
    public static function notify(Post $post): void
    {
        if ($post->notified_at) return;
        $post->forceFill(['notified_at' => now()])->save();
        $post->loadMissing(['author', 'recipient']);
        $who = trim("{$post->author?->first_name} {$post->author?->last_name}") ?: 'Bethany House';
        $url = "/updates/{$post->id}";
        if ($post->kind === 'recognition') {
            if ($post->recipient && $post->recipient->status === 'active') {
                NotificationService::deliver($post->recipient, new InAppNotification(title: "{$who} recognised you" . ($post->points ? " · {$post->points} point" . ($post->points === 1 ? '' : 's') : ''), body: Str::limit((string) $post->body, 140), actionUrl: $url, icon: 'star', data: ['post_id' => $post->id, 'kind' => 'recognition']));
            }
            return;
        }
        if ($post->kind === 'celebration') return;   // on the feed, not in everyone's pocket
        $audience = $post->audience()->reject(fn ($u) => $u->id === $post->author_id);
        $users = User::whereIn('id', $audience->pluck('id'))->get();
        if ($users->isEmpty()) return;
        $title = $post->kind === 'event'
            ? "Event: {$post->title}" . ($post->event_at ? ' · ' . $post->event_at->format('D j M H:i') : '')
            : ($post->must_read ? "Must read: {$post->title}" : (string) $post->title);
        NotificationService::deliver($users, new InAppNotification(title: $title, body: Str::limit((string) $post->body, 140) ?: "Posted by {$who}", actionUrl: $url, icon: $post->kind === 'event' ? 'calendar' : 'megaphone', data: ['post_id' => $post->id, 'kind' => $post->kind, 'must_read' => $post->must_read]));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function visible(User $user, int $id): Post
    {
        $post = Post::published()->visibleTo($user)->find($id) ?? ($user->can('updates.post') ? Post::find($id) : null);
        abort_unless($post, 404, 'No such post.');
        return $post;
    }

    private function mayManage(User $user, Post $post): bool
    {
        return $post->author_id === $user->id || $user->can('updates.post');
    }

    private function myStates(User $user, \Illuminate\Support\Collection $ids): \Illuminate\Support\Collection
    {
        if ($ids->isEmpty()) return collect();
        $reads = PostRead::whereIn('post_id', $ids)->where('user_id', $user->id)->get()->keyBy('post_id');
        $rsvps = PostRsvp::whereIn('post_id', $ids)->where('user_id', $user->id)->pluck('answer', 'post_id');
        return $ids->mapWithKeys(fn ($id) => [$id => ['read_at' => $reads->get($id)?->read_at?->toIso8601String(), 'acknowledged_at' => $reads->get($id)?->acknowledged_at?->toIso8601String(), 'rsvp' => $rsvps->get($id)]]);
    }

    private function pendingMustReads(User $user): int
    {
        $acked = PostRead::where('user_id', $user->id)->whereNotNull('acknowledged_at')->pluck('post_id');
        return Post::published()->visibleTo($user)->where('must_read', true)->whereNotIn('id', $acked)
            ->where(fn ($q) => $q->whereNull('author_id')->orWhere('author_id', '!=', $user->id))->count();   // your own post needs no acknowledgement
    }

    private function rsvpSummary(Post $post): array
    {
        $rows = PostRsvp::where('post_id', $post->id)->get()->groupBy('answer')->map->count();
        return ['yes' => (int) ($rows['yes'] ?? 0), 'no' => (int) ($rows['no'] ?? 0), 'maybe' => (int) ($rows['maybe'] ?? 0)];
    }

    private function readers(Post $post): array
    {
        $audience = $post->audience();
        $reads = PostRead::with('user:id,first_name,last_name')->where('post_id', $post->id)->get()->keyBy('user_id');
        $rsvps = PostRsvp::where('post_id', $post->id)->pluck('answer', 'user_id');
        $name = fn ($u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")];
        $read = []; $acked = []; $pending = [];
        foreach ($audience as $u) {
            if ($u->id === $post->author_id) continue;
            $r = $reads->get($u->id);
            $row = $name($u) + ['read_at' => $r?->read_at?->toIso8601String(), 'acknowledged_at' => $r?->acknowledged_at?->toIso8601String(), 'rsvp' => $rsvps->get($u->id)];
            if (!$r) { $pending[] = $row; continue; }
            $read[] = $row;
            if ($post->must_read && $r->acknowledged_at) $acked[] = $row;
        }
        return ['audience' => $audience->count() - ($audience->contains('id', $post->author_id) ? 1 : 0), 'read' => $read, 'acknowledged' => $acked, 'pending' => $pending, 'rsvp' => $this->rsvpSummary($post)];
    }

    private function present(Post $p, User $viewer, \Illuminate\Support\Collection $mine): array
    {
        $name = fn ($u) => $u ? ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}"), 'avatar_url' => $u->avatar_url] : null;
        $m = $mine->get($p->id, ['read_at' => null, 'acknowledged_at' => null, 'rsvp' => null]);
        return [
            'id' => $p->id, 'kind' => $p->kind, 'title' => $p->title, 'body' => $p->body, 'has_photo' => (bool) $p->photo_path,
            'author' => $name($p->author), 'recipient' => $name($p->recipient), 'outlet' => $p->outlet ? ['id' => $p->outlet->id, 'name' => $p->outlet->name] : null,
            'must_read' => $p->must_read, 'comments_enabled' => $p->comments_enabled, 'points' => $p->points,
            'event_at' => $p->event_at?->toIso8601String(), 'event_place' => $p->event_place,
            'publish_at' => $p->publish_at?->toIso8601String(), 'published_at' => $p->published_at?->toIso8601String(),
            'comments_count' => (int) ($p->comments_count ?? 0), 'reads_count' => (int) ($p->reads_count ?? 0), 'rsvp_yes_count' => (int) ($p->rsvp_yes_count ?? 0),
            'me' => $m, 'can_manage' => $this->mayManage($viewer, $p), 'is_author' => $p->author_id === $viewer->id,
        ];
    }

    private function n(string $kind): string { return in_array($kind[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'n' : ''; }
}
