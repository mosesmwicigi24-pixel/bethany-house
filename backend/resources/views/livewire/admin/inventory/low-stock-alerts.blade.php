<div class="space-y-6">

    {{-- Page Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-boxes"></i>
                <span>Inventory</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Low Stock Alerts</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Low Stock Alerts</h1>
            <p class="mt-0.5 text-sm text-primary-300">Products requiring reordering or immediate attention.</p>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 gap-4">
        <div class="relative overflow-hidden rounded-2xl bg-white border border-danger-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-danger-400 uppercase tracking-wide">Out of Stock</p>
                    <p class="text-3xl font-bold text-danger-600 mt-1">{{ $summary['out_of_stock'] }}</p>
                    <p class="text-xs text-danger-300 mt-1">Products need immediate restock</p>
                </div>
                <div class="w-14 h-14 rounded-2xl bg-danger-50 flex items-center justify-center flex-shrink-0">
                    <i class="bi bi-exclamation-circle text-danger-500 text-2xl"></i>
                </div>
            </div>
            {{-- Decorative --}}
            <div class="absolute -right-3 -bottom-3 w-20 h-20 rounded-full bg-danger-50 opacity-60"></div>
        </div>

        <div class="relative overflow-hidden rounded-2xl bg-white border border-warning-200 p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-warning-500 uppercase tracking-wide">Low Stock</p>
                    <p class="text-3xl font-bold text-warning-600 mt-1">{{ $summary['low_stock'] }}</p>
                    <p class="text-xs text-warning-400 mt-1">Products approaching reorder point</p>
                </div>
                <div class="w-14 h-14 rounded-2xl bg-warning-50 flex items-center justify-center flex-shrink-0">
                    <i class="bi bi-exclamation-triangle text-warning-500 text-2xl"></i>
                </div>
            </div>
            <div class="absolute -right-3 -bottom-3 w-20 h-20 rounded-full bg-warning-50 opacity-60"></div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-56">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search product or SKU…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="outletFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Outlets</option>
            @foreach($outlets as $outlet)
                <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
            @endforeach
        </select>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            <button wire:click="$set('severityFilter', '')"
                    class="px-3.5 py-2.5 font-medium transition {{ $severityFilter === '' ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                All
            </button>
            <button wire:click="$set('severityFilter', 'out_of_stock')"
                    class="px-3.5 py-2.5 font-medium border-l border-primary-100 transition {{ $severityFilter === 'out_of_stock' ? 'bg-danger-500 text-white' : 'text-primary-400 hover:text-danger-600' }}">
                Out of Stock
            </button>
            <button wire:click="$set('severityFilter', 'low_stock')"
                    class="px-3.5 py-2.5 font-medium border-l border-primary-100 transition {{ $severityFilter === 'low_stock' ? 'bg-warning-500 text-white' : 'text-primary-400 hover:text-warning-600' }}">
                Low Stock
            </button>
        </div>
    </div>

    {{-- Alerts Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">SKU</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Outlet</th>
                    <th wire:click="sort('quantity_available')"
                        class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">
                            Available
                            <i class="bi bi-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Reorder Point</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Suggested Order</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Severity</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($stocks as $stock)
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
                        <td class="px-5 py-3.5 text-right tabular-nums font-bold
                            {{ $stock->isOutOfStock() ? 'text-danger-500' : 'text-warning-600' }}">
                            {{ number_format($stock->quantity_available, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-300">
                            {{ number_format($stock->reorder_point, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-500 font-medium">
                            {{ number_format($stock->reorder_quantity ?? ($stock->reorder_point * 2), 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            @if($stock->isOutOfStock())
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold bg-danger-50 text-danger-600 border border-danger-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-danger-500 animate-pulse inline-block"></span>
                                    Out of Stock
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium bg-warning-50 text-warning-700 border border-warning-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-warning-400 inline-block"></span>
                                    Low Stock
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-16 text-center">
                            <i class="bi bi-check-circle text-4xl text-success-300 block mb-3"></i>
                            <p class="text-sm font-semibold text-primary-400">All stock levels are healthy!</p>
                            <p class="text-xs text-primary-200 mt-1">No products are currently below their reorder point.</p>
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