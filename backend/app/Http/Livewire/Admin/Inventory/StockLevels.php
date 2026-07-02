<?php

namespace App\Http\Livewire\Admin\Inventory;

use App\Models\Inventory;
use App\Models\Outlet;
use App\Models\Product;
use Livewire\Component;
use Livewire\WithPagination;

class StockLevels extends Component
{
    use WithPagination;

    public string $search = '';
    public string $outletFilter = '';
    public string $statusFilter = '';
    public string $sortBy = 'product_id';
    public string $sortDir = 'asc';
    public int $perPage = 20;

    protected $queryString = [
        'search' => ['except' => ''],
        'outletFilter' => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'sortBy',
        'sortDir',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingOutletFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    public function render()
    {
        $query = Inventory::with(['product', 'variant', 'outlet'])
            ->products()
            ->when($this->search, fn($q) => $q->whereHas('product', fn($pq) =>
                $pq->where('sku', 'ilike', "%{$this->search}%")
                   ->orWhereHas('translations', fn($tq) =>
                       $tq->where('name', 'ilike', "%{$this->search}%")
                   )
            ))
            ->when($this->outletFilter, fn($q) => $q->where('outlet_id', $this->outletFilter))
            ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
            ->orderBy($this->sortBy, $this->sortDir);

        return view('livewire.admin.inventory.stock-levels', [
            'stocks'  => $query->paginate($this->perPage),
            'outlets' => Outlet::active()->orderBy('name')->get(),
            'statuses' => ['available', 'low_stock', 'out_of_stock', 'expired'],
        ])->layout('layouts.admin');
    }
}