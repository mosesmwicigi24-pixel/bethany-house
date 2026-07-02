<div class="space-y-6">

    {{-- Page Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-boxes"></i>
                <span>Inventory</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Adjustments</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Stock Adjustments</h1>
            <p class="mt-0.5 text-sm text-primary-300">Track all stock movements and apply manual corrections.</p>
        </div>
        <button wire:click="$set('showModal', true)"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-slash-minus"></i>
            New Adjustment
        </button>
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by SKU…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="outletFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Outlets</option>
            @foreach($outlets as $outlet)
                <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="typeFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Types</option>
            @foreach($transactionTypes as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <input wire:model.live="dateFrom" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Date</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Outlet</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Type</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Before</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Change</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">After</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">By</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($transactions as $tx)
                    <tr class="hover:bg-primary-50/50 transition-colors">
                        <td class="px-5 py-3.5 text-primary-300 text-xs whitespace-nowrap">
                            {{ $tx->created_at->format('d M Y') }}
                            <div class="text-[11px] text-primary-200">{{ $tx->created_at->format('H:i') }}</div>
                        </td>
                        <td class="px-5 py-3.5">
                            <div class="font-medium text-primary-600">
                                {{ $tx->inventory?->product?->translations->first()?->name ?? '-' }}
                            </div>
                            <code class="text-[11px] text-primary-300 font-mono">
                                {{ $tx->inventory?->sku ?? $tx->inventory?->product?->sku }}
                            </code>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">{{ $tx->inventory?->outlet?->name }}</td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-info-50 text-info-700 border border-info-200">
                                {{ str_replace('_', ' ', ucwords($tx->transaction_type, '_')) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-300">
                            {{ number_format($tx->quantity_before, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-bold {{ $tx->quantity_change >= 0 ? 'text-success-600' : 'text-danger-500' }}">
                            {{ $tx->quantity_change >= 0 ? '+' : '' }}{{ number_format($tx->quantity_change, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-semibold text-primary-600">
                            {{ number_format($tx->quantity_after, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">{{ $tx->createdBy?->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-16 text-center">
                            <i class="bi bi-arrow-left-right text-4xl text-primary-100 block mb-3"></i>
                            <p class="text-sm font-medium text-primary-300">No transactions found.</p>
                            <p class="text-xs text-primary-200 mt-1">Adjustments will appear here once created.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($transactions->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>

    {{-- Adjustment Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal', false)"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl shadow-primary-900/20 overflow-hidden">

                {{-- Modal Header --}}
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">New Stock Adjustment</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Manually correct inventory quantities.</p>
                    </div>
                    <button wire:click="$set('showModal', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                {{-- Modal Body --}}
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Inventory Item</label>
                        <select wire:model="inventoryId"
                                class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            <option value="">Select inventory item…</option>
                            @foreach($inventories as $inv)
                                <option value="{{ $inv->id }}">
                                    {{ $inv->product?->translations->first()?->name }} - {{ $inv->outlet?->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('inventoryId') <p class="text-xs text-danger-500 mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Adjustment Type</label>
                        <select wire:model="adjustmentType"
                                class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            @foreach($transactionTypes as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('adjustmentType') <p class="text-xs text-danger-500 mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                            Quantity
                            <span class="normal-case font-normal text-primary-200 ml-1">- use negative to reduce stock</span>
                        </label>
                        <input wire:model="adjustmentQty" type="number"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('adjustmentQty') <p class="text-xs text-danger-500 mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <textarea wire:model="adjustmentNotes" rows="3"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"
                                  placeholder="Reason for adjustment…"></textarea>
                    </div>
                </div>

                {{-- Modal Footer --}}
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Cancel
                    </button>
                    <button wire:click="saveAdjustment" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveAdjustment">Save Adjustment</span>
                        <span wire:loading wire:target="saveAdjustment">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>