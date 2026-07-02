<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\ImageService;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    // =========================================================================
    // PUBLIC ENDPOINTS
    // =========================================================================

    /**
     * GET /api/v1/categories
     * Public category list for storefront.
     * Supports: tree structure, flat list, root-only, with counts.
     */
    public function index(Request $request)
    {
        $lang = $request->get('lang', 'en');

        // Tree structure - for nav menus and storefront sidebar
        if ($request->boolean('tree')) {
            $categories = Category::with(['children' => function ($q) {
                    $q->active()->with(['children' => function ($q2) {
                        $q2->active()->with('children')->orderBy('sort_order');
                    }])->orderBy('sort_order');
                }])
                ->active()
                ->rootLevel()
                ->inMenu()
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($c) => $this->formatPublic($c, $lang, true));

            return response()->json(['data' => $categories]);
        }

        $query = Category::query()->active();

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        } elseif ($request->boolean('root_only')) {
            $query->rootLevel();
        }

        if ($request->boolean('featured')) {
            $query->featured();
        }

        if ($request->boolean('with_children')) {
            $query->with('children');
        }

        $categories = $query->orderBy('sort_order')->get();

        return response()->json([
            'data' => $categories->map(fn ($c) => $this->formatPublic($c, $lang)),
        ]);
    }

    /**
     * GET /api/v1/categories/{slug}
     * Single category by slug - includes breadcrumb, children, SEO.
     */
    public function show(Request $request, $slug)
    {
        $lang     = $request->get('lang', 'en');
        $category = Category::with(['parent', 'children'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'category' => $this->formatPublic($category, $lang, true),
        ]);
    }

    /**
     * GET /api/v1/categories/{id}/products
     * Products in a category - optionally include subcategory products.
     */
    public function products(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        $perPage  = min((int) $request->get('per_page', 24), 100);

        $categoryIds = [$category->id];
        if ($request->boolean('include_subcategories')) {
            $categoryIds = array_merge($categoryIds, $category->getAllDescendantIds());
        }

        $query = \App\Models\Product::with(['images', 'variants'])
            ->whereIn('category_id', $categoryIds)
            ->where('status', 'published');

        // Sorting
        match ($request->get('sort', 'newest')) {
            'price_asc'  => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            'name'       => $query->orderBy('name_en', 'asc'),
            default      => $query->orderBy('created_at', 'desc'),
        };

        return response()->json($query->paginate($perPage));
    }

    // =========================================================================
    // ADMIN ENDPOINTS
    // =========================================================================

    /**
     * GET /api/v1/admin/categories
     * Full category list for admin - includes inactive, all fields.
     */
    public function adminIndex(Request $request)
    {
        // Tree view for admin category manager
        if ($request->boolean('tree')) {
            $categories = Category::withCount('products')
                ->with(['children' => function ($q) {
                    $q->withCount('products')
                      ->with(['children' => function ($q2) {
                          $q2->withCount('products')
                             ->with(['children' => function ($q3) {
                                 $q3->withCount('products')->orderBy('sort_order');
                             }])
                             ->orderBy('sort_order');
                      }])
                      ->orderBy('sort_order');
                }])
                ->rootLevel()
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($c) => $this->formatAdmin($c, true));

            return response()->json([
                'data'  => $categories,
                'stats' => [
                    'total'    => Category::count(),
                    'active'   => Category::where('is_active', true)->count(),
                    'featured' => Category::where('featured', true)->count(),
                    'root'     => Category::whereNull('parent_id')->count(),
                ],
            ]);
        }

        $query = Category::withCount('products')->with('parent');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'ILIKE', "%{$search}%")
                  ->orWhere('slug',    'ILIKE', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        if ($request->boolean('root_only')) {
            $query->rootLevel();
        }

        $categories = $query->orderBy('sort_order')->get();

        return response()->json([
            'data'  => $categories->map(fn ($c) => $this->formatAdmin($c)),
            'stats' => [
                'total'    => Category::count(),
                'active'   => Category::where('is_active', true)->count(),
                'featured' => Category::where('featured', true)->count(),
                'root'     => Category::whereNull('parent_id')->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/categories/{id}
     */
    public function adminShow($id)
    {
        $category = Category::withCount('products')
            ->with(['parent', 'children'])
            ->findOrFail($id);

        return response()->json(['category' => $this->formatAdmin($category, true)]);
    }

    /**
     * POST /api/v1/admin/categories
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name_en'            => 'required|string|max:255',
            'name_sw'            => 'nullable|string|max:255',
            'name_fr'            => 'nullable|string|max:255',
            'name_pt'            => 'nullable|string|max:255',
            'description_en'     => 'nullable|string',
            'description_sw'     => 'nullable|string',
            'description_fr'     => 'nullable|string',
            'description_pt'     => 'nullable|string',
            'slug'               => 'nullable|string|max:255|unique:categories,slug',
            'parent_id'          => 'nullable|integer|exists:categories,id',
            'icon'               => 'nullable|string|max:100',
            'color'              => 'nullable|string|max:20',
            'is_active'          => 'sometimes|boolean',
            'show_in_menu'       => 'sometimes|boolean',
            'show_in_storefront' => 'sometimes|boolean',
            'featured'           => 'sometimes|boolean',
            'sort_order'         => 'nullable|integer|min:0',
            'meta_title'         => 'nullable|string|max:255',
            'meta_description'   => 'nullable|string',
            'meta_keywords'      => 'nullable|string|max:500',
        ]);

        // Generate unique slug
        $validated['slug'] = $this->generateSlug(
            $validated['slug'] ?? $validated['name_en']
        );

        // Auto sort order
        if (!isset($validated['sort_order'])) {
            $validated['sort_order'] = Category::where('parent_id', $validated['parent_id'] ?? null)
                ->max('sort_order') + 1;
        }

        // Image upload
        if ($request->hasFile('image')) {
            $result = app(ImageService::class)->process($request->file('image'), 'categories', 'category');
            $validated['image_url'] = $result['url'];
        }

        $category = Category::create($validated);

        try {
            ActivityLogService::log('category_created', null, [
                'category_id' => $category->id,
                'name'        => $category->name_en,
                'slug'        => $category->slug,
                'parent_id'   => $category->parent_id,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Category created successfully.',
            'category' => $this->formatAdmin($category->load(['parent', 'children'])),
        ], 201);
    }

    /**
     * PUT /api/v1/admin/categories/{id}
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name_en'            => 'sometimes|string|max:255',
            'name_sw'            => 'nullable|string|max:255',
            'name_fr'            => 'nullable|string|max:255',
            'name_pt'            => 'nullable|string|max:255',
            'description_en'     => 'nullable|string',
            'description_sw'     => 'nullable|string',
            'description_fr'     => 'nullable|string',
            'description_pt'     => 'nullable|string',
            'slug'               => ['nullable', 'string', 'max:255', "unique:categories,slug,{$category->id}"],
            'parent_id'          => 'nullable|integer|exists:categories,id',
            'icon'               => 'nullable|string|max:100',
            'color'              => 'nullable|string|max:20',
            'is_active'          => 'sometimes|boolean',
            'show_in_menu'       => 'sometimes|boolean',
            'show_in_storefront' => 'sometimes|boolean',
            'featured'           => 'sometimes|boolean',
            'sort_order'         => 'nullable|integer|min:0',
            'meta_title'         => 'nullable|string|max:255',
            'meta_description'   => 'nullable|string',
            'meta_keywords'      => 'nullable|string|max:500',
        ]);

        // Circular reference check
        if (!empty($validated['parent_id'])) {
            if ($category->wouldCreateCircularReference((int) $validated['parent_id'])) {
                return response()->json([
                    'message' => 'Cannot set this parent - it would create a circular reference.',
                ], 422);
            }
        }

        // Regenerate slug if name changed and no explicit slug provided
        if (isset($validated['name_en']) && !isset($validated['slug'])) {
            $validated['slug'] = $this->generateSlug($validated['name_en'], $category->id);
        } elseif (isset($validated['slug'])) {
            $validated['slug'] = $this->generateSlug($validated['slug'], $category->id);
        }

        // Image upload
        if ($request->hasFile('image')) {
            if ($category->image_url) {
                app(ImageService::class)->delete($category->image_url, 'public');
            }
            $result = app(ImageService::class)->process($request->file('image'), 'categories', 'category');
            $validated['image_url'] = $result['url'];
        }

        $category->update($validated);

        try {
            ActivityLogService::log('category_updated', null, [
                'category_id' => $category->id,
                'name'        => $category->name_en,
                'changes'     => array_keys($validated),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => 'Category updated successfully.',
            'category' => $this->formatAdmin($category->fresh()->load(['parent', 'children'])),
        ]);
    }

    /**
     * DELETE /api/v1/admin/categories/{id}
     */
    public function destroy($id)
    {
        $category = Category::withCount(['products', 'children'])->findOrFail($id);

        if ($category->products_count > 0) {
            return response()->json([
                'message' => "Cannot delete - this category has {$category->products_count} product(s). Reassign them first.",
            ], 422);
        }

        if ($category->children_count > 0) {
            return response()->json([
                'message' => "Cannot delete - this category has {$category->children_count} subcategory/ies. Delete or reassign them first.",
            ], 422);
        }

        // Clean up image
        if ($category->image_url && !str_starts_with($category->image_url, 'http')) {
            Storage::disk('public')->delete($category->image_url);
        }

        $category->delete();

        try {
            ActivityLogService::log('category_deleted', null, [
                'category_id' => $id,
                'name'        => $category->name_en,
                'slug'        => $category->slug,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    /**
     * PUT /api/v1/admin/categories/reorder
     * Bulk sort order update.
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'categories'              => 'required|array|min:1',
            'categories.*.id'         => 'required|integer|exists:categories,id',
            'categories.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['categories'] as $item) {
                Category::where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        try {
            ActivityLogService::log('categories_reordered', null, [
                'count' => count($validated['categories']),
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Categories reordered successfully.']);
    }

    /**
     * POST /api/v1/admin/categories/{id}/image
     * Upload/replace category image.
     */
    public function uploadImage(Request $request, $id)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp,svg|max:2048',
        ]);

        $category = Category::findOrFail($id);

        // Delete old image
        if ($category->image_url) {
            app(ImageService::class)->delete($category->image_url, 'public');
        }

        $result = app(ImageService::class)->process($request->file('image'), 'categories', 'category');

        $category->update(['image_url' => $result['url']]);

        return response()->json([
            'message'   => 'Image uploaded successfully.',
            'image_url' => $result['url'],
        ]);
    }

    /**
     * DELETE /api/v1/admin/categories/{id}/image
     * Remove category image.
     */
    public function deleteImage($id)
    {
        $category = Category::findOrFail($id);

        if ($category->image_url && !str_starts_with($category->image_url, 'http')) {
            Storage::disk('public')->delete(
                str_replace(config('app.url') . '/storage/', '', $category->image_url)
            );
        }

        $category->update(['image_url' => null]);

        return response()->json(['message' => 'Image removed.']);
    }

    /**
     * PUT /api/v1/admin/categories/{id}/toggle
     */
    public function toggleStatus($id)
    {
        $category = Category::findOrFail($id);
        $category->update(['is_active' => !$category->is_active]);

        try {
            ActivityLogService::log('category_status_toggled', null, [
                'category_id' => $category->id,
                'name'        => $category->name_en,
                'is_active'   => $category->is_active,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'   => 'Category status updated.',
            'is_active' => $category->is_active,
            'category'  => $this->formatAdmin($category->fresh()),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function generateSlug(string $base, ?int $excludeId = null): string
    {
        $slug     = Str::slug($base);
        $original = $slug;
        $i        = 1;

        while (
            Category::where('slug', $slug)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = "{$original}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function formatAdmin(Category $category, bool $withChildren = false): array
    {
        $data = [
            'id'                 => $category->id,
            'parent_id'          => $category->parent_id,
            'slug'               => $category->slug,
            'name_en'            => $category->name_en,
            'name_sw'            => $category->name_sw,
            'name_fr'            => $category->name_fr,
            'name_pt'            => $category->name_pt,
            'description_en'     => $category->description_en,
            'description_sw'     => $category->description_sw,
            'description_fr'     => $category->description_fr,
            'description_pt'     => $category->description_pt,
            'image_url'          => $category->image_url,
            'icon'               => $category->icon,
            'color'              => $category->color,
            'sort_order'         => $category->sort_order,
            'is_active'          => (bool) $category->is_active,
            'show_in_menu'       => (bool) ($category->show_in_menu ?? true),
            'show_in_storefront' => (bool) ($category->show_in_storefront ?? true),
            'featured'           => (bool) ($category->featured ?? false),
            'meta_title'         => $category->meta_title,
            'meta_description'   => $category->meta_description,
            'meta_keywords'      => $category->meta_keywords,
            'products_count'     => $category->products_count ?? 0,
            'breadcrumb'         => $category->breadcrumb,
            'parent'             => $category->relationLoaded('parent') && $category->parent
                ? ['id' => $category->parent->id, 'name_en' => $category->parent->name_en]
                : null,
            'created_at'         => $category->created_at,
            'updated_at'         => $category->updated_at,
        ];

        if ($withChildren && $category->relationLoaded('children')) {
            $data['children'] = $category->children
                ->map(fn ($c) => $this->formatAdmin($c, true))
                ->values();
        }

        return $data;
    }

    private function formatPublic(Category $category, string $lang = 'en', bool $withChildren = false): array
    {
        $data = [
            'id'          => $category->id,
            'slug'        => $category->slug,
            'name'        => $category->getName($lang),
            'description' => $category->getDescription($lang),
            'image_url'   => $category->image_url,
            'icon'        => $category->icon,
            'color'       => $category->color,
            'sort_order'  => $category->sort_order,
            'featured'    => (bool) $category->featured,
        ];

        if ($withChildren && $category->relationLoaded('children')) {
            $data['children'] = $category->children
                ->map(fn ($c) => $this->formatPublic($c, $lang, true))
                ->values();
        }

        return $data;
    }
}