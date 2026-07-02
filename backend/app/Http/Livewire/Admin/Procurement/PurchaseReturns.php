<?php

namespace App\Http\Livewire\Admin\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

class PurchaseReturns extends Component
{
    use WithPagination;

    public string $search          = '';
    public string $statusFilter    = '';
    public string $supplierFilter  = '';
    public string $dateFrom        = '';
    public string $dateTo          = '';
    public string $sortBy          = 'created_at';
    public string $sortDir         = 'desc';

    // Create return modal
    public bool   $showCreateModal  = false;
    public string $poSearch         = '';
    public string $poError          = '';
    public ?PurchaseOrder $selectedPo = null;
    public string $returnReason     = '';
    public string $creditAmount     = '';
    public string $returnNotes      = '';

    // Detail slide-over
    public bool           $showDetail = false;
    public ?PurchaseReturn $viewing   = null;

    // Approve modal
    public bool   $showApproveModal = false;
    public ?int   $approvingId      = null;
    public string $approveNotes     = '';

    public function updatingSearch(): void { $this->resetPage(); }

    public function searchPo(): void
    {
        $this->poError    = '';
        $this->selectedPo = null;

        $po = PurchaseOrder::with('supplier')
            ->where('po_number', $this->poSearch)
            ->whereIn('status', ['completed', 'partially_received'])
            ->first();

        if (!$po) {
            $this->poError = 'No completed PO found with that number.';
            return;
        }
        $this->selectedPo   = $po;
        $this->creditAmount = (string) $po->total_amount;
    }

    public function saveReturn(): void
    {
        $this->validate([
            'selectedPo'   => 'required',
            'returnReason' => 'required|string|max:500',
            'creditAmount' => 'required|numeric|min:0.01',
        ]);

        PurchaseReturn::create([
            'purchase_order_id' => $this->selectedPo->id,
            'supplier_id'       => $this->selectedPo->supplier_id,
            'return_date'       => now()->toDateString(),
            'reason'            => $this->returnReason,
            'status'            => 'pending',
            'credit_amount'     => $this->creditAmount,
            'notes'             => $this->returnNotes ?: null,
            'created_by'        => auth()->id(),
        ]);

        $this->showCreateModal = false;
        $this->reset(['poSearch', 'selectedPo', 'returnReason', 'creditAmount', 'returnNotes', 'poError']);
        session()->flash('success', 'Purchase return created.');
    }

    public function viewReturn(int $id): void
    {
        $this->viewing = PurchaseReturn::with(['purchaseOrder.items.product.translations', 'purchaseOrder.items.material', 'supplier', 'createdBy'])->find($id);
        $this->showDetail = true;
    }

    public function openApprove(int $id): void
    {
        $this->approvingId  = $id;
        $this->approveNotes = '';
        $this->showApproveModal = true;
    }

    public function approveReturn(): void
    {
        $return = PurchaseReturn::findOrFail($this->approvingId);
        $return->update([
            'status' => 'approved',
            'notes'  => $this->approveNotes
                ? ($return->notes ? $return->notes . "\n\nApproval notes: " . $this->approveNotes : $this->approveNotes)
                : $return->notes,
        ]);

        $this->showApproveModal = false;
        session()->flash('success', 'Return approved.');
    }

    public function completeReturn(int $id): void
    {
        PurchaseReturn::findOrFail($id)->update(['status' => 'completed']);
        session()->flash('success', 'Return marked as completed.');
    }

    public function getSummaryProperty(): array
    {
        return PurchaseReturn::selectRaw("
            COUNT(*) FILTER (WHERE status = 'pending')   AS pending,
            COUNT(*) FILTER (WHERE status = 'approved')  AS approved,
            COUNT(*) FILTER (WHERE status = 'completed') AS completed,
            COALESCE(SUM(credit_amount) FILTER (WHERE status = 'completed'), 0) AS total_credits
        ")->first()->toArray();
    }

    public function render()
    {
        $returns = PurchaseReturn::with(['purchaseOrder', 'supplier', 'createdBy'])
            ->when($this->search, fn($q) =>
                $q->where('return_number', 'ilike', "%{$this->search}%")
                  ->orWhereHas('supplier', fn($sq) =>
                      $sq->where('name', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->statusFilter,   fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->supplierFilter, fn($q) => $q->where('supplier_id', $this->supplierFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('return_date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('return_date', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.procurement.purchase-returns', [
            'returns'   => $returns,
            'summary'   => $this->summary,
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'statuses'  => ['pending', 'approved', 'completed', 'rejected'],
        ])->layout('layouts.admin');
    }
}