<?php

namespace App\Http\Livewire\Admin\Procurement;

use App\Models\GoodsReceivedNote;
use App\Models\GrnItem;
use App\Models\Inventory;
use App\Models\MaterialInventory;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class GoodsReceipt extends Component
{
    use WithPagination;

    // ── List filters ───────────────────────────────────────────────────────────
    public string $search       = '';
    public string $dateFrom     = '';
    public string $dateTo       = '';
    public string $sortBy       = 'created_at';
    public string $sortDir      = 'desc';

    // ── Receive modal ──────────────────────────────────────────────────────────
    public bool           $showReceiveModal = false;
    public ?PurchaseOrder $receivingPo      = null;
    public string         $receivedDate     = '';
    public string         $invoiceNumber    = '';
    public string         $receiveOutletId  = '';
    public string         $receiveNotes     = '';
    // [po_item_id => [qty_received, qty_rejected, condition, notes]]
    public array          $receiptLines     = [];

    // ── GRN detail slide-over ──────────────────────────────────────────────────
    public bool              $showDetail = false;
    public ?GoodsReceivedNote $viewing   = null;

    // ── PO lookup ─────────────────────────────────────────────────────────────
    public string $poSearch   = '';
    public string $poError    = '';

    public function updatingSearch(): void { $this->resetPage(); }

    public function searchPo(): void
    {
        $this->poError    = '';
        $this->receivingPo = null;
        $this->receiptLines = [];

        $po = PurchaseOrder::with(['items.product.translations', 'items.material', 'supplier'])
            ->where('po_number', $this->poSearch)
            ->whereIn('status', ['approved', 'ordered', 'partially_received'])
            ->first();

        if (!$po) {
            $this->poError = 'No open purchase order found with that number.';
            return;
        }

        $this->receivingPo  = $po;
        $this->receiveOutletId = (string) ($po->outlet_id ?? '');
        $this->receivedDate = now()->toDateString();

        // Pre-fill lines with remaining qty
        foreach ($po->items as $item) {
            $remaining = max(0, $item->quantity - $item->quantity_received);
            $this->receiptLines[$item->id] = [
                'qty_received' => $remaining,
                'qty_rejected' => 0,
                'condition'    => 'good',
                'notes'        => '',
            ];
        }

        $this->showReceiveModal = true;
    }

    public function saveReceipt(): void
    {
        if (!$this->receivingPo) return;

        $this->validate([
            'receivedDate'    => 'required|date',
            'receiveOutletId' => 'required|exists:outlets,id',
        ]);

        DB::transaction(function () {
            // Create GRN header
            $grn = GoodsReceivedNote::create([
                'purchase_order_id' => $this->receivingPo->id,
                'outlet_id'         => $this->receiveOutletId,
                'received_date'     => $this->receivedDate,
                'invoice_number'    => $this->invoiceNumber ?: null,
                'notes'             => $this->receiveNotes ?: null,
                'received_by'       => auth()->id(),
            ]);

            foreach ($this->receivingPo->items as $item) {
                $line = $this->receiptLines[$item->id] ?? null;
                if (!$line || (float)$line['qty_received'] <= 0) continue;

                $qtyReceived = (float) $line['qty_received'];
                $qtyRejected = (float) ($line['qty_rejected'] ?? 0);

                // GRN item record
                GrnItem::create([
                    'grn_id'            => $grn->id,
                    'po_item_id'        => $item->id,
                    'quantity_received' => $qtyReceived,
                    'quantity_rejected' => $qtyRejected,
                    'condition'         => $line['condition'] ?? 'good',
                    'notes'             => $line['notes'] ?: null,
                ]);

                // Update PO item quantity_received
                $item->increment('quantity_received', $qtyReceived - $qtyRejected);

                $accepted = $qtyReceived - $qtyRejected;
                if ($accepted <= 0) continue;

                // Update inventory
                if ($item->item_type === 'material' && $item->material_id) {
                    $stock = MaterialInventory::firstOrCreate(
                        ['material_id' => $item->material_id, 'outlet_id' => $this->receiveOutletId],
                        ['quantity_on_hand' => 0]
                    );
                    $stock->increment('quantity_on_hand', $accepted);

                } elseif ($item->item_type === 'product' && $item->product_id) {
                    $inv = Inventory::products()
                        ->where('outlet_id', $this->receiveOutletId)
                        ->where('product_id', $item->product_id)
                        ->where('product_variant_id', $item->product_variant_id)
                        ->first();
                    $inv?->adjustQuantity($accepted, 'purchase', 'grn', $grn->id, auth()->id());
                }
            }

            // Update PO status
            $this->receivingPo->refresh();
            $newStatus = $this->receivingPo->isFullyReceived() ? 'completed' : 'partially_received';
            $this->receivingPo->update(['status' => $newStatus]);

            session()->flash('success', "GRN {$grn->grn_number} created and inventory updated.");
        });

        $this->showReceiveModal = false;
        $this->reset(['receivingPo', 'poSearch', 'receiptLines', 'invoiceNumber', 'receiveNotes']);
    }

    public function viewGrn(int $id): void
    {
        $this->viewing = GoodsReceivedNote::with([
            'purchaseOrder.supplier',
            'outlet',
            'items.purchaseOrderItem.product.translations',
            'items.purchaseOrderItem.material',
            'receivedBy',
        ])->find($id);
        $this->showDetail = true;
    }

    public function render()
    {
        $grns = GoodsReceivedNote::with(['purchaseOrder.supplier', 'outlet', 'receivedBy'])
            ->withCount('items')
            ->when($this->search, fn($q) =>
                $q->where('grn_number', 'ilike', "%{$this->search}%")
                  ->orWhereHas('purchaseOrder', fn($pq) =>
                      $pq->where('po_number', 'ilike', "%{$this->search}%")
                  )
                  ->orWhereHas('purchaseOrder.supplier', fn($sq) =>
                      $sq->where('name', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->dateFrom, fn($q) => $q->whereDate('received_date', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('received_date', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);

        return view('livewire.admin.procurement.goods-receipt', [
            'grns'    => $grns,
            'outlets' => Outlet::active()->orderBy('name')->get(),
        ])->layout('layouts.admin');
    }
}