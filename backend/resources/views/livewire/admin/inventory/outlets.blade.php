<div class="space-y-6">

    {{-- Page Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-boxes"></i>
                <span>Inventory</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Outlets</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Outlets</h1>
            <p class="mt-0.5 text-sm text-primary-300">Manage stores, warehouses, and pickup locations.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i>
            Add Outlet
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name, code or city…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="typeFilter"
                class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Types</option>
            @foreach($outletTypes as $type)
                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
            @endforeach
        </select>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            <button wire:click="$set('statusFilter', '')"
                    class="px-3.5 py-2.5 font-medium transition {{ $statusFilter === '' ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                All
            </button>
            <button wire:click="$set('statusFilter', 'active')"
                    class="px-3.5 py-2.5 font-medium border-l border-primary-100 transition {{ $statusFilter === 'active' ? 'bg-success-500 text-white' : 'text-primary-400 hover:text-success-600' }}">
                Active
            </button>
            <button wire:click="$set('statusFilter', 'inactive')"
                    class="px-3.5 py-2.5 font-medium border-l border-primary-100 transition {{ $statusFilter === 'inactive' ? 'bg-primary-300 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                Inactive
            </button>
        </div>
    </div>

    {{-- Outlet Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($outlets as $outlet)
            @php
                $typeConfig = match($outlet->outlet_type) {
                    'store'     => ['icon' => 'bi-shop',       'bg' => 'bg-secondary-50',  'text' => 'text-secondary-700',  'border' => 'border-secondary-200'],
                    'warehouse' => ['icon' => 'bi-building',   'bg' => 'bg-info-50',       'text' => 'text-info-700',       'border' => 'border-info-200'],
                    'online'    => ['icon' => 'bi-globe2',     'bg' => 'bg-success-50',    'text' => 'text-success-700',    'border' => 'border-success-200'],
                    'popup'     => ['icon' => 'bi-geo-alt',    'bg' => 'bg-warning-50',    'text' => 'text-warning-700',    'border' => 'border-warning-200'],
                    default     => ['icon' => 'bi-shop',       'bg' => 'bg-primary-50',    'text' => 'text-primary-400',    'border' => 'border-primary-100'],
                };
            @endphp
            <div class="group bg-white rounded-2xl border {{ $outlet->is_active ? 'border-primary-100' : 'border-primary-50 opacity-70' }} hover:border-primary-200 hover:shadow-md transition-all duration-200 flex flex-col">

                {{-- Card Top --}}
                <div class="p-5 flex-1">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl {{ $typeConfig['bg'] }} {{ $typeConfig['border'] }} border flex items-center justify-center flex-shrink-0">
                                <i class="bi {{ $typeConfig['icon'] }} {{ $typeConfig['text'] }} text-lg"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-primary-600 leading-tight">{{ $outlet->name }}</h3>
                                <code class="text-xs font-mono text-primary-300">{{ $outlet->code }}</code>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            @if($outlet->is_pickup_location)
                                <span title="Pickup Location"
                                      class="w-6 h-6 flex items-center justify-center rounded-lg bg-secondary-50 border border-secondary-200 text-secondary-600">
                                    <i class="bi bi-bag-check text-xs"></i>
                                </span>
                            @endif
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $outlet->is_active ? 'bg-success-50 text-success-700 border border-success-200' : 'bg-primary-50 text-primary-300 border border-primary-100' }}">
                                {{ $outlet->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>

                    {{-- Details --}}
                    <div class="space-y-1.5 text-sm text-primary-400">
                        @if($outlet->city || $outlet->state_province)
                            <div class="flex items-center gap-2">
                                <i class="bi bi-geo-alt text-primary-200 text-xs w-3.5 flex-shrink-0"></i>
                                <span>{{ collect([$outlet->city, $outlet->state_province, $outlet->country_code])->filter()->implode(', ') }}</span>
                            </div>
                        @endif
                        @if($outlet->phone)
                            <div class="flex items-center gap-2">
                                <i class="bi bi-telephone text-primary-200 text-xs w-3.5 flex-shrink-0"></i>
                                <span>{{ $outlet->phone }}</span>
                            </div>
                        @endif
                        @if($outlet->email)
                            <div class="flex items-center gap-2">
                                <i class="bi bi-envelope text-primary-200 text-xs w-3.5 flex-shrink-0"></i>
                                <span class="truncate">{{ $outlet->email }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Card Footer --}}
                <div class="px-5 py-3.5 border-t border-primary-50 flex items-center justify-between">
                    <div class="flex items-center gap-1.5">
                        <i class="bi bi-boxes text-primary-200 text-xs"></i>
                        <span class="text-xs text-primary-300">
                            <span class="font-semibold text-primary-500">{{ $outlet->inventory_items_count }}</span> items
                        </span>
                        @if($outlet->outlet_type !== 'online')
                            <span class="text-primary-100 mx-1">·</span>
                            <span class="text-xs capitalize px-2 py-0.5 rounded-full {{ $typeConfig['bg'] }} {{ $typeConfig['text'] }}">
                                {{ $outlet->outlet_type }}
                            </span>
                        @endif
                    </div>

                    <div class="flex items-center gap-1">
                        @if($outlet->operating_hours)
                            <button wire:click="viewHours({{ $outlet->id }})"
                                    title="Operating Hours"
                                    class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-200 hover:text-primary-500 hover:bg-primary-50 transition">
                                <i class="bi bi-clock text-xs"></i>
                            </button>
                        @endif
                        <button wire:click="toggleStatus({{ $outlet->id }})"
                                title="{{ $outlet->is_active ? 'Deactivate' : 'Activate' }}"
                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg transition {{ $outlet->is_active ? 'text-success-400 hover:text-success-600 hover:bg-success-50' : 'text-primary-200 hover:text-primary-500 hover:bg-primary-50' }}">
                            <i class="bi {{ $outlet->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} text-base"></i>
                        </button>
                        <button wire:click="openEdit({{ $outlet->id }})"
                                title="Edit"
                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-200 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-pencil text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-3 py-20 text-center">
                <div class="w-16 h-16 rounded-2xl bg-primary-50 border border-primary-100 flex items-center justify-center mx-auto mb-4">
                    <i class="bi bi-shop text-2xl text-primary-200"></i>
                </div>
                <p class="text-sm font-semibold text-primary-400">No outlets found.</p>
                <p class="text-xs text-primary-200 mt-1">Create your first outlet to get started.</p>
            </div>
        @endforelse
    </div>

    @if($outlets->hasPages())
        <div>{{ $outlets->links() }}</div>
    @endif

    {{-- Create / Edit Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal', false)"></div>
            <div class="relative w-full max-w-xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-4">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Outlet' : 'New Outlet' }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5">{{ $isEditing ? 'Update outlet information.' : 'Register a new outlet location.' }}</p>
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
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 uppercase tracking-wider focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('code') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Type</label>
                            <select wire:model="outletType"
                                    class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                @foreach($outletTypes as $type)
                                    <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Name</label>
                        <input wire:model="name" type="text"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('name') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Email</label>
                            <input wire:model="email" type="email"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('email') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Phone</label>
                            <input wire:model="phone" type="text"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>

                    <div class="p-4 rounded-xl bg-primary-50/50 border border-primary-100 space-y-3">
                        <p class="text-xs font-semibold text-primary-400 uppercase tracking-wide">Address</p>
                        <input wire:model="addressLine1" type="text" placeholder="Address line 1"
                               class="w-full rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        <input wire:model="addressLine2" type="text" placeholder="Address line 2 (optional)"
                               class="w-full rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        <div class="grid grid-cols-3 gap-3">
                            <input wire:model="city" type="text" placeholder="City"
                                   class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            <input wire:model="stateProvince" type="text" placeholder="State"
                                   class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            <input wire:model="postalCode" type="text" placeholder="Postal"
                                   class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <input wire:model="countryCode" type="text" maxlength="2" placeholder="Country code (e.g. KE)"
                               class="w-32 rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 uppercase placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('countryCode') <p class="text-xs text-danger-500">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center gap-6">
                        <label class="flex items-center gap-2.5 cursor-pointer">
                            <input wire:model="isActive" type="checkbox"
                                   class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                            <span class="text-sm font-medium text-primary-500">Active outlet</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer">
                            <input wire:model="isPickupLocation" type="checkbox"
                                   class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                            <span class="text-sm font-medium text-primary-500">Pickup location</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Cancel
                    </button>
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update Outlet' : 'Create Outlet' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Operating Hours Modal --}}
    @if($showHoursModal && $viewingOutlet)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showHoursModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showHoursModal', false)"></div>
            <div class="relative w-full max-w-xs rounded-2xl bg-white shadow-2xl shadow-primary-900/20">

                <div class="flex items-center justify-between px-5 py-4 border-b border-primary-100">
                    <div>
                        <h2 class="text-sm font-bold text-primary-500">Operating Hours</h2>
                        <p class="text-xs text-primary-300 mt-0.5">{{ $viewingOutlet->name }}</p>
                    </div>
                    <button wire:click="$set('showHoursModal', false)"
                            class="w-7 h-7 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-xs"></i>
                    </button>
                </div>

                <div class="px-5 py-4">
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-primary-50">
                            @foreach(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day)
                                @php $hours = $viewingOutlet->operating_hours[$day] ?? null; @endphp
                                <tr>
                                    <td class="py-2.5 font-medium capitalize text-primary-500 text-sm">{{ $day }}</td>
                                    <td class="py-2.5 text-right text-sm">
                                        @if($hours && isset($hours['open'], $hours['close']))
                                            <span class="text-primary-600 font-medium">{{ $hours['open'] }} – {{ $hours['close'] }}</span>
                                        @else
                                            <span class="text-primary-200">Closed</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end px-5 py-4 border-t border-primary-50 bg-primary-50/30">
                    <button wire:click="$set('showHoursModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>