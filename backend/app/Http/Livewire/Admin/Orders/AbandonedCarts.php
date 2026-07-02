<?php

namespace App\Http\Livewire\Admin\Orders;

use App\Models\Cart;
use Livewire\Component;
use Livewire\WithPagination;

class AbandonedCarts extends Component
{
    use WithPagination;

    public string $search      = '';
    public string $dateFrom    = '';
    public string $dateTo      = '';
    public string $sortBy      = 'abandoned_at';
    public string $sortDir     = 'desc';
    public int    $minValue    = 0;

    // View detail
    public bool  $showDetail = false;
    public ?Cart $viewing    = null;

    protected $queryString = [
        'search'   => ['except' => ''],
        'dateFrom' => ['except' => ''],
        'dateTo'   => ['except' => ''],
        'minValue' => ['except' => 0],
    ];

    public function updatingSearch(): void   { $this->resetPage(); }
    public function updatingDateFrom(): void { $this->resetPage(); }

    public function sort(string $column): void
    {
        $this->sortBy  = $column;
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    public function viewCart(int $id): void
    {
        $this->viewing    = Cart::with(['items.product.translations', 'items.variant', 'user', 'customer'])->find($id);
        $this->showDetail = true;
    }

    public function sendRecoveryEmail(int $cartId): void
    {
        // Hook in your notification/mailing system here
        // e.g. Cart::find($cartId)->user?->notify(new AbandonedCartRecovery(...))
        session()->flash('success', 'Recovery email queued successfully.');
    }

    public function deleteCart(int $cartId): void
    {
        Cart::findOrFail($cartId)->delete();
        $this->showDetail = false;
        $this->viewing    = null;
        session()->flash('success', 'Cart removed.');
    }

    public function getSummaryProperty(): array
    {
        $stats = Cart::abandoned()
            ->selectRaw("
                COUNT(*) AS total_carts,
                COALESCE(SUM(total_amount), 0) AS total_value,
                COALESCE(AVG(total_amount), 0) AS avg_value
            ")->first();

        return [
            'total_carts' => $stats->total_carts ?? 0,
            'total_value' => $stats->total_value ?? 0,
            'avg_value'   => $stats->avg_value ?? 0,
        ];
    }

    public function render()
    {
        $carts = Cart::with(['items', 'user', 'customer'])
            ->abandoned()
            ->when($this->search, fn($q) =>
                $q->whereHas('user', fn($uq) =>
                    $uq->where('email', 'ilike', "%{$this->search}%")
                       ->orWhereRaw("first_name || ' ' || last_name ILIKE ?", ["%{$this->search}%"])
                )
                ->orWhereHas('customer', fn($cq) =>
                    $cq->where('email', 'ilike', "%{$this->search}%")
                )
            )
            ->when($this->dateFrom, fn($q) => $q->whereDate('abandoned_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->whereDate('abandoned_at', '<=', $this->dateTo))
            ->when($this->minValue > 0, fn($q) => $q->where('total_amount', '>=', $this->minValue))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.orders.abandoned-carts', [
            'carts'   => $carts,
            'summary' => $this->summary,
        ])->layout('layouts.admin');
    }
}