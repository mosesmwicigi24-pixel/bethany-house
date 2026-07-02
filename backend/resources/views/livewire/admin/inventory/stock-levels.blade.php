<div class="space-y-6">

    {{-- Page Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-boxes"></i>
                <span>Inventory</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Stock Levels</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Stock Levels</h1>
            <p class="mt-0.5 text-sm text-primary-300">Real-time inventory quantities across all outlets.</p>
        </div>
    </div>

    {{-- Flash --}}
    @if (session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>
            {{ session('success') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-56">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search"
                   type="text"
                   placeholder="Search product or SKU…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>

        <select wire:model.live="outletFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Outlets</option>
            @foreach($outlets as $outlet)
                <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="statusFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}">{{ str_replace('_', ' ', ucwords($status, '_')) }}</option>
            @endforeach
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th wire:click="sort('product_id')"
                        class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition group">
                        <span class="flex items-center gap-1.5">
                            Product
                            <i class="bi bi-arrow-{{ $sortBy === 'product_id' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200 group-hover:text-secondary-500 transition"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">SKU</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Outlet</th>
                    <th wire:click="sort('quantity_on_hand')"
                        class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition group">
                        <span class="flex items-center justify-end gap-1.5">
                            On Hand
                            <i class="bi bi-arrow-{{ $sortBy === 'quantity_on_hand' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200 group-hover:text-secondary-500 transition"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Reserved</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Available</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Reorder At</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($stocks as $stock)
                    @php
                        $isOut = $stock->isOutOfStock();
                        $isLow = !$isOut && $stock->isLowStock();
                    @endphp
                    <tr class="hover:bg-primary-50/50 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="font-medium text-primary-600">
                                {{ $stock->product?->translations->first()?->name ?? '-' }}
                            </div>
                            @if($stock->variant)
                                <div class="text-xs text-primary-300 mt-0.5">{{ $stock->variant->name }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            <code class="text-xs bg-primary-50 text-primary-400 px-2 py-0.5 rounded-lg font-mono">
                                {{ $stock->sku ?? $stock->product?->sku ?? '-' }}
                            </code>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">{{ $stock->outlet?->name }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-500 font-medium">
                            {{ number_format($stock->quantity_on_hand, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-300">
                            {{ number_format($stock->quantity_reserved, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-bold
                            {{ $isOut ? 'text-danger-500' : ($isLow ? 'text-warning-600' : 'text-success-600') }}">
                            {{ number_format($stock->quantity_available, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-300">
                            {{ number_format($stock->reorder_point, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            @php
                                $badge = match($stock->status) {
                                    'available'    => 'bg-success-50 text-success-700 border border-success-200',
                                    'low_stock'    => 'bg-warning-50 text-warning-700 border border-warning-200',
                                    'out_of_stock' => 'bg-danger-50 text-danger-600 border border-danger-200',
                                    default        => 'bg-primary-50 text-primary-400 border border-primary-100',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                                {{ str_replace('_', ' ', ucwords($stock->status, '_')) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-16 text-center">
                            <i class="bi bi-inbox text-4xl text-primary-100 block mb-3"></i>
                            <p class="text-sm font-medium text-primary-300">No inventory records found.</p>
                            <p class="text-xs text-primary-200 mt-1">Try adjusting your search or filters.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($stocks->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">
                {{ $stocks->links() }}
            </div>
        @endif
    </div>

</div>