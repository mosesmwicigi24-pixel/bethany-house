<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-megaphone"></i><span>Marketing</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Promotions</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Promotions</h1>
            <p class="mt-0.5 text-sm text-primary-300">Automatic discounts and special offers applied at checkout.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> New Promotion
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary strip --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        @php $cards = [
            ['Total',     $summary['total'],     'bi-megaphone',     'border-primary-100',  'bg-primary-50',  'text-primary-400',  'text-primary-600'],
            ['Running',   $summary['running'],   'bi-play-circle',   'border-success-200',  'bg-success-50',  'text-success-500',  'text-success-700'],
            ['Scheduled', $summary['scheduled'], 'bi-calendar-event','border-info-200',     'bg-info-50',     'text-info-500',     'text-info-700'],
            ['Expired',   $summary['expired'],   'bi-clock-history', 'border-warning-200',  'bg-warning-50',  'text-warning-500',  'text-warning-700'],
            ['Total Uses',number_format($summary['total_uses']),'bi-graph-up','border-secondary-200','bg-secondary-50','text-secondary-600','text-secondary-700'],
        ]; @endphp
        @foreach($cards as [$label,$value,$icon,$border,$ibg,$ic,$vc])
            <div class="relative overflow-hidden bg-white rounded-2xl border {{ $border }} p-4 flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl {{ $ibg }} border {{ $border }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $icon }} {{ $ic }}"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                    <p class="text-lg font-bold {{ $vc }} mt-0.5 tabular-nums">{{ $value }}</p>
                </div>
                <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full {{ $ibg }} opacity-50"></div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search promotion name…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All', 'running' => 'Running', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'inactive' => 'Inactive'] as $v => $l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
        <select wire:model.live="typeFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Types</option>
            @foreach($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_',' ',$t)) }}</option>@endforeach
        </select>
    </div>

    {{-- Promotions grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($promotions as $promotion)
            @php
                $status = $promotion->status;
                $badge  = match($status) {
                    'active'    => 'bg-success-50 text-success-700 border border-success-200',
                    'running'   => 'bg-success-50 text-success-700 border border-success-200',
                    'scheduled' => 'bg-info-50 text-info-700 border border-info-200',
                    'expired'   => 'bg-warning-50 text-warning-700 border border-warning-200',
                    'exhausted' => 'bg-danger-50 text-danger-600 border border-danger-200',
                    default     => 'bg-primary-50 text-primary-400 border border-primary-100',
                };
                $typeColor = match($promotion->type) {
                    'flash_sale'        => 'bg-danger-50 text-danger-600 border-danger-200',
                    'buy_x_get_y'       => 'bg-secondary-50 text-secondary-700 border-secondary-200',
                    'bundle'            => 'bg-info-50 text-info-700 border-info-200',
                    'product_discount'  => 'bg-success-50 text-success-700 border-success-200',
                    'category_discount' => 'bg-warning-50 text-warning-700 border-warning-200',
                    default             => 'bg-primary-50 text-primary-400 border-primary-100',
                };
            @endphp
            <div class="bg-white rounded-2xl border border-primary-100 hover:border-primary-200 hover:shadow-md transition-all duration-200 overflow-hidden flex flex-col">
                <div class="px-5 py-4 flex-1 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="font-bold text-primary-600 text-sm leading-tight truncate">{{ $promotion->name }}</h3>
                            @if($promotion->description)
                                <p class="text-xs text-primary-400 mt-0.5 line-clamp-2">{{ $promotion->description }}</p>
                            @endif
                        </div>
                        <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold {{ $badge }} capitalize">{{ $status }}</span>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $typeColor }} capitalize">{{ str_replace('_',' ',$promotion->type) }}</span>
                        </div>
                    </div>
                    {{-- Discount display --}}
                    @if($promotion->discount_value)
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-black text-primary-600 tabular-nums">
                                {{ $promotion->discount_type === 'percentage' ? $promotion->discount_value.'%' : 'KES '.number_format($promotion->discount_value,2) }}
                            </span>
                            <span class="text-xs text-primary-400">off</span>
                        </div>
                    @endif
                    {{-- Dates --}}
                    <div class="text-xs text-primary-400 space-y-0.5">
                        @if($promotion->starts_at)
                            <p><i class="bi bi-calendar-event mr-1"></i>{{ $promotion->starts_at->format('d M Y') }}
                                @if($promotion->ends_at) → {{ $promotion->ends_at->format('d M Y') }}@endif
                            </p>
                        @endif
                        @if($promotion->max_uses)
                            <p><i class="bi bi-people mr-1"></i>{{ $promotion->times_used }} / {{ $promotion->max_uses }} uses</p>
                        @else
                            <p><i class="bi bi-people mr-1"></i>{{ $promotion->times_used }} uses (unlimited)</p>
                        @endif
                        @if($promotion->is_exclusive)
                            <p class="text-warning-600 font-semibold"><i class="bi bi-lock mr-1"></i>Exclusive - cannot stack</p>
                        @endif
                        <p class="text-primary-300">Priority: {{ $promotion->priority }}</p>
                    </div>
                </div>
                <div class="px-5 py-3.5 border-t border-primary-50 flex items-center justify-between">
                    <button wire:click="viewPromotion({{ $promotion->id }})" class="text-xs font-semibold text-primary-400 hover:text-primary-600 transition">
                        <i class="bi bi-eye mr-1"></i>Details
                    </button>
                    <div class="flex items-center gap-1">
                        <button wire:click="toggleActive({{ $promotion->id }})"
                                class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition
                                       {{ $promotion->is_active ? 'bg-success-50 border-success-200 text-success-700 hover:bg-success-100' : 'bg-primary-50 border-primary-200 text-primary-400 hover:bg-primary-100' }}">
                            <i class="bi {{ $promotion->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} text-sm"></i>
                            {{ $promotion->is_active ? 'On' : 'Off' }}
                        </button>
                        <button wire:click="openEdit({{ $promotion->id }})"
                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition">
                            <i class="bi bi-pencil text-xs"></i>
                        </button>
                        <button wire:click="confirmDelete({{ $promotion->id }})"
                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition">
                            <i class="bi bi-trash3 text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-3 py-20 text-center">
                <i class="bi bi-megaphone text-4xl text-primary-100 block mb-3"></i>
                <p class="text-sm font-medium text-primary-300">No promotions yet.</p>
                <button wire:click="openCreate" class="mt-3 text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Create your first promotion →</button>
            </div>
        @endforelse
    </div>

    @if($promotions->hasPages())
        <div>{{ $promotions->links() }}</div>
    @endif

    {{-- ═══ DETAIL SLIDE-OVER ═══ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-md bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $viewing->name }}</h2>
                        <span class="text-xs text-primary-300 capitalize">{{ str_replace('_',' ',$viewing->type) }}</span>
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
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([
                            ['Discount',    $viewing->discount_value ? ($viewing->discount_type==='percentage' ? $viewing->discount_value.'%' : 'KES '.number_format($viewing->discount_value,2)) : '-'],
                            ['Priority',    $viewing->priority],
                            ['Starts',      $viewing->starts_at?->format('d M Y, H:i') ?? 'Immediately'],
                            ['Ends',        $viewing->ends_at?->format('d M Y, H:i') ?? 'No end'],
                            ['Max Uses',    $viewing->max_uses ?? 'Unlimited'],
                            ['Times Used',  $viewing->times_used],
                            ['Exclusive',   $viewing->is_exclusive ? 'Yes - cannot stack' : 'No'],
                            ['Status',      ucfirst($viewing->status)],
                        ] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if($viewing->description)
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">Description</p>
                            <p class="text-sm text-secondary-700">{{ $viewing->description }}</p>
                        </div>
                    @endif
                    @if($viewing->conditions)
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2">Conditions</p>
                            <div class="space-y-1.5">
                                @foreach($viewing->conditions as $cond)
                                    <div class="flex items-center gap-2 rounded-xl bg-primary-50 border border-primary-100 px-3.5 py-2.5">
                                        <span class="text-xs font-semibold text-primary-500 capitalize">{{ $cond['key'] }}</span>
                                        <span class="text-xs text-primary-300">→</span>
                                        <span class="text-xs text-primary-600">{{ $cond['value'] }}</span>
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
                    <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Promotion' : 'New Promotion' }}</h2>
                    <button wire:click="$set('showModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Name <span class="text-danger-500">*</span></label>
                        <input wire:model="name" type="text" placeholder="e.g. Summer Sale 2025"
                               class="w-full border {{ $errors->has('name') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('name')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Description</label>
                        <textarea wire:model="description" rows="2"
                                  class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Type <span class="text-danger-500">*</span></label>
                            <select wire:model="type" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                @foreach($types as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_',' ',$t)) }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Priority</label>
                            <input wire:model="priority" type="number" min="0"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Discount Type</label>
                            <select wire:model="discountType" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (KES)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Discount Value</label>
                            <input wire:model="discountValue" type="number" min="0" step="0.01" placeholder="e.g. 15"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Starts At</label>
                            <input wire:model="startsAt" type="datetime-local"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Ends At</label>
                            <input wire:model="endsAt" type="datetime-local"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('endsAt')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Max Uses <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <input wire:model="maxUses" type="number" min="1" placeholder="Unlimited"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>
                    {{-- Conditions --}}
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-2">Conditions</label>
                        @foreach($conditions as $i => $cond)
                            <div class="flex items-center gap-2 mb-1.5 rounded-xl bg-primary-50 border border-primary-100 px-3 py-2">
                                <span class="text-xs font-semibold text-primary-500 flex-1 capitalize">{{ $cond['key'] }}</span>
                                <span class="text-xs text-primary-300">→</span>
                                <span class="text-xs text-primary-600 flex-1">{{ $cond['value'] }}</span>
                                <button wire:click="removeCondition({{ $i }})" class="text-primary-200 hover:text-danger-500 transition flex-shrink-0"><i class="bi bi-x text-sm"></i></button>
                            </div>
                        @endforeach
                        <div class="flex gap-2">
                            <input wire:model="condKey" type="text" placeholder="Key (e.g. min_qty)"
                                   class="flex-1 border border-primary-100 rounded-xl px-2.5 py-1.5 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                            <input wire:model="condValue" type="text" placeholder="Value (e.g. 3)"
                                   class="flex-1 border border-primary-100 rounded-xl px-2.5 py-1.5 text-xs text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-1 focus:ring-primary-400 transition" />
                            <button wire:click="addCondition" class="rounded-xl bg-primary-50 border border-primary-200 px-2.5 py-1.5 text-xs font-semibold text-primary-500 hover:bg-primary-100 transition">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-5 pt-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input wire:model="isActive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                            <span class="text-sm font-medium text-primary-500">Active</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input wire:model="isExclusive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-warning-500 focus:ring-warning-500/20" />
                            <span class="text-sm font-medium text-primary-500">Exclusive <span class="text-xs font-normal text-primary-300">(cannot stack)</span></span>
                        </label>
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
                        <i class="bi bi-megaphone text-danger-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Delete Promotion?</h2>
                    <p class="text-sm text-primary-400"><span class="font-semibold text-primary-600">{{ $deletingName }}</span> will be permanently removed.</p>
                </div>
                <div class="flex items-center justify-center gap-3 px-6 pb-6">
                    <button wire:click="$set('showDeleteModal',false)" class="flex-1 rounded-xl border border-primary-100 bg-white px-4 py-2.5 text-sm font-semibold text-primary-400 transition">Cancel</button>
                    <button wire:click="delete" wire:loading.attr="disabled"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-danger-500 hover:bg-danger-600 px-4 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-danger-500/20">
                        <span wire:loading.remove wire:target="delete">Delete</span>
                        <span wire:loading wire:target="delete">Deleting…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>