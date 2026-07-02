<div class="max-w-4xl mx-auto space-y-6">

    <div>
        <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
            <i class="bi bi-truck"></i><span>Procurement</span>
            <i class="bi bi-chevron-right text-[10px]"></i>
            <a href="{{ route('admin.procurement.purchase-orders') }}" class="hover:text-primary-500">Purchase Orders</a>
            <i class="bi bi-chevron-right text-[10px]"></i><span>Create PO</span>
        </div>
        <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">New Purchase Order</h1>
        <p class="mt-0.5 text-sm text-primary-300">Add line items from materials or products then save as draft or submit.</p>
    </div>

    @if($errors->any())
        <div class="flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
            <ul class="space-y-0.5 list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Header fields --}}
    <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
        <p class="text-sm font-bold text-primary-500">Order Details</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Supplier <span class="text-danger-500">*</span></label>
                <select wire:model="supplierId" class="w-full rounded-xl border {{ $errors->has('supplierId') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                    <option value="">Select supplier…</option>
                    @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
                @error('supplierId')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Deliver To (Outlet) <span class="text-danger-500">*</span></label>
                <select wire:model="outletId" class="w-full rounded-xl border {{ $errors->has('outletId') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                    <option value="">Select outlet…</option>
                    @foreach($outlets as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                </select>
                @error('outletId')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Order Date <span class="text-danger-500">*</span></label>
                <input wire:model="orderDate" type="date" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Expected Delivery <span class="text-danger-500">*</span></label>
                <input wire:model="expectedDeliveryDate" type="date" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                @error('expectedDeliveryDate')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Currency</label>
                <select wire:model="currencyCode" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                    <option value="KES">KES - Kenyan Shilling</option>
                    <option value="USD">USD - US Dollar</option>
                    <option value="EUR">EUR - Euro</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Payment Terms</label>
                <input wire:model="paymentTerms" type="text" placeholder="e.g. Net 30"
                       class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
            <textarea wire:model="notes" rows="2"
                      class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
        </div>
    </div>

    {{-- Line items --}}
    <div class="bg-white rounded-2xl border border-primary-100 shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-primary-100">
            <p class="text-sm font-bold text-primary-500">Line Items</p>
            @error('lineItems')<p class="text-xs text-danger-500">{{ $message }}</p>@enderror
        </div>

        {{-- Item search bar --}}
        <div class="px-5 py-3 border-b border-primary-50 bg-primary-50/30 flex flex-wrap items-center gap-3">
            <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-xs">
                <button wire:click="$set('addItemType','material')"
                        class="px-3 py-2 font-semibold transition {{ $addItemType==='material' ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                    Materials
                </button>
                <button wire:click="$set('addItemType','product')"
                        class="px-3 py-2 font-semibold border-l border-primary-100 transition {{ $addItemType==='product' ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                    Products
                </button>
            </div>
            <div class="relative flex-1 min-w-48">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-primary-300 text-xs pointer-events-none"></i>
                <input wire:model.live.debounce.200ms="itemSearch" type="text"
                       placeholder="Search {{ $addItemType === 'material' ? 'materials' : 'products' }} to add…"
                       class="w-full pl-8 pr-4 py-2 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
            <button wire:click="addBlankLine"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-primary-200 px-3 py-2 text-xs font-semibold text-primary-500 hover:bg-primary-50 transition">
                <i class="bi bi-plus-lg"></i> Blank Line
            </button>

            {{-- Search results dropdown --}}
            @if($itemSearch && $searchResults->count())
                <div class="absolute z-20 mt-1 w-80 rounded-xl border border-primary-100 bg-white shadow-lg divide-y divide-primary-50 max-h-60 overflow-y-auto" style="top:100%">
                    @foreach($searchResults as $result)
                        <button wire:click="{{ $addItemType==='material' ? 'addMaterial' : 'addProduct' }}({{ $result->id }})"
                                class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-primary-50 transition text-sm">
                            <i class="bi {{ $addItemType==='material' ? 'bi-box' : 'bi-bag' }} text-primary-300 flex-shrink-0"></i>
                            <div class="min-w-0">
                                <p class="font-medium text-primary-600 truncate">
                                    {{ $addItemType==='material' ? $result->name : ($result->translations->first()?->name ?? $result->sku) }}
                                </p>
                                <code class="text-[11px] text-primary-300 font-mono">{{ $result->code ?? $result->sku }}</code>
                            </div>
                            @if($addItemType==='material')
                                <span class="ml-auto text-xs text-primary-400 flex-shrink-0">{{ number_format($result->cost_per_unit,2) }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Line item rows --}}
        @if(!empty($lineItems))
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="border-b border-primary-100">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Description</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider w-24">Qty</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider w-28">Unit Price</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider w-20">Tax %</th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider w-28">Line Total</th>
                        <th class="px-4 py-2.5 w-8"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary-50">
                    @foreach($lineItems as $i => $item)
                    <tr class="hover:bg-primary-50/30 transition-colors">
                        <td class="px-4 py-2.5">
                            <input wire:model="lineItems.{{ $i }}.description" type="text" placeholder="Item description"
                                   class="w-full rounded-lg border border-primary-100 px-2.5 py-1.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition bg-white" />
                            @error("lineItems.{$i}.description")<p class="text-xs text-danger-500 mt-0.5">{{ $message }}</p>@enderror
                        </td>
                        <td class="px-4 py-2.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-primary-50 text-primary-400 border border-primary-100 capitalize">
                                {{ $item['item_type'] }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5">
                            <input wire:model.live="lineItems.{{ $i }}.quantity" type="number" min="0.01" step="0.01"
                                   class="w-full rounded-lg border border-primary-100 px-2.5 py-1.5 text-sm text-right tabular-nums text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition bg-white" />
                        </td>
                        <td class="px-4 py-2.5">
                            <input wire:model.live="lineItems.{{ $i }}.unit_price" type="number" min="0" step="0.01"
                                   class="w-full rounded-lg border border-primary-100 px-2.5 py-1.5 text-sm text-right tabular-nums text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition bg-white" />
                        </td>
                        <td class="px-4 py-2.5">
                            <input wire:model.live="lineItems.{{ $i }}.tax_rate" type="number" min="0" max="100" step="0.5" placeholder="0"
                                   class="w-full rounded-lg border border-primary-100 px-2.5 py-1.5 text-sm text-right tabular-nums text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition bg-white" />
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-primary-600">
                            {{ number_format(((float)$item['unit_price'] * (float)$item['quantity']) + (float)$item['tax_amount'], 2) }}
                        </td>
                        <td class="px-4 py-2.5">
                            <button wire:click="removeLine({{ $i }})"
                                    class="w-6 h-6 flex items-center justify-center rounded text-primary-200 hover:text-danger-500 hover:bg-danger-50 transition">
                                <i class="bi bi-x text-sm"></i>
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-primary-50 border-t border-primary-200">
                    <tr>
                        <td colspan="5" class="px-4 py-3 text-sm font-semibold text-primary-400 text-right">Subtotal</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold text-primary-600">{{ number_format($subtotal,2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-sm text-primary-400 text-right">Tax</td>
                        <td class="px-4 py-2 text-right tabular-nums text-primary-500">{{ number_format($taxTotal,2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" class="px-4 py-3 text-sm font-bold text-primary-500 text-right">Total ({{ $currencyCode }})</td>
                        <td class="px-4 py-3 text-right tabular-nums font-bold text-primary-600 text-base">{{ number_format($total,2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @else
            <div class="flex flex-col items-center justify-center py-12 text-primary-200">
                <i class="bi bi-box text-3xl mb-2"></i>
                <p class="text-sm">Search for materials or products above to add line items.</p>
            </div>
        @endif
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.procurement.purchase-orders') }}"
           class="rounded-xl border border-primary-100 bg-white px-4 py-2.5 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
            Cancel
        </a>
        <button wire:click="save('draft')" wire:loading.attr="disabled"
                class="rounded-xl border border-primary-200 bg-white px-4 py-2.5 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition disabled:opacity-60">
            <span wire:loading.remove wire:target="save('draft')">Save as Draft</span>
            <span wire:loading wire:target="save('draft')">Saving…</span>
        </button>
        <button wire:click="save('submitted')" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
            <span wire:loading.remove wire:target="save('submitted')"><i class="bi bi-send mr-1"></i>Submit PO</span>
            <span wire:loading wire:target="save('submitted')">Submitting…</span>
        </button>
    </div>

</div>