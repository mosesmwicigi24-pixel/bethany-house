<?php

namespace App\Http\Livewire\Admin\Orders;

use App\Models\OrderReturn;
use App\Models\ReturnItem;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

class ReturnsRefunds extends Component
{
    use WithPagination;

    public string $search        = '';
    public string $statusFilter  = '';
    public string $dateFrom      = '';
    public string $dateTo        = '';
    public string $sortBy        = 'created_at';
    public string $sortDir       = 'desc';

    // View modal
    public bool         $showDetail = false;
    public ?OrderReturn $viewing    = null;

    // Approve / process modal
    public bool   $showProcessModal  = false;
    public ?int   $processingId      = null;
    public string $processAction     = ''; // approve | receive | refund
    public string $adminNotes        = '';
    public string $refundMethod      = 'original';
    public string $refundAmount      = '';

    protected $queryString = [
        'search'       => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'dateFrom'     => ['except' => ''],
        'dateTo'       => ['except' => ''],
    ];

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingStatusFilter(): void  { $this->resetPage(); }

    public function viewReturn(int $id): void
    {
        $this->viewing    = OrderReturn::with(['order', 'items.orderItem', 'createdBy', 'approvedBy'])->find($id);
        $this->showDetail = true;
    }

    public function openProcess(int $id, string $action): void
    {
        $return = OrderReturn::with('order')->findOrFail($id);
        $this->processingId  = $id;
        $this->processAction = $action;
        $this->adminNotes    = '';
        $this->refundMethod  = 'original';
        $this->refundAmount  = $action === 'refund' ? (string) $return->refund_amount : '';
        $this->showProcessModal = true;
    }

    public function processReturn(): void
    {
        $this->validate([
            'processingId'  => 'required|exists:order_returns,id',
            'processAction' => 'required|in:approve,receive,refund',
            'adminNotes'    => 'nullable|string|max:1000',
            'refundAmount'  => 'required_if:processAction,refund|nullable|numeric|min:0',
            'refundMethod'  => 'required_if:processAction,refund|nullable|string',
        ]);

        DB::transaction(function () {
            $return = OrderReturn::findOrFail($this->processingId);

            match ($this->processAction) {
                'approve' => $return->update([
                    'status'      => 'approved',
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                    'admin_notes' => $this->adminNotes ?: $return->admin_notes,
                ]),
                'receive' => $return->update([
                    'status'      => 'received',
                    'received_at' => now(),
                    'admin_notes' => $this->adminNotes ?: $return->admin_notes,
                ]),
                'refund' => $return->update([
                    'status'        => 'completed',
                    'refunded_at'   => now(),
                    'refund_amount' => $this->refundAmount,
                    'refund_method' => $this->refundMethod,
                    'admin_notes'   => $this->adminNotes ?: $return->admin_notes,
                ]),
            };
        });

        $this->showProcessModal = false;

        if ($this->showDetail && $this->viewing?->id === $this->processingId) {
            $this->viewReturn($this->processingId);
        }

        session()->flash('success', 'Return updated successfully.');
    }

    public function getSummaryProperty(): array
    {
        return OrderReturn::selectRaw("
            COUNT(*) FILTER (WHERE status = 'requested') AS requested,
            COUNT(*) FILTER (WHERE status = 'approved')  AS approved,
            COUNT(*) FILTER (WHERE status = 'received')  AS received,
            COUNT(*) FILTER (WHERE status = 'completed') AS completed,
            COALESCE(SUM(refund_amount) FILTER (WHERE status = 'completed'), 0) AS total_refunded
        ")->first()->toArray();
    }

    public function render()
    {
        $returns = OrderReturn::with(['order', 'createdBy'])
            ->when($this->search, fn($q) =>
                $q->where('return_number', 'ilike', "%{$this->search}%")
                  ->orWhereHas('order', fn($oq) =>
                      $oq->where('order_number', 'ilike', "%{$this->search}%")
                         ->orWhere('customer_email', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->statusFilter, fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.orders.returns-refunds', [
            'returns' => $returns,
            'summary' => $this->summary,
            'statuses' => ['requested', 'approved', 'received', 'completed', 'rejected'],
            'refundMethods' => ['original' => 'Original Payment Method', 'bank_transfer' => 'Bank Transfer', 'store_credit' => 'Store Credit', 'cash' => 'Cash'],
        ])->layout('layouts.admin');
    }
}