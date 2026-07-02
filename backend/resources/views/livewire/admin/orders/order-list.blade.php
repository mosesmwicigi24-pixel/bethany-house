<div class="space-y-6">

    {{-- ── Page Header ── --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-cart-check"></i>
                <span>Orders</span>
                @if($status)
                    <i class="bi bi-chevron-right text-[10px]"></i>
                    <span>{{ ucfirst($status) }}</span>
                @endif
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">
                {{ $status ? ucfirst($status) . ' Orders' : 'All Orders' }}
            </h1>
            <p class="mt-0.5 text-sm text-primary-300">Manage and track customer orders across all channels.</p>
        </div>
    </div>

    {{-- ── Flash ── --}}
    @if (session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="flex items-center gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
            <i class="bi bi-exclamation-circle-fill text-danger-500 flex-shrink-0"></i>
            {{ session('error') }}
        </div>
    @endif

    {{-- ── Status Summary Tabs ── --}}
    <div class="grid grid-cols-6 gap-3">
        @php
            $tabs = [
                ''           => ['label' => 'All',        'count' => $summary['total'],      'color' => 'primary'],
                'pending'    => ['label' => 'Pending',    'count' => $summary['pending'],    'color' => 'warning'],
                'processing' => ['label' => 'Processing', 'count' => $summary['processing'], 'color' => 'info'],
                'shipped'    => ['label' => 'Shipped',    'count' => $summary['shipped'],    'color' => 'secondary'],
                'completed'  => ['label' => 'Completed',  'count' => $summary['completed'],  'color' => 'success'],
                'cancelled'  => ['label' => 'Cancelled',  'count' => $summary['cancelled'],  'color' => 'danger'],
            ];
            $colorMap = [
                'primary'   => ['active' => 'bg-primary-500 text-white border-primary-500',   'inactive' => 'bg-white text-primary-400 border-primary-100 hover:border-primary-300'],
                'warning'   => ['active' => 'bg-warning-500 text-white border-warning-500',   'inactive' => 'bg-white text-warning-600 border-primary-100 hover:border-warning-300'],
                'info'      => ['active' => 'bg-info-500 text-white border-info-500',         'inactive' => 'bg-white text-info-600 border-primary-100 hover:border-info-300'],
                'secondary' => ['active' => 'bg-secondary-600 text-white border-secondary-600','inactive' => 'bg-white text-secondary-700 border-primary-100 hover:border-secondary-300'],
                'success'   => ['active' => 'bg-success-500 text-white border-success-500',   'inactive' => 'bg-white text-success-700 border-primary-100 hover:border-success-300'],
                'danger'    => ['active' => 'bg-danger-500 text-white border-danger-500',     'inactive' => 'bg-white text-danger-600 border-primary-100 hover:border-danger-300'],
            ];
        @endphp
        @foreach($tabs as $val => $tab)
            @php $isActive = $status === $val; $cm = $colorMap[$tab['color']]; @endphp
            <button wire:click="$set('status', '{{ $val }}')"
                    class="rounded-xl border px-3 py-3 text-center transition cursor-pointer {{ $isActive ? $cm['active'] : $cm['inactive'] }}">
                <div class="text-xl font-bold leading-none">{{ number_format($tab['count']) }}</div>
                <div class="text-xs mt-1 font-medium opacity-90">{{ $tab['label'] }}</div>
            </button>
        @endforeach
    </div>

    {{-- ── Filters ── --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-56">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search"
                   type="text"
                   placeholder="Order #, customer name or email…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="paymentStatus"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Payments</option>
            @foreach($paymentStatuses as $ps)
                <option value="{{ $ps }}">{{ str_replace('_', ' ', ucfirst($ps)) }}</option>
            @endforeach
        </select>
        <select wire:model.live="orderType"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Types</option>
            <option value="online">Online</option>
            <option value="pos">POS</option>
        </select>
        <input wire:model.live="dateFrom" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        @if($search || $paymentStatus || $orderType || $dateFrom || $dateTo)
            <button wire:click="$set('search',''); $set('paymentStatus',''); $set('orderType',''); $set('dateFrom',''); $set('dateTo','');"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-400 hover:text-danger-600 hover:border-danger-200 transition">
                <i class="bi bi-x-circle text-xs"></i> Clear
            </button>
        @endif
    </div>

    {{-- ── Orders Table ── --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th wire:click="sort('order_number')"
                        class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Order
                            <i class="bi bi-arrow-{{ $sortBy === 'order_number' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Customer</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Type</th>
                    <th wire:click="sort('created_at')"
                        class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Date
                            <i class="bi bi-arrow-{{ $sortBy === 'created_at' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Items</th>
                    <th wire:click="sort('total_amount')"
                        class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">Total
                            <i class="bi bi-arrow-{{ $sortBy === 'total_amount' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Payment</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($orders as $order)
                    @php
                        $statusBadge = match($order->status) {
                            'pending'    => 'bg-warning-50 text-warning-700 border border-warning-200',
                            'processing' => 'bg-info-50 text-info-700 border border-info-200',
                            'shipped'    => 'bg-secondary-50 text-secondary-700 border border-secondary-200',
                            'completed'  => 'bg-success-50 text-success-700 border border-success-200',
                            'cancelled'  => 'bg-danger-50 text-danger-600 border border-danger-200',
                            default      => 'bg-primary-50 text-primary-400 border border-primary-100',
                        };
                        $payBadge = match($order->payment_status) {
                            'paid'           => 'bg-success-50 text-success-700 border border-success-200',
                            'pending'        => 'bg-warning-50 text-warning-700 border border-warning-200',
                            'partially_paid' => 'bg-info-50 text-info-700 border border-info-200',
                            'refunded'       => 'bg-primary-50 text-primary-400 border border-primary-200',
                            'failed'         => 'bg-danger-50 text-danger-600 border border-danger-200',
                            default          => 'bg-primary-50 text-primary-300 border border-primary-100',
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewOrder({{ $order->id }})"
                                    class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg hover:bg-secondary-100 transition">
                                {{ $order->order_number }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5">
                            <div class="font-medium text-primary-600">
                                {{ trim($order->customer_first_name . ' ' . $order->customer_last_name) ?: '(Guest)' }}
                            </div>
                            <div class="text-xs text-primary-300 mt-0.5">{{ $order->customer_email }}</div>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $order->order_type === 'pos' ? 'bg-primary-100 text-primary-500' : 'bg-primary-50 text-primary-400 border border-primary-100' }}">
                                <i class="bi {{ $order->order_type === 'pos' ? 'bi-shop' : 'bi-globe2' }} text-[10px]"></i>
                                {{ strtoupper($order->order_type) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">
                            {{ $order->created_at->format('d M Y') }}
                            <div class="text-[11px] text-primary-200">{{ $order->created_at->format('H:i') }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">
                                {{ $order->items->count() }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-semibold text-primary-600">
                            {{ $order->currency_code }} {{ number_format($order->total_amount, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $payBadge }}">
                                {{ str_replace('_', ' ', ucfirst($order->payment_status)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusBadge }}">
                                {{ ucfirst($order->status) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewOrder({{ $order->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition"
                                        title="View details">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                <button wire:click="openStatusModal({{ $order->id }}, '{{ $order->status }}')"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition"
                                        title="Update status">
                                    <i class="bi bi-pencil-square text-sm"></i>
                                </button>
                                @if($order->canBeCancelled())
                                    <button wire:click="cancelOrder({{ $order->id }})"
                                            wire:confirm="Cancel order {{ $order->order_number }}? This cannot be undone."
                                            class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition"
                                            title="Cancel order">
                                        <i class="bi bi-x-circle text-sm"></i>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-5 py-16 text-center">
                            <i class="bi bi-cart-x text-4xl text-primary-100 block mb-3"></i>
                            <p class="text-sm font-medium text-primary-300">No orders found.</p>
                            <p class="text-xs text-primary-200 mt-1">Try adjusting your search or filters.</p>
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

    {{-- ══════════════════════════════════════════════
         ORDER DETAIL SLIDE-OVER
         ══════════════════════════════════════════════ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex"
             x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">

            {{-- Backdrop --}}
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail', false)"></div>

            {{-- Panel --}}
            <div class="w-full max-w-2xl bg-white shadow-2xl shadow-primary-900/20 flex flex-col h-full overflow-hidden">

                {{-- Header --}}
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">
                                {{ $viewing->order_number }}
                            </code>
                            @php
                                $sb = match($viewing->status) {
                                    'pending'    => 'bg-warning-50 text-warning-700 border border-warning-200',
                                    'processing' => 'bg-info-50 text-info-700 border border-info-200',
                                    'shipped'    => 'bg-secondary-50 text-secondary-700 border border-secondary-200',
                                    'completed'  => 'bg-success-50 text-success-700 border border-success-200',
                                    'cancelled'  => 'bg-danger-50 text-danger-600 border border-danger-200',
                                    default      => 'bg-primary-50 text-primary-400 border border-primary-100',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $sb }}">
                                {{ ucfirst($viewing->status) }}
                            </span>
                        </div>
                        <p class="text-xs text-primary-300 mt-1.5">
                            {{ $viewing->created_at->format('d M Y, H:i') }} · {{ strtoupper($viewing->order_type) }}
                            @if($viewing->outlet)
                                · {{ $viewing->outlet->name }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="openStatusModal({{ $viewing->id }}, '{{ $viewing->status }}')"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-primary-100 px-3 py-1.5 text-xs font-semibold text-primary-400 hover:text-primary-600 hover:border-primary-300 transition">
                            <i class="bi bi-pencil-square text-xs"></i> Update Status
                        </button>
                        <button wire:click="$set('showDetail', false)"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                </div>

                {{-- Scrollable body --}}
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6">

                    {{-- Customer + Financials --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-primary-50/50 rounded-xl border border-primary-100 p-4 space-y-2">
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide">Customer</p>
                            <p class="font-semibold text-primary-600">
                                {{ trim($viewing->customer_first_name . ' ' . $viewing->customer_last_name) ?: '(Guest)' }}
                            </p>
                            @if($viewing->customer_email)
                                <div class="flex items-center gap-1.5 text-sm text-primary-400">
                                    <i class="bi bi-envelope text-primary-300 text-xs"></i>
                                    {{ $viewing->customer_email }}
                                </div>
                            @endif
                            @if($viewing->customer_phone)
                                <div class="flex items-center gap-1.5 text-sm text-primary-400">
                                    <i class="bi bi-telephone text-primary-300 text-xs"></i>
                                    {{ $viewing->customer_phone }}
                                </div>
                            @endif
                        </div>
                        <div class="bg-primary-50/50 rounded-xl border border-primary-100 p-4 space-y-2">
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide">Financials</p>
                            <div class="space-y-1 text-sm">
                                <div class="flex justify-between text-primary-400">
                                    <span>Subtotal</span>
                                    <span class="tabular-nums">{{ $viewing->currency_code }} {{ number_format($viewing->subtotal, 2) }}</span>
                                </div>
                                @if($viewing->discount_amount > 0)
                                    <div class="flex justify-between text-success-600">
                                        <span>Discount</span>
                                        <span class="tabular-nums">− {{ number_format($viewing->discount_amount, 2) }}</span>
                                    </div>
                                @endif
                                @if($viewing->tax_amount > 0)
                                    <div class="flex justify-between text-primary-400">
                                        <span>Tax</span>
                                        <span class="tabular-nums">{{ number_format($viewing->tax_amount, 2) }}</span>
                                    </div>
                                @endif
                                @if($viewing->shipping_amount > 0)
                                    <div class="flex justify-between text-primary-400">
                                        <span>Shipping</span>
                                        <span class="tabular-nums">{{ number_format($viewing->shipping_amount, 2) }}</span>
                                    </div>
                                @endif
                                <div class="flex justify-between font-bold text-primary-600 pt-1 border-t border-primary-100">
                                    <span>Total</span>
                                    <span class="tabular-nums">{{ $viewing->currency_code }} {{ number_format($viewing->total_amount, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Shipping address --}}
                    @if($viewing->shipping_address_line1)
                        <div class="bg-primary-50/50 rounded-xl border border-primary-100 p-4">
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2">
                                <i class="bi bi-geo-alt mr-1"></i> Shipping Address
                            </p>
                            <p class="text-sm text-primary-500">
                                {{ $viewing->shipping_address_line1 }}
                                @if($viewing->shipping_address_line2), {{ $viewing->shipping_address_line2 }}@endif<br>
                                {{ collect([$viewing->shipping_city, $viewing->shipping_state, $viewing->shipping_postal_code, $viewing->shipping_country_code])->filter()->implode(', ') }}
                            </p>
                        </div>
                    @endif

                    {{-- Order Items --}}
                    <div>
                        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">
                            <i class="bi bi-box-seam mr-1"></i> Items ({{ $viewing->items->count() }})
                        </p>
                        <div class="rounded-xl border border-primary-100 overflow-hidden">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="bg-primary-50/60 border-b border-primary-100">
                                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Qty</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Unit</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-primary-50">
                                    @foreach($viewing->items as $item)
                                        <tr>
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-primary-600">{{ $item->product_name }}</div>
                                                @if($item->variant_name)
                                                    <div class="text-xs text-primary-300 mt-0.5">{{ $item->variant_name }}</div>
                                                @endif
                                                <code class="text-[11px] text-primary-200 font-mono">{{ $item->sku }}</code>
                                            </td>
                                            <td class="px-4 py-3 text-center tabular-nums text-primary-500">{{ $item->quantity }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums text-primary-400">{{ number_format($item->unit_price, 2) }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-primary-600">{{ number_format($item->total_price, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Payments --}}
                    @if($viewing->payments->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">
                                <i class="bi bi-credit-card mr-1"></i> Payments
                            </p>
                            <div class="space-y-2">
                                @foreach($viewing->payments as $payment)
                                    <div class="flex items-center justify-between rounded-xl bg-primary-50/50 border border-primary-100 px-4 py-3">
                                        <div>
                                            <code class="font-mono text-xs text-primary-400">{{ $payment->payment_number }}</code>
                                            <p class="text-sm font-medium text-primary-500 mt-0.5">
                                                {{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}
                                                @if($payment->provider) · {{ $payment->provider }} @endif
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-primary-600 tabular-nums">{{ number_format($payment->amount, 2) }}</p>
                                            @php
                                                $pb = match($payment->status) {
                                                    'paid'    => 'bg-success-50 text-success-700 border border-success-200',
                                                    'pending' => 'bg-warning-50 text-warning-700 border border-warning-200',
                                                    'failed'  => 'bg-danger-50 text-danger-600 border border-danger-200',
                                                    default   => 'bg-primary-50 text-primary-400 border border-primary-100',
                                                };
                                            @endphp
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $pb }}">
                                                {{ ucfirst($payment->status) }}
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Shipments --}}
                    @if($viewing->shipments->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">
                                <i class="bi bi-truck mr-1"></i> Shipments
                            </p>
                            @foreach($viewing->shipments as $shipment)
                                <div class="rounded-xl border border-primary-100 p-4 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <code class="font-mono text-xs font-semibold text-primary-500">{{ $shipment->shipment_number }}</code>
                                        <span class="text-xs text-primary-400">{{ $shipment->carrier }}</span>
                                    </div>
                                    @if($shipment->tracking_number)
                                        <div class="flex items-center gap-2 text-sm">
                                            <i class="bi bi-qr-code text-primary-300 text-xs"></i>
                                            <span class="font-mono text-primary-500">{{ $shipment->tracking_number }}</span>
                                            @if($shipment->tracking_url)
                                                <a href="{{ $shipment->tracking_url }}" target="_blank"
                                                   class="ml-auto text-xs text-info-600 hover:underline">Track →</a>
                                            @endif
                                        </div>
                                    @endif
                                    @if($shipment->shipped_at)
                                        <p class="text-xs text-primary-300">
                                            Shipped: {{ $shipment->shipped_at->format('d M Y') }}
                                            @if($shipment->delivered_at) · Delivered: {{ $shipment->delivered_at->format('d M Y') }} @endif
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Status Timeline --}}
                    @if($viewing->statusHistory->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-3">
                                <i class="bi bi-clock-history mr-1"></i> Status History
                            </p>
                            <div class="relative pl-5 space-y-4">
                                <div class="absolute left-[7px] top-1 bottom-1 w-px bg-primary-100"></div>
                                @foreach($viewing->statusHistory->sortByDesc('created_at') as $history)
                                    <div class="relative flex gap-3">
                                        <div class="absolute -left-5 top-1 w-2.5 h-2.5 rounded-full border-2 border-white
                                            {{ match($history->status) {
                                                'completed'  => 'bg-success-400',
                                                'cancelled'  => 'bg-danger-400',
                                                'shipped'    => 'bg-secondary-500',
                                                'processing' => 'bg-info-400',
                                                default      => 'bg-warning-400',
                                            } }} shadow-sm"></div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-semibold text-primary-600 capitalize">{{ str_replace('_', ' ', $history->status) }}</span>
                                                @if($history->notify_customer)
                                                    <span class="text-[10px] bg-info-50 text-info-600 border border-info-200 px-1.5 py-0.5 rounded-full font-medium">Customer notified</span>
                                                @endif
                                            </div>
                                            <p class="text-xs text-primary-300 mt-0.5">
                                                {{ $history->created_at->format('d M Y, H:i') }}
                                                @if($history->createdBy) · by {{ $history->createdBy->name }} @endif
                                            </p>
                                            @if($history->notes)
                                                <p class="text-xs text-primary-400 mt-1 italic">{{ $history->notes }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($viewing->customer_notes)
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">
                                <i class="bi bi-chat-left-text mr-1"></i> Customer Notes
                            </p>
                            <p class="text-sm text-secondary-700">{{ $viewing->customer_notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════
         STATUS UPDATE MODAL
         ══════════════════════════════════════════════ --}}
    @if($showStatusModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showStatusModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showStatusModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Update Order Status</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Changes are logged and optionally emailed to the customer.</p>
                    </div>
                    <button wire:click="$set('showStatusModal', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">New Status</label>
                        <select wire:model="newStatus"
                                class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            @foreach($statuses as $s)
                                <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                        @error('newStatus') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                            Notes <span class="font-normal normal-case text-primary-200">(optional)</span>
                        </label>
                        <textarea wire:model="statusNotes" rows="3"
                                  placeholder="Add context for this status change…"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input wire:model="notifyCustomer" type="checkbox"
                               class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                        <span class="text-sm font-medium text-primary-500">Notify customer via email</span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showStatusModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Cancel
                    </button>
                    <button wire:click="updateStatus" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="updateStatus">Save Status</span>
                        <span wire:loading wire:target="updateStatus">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>