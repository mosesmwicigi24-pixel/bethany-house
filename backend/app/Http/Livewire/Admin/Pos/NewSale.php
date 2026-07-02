<?php

namespace App\Http\Livewire\Admin\Pos;

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPrice;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

class NewSale extends Component
{
    // ── Register & outlet ──────────────────────────────
    public ?CashRegister $register = null;

    // ── Product browsing ───────────────────────────────
    public string $search         = '';
    public int    $categoryFilter = 0;
    public string $currency       = 'KES';

    // ── Cart ───────────────────────────────────────────
    // Each item: [product_id, variant_id|null, name, variant_name, sku, unit_price, qty, discount, subtotal]
    public array $cart            = [];

    // ── Discount on whole order ────────────────────────
    public float  $orderDiscount  = 0;   // flat amount
    public string $discountType   = 'flat'; // 'flat' | 'percent'
    public string $discountInput  = '';

    // ── Payment modal ──────────────────────────────────
    public bool   $showPayModal   = false;
    public string $payMethod      = 'cash';  // cash | card | mpesa | split
    public string $cashReceived   = '';
    public string $mpesaRef       = '';
    public string $cardRef        = '';
    // Split
    public string $splitCash      = '';
    public string $splitCard      = '';
    public string $splitMpesa     = '';

    // ── Customer ───────────────────────────────────────
    public string $customerName   = '';
    public string $customerPhone  = '';
    public string $customerEmail  = '';

    // ── Receipt modal ─────────────────────────────────
    public bool   $showReceipt    = false;
    public ?Order $lastOrder      = null;

    // ── Keypad / numpad target ────────────────────────
    public ?int   $editingCartIdx = null;

    public function mount(): void
    {
        // Resolve the open register for the current user
        $this->register = CashRegister::where('status', 'open')
            ->where('opened_by', auth()->id())
            ->latest('opened_at')
            ->first();
    }

    // ── Search & filtering ─────────────────────────────

    public function getProductsProperty()
    {
        return Product::with(['translations', 'images', 'prices' => fn($q) => $q->where('currency_code', $this->currency)])
            ->active()
            ->when($this->search, fn($q) =>
                $q->where('sku', 'ilike', "%{$this->search}%")
                  ->orWhereHas('translations', fn($tq) =>
                      $tq->where('name', 'ilike', "%{$this->search}%")
                  )
            )
            ->when($this->categoryFilter, fn($q) => $q->where('category_id', $this->categoryFilter))
            ->orderBy('sort_order')
            ->limit(48)
            ->get();
    }

    public function getCategoriesProperty()
    {
        return Category::with('translations')
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();
    }

    // ── Cart operations ────────────────────────────────

    public function addToCart(int $productId, ?int $variantId = null): void
    {
        $product = Product::with([
            'translations',
            'prices' => fn($q) => $q->where('currency_code', $this->currency)
                                    ->when($variantId, fn($pq) => $pq->where('product_variant_id', $variantId))
        ])->find($productId);

        if (!$product) return;

        $priceModel = $product->getPriceForCurrency($this->currency, $variantId);
        $unitPrice  = $priceModel ? $priceModel->getEffectivePrice() : 0;
        $name       = $product->translations->first()?->name ?? $product->sku;
        $sku        = $product->sku;

        // Increment if already in cart
        foreach ($this->cart as $idx => $item) {
            if ($item['product_id'] === $productId && $item['variant_id'] === $variantId) {
                $this->cart[$idx]['qty']++;
                $this->recalcItem($idx);
                return;
            }
        }

        $this->cart[] = [
            'product_id'   => $productId,
            'variant_id'   => $variantId,
            'name'         => $name,
            'variant_name' => $variantId ? '' : '',
            'sku'          => $sku,
            'unit_price'   => (float) $unitPrice,
            'qty'          => 1,
            'discount'     => 0,
            'subtotal'     => (float) $unitPrice,
        ];
    }

    public function setQty(int $idx, int $qty): void
    {
        if ($qty <= 0) {
            $this->removeItem($idx);
            return;
        }
        $this->cart[$idx]['qty'] = $qty;
        $this->recalcItem($idx);
    }

    public function incrementQty(int $idx): void
    {
        $this->cart[$idx]['qty']++;
        $this->recalcItem($idx);
    }

    public function decrementQty(int $idx): void
    {
        if ($this->cart[$idx]['qty'] <= 1) {
            $this->removeItem($idx);
            return;
        }
        $this->cart[$idx]['qty']--;
        $this->recalcItem($idx);
    }

    public function setItemDiscount(int $idx, string $value): void
    {
        $this->cart[$idx]['discount'] = max(0, (float) $value);
        $this->recalcItem($idx);
    }

    public function removeItem(int $idx): void
    {
        array_splice($this->cart, $idx, 1);
    }

    public function clearCart(): void
    {
        $this->cart          = [];
        $this->orderDiscount = 0;
        $this->discountInput = '';
        $this->customerName  = '';
        $this->customerPhone = '';
        $this->customerEmail = '';
    }

    protected function recalcItem(int $idx): void
    {
        $item = $this->cart[$idx];
        $base = $item['unit_price'] * $item['qty'];
        $this->cart[$idx]['subtotal'] = max(0, $base - $item['discount']);
    }

    // ── Order-level discount ───────────────────────────

