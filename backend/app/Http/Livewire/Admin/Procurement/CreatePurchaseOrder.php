<?php

namespace App\Http\Livewire\Admin\Procurement;

use App\Models\Material;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class CreatePurchaseOrder extends Component
{
    // ── Header ─────────────────────────────────────────────────────────────────
    public string $supplierId           = '';
    public string $outletId             = '';
    public string $orderDate            = '';
    public string $expectedDeliveryDate = '';
    public string $currencyCode         = 'KES';
    public string $paymentTerms         = '';
    public string $notes                = '';

    // ── Line items ─────────────────────────────────────────────────────────────
    // [item_type, product_id|null, material_id|null, description, quantity, unit_price, tax_amount]
    public array  $lineItems = [];

    // Item search
    public string $itemSearch    = '';
    public string $addItemType   = 'material'; // 'product' | 'material'

    // ── Flash ──────────────────────────────────────────────────────────────────
    public string $flashMessage = '';
    public string $flashType    = 'success';

    public function mount(): void
    {
        $this->orderDate = now()->toDateString();
        $this->expectedDeliveryDate = now()->addDays(14)->toDateString();
        $this->lineItems = [];
    }

    public function getSearchResultsProperty()
    {
        if (strlen($this->itemSearch) < 2) return collect();

        if ($this->addItemType === 'material') {
            return Material::active()
                ->where(fn($q) =>
                    $q->where('name', 'ilike', "%{$this->itemSearch}%")
                      ->orWhere('code', 'ilike', "%{$this->itemSearch}%")
                )
                ->limit(8)
                ->get();
        }

        return Product::with('translations')
            ->active()
            ->where(fn($q) =>
                $q->where('sku', 'ilike', "%{$this->itemSearch}%")
                  ->orWhereHas('translations', fn($tq) =>
                      $tq->where('name', 'ilike', "%{$this->itemSearch}%")
                  )
            )
            ->limit(8)
            ->get();
    }

    public function addMaterial(int $materialId): void
    {
        $material = Material::find($materialId);
        if (!$material) return;

        $this->lineItems[] = [
            'item_type'   => 'material',
            'product_id'  => null,
            'material_id' => $material->id,
            'description' => $material->name . ' (' . $material->unit_of_measure . ')',
            'quantity'    => 1,
            'unit_price'  => (float) $material->cost_per_unit,
            'tax_amount'  => 0,
            'tax_rate'    => 0,
        ];
        $this->itemSearch = '';
    }

    public function addProduct(int $productId): void
    {
        $product = Product::with('translations')->find($productId);
        if (!$product) return;

        $this->lineItems[] = [
            'item_type'   => 'product',
            'product_id'  => $product->id,
            'material_id' => null,
            'description' => $product->translations->first()?->name ?? $product->sku,
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_amount'  => 0,
            'tax_rate'    => 0,
        ];
        $this->itemSearch = '';
    }

    public function addBlankLine(): void
    {
        $this->lineItems[] = [
            'item_type'   => 'other',
            'product_id'  => null,
            'material_id' => null,
            'description' => '',
            'quantity'    => 1,
            'unit_price'  => 0,
            'tax_amount'  => 0,
            'tax_rate'    => 0,
        ];
    }

    public function removeLine(int $idx): void
    {
        array_splice($this->lineItems, $idx, 1);
    }

    public function updatedLineItems(): void
    {
        // Recalculate tax_amount when rate or unit_price/quantity changes
        foreach ($this->lineItems as $i => $item) {
            $base = (float)$item['unit_price'] * (float)$item['quantity'];
            $this->lineItems[$i]['tax_amount'] = round($base * ((float)($item['tax_rate'] ?? 0) / 100), 2);
        }
    }

    public function getSubtotalProperty(): float
    {
        return collect($this->lineItems)->sum(fn($i) => (float)$i['unit_price'] * (float)$i['quantity']);
    }

    public function getTaxTotalProperty(): float
    {
        return collect($this->lineItems)->sum(fn($i) => (float)$i['tax_amount']);
    }

    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->taxTotal;
    }

    public function save(string $status = 'draft'): void
    {
        $this->validate([
            'supplierId'           => 'required|exists:suppliers,id',
            'outletId'             => 'required|exists:outlets,id',
            'orderDate'            => 'required|date',
            'expectedDeliveryDate' => 'required|date|after_or_equal:orderDate',
            'currencyCode'         => 'required|string|size:3',
            'lineItems'            => 'required|array|min:1',
            'lineItems.*.description' => 'required|string',
            'lineItems.*.quantity'    => 'required|numeric|min:0.01',
            'lineItems.*.unit_price'  => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($status) {
            $po = PurchaseOrder::create([
                'supplier_id'           => $this->supplierId,
                'outlet_id'             => $this->outletId,
                'order_date'            => $this->orderDate,
                'expected_delivery_date'=> $this->expectedDeliveryDate,
                'status'                => $status,
                'currency_code'         => $this->currencyCode,
                'payment_terms'         => $this->paymentTerms ?: null,
                'subtotal'              => $this->subtotal,
                'tax_amount'            => $this->taxTotal,
                'shipping_amount'       => 0,
                'total_amount'          => $this->total,
                'payment_status'        => 'pending',
                'notes'                 => $this->notes ?: null,
                'created_by'            => auth()->id(),
            ]);

            foreach ($this->lineItems as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'item_type'         => $item['item_type'],
                    'product_id'        => $item['product_id'],
                    'material_id'       => $item['material_id'],
                    'description'       => $item['description'],
                    'quantity'          => $item['quantity'],
                    'quantity_received' => 0,
                    'unit_price'        => $item['unit_price'],
                    'tax_amount'        => $item['tax_amount'],
                    'total_price'       => ((float)$item['unit_price'] * (float)$item['quantity']) + (float)$item['tax_amount'],
                ]);
            }

            session()->flash('success', "Purchase order {$po->po_number} created.");
            $this->redirect(route('procurement.purchase-orders'), navigate: true);
        });
    }

    public function render()
    {
        return view('livewire.admin.procurement.create-purchase-order', [
            'suppliers'     => Supplier::active()->orderBy('name')->get(),
            'outlets'       => Outlet::active()->orderBy('name')->get(),
            'searchResults' => $this->searchResults,
            'subtotal'      => $this->subtotal,
            'taxTotal'      => $this->taxTotal,
            'total'         => $this->total,
        ])->layout('layouts.admin');
    }
}