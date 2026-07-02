<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-box"></i><span>Shipping</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Shipping Zones</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Shipping Zones</h1>
            <p class="mt-0.5 text-sm text-primary-300">Group countries into zones and assign shipping methods to each.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> New Zone
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Zones grid --}}
    @if($zones->isEmpty())
        <div class="py-20 text-center bg-white rounded-2xl border border-primary-100 shadow-sm">
            <i class="bi bi-globe text-4xl text-primary-100 block mb-3"></i>
            <p class="text-sm font-medium text-primary-300">No shipping zones configured yet.</p>
            <button wire:click="openCreate" class="mt-3 text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Create your first zone →</button>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($zones as $zone)
                @php
                    $activeMethodCount = $zone->methods->where('is_active', true)->count();
                @endphp
                <div class="group bg-white rounded-2xl border {{ $zone->is_active ? 'border-primary-100' : 'border-primary-100 opacity-70' }} hover:border-primary-200 hover:shadow-md transition-all duration-200 overflow-hidden flex flex-col">

                    {{-- Card header --}}
                    <div class="px-5 py-4 flex items-start justify-between gap-3 border-b border-primary-50">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-xl bg-primary-50 border border-primary-100 flex items-center justify-center flex-shrink-0">
                                <i class="bi bi-globe text-primary-400 text-lg"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="font-bold text-primary-600 text-sm truncate">{{ $zone->name }}</p>
                                @if($zone->description)
                                    <p class="text-xs text-primary-300 mt-0.5 truncate">{{ $zone->description }}</p>
                                @endif
                            </div>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold flex-shrink-0
                              {{ $zone->is_active ? 'bg-success-50 text-success-700 border border-success-200' : 'bg-primary-50 text-primary-400 border border-primary-100' }}">
                            {{ $zone->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>

                    {{-- Stats --}}
                    <div class="px-5 py-3.5 grid grid-cols-3 gap-3 text-center border-b border-primary-50">
                        <div>
                            <p class="text-lg font-bold text-primary-600 tabular-nums">{{ $zone->countries_count }}</p>
                            <p class="text-[11px] text-primary-300 uppercase tracking-wide font-semibold mt-0.5">Countries</p>
                        </div>
                        <div>
                            <p class="text-lg font-bold text-primary-600 tabular-nums">{{ $zone->methods_count }}</p>
                            <p class="text-[11px] text-primary-300 uppercase tracking-wide font-semibold mt-0.5">Methods</p>
                        </div>
                        <div>
                            <p class="text-lg font-bold text-success-700 tabular-nums">{{ $activeMethodCount }}</p>
                            <p class="text-[11px] text-primary-300 uppercase tracking-wide font-semibold mt-0.5">Active</p>
                        </div>
                    </div>

                    {{-- Methods preview --}}
                    @if($zone->methods->count())
                        <div class="px-5 py-3 space-y-1.5 flex-1">
                            @foreach($zone->methods->take(3) as $method)
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-primary-500 font-medium truncate">{{ $method->name }}</span>
                                    <span class="text-primary-400 tabular-nums flex-shrink-0 ml-2">
                                        @if($method->cost_type === 'free') Free
                                        @elseif($method->cost_type === 'flat_rate') KES {{ number_format($method->flat_rate,2) }}
                                        @else {{ ucfirst(str_replace('_',' ',$method->cost_type)) }}
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                            @if($zone->methods->count() > 3)
                                <p class="text-[11px] text-primary-300">+{{ $zone->methods->count()-3 }} more methods</p>
                            @endif
                        </div>
                    @else
                        <div class="px-5 py-3 flex-1">
                            <p class="text-xs text-primary-200 italic">No methods yet</p>
                        </div>
                    @endif

                    {{-- Actions --}}
                    <div class="px-5 py-3 border-t border-primary-50 flex items-center justify-between">
                        <button wire:click="viewZone({{ $zone->id }})"
                                class="text-xs font-semibold text-primary-400 hover:text-primary-600 transition">
                            <i class="bi bi-eye mr-1"></i>View Countries
                        </button>
                        <div class="flex items-center gap-1">
                            <button wire:click="toggleActive({{ $zone->id }})"
                                    class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition
                                           {{ $zone->is_active ? 'bg-success-50 border-success-200 text-success-700 hover:bg-success-100' : 'bg-primary-50 border-primary-200 text-primary-400 hover:bg-primary-100' }}">
                                <i class="bi {{ $zone->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} text-sm"></i>
                            </button>
                            <button wire:click="openEdit({{ $zone->id }})"
                                    class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition">
                                <i class="bi bi-pencil text-xs"></i>
                            </button>
                            <button wire:click="confirmDelete({{ $zone->id }})"
                                    class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition">
                                <i class="bi bi-trash3 text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ═══ DETAIL SLIDE-OVER (countries list) ═══ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-md bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $viewing->name }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5">{{ $viewing->countries_count }} countries · {{ $viewing->methods_count }} methods</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="openEdit({{ $viewing->id }})"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-primary-100 px-3 py-1.5 text-xs font-semibold text-primary-400 hover:text-primary-600 hover:border-primary-300 transition">
                            <i class="bi bi-pencil text-xs"></i> Edit
                        </button>
                        <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    @if($viewing->description)
                        <p class="text-sm text-primary-400">{{ $viewing->description }}</p>
                    @endif

                    {{-- Countries --}}
                    <div>
                        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">
                            <i class="bi bi-globe mr-1"></i>Countries ({{ $viewing->countries->count() }})
                        </p>
                        @if($viewing->countries->count())
                            <div class="grid grid-cols-2 gap-1.5">
                                @foreach($viewing->countries->sortBy('name') as $country)
                                    <div class="flex items-center gap-2 rounded-lg bg-primary-50 border border-primary-100 px-2.5 py-1.5">
                                        <span class="text-xs font-mono font-bold text-primary-400">{{ $country->code }}</span>
                                        <span class="text-xs text-primary-600 truncate">{{ $country->name }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-primary-200 italic">No countries assigned yet.</p>
                        @endif
                    </div>

                    {{-- Methods --}}
                    @if($viewing->methods->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">
                                <i class="bi bi-truck mr-1"></i>Shipping Methods
                            </p>
                            <div class="space-y-2">
                                @foreach($viewing->methods as $method)
                                    <div class="flex items-center justify-between rounded-xl bg-primary-50/50 border border-primary-100 px-3.5 py-3">
                                        <div>
                                            <p class="text-sm font-semibold text-primary-600">{{ $method->name }}</p>
                                            @if($method->delivery_time)
                                                <p class="text-xs text-primary-400 mt-0.5"><i class="bi bi-clock mr-1"></i>{{ $method->delivery_time }}</p>
                                            @endif
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm font-bold text-primary-600 tabular-nums">
                                                @if($method->cost_type==='free') Free
                                                @elseif($method->cost_type==='flat_rate') KES {{ number_format($method->flat_rate,2) }}
                                                @else {{ ucfirst(str_replace('_',' ',$method->cost_type)) }}
                                                @endif
                                            </p>
                                            <span class="text-[10px] {{ $method->is_active ? 'text-success-600' : 'text-primary-300' }} font-semibold">{{ $method->is_active ? 'Active' : 'Inactive' }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ CREATE / EDIT MODAL ═══ --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal',false)"></div>
            <div class="relative w-full max-w-xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-6">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Zone' : 'New Shipping Zone' }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Assign countries that belong to this delivery zone.</p>
                    </div>
                    <button wire:click="$set('showModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="px-6 py-5 space-y-4">
                    {{-- Name + active --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Zone Name <span class="text-danger-500">*</span></label>
                            <input wire:model="name" type="text" placeholder="e.g. East Africa"
                                   class="w-full border {{ $errors->has('name') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('name')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Description</label>
                            <input wire:model="description" type="text" placeholder="Optional notes"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>

                    {{-- Country picker --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-semibold text-primary-400 uppercase tracking-wide">Countries</label>
                            @if(count($selectedCountries))
                                <span class="text-xs font-semibold text-primary-500">{{ count($selectedCountries) }} selected</span>
                            @endif
                        </div>
                        <div class="relative mb-2">
                            <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
                            <input wire:model.live.debounce.200ms="countrySearch" type="text" placeholder="Filter countries…"
                                   class="w-full pl-9 pr-4 py-2 rounded-xl border border-primary-100 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        {{-- Selected tags --}}
                        @if(count($selectedCountries))
                            <div class="flex flex-wrap gap-1.5 mb-2">
                                @foreach($selectedCountries as $code)
                                    <button wire:click="toggleCountry('{{ $code }}')"
                                            class="inline-flex items-center gap-1.5 rounded-full bg-primary-500 text-white text-[11px] font-semibold px-2.5 py-1 hover:bg-primary-600 transition">
                                        {{ $code }} <i class="bi bi-x text-xs"></i>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        <div class="h-52 overflow-y-auto rounded-xl border border-primary-100 bg-primary-50/30 divide-y divide-primary-50">
                            @forelse($filteredCountries as $country)
                                @php $selected = in_array($country->code, $selectedCountries); @endphp
                                <button wire:click="toggleCountry('{{ $country->code }}')"
                                        class="w-full flex items-center gap-3 px-3.5 py-2.5 text-left hover:bg-primary-50 transition text-sm
                                               {{ $selected ? 'bg-primary-50' : '' }}">
                                    <div class="w-5 h-5 rounded border {{ $selected ? 'bg-primary-500 border-primary-500' : 'border-primary-200 bg-white' }} flex items-center justify-center flex-shrink-0 transition">
                                        @if($selected)<i class="bi bi-check text-white text-xs"></i>@endif
                                    </div>
                                    <span class="font-mono text-xs text-primary-400 flex-shrink-0 w-8">{{ $country->code }}</span>
                                    <span class="text-primary-600 truncate">{{ $country->name }}</span>
                                </button>
                            @empty
                                <p class="text-xs text-primary-300 px-4 py-6 text-center">No countries match your search.</p>
                            @endforelse
                        </div>
                    </div>

                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input wire:model="isActive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                        <span class="text-sm font-medium text-primary-500">Active zone</span>
                    </label>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update Zone' : 'Create Zone' }}</span>
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
                        <i class="bi bi-globe text-danger-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Delete Zone?</h2>
                    <p class="text-sm text-primary-400">
                        <span class="font-semibold text-primary-600">{{ $deletingName }}</span> and all its shipping methods will be permanently removed.
                    </p>
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