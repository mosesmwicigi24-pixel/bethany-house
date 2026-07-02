<?php

namespace App\Http\Livewire\Admin\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductSeo;
use App\Models\ProductTranslation;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProductCreate extends Component
{
    use WithFileUploads;

    // ── Active tab ─────────────────────────────────────────────────────────────
    public string $activeTab = 'details';

    // ── Translations ───────────────────────────────────────────────────────────
    #[Validate('required|string|max:255')]
    public string $name_en = '';

    #[Validate('nullable|string|max:255')]
    public string $name_fr = '';

    #[Validate('nullable|string|max:255')]
    public string $name_pt = '';

    // ── Product table fields ───────────────────────────────────────────────────
    #[Validate('required|string|max:100|unique:products,sku')]
    public string $sku = '';

    #[Validate('required|exists:categories,id')]
    public string $category_id = '';

    // ── Prices ────────────────────────────────────────────────────────────────
    #[Validate('required|numeric|min:0')]
    public string $price_kes = '';

    #[Validate('required|numeric|min:0')]
    public string $price_usd = '';

    // ── Descriptions ──────────────────────────────────────────────────────────
    #[Validate('required|string')]
    public string $description_en = '';

    #[Validate('nullable|string')]
    public string $description_fr = '';

    #[Validate('nullable|string')]
    public string $description_pt = '';

    // ── Physical ──────────────────────────────────────────────────────────────
    #[Validate('nullable|numeric|min:0')]
    public string $weight = '';

    // ── Type, status, flags ────────────────────────────────────────────────────
    #[Validate('required|in:simple,variant,made_to_order')]
    public string $type = 'simple';

    #[Validate('in:draft,active,archived')]
    public string $status = 'draft';

    public bool $is_featured   = false;
    public bool $is_producible = false;

    // ── SEO ───────────────────────────────────────────────────────────────────
    #[Validate('nullable|string|max:255')]
    public string $meta_title = '';

    #[Validate('nullable|string')]
    public string $meta_description = '';

    #[Validate('nullable|string')]
    public string $meta_keywords = '';

    // ── Images ────────────────────────────────────────────────────────────────
    #[Validate(['images.*' => 'image|max:5120'])]
    public array $images = [];

    // ── Variant builder ───────────────────────────────────────────────────────
    public array $variantAttributes = [];
    public array $generatedVariants = [];

    // ── State ─────────────────────────────────────────────────────────────────
    public bool   $saving       = false;
    public string $flashMessage = '';
    public string $flashType    = 'success';

    /**
     * Maps each field name to the tab it lives on.
     * Used to auto-switch to the first tab that has errors after a failed save.
     */
    private array $fieldTabMap = [
        'name_en'        => 'details',
        'sku'            => 'details',
        'category_id'    => 'details',
        'price_kes'      => 'details',
        'price_usd'      => 'details',
        'description_en' => 'details',
        'weight'         => 'details',
        'name_fr'        => 'translations',
        'name_pt'        => 'translations',
        'description_fr' => 'translations',
        'description_pt' => 'translations',
        'meta_title'     => 'seo',
        'meta_description'=> 'seo',
        'meta_keywords'  => 'seo',
    ];

    // ── Computed ──────────────────────────────────────────────────────────────
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

    /**
     * Returns a map of tab => bool indicating whether that tab has any errors.
     * Used by the blade to show error badges on tab buttons.
     */
    #[Computed]
    public function tabErrors(): array
    {
        $errors     = $this->getErrorBag()->toArray();
        $tabErrors  = ['details' => false, 'translations' => false, 'variants' => false, 'seo' => false];

        foreach (array_keys($errors) as $field) {
            $tab = $this->fieldTabMap[$field] ?? null;
            if ($tab) {
                $tabErrors[$tab] = true;
            }
        }

        return $tabErrors;
    }

    // ── Variant builder ───────────────────────────────────────────────────────
    public function addAttributeRow(): void
    {
        $this->variantAttributes[] = ['key' => '', 'values' => ''];
    }

    public function removeAttributeRow(int $index): void
    {
        array_splice($this->variantAttributes, $index, 1);
        $this->generatedVariants = [];
    }

    public function generateVariants(): void
    {
        $combos = [[]];

        foreach ($this->variantAttributes as $attr) {
            $key  = trim($attr['key']);
            $vals = array_filter(array_map('trim', explode(',', $attr['values'])));

            if (!$key || !$vals) {
                continue;
            }

            $newCombos = [];
            foreach ($combos as $combo) {
                foreach ($vals as $val) {
                    $newCombos[] = array_merge($combo, [$key => $val]);
                }
            }
            $combos = $newCombos;
        }

        if (count($combos) === 1 && empty($combos[0])) {
            $this->generatedVariants = [];
            return;
        }

        $base_kes = (float) ($this->price_kes ?: 0);
        $base_usd = (float) ($this->price_usd ?: 0);

        $this->generatedVariants = array_map(fn($attrs) => [
            'attrs'      => $attrs,
            'price_kes'  => $base_kes,
            'price_usd'  => $base_usd,
            'sku_suffix' => strtolower(implode('-', array_values($attrs))),
        ], $combos);
    }

    public function updateVariantPrice(int $index, string $field, string $value): void
    {
        if (isset($this->generatedVariants[$index])) {
            $this->generatedVariants[$index][$field] = $value;
        }
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    public function save(string $status = 'draft'): void
    {
        $this->status       = $status;
        $this->flashMessage = '';

        // Run validation - on failure Livewire throws ValidationException,
        // which populates the error bag. We then switch to the first tab
        // that has an error so the user can see it immediately.
        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->redirectToFirstErrorTab();
            return; // Livewire will re-render with errors visible
        }

        $this->saving = true;

        DB::beginTransaction();
        try {
            // ── 1. Slug ────────────────────────────────────────────────────
            $slug = Str::slug($this->name_en);
            $base = $slug;
            $i    = 1;
            while (Product::where('slug', $slug)->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }

            // ── 2. Product row ────────────────────────────────────────────
            $product = Product::create([
                'sku'           => $this->sku,
                'slug'          => $slug,
                'category_id'   => $this->category_id,
                'product_type'  => $this->type,
                'status'        => $this->status,
                'is_featured'   => $this->is_featured,
                'is_producible' => $this->type === 'made_to_order' ? true : $this->is_producible,
                'weight'        => $this->weight ?: null,
                'published_at'  => $this->status === 'active' ? now() : null,
            ]);

            // ── 3. Translations ───────────────────────────────────────────
            $translations = [
                ['language_code' => 'en', 'name' => $this->name_en, 'description' => $this->description_en],
            ];
            if ($this->name_fr) {
                $translations[] = ['language_code' => 'fr', 'name' => $this->name_fr, 'description' => $this->description_fr ?: null];
            }
            if ($this->name_pt) {
                $translations[] = ['language_code' => 'pt', 'name' => $this->name_pt, 'description' => $this->description_pt ?: null];
            }
            foreach ($translations as $t) {
                ProductTranslation::create(array_merge(['product_id' => $product->id], $t));
            }

            // ── 4. Base product-level prices (variant_id = null) ──────────
            ProductPrice::create([
                'product_id'         => $product->id,
                'product_variant_id' => null,
                'currency_code'      => 'KES',
                'regular_price'      => $this->price_kes,
            ]);
            ProductPrice::create([
                'product_id'         => $product->id,
                'product_variant_id' => null,
                'currency_code'      => 'USD',
                'regular_price'      => $this->price_usd,
            ]);

            // ── 5. Variants + variant prices ──────────────────────────────
            if ($this->type === 'simple' || $this->type === 'made_to_order') {
                $variant = ProductVariant::create([
                    'product_id'   => $product->id,
                    'sku'          => $this->sku,
                    'variant_name' => 'Default',
                    'attributes'   => [],   // JSONB NOT NULL - empty array is valid
                    'is_default'   => true,
                    'is_active'    => true,
                ]);

                ProductPrice::create([
                    'product_id'         => $product->id,
                    'product_variant_id' => $variant->id,
                    'currency_code'      => 'KES',
                    'regular_price'      => $this->price_kes,
                ]);
                ProductPrice::create([
                    'product_id'         => $product->id,
                    'product_variant_id' => $variant->id,
                    'currency_code'      => 'USD',
                    'regular_price'      => $this->price_usd,
                ]);

            } elseif ($this->type === 'variant' && !empty($this->generatedVariants)) {
                foreach ($this->generatedVariants as $idx => $v) {
                    $variant = ProductVariant::create([
                        'product_id'   => $product->id,
                        'sku'          => $this->sku . '-' . $v['sku_suffix'],
                        'variant_name' => implode(' / ', array_values($v['attrs'])),
                        'attributes'   => $v['attrs'],
                        'is_default'   => $idx === 0,
                        'is_active'    => true,
                    ]);

                    ProductPrice::create([
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant->id,
                        'currency_code'      => 'KES',
                        'regular_price'      => $v['price_kes'] ?: $this->price_kes,
                    ]);
                    ProductPrice::create([
                        'product_id'         => $product->id,
                        'product_variant_id' => $variant->id,
                        'currency_code'      => 'USD',
                        'regular_price'      => $v['price_usd'] ?: $this->price_usd,
                    ]);
                }
            }

            // ── 6. SEO ────────────────────────────────────────────────────
            if ($this->meta_title || $this->meta_description || $this->meta_keywords) {
                ProductSeo::create([
                    'product_id'       => $product->id,
                    'language_code'    => 'en',
                    'meta_title'       => $this->meta_title ?: null,
                    'meta_description' => $this->meta_description ?: null,
                    'meta_keywords'    => $this->meta_keywords ?: null,
                ]);
            }

            // ── 7. Images ─────────────────────────────────────────────────
            // Column is image_url (not image_path). Store file → get public URL.
            foreach ($this->images as $sortOrder => $image) {
                $path = $image->store('products', 'public');
                $url  = Storage::disk('public')->url($path);

                $product->images()->create([
                    'image_url'  => $url,
                    'is_primary' => $sortOrder === 0,
                    'sort_order' => $sortOrder,
                ]);
            }

            DB::commit();
            $this->saving = false;

            session()->flash('flash_success', "'{$this->name_en}' created successfully.");
            $this->redirect(route('products.edit', $product->id), navigate: true);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->saving       = false;
            $this->flashMessage = 'Failed to save product: ' . $e->getMessage();
            $this->flashType    = 'error';
        }
    }

    /**
     * After a validation failure, switch to the first tab that contains an error
     * so the user can see what needs fixing rather than staring at a blank response.
     */
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
        return view('livewire.admin.products.create', [])->layout('layouts.admin');
    }
}