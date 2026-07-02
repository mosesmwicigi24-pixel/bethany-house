<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-truck"></i><span>Procurement</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Goods Receipt</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Goods Receipt</h1>
            <p class="mt-0.5 text-sm text-primary-300">Record deliveries against purchase orders and update inventory.</p>
        </div>
        <button wire:click="$set('showReceiveModal', true)"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-box-arrow-in-down"></i> Receive Goods
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="GRN #, PO # or supplier…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <input wire:model.live="dateFrom" type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo"   type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
    </div>

    {{-- GRN Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">GRN #</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">PO #</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Supplier</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Outlet</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Received Date</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Items</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Received By</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($grns as $grn)
                <tr class="hover:bg-primary-50/40 transition-colors group">
                    <td class="px-5 py-3.5">
                        <button wire:click="viewGrn({{ $grn->id }})"
                                class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg hover:bg-secondary-100 transition">
                            {{ $grn->grn_number }}
                        </button>
                    </td>
                    <td class="px-5 py-3.5">
                        <code class="font-mono text-xs text-primary-400">{{ $grn->purchaseOrder?->po_number }}</code>
                    </td>
                    <td class="px-5 py-3.5 text-sm font-medium text-primary-600">{{ $grn->purchaseOrder?->supplier?->name ?? '-' }}</td>
                    <td class="px-5 py-3.5 text-sm text-primary-400">{{ $grn->outlet?->name ?? '-' }}</td>
                    <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">{{ $grn->received_date?->format('d M Y') }}</td>
                    <td class="px-5 py-3.5 text-center">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">{{ $grn->items_count }}</span>
                    </td>
                    <td class="px-5 py-3.5 text-sm text-primary-400">{{ $grn->receivedBy?->name ?? '-' }}</td>
                    <td class="px-5 py-3.5 text-right">
                        <button wire:click="viewGrn({{ $grn->id }})"
                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition opacity-0 group-hover:opacity-100" title="View">
                            <i class="bi bi-eye text-sm"></i>
                        </button>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-5 py-16 text-center">
                    <i class="bi bi-box-arrow-in-down text-4xl text-primary-100 block mb-3"></i>
                    <p class="text-sm font-medium text-primary-300">No goods received notes yet.</p>
                </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($grns->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $grns->links() }}</div>
        @endif
    </div>

    {{-- Receive Goods Modal --}}
    @if($showReceiveModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showReceiveModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showReceiveModal', false)"></div>
            <div class="relative w-full max-w-2xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-6">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Receive Goods</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Enter a PO number to begin receiving.</p>
                    </div>
                    <button wire:click="$set('showReceiveModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-5">
                    {{-- PO lookup --}}
                    @if(!$receivingPo)
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Purchase Order Number</label>
                            <div class="flex gap-2">
                                <input wire:model="poSearch" wire:keydown.enter="searchPo" type="text" placeholder="e.g. PO-20240101-0001"
                                       class="flex-1 rounded-xl border border-primary-200 px-4 py-3 text-sm text-primary-600 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-400 transition" />
                                <button wire:click="searchPo" wire:loading.attr="disabled"
                                        class="rounded-xl bg-primary-500 hover:bg-primary-600 px-5 py-3 text-sm font-semibold text-white transition disabled:opacity-60">
                                    <span wire:loading.remove wire:target="searchPo"><i class="bi bi-search"></i></span>
                                    <span wire:loading wire:target="searchPo"><i class="bi bi-arrow-clockwise animate-spin"></i></span>
                                </button>
                            </div>
                            @if($poError)
                                <div class="mt-2 flex items-center gap-2 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                                    <i class="bi bi-exclamation-circle text-danger-500 flex-shrink-0"></i>{{ $poError }}
                                </div>
                            @endif
                        </div>
                    @else
                        {{-- PO details + receipt form --}}
                        <div class="rounded-xl bg-success-50 border border-success-200 px-4 py-3 flex items-center gap-3">
                            <i class="bi bi-check-circle-fill text-success-500"></i>
                            <div>
                                <p class="text-sm font-semibold text-success-700">{{ $receivingPo->po_number }}</p>
                                <p class="text-xs text-success-600 mt-0.5">{{ $receivingPo->supplier?->name }}</p>
                            </div>
                            <button wire:click="$set('receivingPo', null); $set('poSearch', '')" class="ml-auto text-success-400 hover:text-success-600 transition">
                                <i class="bi bi-x-circle text-sm"></i>
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Received Date <span class="text-danger-500">*</span></label>
                                <input wire:model="receivedDate" type="date" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                                @error('receivedDate')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Receive at Outlet <span class="text-danger-500">*</span></label>
                                <select wire:model="receiveOutletId" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                    <option value="">Select outlet…</option>
                                    @foreach($outlets as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                                </select>
                                @error('receiveOutletId')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Supplier Invoice # <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                                <input wire:model="invoiceNumber" type="text" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                                <input wire:model="receiveNotes" type="text" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                        </div>

                        {{-- Receipt line items --}}
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2">Receipt Lines</p>
                            <div class="rounded-xl border border-primary-100 overflow-hidden">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-primary-50/60 border-b border-primary-100">
                                        <tr>
                                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase">Item</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Ordered</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Received</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Rejected</th>
                                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase">Condition</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-primary-50">
                                        @foreach($receivingPo->items as $item)
                                            @php $line = $receiptLines[$item->id] ?? []; @endphp
                                            <tr>
                                                <td class="px-4 py-3">
                                                    <p class="font-medium text-primary-600 text-sm">{{ $item->description }}</p>
                                                    <span class="text-[11px] text-primary-300">Remaining: {{ max(0, $item->quantity - $item->quantity_received) }}</span>
                                                </td>
                                                <td class="px-4 py-3 text-right tabular-nums text-primary-400">{{ $item->quantity }}</td>
                                                <td class="px-4 py-3">
                                                    <input wire:model="receiptLines.{{ $item->id }}.qty_received"
                                                           type="number" min="0" step="0.01"
                                                           class="w-20 rounded-lg border border-primary-100 px-2 py-1.5 text-xs text-right tabular-nums text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                                </td>
                                                <td class="px-4 py-3">
                                                    <input wire:model="receiptLines.{{ $item->id }}.qty_rejected"
                                                           type="number" min="0" step="0.01"
                                                           class="w-20 rounded-lg border border-primary-100 px-2 py-1.5 text-xs text-right tabular-nums text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                                </td>
                                                <td class="px-4 py-3">
                                                    <select wire:model="receiptLines.{{ $item->id }}.condition"
                                                            class="rounded-lg border border-primary-100 px-2 py-1.5 text-xs text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition bg-white">
                                                        <option value="good">Good</option>
                                                        <option value="damaged">Damaged</option>
                                                        <option value="expired">Expired</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>

                @if($receivingPo)
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showReceiveModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="saveReceipt" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-success-500 hover:bg-success-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-success-500/20">
                        <span wire:loading.remove wire:target="saveReceipt"><i class="bi bi-box-arrow-in-down mr-1"></i>Confirm Receipt</span>
                        <span wire:loading wire:target="saveReceipt">Saving…</span>
                    </button>
                </div>
                @endif
            </div>
        </div>
    @endif

    {{-- GRN Detail slide-over --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-xl bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">{{ $viewing->grn_number }}</code>
                        <p class="text-xs text-primary-300 mt-1.5">{{ $viewing->purchaseOrder?->supplier?->name }} · {{ $viewing->received_date?->format('d M Y') }}</p>
                    </div>
                    <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-4">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        @foreach([['PO',$viewing->purchaseOrder?->po_number??'-'],['Outlet',$viewing->outlet?->name??'-'],['Invoice #',$viewing->invoice_number??'-'],['Received By',$viewing->receivedBy?->name??'-']] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="rounded-xl border border-primary-100 overflow-hidden">
                        <table class="min-w-full text-sm">
                            <thead class="bg-primary-50/60 border-b border-primary-100">
                                <tr>
                                    <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase">Item</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Received</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Rejected</th>
                                    <th class="px-4 py-2.5 text-center text-xs font-semibold text-primary-300 uppercase">Condition</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-primary-50">
                                @foreach($viewing->items as $grnItem)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-primary-600">{{ $grnItem->purchaseOrderItem?->description }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-success-600 font-semibold">{{ $grnItem->quantity_received }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums {{ $grnItem->quantity_rejected > 0 ? 'text-danger-600 font-semibold' : 'text-primary-300' }}">{{ $grnItem->quantity_rejected }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize
                                            {{ $grnItem->condition==='good' ? 'bg-success-50 text-success-700 border border-success-200' : 'bg-danger-50 text-danger-600 border border-danger-200' }}">
                                            {{ $grnItem->condition }}
                                        </span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($viewing->notes)
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">Notes</p>
                            <p class="text-sm text-secondary-700">{{ $viewing->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

</div>