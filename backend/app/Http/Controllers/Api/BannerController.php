<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\ActivityLogService;
use App\Services\ImageService;
use Illuminate\Http\Request;

/**
 * The homepage/marketing content-block CMS. Every marketing block on the
 * storefront (hero slider slides, promo strips, the shop hero, category tiles,
 * testimonials, pillars, newsletter …) is a `banners` row, placed by:
 *   - placement  = which page (homepage | shop | …)
 *   - position   = which slot/section (home_hero | home_promo | shop_hero | …)
 *   - sort_order = its order within the slot (the slider's "position 1, 2, 3")
 * and carrying its editable details (title, subtitle, image, link, styles JSON).
 *
 * Public read is grouped by position so the storefront can render each section
 * (and fall back to its hardcoded content when a slot is empty).
 */
class BannerController extends Controller
{
    // ── PUBLIC: storefront content blocks ────────────────────────────────────

    /** GET /api/v1/site/content?placement=homepage[&position=home_hero] */
    public function content(Request $request)
    {
        try {
            $q = Banner::active();
            if ($request->filled('placement')) {
                $q->where('placement', $request->placement);
            }
            if ($request->filled('position')) {
                $q->where('position', $request->position);
            }
            $grouped = $q->orderBy('position')->orderBy('sort_order')->get()
                ->map(fn (Banner $b) => $this->publicShape($b))
                ->groupBy('position');

            return response()->json(['data' => $grouped]);
        } catch (\Throwable $e) {
            // Theming/marketing must never break the storefront.
            return response()->json(['data' => (object) []]);
        }
    }

    private function publicShape(Banner $b): array
    {
        return [
            'id'               => $b->id,
            'position'         => $b->position,
            'sort_order'       => $b->sort_order,
            'title'            => $b->title,
            'subtitle'         => $b->subtitle,
            'image_url'        => $b->image_url,
            'mobile_image_url' => $b->mobile_image_url,
            'link_url'         => $b->link_url,
            'link_text'        => $b->link_text,
            'open_in_new_tab'  => (bool) $b->open_in_new_tab,
            'styles'           => $b->styles,
        ];
    }

    // ── ADMIN CRUD (CMS) ─────────────────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        $q = Banner::query();
        if ($request->filled('placement')) {
            $q->where('placement', $request->placement);
        }
        if ($request->filled('position')) {
            $q->where('position', $request->position);
        }
        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $banners = $q->orderBy('placement')->orderBy('position')->orderBy('sort_order')->get();

        return response()->json([
            'data'  => $banners,
            'stats' => [
                'total'  => Banner::count(),
                'active' => Banner::where('is_active', true)->count(),
                'live'   => Banner::active()->count(),
            ],
        ]);
    }

    public function adminShow($id)
    {
        return response()->json(['data' => Banner::findOrFail($id)]);
    }

    public function store(Request $request)
    {
        $banner = Banner::create($this->validated($request));
        $this->log('banner_created', $banner);

        return response()->json(['data' => $banner], 201);
    }

    public function update(Request $request, $id)
    {
        $banner = Banner::findOrFail($id);
        $banner->update($this->validated($request));
        $this->log('banner_updated', $banner);

        return response()->json(['data' => $banner]);
    }

    public function destroy($id)
    {
        $banner = Banner::findOrFail($id);
        $banner->delete();
        $this->log('banner_deleted', $banner);

        return response()->json(['message' => 'Banner deleted.']);
    }

    /** POST /banners/{id}/image (multipart `image`) — upload + set image_url. */
    public function uploadImage(Request $request, $id)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            'field' => 'nullable|in:image_url,mobile_image_url',
        ]);

        $banner = Banner::findOrFail($id);
        $field  = $request->input('field', 'image_url');
        $result = app(ImageService::class)->process($request->file('image'), 'banners', 'banner');
        $banner->update([$field => $result['url']]);
        $this->log('banner_image_uploaded', $banner);

        return response()->json(['data' => $banner->fresh(), 'url' => $result['url']]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'            => 'nullable|string|max:150',
            'subtitle'         => 'nullable|string|max:255',
            'image_url'        => 'nullable|string|max:500',
            'mobile_image_url' => 'nullable|string|max:500',
            'link_url'         => 'nullable|string|max:500',
            'link_text'        => 'nullable|string|max:100',
            'position'         => 'required|string|max:50',   // slot: home_hero, home_promo, shop_hero, …
            'placement'        => 'nullable|string|max:50',    // page: homepage, shop, …
            'is_active'        => 'sometimes|boolean',
            'open_in_new_tab'  => 'sometimes|boolean',
            'sort_order'       => 'nullable|integer|min:0',    // the slider's "position 1, 2, 3"
            'starts_at'        => 'nullable|date',
            'ends_at'          => 'nullable|date|after_or_equal:starts_at',
            'styles'           => 'nullable|array',            // eyebrow, badge, price_tag, theme, cta2 …
        ]);
    }

    private function log(string $action, Banner $b): void
    {
        try {
            ActivityLogService::log($action, null, [
                'banner_id' => $b->id, 'title' => $b->title,
                'position'  => $b->position, 'sort_order' => $b->sort_order,
            ]);
        } catch (\Exception) {
        }
    }
}
