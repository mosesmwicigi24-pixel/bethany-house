<?php

namespace App\Http\Livewire\Admin\Payments;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use Livewire\Component;
use Livewire\WithPagination;

class Transactions extends Component
{
    use WithPagination;

    public string $search         = '';
    public string $statusFilter   = '';
    public string $methodFilter   = '';
    public string $providerFilter = '';
    public string $dateFrom       = '';
    public string $dateTo         = '';
    public string $sortBy         = 'created_at';
    public string $sortDir        = 'desc';

    // Detail slide-over
    public bool     $showDetail = false;
    public ?Payment $viewing    = null;

    protected $queryString = [
        'search'         => ['except' => ''],
        'statusFilter'   => ['except' => ''],
        'methodFilter'   => ['except' => ''],
        'providerFilter' => ['except' => ''],
        'dateFrom'       => ['except' => ''],
        'dateTo'         => ['except' => ''],
    ];

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function viewPayment(int $id): void
    {
        $this->viewing = Payment::with(['order', 'transactions'])->find($id);
        $this->showDetail = true;
    }

    public function getSummaryProperty(): array
    {
        return Payment::selectRaw("
            COUNT(*)                                          AS total,
            COUNT(*) FILTER (WHERE status = 'paid')          AS paid,
            COUNT(*) FILTER (WHERE status = 'pending')       AS pending,
            COUNT(*) FILTER (WHERE status = 'failed')        AS failed,
            COALESCE(SUM(amount) FILTER (WHERE status = 'paid'), 0)        AS total_collected,
            COALESCE(SUM(refund_amount) FILTER (WHERE refund_amount > 0), 0) AS total_refunded
        ")->first()->toArray();
    }

    public function getMethodsProperty(): array
    {
        return Payment::distinct()->orderBy('payment_method')->pluck('payment_method')->toArray();
    }

    public function getProvidersProperty(): array
    {
        return Payment::whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider')->toArray();
    }

    public function render()
    {
        $payments = Payment::with(['order'])
            ->when($this->search, fn($q) =>
                $q->where('payment_number', 'ilike', "%{$this->search}%")
                  ->orWhere('provider_transaction_id', 'ilike', "%{$this->search}%")
                  ->orWhereHas('order', fn($oq) =>
                      $oq->where('order_number', 'ilike', "%{$this->search}%")
                         ->orWhere('customer_email', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->statusFilter,   fn($q) => $q->where('status', $this->statusFilter))
            ->when($this->methodFilter,   fn($q) => $q->where('payment_method', $this->methodFilter))
            ->when($this->providerFilter, fn($q) => $q->where('provider', $this->providerFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        return view('livewire.admin.payments.transactions', [
            'payments'  => $payments,
            'summary'   => $this->summary,
            'methods'   => $this->methods,
            'providers' => $this->providers,
        ])->layout('layouts.admin');
    }
}