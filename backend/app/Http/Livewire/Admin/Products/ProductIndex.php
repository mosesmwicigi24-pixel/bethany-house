<?php

namespace App\Http\Livewire\Admin\Products;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ProductIndex extends Component
{
    use WithPagination;

    // ── Filters (synced to URL) ───────────────────────────────────────────────
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $category_id = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $sort_by = 'created_at';

    #[Url]
    public string $sort_order = 'desc';

    public int $perPage = 25;

    // ── Bulk selection ────────────────────────────────────────────────────────
    public array $selected     = [];
    public bool  $selectAll    = false;
    public string $bulkAction  = '';

    // ── Delete modal ──────────────────────────────────────────────────────────
    public bool   $showDeleteModal   = false;
    public ?int   $deleteProductId   = null;
    public string $deleteProductName = '';

    // ── Flash message ─────────────────────────────────────────────────────────
    public string $flashMessage = '';
    public string $flashType    = 'success'; // success | error

    protected $queryString = [
        'search'      => ['except' => ''],
        'status'      => ['except' => ''],
        'category_id' => ['except' => ''],
        'type'        => ['except' => ''],
        'sort_by'     => ['except' => 'created_at'],
        'sort_order'  => ['except' => 'desc'],
    ];

    // ── Lifecycle ─────────────────────────────────────────────────────────────
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
        $this->selected  = [];
        $this->selectAll = false;
    }

    public function updatingCategoryId(): void { $this->resetPage(); }
    public function updatingType(): void        { $this->resetPage(); }

    // ── Computed: summary counts ──────────────────────────────────────────────
    #[Computed]
    public function summary(): array
    {
        return [
            'total'    => Product::count(),
            'active'   => Product::where('status', 'active')->count(),
            'draft'    => Product::where('status', 'draft')->count(),
            'archived' => Product::where('status', 'archived')->count(),
            'featured' => Product::where('is_featured', true)->count(),
        ];
    }

    // ── Computed: categories for the filter dropdown ──────────────────────────
    #[Computed]
    public function categories()
    {
        return Category::withoutGlobalScopes()->withCount('products')->with('translations')->whereNull('deleted_at')->orderBy('sort_order')->get();
    }

    // ── Main query ────────────────────────────────────────────────────────────
    #[Computed]
    public function products()
    {
        $query = Product::with(['category', 'images'])
            ->withCount('variants');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name_en', 'like', "%{$this->search}%")
                  ->orWhere('sku', 'like', "%{$this->search}%")
                  ->orWhere('description_en', 'like', "%{$this->search}%");
            });
        }

        if ($this->status === 'featured') {
            $query->where('is_featured', true);
        } elseif ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->category_id) {
            $query->where('category_id', $this->category_id);
        }

        if ($this->type) {
            $query->where('type', $this->type);
        }

        $allowed = ['name_en', 'sku', 'price_kes', 'price_usd', 'created_at', 'status'];
        if (in_array($this->sort_by, $allowed)) {
            $query->orderBy($this->sort_by, $this->sort_order);
        }

        return $query->paginate($this->perPage);
    }

    // ── Sorting ───────────────────────────────────────────────────────────────
    public function sortBy(string $column): void
    {
        if ($this->sort_by === $column) {
            $this->sort_order = $this->sort_order === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort_by    = $column;
            $this->sort_order = 'asc';
        }
        $this->resetPage();
    }

    // ── Filter helpers ────────────────────────────────────────────────────────
    public function setStatus(string $status): void
    {
        $this->status   = $status;
        $this->selected = [];
        $this->selectAll = false;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search      = '';
        $this->status      = '';
        $this->category_id = '';
        $this->type        = '';
        $this->sort_by     = 'created_at';
        $this->sort_order  = 'desc';
        $this->selected    = [];
        $this->selectAll   = false;
        $this->resetPage();
    }

    // ── Row selection ─────────────────────────────────────────────────────────
    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? $this->products->pluck('id')->map(fn ($id) => (string) $id)->toArray()
            : [];
    }

    public function updatedSelected(): void
    {
        $pageIds         = $this->products->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        $this->selectAll = !array_diff($pageIds, $this->selected);
    }

    // ── Bulk actions ──────────────────────────────────────────────────────────
    public function applyBulkAction(): void
    {
        if (!$this->bulkAction || empty($this->selected)) {
            return;
        }

        $ids = array_map('intval', $this->selected);

        DB::beginTransaction();
        try {
            match ($this->bulkAction) {
                'active'   => Product::whereIn('id', $ids)->update(['status' => 'active']),
                'draft'    => Product::whereIn('id', $ids)->update(['status' => 'draft']),
                'archived' => Product::whereIn('id', $ids)->update(['status' => 'archived']),
                'delete'   => $this->bulkDelete($ids),
                default    => null,
            };
            DB::commit();
            $this->flash(count($ids) . ' products updated.');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->flash('Action failed: ' . $e->getMessage(), 'error');
        }

        $this->selected    = [];
        $this->selectAll   = false;
        $this->bulkAction  = '';
        unset($this->products); // bust computed cache
    }

    private function bulkDelete(array $ids): void
    {
        foreach ($ids as $id) {
            $product = Product::find($id);
            if ($product && !$product->orderItems()->exists()) {
                $product->delete();
            }
        }
    }

    // ── Single delete ─────────────────────────────────────────────────────────
    public function confirmDelete(int $id, string $name): void
    {
        $this->deleteProductId   = $id;
        $this->deleteProductName = $name;
        $this->showDeleteModal   = true;
    }

    public function deleteProduct(): void
    {
        $product = Product::find($this->deleteProductId);

        if (!$product) {
            $this->flash('Product not found.', 'error');
            $this->showDeleteModal = false;
            return;
        }

        if ($product->orderItems()->exists()) {
            $this->flash('Cannot delete: product has existing orders.', 'error');
            $this->showDeleteModal = false;
            return;
        }

        $product->delete();
        $this->flash("'{$product->name_en}' deleted.");
        $this->showDeleteModal = false;
        unset($this->products);
    }

    // ── Flash helper ─────────────────────────────────────────────────────────
    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType    = $type;
        $this->dispatch('flash-shown');
    }

    public function render()
    {
        return view('livewire.admin.products.index', [])->layout('layouts.admin');
    }
}