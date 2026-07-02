{{-- POS Terminal - full-width, no padding layout --}}
<div class="flex flex-col lg:flex-row h-[calc(100vh-64px)] bg-primary-50/40 overflow-hidden font-dm-sans"
     x-data="{
         barcodeBuffer: '',
         barcodeTimer: null,
         initBarcode() {
             window.addEventListener('keydown', (e) => {
                 if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                 if (e.key === 'Enter' && this.barcodeBuffer.length > 2) {
                     $wire.scanBarcode(this.barcodeBuffer);
                     this.barcodeBuffer = '';
                     return;
                 }
                 if (e.key.length === 1) {
                     this.barcodeBuffer += e.key;
                     clearTimeout(this.barcodeTimer);
                     this.barcodeTimer = setTimeout(() => this.barcodeBuffer = '', 300);
                 }
             });
         }
     }"
     x-init="initBarcode()">

    {{-- ══════════════════════════════════════════════════════
         LEFT PANEL - Product Browser
         ══════════════════════════════════════════════════════ --}}
    <div class="flex-1 flex flex-col overflow-hidden">

        {{-- Top bar --}}
        <div class="flex-shrink-0 bg-white border-b border-primary-100 px-4 py-3 flex items-center gap-3">
            <div class="flex items-center gap-2 mr-2">
                <div class="w-8 h-8 rounded-lg bg-primary-500 flex items-center justify-center">
                    <i class="bi bi-calculator text-secondary-400 text-sm"></i>
                </div>
                <span class="font-bold text-primary-600 text-sm hidden sm:block">POS Terminal</span>
            </div>

            {{-- Search --}}
            <div class="relative flex-1 max-w-sm">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
                <input wire:model.live.debounce.200ms="search"
                       type="text"
                       placeholder="Search products or scan barcode…"
                       class="w-full pl-9 pr-4 py-2 rounded-xl border border-primary-100 bg-primary-50 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 focus:bg-white transition" />
                @if($search)
                    <button wire:click="$set('search', '')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-primary-300 hover:text-primary-500">
                        <i class="bi bi-x text-sm"></i>
                    </button>
                @endif
            </div>

            {{-- Register status --}}
            <div class="ml-auto flex-shrink-0">
                @if($register?->isOpen())
                    <div class="flex items-center gap-2 rounded-xl bg-success-50 border border-success-200 px-3 py-1.5">
                        <span class="w-2 h-2 rounded-full bg-success-500 animate-pulse"></span>
                        <span class="text-xs font-semibold text-success-700">{{ $register->register_number }}</span>
                    </div>
                @else
                    <a href="/admin/pos/register"
                       class="inline-flex items-center gap-1.5 rounded-xl bg-danger-50 border border-danger-200 px-3 py-1.5 text-xs font-semibold text-danger-600 hover:bg-danger-100 transition">
                        <i class="bi bi-exclamation-circle text-xs"></i> No Register Open
                    </a>
                @endif
            </div>
        </div>

        {{-- Category tabs --}}
        <div class="flex-shrink-0 bg-white border-b border-primary-100 px-4 overflow-x-auto">
            <div class="flex items-center gap-1 py-2 min-w-max">
                <button wire:click="$set('categoryFilter', 0)"
                        class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition whitespace-nowrap
                               {{ $categoryFilter === 0 ? 'bg-primary-500 text-white' : 'text-primary-400 hover:bg-primary-50' }}">
                    All
                </button>
                @foreach($categories as $cat)
                    <button wire:click="$set('categoryFilter', {{ $cat->id }})"
                            class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition whitespace-nowrap
                                   {{ $categoryFilter === $cat->id ? 'bg-primary-500 text-white' : 'text-primary-400 hover:bg-primary-50' }}">
                        {{ $cat->translations->first()?->name ?? $cat->slug }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Product grid --}}
        <div class="flex-1 overflow-y-auto p-4">
            @if($products->isEmpty())
                <div class="flex flex-col items-center justify-center h-48 text-primary-200">
                    <i class="bi bi-search text-3xl mb-2"></i>
                    <p class="text-sm">No products found</p>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3">
                    @foreach($products as $product)
                        @php
                            $priceModel = $product->prices->first();
                            $price      = $priceModel ? $priceModel->getEffectivePrice() : 0;
                            $onSale     = $priceModel?->isOnSale();
                            $name       = $product->translations->first()?->name ?? $product->sku;
                            $img        = $product->images->first()?->image_url ?? null;
                        @endphp
                        <button wire:click="addToCart({{ $product->id }})"
                                wire:loading.class="opacity-60"
                                wire:target="addToCart({{ $product->id }})"
                                class="group relative bg-white rounded-2xl border border-primary-100 p-3 text-left hover:border-primary-300 hover:shadow-md active:scale-95 transition-all duration-150 cursor-pointer">

                            {{-- Product image / placeholder --}}
                            <div class="aspect-square rounded-xl mb-2.5 overflow-hidden bg-primary-50 flex items-center justify-center">
                                @if($img)
                                    <img src="{{ $img }}" alt="{{ $name }}" class="w-full h-full object-cover" />
                                @else
                                    <i class="bi bi-box text-primary-200 text-2xl"></i>
                                @endif
                            </div>

                            {{-- Name --}}
                            <p class="text-xs font-semibold text-primary-600 leading-tight line-clamp-2">{{ $name }}</p>

                            {{-- Price --}}
                            <div class="mt-1.5 flex items-center justify-between">
                                <span class="text-sm font-bold {{ $onSale ? 'text-danger-600' : 'text-primary-500' }}">
                                    {{ number_format($price, 2) }}
                                </span>
                                @if($onSale && $priceModel->regular_price > $price)
                                    <span class="text-[10px] text-primary-300 line-through">{{ number_format($priceModel->regular_price, 2) }}</span>
                                @endif
                            </div>

                            {{-- Add indicator --}}
                            <div class="absolute top-2 right-2 w-6 h-6 rounded-full bg-primary-500 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-sm">
                                <i class="bi bi-plus text-sm"></i>
                            </div>

                            @if($onSale)
                                <div class="absolute top-2 left-2">
                                    <span class="text-[10px] font-bold bg-danger-500 text-white px-1.5 py-0.5 rounded-full">SALE</span>
                                </div>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════
         RIGHT PANEL - Cart & Checkout
         ══════════════════════════════════════════════════════ --}}
    <div class="w-full lg:w-[380px] xl:w-[420px] flex-shrink-0 flex flex-col bg-white border-t lg:border-t-0 lg:border-l border-primary-100 h-[50vh] lg:h-full">

        {{-- Cart header --}}
        <div class="flex-shrink-0 flex items-center justify-between px-5 py-4 border-b border-primary-100">
            <div class="flex items-center gap-2">
                <i class="bi bi-cart3 text-primary-500 text-lg"></i>
                <span class="font-bold text-primary-600">Cart</span>
                @if(count($cart))
                    <span class="w-5 h-5 rounded-full bg-primary-500 text-white text-xs font-bold flex items-center justify-center">
                        {{ count($cart) }}
                    </span>
                @endif
            </div>
            @if(count($cart))
                <button wire:click="clearCart" wire:confirm="Clear the entire cart?"
                        class="text-xs text-danger-400 hover:text-danger-600 font-medium transition">
                    <i class="bi bi-trash3 mr-1"></i>Clear
                </button>
            @endif
        </div>

        {{-- Cart items --}}
        <div class="flex-1 overflow-y-auto px-4 py-3 space-y-2">
            @forelse($cart as $idx => $item)
                <div class="group flex items-start gap-3 rounded-xl bg-primary-50/60 border border-primary-100 p-3 hover:border-primary-200 transition">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-primary-600 truncate">{{ $item['name'] }}</p>
                        <p class="text-xs text-primary-300 font-mono mt-0.5">{{ $item['sku'] }}</p>
                        <div class="flex items-center gap-2 mt-2">
                            {{-- Qty controls --}}
                            <div class="flex items-center rounded-lg border border-primary-200 overflow-hidden">
                                <button wire:click="decrementQty({{ $idx }})"
                                        class="w-7 h-7 flex items-center justify-center text-primary-400 hover:bg-primary-100 hover:text-primary-600 transition text-sm">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <input type="number"
                                       value="{{ $item['qty'] }}"
                                       wire:change="setQty({{ $idx }}, $event.target.value)"
                                       class="w-10 h-7 text-center text-sm font-bold text-primary-600 bg-white border-x border-primary-200 focus:outline-none" />
                                <button wire:click="incrementQty({{ $idx }})"
                                        class="w-7 h-7 flex items-center justify-center text-primary-400 hover:bg-primary-100 hover:text-primary-600 transition text-sm">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                            <span class="text-xs text-primary-300">×</span>
                            <span class="text-xs font-medium text-primary-400">{{ number_format($item['unit_price'], 2) }}</span>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-2 flex-shrink-0">
                        <button wire:click="removeItem({{ $idx }})"
                                class="text-primary-200 hover:text-danger-500 transition opacity-0 group-hover:opacity-100">
                            <i class="bi bi-x-circle text-sm"></i>
                        </button>
                        <span class="text-sm font-bold text-primary-600 tabular-nums">
                            {{ number_format($item['subtotal'], 2) }}
                        </span>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center h-32 text-primary-200">
                    <i class="bi bi-cart text-4xl mb-2"></i>
                    <p class="text-sm">Cart is empty</p>
                    <p class="text-xs mt-1">Tap products to add them</p>
                </div>
            @endforelse
        </div>

        {{-- Customer & Discount --}}
        @if(count($cart))
            <div class="flex-shrink-0 px-4 pb-2 space-y-2 border-t border-primary-50 pt-3">
                {{-- Customer (optional) --}}
                <div x-data="{ open: false }">
                    <button @click="open = !open"
                            class="w-full flex items-center justify-between text-xs text-primary-400 hover:text-primary-600 font-medium transition py-1">
                        <span><i class="bi bi-person-circle mr-1.5"></i>
                            {{ $customerName ?: 'Add customer (optional)' }}
                        </span>
                        <i class="bi bi-chevron-down text-[10px] transition" :class="open ? 'rotate-180' : ''"></i>
                    </button>
                    <div x-show="open" x-collapse class="space-y-1.5 pt-1">
                        <input wire:model="customerName" type="text" placeholder="Name"
                               class="w-full rounded-lg border border-primary-100 px-3 py-1.5 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                        <input wire:model="customerPhone" type="tel" placeholder="Phone"
                               class="w-full rounded-lg border border-primary-100 px-3 py-1.5 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                    </div>
                </div>

                {{-- Discount --}}
                <div class="flex items-center gap-2">
                    <select wire:model="discountType"
                            class="rounded-lg border border-primary-100 px-2 py-1.5 text-xs text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 bg-white">
                        <option value="flat">KES off</option>
                        <option value="percent">% off</option>
                    </select>
                    <input wire:model="discountInput" type="number" min="0" placeholder="Discount"
                           class="flex-1 rounded-lg border border-primary-100 px-3 py-1.5 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                    <button wire:click="applyDiscount"
                            class="rounded-lg bg-secondary-100 border border-secondary-300 px-3 py-1.5 text-xs font-semibold text-secondary-700 hover:bg-secondary-200 transition">
                        Apply
                    </button>
                </div>
            </div>
        @endif

        {{-- Order totals --}}
        <div class="flex-shrink-0 px-5 py-4 border-t border-primary-100 space-y-1.5">
            <div class="flex justify-between text-sm text-primary-400">
                <span>Subtotal</span>
                <span class="tabular-nums font-medium">{{ number_format($subtotal, 2) }}</span>
            </div>
            @if($orderDiscount > 0)
                <div class="flex justify-between text-sm text-success-600">
                    <span>Discount</span>
                    <span class="tabular-nums font-medium">− {{ number_format($orderDiscount, 2) }}</span>
                </div>
            @endif
            @if($tax > 0)
                <div class="flex justify-between text-sm text-primary-400">
                    <span>Tax</span>
                    <span class="tabular-nums">{{ number_format($tax, 2) }}</span>
                </div>
            @endif
            <div class="flex justify-between items-center pt-2 border-t border-primary-100">
                <span class="font-bold text-primary-600 text-base">Total</span>
                <span class="font-bold text-primary-600 text-xl tabular-nums">
                    KES {{ number_format($total, 2) }}
                </span>
            </div>
        </div>

        {{-- Charge button --}}
        <div class="flex-shrink-0 px-4 pb-5">
            <button wire:click="openPayment"
                    @disabled(!count($cart))
                    class="w-full py-4 rounded-2xl font-bold text-base transition shadow-lg
                           {{ count($cart) ? 'bg-primary-500 hover:bg-primary-600 text-white shadow-primary-500/30 active:scale-[0.98]' : 'bg-primary-100 text-primary-300 cursor-not-allowed' }}">
                <i class="bi bi-credit-card mr-2"></i>
                Charge KES {{ number_format($total, 2) }}
            </button>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════
         PAYMENT MODAL
         ══════════════════════════════════════════════════════ --}}
    @if($showPayModal)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
             x-data x-on:keydown.escape.window="$wire.set('showPayModal', false)">
            <div class="absolute inset-0 bg-primary-900/50 backdrop-blur-sm" wire:click="$set('showPayModal', false)"></div>
            <div class="relative w-full sm:max-w-md bg-white rounded-t-3xl sm:rounded-2xl shadow-2xl shadow-primary-900/30 overflow-hidden">

                {{-- Handle --}}
                <div class="flex justify-center pt-3 pb-1 sm:hidden">
                    <div class="w-10 h-1 rounded-full bg-primary-200"></div>
                </div>

                <div class="flex items-center justify-between px-6 py-4 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Payment</h2>
                        <p class="text-2xl font-bold text-primary-600 mt-0.5 tabular-nums">
                            KES {{ number_format($total, 2) }}
                        </p>
                    </div>
                    <button wire:click="$set('showPayModal', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-5">
                    {{-- Payment method selector --}}
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-2">Payment Method</label>
                        <div class="grid grid-cols-4 gap-2">
                            @foreach(['cash' => ['bi-cash-coin','Cash'], 'card' => ['bi-credit-card','Card'], 'mpesa' => ['bi-phone','M-Pesa'], 'split' => ['bi-layers','Split']] as $method => [$icon, $label])
                                <button wire:click="$set('payMethod', '{{ $method }}')"
                                        class="flex flex-col items-center gap-1.5 rounded-xl border py-3 transition font-medium text-xs
                                               {{ $payMethod === $method
                                                  ? 'bg-primary-500 text-white border-primary-500 shadow-sm shadow-primary-500/20'
                                                  : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
                                    <i class="bi {{ $icon }} text-lg"></i>
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Method-specific fields --}}
                    @if($payMethod === 'cash')
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Cash Received</label>
                            <input wire:model.live="cashReceived" type="number" step="0.01"
                                   class="w-full rounded-xl border border-primary-200 px-4 py-3 text-lg font-bold text-primary-600 text-right tabular-nums focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-400 transition" />
                            @error('cashReceived') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                            @if((float)$cashReceived >= $total && $total > 0)
                                <div class="mt-2 flex items-center justify-between rounded-xl bg-success-50 border border-success-200 px-4 py-2.5">
                                    <span class="text-sm font-semibold text-success-700">Change Due</span>
                                    <span class="text-xl font-bold text-success-700 tabular-nums">KES {{ number_format($change, 2) }}</span>
                                </div>
                            @endif
                            {{-- Quick cash buttons --}}
                            <div class="flex flex-wrap gap-1.5 mt-2.5">
                                @foreach([500, 1000, 2000, 5000] as $amount)
                                    <button wire:click="$set('cashReceived', '{{ $amount }}')"
                                            class="px-3 py-1.5 rounded-lg border border-primary-200 text-xs font-semibold text-primary-500 hover:bg-primary-50 transition">
                                        {{ number_format($amount) }}
                                    </button>
                                @endforeach
                                <button wire:click="$set('cashReceived', '{{ number_format($total, 2, '.', '') }}')"
                                        class="px-3 py-1.5 rounded-lg border border-secondary-300 bg-secondary-50 text-xs font-semibold text-secondary-700 hover:bg-secondary-100 transition">
                                    Exact
                                </button>
                            </div>
                        </div>

                    @elseif($payMethod === 'card')
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Card Reference <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                            <input wire:model="cardRef" type="text" placeholder="Last 4 digits or ref…"
                                   class="w-full rounded-xl border border-primary-100 px-4 py-3 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>

                    @elseif($payMethod === 'mpesa')
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">M-Pesa Reference</label>
                            <input wire:model="mpesaRef" type="text" placeholder="e.g. QJK2T7Y3PL"
                                   class="w-full rounded-xl border border-primary-100 px-4 py-3 text-sm font-mono text-primary-500 uppercase focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>

                    @elseif($payMethod === 'split')
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1">Cash</label>
                                <input wire:model.live="splitCash" type="number" step="0.01" placeholder="0.00"
                                       class="w-full rounded-xl border border-primary-100 px-4 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1">Card</label>
                                <input wire:model.live="splitCard" type="number" step="0.01" placeholder="0.00"
                                       class="w-full rounded-xl border border-primary-100 px-4 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1">M-Pesa</label>
                                <input wire:model.live="splitMpesa" type="number" step="0.01" placeholder="0.00"
                                       class="w-full rounded-xl border border-primary-100 px-4 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            @php $splitTotal = (float)$splitCash + (float)$splitCard + (float)$splitMpesa; @endphp
                            <div class="flex items-center justify-between rounded-xl px-4 py-2.5 {{ $splitTotal >= $total ? 'bg-success-50 border border-success-200' : 'bg-warning-50 border border-warning-200' }}">
                                <span class="text-xs font-semibold {{ $splitTotal >= $total ? 'text-success-700' : 'text-warning-700' }}">
                                    {{ $splitTotal >= $total ? 'Covered' : 'Remaining' }}
                                </span>
                                <span class="font-bold tabular-nums {{ $splitTotal >= $total ? 'text-success-700' : 'text-warning-700' }}">
                                    KES {{ number_format(abs($total - $splitTotal), 2) }}
                                </span>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="px-6 pb-6">
                    <button wire:click="processPayment"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-70 cursor-not-allowed"
                            class="w-full py-4 rounded-2xl font-bold text-base bg-success-500 hover:bg-success-600 text-white transition shadow-lg shadow-success-500/30 active:scale-[0.98]">
                        <span wire:loading.remove wire:target="processPayment">
                            <i class="bi bi-check-circle mr-2"></i>Complete Sale
                        </span>
                        <span wire:loading wire:target="processPayment">Processing…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════════
         RECEIPT MODAL
         ══════════════════════════════════════════════════════ --}}
    @if($showReceipt && $lastOrder)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showReceipt', false)">
            <div class="absolute inset-0 bg-primary-900/50 backdrop-blur-sm"></div>
            <div class="relative w-full max-w-xs bg-white rounded-2xl shadow-2xl shadow-primary-900/30 overflow-hidden">

                {{-- Receipt header --}}
                <div class="bg-primary-500 px-6 py-5 text-center text-white">
                    <div class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center mx-auto mb-3">
                        <i class="bi bi-check2 text-2xl"></i>
                    </div>
                    <p class="font-bold text-lg">Sale Complete!</p>
                    <p class="text-primary-200 text-sm mt-0.5">{{ $lastOrder->order_number }}</p>
                </div>

                <div class="px-6 py-4 space-y-3">
                    {{-- Items --}}
                    <div class="space-y-1.5 text-sm">
                        @foreach($lastOrder->items as $item)
                            <div class="flex justify-between">
                                <span class="text-primary-500">{{ $item->product_name }} × {{ $item->quantity }}</span>
                                <span class="font-medium text-primary-600 tabular-nums">{{ number_format($item->total_price, 2) }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="border-t border-dashed border-primary-100 pt-3 space-y-1 text-sm">
                        @if($lastOrder->discount_amount > 0)
                            <div class="flex justify-between text-success-600">
                                <span>Discount</span>
                                <span>− {{ number_format($lastOrder->discount_amount, 2) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between font-bold text-primary-600 text-base">
                            <span>Total Paid</span>
                            <span class="tabular-nums">KES {{ number_format($lastOrder->total_amount, 2) }}</span>
                        </div>
                        @if((float)$cashReceived > 0 && $payMethod === 'cash')
                            <div class="flex justify-between text-success-600 font-semibold">
                                <span>Change</span>
                                <span class="tabular-nums">KES {{ number_format(max(0, (float)$cashReceived - $lastOrder->total_amount), 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <p class="text-center text-xs text-primary-300">{{ now()->format('d M Y, H:i') }}</p>
                </div>

                <div class="px-6 pb-5 flex gap-2">
                    <button onclick="window.print()"
                            class="flex-1 py-2.5 rounded-xl border border-primary-200 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-printer mr-1"></i>Print
                    </button>
                    <button wire:click="$set('showReceipt', false)"
                            class="flex-1 py-2.5 rounded-xl bg-primary-500 text-sm font-semibold text-white hover:bg-primary-600 transition">
                        New Sale
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>