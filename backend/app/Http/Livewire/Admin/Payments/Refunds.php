<?php

namespace App\Http\Livewire\Admin\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Refunds extends Component
{
    use WithPagination;

    public string $search        = '';
    public string $methodFilter  = '';
    public string $dateFrom      = '';
    public string $dateTo        = '';
    public string $sortBy        = 'refunded_at';
    public string $sortDir       = 'desc';

    // Issue refund modal
    public bool   $showRefundModal  = false;
    public string $refundPaySearch  = '';
    public string $refundPayError   = '';
    public ?Payment $refundPayment  = null;
    public string $refundAmount     = '';
    public string $refundReason     = '';

    // Detail slide-over
    public bool     $showDetail = false;
    public ?Payment $viewing    = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'methodFilter' => ['except' => ''],
        'dateFrom'     => ['except' => ''],
        'dateTo'       => ['except' => ''],
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

    // ── Refund lookup ──────────────────────────────────────────────────────────

    public function searchPayment(): void
    {
        $this->refundPayError   = '';
        $this->refundPayment    = null;
        $this->refundAmount     = '';

        $pay = Payment::with('order')
            ->where(fn($q) =>
                $q->where('payment_number', $this->refundPaySearch)
                  ->orWhereHas('order', fn($oq) => $oq->where('order_number', $this->refundPaySearch))
            )
            ->where('status', 'paid')
            ->first();

        if (!$pay) {
            $this->refundPayError = 'No paid payment found with that number.';
            return;
        }

        if (!$pay->canBeRefunded()) {
            $this->refundPayError = 'This payment has already been fully refunded.';
            return;
        }

        $this->refundPayment = $pay;
        $this->refundAmount  = (string) $pay->getRemainingRefundableAmount();
    }

    public function issueRefund(): void
    {
        $this->validate([
            'refundPayment' => 'required',
            'refundAmount'  => 'required|numeric|min:0.01',
            'refundReason'  => 'nullable|string|max:500',
        ]);

        $max = $this->refundPayment->getRemainingRefundableAmount();

        if ((float) $this->refundAmount > $max) {
            $this->addError('refundAmount', "Maximum refundable amount is {$max}.");
            return;
        }

        DB::transaction(function () {
            $payment = $this->refundPayment;

            $newRefundTotal = $payment->refund_amount + (float) $this->refundAmount;

            $payment->update([
                'refund_amount' => $newRefundTotal,
                'refunded_at'   => now(),
                'status'        => $newRefundTotal >= $payment->amount ? 'refunded' : 'partial_refund',
            ]);

            // Log a transaction record
            $payment->transactions()->create([
                'transaction_type' => 'refund',
                'amount'           => (float) $this->refundAmount,
                'status'           => 'success',
                'request_payload'  => ['reason' => $this->refundReason ?: null],
                'response_payload' => ['refunded_by' => auth()->id(), 'at' => now()->toISOString()],
            ]);
        });

        $this->showRefundModal = false;
        $this->reset(['refundPaySearch', 'refundPayment', 'refundAmount', 'refundReason', 'refundPayError']);
        session()->flash('success', 'Refund issued successfully.');
    }

    public function getSummaryProperty(): array
    {
        return Payment::whereNotNull('refunded_at')
            ->selectRaw("
                COUNT(DISTINCT id)                        AS total_refunds,
                COALESCE(SUM(refund_amount), 0)           AS total_refunded,
                COUNT(*) FILTER (WHERE status = 'refunded') AS full_refunds,
                COUNT(*) FILTER (WHERE status = 'partial_refund') AS partial_refunds
            ")->first()->toArray();
    }

    public function render()
    {
        $refunds = Payment::with(['order'])
            ->whereNotNull('refunded_at')
            ->when($this->search, fn($q) =>
                $q->where('payment_number', 'ilike', "%{$this->search}%")
                  ->orWhereHas('order', fn($oq) =>
                      $oq->where('order_number', 'ilike', "%{$this->search}%")
                         ->orWhere('customer_email', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->methodFilter, fn($q) => $q->where('payment_method', $this->methodFilter))
            ->when($this->dateFrom, fn($q) => $q->whereDate('refunded_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('refunded_at', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        $methods = Payment::whereNotNull('refunded_at')
            ->distinct()->orderBy('payment_method')->pluck('payment_method');

        return view('livewire.admin.payments.refunds', [
            'refunds'  => $refunds,
            'summary'  => $this->summary,
            'methods'  => $methods,
        ])->layout('layouts.admin');
    }
}