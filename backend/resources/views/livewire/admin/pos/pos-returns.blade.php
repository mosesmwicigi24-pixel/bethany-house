<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-calculator"></i><span>Point of Sale</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>POS Returns</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">POS Returns</h1>
            <p class="mt-0.5 text-sm text-primary-300">Process in-store product returns and issue refunds instantly.</p>
        </div>
        @if($foundOrder || $showReceipt)
            <button wire:click="resetReturn"
                    class="inline-flex items-center gap-2 rounded-xl border border-primary-200 bg-white px-4 py-2.5 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition">
                <i class="bi bi-arrow-left"></i> Start Over
            </button>
        @endif
    </div>

    @if($showReceipt && $lastReturn)
        {{-- ── Success receipt ── --}}
        <div class="max-w-sm mx-auto">
            <div class="bg-white rounded-2xl border border-success-200 overflow-hidden shadow-sm">
                <div class="bg-success-500 px-6 py-6 text-center text-white">
                    <div class="w-14 h-14 rounded-full bg-white/10 flex items-center justify-center mx-auto mb-3">
                        <i class="bi bi-check2 text-3xl"></i>
                    </div>
                    <p class="font-bold text-lg">Return Processed</p>
                    <code class="text-success-200 text-sm">{{ $lastReturn->return_number }}</code>
                </div>
                <div class="px-6 py-5 space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-primary-400">Refund Amount</span><span class="font-bold text-success-600 text-lg tabular-nums">KES {{ number_format($lastReturn->refund_amount,2) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary-400">Refund Method</span><span class="font-semibold text-primary-600 capitalize">{{ str_replace('_',' ',$lastReturn->refund_method) }}</span></div>
                    <div class="flex justify-between"><span class="text-primary-400">Processed At</span><span class="font-medium text-primary-500">{{ $lastReturn->refunded_at->format('d M Y, H:i') }}</span></div>
                </div>
                <div class="px-6 pb-5 flex gap-2">
                    <button onclick="window.print()" class="flex-1 py-2.5 rounded-xl border border-primary-200 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-printer mr-1"></i>Print
                    </button>
                    <button wire:click="resetReturn" class="flex-1 py-2.5 rounded-xl bg-primary-500 text-sm font-semibold text-white hover:bg-primary-600 transition">
                        New Return
                    </button>
                </div>
            </div>
        </div>

    @elseif(!$foundOrder)
        {{-- ── Step 1: Find order ── --}}
        <div class="max-w-md mx-auto">
            <div class="bg-white rounded-2xl border border-primary-100 p-8 text-center shadow-sm">
                <div class="w-16 h-16 rounded-2xl bg-primary-50 border border-primary-100 flex items-center justify-center mx-auto mb-4">
                    <i class="bi bi-search text-primary-400 text-2xl"></i>
                </div>
                <h2 class="text-base font-bold text-primary-500 mb-1">Find the Order</h2>
                <p class="text-sm text-primary-300 mb-6">Enter the POS order number or customer phone number.</p>

                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input wire:model="orderSearch"
                               wire:keydown.enter="searchOrder"
                               type="text"
                               placeholder="POS-20240101-0001 or 0712…"
                               autofocus
                               class="w-full rounded-xl border border-primary-200 px-4 py-3 text-sm text-primary-600 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-400 transition" />
                    </div>
                    <button wire:click="searchOrder" wire:loading.attr="disabled"
                            class="rounded-xl bg-primary-500 hover:bg-primary-600 px-5 py-3 text-sm font-semibold text-white transition disabled:opacity-60">
                        <span wire:loading.remove wire:target="searchOrder"><i class="bi bi-search"></i></span>
                        <span wire:loading wire:target="searchOrder"><i class="bi bi-arrow-clockwise animate-spin"></i></span>
                    </button>
                </div>

                @if($searchError)
                    <div class="mt-4 flex items-center gap-2 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle text-danger-500 flex-shrink-0"></i>
                        {{ $searchError }}
                    </div>
                @endif
            </div>
        </div>

    @else
        {{-- ── Step 2: Select return items ── --}}
        <div class="max-w-2xl mx-auto space-y-5">

            {{-- Order info banner --}}
            <div class="bg-white rounded-2xl border border-primary-100 p-4 flex items-center gap-4">
                <div class="w-10 h-10 rounded-xl bg-success-50 border border-success-200 flex items-center justify-center flex-shrink-0">
                    <i class="bi bi-check-circle text-success-500"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg">{{ $foundOrder->order_number }}</code>
                        <span class="text-sm text-primary-400">{{ $foundOrder->created_at->format('d M Y, H:i') }}</span>
                    </div>
                    <p class="text-xs text-primary-400 mt-0.5">
                        {{ $foundOrder->customer_first_name ?: '(Walk-in)' }}
                        @if($foundOrder->customer_phone) · {{ $foundOrder->customer_phone }} @endif
                        · KES {{ number_format($foundOrder->total_amount,2) }}
                    </p>
                </div>
            </div>

            {{-- Item selection --}}
            <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
                <div class="px-5 py-4 border-b border-primary-100">
                    <p class="text-sm font-bold text-primary-500">Select Items to Return</p>
                    <p class="text-xs text-primary-300 mt-0.5">Enter the quantity to return for each product.</p>
                </div>
                <div class="divide-y divide-primary-50">
                    @foreach($foundOrder->items as $item)
                        <div class="flex items-center gap-4 px-5 py-4">
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-primary-600 text-sm">{{ $item->product_name }}</p>
                                @if($item->variant_name)
                                    <p class="text-xs text-primary-300">{{ $item->variant_name }}</p>
                                @endif
                                <div class="flex items-center gap-3 mt-1 text-xs text-primary-400">
                                    <span>Sold: {{ $item->quantity }}</span>
                                    <span>·</span>
                                    <span>Unit: KES {{ number_format($item->unit_price,2) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <label class="text-xs text-primary-400 font-medium">Return qty</label>
                                <div class="flex items-center rounded-xl border border-primary-200 overflow-hidden">
                                    <button wire:click="$set('returnItems.{{ $item->id }}', max(0, ($returnItems[{{ $item->id }}] ?? 0) - 1))"
                                            class="w-8 h-9 flex items-center justify-center text-primary-400 hover:bg-primary-50 hover:text-primary-600 transition">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <input type="number" min="0" max="{{ $item->quantity }}"
                                           wire:model.live="returnItems.{{ $item->id }}"
                                           class="w-12 h-9 text-center text-sm font-bold text-primary-600 bg-white border-x border-primary-200 focus:outline-none" />
                                    <button wire:click="$set('returnItems.{{ $item->id }}', min({{ $item->quantity }}, ($returnItems[{{ $item->id }}] ?? 0) + 1))"
                                            class="w-8 h-9 flex items-center justify-center text-primary-400 hover:bg-primary-50 hover:text-primary-600 transition">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                @if(($returnItems[$item->id] ?? 0) > 0)
                                    <span class="text-xs font-bold text-danger-600 tabular-nums min-w-[70px] text-right">
                                        − KES {{ number_format($item->unit_price * ($returnItems[$item->id] ?? 0), 2) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Reason & refund method --}}
            <div class="bg-white rounded-2xl border border-primary-100 p-5 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Return Reason</label>
                    <textarea wire:model="returnReason" rows="2" placeholder="Customer's reason for return…"
                              class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    @error('returnReason') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Refund Method</label>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach($refundMethods as $key => $label)
                            <button wire:click="$set('refundMethod', '{{ $key }}')"
                                    class="py-2.5 rounded-xl border text-xs font-semibold transition
                                           {{ $refundMethod === $key ? 'bg-primary-500 text-white border-primary-500 shadow-sm shadow-primary-500/20' : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Refund total + process button --}}
            <div class="bg-white rounded-2xl border border-primary-100 p-5">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-sm font-semibold text-primary-500">Total Refund Amount</span>
                    <span class="text-2xl font-bold text-danger-600 tabular-nums">KES {{ number_format($returnTotal, 2) }}</span>
                </div>
                @error('returnItems') <p class="text-xs text-danger-500 mb-3">{{ $message }}</p> @enderror
                <button wire:click="processReturn"
                        wire:loading.attr="disabled"
                        @if($returnTotal <= 0) disabled @endif
                        class="w-full py-3.5 rounded-2xl font-bold text-sm transition
                               {{ $returnTotal > 0 ? 'bg-danger-500 hover:bg-danger-600 text-white shadow-sm shadow-danger-500/20 active:scale-[0.98]' : 'bg-primary-100 text-primary-300 cursor-not-allowed' }}">
                    <span wire:loading.remove wire:target="processReturn">
                        <i class="bi bi-arrow-return-left mr-2"></i>Process Return - KES {{ number_format($returnTotal,2) }}
                    </span>
                    <span wire:loading wire:target="processReturn">Processing return…</span>
                </button>
            </div>
        </div>
    @endif

</div>