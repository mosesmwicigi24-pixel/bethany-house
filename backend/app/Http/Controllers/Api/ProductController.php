<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSeo;
use App\Models\ProductTranslation;
use App\Services\TaxCalculationService;
use App\Services\ActivityLogService;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    // =========================================================================
    // PUBLIC ENDPOINTS
    // =========================================================================

    /**
     * GET /api/v1/products  - storefront product listing
     */
    public function index(Request $request)
    {
        $lang     = $request->get('lang', 'en');
        $currency = $request->get('currency', 'KES');
        $perPage  = min((int) $request->get('per_page', 24), 100);

        $query = Product::with(['category', 'images', 'translations', 'prices'])
            ->published();

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('translations', function ($q) use ($search, $lang) {
                $q->where('language_code', $lang)
                  ->where(function ($q2) use ($search) {
                      $q2->where('name', 'ILIKE', "%{$search}%")
                         ->orWhere('description', 'ILIKE', "%{$search}%");
                  });
            })->orWhere('sku', 'ILIKE', "%{$search}%");
        }

        match ($request->get('sort', 'newest')) {
            'name'     => $query->orderBy('sort_order'),
            'featured' => $query->orderBy('is_featured', 'desc'),
            default    => $query->orderBy('published_at', 'desc'),
        };

        $products = $query->paginate($perPage);
        // Expose availability to storefront/API consumers (Neema WhatsApp agent)
        // so an out-of-stock item is never quoted. `aliases` serialises via the
        // model cast automatically.
        $products->getCollection()->each->append(['in_stock', 'available_qty']);

        return response()->json($products);
    }

    /**
     * GET /api/v1/products/{slug}  - single public product
     */
    public function show(Request $request, $slug)
    {
        $product = Product::with([
            'category', 'translations', 'prices',
            'variants.prices', 'variants.images',
            'images', 'seo', 'reviews',
        ])->where('slug', $slug)->published()->firstOrFail();

        return response()->json(['product' => $this->formatPublic($product)]);
    }

    public function featured(Request $request)
    {
        $products = Product::with(['images', 'translations', 'prices'])
            ->published()->featured()
            ->limit(12)->get();

        return response()->json(['data' => $products]);
    }

    public function newArrivals(Request $request)
    {
        $products = Product::with(['images', 'translations', 'prices'])
            ->published()
            ->orderBy('published_at', 'desc')
            ->limit(12)->get();

        return response()->json(['data' => $products]);
    }

    public function search(Request $request)
    {
        $request->validate(['q' => 'required|string|min:2']);
        $lang = $request->get('lang', 'en');

        $products = Product::with(['images', 'translations'])
            ->published()
            ->whereHas('translations', fn ($q) =>
                $q->where('language_code', $lang)
                  ->where('name', 'ILIKE', "%{$request->q}%")
            )
            ->orWhere('sku', 'ILIKE', "%{$request->q}%")
            ->limit(20)->get();

        return response()->json(['data' => $products]);
    }

    public function variants(Request $request, $id)
    {
        $product  = Product::findOrFail($id);
        $variants = $product->variants()->with(['prices', 'images'])->where('is_active', true)->get();
        return response()->json(['data' => $variants]);
    }

    // =========================================================================
    // ADMIN ENDPOINTS
    // =========================================================================

    /**
     * GET /api/v1/admin/products  - paginated admin list
     */
    public function adminIndex(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $query = Product::with([
            'category:id,name_en',
            'images' => fn ($q) => $q->where('is_primary', true)->limit(1),
            'translations' => fn ($q) => $q->where('language_code', 'en'),
            'prices' => fn ($q) => $q->whereNull('product_variant_id')->where('currency_code', 'KES'),
        ])->withCount('variants');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }
        if ($request->filled('is_featured')) {
            $query->where('is_featured', filter_var($request->is_featured, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('is_producible')) {
            $query->where('is_producible', filter_var($request->is_producible, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }
        if ($request->filled('price_min') || $request->filled('price_max')) {
            $query->whereHas('prices', function ($q) use ($request) {
                $q->whereNull('product_variant_id')->where('currency_code', 'KES');
                if ($request->filled('price_min')) {
                    $q->where('regular_price', '>=', (float) $request->price_min);
                }
                if ($request->filled('price_max')) {
                    $q->where('regular_price', '<=', (float) $request->price_max);
                }
            });
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'ILIKE', "%{$search}%")
                  ->orWhere('slug', 'ILIKE', "%{$search}%")
                  ->orWhere('brand', 'ILIKE', "%{$search}%")
                  ->orWhereHas('translations', fn ($q2) =>
                      $q2->where('language_code', 'en')
                         ->where('name', 'ILIKE', "%{$search}%")
                  );
            });
        }

        match ($request->get('sort_by', 'created_at')) {
            'name'   => $query->orderBy(
                ProductTranslation::select('name')
                    ->whereColumn('product_id', 'products.id')
                    ->where('language_code', 'en')
                    ->limit(1)
            ),
            'sku'    => $query->orderBy('sku'),
            'status' => $query->orderBy('status'),
            default  => $query->orderBy('created_at', $request->get('sort_dir', 'desc')),
        };

        $products = $query->paginate($perPage);

        $stats = [
            'total'      => Product::count(),
            'active'     => Product::where('status', 'active')->count(),
            'draft'      => Product::where('status', 'draft')->count(),
            'archived'   => Product::where('status', 'archived')->count(),
            'featured'   => Product::where('is_featured', true)->count(),
            'producible' => Product::where('is_producible', true)->count(),
        ];

        // Distinct non-null brands for the filter dropdown
        $brands = Product::whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');

        // Active categories that actually have products (for the filter dropdown)
        $categories = DB::table('categories')
            ->where('is_active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('products')
                  ->whereColumn('products.category_id', 'categories.id');
            })
            ->orderBy('name_en')
            ->get(['id', 'name_en']);

        return response()->json([
            'data'       => collect($products->items())->map(fn ($p) => $this->formatListItem($p)),
            'meta'       => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'total'        => $products->total(),
                'from'         => $products->firstItem(),
                'to'           => $products->lastItem(),
            ],
            'stats'      => $stats,
            'brands'     => $brands,
            'categories' => $categories,
        ]);
    }

    /**
     * GET /api/v1/admin/products/{id}  - full product detail
     *
     * Phase 2: includes tax_rate_ids and tax_rates in response.
     */
    public function adminShow($id)
    {
        $product = Product::with([
            'category:id,name_en,slug',
            'translations',
            'prices',
            'variants.prices',
            'variants.images',
            'images' => fn ($q) => $q->orderBy('sort_order'),
            'seo',
        ])->findOrFail($id);

        $detail = $this->formatDetail($product);

        // Phase 2 - attach tax rate assignments
        $detail['tax_rate_ids'] = DB::table('product_tax_rates')
            ->where('product_id', $id)
            ->pluck('tax_rate_id')
            ->toArray();

        $detail['tax_rates'] = DB::table('product_tax_rates as ptr')
            ->join('tax_rates as tr', 'ptr.tax_rate_id', '=', 'tr.id')
            ->where('ptr.product_id', $id)
            ->select('tr.id', 'tr.name', 'tr.rate', 'tr.code', 'tr.tax_type', 'tr.is_default', 'tr.is_active')
            ->get()
            ->toArray();

        return response()->json(['product' => $detail]);
    }

    /**
     * POST /api/v1/admin/products
     *
     * Phase 2: accepts tax_rate_ids and syncs the product_tax_rates pivot after creation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // Core
            'sku'                 => 'required|string|max:100|unique:products,sku',
            'slug'                => 'nullable|string|max:255|unique:products,slug',
            'category_id'         => 'nullable|exists:categories,id',
            'product_type'        => 'required|in:simple,variable,made_to_order',
            'status'              => 'required|in:draft,active,inactive,archived',
            'is_featured'         => 'boolean',
            'is_producible'       => 'boolean',
            'brand'               => 'nullable|string|max:100',
            'tax_class'           => 'nullable|string|max:50',
            'low_stock_threshold' => 'integer|min:0',
            'weight'              => 'nullable|numeric|min:0',
            'length'              => 'nullable|numeric|min:0',
            'width'               => 'nullable|numeric|min:0',
            'height'              => 'nullable|numeric|min:0',
            // Translations
            'translations'                          => 'required|array|min:1',
            'translations.*.language_code'          => 'required|string|max:10',
            'translations.*.name'                   => 'required|string|max:255',
            'translations.*.description'            => 'required|string',
            'translations.*.short_description'      => 'nullable|string',
            'translations.*.specifications'         => 'nullable|array',
            // Prices
            'prices'                                => 'required|array|min:1',
            'prices.*.currency_code'                => 'required|string|max:10|exists:currencies,code',
            'prices.*.regular_price'                => 'required|numeric|min:0',
            'prices.*.sale_price'                   => 'nullable|numeric|min:0',
            'prices.*.cost_price'                   => 'nullable|numeric|min:0',
            'prices.*.sale_start_date'              => 'nullable|date',
            'prices.*.sale_end_date'                => 'nullable|date|after:prices.*.sale_start_date',
            // SEO
            'seo'                                   => 'nullable|array',
            'seo.*.language_code'                   => 'required|string|max:10',
            'seo.*.meta_title'                      => 'nullable|string|max:255',
            'seo.*.meta_description'                => 'nullable|string',
            'seo.*.meta_keywords'                   => 'nullable|string',
            'seo.*.canonical_url'                   => 'nullable|url',
            'seo.*.og_title'                        => 'nullable|string|max:255',
            'seo.*.og_description'                  => 'nullable|string',
            // Measurements
            'measurements'                          => 'nullable|array',
            'measurements.*.name'                   => 'required_with:measurements|string|max:100',
            'measurements.*.unit'                   => 'nullable|string|max:30',
            'measurements.*.required'               => 'boolean',
            // Phase 2 - tax rate IDs
            'tax_rate_ids'                          => 'nullable|array',
            'tax_rate_ids.*'                        => 'integer|exists:tax_rates,id',
        ]);

        DB::beginTransaction();
        try {
            $slug = $this->generateSlug(
                $validated['slug'] ?? collect($validated['translations'])->firstWhere('language_code', 'en')['name'] ?? $validated['sku']
            );

            $product = Product::create([
                'sku'                 => strtoupper($validated['sku']),
                'slug'                => $slug,
                'category_id'         => $validated['category_id'] ?? null,
                'product_type'        => $validated['product_type'],
                'status'              => $validated['status'],
                'is_featured'         => $validated['is_featured'] ?? false,
                'is_producible'       => $validated['is_producible'] ?? false,
                'brand'               => $validated['brand'] ?? null,
                'tax_class'           => $validated['tax_class'] ?? null,
                'low_stock_threshold' => $validated['low_stock_threshold'] ?? 5,
                'weight'              => $validated['weight'] ?? null,
                'length'              => $validated['length'] ?? null,
                'width'               => $validated['width'] ?? null,
                'height'              => $validated['height'] ?? null,
                'published_at'        => $validated['status'] === 'active' ? now() : null,
                'measurements'        => !empty($validated['measurements']) ? $validated['measurements'] : null,
            ]);

            // Translations
            foreach ($validated['translations'] as $trans) {
                ProductTranslation::create([
                    'product_id'        => $product->id,
                    'language_code'     => $trans['language_code'],
                    'name'              => $trans['name'],
                    'description'       => $trans['description'],
                    'short_description' => $trans['short_description'] ?? '',
                    'specifications'    => $trans['specifications'] ?? null,
                ]);
            }

            // Base prices
            foreach ($validated['prices'] as $price) {
                ProductPrice::create([
                    'product_id'         => $product->id,
                    'product_variant_id' => null,
                    'currency_code'      => $price['currency_code'],
                    'regular_price'      => $price['regular_price'],
                    'sale_price'         => $price['sale_price'] ?? null,
                    'cost_price'         => $price['cost_price'] ?? null,
                    'sale_start_date'    => $price['sale_start_date'] ?? null,
                    'sale_end_date'      => $price['sale_end_date'] ?? null,
                ]);
            }

            // SEO
            if (!empty($validated['seo'])) {
                foreach ($validated['seo'] as $seo) {
                    ProductSeo::create(array_merge(['product_id' => $product->id], $seo));
                }
            }

            // Phase 2 - sync tax rates
            $taxRateIds = $validated['tax_rate_ids'] ?? [];
            $this->syncTaxRates($product->id, $taxRateIds);

            DB::commit();

            ActivityLogService::log('created', $product, [
                'sku'    => $product->sku,
                'status' => $product->status,
            ]);

            $detail = $this->formatDetail($product->fresh()->load([
                'category', 'translations', 'prices', 'variants', 'images', 'seo',
            ]));
            $detail['tax_rate_ids'] = $taxRateIds;
            $detail['tax_rates']    = $this->loadTaxRates($product->id);

            return response()->json([
                'message' => 'Product created successfully.',
                'product' => $detail,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create product.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/v1/admin/products/{id}
     *
     * Phase 2: accepts tax_rate_ids and syncs the pivot.
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'sku'                 => ['sometimes', 'string', 'max:100', Rule::unique('products')->ignore($product->id)],
            'slug'                => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products')->ignore($product->id)],
            'category_id'         => 'sometimes|nullable|exists:categories,id',
            'product_type'        => 'sometimes|in:simple,variable,made_to_order',
            'status'              => 'sometimes|in:draft,active,inactive,archived',
            'is_featured'         => 'sometimes|boolean',
            'is_producible'       => 'sometimes|boolean',
            'brand'               => 'sometimes|nullable|string|max:100',
            'tax_class'           => 'sometimes|nullable|string|max:50',
            'low_stock_threshold' => 'sometimes|integer|min:0',
            'weight'              => 'sometimes|nullable|numeric|min:0',
            'length'              => 'sometimes|nullable|numeric|min:0',
            'width'               => 'sometimes|nullable|numeric|min:0',
            'height'              => 'sometimes|nullable|numeric|min:0',
            'translations'                     => 'sometimes|array',
            'translations.*.language_code'     => 'required|string',
            'translations.*.name'              => 'required|string|max:255',
            'translations.*.description'       => 'required|string',
            'translations.*.short_description' => 'nullable|string',
            'translations.*.specifications'    => 'nullable|array',
            'prices'                           => 'sometimes|array',
            'prices.*.currency_code'           => 'required|string|exists:currencies,code',
            'prices.*.regular_price'           => 'required|numeric|min:0',
            'prices.*.sale_price'              => 'nullable|numeric|min:0',
            'prices.*.cost_price'              => 'nullable|numeric|min:0',
            'prices.*.sale_start_date'         => 'nullable|date',
            'prices.*.sale_end_date'           => 'nullable|date',
            'seo'                              => 'sometimes|array',
            'seo.*.language_code'              => 'required|string',
            'seo.*.meta_title'                 => 'nullable|string|max:255',
            'seo.*.meta_description'           => 'nullable|string',
            'seo.*.meta_keywords'              => 'nullable|string',
            'seo.*.canonical_url'              => 'nullable|url',
            'seo.*.og_title'                   => 'nullable|string|max:255',
            'seo.*.og_description'             => 'nullable|string',
            'measurements'                     => 'sometimes|nullable|array',
            'measurements.*.name'              => 'required_with:measurements|string|max:100',
            'measurements.*.unit'              => 'nullable|string|max:30',
            'measurements.*.required'          => 'boolean',
            // Phase 2 - tax rate IDs
            'tax_rate_ids'                     => 'sometimes|nullable|array',
            'tax_rate_ids.*'                   => 'integer|exists:tax_rates,id',
        ]);

        DB::beginTransaction();
        try {
            $coreFields = [
                'category_id', 'product_type', 'status', 'is_featured',
                'is_producible', 'brand', 'tax_class', 'low_stock_threshold',
                'weight', 'length', 'width', 'height',
            ];

            $update = [];

            if (array_key_exists('measurements', $validated)) {
                $update['measurements'] = !empty($validated['measurements'])
                    ? $validated['measurements']
                    : null;
            }

            foreach ($coreFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $update[$field] = $validated[$field];
                }
            }

            if (isset($validated['sku'])) {
                $update['sku'] = strtoupper($validated['sku']);
            }

            if (isset($validated['slug'])) {
                $update['slug'] = $this->generateSlug($validated['slug'], $product->id);
            }

            if (isset($validated['status']) && $validated['status'] === 'active' && !$product->published_at) {
                $update['published_at'] = now();
            }

            if (!empty($update)) {
                $product->update($update);
            }

            // Upsert translations
            if (!empty($validated['translations'])) {
                foreach ($validated['translations'] as $trans) {
                    ProductTranslation::updateOrCreate(
                        ['product_id' => $product->id, 'language_code' => $trans['language_code']],
                        [
                            'name'              => $trans['name'],
                            'description'       => $trans['description'],
                            'short_description' => $trans['short_description'] ?? '',
                            'specifications'    => $trans['specifications'] ?? null,
                        ]
                    );
                }
            }

            // Upsert base prices
            if (!empty($validated['prices'])) {
                foreach ($validated['prices'] as $price) {
                    ProductPrice::updateOrCreate(
                        [
                            'product_id'         => $product->id,
                            'product_variant_id' => null,
                            'currency_code'      => $price['currency_code'],
                        ],
                        [
                            'regular_price'   => $price['regular_price'],
                            'sale_price'      => $price['sale_price'] ?? null,
                            'cost_price'      => $price['cost_price'] ?? null,
                            'sale_start_date' => $price['sale_start_date'] ?? null,
                            'sale_end_date'   => $price['sale_end_date'] ?? null,
                        ]
                    );
                }
            }

            // Upsert SEO
            if (!empty($validated['seo'])) {
                foreach ($validated['seo'] as $seo) {
                    ProductSeo::updateOrCreate(
                        ['product_id' => $product->id, 'language_code' => $seo['language_code']],
                        array_diff_key($seo, ['language_code' => true])
                    );
                }
            }

            // Phase 2 - sync tax rates (only if key was present in the request)
            if (array_key_exists('tax_rate_ids', $validated)) {
                $taxRateIds = $validated['tax_rate_ids'] ?? [];
                $this->syncTaxRates($product->id, $taxRateIds);
            }

            DB::commit();

            ActivityLogService::log('updated', $product, [
                'sku'    => $product->sku,
                'status' => $product->status,
            ]);

            $detail = $this->formatDetail($product->fresh()->load([
                'category', 'translations', 'prices', 'variants.prices', 'variants.images', 'images', 'seo',
            ]));
            $detail['tax_rate_ids'] = DB::table('product_tax_rates')->where('product_id', $id)->pluck('tax_rate_id')->toArray();
            $detail['tax_rates']    = $this->loadTaxRates($id);

            return response()->json([
                'message' => 'Product updated successfully.',
                'product' => $detail,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update product.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/v1/admin/products/{id}
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        try {
            $inOrders = DB::table('order_items')
                ->whereExists(fn ($q) =>
                    $q->select(DB::raw(1))
                      ->from('product_variants')
                      ->whereColumn('product_variants.id', 'order_items.product_variant_id')
                      ->where('product_variants.product_id', $product->id)
                )
                ->exists();

            if ($inOrders) {
                return response()->json([
                    'message' => 'Cannot delete a product that has been ordered. Archive it instead.',
                ], 422);
            }
        } catch (\Exception) {}

        DB::beginTransaction();
        try {
            foreach ($product->images as $image) {
                $this->deleteImageFile($image->image_url);
            }

            // Phase 2 - remove tax rate assignments before deleting
            DB::table('product_tax_rates')->where('product_id', $product->id)->delete();

            $product->delete();
            DB::commit();

            ActivityLogService::log('deleted', $product, ['sku' => $product->sku]);

            return response()->json(['message' => 'Product deleted.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete product.', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Status ─────────────────────────────────────────────────────────────────

    public function publish($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => 'active', 'published_at' => $product->published_at ?? now()]);

        try {
            ActivityLogService::log('product_published', $product, [
                'sku'  => $product->sku,
                'name' => $product->translations->firstWhere('language_code', 'en')?->name ?? $product->sku,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Product published.',
            'product' => $this->formatDetail($product->fresh()->load(['translations', 'prices', 'variants', 'images', 'seo'])),
        ]);
    }

    public function archive($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => 'archived']);

        try {
            ActivityLogService::log('product_archived', $product, [
                'sku'  => $product->sku,
                'name' => $product->translations->firstWhere('language_code', 'en')?->name ?? $product->sku,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Product archived.']);
    }

    // ── Images ─────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/admin/products/{id}/images
     */
    public function uploadImages(Request $request, $id)
    {
        $request->validate([
            'images'   => 'required|array|min:1|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $product      = Product::findOrFail($id);
        $maxOrder     = $product->images()->max('sort_order') ?? 0;
        $isPrimary    = !$product->images()->exists();
        $uploaded     = [];
        $imageService = app(ImageService::class);

        foreach ($request->file('images') as $i => $file) {
            $result = $imageService->process($file, "products/{$product->id}", 'product');

            $image = ProductImage::create([
                'product_id'         => $product->id,
                'product_variant_id' => null,
                'image_url'          => $result['url'],
                'thumbnail_url'      => $result['thumbnail_url'] ?? $result['url'],
                'alt_text'           => $product->translations->firstWhere('language_code', 'en')?->name ?? '',
                'is_primary'         => $isPrimary && $i === 0,
                'sort_order'         => $maxOrder + $i + 1,
            ]);

            $uploaded[] = $image;
        }

        return response()->json([
            'message' => count($uploaded) . ' image(s) uploaded.',
            'images'  => $uploaded,
        ]);
    }

    /**
     * PUT /api/v1/admin/products/{productId}/images/{imageId}/primary
     */
    public function setPrimaryImage($productId, $imageId)
    {
        $product = Product::findOrFail($productId);
        $product->images()->update(['is_primary' => false]);
        $product->images()->where('id', $imageId)->update(['is_primary' => true]);

        return response()->json(['message' => 'Primary image updated.']);
    }

    /**
     * PUT /api/v1/admin/products/{id}/images/reorder
     */
    public function reorderImages(Request $request, $id)
    {
        $request->validate([
            'images'            => 'required|array',
            'images.*.id'       => 'required|integer',
            'images.*.sort_order' => 'required|integer',
        ]);

        foreach ($request->images as $item) {
            ProductImage::where('id', $item['id'])
                ->where('product_id', $id)
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'Images reordered.']);
    }

    /**
     * DELETE /api/v1/admin/products/{id}/images/{imageId}
     */
    public function deleteImage($productId, $imageId)
    {
        $image = ProductImage::where('product_id', $productId)->where('id', $imageId)->firstOrFail();
        $this->deleteImageFile($image->image_url);
        $image->delete();

        // Reassign primary if needed
        if ($image->is_primary) {
            ProductImage::where('product_id', $productId)->orderBy('sort_order')->first()?->update(['is_primary' => true]);
        }

        return response()->json(['message' => 'Image deleted.']);
    }

    // ── Variants ───────────────────────────────────────────────────────────────

    /**
     * Return $base if free, else the next available `$base-2`, `$base-3`, …
     * variant SKU. Lets bulk "Generate Variants" resolve colliding auto-generated
     * SKUs (e.g. two attribute values sharing a 3-letter abbreviation, or a combo
     * already stocked) instead of failing the whole save.
     */
    private function resolveUniqueVariantSku(string $base): string
    {
        $base = substr($base, 0, 100);
        if (!ProductVariant::where('sku', $base)->exists()) {
            return $base;
        }
        for ($i = 2; $i < 10000; $i++) {
            $candidate = substr($base, 0, 100 - strlen("-{$i}")) . "-{$i}";
            if (!ProductVariant::where('sku', $candidate)->exists()) {
                return $candidate;
            }
        }
        // Unreachable in practice; keep a deterministic fallback.
        return substr($base, 0, 90) . '-' . uniqid();
    }

    public function addVariant(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);

        $validated = $request->validate([
            // Not `unique:` — a colliding SKU is auto-resolved to the next free
            // one below (…-GRE-MIN → …-GRE-MIN-2 → -3) rather than hard-rejected,
            // so bulk "Generate Variants" never fails on a duplicate abbreviation.
            'sku'          => "required|string|max:100",
            'variant_name' => 'required|string|max:255',
            'attributes'   => 'nullable|array',
            'weight'       => 'nullable|numeric|min:0',
            'is_default'   => 'boolean',
            'is_active'    => 'boolean',
            'prices'                    => 'required|array|min:1',
            'prices.*.currency_code'    => 'required|string|exists:currencies,code',
            'prices.*.regular_price'    => 'required|numeric|min:0',
            'prices.*.sale_price'       => 'nullable|numeric|min:0',
            'prices.*.cost_price'       => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            if ($validated['is_default'] ?? false) {
                $product->variants()->update(['is_default' => false]);
            }

            // Resolve a globally-unique SKU: if the requested one is taken, append
            // the next free numeric suffix (-2, -3, …). SKUs are stored uppercased,
            // so uniqueness is checked against the uppercased value.
            $sku = $this->resolveUniqueVariantSku(strtoupper($validated['sku']));

            $variant = $product->variants()->create([
                'sku'          => $sku,
                'variant_name' => $validated['variant_name'],
                'attributes'   => $validated['attributes'] ?? [],
                'weight'       => $validated['weight'] ?? null,
                'is_default'   => $validated['is_default'] ?? false,
                'is_active'    => $validated['is_active'] ?? true,
            ]);

            foreach ($validated['prices'] as $price) {
                ProductPrice::create([
                    'product_id'         => $productId,
                    'product_variant_id' => $variant->id,
                    'currency_code'      => $price['currency_code'],
                    'regular_price'      => $price['regular_price'],
                    'sale_price'         => $price['sale_price'] ?? null,
                    'cost_price'         => $price['cost_price'] ?? null,
                ]);
            }

            DB::commit();

            try {
                ActivityLogService::log('product_variant_created', $product, [
                    'variant_id'   => $variant->id,
                    'sku'          => $variant->sku,
                    'variant_name' => $variant->variant_name,
                    'product_sku'  => $product->sku,
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Variant created.',
                'variant' => $variant->load(['prices', 'images']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create variant.', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateVariant(Request $request, $productId, $variantId)
    {
        $variant = ProductVariant::where('product_id', $productId)->where('id', $variantId)->firstOrFail();

        $validated = $request->validate([
            'sku'          => ['sometimes', 'string', 'max:100', Rule::unique('product_variants')->ignore($variant->id)],
            'variant_name' => 'sometimes|string|max:255',
            'attributes'   => 'sometimes|nullable|array',
            'weight'       => 'sometimes|nullable|numeric|min:0',
            'is_default'   => 'sometimes|boolean',
            'is_active'    => 'sometimes|boolean',
            'prices'                    => 'sometimes|array',
            'prices.*.currency_code'    => 'required|string|exists:currencies,code',
            'prices.*.regular_price'    => 'required|numeric|min:0',
            'prices.*.sale_price'       => 'nullable|numeric|min:0',
            'prices.*.cost_price'       => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            if (isset($validated['is_default']) && $validated['is_default']) {
                ProductVariant::where('product_id', $productId)->update(['is_default' => false]);
            }

            $updateData = array_intersect_key($validated, array_flip([
                'variant_name', 'attributes', 'weight', 'is_default', 'is_active',
            ]));
            if (isset($validated['sku'])) {
                $updateData['sku'] = strtoupper($validated['sku']);
            }

            $variant->update($updateData);

            if (!empty($validated['prices'])) {
                foreach ($validated['prices'] as $price) {
                    ProductPrice::updateOrCreate(
                        ['product_variant_id' => $variant->id, 'currency_code' => $price['currency_code']],
                        [
                            'product_id'    => $productId,
                            'regular_price' => $price['regular_price'],
                            'sale_price'    => $price['sale_price'] ?? null,
                            'cost_price'    => $price['cost_price'] ?? null,
                        ]
                    );
                }
            }

            DB::commit();

            try {
                $product = \App\Models\Product::find($productId);
                ActivityLogService::log('product_variant_updated', $product, [
                    'variant_id'   => $variant->id,
                    'sku'          => $variant->sku,
                    'variant_name' => $variant->variant_name,
                    'changes'      => array_keys($updateData),
                ]);
            } catch (\Exception) {}

            return response()->json([
                'message' => 'Variant updated.',
                'variant' => $variant->fresh()->load(['prices', 'images']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update variant.', 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteVariant($productId, $variantId)
    {
        $variant = ProductVariant::where('product_id', $productId)->where('id', $variantId)->firstOrFail();

        $inOrders = DB::table('order_items')->where('product_variant_id', $variantId)->exists();
        if ($inOrders) {
            return response()->json(['message' => 'Cannot delete a variant that has been ordered.'], 422);
        }

        $variantSku  = $variant->sku;
        $variantName = $variant->variant_name;
        $variant->delete();

        try {
            $product = \App\Models\Product::find($productId);
            ActivityLogService::log('product_variant_deleted', $product, [
                'variant_id'   => $variantId,
                'sku'          => $variantSku,
                'variant_name' => $variantName,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Variant deleted.']);
    }

    // ── Bulk import ────────────────────────────────────────────────────────────

    public function bulkImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file   = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return response()->json(['message' => 'Could not read the file.'], 422);
        }

        $headers  = null;
        $imported = 0;
        $errors   = [];
        $row      = 0;

        DB::beginTransaction();
        try {
            while (($line = fgetcsv($handle)) !== false) {
                $row++;
                if ($row === 1) {
                    $headers = array_map('trim', $line);
                    continue;
                }

                if (count($line) < count($headers ?? [])) continue;

                $record = array_combine($headers, array_map('trim', $line));

                if (empty($record['name_en']) || empty($record['sku'])) {
                    $errors[] = "Row {$row}: name_en and sku are required.";
                    continue;
                }

                try {
                    $product = Product::create([
                        'sku'             => strtoupper($record['sku']),
                        'slug'            => $this->generateSlug($record['name_en']),
                        'category_id'     => !empty($record['category_id']) ? (int) $record['category_id'] : null,
                        'product_type'    => in_array($record['product_type'] ?? '', ['simple', 'variable', 'made_to_order']) ? $record['product_type'] : 'simple',
                        'status'          => in_array($record['status'] ?? '', ['draft', 'active', 'inactive', 'archived']) ? $record['status'] : 'draft',
                        'is_featured'     => filter_var($record['is_featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
                        'is_producible'   => filter_var($record['is_producible'] ?? false, FILTER_VALIDATE_BOOLEAN),
                        'brand'           => $record['brand'] ?? null,
                        'weight'          => !empty($record['weight']) ? (float) $record['weight'] : null,
                        'low_stock_threshold' => (int) ($record['low_stock_threshold'] ?? 5),
                        'published_at'    => ($record['status'] ?? '') === 'active' ? now() : null,
                    ]);

                    ProductTranslation::create([
                        'product_id'        => $product->id,
                        'language_code'     => 'en',
                        'name'              => $record['name_en'],
                        'description'       => $record['description_en'],
                        'short_description' => $record['short_description_en'] ?? '',
                    ]);

                    if (!empty($record['price_kes'])) {
                        ProductPrice::create([
                            'product_id'         => $product->id,
                            'product_variant_id' => null,
                            'currency_code'      => 'KES',
                            'regular_price'      => (float) $record['price_kes'],
                            'cost_price'         => !empty($record['cost_kes']) ? (float) $record['cost_kes'] : null,
                        ]);
                    }

                    if (!empty($record['price_usd'])) {
                        ProductPrice::create([
                            'product_id'         => $product->id,
                            'product_variant_id' => null,
                            'currency_code'      => 'USD',
                            'regular_price'      => (float) $record['price_usd'],
                        ]);
                    }

                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Row {$row}: " . $e->getMessage();
                }
            }

            fclose($handle);
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            fclose($handle);
            return response()->json(['message' => 'Import failed: ' . $e->getMessage()], 500);
        }

        try {
            ActivityLogService::log('products_bulk_imported', null, [
                'imported_count' => $imported,
                'error_count'    => count($errors),
                'filename'       => $request->file('file')->getClientOriginalName(),
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message'  => "{$imported} product(s) imported successfully.",
            'imported' => $imported,
            'errors'   => $errors,
        ]);
    }

    /**
     * GET /api/v1/admin/products/export-template
     */
    public function exportTemplate()
    {
        $headers = [
            'sku', 'name_en', 'description_en', 'short_description_en',
            'category_id', 'product_type', 'status', 'price_kes', 'price_usd',
            'cost_kes', 'brand', 'weight', 'is_featured', 'is_producible', 'low_stock_threshold',
        ];

        $example = [
            'BH-001', 'Classic Ankara Dress', 'A beautiful Ankara dress...', 'Made from quality Ankara fabric.',
            '1', 'simple', 'draft', '2500', '19.50',
            '1200', 'Bethany House', '0.5', 'false', 'true', '5',
        ];

        $csv = implode(',', $headers) . "\n" . implode(',', $example) . "\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=products-import-template.csv',
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Sync the product_tax_rates pivot.
     * Deletes all existing assignments then re-inserts the provided IDs.
     */
    private function syncTaxRates(int $productId, array $taxRateIds): void
    {
        DB::table('product_tax_rates')->where('product_id', $productId)->delete();

        if (!empty($taxRateIds)) {
            $now     = now();
            $inserts = array_map(fn ($rateId) => [
                'product_id'  => $productId,
                'tax_rate_id' => (int) $rateId,
                'created_at'  => $now,
                'updated_at'  => $now,
            ], array_unique($taxRateIds));

            DB::table('product_tax_rates')->insert($inserts);
        }

        TaxCalculationService::invalidateProductCache($productId);
    }

    /**
     * Fetch tax rate rows for a product (used in response formatting).
     */
    private function loadTaxRates(int $productId): array
    {
        return DB::table('product_tax_rates as ptr')
            ->join('tax_rates as tr', 'ptr.tax_rate_id', '=', 'tr.id')
            ->where('ptr.product_id', $productId)
            ->select('tr.id', 'tr.name', 'tr.rate', 'tr.code', 'tr.tax_type', 'tr.is_default', 'tr.is_active')
            ->get()
            ->toArray();
    }

    private function generateSlug(string $base, ?int $excludeId = null): string
    {
        $slug     = Str::slug($base);
        $original = $slug;
        $i        = 1;

        while (
            Product::where('slug', $slug)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = "{$original}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function deleteImageFile(string $url): void
    {
        try {
            app(ImageService::class)->delete($url, 'public');
        } catch (\Exception) {}
    }

    private function formatListItem(Product $product): array
    {
        $enTrans   = $product->translations->firstWhere('language_code', 'en');
        $primary   = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
        $basePrice = $product->prices->whereNull('product_variant_id')->firstWhere('currency_code', 'KES');

        return [
            'id'                  => $product->id,
            'uuid'                => $product->uuid,
            'sku'                 => $product->sku,
            'slug'                => $product->slug,
            'status'              => $product->status,
            'product_type'        => $product->product_type,
            'is_featured'         => (bool) $product->is_featured,
            'is_producible'       => (bool) $product->is_producible,
            'brand'               => $product->brand,
            'low_stock_threshold' => $product->low_stock_threshold,
            'published_at'        => $product->published_at,
            'created_at'          => $product->created_at,
            'category'            => $product->category ? ['id' => $product->category->id, 'name_en' => $product->category->name_en] : null,
            'en_translation'      => $enTrans ? ['name' => $enTrans->name, 'short_description' => $enTrans->short_description] : null,
            'primary_image'       => $primary ? ['id' => $primary->id, 'image_url' => $primary->image_url, 'alt_text' => $primary->alt_text] : null,
            'base_price'          => $basePrice ? ['regular_price' => $basePrice->regular_price, 'sale_price' => $basePrice->sale_price, 'currency_code' => 'KES'] : null,
            'variants_count'      => $product->variants_count ?? 0,
        ];
    }

    private function formatDetail(Product $product): array
    {
        return [
            'id'                  => $product->id,
            'uuid'                => $product->uuid,
            'sku'                 => $product->sku,
            'slug'                => $product->slug,
            'category_id'         => $product->category_id,
            'product_type'        => $product->product_type,
            'status'              => $product->status,
            'is_featured'         => (bool) $product->is_featured,
            'is_producible'       => (bool) $product->is_producible,
            'brand'               => $product->brand,
            'tax_class'           => $product->tax_class,
            'low_stock_threshold' => $product->low_stock_threshold,
            'weight'              => $product->weight,
            'length'              => $product->length,
            'width'               => $product->width,
            'height'              => $product->height,
            'published_at'        => $product->published_at,
            'created_at'          => $product->created_at,
            'updated_at'          => $product->updated_at,
            'category'            => $product->category ? ['id' => $product->category->id, 'name_en' => $product->category->name_en, 'slug' => $product->category->slug] : null,
            'translations'        => $product->translations->values(),
            'prices'              => $product->prices->whereNull('product_variant_id')->values(),
            'variants'            => $product->variants->map(fn ($v) => [
                'id'           => $v->id,
                'sku'          => $v->sku,
                'variant_name' => $v->variant_name,
                'attributes'   => $v->attributes,
                'weight'       => $v->weight,
                'is_default'   => (bool) $v->is_default,
                'is_active'    => (bool) $v->is_active,
                'prices'       => $v->prices->values(),
                'images'       => $v->images->values(),
            ])->values(),
            'images'              => $product->images->sortBy('sort_order')->values(),
            'seo'                 => $product->seo->values(),
            'measurements'        => $product->measurements ?? [],
            // tax_rate_ids and tax_rates are added in adminShow/store/update
            'tax_rate_ids'        => [],
            'tax_rates'           => [],
        ];
    }

    private function formatPublic(Product $product): array
    {
        return $this->formatDetail($product);
    }
}