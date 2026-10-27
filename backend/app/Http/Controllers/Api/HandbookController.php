<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Post;
use App\Models\PostRead;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The handbook (People C4): policies and how-tos, searchable from the
 * phone. A published must-read article carries a must-read post on the
 * Updates feed, so "read and understood" is the feed's own tracking:
 * opening the article marks the post read; the acknowledgement is pressed
 * on the feed (or here, through the same endpoint). updates.post writes.
 */
class HandbookController extends Controller
{
    /** GET /handbook?q= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate(['q' => 'nullable|string|max:80']);
        $q = Article::visibleTo($user)->orderBy('category')->orderBy('title');
        if (!$user->can('updates.post')) $q->published();
        if (!empty($v['q'])) { $s = '%' . strtolower(trim($v['q'])) . '%'; $q->where(fn ($w) => $w->whereRaw('LOWER(title) LIKE ?', [$s])->orWhereRaw('LOWER(body) LIKE ?', [$s])); }
        $rows = $q->get();
        $reads = PostRead::where('user_id', $user->id)->whereIn('post_id', $rows->pluck('post_id')->filter())->get()->keyBy('post_id');
        return response()->json(['data' => $rows->map(fn (Article $a) => $this->present($a, $user, $reads->get($a->post_id)))->values(), 'can_edit' => $user->can('updates.post'),
            'categories' => $rows->pluck('category')->filter()->unique()->values()]);
    }

    /** GET /handbook/{id} — opening it marks the linked post read. */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $a = Article::visibleTo($user)->findOrFail($id);
        if (!$a->published_at && !$user->can('updates.post')) abort(404);
        $read = null;
        if ($a->post_id) $read = PostRead::firstOrCreate(['post_id' => $a->post_id, 'user_id' => $user->id], ['read_at' => now()]);
        return response()->json(['article' => $this->present($a, $user, $read, full: true)]);
    }

    /** POST /handbook (updates.post) */
    public function store(Request $request): JsonResponse
    {
        $v = $this->validateArticle($request);
        $a = Article::create($v + ['slug' => $this->slug($v['title']), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'published_at' => !empty($v['publish']) ? now() : null]);
        $this->syncPost($a, $request->user());
        ActivityLogService::log('article_saved', $a, ['published' => (bool) $a->published_at, 'must_read' => $a->must_read], "{$request->user()->first_name} wrote the handbook article \"{$a->title}\"");
        return response()->json(['message' => $a->published_at ? 'Published.' : 'Saved as a draft.', 'article' => $this->present($a->fresh(), $request->user(), null, full: true)], 201);
    }

    /** PUT /handbook/{id} (updates.post) */
    public function update(Request $request, int $id): JsonResponse
    {
        $a = Article::findOrFail($id);
        $v = $this->validateArticle($request, partial: true);
        if (array_key_exists('publish', $v)) { if ($v['publish'] && !$a->published_at) $v['published_at'] = now(); elseif (!$v['publish']) $v['published_at'] = null; }
        unset($v['publish']);
        $a->fill($v + ['updated_by' => $request->user()->id])->save();
        $this->syncPost($a, $request->user());
        ActivityLogService::log('article_saved', $a, ['changed' => array_keys($v), 'published' => (bool) $a->published_at], "{$request->user()->first_name} edited the handbook article \"{$a->title}\"");
        return response()->json(['message' => 'Saved.', 'article' => $this->present($a->fresh(), $request->user(), null, full: true)]);
    }

    /** DELETE /handbook/{id} (updates.post) */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $a = Article::findOrFail($id);
        $a->delete();
        if ($a->post_id) Post::whereKey($a->post_id)->delete();
        ActivityLogService::log('article_removed', $a, [], "{$request->user()->first_name} removed the handbook article \"{$a->title}\"");
        return response()->json(['message' => 'Removed.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** A published must-read article has a must-read post; an unpublished or plain one has none (the post is removed). */
    private function syncPost(Article $a, $actor): void
    {
        if ($a->published_at && $a->must_read) {
            $fields = ['kind' => 'update', 'author_id' => $a->created_by ?? $actor->id, 'outlet_id' => $a->outlet_id, 'title' => "Policy: {$a->title}", 'body' => Str::limit($a->body, 300) . "\n\nRead the full article in the handbook: /handbook/{$a->id}", 'must_read' => true, 'comments_enabled' => false];
            if ($a->post_id && ($post = Post::find($a->post_id))) { $post->fill($fields)->save(); }
            else { $post = Post::create($fields + ['published_at' => now()]); $a->forceFill(['post_id' => $post->id])->save(); UpdatesController::notify($post); }
        } elseif ($a->post_id) {
            Post::whereKey($a->post_id)->delete();
            $a->forceFill(['post_id' => null])->save();
        }
    }

    private function validateArticle(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        return $request->validate(['title' => "{$req}|string|max:140", 'body' => "{$req}|string|max:50000", 'category' => 'nullable|string|max:40',
            'outlet_id' => 'nullable|integer|exists:outlets,id', 'must_read' => 'sometimes|boolean', 'publish' => 'sometimes|boolean']);
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'article'; $slug = $base; $n = 2;
        while (Article::withTrashed()->where('slug', $slug)->exists()) $slug = "{$base}-" . $n++;
        return $slug;
    }

    private function present(Article $a, $user, ?PostRead $read, bool $full = false): array
    {
        $row = ['id' => $a->id, 'title' => $a->title, 'slug' => $a->slug, 'category' => $a->category, 'outlet' => $a->outlet_id ? ['id' => $a->outlet_id, 'name' => $a->outlet?->name] : null,
            'must_read' => $a->must_read, 'post_id' => $a->post_id, 'published_at' => $a->published_at?->toIso8601String(), 'updated_at' => $a->updated_at?->toIso8601String(),
            'excerpt' => Str::limit(trim(preg_replace('/\s+/', ' ', $a->body)), 140), 'read_at' => $read?->read_at?->toIso8601String(), 'acknowledged_at' => $read?->acknowledged_at?->toIso8601String()];
        if ($full) $row['body'] = $a->body;
        return $row;
    }
}
