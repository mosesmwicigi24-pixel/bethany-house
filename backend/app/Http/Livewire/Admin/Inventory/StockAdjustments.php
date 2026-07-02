<?php

namespace App\Http\Livewire\Admin\Inventory;

use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Outlet;
use App\Models\Product;
use Livewire\Component;
use Livewire\WithPagination;

class StockAdjustments extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';
    public string $outletFilter = '';
    public string $typeFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    // Adjust modal state
    public bool $showModal = false;
    public ?int $inventoryId = null;
    public int $adjustmentQty = 0;
    public string $adjustmentType = 'manual';
    public string $adjustmentNotes = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'outletFilter' => ['except' => ''],
        'typeFilter' => ['except' => ''],
        'dateFrom' => ['except' => ''],
        'dateTo' => ['except' => ''],
    ];

    protected function rules(): array
    {
        return [
            'inventoryId'     => 'required|exists:inventories,id',
            'adjustmentQty'   => 'required|integer|not_in:0',
            'adjustmentType'  => 'required|in:manual,damage,expiry,correction,stock_count',
            'adjustmentNotes' => 'nullable|string|max:500',
        ];
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingOutletFilter(): void { $this->resetPage(); }
    public function updatingTypeFilter(): void { $this->resetPage(); }

    public function openAdjustModal(int $inventoryId): void
    {
        $this->reset(['adjustmentQty', 'adjustmentType', 'adjustmentNotes']);
        $this->inventoryId = $inventoryId;
        $this->adjustmentType = 'manual';
        $this->showModal = true;
    }

    public function saveAdjustment(): void
    {
        $this->validate();

        $inventory = Inventory::findOrFail($this->inventoryId);
        $inventory->adjustQuantity(
            $this->adjustmentQty,
            $this->adjustmentType,
            null,
            null,
            auth()->id()
        );

        $this->showModal = false;
        $this->reset(['inventoryId', 'adjustmentQty', 'adjustmentType', 'adjustmentNotes']);
        session()->flash('success', 'Stock adjusted successfully.');
    }

    public function render()
    {
        $transactions = InventoryTransaction::with(['inventory.product', 'inventory.outlet', 'createdBy'])
            ->when($this->search, fn($q) => $q->whereHas('inventory.product', fn($pq) =>
                $pq->where('sku', 'ilike', "%{$this->search}%")
            ))
            ->when($this->outletFilter, fn($q) => $q->whereHas('inventory', fn($iq) =>
                $iq->where('outlet_id', $this->outletFilter)
            ))
            ->when($this->typeFilter, fn($q) => $q->where('transaction_type', $this->typeFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->latest()
            ->paginate(20);

        $inventories = Inventory::with(['product.translations', 'outlet'])
            ->products()
            ->orderBy('product_id')
            ->get();

        return view('livewire.admin.inventory.stock-adjustments', [
            'transactions' => $transactions,
            'inventories'  => $inventories,
            'outlets'      => Outlet::active()->orderBy('name')->get(),
            'transactionTypes' => [
                'manual'      => 'Manual',
                'damage'      => 'Damage',
                'expiry'      => 'Expiry',
                'correction'  => 'Correction',
                'stock_count' => 'Stock Count',
            ],
        ])->layout('layouts.admin');
    }
}