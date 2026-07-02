<?php

namespace App\Http\Livewire\Admin\Inventory;

use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

class StockTransfers extends Component
{
    use WithPagination;

    // Filters
    public string $search = '';
    public string $statusFilter = '';
    public string $outletFilter = '';

    // Create transfer modal
    public bool $showCreateModal = false;
    public string $fromOutletId = '';
    public string $toOutletId = '';
    public string $transferDate = '';
    public string $notes = '';
    public array $transferItems = [];

    // View transfer
    public bool $showViewModal = false;
    public ?InventoryTransfer $viewing = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'outletFilter' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->transferDate = now()->toDateString();
        $this->transferItems = [['product_id' => '', 'product_variant_id' => '', 'quantity_requested' => 1]];
    }

    protected function rules(): array
    {
        return [
            'fromOutletId'                      => 'required|exists:outlets,id|different:toOutletId',
            'toOutletId'                        => 'required|exists:outlets,id',
            'transferDate'                      => 'required|date',
            'notes'                             => 'nullable|string|max:1000',
            'transferItems'                     => 'required|array|min:1',
            'transferItems.*.product_id'        => 'required|exists:products,id',
            'transferItems.*.quantity_requested'=> 'required|integer|min:1',
        ];
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }

    public function openCreateModal(): void
    {
        $this->reset(['fromOutletId', 'toOutletId', 'notes']);
        $this->transferDate = now()->toDateString();
        $this->transferItems = [['product_id' => '', 'product_variant_id' => '', 'quantity_requested' => 1]];
        $this->showCreateModal = true;
    }

    public function addItem(): void
    {
        $this->transferItems[] = ['product_id' => '', 'product_variant_id' => '', 'quantity_requested' => 1];
    }

    public function removeItem(int $index): void
    {
        unset($this->transferItems[$index]);
        $this->transferItems = array_values($this->transferItems);
    }

    public function saveTransfer(): void
    {
        $this->validate();

        DB::transaction(function () {
            $transfer = InventoryTransfer::create([
                'from_outlet_id' => $this->fromOutletId,
                'to_outlet_id'   => $this->toOutletId,
                'status'         => 'pending',
                'requested_by'   => auth()->id(),
                'requested_at'   => now(),
                'notes'          => $this->notes,
            ]);

            foreach ($this->transferItems as $item) {
                $transfer->items()->create([
                    'product_id'         => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?: null,
                    'quantity_requested' => $item['quantity_requested'],
                    'quantity_received'  => 0,
                ]);
            }
        });

        $this->showCreateModal = false;
        session()->flash('success', 'Transfer created successfully.');
    }

    public function viewTransfer(int $id): void
    {
        $this->viewing = InventoryTransfer::with(['fromOutlet', 'toOutlet', 'items.product', 'requestedBy', 'approvedBy', 'completedBy'])->find($id);
        $this->showViewModal = true;
    }

    public function approveTransfer(int $id): void
    {
        $transfer = InventoryTransfer::findOrFail($id);
        $transfer->update([
            'status'      => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        session()->flash('success', 'Transfer approved.');
    }

    public function completeTransfer(int $id): void
    {
        $transfer = InventoryTransfer::with('items')->findOrFail($id);

        DB::transaction(function () use ($transfer) {
            foreach ($transfer->items as $item) {
                // Deduct from source outlet
                $fromInventory = \App\Models\Inventory::where('outlet_id', $transfer->from_outlet_id)
                    ->where('product_id', $item->product_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->first();

                if ($fromInventory) {
                    $fromInventory->adjustQuantity(-$item->quantity_requested, 'transfer_out', 'inventory_transfer', $transfer->id, auth()->id());
                }

                // Add to destination outlet
                $toInventory = \App\Models\Inventory::firstOrCreate(
                    ['outlet_id' => $transfer->to_outlet_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'inventory_type' => 'product'],
                    ['quantity_on_hand' => 0, 'status' => 'out_of_stock']
                );
                $toInventory->adjustQuantity($item->quantity_requested, 'transfer_in', 'inventory_transfer', $transfer->id, auth()->id());

                $item->update(['quantity_received' => $item->quantity_requested]);
            }

            $transfer->update([
                'status'       => 'completed',
                'completed_by' => auth()->id(),
                'completed_at' => now(),
            ]);
        });

        session()->flash('success', 'Transfer completed and stock updated.');
    }

    public function render()
    {
        $transfers = InventoryTransfer::with(['fromOutlet', 'toOutlet', 'requestedBy'])
            ->when($this->search, fn($q) => $q->where('transfer_number', 'ilike', "%{$this->search}%"))
            ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->outletFilter, fn($q) =>
                $q->where('from_outlet_id', $this->outletFilter)
                  ->orWhere('to_outlet_id', $this->outletFilter)
            )
            ->latest()
            ->paginate(15);

        return view('livewire.admin.inventory.stock-transfers', [
            'transfers' => $transfers,
            'outlets'   => Outlet::active()->orderBy('name')->get(),
            'products'  => Product::with('translations')->orderBy('sku')->get(),
            'statuses'  => ['pending', 'approved', 'completed', 'cancelled'],
        ])->layout('layouts.admin');
    }
}