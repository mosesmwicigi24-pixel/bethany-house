<?php

namespace App\Http\Livewire\Admin\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSeo;
use App\Models\ProductTranslation;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProductEdit extends Component
{
    use WithFileUploads;

    // ── Loaded product ─────────────────────────────────────────────────────────
    public Product $product;

    // ── Active tab ─────────────────────────────────────────────────────────────
    public string $activeTab = 'details';

    // ── Core fields ────────────────────────────────────────────────────────────
    public string $name_en        = '';
    public string $name_fr        = '';
    public string $name_pt        = '';
    public string $sku            = '';
    public string $category_id    = '';
    public string $price_kes      = '';
    public string $price_usd      = '';
    public string $description_en = '';
    public string $description_fr = '';
    public string $description_pt = '';
    public string $weight         = '';
    public string $type           = 'simple';
    public string $status         = 'draft';
    public bool   $is_featured    = false;
    public bool   $is_producible  = false;

    // ── SEO ────────────────────────────────────────────────────────────────────
    public string $meta_title       = '';
    public string $meta_description = '';
    public string $meta_keywords    = '';

    // ── New images to upload ───────────────────────────────────────────────────
    public array $newImages = [];

    // ── Existing images (for reorder / delete) ─────────────────────────────────
    // Each row: [id, image_url, is_primary, sort_order, marked_for_deletion]
    public array $existingImages = [];

    // ── Variants ───────────────────────────────────────────────────────────────
    // Each row mirrors what's in the DB + editable fields
    public array $variants = [];

    // New variant row being added inline
    public bool   $showAddVariant   = false;
    public string $newVariantName   = '';
    public string $newVariantSku    = '';
    public array  $newVariantAttrs  = []; // ['Color' => 'Red']
    public string $newAttrKey       = '';
    public string $newAttrValue     = '';
    public string $newVariantKes    = '';
    public string $newVariantUsd    = '';

    // ── State ──────────────────────────────────────────────────────────────────
    public bool   $saving       = false;
    public string $flashMessage = '';
    public string $flashType    = 'success';

    /**
     * Maps each field to the tab it lives on - used for auto-tab-switching
     * when validation fails on a hidden tab.
     */
    private array $fieldTabMap = [
        'name_en'          => 'details',
        'sku'              => 'details',
        'category_id'      => 'details',
        'price_kes'        => 'details',
        'price_usd'        => 'details',
        'description_en'   => 'details',
        'weight'           => 'details',
        'name_fr'          => 'translations',
        'name_pt'          => 'translations',
        'description_fr'   => 'translations',
        'description_pt'   => 'translations',
        'meta_title'       => 'seo',
        'meta_description' => 'seo',
        'meta_keywords'    => 'seo',
    ];

    // ── Mount ──────────────────────────────────────────────────────────────────

    public function mount(Product $product): void
    {
        $this->product = $product->load([
            'translations',
            'prices',
            'images'    => fn($q) => $q->orderBy('sort_order'),
            'variants.prices',
            'seo',
        ]);

        // ── Translations ──
        $en = $product->getTranslation('en');
        $fr = $product->getTranslation('fr');
        $pt = $product->getTranslation('pt');

        $this->name_en        = $en?->name        ?? '';
        $this->description_en = $en?->description ?? '';
        $this->name_fr        = $fr?->name        ?? '';
        $this->description_fr = $fr?->description ?? '';
        $this->name_pt        = $pt?->name        ?? '';
        $this->description_pt = $pt?->description ?? '';

        // ── Product table columns ──
        $this->sku          = $product->sku;
        $this->category_id  = (string) ($product->category_id ?? '');
        $this->type         = $product->product_type;
        $this->status       = $product->status;
        $this->is_featured  = $product->is_featured;
        $this->is_producible= $product->is_producible;
        $this->weight       = $product->weight ? (string) $product->weight : '';

        // ── Base prices (variant_id IS NULL) ──
        $kesPrice = $product->getPriceForCurrency('KES');
        $usdPrice = $product->getPriceForCurrency('USD');
        $this->price_kes = $kesPrice ? (string) $kesPrice->regular_price : '';
        $this->price_usd = $usdPrice ? (string) $usdPrice->regular_price : '';

        // ── SEO ──
        $seo = $product->seo->firstWhere('language_code', 'en');
        $this->meta_title       = $seo?->meta_title       ?? '';
        $this->meta_description = $seo?->meta_description ?? '';
        $this->meta_keywords    = $seo?->meta_keywords    ?? '';

        // ── Existing images ──
        $this->existingImages = $product->images->map(fn($img) => [
            'id'                 => $img->id,
            'image_url'          => $img->image_url,
            'is_primary'         => $img->is_primary,
            'sort_order'         => $img->sort_order,
            'marked_for_deletion'=> false,
        ])->toArray();

        // ── Variants ──
        $this->variants = $product->variants->map(fn($v) => [
            'id'           => $v->id,
            'sku'          => $v->sku,
            'variant_name' => $v->variant_name ?? '',
            'attributes'   => $v->attributes ?? [],
            'is_default'   => $v->is_default,
            'is_active'    => $v->is_active,
            'price_kes'    => (string) ($v->prices->firstWhere('currency_code', 'KES')?->regular_price ?? ''),
            'price_usd'    => (string) ($v->prices->firstWhere('currency_code', 'USD')?->regular_price ?? ''),
            'sale_kes'     => (string) ($v->prices->firstWhere('currency_code', 'KES')?->sale_price ?? ''),
            'sale_usd'     => (string) ($v->prices->firstWhere('currency_code', 'USD')?->sale_price ?? ''),
        ])->toArray();
    }

    // ── Computed ───────────────────────────────────────────────────────────────

    #[Computed]
    public function categories()
    {
        return Category::withoutGlobalScopes()
            ->with(['translations', 'children.children', 'children.translations', 'children.children.translations'])
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function serpTitle(): string
    {
        return $this->meta_title ?: $this->name_en ?: 'Product Name';
    }

    #[Computed]
    public function serpSlug(): string
    {
        return $this->name_en ? Str::slug($this->name_en) : 'product-slug';
    }

    #[Computed]
    public function serpDesc(): string
    {
        return $this->meta_description ?: 'Meta description will appear here...';
    }

    #[Computed]
    public function tabErrors(): array
    {
        $errors    = $this->getErrorBag()->toArray();
        $tabErrors = ['details' => false, 'translations' => false, 'variants' => false, 'seo' => false];

        foreach (array_keys($errors) as $field) {
            $tab = $this->fieldTabMap[$field] ?? null;
            if ($tab) {
                $tabErrors[$tab] = true;
            }
        }

        return $tabErrors;
    }

    // ── Image management ───────────────────────────────────────────────────────

    public function setPrimary(int $index): void
    {
        foreach ($this->existingImages as $i => $img) {
            $this->existingImages[$i]['is_primary'] = ($i === $index);
        }
    }

    public function toggleDeleteImage(int $index): void
    {
        $this->existingImages[$index]['marked_for_deletion'] =
            !$this->existingImages[$index]['marked_for_deletion'];
    }

    public function moveImageUp(int $index): void
    {
        if ($index === 0) return;
        [$this->existingImages[$index - 1], $this->existingImages[$index]] =
            [$this->existingImages[$index], $this->existingImages[$index - 1]];
        $this->reindexSortOrders();
    }

    public function moveImageDown(int $index): void
    {
        if ($index >= count($this->existingImages) - 1) return;
        [$this->existingImages[$index], $this->existingImages[$index + 1]] =
            [$this->existingImages[$index + 1], $this->existingImages[$index]];
        $this->reindexSortOrders();
    }

    protected function reindexSortOrders(): void
    {
        foreach ($this->existingImages as $i => $img) {
            $this->existingImages[$i]['sort_order'] = $i;
        }
    }

    // ── Variant management ─────────────────────────────────────────────────────

    public function addNewAttr(): void
    {
        if ($this->newAttrKey) {
            $this->newVariantAttrs[trim($this->newAttrKey)] = trim($this->newAttrValue);
            $this->newAttrKey   = '';
            $this->newAttrValue = '';
        }
    }

    public function removeNewAttr(string $key): void
    {
        unset($this->newVariantAttrs[$key]);
    }

    public function saveNewVariant(): void
    {
        $this->validate([
            'newVariantSku' => 'required|string|unique:product_variants,sku',
            'newVariantKes' => 'required|numeric|min:0',
            'newVariantUsd' => 'required|numeric|min:0',
        ], [], [
            'newVariantSku' => 'SKU',
            'newVariantKes' => 'KES Price',
            'newVariantUsd' => 'USD Price',
        ]);

        DB::transaction(function () {
            $variant = ProductVariant::create([
                'product_id'   => $this->product->id,
                'sku'          => $this->newVariantSku,
                'variant_name' => $this->newVariantName ?: null,
                'attributes'   => $this->newVariantAttrs ?: [],
                'is_default'   => false,
                'is_active'    => true,
            ]);

            ProductPrice::create([
                'product_id'         => $this->product->id,
                'product_variant_id' => $variant->id,
                'currency_code'      => 'KES',
                'regular_price'      => $this->newVariantKes,
            ]);
            ProductPrice::create([
                'product_id'         => $this->product->id,
                'product_variant_id' => $variant->id,
                'currency_code'      => 'USD',
                'regular_price'      => $this->newVariantUsd,
            ]);

            // Add to local state
            $this->variants[] = [
                'id'           => $variant->id,
                'sku'          => $variant->sku,
                'variant_name' => $variant->variant_name ?? '',
                'attributes'   => $variant->attributes ?? [],
                'is_default'   => false,
                'is_active'    => true,
                'price_kes'    => $this->newVariantKes,
                'price_usd'    => $this->newVariantUsd,
                'sale_kes'     => '',
                'sale_usd'     => '',
            ];
        });

        $this->reset(['newVariantName', 'newVariantSku', 'newVariantAttrs', 'newVariantKes', 'newVariantUsd', 'newAttrKey', 'newAttrValue']);
        $this->showAddVariant = false;
        session()->flash('success', 'Variant added.');
    }

    public function toggleVariantActive(int $variantId): void
    {
        $variant = ProductVariant::findOrFail($variantId);
        $variant->update(['is_active' => !$variant->is_active]);

        foreach ($this->variants as $i => $v) {
            if ($v['id'] === $variantId) {
                $this->variants[$i]['is_active'] = !$this->variants[$i]['is_active'];
                break;
            }
        }
    }

    public function setDefaultVariant(int $variantId): void
    {
        ProductVariant::where('product_id', $this->product->id)->update(['is_default' => false]);
        ProductVariant::findOrFail($variantId)->update(['is_default' => true]);

        foreach ($this->variants as $i => $v) {
            $this->variants[$i]['is_default'] = ($v['id'] === $variantId);
        }
    }

    // ── Save ───────────────────────────────────────────────────────────────────

    public function save(string $status = null): void
    {
        if ($status) {
            $this->status = $status;
        }

        $this->flashMessage = '';

        $skuRule = 'required|string|max:100|unique:products,sku,' . $this->product->id;

        try {
            $this->validate([
                'name_en'          => 'required|string|max:255',
                'name_fr'          => 'nullable|string|max:255',
                'name_pt'          => 'nullable|string|max:255',
                'sku'              => $skuRule,
                'category_id'      => 'required|exists:categories,id',
                'price_kes'        => 'required|numeric|min:0',
                'price_usd'        => 'required|numeric|min:0',
                'description_en'   => 'required|string',
                'description_fr'   => 'nullable|string',
                'description_pt'   => 'nullable|string',
                'weight'           => 'nullable|numeric|min:0',
                'type'             => 'required|in:simple,variant,made_to_order',
                'status'           => 'required|in:draft,active,archived',
                'meta_title'       => 'nullable|string|max:255',
                'meta_description' => 'nullable|string',
                'meta_keywords'    => 'nullable|string',
                'newImages.*'      => 'nullable|image|max:5120',
            ]);
        } catch (\Illuminate\Validation\ValidationException) {
            $this->redirectToFirstErrorTab();
            return;
        }

        $this->saving = true;

        DB::beginTransaction();
        try {
            // ── 1. Product row ────────────────────────────────────────────
            $wasActive   = $this->product->status === 'active';
            $nowActive   = $this->status === 'active';
            $publishedAt = $this->product->published_at;

            if ($nowActive && !$wasActive) {
                $publishedAt = now();
            } elseif (!$nowActive) {
                $publishedAt = null;
            }

            $this->product->update([
                'sku'           => $this->sku,
                'category_id'   => $this->category_id,
                'product_type'  => $this->type,
                'status'        => $this->status,
                'is_featured'   => $this->is_featured,
                'is_producible' => $this->type === 'made_to_order' ? true : $this->is_producible,
                'weight'        => $this->weight ?: null,
                'published_at'  => $publishedAt,
            ]);

            // ── 2. Translations ───────────────────────────────────────────
            $translationData = [
                'en' => ['name' => $this->name_en, 'description' => $this->description_en],
                'fr' => ['name' => $this->name_fr, 'description' => $this->description_fr ?: null],
                'pt' => ['name' => $this->name_pt, 'description' => $this->description_pt ?: null],
            ];

            foreach ($translationData as $lang => $data) {
                if ($lang !== 'en' && !$data['name']) {
                    // Remove translation if name is cleared
                    ProductTranslation::where('product_id', $this->product->id)
                        ->where('language_code', $lang)
                        ->delete();
                    continue;
                }
                ProductTranslation::updateOrCreate(
                    ['product_id' => $this->product->id, 'language_code' => $lang],
                    $data
                );
            }

            // ── 3. Base product prices (variant_id IS NULL) ───────────────
            ProductPrice::updateOrCreate(
                ['product_id' => $this->product->id, 'product_variant_id' => null, 'currency_code' => 'KES'],
                ['regular_price' => $this->price_kes]
            );
            ProductPrice::updateOrCreate(
                ['product_id' => $this->product->id, 'product_variant_id' => null, 'currency_code' => 'USD'],
                ['regular_price' => $this->price_usd]
            );

            // ── 4. Variant prices (from editable rows) ────────────────────
            foreach ($this->variants as $v) {
                ProductPrice::updateOrCreate(
                    ['product_id' => $this->product->id, 'product_variant_id' => $v['id'], 'currency_code' => 'KES'],
                    ['regular_price' => $v['price_kes'] ?: $this->price_kes, 'sale_price' => $v['sale_kes'] ?: null]
                );
                ProductPrice::updateOrCreate(
                    ['product_id' => $this->product->id, 'product_variant_id' => $v['id'], 'currency_code' => 'USD'],
                    ['regular_price' => $v['price_usd'] ?: $this->price_usd, 'sale_price' => $v['sale_usd'] ?: null]
                );

                // Update variant SKU + name if they changed
                ProductVariant::where('id', $v['id'])->update([
                    'sku'          => $v['sku'],
                    'variant_name' => $v['variant_name'] ?: null,
                ]);
            }

            // ── 5. SEO ────────────────────────────────────────────────────
            if ($this->meta_title || $this->meta_description || $this->meta_keywords) {
                ProductSeo::updateOrCreate(
                    ['product_id' => $this->product->id, 'language_code' => 'en'],
                    [
                        'meta_title'       => $this->meta_title ?: null,
                        'meta_description' => $this->meta_description ?: null,
                        'meta_keywords'    => $this->meta_keywords ?: null,
                    ]
                );
            }

            // ── 6. Delete marked images ───────────────────────────────────
            foreach ($this->existingImages as $img) {
                if ($img['marked_for_deletion']) {
                    $record = ProductImage::find($img['id']);
                    if ($record) {
                        // Attempt to delete the file from storage
                        $path = str_replace(Storage::disk('public')->url(''), '', $record->image_url);
                        Storage::disk('public')->delete($path);
                        $record->delete();
                    }
                }
            }

            // ── 7. Update sort order + primary on surviving images ────────
            foreach ($this->existingImages as $img) {
                if (!$img['marked_for_deletion']) {
                    ProductImage::where('id', $img['id'])->update([
                        'is_primary' => $img['is_primary'],
                        'sort_order' => $img['sort_order'],
                    ]);
                }
            }

            // ── 8. Upload new images ──────────────────────────────────────
            $hasAnyPrimary = collect($this->existingImages)
                ->where('marked_for_deletion', false)
                ->where('is_primary', true)
                ->isNotEmpty();

            foreach ($this->newImages as $i => $image) {
                $path = $image->store('products', 'public');
                $url  = Storage::disk('public')->url($path);

                $isPrimary = !$hasAnyPrimary && $i === 0;

                $this->product->images()->create([
                    'image_url'  => $url,
                    'is_primary' => $isPrimary,
                    'sort_order' => count($this->existingImages) + $i,
                ]);

                if ($isPrimary) $hasAnyPrimary = true;
            }

            DB::commit();

            $this->saving    = false;
            $this->newImages = [];

            // Reload existing images after save
            $this->existingImages = $this->product->fresh()->images()
                ->orderBy('sort_order')
                ->get()
                ->map(fn($img) => [
                    'id'                  => $img->id,
                    'image_url'           => $img->image_url,
                    'is_primary'          => $img->is_primary,
                    'sort_order'          => $img->sort_order,
                    'marked_for_deletion' => false,
                ])->toArray();

            $this->flashMessage = "'{$this->name_en}' updated successfully.";
            $this->flashType    = 'success';

        } catch (\Exception $e) {
            DB::rollBack();
            $this->saving       = false;
            $this->flashMessage = 'Failed to update product: ' . $e->getMessage();
            $this->flashType    = 'error';
        }
    }

    protected function redirectToFirstErrorTab(): void
    {
        $tabOrder = ['details', 'translations', 'variants', 'seo'];
        foreach ($tabOrder as $tab) {
            foreach ($this->fieldTabMap as $field => $fieldTab) {
                if ($fieldTab === $tab && $this->getErrorBag()->has($field)) {
                    $this->activeTab = $tab;
                    return;
                }
            }
        }
    }

    public function render()
    {
        return view('livewire.admin.products.edit')->layout('layouts.admin');
    }
}