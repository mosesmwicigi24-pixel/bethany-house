<?php

namespace App\Http\Livewire\Admin\Pos;

use App\Models\Order;
use App\Models\CashRegister;
use App\Models\Outlet;
use Livewire\Component;
use Livewire\WithPagination;

class SalesHistory extends Component
{
    use WithPagination;

    public string $search        = '';
    public string $dateFrom      = '';
    public string $dateTo        = '';
    public string $payMethod     = '';
    public string $outletFilter  = '';
    public string $sortBy        = 'created_at';
    public string $sortDir       = 'desc';

    public bool   $showDetail    = false;
    public ?Order $viewing       = null;

    // Reprint receipt
    public bool   $showReceipt   = false;
    public ?Order $receiptOrder  = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'dateFrom'     => ['except' => ''],
        'dateTo'       => ['except' => ''],
        'payMethod'    => ['except' => ''],
        'outletFilter' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->dateFrom = now()->startOfDay()->toDateString();
        $this->dateTo   = now()->toDateString();
    }

    public function updatingSearch(): void { $this->resetPage(); }

    public function sort(string $col): void
    {
        $this->sortBy  = $col;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function viewOrder(int $id): void
    {
        $this->viewing    = Order::with(['items', 'payments'])->find($id);
        $this->showDetail = true;
    }

    public function printReceipt(int $id): void
    {
        $this->receiptOrder = Order::with(['items', 'outlet'])->find($id);
        $this->showReceipt  = true;
    }

    public function getSummaryProperty(): array
    {
        $base = Order::where('order_type', 'pos')
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->outletFilter, fn($q) => $q->where('outlet_id', $this->outletFilter));

        return $base->selectRaw("
            COUNT(*) AS total_transactions,
            COALESCE(SUM(total_amount), 0) AS total_revenue,
            COALESCE(SUM(total_amount) FILTER (WHERE payment_method = 'cash'), 0) AS cash_total,
            COALESCE(SUM(total_amount) FILTER (WHERE payment_method = 'card'), 0) AS card_total,
            COALESCE(SUM(total_amount) FILTER (WHERE payment_method = 'mpesa'), 0) AS mpesa_total
        ")->first()->toArray();
    }

    public function render()
    {
        $orders = Order::with(['items', 'outlet'])
            ->where('order_type', 'pos')
            ->when($this->search, fn($q) =>
                $q->where('order_number', 'ilike', "%{$this->search}%")
                  ->orWhere('customer_phone', 'ilike', "%{$this->search}%")
                  ->orWhere('customer_email', 'ilike', "%{$this->search}%")
            )
            ->when($this->dateFrom, fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->payMethod, fn($q) => $q->where('payment_method', $this->payMethod))
            ->when($this->outletFilter, fn($q) => $q->where('outlet_id', $this->outletFilter))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        return view('livewire.admin.pos.sales-history', [
            'orders'     => $orders,
            'summary'    => $this->summary,
            'outlets'    => Outlet::active()->orderBy('name')->get(),
            'payMethods' => ['cash', 'card', 'mpesa', 'split'],
        ])->layout('layouts.admin');
    }
}