<div class="space-y-6">

    {{-- Page Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-boxes"></i>
                <span>Inventory</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Transfers</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Stock Transfers</h1>
            <p class="mt-0.5 text-sm text-primary-300">Move inventory between outlets with full audit trail.</p>
        </div>
        <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-arrow-left-right"></i>
            New Transfer
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search transfer number…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="statusFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}">{{ ucfirst($status) }}</option>
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

    {{-- Transfers Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Transfer #</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Route</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Date</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Requested By</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($transfers as $transfer)
                    @php
                        $badge = match($transfer->status) {
                            'pending'   => 'bg-warning-50 text-warning-700 border border-warning-200',
                            'approved'  => 'bg-info-50 text-info-700 border border-info-200',
                            'completed' => 'bg-success-50 text-success-700 border border-success-200',
                            'cancelled' => 'bg-danger-50 text-danger-600 border border-danger-200',
                            default     => 'bg-primary-50 text-primary-400 border border-primary-100',
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/50 transition-colors">
                        <td class="px-5 py-3.5">
                            <code class="text-xs font-mono font-semibold text-secondary-700 bg-secondary-50 px-2 py-0.5 rounded-lg border border-secondary-200">
                                {{ $transfer->transfer_number }}
                            </code>
                        </td>
                        <td class="px-5 py-3.5">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="text-primary-500 font-medium">{{ $transfer->fromOutlet?->name }}</span>
                                <i class="bi bi-arrow-right text-primary-200 text-xs"></i>
                                <span class="text-primary-500 font-medium">{{ $transfer->toOutlet?->name }}</span>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">{{ $transfer->created_at->format('d M Y') }}</td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">{{ $transfer->requestedBy?->name ?? '-' }}</td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                                {{ ucfirst($transfer->status) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button wire:click="viewTransfer({{ $transfer->id }})"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-primary-400 hover:text-primary-600 hover:bg-primary-50 transition">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                @if($transfer->status === 'pending')
                                    <button wire:click="approveTransfer({{ $transfer->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-info-600 hover:bg-info-50 transition">
                                        <i class="bi bi-check2"></i> Approve
                                    </button>
                                @endif
                                @if($transfer->status === 'approved')
                                    <button wire:click="completeTransfer({{ $transfer->id }})"
                                            wire:confirm="Complete this transfer? Stock levels will be updated immediately."
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-success-600 hover:bg-success-50 transition">
                                        <i class="bi bi-check2-all"></i> Complete
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-16 text-center">
                            <i class="bi bi-arrow-left-right text-4xl text-primary-100 block mb-3"></i>
                            <p class="text-sm font-medium text-primary-300">No transfers found.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($transfers->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">
                {{ $transfers->links() }}
            </div>
        @endif
    </div>

    {{-- Create Transfer Modal --}}
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showCreateModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showCreateModal', false)"></div>
            <div class="relative w-full max-w-2xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-4">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">New Stock Transfer</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Initiate a stock movement between outlets.</p>
                    </div>
                    <button wire:click="$set('showCreateModal', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">From Outlet</label>
                            <select wire:model="fromOutletId"
                                    class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="">Select outlet…</option>
                                @foreach($outlets as $outlet)
                                    <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                                @endforeach
                            </select>
                            @error('fromOutletId') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">To Outlet</label>
                            <select wire:model="toOutletId"
                                    class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="">Select outlet…</option>
                                @foreach($outlets as $outlet)
                                    <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                                @endforeach
                            </select>
                            @error('toOutletId') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Transfer Date</label>
                        <input wire:model="transferDate" type="date"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-2">Items</label>
                        <div class="space-y-2.5">
                            @foreach($transferItems as $i => $item)
                                <div class="flex gap-2 items-start p-3 rounded-xl bg-primary-50/50 border border-primary-100">
                                    <select wire:model="transferItems.{{ $i }}.product_id"
                                            class="flex-1 rounded-xl border border-primary-100 bg-white px-3 py-2 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition">
                                        <option value="">Select product…</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}">{{ $product->translations->first()?->name ?? $product->sku }}</option>
                                        @endforeach
                                    </select>
                                    <input wire:model="transferItems.{{ $i }}.quantity_requested"
                                           type="number" min="1" placeholder="Qty"
                                           class="w-24 rounded-xl border border-primary-100 bg-white px-3 py-2 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                                    @if(count($transferItems) > 1)
                                        <button wire:click="removeItem({{ $i }})"
                                                class="w-8 h-9 flex-shrink-0 flex items-center justify-center rounded-lg text-danger-400 hover:text-danger-600 hover:bg-danger-50 transition">
                                            <i class="bi bi-trash3 text-sm"></i>
                                        </button>
                                    @endif
                                </div>
                                @error("transferItems.{$i}.product_id") <p class="text-xs text-danger-500 -mt-1">{{ $message }}</p> @enderror
                            @endforeach
                        </div>
                        <button wire:click="addItem"
                                class="mt-2.5 inline-flex items-center gap-1.5 text-sm font-medium text-primary-400 hover:text-primary-600 transition">
                            <i class="bi bi-plus-circle"></i> Add another item
                        </button>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <textarea wire:model="notes" rows="2"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"
                                  placeholder="Any notes about this transfer…"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showCreateModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Cancel
                    </button>
                    <button wire:click="saveTransfer" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveTransfer">Create Transfer</span>
                        <span wire:loading wire:target="saveTransfer">Creating…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- View Transfer Modal --}}
    @if($showViewModal && $viewing)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showViewModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showViewModal', false)"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-4">

                <div class="px-6 py-5 border-b border-primary-100">
                    <div class="flex items-start justify-between">
                        <div>
                            <code class="text-xs font-mono font-bold text-secondary-700 bg-secondary-50 px-2 py-0.5 rounded-lg border border-secondary-200">
                                {{ $viewing->transfer_number }}
                            </code>
                            <div class="flex items-center gap-2 mt-2.5 text-sm font-medium text-primary-600">
                                <span>{{ $viewing->fromOutlet?->name }}</span>
                                <i class="bi bi-arrow-right text-primary-300 text-xs"></i>
                                <span>{{ $viewing->toOutlet?->name }}</span>
                            </div>
                        </div>
                        <button wire:click="$set('showViewModal', false)"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                    <div class="grid grid-cols-3 gap-4 mt-4">
                        <div class="bg-primary-50/50 rounded-xl px-3 py-2.5">
                            <p class="text-[11px] text-primary-300 uppercase font-semibold tracking-wide">Status</p>
                            <p class="text-sm font-semibold text-primary-600 mt-0.5 capitalize">{{ $viewing->status }}</p>
                        </div>
                        <div class="bg-primary-50/50 rounded-xl px-3 py-2.5">
                            <p class="text-[11px] text-primary-300 uppercase font-semibold tracking-wide">Requested By</p>
                            <p class="text-sm font-medium text-primary-500 mt-0.5">{{ $viewing->requestedBy?->name ?? '-' }}</p>
                        </div>
                        <div class="bg-primary-50/50 rounded-xl px-3 py-2.5">
                            <p class="text-[11px] text-primary-300 uppercase font-semibold tracking-wide">Date</p>
                            <p class="text-sm font-medium text-primary-500 mt-0.5">{{ $viewing->created_at->format('d M Y') }}</p>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-5">
                    <h3 class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-3">Transfer Items</h3>
                    <div class="rounded-xl border border-primary-100 overflow-hidden">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-primary-100 bg-primary-50/50">
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Requested</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Received</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-primary-50">
                                @foreach($viewing->items as $item)
                                    <tr>
                                        <td class="px-4 py-3 text-primary-600 font-medium">{{ $item->product?->translations->first()?->name ?? $item->product?->sku }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-primary-500">{{ $item->quantity_requested }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums font-semibold {{ $item->quantity_received < $item->quantity_requested ? 'text-warning-600' : 'text-success-600' }}">
                                            {{ $item->quantity_received }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($viewing->notes)
                        <div class="mt-4 p-3 bg-primary-50/50 rounded-xl border border-primary-100">
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1">Notes</p>
                            <p class="text-sm text-primary-500">{{ $viewing->notes }}</p>
                        </div>
                    @endif
                </div>

                <div class="flex justify-end px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showViewModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>