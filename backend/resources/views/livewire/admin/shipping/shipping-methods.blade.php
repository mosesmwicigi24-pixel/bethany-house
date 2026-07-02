<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-box"></i><span>Shipping</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Shipping Methods</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Shipping Methods</h1>
            <p class="mt-0.5 text-sm text-primary-300">Define how orders are delivered within each shipping zone.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> Add Method
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @php $cards = [
            ['Total',         $summary['total'],        'bi-truck',       'border-primary-100',  'bg-primary-50',  'text-primary-400',  'text-primary-600'],
            ['Active',        $summary['active'],       'bi-check-circle','border-success-200',  'bg-success-50',  'text-success-500',  'text-success-700'],
            ['Free Shipping', $summary['free_shipping'],'bi-gift',        'border-info-200',     'bg-info-50',     'text-info-500',     'text-info-700'],
            ['Inactive',      $summary['inactive'],     'bi-pause-circle','border-warning-200',  'bg-warning-50',  'text-warning-500',  'text-warning-700'],
        ]; @endphp
        @foreach($cards as [$label,$value,$icon,$border,$ibg,$ic,$vc])
            <div class="relative overflow-hidden bg-white rounded-2xl border {{ $border }} p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl {{ $ibg }} border {{ $border }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $icon }} {{ $ic }} text-lg"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                    <p class="text-xl font-bold {{ $vc }} mt-0.5 tabular-nums">{{ $value }}</p>
                </div>
                <div class="absolute -right-2 -bottom-2 w-12 h-12 rounded-full {{ $ibg }} opacity-50"></div>
            </div>
        @endforeach
    </div>

    {{-- Zone filter --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            <button wire:click="$set('zoneFilter','')"
                    class="px-3.5 py-2.5 font-medium transition border-r border-primary-100
                           {{ $zoneFilter === '' ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">All Zones</button>
            @foreach($zones as $zone)
                <button wire:click="$set('zoneFilter','{{ $zone->id }}')"
                        class="px-3.5 py-2.5 font-medium transition border-r border-primary-100 last:border-r-0
                               {{ $zoneFilter == $zone->id ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $zone->name }}</button>
            @endforeach
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'] as $v => $l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
    </div>

    {{-- Grouped by zone --}}
    @forelse($methods as $zoneId => $zoneMethods)
        @php $zone = $zones->firstWhere('id', $zoneId); @endphp
        <div class="space-y-3">
            <div class="flex items-center gap-3">
                <h2 class="text-xs font-bold text-primary-400 uppercase tracking-widest">{{ $zone?->name ?? 'Unknown Zone' }}</h2>
                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-primary-100 text-primary-500 text-[10px] font-bold">{{ $zoneMethods->count() }}</span>
                <div class="flex-1 h-px bg-primary-100"></div>
            </div>

            <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm divide-y divide-primary-50">
                @foreach($zoneMethods as $method)
                    @php
                        $typeConfig = match($method->cost_type) {
                            'flat_rate'    => ['KES '.number_format($method->flat_rate,2), 'bg-primary-50 text-primary-500 border-primary-200'],
                            'free'         => ['Free', 'bg-success-50 text-success-700 border-success-200'],
                            'percentage'   => [$method->flat_rate.'%', 'bg-info-50 text-info-700 border-info-200'],
                            'weight_based' => ['Weight-based', 'bg-secondary-50 text-secondary-700 border-secondary-200'],
                            default        => ['-', 'bg-primary-50 text-primary-300 border-primary-100'],
                        };
                    @endphp
                    <div class="flex items-center gap-4 px-5 py-4 hover:bg-primary-50/30 transition-colors group">
                        {{-- Sort controls --}}
                        <div class="flex flex-col gap-0.5 flex-shrink-0">
                            <button wire:click="moveUp({{ $method->id }})" class="w-5 h-5 flex items-center justify-center rounded text-primary-200 hover:text-primary-500 transition">
                                <i class="bi bi-chevron-up text-xs"></i>
                            </button>
                            <button wire:click="moveDown({{ $method->id }})" class="w-5 h-5 flex items-center justify-center rounded text-primary-200 hover:text-primary-500 transition">
                                <i class="bi bi-chevron-down text-xs"></i>
                            </button>
                        </div>
                        {{-- Icon --}}
                        <div class="w-10 h-10 rounded-xl bg-primary-50 border border-primary-100 flex items-center justify-center flex-shrink-0">
                            <i class="bi {{ $method->cost_type === 'free' ? 'bi-gift' : 'bi-truck' }} text-primary-400 text-lg"></i>
                        </div>
                        {{-- Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <p class="font-bold text-primary-600 text-sm">{{ $method->name }}</p>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $typeConfig[1] }}">{{ $typeConfig[0] }}</span>
                            </div>
                            <div class="flex items-center gap-4 mt-0.5 text-xs text-primary-400">
                                @if($method->description)<span>{{ $method->description }}</span>@endif
                                @if($method->delivery_time)<span><i class="bi bi-clock mr-1"></i>{{ $method->delivery_time }}</span>@endif
                                @if($method->min_order_amount)<span><i class="bi bi-cart-check mr-1"></i>Min: KES {{ number_format($method->min_order_amount,2) }}</span>@endif
                            </div>
                        </div>
                        {{-- Actions --}}
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <button wire:click="toggleActive({{ $method->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition
                                           {{ $method->is_active ? 'bg-success-50 border-success-200 text-success-700 hover:bg-success-100' : 'bg-primary-50 border-primary-200 text-primary-400 hover:bg-primary-100' }}">
                                <i class="bi {{ $method->is_active ? 'bi-toggle-on text-success-500' : 'bi-toggle-off text-primary-300' }} text-base"></i>
                                {{ $method->is_active ? 'Active' : 'Inactive' }}
                            </button>
                            <button wire:click="openEdit({{ $method->id }})"
                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Edit">
                                <i class="bi bi-pencil text-sm"></i>
                            </button>
                            <button wire:click="confirmDelete({{ $method->id }})"
                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition" title="Delete">
                                <i class="bi bi-trash3 text-sm"></i>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="py-20 text-center bg-white rounded-2xl border border-primary-100 shadow-sm">
            <i class="bi bi-truck text-4xl text-primary-100 block mb-3"></i>
            <p class="text-sm font-medium text-primary-300">No shipping methods found.</p>
            <p class="text-xs text-primary-200 mt-1">Create shipping zones first, then add methods to them.</p>
            <button wire:click="openCreate" class="mt-3 text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Add first method →</button>
        </div>
    @endforelse

    {{-- ═══ CREATE / EDIT MODAL ═══ --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal',false)"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Method' : 'New Shipping Method' }}</h2>
                    <button wire:click="$set('showModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="px-6 py-5 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Method Name <span class="text-danger-500">*</span></label>
                            <input wire:model="name" type="text" placeholder="e.g. Standard Delivery"
                                   class="w-full border {{ $errors->has('name') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('name')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Shipping Zone <span class="text-danger-500">*</span></label>
                            <select wire:model="shippingZoneId" class="w-full border {{ $errors->has('shippingZoneId') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="">Select zone…</option>
                                @foreach($zones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                            </select>
                            @error('shippingZoneId')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Cost Type <span class="text-danger-500">*</span></label>
                            <select wire:model.live="costType" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                @foreach($costTypes as $ct)<option value="{{ $ct }}">{{ ucfirst(str_replace('_',' ',$ct)) }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                                @if($costType === 'free') Cost (auto = 0) @elseif($costType === 'percentage') Rate (%) @else Flat Rate (KES) @endif
                            </label>
                            <input wire:model="flatRate" type="number" min="0" step="0.01"
                                   :disabled="{{ $costType === 'free' ? 'true' : 'false' }}"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition disabled:opacity-40" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Delivery Time</label>
                            <input wire:model="deliveryTime" type="text" placeholder="e.g. 2–3 business days"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Min Order (KES)</label>
                            <input wire:model="minOrderAmount" type="number" min="0" step="0.01" placeholder="No minimum"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Description</label>
                            <input wire:model="description" type="text" placeholder="Optional short description"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Sort Order</label>
                            <input wire:model="sortOrder" type="number" min="0"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div class="flex items-end pb-2.5">
                            <label class="flex items-center gap-2.5 cursor-pointer">
                                <input wire:model="isActive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                                <span class="text-sm font-medium text-primary-500">Active</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update' : 'Create' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ DELETE CONFIRM ═══ --}}
    @if($showDeleteModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showDeleteModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showDeleteModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="px-6 py-6 text-center space-y-3">
                    <div class="w-14 h-14 rounded-full bg-danger-50 border border-danger-200 flex items-center justify-center mx-auto">
                        <i class="bi bi-truck text-danger-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Delete Method?</h2>
                    <p class="text-sm text-primary-400"><span class="font-semibold text-primary-600">{{ $deletingName }}</span> will be permanently removed.</p>
                </div>
                <div class="flex items-center justify-center gap-3 px-6 pb-6">
                    <button wire:click="$set('showDeleteModal',false)" class="flex-1 rounded-xl border border-primary-100 bg-white px-4 py-2.5 text-sm font-semibold text-primary-400 transition">Cancel</button>
                    <button wire:click="delete" wire:loading.attr="disabled"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-danger-500 hover:bg-danger-600 px-4 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60">
                        <span wire:loading.remove wire:target="delete">Delete</span>
                        <span wire:loading wire:target="delete">Deleting…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>