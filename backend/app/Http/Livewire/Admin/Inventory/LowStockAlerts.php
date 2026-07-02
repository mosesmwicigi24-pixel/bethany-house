<?php

namespace App\Http\Livewire\Admin\Inventory;

use App\Models\Inventory;
use App\Models\Outlet;
use Livewire\Component;
use Livewire\WithPagination;

class LowStockAlerts extends Component
{
    use WithPagination;

    public string $outletFilter = '';
    public string $severityFilter = ''; // low_stock | out_of_stock
    public string $search = '';
    public string $sortBy = 'quantity_available';
    public string $sortDir = 'asc';

    protected $queryString = [
        'outletFilter'   => ['except' => ''],
        'severityFilter' => ['except' => ''],
        'search'         => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingOutletFilter(): void { $this->resetPage(); }
    public function updatingSeverityFilter(): void { $this->resetPage(); }

    public function sort(string $column): void
    {
        $this->sortBy  = $column;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function render()
    {
        $query = Inventory::with(['product.translations', 'variant', 'outlet'])
            ->products()
            ->where(function ($q) {
                $q->lowStock()->orWhere->outOfStock();
            })
            ->when($this->outletFilter, fn($q) => $q->where('outlet_id', $this->outletFilter))
            ->when($this->severityFilter === 'out_of_stock', fn($q) => $q->outOfStock())
            ->when($this->severityFilter === 'low_stock', fn($q) => $q->lowStock())
            ->when($this->search, fn($q) => $q->whereHas('product', fn($pq) =>
                $pq->where('sku', 'ilike', "%{$this->search}%")
                   ->orWhereHas('translations', fn($tq) =>
                       $tq->where('name', 'ilike', "%{$this->search}%")
                   )
            ))
            ->orderByRaw('(quantity_on_hand - quantity_reserved) ' . $this->sortDir);

        $stocks = $query->paginate(20);

        $summary = [
            'out_of_stock' => Inventory::products()->outOfStock()->count(),
            'low_stock'    => Inventory::products()->lowStock()->count(),
        ];

        return view('livewire.admin.inventory.low-stock-alerts', [
            'stocks'  => $stocks,
            'outlets' => Outlet::active()->orderBy('name')->get(),
            'summary' => $summary,
        ])->layout('layouts.admin');
    }
}