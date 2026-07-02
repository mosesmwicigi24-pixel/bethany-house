<?php

namespace App\Http\Livewire\Admin\Products;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class VariantIndex extends Component
{
    use WithPagination;

    // ── Filters ───────────────────────────────────────────────────────────────
    #[Url(as: 'q')]
    public string $search       = '';

    #[Url]
    public string $product_id   = '';

    public bool   $lowStock          = false;
    public int    $lowStockThreshold = 5;

    // ── Selection ─────────────────────────────────────────────────────────────
    public array  $selected     = [];
    public bool   $selectAll    = false;

    // ── Bulk price adjust modal ───────────────────────────────────────────────
    public bool   $showBulkModal    = false;
    public string $bulkCurrency     = 'KES';
    public string $bulkAdjustType   = 'percentage';
    public string $bulkValue        = '';

    // ── Edit modal ────────────────────────────────────────────────────────────
    public bool   $showEditModal    = false;
    public ?int   $editId           = null;
    public string $edit_sku         = '';
    public string $edit_name        = '';   // → variant_name column
    public string $edit_price_kes   = '';   // → product_prices KES regular_price
    public string $edit_price_usd   = '';   // → product_prices USD regular_price
    public bool   $edit_is_active   = true;

    // ── Flash ─────────────────────────────────────────────────────────────────
    public string $flashMessage = '';
    public string $flashType    = 'success';

    // ── Filter update hooks ───────────────────────────────────────────────────
    public function updatingSearch(): void    { $this->resetPage(); }
    public function updatingProductId(): void { $this->resetPage(); $this->clearSelected(); }
    public function updatingLowStock(): void  { $this->resetPage(); }

    // ── Computed: product list for filter dropdown ────────────────────────────
    #[Computed]
    public function products()
    {
        return Product::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->orderBy('sku')
            ->get();
    }

    // ── Computed: variants query ──────────────────────────────────────────────
    #[Computed]
    public function variants()
    {
        $query = ProductVariant::with([
                'product',
                'prices', // Eloquent constrains by product_variant_id automatically
            ])
            ->addSelect([
                // Correct column: quantity_on_hand; correct FK: product_variant_id
                'stock_on_hand' => Inventory::selectRaw('COALESCE(SUM(quantity_on_hand), 0)')
                    ->whereColumn('product_variant_id', 'product_variants.id')
                    ->where('inventory_type', 'product'),
            ]);

        if ($this->search) {
            $query->where(function ($q) {
                // Correct columns: sku, variant_name (not 'name')
                $q->where('product_variants.sku', 'ilike', "%{$this->search}%")
                  ->orWhere('variant_name', 'ilike', "%{$this->search}%");
            });
        }

        if ($this->product_id) {
            $query->where('product_id', $this->product_id);
        }

        if ($this->lowStock) {
            // Correct FK: product_variant_id; correct column: quantity_on_hand
            $query->whereRaw(
                '(SELECT COALESCE(SUM(quantity_on_hand), 0) FROM inventories WHERE product_variant_id = product_variants.id AND inventory_type = \'product\') <= ?',
                [$this->lowStockThreshold]
            );
        }

        return $query->paginate(50);
    }