    public function applyDiscount(): void
    {
        $val = (float) $this->discountInput;
        if ($this->discountType === 'percent') {
            $this->orderDiscount = ($this->getSubtotalProperty() * $val) / 100;
        } else {
            $this->orderDiscount = $val;
        }
        $this->orderDiscount = min($this->orderDiscount, $this->getSubtotalProperty());
    }

    // ── Computed totals ────────────────────────────────

    public function getSubtotalProperty(): float
    {
        return collect($this->cart)->sum('subtotal');
    }

    public function getTaxProperty(): float
    {
        // 16% VAT example - hook into your TaxRate model if needed
        return 0;
    }

    public function getTotalProperty(): float
    {
        return max(0, $this->subtotal - $this->orderDiscount + $this->tax);
    }

    public function getChangeProperty(): float
    {
        if ($this->payMethod === 'cash') {
            return max(0, (float) $this->cashReceived - $this->total);
        }
        if ($this->payMethod === 'split') {
            $paid = (float) $this->splitCash + (float) $this->splitCard + (float) $this->splitMpesa;
            return max(0, $paid - $this->total);
        }
        return 0;
    }

    // ── Payment ────────────────────────────────────────

    public function openPayment(): void
    {
        if (empty($this->cart)) return;
        $this->cashReceived = number_format($this->total, 2, '.', '');
        $this->showPayModal = true;
    }

    public function processPayment(): void
    {
        if (empty($this->cart)) return;

        // Validate cash received covers total
        if ($this->payMethod === 'cash' && (float) $this->cashReceived < $this->total) {
            $this->addError('cashReceived', 'Cash received is less than the total amount.');
            return;
        }

        DB::transaction(function () {
            // Build order
            $order = Order::create([
                'order_number'       => 'POS-' . date('Ymd') . '-' . str_pad(Order::whereDate('created_at', today())->count() + 1, 4, '0', STR_PAD_LEFT),
                'order_type'         => 'pos',
                'status'             => 'completed',
                'currency_code'      => $this->currency,
                'outlet_id'          => $this->register?->outlet_id,
                'user_id'            => auth()->id(),
                'customer_first_name'=> $this->customerName ?: null,
                'customer_phone'     => $this->customerPhone ?: null,
                'customer_email'     => $this->customerEmail ?: null,
                'subtotal'           => $this->subtotal,
                'discount_amount'    => $this->orderDiscount,
                'tax_amount'         => $this->tax,
                'shipping_amount'    => 0,
                'total_amount'       => $this->total,
                'payment_method'     => $this->payMethod,
                'payment_status'     => 'paid',
                'completed_at'       => now(),
            ]);

            // Order items
            foreach ($this->cart as $item) {
                OrderItem::create([
                    'order_id'           => $order->id,
                    'product_id'         => $item['product_id'],
                    'product_variant_id' => $item['variant_id'],
                    'product_name'       => $item['name'],
                    'variant_name'       => $item['variant_name'],
                    'sku'                => $item['sku'],
                    'quantity'           => $item['qty'],
                    'unit_price'         => $item['unit_price'],
                    'discount_amount'    => $item['discount'],
                    'tax_amount'         => 0,
                    'total_price'        => $item['subtotal'],
                ]);

                // Deduct inventory
                if ($this->register?->outlet_id) {
                    $inv = Inventory::products()
                        ->where('outlet_id', $this->register->outlet_id)
                        ->where('product_id', $item['product_id'])
                        ->where('product_variant_id', $item['variant_id'])
                        ->first();
                    $inv?->adjustQuantity(-$item['qty'], 'sale', 'order', $order->id, auth()->id());
                }
            }

            // Payments record
            Payment::create([
                'order_id'       => $order->id,
                'payment_number' => 'PAY-' . strtoupper(uniqid()),
                'payment_method' => $this->payMethod,
                'amount'         => $this->total,
                'currency_code'  => $this->currency,
                'status'         => 'paid',
                'paid_at'        => now(),
                'phone_number'   => $this->mpesaRef ?: null,
                'provider_reference' => $this->cardRef ?: $this->mpesaRef ?: null,
            ]);

            // Cash register record
            if ($this->register?->isOpen()) {
                $this->register->recordSale($this->total, $this->payMethod, $order->id);
            }

            // Status history
            OrderStatusHistory::create([
                'order_id'   => $order->id,
                'status'     => 'completed',
                'notes'      => 'POS sale completed.',
                'created_by' => auth()->id(),
            ]);

            $this->lastOrder = $order->load('items');
        });

        $this->showPayModal  = false;
        $this->showReceipt   = true;
        $this->clearCart();
    }

    // ── Barcode / SKU scan ────────────────────────────

    public function scanBarcode(string $code): void
    {
        $product = Product::with(['translations', 'prices' => fn($q) => $q->where('currency_code', $this->currency)])
            ->where('sku', $code)
            ->first();

        if ($product) {
            $this->addToCart($product->id);
            $this->search = '';
        }
    }

    public function render()
    {
        return view('livewire.admin.pos.new-sale', [
            'products'   => $this->products,
            'categories' => $this->categories,
            'subtotal'   => $this->subtotal,
            'tax'        => $this->tax,
            'total'      => $this->total,
            'change'     => $this->change,
        ])->layout('layouts.admin', ['fullWidth' => true]);
    }
}