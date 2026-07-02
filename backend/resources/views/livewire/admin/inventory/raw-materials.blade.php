<div class="space-y-6">

    {{-- Page Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-boxes"></i>
                <span>Inventory</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Raw Materials</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Raw Materials</h1>
            <p class="mt-0.5 text-sm text-primary-300">Manage raw materials, packaging, and consumables inventory.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i>
            Add Material
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name or code…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="typeFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Types</option>
            @foreach($materialTypes as $type)
                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
            @endforeach
        </select>
        <select wire:model.live="statusFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Material</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Type</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Unit</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Cost / Unit</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Total Stock</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Supplier</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($materials as $material)
                    <tr class="hover:bg-primary-50/50 transition-colors">
                        <td class="px-5 py-3.5">
                            <div class="font-medium text-primary-600">
                                {{ $material->name }}
                                @if($material->isLowStock())
                                    <span class="ml-1.5 inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold bg-warning-50 text-warning-700 border border-warning-200">Low</span>
                                @endif
                            </div>
                            <code class="text-[11px] text-primary-300 font-mono">{{ $material->code }}</code>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-primary-50 text-primary-400 border border-primary-100 capitalize">
                                {{ $material->material_type }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">{{ $material->unit_of_measure }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-500 font-medium">
                            {{ number_format($material->cost_per_unit, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-bold {{ $material->isLowStock() ? 'text-warning-600' : 'text-primary-600' }}">
                            {{ number_format($material->getTotalStock(), 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">{{ $material->supplier?->name ?? '-' }}</td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $material->is_active ? 'bg-success-50 text-success-700 border border-success-200' : 'bg-primary-50 text-primary-300 border border-primary-100' }}">
                                {{ $material->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button wire:click="openStockAdjust({{ $material->id }})"
                                        title="Adjust Stock"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-secondary-700 hover:bg-secondary-50 transition">
                                    <i class="bi bi-plus-slash-minus text-xs"></i>
                                </button>
                                <button wire:click="openEdit({{ $material->id }})"
                                        title="Edit"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition">
                                    <i class="bi bi-pencil text-xs"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-16 text-center">
                            <i class="bi bi-box text-4xl text-primary-100 block mb-3"></i>
                            <p class="text-sm font-medium text-primary-300">No materials found.</p>
                            <p class="text-xs text-primary-200 mt-1">Add your first raw material to get started.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($materials->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">
                {{ $materials->links() }}
            </div>
        @endif
    </div>

    {{-- Create / Edit Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal', false)"></div>
            <div class="relative w-full max-w-xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-4">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Material' : 'Add Material' }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5">{{ $isEditing ? 'Update material details.' : 'Register a new raw material.' }}</p>
                    </div>
                    <button wire:click="$set('showModal', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Code</label>
                            <input wire:model="code" type="text"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('code') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Material Type</label>
                            <select wire:model="materialType"
                                    class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="">Select type…</option>
                                @foreach($materialTypes as $type)
                                    <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                @endforeach
                            </select>
                            @error('materialType') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Name</label>
                        <input wire:model="name" type="text"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('name') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Description <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <textarea wire:model="description" rows="2"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Unit of Measure</label>
                            <input wire:model="unitOfMeasure" type="text" placeholder="kg, pcs, L…"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('unitOfMeasure') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Cost / Unit</label>
                            <input wire:model="costPerUnit" type="number" step="0.01" min="0"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('costPerUnit') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Reorder Point</label>
                            <input wire:model="reorderPoint" type="number" step="0.01" min="0"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('reorderPoint') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Reorder Quantity</label>
                            <input wire:model="reorderQuantity" type="number" step="0.01" min="0"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('reorderQuantity') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Supplier</label>
                            <select wire:model="supplierId"
                                    class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="">No supplier</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input wire:model="isActive" type="checkbox"
                               class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                        <span class="text-sm font-medium text-primary-500">Active material</span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Cancel
                    </button>
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update Material' : 'Create Material' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Stock Adjust Modal --}}
    @if($showStockModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showStockModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showStockModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Adjust Stock</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Update material stock at a specific outlet.</p>
                    </div>
                    <button wire:click="$set('showStockModal', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Outlet</label>
                        <select wire:model="adjustOutletId"
                                class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            <option value="">Select outlet…</option>
                            @foreach($outlets as $outlet)
                                <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                            @endforeach
                        </select>
                        @error('adjustOutletId') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                            Quantity Change
                            <span class="normal-case font-normal text-primary-200 ml-1">- negative to reduce</span>
                        </label>
                        <input wire:model="adjustQty" type="number"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('adjustQty') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showStockModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Cancel
                    </button>
                    <button wire:click="saveStockAdjust" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveStockAdjust">Save</span>
                        <span wire:loading wire:target="saveStockAdjust">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>