<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-megaphone"></i><span>Marketing</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Discounts & Coupons</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Discounts & Coupons</h1>
            <p class="mt-0.5 text-sm text-primary-300">Create and manage coupon codes for your storefront.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> Create Coupon
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
            ['Total',       $summary['total'],      'bi-ticket-perforated','border-primary-100',  'bg-primary-50',  'text-primary-400',  'text-primary-600'],
            ['Active',      $summary['active'],     'bi-check-circle',     'border-success-200',  'bg-success-50',  'text-success-500',  'text-success-700'],
            ['Expired',     $summary['expired'],    'bi-clock-history',    'border-warning-200',  'bg-warning-50',  'text-warning-500',  'text-warning-700'],
            ['Total Uses',  number_format($summary['total_uses']), 'bi-graph-up','border-info-200','bg-info-50','text-info-500','text-info-700'],
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

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search code or description…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All', 'active' => 'Active', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'inactive' => 'Inactive'] as $v => $l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
        <select wire:model.live="typeFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Types</option>
            <option value="percentage">Percentage</option>
            <option value="fixed">Fixed Amount</option>
            <option value="free_shipping">Free Shipping</option>
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Code</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Description</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Type</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Value</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Validity</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Uses</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($coupons as $coupon)
                    @php
                        $status = $coupon->status;
                        $badge  = match($status) {
                            'active'    => 'bg-success-50 text-success-700 border border-success-200',
                            'scheduled' => 'bg-info-50 text-info-700 border border-info-200',
                            'expired'   => 'bg-warning-50 text-warning-700 border border-warning-200',
                            'exhausted' => 'bg-danger-50 text-danger-600 border border-danger-200',
                            default     => 'bg-primary-50 text-primary-400 border border-primary-100',
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewCoupon({{ $coupon->id }})"
                                    class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-1 rounded-lg hover:bg-secondary-100 transition tracking-wider">
                                {{ $coupon->code }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-500 max-w-[180px] truncate">{{ $coupon->description ?: '-' }}</td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-primary-50 text-primary-500 border border-primary-100 capitalize">
                                {{ str_replace('_', ' ', $coupon->type) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-center font-bold text-primary-600 tabular-nums">
                            @if($coupon->type === 'percentage')
                                {{ $coupon->value }}%
                            @elseif($coupon->type === 'fixed')
                                KES {{ number_format($coupon->value, 2) }}
                            @else
                                <span class="text-success-600">Free</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-xs text-primary-400">
                            @if($coupon->valid_from || $coupon->valid_until)
                                <p>{{ $coupon->valid_from?->format('d M Y') ?? '-' }}</p>
                                <p>→ {{ $coupon->valid_until?->format('d M Y') ?? 'No expiry' }}</p>
                            @else
                                <span class="text-primary-300">No restrictions</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center text-sm tabular-nums">
                            <span class="font-semibold text-primary-600">{{ $coupon->times_used }}</span>
                            @if($coupon->usage_limit)
                                <span class="text-primary-300"> / {{ $coupon->usage_limit }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }} capitalize">{{ $status }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewCoupon({{ $coupon->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition" title="View">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                <button wire:click="openEdit({{ $coupon->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Edit">
                                    <i class="bi bi-pencil text-sm"></i>
                                </button>
                                <button wire:click="duplicate({{ $coupon->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-secondary-600 hover:bg-secondary-50 transition" title="Duplicate">
                                    <i class="bi bi-copy text-sm"></i>
                                </button>
                                <button wire:click="toggleActive({{ $coupon->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg transition
                                               {{ $coupon->is_active ? 'text-primary-300 hover:text-warning-600 hover:bg-warning-50' : 'text-primary-300 hover:text-success-600 hover:bg-success-50' }}" title="{{ $coupon->is_active ? 'Deactivate' : 'Activate' }}">
                                    <i class="bi {{ $coupon->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} text-base"></i>
                                </button>
                                <button wire:click="confirmDelete({{ $coupon->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition" title="Delete">
                                    <i class="bi bi-trash3 text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-5 py-16 text-center">
                        <i class="bi bi-ticket-perforated text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No coupons yet.</p>
                        <button wire:click="openCreate" class="mt-3 text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Create your first coupon →</button>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($coupons->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $coupons->links() }}</div>
        @endif
    </div>

    {{-- ═══ DETAIL SLIDE-OVER ═══ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-md bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <code class="font-mono text-lg font-black text-secondary-700 tracking-widest">{{ $viewing->code }}</code>
                        @php $sb=match($viewing->status){'active'=>'bg-success-50 text-success-700 border border-success-200','expired'=>'bg-warning-50 text-warning-700 border border-warning-200','inactive'=>'bg-primary-50 text-primary-400 border border-primary-100',default=>'bg-info-50 text-info-700 border border-info-200'}; @endphp
                        <span class="ml-2 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $sb }} capitalize">{{ $viewing->status }}</span>
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
                            ['Type',     str_replace('_',' ',ucfirst($viewing->type))],
                            ['Value',    $viewing->type==='percentage' ? $viewing->value.'%' : ($viewing->type==='fixed' ? 'KES '.number_format($viewing->value,2) : 'Free Shipping')],
                            ['Min Order',  $viewing->minimum_order_amount ? 'KES '.number_format($viewing->minimum_order_amount,2) : 'None'],
                            ['Max Discount',$viewing->max_discount_amount ? 'KES '.number_format($viewing->max_discount_amount,2) : 'None'],
                            ['Valid From',  $viewing->valid_from?->format('d M Y, H:i') ?? 'No restriction'],
                            ['Valid Until', $viewing->valid_until?->format('d M Y, H:i') ?? 'No expiry'],
                            ['Usage Limit', $viewing->usage_limit ?? 'Unlimited'],
                            ['Per Customer',$viewing->usage_limit_per_customer ?? 'Unlimited'],
                        ] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    {{-- Usage progress --}}
                    @if($viewing->usage_limit)
                        @php $pct = round($viewing->times_used / $viewing->usage_limit * 100); @endphp
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1.5">
                                <span class="font-semibold text-primary-400 uppercase tracking-wide">Usage</span>
                                <span class="font-bold text-primary-600">{{ $viewing->times_used }} / {{ $viewing->usage_limit }}</span>
                            </div>
                            <div class="h-2.5 rounded-full bg-primary-100 overflow-hidden">
                                <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-danger-500' : ($pct >= 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                                     style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center gap-2 text-sm text-primary-500">
                            <i class="bi bi-graph-up text-primary-300"></i>
                            <span>Used <strong>{{ $viewing->times_used }}</strong> times (no limit)</span>
                        </div>
                    @endif
                    @if($viewing->description)
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">Description</p>
                            <p class="text-sm text-secondary-700">{{ $viewing->description }}</p>
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
                    <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Coupon' : 'New Coupon' }}</h2>
                    <button wire:click="$set('showModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>

                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <div class="px-6 py-5 space-y-4">
                    {{-- Code --}}
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Coupon Code <span class="text-danger-500">*</span></label>
                        <div class="flex gap-2">
                            <input wire:model="code" type="text" placeholder="e.g. SAVE20"
                                   class="flex-1 border {{ $errors->has('code') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm font-mono uppercase text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            <button wire:click="generateCode" type="button"
                                    class="rounded-xl border border-primary-200 bg-primary-50 hover:bg-primary-100 px-3.5 text-xs font-semibold text-primary-500 transition">
                                <i class="bi bi-shuffle"></i> Generate
                            </button>
                        </div>
                        @error('code')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Description</label>
                        <input wire:model="description" type="text" placeholder="e.g. 20% off all orders"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>
                    {{-- Type + Value --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Type <span class="text-danger-500">*</span></label>
                            <select wire:model.live="type" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (KES)</option>
                                <option value="free_shipping">Free Shipping</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                                Value @if($type !== 'free_shipping')<span class="text-danger-500">*</span>@endif
                            </label>
                            <div class="relative">
                                @if($type === 'percentage')
                                    <span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-primary-300">%</span>
                                @elseif($type === 'fixed')
                                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-primary-300">KES</span>
                                @endif
                                <input wire:model="value" type="number" min="0" step="0.01"
                                       :disabled="{{ $type === 'free_shipping' ? 'true' : 'false' }}"
                                       class="w-full border {{ $errors->has('value') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl {{ $type === 'fixed' ? 'pl-11' : '' }} px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition disabled:opacity-50" />
                            </div>
                            @error('value')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    {{-- Min order + max discount --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Min Order Amount</label>
                            <input wire:model="minimumOrderAmount" type="number" min="0" step="0.01" placeholder="No minimum"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Max Discount Cap</label>
                            <input wire:model="maxDiscountAmount" type="number" min="0" step="0.01" placeholder="No cap"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    {{-- Validity --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Valid From</label>
                            <input wire:model="validFrom" type="datetime-local"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Valid Until</label>
                            <input wire:model="validUntil" type="datetime-local"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('validUntil')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    {{-- Usage limits --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Total Usage Limit</label>
                            <input wire:model="usageLimit" type="number" min="1" placeholder="Unlimited"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Per Customer Limit</label>
                            <input wire:model="usageLimitPerCustomer" type="number" min="1" placeholder="Unlimited"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input wire:model="isActive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                        <span class="text-sm font-medium text-primary-500">Active (usable at checkout)</span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update Coupon' : 'Create Coupon' }}</span>
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
                        <i class="bi bi-ticket-perforated text-danger-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Delete Coupon?</h2>
                    <p class="text-sm text-primary-400">Are you sure you want to delete <code class="font-mono font-bold text-secondary-700">{{ $deletingCode }}</code>? This cannot be undone.</p>
                </div>
                <div class="flex items-center justify-center gap-3 px-6 pb-6">
                    <button wire:click="$set('showDeleteModal',false)" class="flex-1 rounded-xl border border-primary-100 bg-white px-4 py-2.5 text-sm font-semibold text-primary-400 hover:text-primary-500 transition">Cancel</button>
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