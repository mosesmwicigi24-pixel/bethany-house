<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-calculator"></i>
                <span>Point of Sale</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Sales History</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Sales History</h1>
            <p class="mt-0.5 text-sm text-primary-300">All POS transactions with filters and receipt reprints.</p>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @php
            $cards = [
                ['label'=>'Transactions', 'value'=> number_format($summary['total_transactions']), 'icon'=>'bi-receipt', 'color'=>'primary', 'prefix'=>''],
                ['label'=>'Total Revenue', 'value'=> number_format($summary['total_revenue'],2), 'icon'=>'bi-graph-up-arrow', 'color'=>'success', 'prefix'=>'KES '],
                ['label'=>'Cash Sales',   'value'=> number_format($summary['cash_total'],2),    'icon'=>'bi-cash-coin', 'color'=>'warning', 'prefix'=>'KES '],
                ['label'=>'M-Pesa',       'value'=> number_format($summary['mpesa_total'],2),   'icon'=>'bi-phone',     'color'=>'info',    'prefix'=>'KES '],
            ];
            $cc = ['primary'=>['border'=>'border-primary-100','ibg'=>'bg-primary-50','ic'=>'text-primary-400','vc'=>'text-primary-600'],
                   'success'=>['border'=>'border-success-200','ibg'=>'bg-success-50','ic'=>'text-success-500','vc'=>'text-success-700'],
                   'warning'=>['border'=>'border-warning-200','ibg'=>'bg-warning-50','ic'=>'text-warning-500','vc'=>'text-warning-700'],
                   'info'   =>['border'=>'border-info-200',   'ibg'=>'bg-info-50',   'ic'=>'text-info-500',  'vc'=>'text-info-700']];
        @endphp
        @foreach($cards as $card)
            @php $c = $cc[$card['color']]; @endphp
            <div class="relative overflow-hidden bg-white rounded-2xl border {{ $c['border'] }} p-4 flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl {{ $c['ibg'] }} border {{ $c['border'] }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $card['icon'] }} {{ $c['ic'] }} text-lg"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $card['label'] }}</p>
                    <p class="text-lg font-bold {{ $c['vc'] }} mt-0.5 tabular-nums truncate">{{ $card['prefix'] }}{{ $card['value'] }}</p>
                </div>
                <div class="absolute -right-2 -bottom-2 w-14 h-14 rounded-full {{ $c['ibg'] }} opacity-50"></div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text"
                   placeholder="Order #, phone or email…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <input wire:model.live="dateFrom" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <select wire:model.live="payMethod"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Methods</option>
            @foreach($payMethods as $m)
                <option value="{{ $m }}">{{ ucfirst($m) }}</option>
            @endforeach
        </select>
        <select wire:model.live="outletFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Outlets</option>
            @foreach($outlets as $outlet)
                <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Order #</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Customer</th>
                    <th wire:click="sort('created_at')"
                        class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Time
                            <i class="bi bi-arrow-{{ $sortBy === 'created_at' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Items</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Payment</th>
                    <th wire:click="sort('total_amount')"
                        class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">Total
                            <i class="bi bi-arrow-{{ $sortBy === 'total_amount' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($orders as $order)
                    @php
                        $pmIcon = match($order->payment_method) {
                            'cash'  => ['bi-cash-coin','bg-warning-50 text-warning-700 border-warning-200'],
                            'card'  => ['bi-credit-card','bg-info-50 text-info-700 border-info-200'],
                            'mpesa' => ['bi-phone','bg-success-50 text-success-700 border-success-200'],
                            default => ['bi-layers','bg-primary-50 text-primary-400 border-primary-100'],
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <code class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg">
                                {{ $order->order_number }}
                            </code>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-500">
                            {{ $order->customer_first_name ?: '(Walk-in)' }}
                            @if($order->customer_phone)
                                <div class="text-xs text-primary-300">{{ $order->customer_phone }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">
                            {{ $order->created_at->format('d M, H:i') }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">
                                {{ $order->items->count() }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium border {{ $pmIcon[1] }}">
                                <i class="bi {{ $pmIcon[0] }} text-[11px]"></i>
                                {{ ucfirst($order->payment_method) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-bold text-primary-600">
                            {{ number_format($order->total_amount, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewOrder({{ $order->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition"
                                        title="View details">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                <button wire:click="printReceipt({{ $order->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition"
                                        title="Print receipt">
                                    <i class="bi bi-printer text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-16 text-center">
                            <i class="bi bi-receipt text-4xl text-primary-100 block mb-3"></i>
                            <p class="text-sm font-medium text-primary-300">No transactions found.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        @if($orders->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">
                {{ $orders->links() }}
            </div>
        @endif
    </div>

    {{-- Detail slide-over --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex"
             x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail', false)"></div>
            <div class="w-full max-w-md bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">{{ $viewing->order_number }}</code>
                        <p class="text-xs text-primary-300 mt-1.5">{{ $viewing->created_at->format('d M Y, H:i') }}</p>
                    </div>
                    <button wire:click="$set('showDetail', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-primary-50/60 rounded-xl border border-primary-100 px-3.5 py-3">
                            <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">Customer</p>
                            <p class="text-sm font-medium text-primary-600 mt-0.5">{{ $viewing->customer_first_name ?: '(Walk-in)' }}</p>
                            @if($viewing->customer_phone)<p class="text-xs text-primary-400">{{ $viewing->customer_phone }}</p>@endif
                        </div>
                        <div class="bg-primary-50/60 rounded-xl border border-primary-100 px-3.5 py-3">
                            <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">Payment</p>
                            <p class="text-sm font-semibold text-primary-600 mt-0.5 capitalize">{{ $viewing->payment_method }}</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-primary-100 overflow-hidden">
                        <table class="min-w-full text-sm">
                            <thead class="bg-primary-50/60 border-b border-primary-100">
                                <tr>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase">Product</th>
                                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-primary-300 uppercase">Qty</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-primary-50">
                                @foreach($viewing->items as $item)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-primary-600">{{ $item->product_name }}</td>
                                        <td class="px-4 py-3 text-center text-primary-500">{{ $item->quantity }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-primary-600">{{ number_format($item->total_price, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="space-y-1.5 text-sm">
                        @if($viewing->discount_amount > 0)
                            <div class="flex justify-between text-success-600"><span>Discount</span><span>− {{ number_format($viewing->discount_amount,2) }}</span></div>
                        @endif
                        <div class="flex justify-between font-bold text-primary-600 text-base pt-1 border-t border-primary-100">
                            <span>Total</span>
                            <span class="tabular-nums">KES {{ number_format($viewing->total_amount, 2) }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 px-6 py-4 border-t border-primary-100">
                    <button wire:click="printReceipt({{ $viewing->id }})"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border border-primary-200 bg-white px-4 py-2.5 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-printer"></i> Print Receipt
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Receipt print modal --}}
    @if($showReceipt && $receiptOrder)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showReceipt', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showReceipt', false)"></div>
            <div class="relative w-full max-w-xs bg-white rounded-2xl shadow-2xl overflow-hidden print:shadow-none">
                <div class="bg-primary-500 px-6 py-4 text-center text-white">
                    <p class="font-bold">{{ $receiptOrder->outlet?->name ?? 'POS Sale' }}</p>
                    <code class="text-primary-200 text-xs">{{ $receiptOrder->order_number }}</code>
                </div>
                <div class="px-6 py-4 space-y-2 text-sm">
                    @foreach($receiptOrder->items as $item)
                        <div class="flex justify-between"><span>{{ $item->product_name }} ×{{ $item->quantity }}</span><span class="tabular-nums">{{ number_format($item->total_price,2) }}</span></div>
                    @endforeach
                    <div class="border-t border-dashed border-primary-100 pt-2 flex justify-between font-bold text-primary-600">
                        <span>Total</span><span class="tabular-nums">KES {{ number_format($receiptOrder->total_amount,2) }}</span>
                    </div>
                    <p class="text-center text-xs text-primary-300 pt-2">{{ $receiptOrder->created_at->format('d M Y, H:i') }}</p>
                </div>
                <div class="px-6 pb-5 flex gap-2">
                    <button onclick="window.print()" class="flex-1 py-2.5 rounded-xl border border-primary-200 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-printer mr-1"></i>Print
                    </button>
                    <button wire:click="$set('showReceipt', false)" class="flex-1 py-2.5 rounded-xl bg-primary-500 text-sm font-semibold text-white hover:bg-primary-600 transition">Close</button>
                </div>
            </div>
        </div>
    @endif

</div>