<?php

namespace App\Http\Livewire\Admin\Procurement;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Outlet;
use Livewire\Component;
use Livewire\WithPagination;

class PurchaseOrders extends Component
{
    use WithPagination;

    public string $search          = '';
    public string $statusFilter    = '';
    public string $supplierFilter  = '';
    public string $paymentFilter   = '';
    public string $dateFrom        = '';
    public string $dateTo          = '';
    public string $sortBy          = 'created_at';
    public string $sortDir         = 'desc';

    // Detail slide-over
    public bool          $showDetail = false;
    public ?PurchaseOrder $viewing   = null;

    // Status update modal
    public bool   $showStatusModal = false;
    public ?int   $updatingId      = null;
    public string $newStatus       = '';
    public string $statusNotes     = '';

    protected $queryString = [
        'search'         => ['except' => ''],
        'statusFilter'   => ['except' => ''],
        'supplierFilter' => ['except' => ''],
        'paymentFilter'  => ['except' => ''],
        'dateFrom'       => ['except' => ''],
        'dateTo'         => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function viewOrder(int $id): void
    {
        $this->viewing = PurchaseOrder::with([
            'supplier',
            'outlet',
            'items.product.translations',
            'items.material',
            'goodsReceivedNotes.receivedBy',
            'createdBy',
            'approvedBy',
        ])->find($id);
        $this->showDetail = true;
    }

    public function openStatusModal(int $id, string $current): void
    {
        $this->updatingId   = $id;
        $this->newStatus    = $current;
        $this->statusNotes  = '';
        $this->showStatusModal = true;
    }

    public function updateStatus(): void
    {
        $this->validate([
            'updatingId' => 'required|exists:purchase_orders,id',
            'newStatus'  => 'required|in:draft,submitted,approved,ordered,partially_received,completed,cancelled',
        ]);

        $po   = PurchaseOrder::findOrFail($this->updatingId);
        $data = ['status' => $this->newStatus];

        if ($this->newStatus === 'approved' && !$po->approved_at) {
            $data['approved_by'] = auth()->id();
            $data['approved_at'] = now();
        }

        $po->update($data);

        if ($this->showDetail && $this->viewing?->id === $this->updatingId) {
            $this->viewOrder($this->updatingId);
        }

        $this->showStatusModal = false;
        session()->flash('success', 'Purchase order status updated.');
    }

    public function getSummaryProperty(): array
    {
        return PurchaseOrder::selectRaw("
            COUNT(*) FILTER (WHERE status = 'draft')               AS draft,
            COUNT(*) FILTER (WHERE status = 'approved')            AS approved,
            COUNT(*) FILTER (WHERE status IN ('ordered','partially_received')) AS pending_receipt,
            COUNT(*) FILTER (WHERE status = 'completed')           AS completed,
            COALESCE(SUM(total_amount) FILTER (WHERE status NOT IN ('draft','cancelled')), 0) AS total_value
        ")->first()->toArray();
    }

    public function render()
    {
        $orders = PurchaseOrder::with(['supplier', 'outlet', 'createdBy'])
            ->withCount('items')
            ->when($this->search, fn($q) =>
                $q->where('po_number', 'ilike', "%{$this->search}%")
                  ->orWhereHas('supplier', fn($sq) =>
                      $sq->where('name', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->statusFilter,   fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->supplierFilter, fn($q) => $q->where('supplier_id', $this->supplierFilter))
            ->when($this->paymentFilter,  fn($q) => $q->where('payment_status', $this->paymentFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('order_date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('order_date', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.procurement.purchase-orders', [
            'orders'    => $orders,
            'summary'   => $this->summary,
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'statuses'  => ['draft','submitted','approved','ordered','partially_received','completed','cancelled'],
            'paymentStatuses' => ['pending','partial','paid'],
        ])->layout('layouts.admin');
    }
}