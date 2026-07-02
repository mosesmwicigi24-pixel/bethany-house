<div class="space-y-6">

    {{-- ── Page Header ── --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-cart-check"></i>
                <span>Orders</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Abandoned Carts</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Abandoned Carts</h1>
            <p class="mt-0.5 text-sm text-primary-300">Recover lost revenue by re-engaging customers who left without purchasing.</p>
        </div>
    </div>

    {{-- ── Flash ── --}}
    @if (session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>
            {{ session('success') }}
        </div>
    @endif

    {{-- ── Summary Cards ── --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="relative overflow-hidden bg-white rounded-2xl border border-danger-200 p-5">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-danger-50 border border-danger-200 flex items-center justify-center flex-shrink-0">
                    <i class="bi bi-cart-x text-danger-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-danger-400 uppercase tracking-wide">Abandoned Carts</p>
                    <p class="text-2xl font-bold text-danger-700 mt-0.5">{{ number_format($summary['total_carts']) }}</p>
                </div>
            </div>
            <div class="absolute -right-3 -bottom-3 w-16 h-16 rounded-full bg-danger-50 opacity-50"></div>
        </div>

        <div class="relative overflow-hidden bg-white rounded-2xl border border-warning-200 p-5">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-warning-50 border border-warning-200 flex items-center justify-center flex-shrink-0">
                    <i class="bi bi-currency-exchange text-warning-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-warning-500 uppercase tracking-wide">Lost Revenue</p>
                    <p class="text-2xl font-bold text-warning-700 mt-0.5 tabular-nums">{{ number_format($summary['total_value'], 2) }}</p>
                </div>
            </div>
            <div class="absolute -right-3 -bottom-3 w-16 h-16 rounded-full bg-warning-50 opacity-50"></div>
        </div>

        <div class="relative overflow-hidden bg-white rounded-2xl border border-info-200 p-5">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-info-50 border border-info-200 flex items-center justify-center flex-shrink-0">
                    <i class="bi bi-bar-chart text-info-500 text-xl"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-info-500 uppercase tracking-wide">Avg Cart Value</p>
                    <p class="text-2xl font-bold text-info-700 mt-0.5 tabular-nums">{{ number_format($summary['avg_value'], 2) }}</p>
                </div>
            </div>
            <div class="absolute -right-3 -bottom-3 w-16 h-16 rounded-full bg-info-50 opacity-50"></div>
        </div>
    </div>

    {{-- ── Filters ── --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-56">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text"
                   placeholder="Customer name or email…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <input wire:model.live="dateFrom" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <div class="flex items-center gap-2 rounded-xl border border-primary-100 bg-white px-3.5 py-2.5">
            <label class="text-xs font-semibold text-primary-300 uppercase tracking-wide whitespace-nowrap">Min value</label>
            <input wire:model.live.debounce.500ms="minValue" type="number" min="0" placeholder="0"
                   class="w-20 text-sm text-primary-500 focus:outline-none bg-transparent" />
        </div>
    </div>

    {{-- ── Carts Table ── --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Customer</th>
                    <th wire:click="sort('abandoned_at')"
                        class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">
                            Abandoned
                            <i class="bi bi-arrow-{{ $sortBy === 'abandoned_at' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Items</th>
                    <th wire:click="sort('total_amount')"
                        class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">
                            Value
                            <i class="bi bi-arrow-{{ $sortBy === 'total_amount' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Currency</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($carts as $cart)
                    @php
                        $owner     = $cart->user ?? $cart->customer;
                        $name      = $owner ? ($owner->name ?? ($owner->first_name . ' ' . $owner->last_name)) : null;
                        $email     = $owner?->email ?? '(Guest)';
                        $itemCount = $cart->items->count();
                        $isHigh    = $cart->total_amount >= 5000;
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center flex-shrink-0">
                                    <i class="bi bi-person text-primary-400 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-primary-600">{{ $name ?? '(Guest)' }}</p>
                                    <p class="text-xs text-primary-300">{{ $email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">
                            @if($cart->abandoned_at)
                                {{ $cart->abandoned_at->format('d M Y') }}
                                <div class="text-[11px] text-primary-200">{{ $cart->abandoned_at->diffForHumans() }}</div>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">
                                {{ $itemCount }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-bold {{ $isHigh ? 'text-danger-600' : 'text-primary-600' }}">
                            {{ number_format($cart->total_amount, 2) }}
                            @if($isHigh)
                                <i class="bi bi-exclamation-circle text-danger-400 text-xs ml-1" title="High-value cart"></i>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-xs font-mono text-primary-400 uppercase">{{ $cart->currency_code }}</td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewCart({{ $cart->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition"
                                        title="View items">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                @if($owner && $owner->email)
                                    <button wire:click="sendRecoveryEmail({{ $cart->id }})"
                                            class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition"
                                            title="Send recovery email">
                                        <i class="bi bi-envelope text-sm"></i>
                                    </button>
                                @endif
                                <button wire:click="deleteCart({{ $cart->id }})"
                                        wire:confirm="Delete this abandoned cart?"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition"
                                        title="Delete cart">
                                    <i class="bi bi-trash3 text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-16 text-center">
                            <i class="bi bi-cart-check text-4xl text-success-200 block mb-3"></i>
                            <p class="text-sm font-semibold text-primary-400">No abandoned carts found.</p>
                            <p class="text-xs text-primary-200 mt-1">Great - customers are completing their purchases!</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($carts->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">
                {{ $carts->links() }}
            </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════
         CART DETAIL SLIDE-OVER
         ══════════════════════════════════════════════ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex"
             x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail', false)"></div>
            <div class="w-full max-w-md bg-white shadow-2xl shadow-primary-900/20 flex flex-col h-full overflow-hidden">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Cart Details</h2>
                        @php
                            $vo = $viewing->user ?? $viewing->customer;
                            $vn = $vo ? ($vo->name ?? ($vo->first_name . ' ' . $vo->last_name)) : null;
                        @endphp
                        <p class="text-xs text-primary-300 mt-0.5">{{ $vn ?? '(Guest)' }} · {{ $vo?->email }}</p>
                    </div>
                    <button wire:click="$set('showDetail', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-4">

                    {{-- Meta --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-primary-50/60 rounded-xl border border-primary-100 px-3.5 py-3">
                            <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">Abandoned</p>
                            <p class="text-sm font-semibold text-primary-600 mt-0.5">
                                {{ $viewing->abandoned_at?->format('d M Y, H:i') ?? '-' }}
                            </p>
                        </div>
                        <div class="bg-primary-50/60 rounded-xl border border-primary-100 px-3.5 py-3">
                            <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">Total Value</p>
                            <p class="text-sm font-bold text-danger-600 mt-0.5 tabular-nums">
                                {{ $viewing->currency_code }} {{ number_format($viewing->total_amount, 2) }}
                            </p>
                        </div>
                    </div>

                    {{-- Items --}}
                    <div>
                        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">
                            Items ({{ $viewing->items->count() }})
                        </p>
                        <div class="space-y-2">
                            @foreach($viewing->items as $item)
                                <div class="flex items-center gap-3 rounded-xl bg-primary-50/50 border border-primary-100 p-3">
                                    <div class="w-10 h-10 rounded-lg bg-primary-100 flex items-center justify-center flex-shrink-0">
                                        <i class="bi bi-box text-primary-300 text-sm"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-medium text-primary-600 text-sm truncate">
                                            {{ $item->product?->translations->first()?->name ?? '-' }}
                                        </p>
                                        @if($item->variant)
                                            <p class="text-xs text-primary-300">{{ $item->variant->name }}</p>
                                        @endif
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-sm font-semibold text-primary-600 tabular-nums">× {{ $item->quantity }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if($viewing->coupon_code)
                        <div class="flex items-center gap-2 rounded-xl bg-secondary-50 border border-secondary-200 px-4 py-3">
                            <i class="bi bi-tag text-secondary-500 text-sm"></i>
                            <span class="text-sm font-semibold text-secondary-700">Coupon: <code>{{ $viewing->coupon_code }}</code></span>
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    @if($vo && $vo->email)
                        <button wire:click="sendRecoveryEmail({{ $viewing->id }})"
                                class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border border-info-200 bg-info-50 px-4 py-2.5 text-sm font-semibold text-info-700 hover:bg-info-100 transition">
                            <i class="bi bi-envelope"></i> Send Recovery Email
                        </button>
                    @endif
                    <button wire:click="deleteCart({{ $viewing->id }})"
                            wire:confirm="Delete this cart?"
                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-danger-200 bg-danger-50 px-4 py-2.5 text-sm font-semibold text-danger-600 hover:bg-danger-100 transition">
                        <i class="bi bi-trash3"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>