<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// ==================== CONTENT PAGE CONTROLLER ====================

class ContentPageController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('content_pages');

        // Was checking $request->user()->role, a column that doesn't exist -
        // User only has Spatie roles via HasRoles (roles()/hasRole()), so
        // ->role always resolved to null and this filter applied to EVERY
        // caller, including real admins. Drafts were never visible through
        // this endpoint to anyone. Fixed to check the actual permission that
        // gates content-page management (settings.edit, same as
        // store/update/destroy/publish below), consistent with the rest of
        // the app rather than a hardcoded role name.
        if (!$request->user() || !$request->user()->can('settings.edit')) {
            $query->where('status', 'published');
        }

        $lang = $request->get('lang', 'en');
        
        $pages = $query->orderBy('title', 'asc')->get()->map(function ($page) use ($lang) {
            $translation = DB::table('content_page_translations')
                ->where('content_page_id', $page->id)
                ->where('language', $lang)
                ->first();

            $page->title = $translation->title ?? $page->title;
            $page->content = $translation->content ?? '';
            
            return $page;
        });

        return response()->json($pages);
    }

    public function show($slug, Request $request)
    {
        $page = DB::table('content_pages')->where('slug', $slug)->first();

        if (!$page) {
            return response()->json(['message' => 'Page not found'], 404);
        }

        if ($page->status !== 'published' && (!$request->user() || !$request->user()->can('settings.edit'))) {
            return response()->json(['message' => 'Page not found'], 404);
        }

        $lang = $request->get('lang', 'en');
        $translation = DB::table('content_page_translations')
            ->where('content_page_id', $page->id)
            ->where('language', $lang)
            ->first();

        $page->title = $translation->title ?? $page->title;
        $page->content = $translation->content ?? '';
        $page->meta_title = $translation->meta_title ?? $page->meta_title;
        $page->meta_description = $translation->meta_description ?? $page->meta_description;

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:content_pages,slug',
            'content' => 'required|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'status' => 'required|in:draft,published',
            'translations' => 'nullable|array',
            'translations.*.language' => 'required|in:en,fr,pt',
            'translations.*.title' => 'required|string',
            'translations.*.content' => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            $pageId = DB::table('content_pages')->insertGetId([
                'title' => $validated['title'],
                'slug' => $validated['slug'],
                'content' => $validated['content'],
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'status' => $validated['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (isset($validated['translations'])) {
                foreach ($validated['translations'] as $trans) {
                    DB::table('content_page_translations')->insert([
                        'content_page_id' => $pageId,
                        'language' => $trans['language'],
                        'title' => $trans['title'],
                        'content' => $trans['content'],
                        'meta_title' => $trans['meta_title'] ?? null,
                        'meta_description' => $trans['meta_description'] ?? null,
                        'created_at' => now(),
                    ]);
                }
            }

            DB::commit();

            try {
                ActivityLogService::log('content_page_created', null, [
                    'page_id' => $pageId,
                    'title'   => $validated['title'],
                    'slug'    => $validated['slug'],
                    'status'  => $validated['status'],
                ]);
            } catch (\Exception) {}

            return response()->json(['message' => 'Page created', 'id' => $pageId], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('content_pages')->ignore($id)],
            'content' => 'sometimes|string',
            'meta_title' => 'nullable|string|max:255',
            'status' => 'sometimes|in:draft,published',
        ]);

        DB::table('content_pages')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));

        try {
            ActivityLogService::log('content_page_updated', null, [
                'page_id' => $id,
                'changes' => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Page updated']);
    }

    public function destroy($id)
    {
        $page = DB::table('content_pages')->find($id);
        DB::beginTransaction();
        try {
            DB::table('content_page_translations')->where('content_page_id', $id)->delete();
            DB::table('content_pages')->where('id', $id)->delete();
            DB::commit();

            try {
                ActivityLogService::log('content_page_deleted', null, [
                    'page_id' => $id,
                    'title'   => $page->title ?? null,
                    'slug'    => $page->slug ?? null,
                ]);
            } catch (\Exception) {}

            return response()->json(['message' => 'Page deleted']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function publish($id)
    {
        DB::table('content_pages')->where('id', $id)->update(['status' => 'published', 'published_at' => now()]);

        try {
            ActivityLogService::log('content_page_published', null, ['page_id' => $id]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Page published']);
    }

    public function unpublish($id)
    {
        DB::table('content_pages')->where('id', $id)->update(['status' => 'draft']);

        try {
            ActivityLogService::log('content_page_unpublished', null, ['page_id' => $id]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Page unpublished']);
    }

    public function duplicate($id)
    {
        $page = DB::table('content_pages')->find($id);
        if (!$page) return response()->json(['message' => 'Not found'], 404);

        DB::beginTransaction();
        try {
            $newId = DB::table('content_pages')->insertGetId([
                'title' => $page->title . ' (Copy)',
                'slug' => $page->slug . '-copy-' . time(),
                'content' => $page->content,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::commit();

            try {
                ActivityLogService::log('content_page_duplicated', null, [
                    'source_page_id' => $id,
                    'new_page_id'    => $newId,
                    'source_title'   => $page->title,
                ]);
            } catch (\Exception) {}

            return response()->json(['message' => 'Page duplicated', 'id' => $newId], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed', 'error' => $e->getMessage()], 500);
        }
    }
}