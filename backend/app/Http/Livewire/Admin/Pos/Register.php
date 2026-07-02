<?php

namespace App\Http\Livewire\Admin\Pos;

use App\Models\CashRegister;
use App\Models\Outlet;
use Livewire\Component;
use Livewire\WithPagination;

class Register extends Component
{
    use WithPagination;

    public ?CashRegister $register = null;

    // Open register modal
    public bool   $showOpenModal     = false;
    public string $openOutletId      = '';
    public string $openingBalance    = '';
    public string $openNotes         = '';

    // Cash in/out modal
    public bool   $showCashModal     = false;
    public string $cashAction        = 'in'; // 'in' | 'out'
    public string $cashAmount        = '';
    public string $cashReason        = '';

    // Denomination count (KES notes/coins)
    public array  $denominations     = [
        1000 => 0, 500 => 0, 200 => 0, 100 => 0,
        50   => 0,  20 => 0,  10 => 0,   5 => 0,  1 => 0,
    ];

    public function mount(): void
    {
        $this->resolveRegister();
    }

    protected function resolveRegister(): void
    {
        $this->register = CashRegister::with(['outlet', 'openedBy'])
            ->where('status', 'open')
            ->where('opened_by', auth()->id())
            ->latest('opened_at')
            ->first();
    }

    public function openRegister(): void
    {
        $this->validate([
            'openOutletId'   => 'required|exists:outlets,id',
            'openingBalance' => 'required|numeric|min:0',
        ]);

        $register = CashRegister::where('outlet_id', $this->openOutletId)
            ->where('status', '!=', 'open')
            ->firstOrCreate(
                ['outlet_id' => $this->openOutletId],
                ['register_name' => 'Main Register', 'currency_code' => 'KES']
            );

        $register->open((float) $this->openingBalance, auth()->id(), $this->openNotes ?: null);

        $this->resolveRegister();
        $this->showOpenModal = false;
        $this->reset(['openOutletId', 'openingBalance', 'openNotes']);
        session()->flash('success', 'Cash register opened.');
    }

    public function recordCashMovement(): void
    {
        $this->validate([
            'cashAmount' => 'required|numeric|min:0.01',
            'cashReason' => 'required|string|max:200',
        ]);

        if (!$this->register) return;

        if ($this->cashAction === 'in') {
            $this->register->recordCashIn((float) $this->cashAmount, $this->cashReason, auth()->id());
        } else {
            $this->register->recordCashOut((float) $this->cashAmount, $this->cashReason, auth()->id());
        }

        $this->resolveRegister();
        $this->showCashModal = false;
        $this->reset(['cashAmount', 'cashReason']);
        session()->flash('success', 'Cash movement recorded.');
    }

    public function getDenominationTotal(): float
    {
        $total = 0;
        foreach ($this->denominations as $denom => $count) {
            $total += $denom * (int) $count;
        }
        return $total;
    }

    public function getTransactionsProperty()
    {
        if (!$this->register) return collect();
        return $this->register->transactions()
            ->with(['order', 'createdBy'])
            ->latest()
            ->limit(30)
            ->get();
    }

    public function render()
    {
        return view('livewire.admin.pos.register', [
            'outlets'      => Outlet::active()->orderBy('name')->get(),
            'transactions' => $this->transactions,
            'denomTotal'   => $this->getDenominationTotal(),
        ])->layout('layouts.admin');
    }
}