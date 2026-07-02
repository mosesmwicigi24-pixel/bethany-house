<?php

namespace App\Http\Livewire\Admin\Products;

use App\Models\Product;
use App\Models\ProductSeo;
use App\Models\ProductTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SeoIndex extends Component
{
    use WithPagination;

    // ── Filters ───────────────────────────────────────────────────────────────
    #[Url(as: 'q')]
    public string $search      = '';

    #[Url]
    public string $missingMeta = ''; // '' | 'title' | 'description'

    public int    $perPage     = 50;

    // ── Inline edits: [productId => [meta_title, meta_description, meta_keywords, slug]] ──
    public array $pendingEdits = [];

    // ── Auto-generate modal ───────────────────────────────────────────────────
    public bool   $showGenModal = false;
    public string $genScope     = 'all'; // all | missing_title | missing_description
    public bool   $genOverwrite = false;

    // ── Flash ─────────────────────────────────────────────────────────────────
    public string $flashMessage = '';
    public string $flashType    = 'success';

    // ── Hooks ─────────────────────────────────────────────────────────────────
    public function updatingSearch(): void     { $this->resetPage(); }
    public function updatingMissingMeta(): void { $this->resetPage(); }

    // ── Stats ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function stats(): array
    {
        $total = Product::count();

        // SEO lives in product_seo - count via that table
        $hasTitle = ProductSeo::where('language_code', 'en')
            ->whereNotNull('meta_title')
            ->where('meta_title', '!=', '')
            ->count();

        $hasDesc = ProductSeo::where('language_code', 'en')
            ->whereNotNull('meta_description')
            ->where('meta_description', '!=', '')
            ->count();

        return [
            'total'           => $total,
            'has_title'       => $hasTitle,
            'missing_title'   => $total - $hasTitle,
            'has_description' => $hasDesc,
            'missing_desc'    => $total - $hasDesc,
            'completion_pct'  => $total
                ? round((($hasTitle + $hasDesc) / ($total * 2)) * 100)
                : 0,
        ];
    }

    // ── Product query ─────────────────────────────────────────────────────────
    #[Computed]
    public function products()
    {
        $query = Product::with([
            // EN translation for name + description
            'translations' => fn($q) => $q->where('language_code', 'en'),
            // EN SEO record
            'seo'          => fn($q) => $q->where('language_code', 'en'),
        ]);

        // Search: name lives in product_translations, sku on products
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('sku', 'ilike', "%{$this->search}%")
                  ->orWhereHas('translations', fn($tq) =>
                      $tq->where('language_code', 'en')
                         ->where('name', 'ilike', "%{$this->search}%")
                  );
            });
        }

        // Missing-meta filters: check product_seo table via whereHas / whereDoesntHave
        if ($this->missingMeta === 'title') {
            $query->where(function ($q) {
                // Either no SEO record at all, or SEO record with empty meta_title
                $q->whereDoesntHave('seo', fn($sq) => $sq->where('language_code', 'en'))
                  ->orWhereHas('seo', fn($sq) =>
                      $sq->where('language_code', 'en')
                         ->where(fn($inner) =>
                             $inner->whereNull('meta_title')
                                   ->orWhere('meta_title', '')
                         )
                  );
            });
        } elseif ($this->missingMeta === 'description') {
            $query->where(function ($q) {
                $q->whereDoesntHave('seo', fn($sq) => $sq->where('language_code', 'en'))
                  ->orWhereHas('seo', fn($sq) =>
                      $sq->where('language_code', 'en')
                         ->where(fn($inner) =>
                             $inner->whereNull('meta_description')
                                   ->orWhere('meta_description', '')
                         )
                  );
            });
        }

        // Order by EN translation name; fall back to sku when no translation exists
        return $query
            ->orderByRaw("(
                SELECT name FROM product_translations
                WHERE product_id = products.id AND language_code = 'en'
                LIMIT 1
            ) ASC NULLS LAST")
            ->paginate($this->perPage);
    }

    // ── Inline edit tracking ──────────────────────────────────────────────────
    public function updateField(int $productId, string $field, string $value): void
    {
        $allowed = ['meta_title', 'meta_description', 'meta_keywords', 'slug'];
        if (!in_array($field, $allowed)) return;

        $this->pendingEdits[$productId][$field] = $value;
    }

    public function unsavedCount(): int
    {
        return count($this->pendingEdits);
    }

    // ── Save all pending ──────────────────────────────────────────────────────
    public function saveAll(): void
    {
        if (empty($this->pendingEdits)) return;

        DB::beginTransaction();
        try {
            foreach ($this->pendingEdits as $id => $fields) {
                $product = Product::find($id);
                if (!$product) continue;

                // slug lives on the products table
                if (isset($fields['slug'])) {
                    $product->update(['slug' => Str::slug($fields['slug'])]);
                }

                // SEO fields live in product_seo table
                $seoFields = array_intersect_key(
                    $fields,
                    array_flip(['meta_title', 'meta_description', 'meta_keywords'])
                );

                if (!empty($seoFields)) {
                    ProductSeo::updateOrCreate(
                        ['product_id' => $id, 'language_code' => 'en'],
                        $seoFields
                    );
                }
            }

            DB::commit();
            $count = count($this->pendingEdits);
            $this->pendingEdits = [];
            $this->flash("SEO data saved for {$count} product" . ($count !== 1 ? 's' : '') . '.');
            unset($this->products, $this->stats);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->flash('Save failed: ' . $e->getMessage(), 'error');
        }
    }

    public function discardEdits(): void
    {
        $this->pendingEdits = [];
        unset($this->products);
    }

    // ── Auto-generate ─────────────────────────────────────────────────────────
    public function autoGenerate(): void
    {
        // Load products with EN translation + SEO
        $query = Product::with([
            'translations' => fn($q) => $q->where('language_code', 'en'),
            'seo'          => fn($q) => $q->where('language_code', 'en'),
        ]);

        // Scope: only products missing the relevant field (unless overwrite)
        if (!$this->genOverwrite) {
            if ($this->genScope === 'missing_title') {
                $query->where(function ($q) {
                    $q->whereDoesntHave('seo', fn($sq) => $sq->where('language_code', 'en'))
                      ->orWhereHas('seo', fn($sq) =>
                          $sq->where('language_code', 'en')
                             ->where(fn($i) => $i->whereNull('meta_title')->orWhere('meta_title', ''))
                      );
                });
            } elseif ($this->genScope === 'missing_description') {
                $query->where(function ($q) {
                    $q->whereDoesntHave('seo', fn($sq) => $sq->where('language_code', 'en'))
                      ->orWhereHas('seo', fn($sq) =>
                          $sq->where('language_code', 'en')
                             ->where(fn($i) => $i->whereNull('meta_description')->orWhere('meta_description', ''))
                      );
                });
            } else {
                // 'all' scope - only products missing title OR description
                $query->where(function ($q) {
                    $q->whereDoesntHave('seo', fn($sq) => $sq->where('language_code', 'en'))
                      ->orWhereHas('seo', fn($sq) =>
                          $sq->where('language_code', 'en')
                             ->where(fn($i) =>
                                 $i->whereNull('meta_title')->orWhere('meta_title', '')
                                   ->orWhereNull('meta_description')->orWhere('meta_description', '')
                             )
                      );
                });
            }
        }

        $products = $query->get();
        $updated  = 0;

        foreach ($products as $product) {
            $translation = $product->translations->first();
            $seo         = $product->seo->first();

            $productName = $translation?->name ?? $product->sku;
            $description = $translation?->description ?? '';

            $seoData = [];

            if ($this->genOverwrite || empty($seo?->meta_title)) {
                $seoData['meta_title'] = Str::limit($productName, 60);
            }

            if ($this->genOverwrite || empty($seo?->meta_description)) {
                $seoData['meta_description'] = Str::limit(strip_tags($description), 155);
            }

            if (!empty($seoData)) {
                ProductSeo::updateOrCreate(
                    ['product_id' => $product->id, 'language_code' => 'en'],
                    $seoData
                );
                $updated++;
            }
        }

        $this->showGenModal = false;
        $this->pendingEdits = [];
        $this->flash("Auto-generated SEO for {$updated} product" . ($updated !== 1 ? 's' : '') . '.');
        unset($this->products, $this->stats);
    }

    private function flash(string $msg, string $type = 'success'): void
    {
        $this->flashMessage = $msg;
        $this->flashType    = $type;
    }

    public function render()
    {
        return view('livewire.admin.products.seo', [])->layout('layouts.admin');
    }
}