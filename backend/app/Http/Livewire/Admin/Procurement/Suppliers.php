<?php

namespace App\Http\Livewire\Admin\Procurement;

use App\Models\Supplier;
use Livewire\Component;
use Livewire\WithPagination;

class Suppliers extends Component
{
    use WithPagination;

    public string $search        = '';
    public string $statusFilter  = 'active';
    public string $ratingFilter  = '';
    public string $sortBy        = 'name';
    public string $sortDir       = 'asc';

    // Detail slide-over
    public bool      $showDetail = false;
    public ?Supplier $viewing    = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'statusFilter' => ['except' => 'active'],
        'ratingFilter' => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function viewSupplier(int $id): void
    {
        $this->viewing = Supplier::withCount('purchaseOrders')
            ->with(['materials', 'purchaseOrders' => fn($q) => $q->latest()->limit(5)])
            ->find($id);
        $this->showDetail = true;
    }

    public function toggleActive(int $id): void
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update(['is_active' => !$supplier->is_active]);
        session()->flash('success', 'Supplier status updated.');
    }

    public function getSummaryProperty(): array
    {
        return [
            'total'      => Supplier::count(),
            'active'     => Supplier::active()->count(),
            'top_rated'  => Supplier::topRated()->count(),
            'total_spend'=> \App\Models\PurchaseOrder::where('status', 'completed')->sum('total_amount'),
        ];
    }

    public function render()
    {
        $suppliers = Supplier::withCount(['purchaseOrders', 'materials'])
            ->when($this->search, fn($q) =>
                $q->where('name', 'ilike', "%{$this->search}%")
                  ->orWhere('code', 'ilike', "%{$this->search}%")
                  ->orWhere('email', 'ilike', "%{$this->search}%")
                  ->orWhere('contact_person', 'ilike', "%{$this->search}%")
            )
            ->when($this->statusFilter === 'active',   fn($q) => $q->active())
            ->when($this->statusFilter === 'inactive', fn($q) => $q->where('is_active', false))
            ->when($this->ratingFilter, fn($q) => $q->topRated((float) $this->ratingFilter))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.procurement.suppliers', [
            'suppliers' => $suppliers,
            'summary'   => $this->summary,
        ])->layout('layouts.admin');
    }
}