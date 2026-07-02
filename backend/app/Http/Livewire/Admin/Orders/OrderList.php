<?php

namespace App\Http\Livewire\Admin\Orders;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

class OrderList extends Component
{
    use WithPagination;

    // Filters
    public string $search       = '';
    public string $status       = '';   // pre-set from URL (pending, processing, shipped, completed)
    public string $paymentStatus = '';
    public string $orderType    = '';
    public string $dateFrom     = '';
    public string $dateTo       = '';
    public string $sortBy       = 'created_at';
    public string $sortDir      = 'desc';
    public int    $perPage      = 20;

    // Order detail slide-over
    public bool   $showDetail   = false;
    public ?Order $viewing      = null;

    // Status update modal
    public bool   $showStatusModal = false;
    public ?int   $updatingOrderId = null;
    public string $newStatus       = '';
    public string $statusNotes     = '';
    public bool   $notifyCustomer  = false;

    protected $queryString = [
        'search'        => ['except' => ''],
        'status'        => ['except' => ''],
        'paymentStatus' => ['except' => ''],
        'orderType'     => ['except' => ''],
        'dateFrom'      => ['except' => ''],
        'dateTo'        => ['except' => ''],
        'sortBy'        => ['except' => 'created_at'],
        'sortDir'       => ['except' => 'desc'],
    ];

    public function mount(string $status = ''): void
    {
        // Allow pre-filtering from URL parameter
        if ($status) {
            $this->status = $status;
        }
    }

    public function updatingSearch(): void   { $this->resetPage(); }
    public function updatingStatus(): void   { $this->resetPage(); }
    public function updatingPaymentStatus(): void { $this->resetPage(); }
    public function updatingOrderType(): void { $this->resetPage(); }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $column;
            $this->sortDir = 'desc';
        }
    }

    public function viewOrder(int $id): void
    {
        $this->viewing = Order::with([
            'items',
            'payments',
            'shipments.tracking',
            'statusHistory.createdBy',
            'outlet',
        ])->find($id);
        $this->showDetail = true;
    }

    public function openStatusModal(int $orderId, string $currentStatus): void
    {
        $this->updatingOrderId = $orderId;
        $this->newStatus       = $currentStatus;
        $this->statusNotes     = '';
        $this->notifyCustomer  = false;
        $this->showStatusModal = true;
    }

    public function updateStatus(): void
    {
        $this->validate([
            'updatingOrderId' => 'required|exists:orders,id',
            'newStatus'       => 'required|string|in:pending,processing,shipped,completed,cancelled',
            'statusNotes'     => 'nullable|string|max:500',
        ]);

        DB::transaction(function () {
            $order = Order::findOrFail($this->updatingOrderId);

            $order->update(['status' => $this->newStatus]);

            OrderStatusHistory::create([
                'order_id'        => $order->id,
                'status'          => $this->newStatus,
                'notes'           => $this->statusNotes ?: null,
                'notify_customer' => $this->notifyCustomer,
                'created_by'      => auth()->id(),
            ]);
        });

        // Refresh viewing if the detail panel is open for this order
        if ($this->showDetail && $this->viewing?->id === $this->updatingOrderId) {
            $this->viewOrder($this->updatingOrderId);
        }

        $this->showStatusModal = false;
        session()->flash('success', 'Order status updated successfully.');
    }

    public function cancelOrder(int $id): void
    {
        DB::transaction(function () use ($id) {
            $order = Order::findOrFail($id);

            if (!$order->canBeCancelled()) {
                session()->flash('error', 'This order cannot be cancelled.');
                return;
            }

            $order->update([
                'status'       => 'cancelled',
                'cancelled_at' => now(),
            ]);

            OrderStatusHistory::create([
                'order_id'        => $id,
                'status'          => 'cancelled',
                'notes'           => 'Cancelled by admin.',
                'notify_customer' => true,
                'created_by'      => auth()->id(),
            ]);
        });

        session()->flash('success', 'Order cancelled.');
    }

    public function getSummaryProperty(): array
    {
        return Order::selectRaw("
            COUNT(*) FILTER (WHERE status = 'pending')    AS pending,
            COUNT(*) FILTER (WHERE status = 'processing') AS processing,
            COUNT(*) FILTER (WHERE status = 'shipped')    AS shipped,
            COUNT(*) FILTER (WHERE status = 'completed')  AS completed,
            COUNT(*) FILTER (WHERE status = 'cancelled')  AS cancelled,
            COUNT(*)                                       AS total
        ")->first()->toArray();
    }

    public function render()
    {
        $orders = Order::with(['items', 'outlet'])
            ->when($this->search, fn($q) =>
                $q->where('order_number', 'ilike', "%{$this->search}%")
                  ->orWhere('customer_email', 'ilike', "%{$this->search}%")
                  ->orWhere(DB::raw("customer_first_name || ' ' || customer_last_name"), 'ilike', "%{$this->search}%")
            )
            ->when($this->status, fn($q) => $q->where('status', $this->status))
            ->when($this->paymentStatus, fn($q) => $q->where('payment_status', $this->paymentStatus))
            ->when($this->orderType, fn($q) => $q->where('order_type', $this->orderType))
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate($this->perPage);

        return view('livewire.admin.orders.order-list', [
            'orders'   => $orders,
            'summary'  => $this->summary,
            'statuses' => ['pending', 'processing', 'shipped', 'completed', 'cancelled'],
            'paymentStatuses' => ['pending', 'paid', 'partially_paid', 'refunded', 'failed'],
        ])->layout('layouts.admin');
    }
}