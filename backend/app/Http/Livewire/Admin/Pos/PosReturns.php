<?php

namespace App\Http\Livewire\Admin\Pos;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Models\Inventory;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

class PosReturns extends Component
{
    // Step 1 – Find order
    public string  $orderSearch   = '';
    public ?Order  $foundOrder    = null;
    public string  $searchError   = '';

    // Step 2 – Select items to return
    public array   $returnItems   = []; // [order_item_id => qty_to_return]
    public string  $returnReason  = '';
    public string  $refundMethod  = 'cash';

    // Step 3 – Confirmation / receipt
    public bool    $showReceipt   = false;
    public ?OrderReturn $lastReturn = null;

    public function searchOrder(): void
    {
        $this->searchError = '';
        $this->foundOrder  = null;
        $this->returnItems = [];

        $order = Order::with(['items'])
            ->where('order_type', 'pos')
            ->where('status', 'completed')
            ->where(function($q) {
                $q->where('order_number', $this->orderSearch)
                  ->orWhere('customer_phone', $this->orderSearch);
            })
            ->latest()
            ->first();

        if (!$order) {
            $this->searchError = 'No completed POS order found for that number or phone.';
            return;
        }

        $this->foundOrder  = $order;

        // Pre-populate return qty = 0 for each item
        foreach ($order->items as $item) {
            $this->returnItems[$item->id] = 0;
        }
    }

    public function getReturnTotalProperty(): float
    {
        if (!$this->foundOrder) return 0;
        $total = 0;
        foreach ($this->foundOrder->items as $item) {
            $qty = (int) ($this->returnItems[$item->id] ?? 0);
            if ($qty > 0) {
                $total += $item->unit_price * $qty;
            }
        }
        return $total;
    }

    public function processReturn(): void
    {
        if (!$this->foundOrder) return;

        $hasItems = collect($this->returnItems)->some(fn($qty) => $qty > 0);
        if (!$hasItems) {
            $this->addError('returnItems', 'Select at least one item to return.');
            return;
        }

        $this->validate([
            'returnReason' => 'required|string|max:500',
            'refundMethod' => 'required|in:cash,card,mpesa,store_credit',
        ]);

        DB::transaction(function () {
            $orderReturn = OrderReturn::create([
                'order_id'      => $this->foundOrder->id,
                'status'        => 'completed',  // POS returns are immediate
                'return_reason' => $this->returnReason,
                'refund_amount' => $this->returnTotal,
                'refund_method' => $this->refundMethod,
                'requested_at'  => now(),
                'approved_at'   => now(),
                'received_at'   => now(),
                'refunded_at'   => now(),
                'created_by'    => auth()->id(),
                'approved_by'   => auth()->id(),
            ]);

            foreach ($this->foundOrder->items as $item) {
                $qty = (int) ($this->returnItems[$item->id] ?? 0);
                if ($qty <= 0) continue;

                ReturnItem::create([
                    'return_id'     => $orderReturn->id,
                    'order_item_id' => $item->id,
                    'quantity'      => $qty,
                    'reason'        => $this->returnReason,
                    'condition'     => 'returned',
                ]);

                // Restore inventory
                if ($this->foundOrder->outlet_id) {
                    $inv = Inventory::products()
                        ->where('outlet_id', $this->foundOrder->outlet_id)
                        ->where('product_id', $item->product_id)
                        ->where('product_variant_id', $item->product_variant_id)
                        ->first();
                    $inv?->adjustQuantity($qty, 'return', 'order_return', $orderReturn->id, auth()->id());
                }
            }

            $this->lastReturn = $orderReturn;
        });

        $this->showReceipt  = true;
        $this->foundOrder   = null;
        $this->orderSearch  = '';
        $this->returnItems  = [];
        $this->returnReason = '';
    }

    public function resetReturn(): void
    {
        $this->foundOrder  = null;
        $this->orderSearch = '';
        $this->returnItems = [];
        $this->returnReason = '';
        $this->searchError  = '';
        $this->showReceipt  = false;
        $this->lastReturn   = null;
    }

    public function render()
    {
        return view('livewire.admin.pos.pos-returns', [
            'refundMethods' => ['cash' => 'Cash', 'card' => 'Card', 'mpesa' => 'M-Pesa', 'store_credit' => 'Store Credit'],
        ])->layout('layouts.admin');
    }
}