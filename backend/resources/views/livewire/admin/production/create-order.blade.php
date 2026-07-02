<div class="space-y-6 max-w-3xl mx-auto">

    <div>
        <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
            <i class="bi bi-scissors"></i><span>Production</span>
            <i class="bi bi-chevron-right text-[10px]"></i>
            <a href="{{ route('admin.production.orders') }}" class="hover:text-primary-500">Orders</a>
            <i class="bi bi-chevron-right text-[10px]"></i><span>Create</span>
        </div>
        <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">New Production Order</h1>
        <p class="mt-0.5 text-sm text-primary-300">Create a new garment production order in {{ $step }} steps.</p>
    </div>

    {{-- Step indicator --}}
    <div class="flex items-center gap-0">
        @foreach(['Product', 'Details', 'Tasks & Materials', 'Review'] as $i => $label)
            @php $n = $i + 1; $done = $step > $n; $active = $step === $n; @endphp
            <div class="flex items-center {{ $n < 4 ? 'flex-1' : '' }}">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold border-2 transition
                                {{ $done   ? 'bg-success-500 border-success-500 text-white' :
                                   ($active ? 'bg-primary-500 border-primary-500 text-white' :
                                              'bg-white border-primary-200 text-primary-300') }}">
                        {{ $done ? '✓' : $n }}
                    </div>
                    <span class="text-xs font-semibold {{ $active ? 'text-primary-600' : ($done ? 'text-success-600' : 'text-primary-300') }} hidden sm:block">
                        {{ $label }}
                    </span>
                </div>
                @if($n < 4)
                    <div class="flex-1 h-0.5 mx-3 {{ $done ? 'bg-success-400' : 'bg-primary-100' }} transition"></div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Step 1: Product Selection --}}
    @if($step === 1)
        <div class="bg-white rounded-2xl border border-primary-100 p-6 space-y-5 shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Search Product</label>
                <div class="relative">
                    <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
                    <input wire:model.live.debounce.200ms="productSearch" type="text"
                           placeholder="Type product name or SKU…"
                           class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-200 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-400 transition" />
                </div>

                @if($productSearch && $products->count())
                    <div class="mt-2 rounded-xl border border-primary-100 overflow-hidden shadow-sm divide-y divide-primary-50">
                        @foreach($products as $product)
                            @php $name = $product->translations->first()?->name ?? $product->sku; @endphp
                            <button wire:click="selectProduct({{ $product->id }}, '{{ addslashes($name) }}')"
                                    class="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-primary-50 transition">
                                <div class="w-9 h-9 rounded-lg bg-primary-50 border border-primary-100 flex items-center justify-center flex-shrink-0">
                                    <i class="bi bi-box text-primary-300 text-sm"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-primary-600 truncate">{{ $name }}</p>
                                    <p class="text-xs text-primary-300 font-mono">{{ $product->sku }}</p>
                                </div>
                                @if($product->variants->count())
                                    <span class="ml-auto text-xs text-primary-300 flex-shrink-0">{{ $product->variants->count() }} variants</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @elseif(strlen($productSearch) >= 2 && $products->isEmpty())
                    <p class="mt-2 text-xs text-primary-300 text-center py-4">No products found.</p>
                @endif
            </div>

            @if($productId)
                <div class="rounded-xl bg-success-50 border border-success-200 px-4 py-3 flex items-center gap-3">
                    <i class="bi bi-check-circle-fill text-success-500"></i>
                    <div>
                        <p class="text-sm font-semibold text-success-700">{{ $productName }}</p>
                        <p class="text-xs text-success-500 mt-0.5">Product selected</p>
                    </div>
                    <button wire:click="$set('productId', null); $set('productName', '')"
                            class="ml-auto text-success-400 hover:text-success-600 transition">
                        <i class="bi bi-x-circle text-sm"></i>
                    </button>
                </div>

                {{-- Variant selection if applicable --}}
                @if($products->firstWhere('id', $productId)?->variants?->count())
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Variant <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <select wire:model="variantId" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            <option value="">No specific variant</option>
                            @foreach($products->firstWhere('id', $productId)->variants as $variant)
                                <option value="{{ $variant->id }}">{{ $variant->variant_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @else
                <p class="text-sm text-center text-primary-200 py-6">
                    <i class="bi bi-search text-3xl block mb-2 text-primary-100"></i>
                    Search and select a product to continue
                </p>
            @endif

            @error('productId')<p class="text-xs text-danger-500">{{ $message }}</p>@enderror
        </div>
    @endif

    {{-- Step 2: Order Details --}}
    @if($step === 2)
        <div class="bg-white rounded-2xl border border-primary-100 p-6 space-y-5 shadow-sm">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Quantity</label>
                    <input wire:model="quantity" type="number" min="1"
                           class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    @error('quantity')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Priority</label>
                    <select wire:model="priority" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                        <option value="low">Low</option>
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Due Date</label>
                    <input wire:model="dueDate" type="date"
                           class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    @error('dueDate')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Outlet</label>
                    <select wire:model="outletId" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                        <option value="">Select outlet…</option>
                        @foreach($outlets as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
                    </select>
                    @error('outletId')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Linked Customer Order <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                <select wire:model="customerOrderId" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                    <option value="">None</option>
                    @foreach($orders as $o)<option value="{{ $o->id }}">{{ $o->order_number }} - {{ $o->customer_email }}</option>@endforeach
                </select>
            </div>

            {{-- Specifications --}}
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-2">Specifications</label>
                @if($specifications)
                    <div class="space-y-1.5 mb-2">
                        @foreach($specifications as $k => $v)
                            <div class="flex items-center gap-2 rounded-xl bg-primary-50 border border-primary-100 px-3 py-2">
                                <span class="text-xs font-semibold text-primary-500 capitalize">{{ $k }}</span>
                                <span class="text-xs text-primary-300">→</span>
                                <span class="text-xs text-primary-600">{{ $v }}</span>
                                <button wire:click="removeSpec('{{ $k }}')" class="ml-auto text-primary-200 hover:text-danger-500 transition"><i class="bi bi-x text-sm"></i></button>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="flex gap-2">
                    <input wire:model="specKey"   type="text" placeholder="e.g. chest_size"
                           class="flex-1 rounded-xl border border-primary-100 px-3 py-2 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    <input wire:model="specValue" type="text" placeholder="e.g. 42 inches"
                           class="flex-1 rounded-xl border border-primary-100 px-3 py-2 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    <button wire:click="addSpecification" class="rounded-xl bg-primary-50 border border-primary-200 px-3 py-2 text-xs font-semibold text-primary-500 hover:bg-primary-100 transition">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                <textarea wire:model="notes" rows="3" placeholder="Any special production instructions…"
                          class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
            </div>
        </div>
    @endif

    {{-- Step 3: Tasks & BOM --}}
    @if($step === 3)
        <div class="space-y-4">
            {{-- Tasks (Production Stages) --}}
            <div class="bg-white rounded-2xl border border-primary-100 p-5 shadow-sm">
                <p class="text-sm font-bold text-primary-500 mb-3">
                    <i class="bi bi-list-task mr-1.5 text-primary-400"></i>Production Tasks
                </p>
                <div class="space-y-2">
                    @foreach($tasks as $i => $task)
                        <div class="flex items-center gap-3 rounded-xl border {{ ($task['include'] ?? true) ? 'border-primary-200 bg-primary-50/40' : 'border-primary-100 bg-white opacity-50' }} px-3.5 py-3">
                            <input wire:model="tasks.{{ $i }}.include" type="checkbox"
                                   class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                            <span class="text-sm font-medium text-primary-600 w-40 flex-shrink-0">{{ $task['stage_name'] }}</span>
                            <input wire:model="tasks.{{ $i }}.estimated_hours" type="number" step="0.5" min="0"
                                   placeholder="Est. hours"
                                   class="w-28 rounded-lg border border-primary-100 px-2.5 py-1.5 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition bg-white" />
                            <input wire:model="tasks.{{ $i }}.notes" type="text" placeholder="Notes…"
                                   class="flex-1 rounded-lg border border-primary-100 px-2.5 py-1.5 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition bg-white" />
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- BOM Items --}}
            <div class="bg-white rounded-2xl border border-primary-100 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm font-bold text-primary-500">
                        <i class="bi bi-box-seam mr-1.5 text-primary-400"></i>
                        Materials Required
                        @if($bomItems) <span class="text-xs font-normal text-success-600 ml-2">Auto-loaded from BOM</span>@endif
                    </p>
                    <button wire:click="addBomItem" class="inline-flex items-center gap-1.5 rounded-xl border border-primary-200 px-3 py-1.5 text-xs font-semibold text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-plus-lg"></i> Add Material
                    </button>
                </div>
                @if($bomItems)
                    <div class="space-y-2">
                        @foreach($bomItems as $i => $item)
                            <div class="flex items-center gap-2 rounded-xl bg-primary-50/50 border border-primary-100 px-3 py-2.5">
                                <select wire:model="bomItems.{{ $i }}.material_id"
                                        class="flex-1 rounded-lg border border-primary-100 bg-white px-2.5 py-1.5 text-xs text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition">
                                    <option value="">Select material…</option>
                                    @foreach($materials as $m)
                                        <option value="{{ $m->id }}" {{ $item['material_id'] == $m->id ? 'selected' : '' }}>{{ $m->name }} ({{ $m->code }})</option>
                                    @endforeach
                                </select>
                                <input wire:model="bomItems.{{ $i }}.quantity" type="number" step="0.01" min="0.01" placeholder="Qty"
                                       class="w-20 rounded-lg border border-primary-100 bg-white px-2.5 py-1.5 text-xs text-center text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                <input wire:model="bomItems.{{ $i }}.unit_of_measure" type="text" placeholder="Unit"
                                       class="w-16 rounded-lg border border-primary-100 bg-white px-2.5 py-1.5 text-xs text-center text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                <button wire:click="removeBomItem({{ $i }})" class="text-primary-200 hover:text-danger-500 transition flex-shrink-0">
                                    <i class="bi bi-trash3 text-xs"></i>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-8 text-primary-200">
                        <i class="bi bi-box text-3xl block mb-2"></i>
                        <p class="text-sm">No materials added. Click "Add Material" or select a product with a BOM.</p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Step 4: Review --}}
    @if($step === 4)
        <div class="bg-white rounded-2xl border border-primary-100 p-6 shadow-sm space-y-5">
            <p class="text-sm font-bold text-primary-500 mb-1">Review & Confirm</p>
            <div class="grid grid-cols-2 gap-3 text-sm">
                @foreach([
                    ['Product',  $productName],
                    ['Quantity', $quantity . ' units'],
                    ['Priority', ucfirst($priority)],
                    ['Due Date', $dueDate],
                    ['Outlet',   $outlets->firstWhere('id', $outletId)?->name ?? '-'],
                ] as [$label, $val])
                    <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                        <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                        <p class="text-sm font-semibold text-primary-600 mt-0.5">{{ $val }}</p>
                    </div>
                @endforeach
            </div>
            <div class="rounded-xl bg-primary-50/50 border border-primary-100 p-4">
                <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2">Tasks</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach(array_filter($tasks, fn($t) => $t['include'] ?? true) as $task)
                        <span class="inline-flex items-center rounded-full bg-info-50 border border-info-200 px-2.5 py-0.5 text-xs font-medium text-info-700">
                            {{ $task['stage_name'] }}
                            @if($task['estimated_hours']) · {{ $task['estimated_hours'] }}h @endif
                        </span>
                    @endforeach
                </div>
            </div>
            @if($bomItems)
                <div class="rounded-xl bg-primary-50/50 border border-primary-100 p-4">
                    <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2">Materials</p>
                    <div class="space-y-1 text-xs text-primary-500">
                        @foreach($bomItems as $item)
                            @if($item['material_id'])
                                <div>{{ $materials->firstWhere('id', $item['material_id'])?->name ?? '-' }} - {{ $item['quantity'] }} {{ $item['unit_of_measure'] }}</div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Navigation buttons --}}
    <div class="flex items-center justify-between">
        <button wire:click="prevStep" @if($step === 1) disabled @endif
                class="inline-flex items-center gap-2 rounded-xl border border-primary-200 bg-white px-4 py-2.5 text-sm font-semibold text-primary-400 hover:text-primary-600 hover:border-primary-300 transition disabled:opacity-40 disabled:cursor-not-allowed">
            <i class="bi bi-arrow-left"></i> Back
        </button>
        @if($step < 4)
            <button wire:click="nextStep" @if($step === 1 && !$productId) disabled @endif
                    class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20 disabled:opacity-40 disabled:cursor-not-allowed">
                Continue <i class="bi bi-arrow-right"></i>
            </button>
        @else
            <button wire:click="save" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-xl bg-success-500 hover:bg-success-600 px-5 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-success-500/20 disabled:opacity-60">
                <span wire:loading.remove wire:target="save"><i class="bi bi-check2-circle mr-1"></i>Create Production Order</span>
                <span wire:loading wire:target="save">Creating…</span>
            </button>
        @endif
    </div>
</div>