<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-scissors"></i><span>Production</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Bill of Materials</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Bill of Materials</h1>
            <p class="mt-0.5 text-sm text-primary-300">Define the materials required to produce each product.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> New BOM
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by product name…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['all'=>'All', 'active'=>'Active', 'inactive'=>'Inactive'] as $val => $label)
                <button wire:click="$set('statusFilter', '{{ $val }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $val ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- BOM Cards grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($boms as $bom)
            @php $productName = $bom->product?->translations->first()?->name ?? $bom->product?->sku ?? '-'; @endphp
            <div class="bg-white rounded-2xl border {{ $bom->is_active ? 'border-primary-100' : 'border-primary-50 opacity-60' }} hover:border-primary-200 hover:shadow-md transition-all duration-200 flex flex-col">
                <div class="p-5 flex-1">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-primary-50 border border-primary-100 flex items-center justify-center flex-shrink-0">
                                <i class="bi bi-diagram-3 text-primary-400 text-lg"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-bold text-primary-600 text-sm leading-tight truncate">{{ $productName }}</h3>
                                @if($bom->variant)
                                    <p class="text-xs text-primary-300 mt-0.5">{{ $bom->variant->variant_name }}</p>
                                @endif
                            </div>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold flex-shrink-0
                                     {{ $bom->is_active ? 'bg-success-50 text-success-700 border border-success-200' : 'bg-primary-50 text-primary-300 border border-primary-100' }}">
                            {{ $bom->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>

                    <div class="space-y-1.5 text-xs text-primary-400">
                        <div class="flex items-center justify-between">
                            <span>Version</span>
                            <span class="font-semibold text-primary-600">v{{ $bom->version }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Materials</span>
                            <span class="font-semibold text-primary-600">{{ $bom->items_count }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Last Updated</span>
                            <span class="text-primary-400">{{ $bom->updated_at->format('d M Y') }}</span>
                        </div>
                    </div>
                </div>

                <div class="px-5 py-3.5 border-t border-primary-50 flex items-center justify-between">
                    <button wire:click="viewBomItems({{ $bom->id }})"
                            class="text-xs font-semibold text-info-600 hover:text-info-700 transition">
                        <i class="bi bi-list-ul mr-1"></i>View Items
                    </button>
                    <div class="flex items-center gap-1">
                        <button wire:click="toggleActive({{ $bom->id }})"
                                title="{{ $bom->is_active ? 'Deactivate' : 'Activate' }}"
                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg transition
                                       {{ $bom->is_active ? 'text-success-400 hover:text-success-600 hover:bg-success-50' : 'text-primary-200 hover:text-primary-500 hover:bg-primary-50' }}">
                            <i class="bi {{ $bom->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} text-base"></i>
                        </button>
                        <button wire:click="openEdit({{ $bom->id }})"
                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-200 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-pencil text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-3 py-20 text-center">
                <div class="w-16 h-16 rounded-2xl bg-primary-50 border border-primary-100 flex items-center justify-center mx-auto mb-4">
                    <i class="bi bi-diagram-3 text-primary-200 text-2xl"></i>
                </div>
                <p class="text-sm font-semibold text-primary-400">No BOMs found.</p>
                <p class="text-xs text-primary-200 mt-1">Create a Bill of Materials to define what materials each product needs.</p>
            </div>
        @endforelse
    </div>

    @if($boms->hasPages())
        <div>{{ $boms->links() }}</div>
    @endif

    {{-- View BOM Items slide-over --}}
    @if($showItems && $viewingBom)
        <div class="fixed inset-0 z-50 flex"
             x-data x-on:keydown.escape.window="$wire.set('showItems', false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showItems', false)"></div>
            <div class="w-full max-w-lg bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">
                            {{ $viewingBom->product?->translations->first()?->name }} - BOM v{{ $viewingBom->version }}
                        </h2>
                        <p class="text-xs text-primary-300 mt-0.5">{{ $viewingBom->items->count() }} materials · Est. cost:
                            <span class="font-semibold text-primary-500">KES {{ number_format($viewingBom->getTotalCost(), 2) }}</span>
                        </p>
                    </div>
                    <button wire:click="$set('showItems', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5">
                    <div class="rounded-xl border border-primary-100 overflow-hidden">
                        <table class="min-w-full text-sm">
                            <thead class="bg-primary-50/60 border-b border-primary-100">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Material</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Code</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Quantity</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Unit</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-primary-50">
                                @foreach($viewingBom->items as $item)
                                    <tr class="hover:bg-primary-50/40 transition-colors">
                                        <td class="px-4 py-3 font-medium text-primary-600">{{ $item->material?->name }}</td>
                                        <td class="px-4 py-3"><code class="text-xs font-mono text-primary-300">{{ $item->material?->code }}</code></td>
                                        <td class="px-4 py-3 text-right tabular-nums text-primary-500 font-semibold">{{ $item->quantity }}</td>
                                        <td class="px-4 py-3 text-primary-400 text-xs">{{ $item->unit_of_measure }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-primary-500">
                                            {{ number_format($item->quantity * ($item->material?->cost_per_unit ?? 0), 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-primary-50 border-t border-primary-200">
                                <tr>
                                    <td colspan="4" class="px-4 py-3 text-sm font-bold text-primary-500">Total Estimated Cost</td>
                                    <td class="px-4 py-3 text-right font-bold text-primary-600 tabular-nums">KES {{ number_format($viewingBom->getTotalCost(), 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @if($viewingBom->notes)
                        <div class="mt-4 rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">Notes</p>
                            <p class="text-sm text-secondary-700">{{ $viewingBom->notes }}</p>
                        </div>
                    @endif
                </div>
                <div class="flex items-center gap-2 px-6 py-4 border-t border-primary-100">
                    <button wire:click="openEdit({{ $viewingBom->id }})"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border border-primary-200 bg-white px-4 py-2.5 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-pencil"></i> Edit BOM
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Create / Edit BOM Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal', false)"></div>
            <div class="relative w-full max-w-2xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-6">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Bill of Materials' : 'New Bill of Materials' }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Define the materials needed to produce this product.</p>
                    </div>
                    <button wire:click="$set('showModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-5">
                    {{-- Product search --}}
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Product</label>
                        <div class="relative">
                            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
                            <input wire:model.live.debounce.200ms="bomProductSearch" type="text"
                                   placeholder="Search product…"
                                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        @if($bomProductSearch && $this->bomProducts->count() && !$bomProductId)
                            <div class="mt-1.5 rounded-xl border border-primary-100 overflow-hidden shadow-sm divide-y divide-primary-50">
                                @foreach($this->bomProducts as $p)
                                    <button wire:click="selectBomProduct({{ $p->id }}, '{{ addslashes($p->translations->first()?->name ?? $p->sku) }}')"
                                            class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-primary-50 transition text-sm">
                                        <i class="bi bi-box text-primary-300 flex-shrink-0"></i>
                                        <span class="font-medium text-primary-600">{{ $p->translations->first()?->name ?? $p->sku }}</span>
                                        <code class="ml-auto text-xs text-primary-300 font-mono">{{ $p->sku }}</code>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        @if($bomProductId)
                            <div class="mt-1.5 flex items-center gap-2 rounded-xl bg-success-50 border border-success-200 px-3 py-2">
                                <i class="bi bi-check-circle-fill text-success-500 text-sm"></i>
                                <span class="text-sm font-semibold text-success-700">{{ $bomProductSearch }}</span>
                                <button wire:click="$set('bomProductId', ''); $set('bomProductSearch', '')" class="ml-auto text-success-400 hover:text-success-600"><i class="bi bi-x text-sm"></i></button>
                            </div>
                        @endif
                        @error('bomProductId')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Version</label>
                            <input wire:model="bomVersion" type="number" min="1"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div class="flex items-end pb-2.5">
                            <label class="flex items-center gap-2.5 cursor-pointer">
                                <input wire:model="bomIsActive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                                <span class="text-sm font-medium text-primary-500">Active (use in production)</span>
                            </label>
                        </div>
                    </div>

                    {{-- Material Lines --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-semibold text-primary-400 uppercase tracking-wide">Material Lines</label>
                            <button wire:click="addBomLine" class="inline-flex items-center gap-1.5 rounded-xl border border-primary-200 px-3 py-1.5 text-xs font-semibold text-primary-500 hover:bg-primary-50 transition">
                                <i class="bi bi-plus-lg"></i> Add Line
                            </button>
                        </div>
                        @error('bomItems')<p class="text-xs text-danger-500 mb-2">{{ $message }}</p>@enderror
                        <div class="space-y-2">
                            @foreach($bomItems as $i => $item)
                                <div class="flex items-center gap-2 rounded-xl bg-primary-50/50 border border-primary-100 px-3 py-2.5">
                                    <select wire:model="bomItems.{{ $i }}.material_id"
                                            class="flex-1 rounded-lg border border-primary-100 bg-white px-2.5 py-1.5 text-xs text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition">
                                        <option value="">Select material…</option>
                                        @foreach($materials as $m)
                                            <option value="{{ $m->id }}" {{ $item['material_id'] == $m->id ? 'selected' : '' }}>
                                                {{ $m->name }} ({{ $m->code }})
                                            </option>
                                        @endforeach
                                    </select>
                                    <input wire:model="bomItems.{{ $i }}.quantity" type="number" step="0.01" min="0.01" placeholder="Qty"
                                           class="w-20 rounded-lg border border-primary-100 bg-white px-2.5 py-1.5 text-xs text-center text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                    <input wire:model="bomItems.{{ $i }}.unit_of_measure" type="text" placeholder="Unit"
                                           class="w-16 rounded-lg border border-primary-100 bg-white px-2.5 py-1.5 text-xs text-center text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                    <input wire:model="bomItems.{{ $i }}.notes" type="text" placeholder="Notes…"
                                           class="w-28 rounded-lg border border-primary-100 bg-white px-2.5 py-1.5 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                    <button wire:click="removeBomLine({{ $i }})" class="text-primary-200 hover:text-danger-500 transition flex-shrink-0">
                                        <i class="bi bi-trash3 text-xs"></i>
                                    </button>
                                </div>
                                @error("bomItems.{$i}.material_id")<p class="text-xs text-danger-500 -mt-1 mb-1">{{ $message }}</p>@enderror
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <textarea wire:model="bomNotes" rows="2" placeholder="Any notes about this BOM version…"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="saveBom" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveBom">{{ $isEditing ? 'Update BOM' : 'Create BOM' }}</span>
                        <span wire:loading wire:target="saveBom">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>