    // ── Selection ─────────────────────────────────────────────────────────────
    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? $this->variants->pluck('id')->map(fn($id) => (string)$id)->toArray()
            : [];
    }

    public function clearSelected(): void
    {
        $this->selected  = [];
        $this->selectAll = false;
    }

    // ── Edit modal ────────────────────────────────────────────────────────────
    public function openEdit(int $id): void
    {
        $v = ProductVariant::with('prices')->findOrFail($id);

        $this->editId       = $id;
        $this->edit_sku     = $v->sku;
        $this->edit_name    = $v->variant_name ?? '';   // correct column
        $this->edit_is_active = $v->is_active !== false;

        // Prices live in product_prices, not on the variant row
        $this->edit_price_kes = (string) ($v->prices->firstWhere('currency_code', 'KES')?->regular_price ?? '');
        $this->edit_price_usd = (string) ($v->prices->firstWhere('currency_code', 'USD')?->regular_price ?? '');

        $this->showEditModal = true;
    }

    public function saveVariant(): void
    {
        $this->validate([
            'edit_sku'       => ['required', 'string', Rule::unique('product_variants', 'sku')->ignore($this->editId)],
            'edit_name'      => 'nullable|string|max:255',
            'edit_price_kes' => 'required|numeric|min:0',
            'edit_price_usd' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () {
            $variant = ProductVariant::findOrFail($this->editId);

            // Update the variant row - only columns that actually exist
            $variant->update([
                'sku'          => $this->edit_sku,
                'variant_name' => $this->edit_name ?: null,  // correct column
                'is_active'    => $this->edit_is_active,
            ]);

            // Update prices in product_prices table via updateOrCreate
            ProductPrice::updateOrCreate(
                [
                    'product_id'         => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'currency_code'      => 'KES',
                ],
                ['regular_price' => $this->edit_price_kes]
            );
            ProductPrice::updateOrCreate(
                [
                    'product_id'         => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'currency_code'      => 'USD',
                ],
                ['regular_price' => $this->edit_price_usd]
            );
        });

        $this->showEditModal = false;
        $this->flash('Variant updated.');
        unset($this->variants);
    }

    // ── Bulk actions ──────────────────────────────────────────────────────────
    public function bulkActivate(): void   { $this->doBulk('activate'); }
    public function bulkDeactivate(): void { $this->doBulk('deactivate'); }

    public function applyBulkPrice(): void
    {
        $this->validate([
            'bulkValue'      => 'required|numeric',
            'bulkCurrency'   => 'required|in:KES,USD',
            'bulkAdjustType' => 'required|in:fixed,percentage',
        ]);
        $this->doBulk('adjust_price', $this->bulkValue, $this->bulkAdjustType);
        $this->showBulkModal = false;
        $this->bulkValue     = '';
    }

    private function doBulk(string $action, ?string $value = null, ?string $adjustType = null): void
    {
        $ids = array_map('intval', $this->selected);
        if (empty($ids)) return;

        DB::beginTransaction();
        try {
            $variants = ProductVariant::whereIn('id', $ids)->get();
            $count    = $variants->count();

            foreach ($variants as $v) {
                match ($action) {
                    'activate'   => $v->update(['is_active' => true]),
                    'deactivate' => $v->update(['is_active' => false]),

                    // Prices live in product_prices - update via updateOrCreate
                    'adjust_price' => (function () use ($v, $value, $adjustType) {
                        $currency = $this->bulkCurrency; // 'KES' or 'USD'

                        $existing = ProductPrice::where('product_variant_id', $v->id)
                            ->where('currency_code', $currency)
                            ->first();

                        $currentPrice = (float) ($existing?->regular_price ?? 0);
                        $newPrice     = $this->adjust($currentPrice, (float) $value, $adjustType);

                        ProductPrice::updateOrCreate(
                            ['product_id' => $v->product_id, 'product_variant_id' => $v->id, 'currency_code' => $currency],
                            ['regular_price' => $newPrice]
                        );
                    })(),

                    default => null,
                };
            }

            DB::commit();
            $this->flash("{$count} variant(s) updated.");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->flash('Bulk action failed: ' . $e->getMessage(), 'error');
        }

        $this->clearSelected();
        unset($this->variants);
    }

    private function adjust(float $price, float $val, string $type): float
    {
        $new = $type === 'percentage' ? $price * (1 + $val / 100) : $price + $val;
        return max(0, round($new, 2));
    }

    private function flash(string $msg, string $type = 'success'): void
    {
        $this->flashMessage = $msg;
        $this->flashType    = $type;
    }

    public function render()
    {
        return view('livewire.admin.products.variants', [])->layout('layouts.admin');
    }
}