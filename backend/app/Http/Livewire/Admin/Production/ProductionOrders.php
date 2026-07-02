<?php

namespace App\Http\Livewire\Admin\Production;

use App\Models\ProductionOrder;
use App\Models\Product;
use App\Models\Outlet;
use Livewire\Component;
use Livewire\WithPagination;

class ProductionOrders extends Component
{
    use WithPagination;

    public string $search        = '';
    public string $statusFilter  = '';
    public string $priorityFilter = '';
    public string $outletFilter  = '';
    public string $dateFrom      = '';
    public string $dateTo        = '';
    public string $sortBy        = 'created_at';
    public string $sortDir       = 'desc';

    // Detail slide-over
    public bool              $showDetail = false;
    public ?ProductionOrder  $viewing    = null;

    // Status update
    public bool   $showStatusModal  = false;
    public ?int   $updatingId       = null;
    public string $newStatus        = '';
    public string $statusNotes      = '';

    protected $queryString = [
        'search'         => ['except' => ''],
        'statusFilter'   => ['except' => ''],
        'priorityFilter' => ['except' => ''],
        'outletFilter'   => ['except' => ''],
        'dateFrom'       => ['except' => ''],
        'dateTo'         => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function viewOrder(int $id): void
    {
        $this->viewing = ProductionOrder::with([
            'product.translations',
            'variant',
            'tasks.stage',
            'tasks.assignedTo',
            'materialAllocations.material',
            'customerOrder',
            'createdBy',
            'outlet',
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
            'updatingId' => 'required|exists:production_orders,id',
            'newStatus'  => 'required|in:pending,in_progress,on_hold,quality_check,completed,cancelled',
        ]);

        $order = ProductionOrder::findOrFail($this->updatingId);
        $data  = ['status' => $this->newStatus];

        if ($this->newStatus === 'in_progress' && !$order->started_at) {
            $data['started_at'] = now();
        }
        if ($this->newStatus === 'completed' && !$order->completed_at) {
            $data['completed_at'] = now();
        }

        $order->update($data);

        if ($this->showDetail && $this->viewing?->id === $this->updatingId) {
            $this->viewOrder($this->updatingId);
        }

        $this->showStatusModal = false;
        session()->flash('success', 'Production order status updated.');
    }

    public function getSummaryProperty(): array
    {
        return ProductionOrder::selectRaw("
            COUNT(*) FILTER (WHERE status = 'pending')        AS pending,
            COUNT(*) FILTER (WHERE status = 'in_progress')    AS in_progress,
            COUNT(*) FILTER (WHERE status = 'quality_check')  AS quality_check,
            COUNT(*) FILTER (WHERE status = 'completed')      AS completed,
            COUNT(*) FILTER (WHERE due_date < NOW() AND completed_at IS NULL) AS overdue
        ")->first()->toArray();
    }

    public function render()
    {
        $orders = ProductionOrder::with(['product.translations', 'variant', 'outlet', 'createdBy'])
            ->withCount(['tasks', 'tasks as completed_tasks_count' => fn($q) => $q->where('status', 'completed')])
            ->when($this->search, fn($q) =>
                $q->where('order_number', 'ilike', "%{$this->search}%")
                  ->orWhereHas('product.translations', fn($tq) =>
                      $tq->where('name', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->statusFilter,   fn($q) => $q->where('status',   $this->statusFilter))
            ->when($this->priorityFilter, fn($q) => $q->where('priority', $this->priorityFilter))
            ->when($this->outletFilter,   fn($q) => $q->where('outlet_id', $this->outletFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('due_date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('due_date', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.production.production-orders', [
            'orders'     => $orders,
            'summary'    => $this->summary,
            'outlets'    => Outlet::active()->orderBy('name')->get(),
            'statuses'   => ['pending', 'in_progress', 'on_hold', 'quality_check', 'completed', 'cancelled'],
            'priorities' => ['low', 'normal', 'high', 'urgent'],
        ])->layout('layouts.admin');
    }
}