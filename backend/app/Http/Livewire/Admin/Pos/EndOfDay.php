<?php

namespace App\Http\Livewire\Admin\Pos;

use App\Models\CashRegister;
use App\Models\Order;
use Livewire\Component;

class EndOfDay extends Component
{
    public ?CashRegister $register = null;

    // Close register form
    public string $actualCash   = '';
    public string $closingNotes = '';
    public bool   $showConfirm  = false;

    // Denomination entry
    public array $denominations = [
        1000 => 0, 500 => 0, 200 => 0, 100 => 0,
        50   => 0,  20 => 0,  10 => 0,   5 => 0,  1 => 0,
    ];
    public bool $useDenominations = false;

    // Closed state
    public bool              $justClosed = false;
    public ?array            $summary    = null;
    public ?CashRegister     $closedReg  = null;

    public function mount(): void
    {
        $this->register = CashRegister::with(['outlet', 'openedBy'])
            ->where('status', 'open')
            ->where('opened_by', auth()->id())
            ->latest('opened_at')
            ->first();
    }

    public function getDenominationTotal(): float
    {
        $total = 0;
        foreach ($this->denominations as $denom => $count) {
            $total += $denom * (int) $count;
        }
        return $total;
    }

    public function updatedDenominations(): void
    {
        if ($this->useDenominations) {
            $this->actualCash = (string) $this->getDenominationTotal();
        }
    }

    public function getShiftSalesProperty(): array
    {
        if (!$this->register) return [];

        return Order::where('order_type', 'pos')
            ->where('outlet_id', $this->register->outlet_id)
            ->where('status', 'completed')
            ->whereDate('created_at', $this->register->opened_at ?? today())
            ->selectRaw("
                COUNT(*) AS transaction_count,
                COALESCE(SUM(total_amount), 0) AS total_revenue,
                COALESCE(SUM(total_amount) FILTER (WHERE payment_method = 'cash'),  0) AS cash_total,
                COALESCE(SUM(total_amount) FILTER (WHERE payment_method = 'card'),  0) AS card_total,
                COALESCE(SUM(total_amount) FILTER (WHERE payment_method = 'mpesa'), 0) AS mpesa_total,
                COALESCE(SUM(discount_amount), 0) AS total_discounts
            ")->first()->toArray();
    }

    public function confirmClose(): void
    {
        $this->validate([
            'actualCash' => 'required|numeric|min:0',
        ]);
        $this->showConfirm = true;
    }

    public function closeRegister(): void
    {
        if (!$this->register) return;

        $denominationData = $this->useDenominations
            ? array_filter($this->denominations, fn($c) => $c > 0)
            : null;

        $this->closedReg = $this->register;
        $this->summary   = $this->register->getShiftSummary();

        $this->register->close(
            (float) $this->actualCash,
            auth()->id(),
            $this->closingNotes ?: null,
            $denominationData
        );

        $this->register    = null;
        $this->justClosed  = true;
        $this->showConfirm = false;
    }

    public function render()
    {
        return view('livewire.admin.pos.end-of-day', [
            'shiftSales'   => $this->register ? $this->shiftSales : [],
            'denomTotal'   => $this->getDenominationTotal(),
        ])->layout('layouts.admin');
    }
}