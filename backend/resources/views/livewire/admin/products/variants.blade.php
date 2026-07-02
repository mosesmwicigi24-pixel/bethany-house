<div class="space-y-6 font-dm-sans">

    {{-- Flash --}}
    @if($flashMessage)
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
         x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="rounded-xl px-4 py-3 text-sm font-medium border
                {{ $flashType === 'success' ? 'bg-success-100 text-success-700 border-success-200' : 'bg-danger-100 text-danger-700 border-danger-200' }}">
        {{ $flashMessage }}
    </div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-primary-500 tracking-tight">Product Variants</h1>
            <p class="text-sm text-gray-500 mt-0.5">Manage variants across all products</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-4">
        <div class="flex flex-wrap gap-3 items-center">
            <div class="flex-1 min-w-[200px] relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input wire:model.live.debounce.350ms="search" type="text" placeholder="Search SKU or name…"
                    class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/50">
            </div>
            <select wire:model.live="product_id"
                class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 min-w-[180px] bg-gray-50/50">
                <option value="">All Products</option>
                @foreach($this->products as $p)
                    <option value="{{ $p->id }}">{{ $p->name_en }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer bg-gray-50/50 border border-gray-200 rounded-xl px-3 py-2 hover:bg-gray-100 transition-colors">
                <input wire:model.live="lowStock" type="checkbox" class="rounded border-gray-300 text-primary-500 focus:ring-primary-400">
                <span>Low stock</span>
                <span class="text-gray-400">≤</span>
                <input wire:model.live="lowStockThreshold" type="number" min="1" value="5"
                    class="w-12 border border-gray-200 rounded-lg px-2 py-0.5 text-xs text-center focus:outline-none focus:ring-1 focus:ring-primary-300 bg-white">
            </label>
            <button wire:click="$set('search', ''); $set('product_id', ''); $set('lowStock', false)"
                class="px-4 py-2 text-sm text-gray-500 border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors flex items-center gap-1.5">
                <i class="bi bi-x-circle"></i> Clear
            </button>
        </div>
    </div>

    {{-- Bulk Bar --}}
    @if(count($selected) > 0)
    <div class="bg-primary-50 border border-primary-200 rounded-2xl px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
        <span class="text-sm text-primary-700 font-semibold">
            <i class="bi bi-check2-square me-1.5"></i>{{ count($selected) }} variant{{ count($selected) !== 1 ? 's' : '' }} selected
        </span>
        <div class="flex items-center gap-2 flex-wrap">
            <button wire:click="bulkActivate"
                class="text-sm px-3 py-1.5 bg-success-500 text-white rounded-xl hover:bg-success-600 transition-colors font-medium">Activate</button>
            <button wire:click="bulkDeactivate"
                class="text-sm px-3 py-1.5 bg-gray-500 text-white rounded-xl hover:bg-gray-600 transition-colors font-medium">Deactivate</button>
            <button wire:click="$set('showBulkModal', true)"
                class="text-sm px-3 py-1.5 bg-primary-500 text-white rounded-xl hover:bg-primary-600 transition-colors font-medium">
                <i class="bi bi-currency-dollar me-1"></i>Adjust Prices
            </button>
            <button wire:click="clearSelected"
                class="text-sm px-3 py-1.5 border border-gray-200 rounded-xl text-gray-500 hover:bg-white transition-colors">Cancel</button>
        </div>
    </div>
    @endif

    {{-- Table --}}
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 overflow-hidden">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80 border-b border-gray-100">
                        <th class="px-4 py-3.5 text-left w-10">
                            <input type="checkbox" wire:model.live="selectAll"
                                class="rounded border-gray-300 text-primary-500 focus:ring-primary-400 cursor-pointer">
                        </th>
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600">Variant</th>
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600">Product</th>
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600">Attributes</th>
                        <th class="px-4 py-3.5 text-right font-semibold text-gray-600">KES</th>
                        <th class="px-4 py-3.5 text-right font-semibold text-gray-600">USD</th>
                        <th class="px-4 py-3.5 text-center font-semibold text-gray-600">Stock</th>
                        <th class="px-4 py-3.5 text-center font-semibold text-gray-600">Status</th>
                        <th class="px-4 py-3.5 text-center font-semibold text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($this->variants as $v)
                    @php
                        $attrs = $v->attribute_values
                            ? (is_string($v->attribute_values) ? json_decode($v->attribute_values, true) : $v->attribute_values)
                            : [];
                        $stock = $v->inventories_sum_quantity ?? 0;
                        $stockCls = $stock <= 0 ? 'bg-danger-100 text-danger-700' : ($stock <= 5 ? 'bg-warning-100 text-warning-700' : 'bg-success-100 text-success-700');
                    @endphp
                    <tr class="hover:bg-gray-50/60 transition-colors">
                        <td class="px-4 py-3.5">
                            <input type="checkbox" wire:model.live="selected" value="{{ $v->id }}"
                                class="rounded border-gray-300 text-primary-500 focus:ring-primary-400 cursor-pointer">
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="font-semibold text-gray-800 text-sm">{{ $v->name }}</div>
                            <div class="font-mono text-xs text-gray-400 mt-0.5">{{ $v->sku }}</div>
                        </td>
                        <td class="px-4 py-3.5 text-sm text-gray-600">{{ $v->product?->name_en ?? '-' }}</td>
                        <td class="px-4 py-3.5">
                            @if(!empty($attrs))
                            <div class="flex flex-wrap gap-1">
                                @foreach($attrs as $key => $val)
                                <span class="text-xs bg-gray-100 text-gray-600 rounded-lg px-2 py-0.5 font-medium">{{ $key }}: {{ $val }}</span>
                                @endforeach
                            </div>
                            @else
                            <span class="text-xs text-gray-300">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-right tabular-nums text-gray-700 text-xs font-medium">{{ number_format($v->price_kes) }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums text-gray-700 text-xs">${{ number_format($v->price_usd, 2) }}</td>
                        <td class="px-4 py-3.5 text-center">
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $stockCls }}">{{ $stock }}</span>
                        </td>
                        <td class="px-4 py-3.5 text-center">
                            @if($v->is_active !== false)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-success-100 text-success-700">Active</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-center">
                            <button wire:click="openEdit({{ $v->id }})"
                                class="w-7 h-7 rounded-lg flex items-center justify-center mx-auto text-gray-400 hover:text-primary-600 hover:bg-primary-50 transition-colors">
                                <i class="bi bi-pencil text-sm"></i>
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center py-16">
                            <div class="flex flex-col items-center gap-2 text-gray-400">
                                <i class="bi bi-diagram-3 text-3xl text-gray-200"></i>
                                <p class="text-sm font-medium text-gray-500">No variants found</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($this->variants->hasPages())
        <div class="px-5 py-3.5 border-t border-gray-100 flex items-center justify-between flex-wrap gap-2 bg-gray-50/50">
            <p class="text-sm text-gray-500">
                Showing {{ $this->variants->firstItem() }}–{{ $this->variants->lastItem() }} of {{ $this->variants->total() }}
            </p>
            {{ $this->variants->links('livewire.admin.pagination') }}
        </div>
        @endif
    </div>

    {{-- Edit Variant Modal --}}
    @if($showEditModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden ring-1 ring-gray-200">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h3 class="font-semibold text-gray-900">Edit Variant</h3>
                <button wire:click="$set('showEditModal', false)" class="w-8 h-8 rounded-xl flex items-center justify-center text-gray-400 hover:bg-gray-100 transition-colors">
                    <i class="bi bi-x-lg text-sm"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">SKU</label>
                        <input wire:model="edit_sku" type="text"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                        @error('edit_sku') <p class="text-danger-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Name</label>
                        <input wire:model="edit_name" type="text"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                        @error('edit_name') <p class="text-danger-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Price KES</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-400">KES</span>
                            <input wire:model="edit_price_kes" type="number" min="0" step="0.01"
                                class="w-full border border-gray-200 rounded-xl pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                        </div>
                        @error('edit_price_kes') <p class="text-danger-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Price USD</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-bold text-gray-400">$</span>
                            <input wire:model="edit_price_usd" type="number" min="0" step="0.01"
                                class="w-full border border-gray-200 rounded-xl pl-7 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                        </div>
                        @error('edit_price_usd') <p class="text-danger-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input wire:model="edit_is_active" type="checkbox"
                        class="rounded border-gray-300 text-primary-500 focus:ring-primary-400">
                    <span class="text-sm font-medium text-gray-700">Variant is active</span>
                </label>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 bg-gray-50/50">
                <button wire:click="$set('showEditModal', false)"
                    class="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-white transition-colors">Cancel</button>
                <button wire:click="saveVariant" wire:loading.attr="disabled"
                    class="px-5 py-2 text-sm bg-primary-500 text-white rounded-xl hover:bg-primary-600 transition-colors font-semibold disabled:opacity-60">
                    <span wire:loading.remove wire:target="saveVariant">Save Changes</span>
                    <span wire:loading wire:target="saveVariant">Saving…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Bulk Price Modal --}}
    @if($showBulkModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 ring-1 ring-gray-200">
            <h3 class="font-semibold text-gray-900 mb-1">Adjust Prices</h3>
            <p class="text-sm text-gray-500 mb-5">Apply a price adjustment to {{ count($selected) }} selected variant{{ count($selected) !== 1 ? 's' : '' }}.</p>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Currency</label>
                    <select wire:model="bulkCurrency"
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                        <option value="adjust_price_kes">KES</option>
                        <option value="adjust_price_usd">USD</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Adjustment Type</label>
                    <select wire:model="bulkAdjustType"
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed Amount</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Value</label>
                    <input wire:model="bulkValue" type="number" step="0.01"
                        placeholder="{{ $bulkAdjustType === 'percentage' ? 'e.g. 10 for +10%' : 'e.g. 500 for +KES 500' }}"
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                    @error('bulkValue') <p class="text-danger-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button wire:click="$set('showBulkModal', false)"
                    class="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">Cancel</button>
                <button wire:click="applyBulkPrice" wire:loading.attr="disabled"
                    class="px-5 py-2 text-sm bg-primary-500 text-white rounded-xl hover:bg-primary-600 transition-colors font-semibold disabled:opacity-60">
                    <span wire:loading.remove wire:target="applyBulkPrice">Apply</span>
                    <span wire:loading wire:target="applyBulkPrice">Applying…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

</div>