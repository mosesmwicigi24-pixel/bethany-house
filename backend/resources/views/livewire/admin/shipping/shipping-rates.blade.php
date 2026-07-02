<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-box"></i><span>Shipping</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Shipping Rates</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Shipping Rates</h1>
            <p class="mt-0.5 text-sm text-primary-300">Review and adjust rates for every method in every zone. Click any rate to edit inline.</p>
        </div>
        <button wire:click="$set('showBulkModal', true)"
                class="inline-flex items-center gap-2 rounded-xl border border-primary-200 bg-white hover:bg-primary-50 px-4 py-2.5 text-sm font-semibold text-primary-500 transition">
            <i class="bi bi-sliders"></i> Bulk Adjust
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Zone filter --}}
    <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm w-fit">
        <button wire:click="$set('zoneFilter','')"
                class="px-3.5 py-2.5 font-medium transition border-r border-primary-100
                       {{ $zoneFilter === '' ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">All Zones</button>
        @foreach($allZones as $z)
            <button wire:click="$set('zoneFilter','{{ $z->id }}')"
                    class="px-3.5 py-2.5 font-medium transition border-r border-primary-100 last:border-r-0
                           {{ $zoneFilter == $z->id ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $z->name }}</button>
        @endforeach
    </div>

    {{-- Rates tables grouped by zone --}}
    @forelse($zones as $zone)
        <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
            {{-- Zone header --}}
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-primary-100 bg-primary-50/40">
                <div class="flex items-center gap-3">
                    <i class="bi bi-globe text-primary-400"></i>
                    <h2 class="text-sm font-bold text-primary-600">{{ $zone->name }}</h2>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold {{ $zone->is_active ? 'bg-success-50 text-success-700 border border-success-200' : 'bg-primary-50 text-primary-400 border border-primary-100' }}">
                        {{ $zone->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>
                <span class="text-xs text-primary-400">{{ $zone->methods->count() }} method(s)</span>
            </div>

            @if($zone->methods->isEmpty())
                <div class="px-5 py-8 text-center text-sm text-primary-200">
                    <i class="bi bi-truck text-2xl text-primary-100 block mb-2"></i>
                    No shipping methods in this zone.
                    <a href="{{ route('shipping.methods') }}" class="text-primary-400 hover:text-primary-600 font-semibold ml-1">Add one →</a>
                </div>
            @else
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-primary-100">
                            <th class="px-5 py-3 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Method</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Delivery Time</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Cost Type</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Rate (KES)</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Min Order</th>
                            <th class="px-5 py-3 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary-50">
                        @foreach($zone->methods as $method)
                            @php $isEditingThis = $editingMethodId === $method->id; @endphp
                            <tr class="hover:bg-primary-50/30 transition-colors {{ $isEditingThis ? 'bg-primary-50/60 ring-2 ring-inset ring-primary-300' : '' }}">
                                <td class="px-5 py-3.5">
                                    <p class="font-semibold text-primary-600 text-sm">{{ $method->name }}</p>
                                    @if($method->description)
                                        <p class="text-xs text-primary-300 mt-0.5">{{ $method->description }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-sm text-primary-400">{{ $method->delivery_time ?: '-' }}</td>
                                <td class="px-5 py-3.5 text-center">
                                    @if($isEditingThis)
                                        <select wire:model="pendingEdits.{{ $method->id }}.cost_type"
                                                class="rounded-lg border border-primary-200 px-2 py-1 text-xs text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition bg-white">
                                            @foreach(['flat_rate','free','percentage','weight_based'] as $ct)
                                                <option value="{{ $ct }}">{{ ucfirst(str_replace('_',' ',$ct)) }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold
                                              @if($method->cost_type==='free') bg-success-50 text-success-700 border-success-200
                                              @elseif($method->cost_type==='flat_rate') bg-primary-50 text-primary-500 border-primary-200
                                              @else bg-info-50 text-info-700 border-info-200
                                              @endif">
                                            {{ ucfirst(str_replace('_',' ',$method->cost_type)) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    @if($isEditingThis)
                                        <input wire:model="pendingEdits.{{ $method->id }}.flat_rate"
                                               type="number" min="0" step="0.01"
                                               class="w-28 rounded-lg border border-primary-200 px-2.5 py-1.5 text-xs text-right tabular-nums text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                    @else
                                        <span class="font-semibold text-primary-600 tabular-nums">
                                            @if($method->cost_type==='free') Free
                                            @elseif($method->flat_rate) {{ number_format($method->flat_rate,2) }}
                                            @else -
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    @if($isEditingThis)
                                        <input wire:model="pendingEdits.{{ $method->id }}.min_order_amount"
                                               type="number" min="0" step="0.01" placeholder="None"
                                               class="w-28 rounded-lg border border-primary-200 px-2.5 py-1.5 text-xs text-right tabular-nums text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                                    @else
                                        <span class="text-sm text-primary-400 tabular-nums">
                                            {{ $method->min_order_amount ? number_format($method->min_order_amount,2) : '-' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-center">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                          {{ $method->is_active ? 'bg-success-50 text-success-700 border border-success-200' : 'bg-primary-50 text-primary-400 border border-primary-100' }}">
                                        {{ $method->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    @if($isEditingThis)
                                        <div class="flex items-center justify-end gap-1">
                                            <button wire:click="saveRate({{ $method->id }})" wire:loading.attr="disabled"
                                                    class="inline-flex items-center gap-1 rounded-lg bg-success-500 hover:bg-success-600 px-2.5 py-1.5 text-xs font-semibold text-white transition disabled:opacity-60">
                                                <i class="bi bi-check2"></i> Save
                                            </button>
                                            <button wire:click="cancelEdit"
                                                    class="inline-flex items-center gap-1 rounded-lg border border-primary-200 bg-white hover:bg-primary-50 px-2.5 py-1.5 text-xs font-semibold text-primary-400 transition">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    @else
                                        <button wire:click="startEdit({{ $method->id }})"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-primary-100 hover:border-primary-300 bg-white hover:bg-primary-50 px-2.5 py-1.5 text-xs font-semibold text-primary-400 hover:text-primary-600 transition">
                                            <i class="bi bi-pencil text-xs"></i> Edit Rate
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @empty
        <div class="py-20 text-center bg-white rounded-2xl border border-primary-100 shadow-sm">
            <i class="bi bi-currency-exchange text-4xl text-primary-100 block mb-3"></i>
            <p class="text-sm font-medium text-primary-300">No shipping zones found.</p>
            <a href="{{ route('admin.shipping.zones') }}" class="mt-3 inline-block text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Set up shipping zones first →</a>
        </div>
    @endforelse

    {{-- ═══ BULK ADJUST MODAL ═══ --}}
    @if($showBulkModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showBulkModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showBulkModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Bulk Rate Adjustment</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Adjust all flat-rate methods in a zone at once.</p>
                    </div>
                    <button wire:click="$set('showBulkModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Zone <span class="text-danger-500">*</span></label>
                        <select wire:model="bulkZoneId" class="w-full border {{ $errors->has('bulkZoneId') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            <option value="">Select zone…</option>
                            @foreach($allZones as $z)<option value="{{ $z->id }}">{{ $z->name }}</option>@endforeach
                        </select>
                        @error('bulkZoneId')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Adjustment Type</label>
                        <div class="grid grid-cols-2 gap-2">
                            <button wire:click="$set('bulkAdjustType','fixed')"
                                    class="py-2.5 rounded-xl border text-xs font-semibold transition
                                           {{ $bulkAdjustType==='fixed' ? 'bg-primary-500 text-white border-primary-500' : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
                                Fixed (KES)
                            </button>
                            <button wire:click="$set('bulkAdjustType','percentage')"
                                    class="py-2.5 rounded-xl border text-xs font-semibold transition
                                           {{ $bulkAdjustType==='percentage' ? 'bg-primary-500 text-white border-primary-500' : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
                                Percentage (%)
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                            Value
                            <span class="font-normal normal-case text-primary-300">
                                (use negative to decrease, e.g. -50 or -10)
                            </span>
                        </label>
                        <div class="relative">
                            <input wire:model="bulkValue" type="number" step="0.01" placeholder="{{ $bulkAdjustType==='percentage' ? 'e.g. 10 or -10' : 'e.g. 50 or -50' }}"
                                   class="w-full border {{ $errors->has('bulkValue') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 pr-10 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            <span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-primary-300">{{ $bulkAdjustType==='percentage' ? '%' : 'KES' }}</span>
                        </div>
                        @error('bulkValue')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="rounded-xl bg-warning-50 border border-warning-200 px-4 py-3 text-xs text-warning-700">
                        <i class="bi bi-exclamation-triangle-fill mr-1"></i>
                        This adjusts all <strong>flat-rate</strong> methods in the selected zone. Free and weight-based methods are unaffected.
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showBulkModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="applyBulkAdjust" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="applyBulkAdjust"><i class="bi bi-sliders mr-1"></i>Apply</span>
                        <span wire:loading wire:target="applyBulkAdjust">Applying…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>