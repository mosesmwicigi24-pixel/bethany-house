<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-person-circle"></i><span>Customers</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Reviews & Ratings</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Reviews & Ratings</h1>
            <p class="mt-0.5 text-sm text-primary-300">Moderate customer product reviews and track satisfaction.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        {{-- Avg rating - large --}}
        <div class="col-span-2 sm:col-span-2 lg:col-span-1 relative overflow-hidden bg-white rounded-2xl border border-secondary-200 p-4 flex flex-col items-center justify-center">
            <div class="flex items-end gap-1">
                <span class="text-4xl font-black text-secondary-700 tabular-nums leading-none">{{ number_format($summary['avg_rating'], 1) }}</span>
                <span class="text-base text-secondary-400 font-semibold mb-0.5">/ 5</span>
            </div>
            <div class="flex items-center gap-0.5 mt-1.5">
                @for($i = 1; $i <= 5; $i++)
                    <i class="bi {{ $i <= round($summary['avg_rating']) ? 'bi-star-fill' : 'bi-star' }} text-secondary-400 text-sm"></i>
                @endfor
            </div>
            <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide mt-1.5">Avg Rating</p>
            <div class="absolute -right-3 -bottom-3 w-16 h-16 rounded-full bg-secondary-50 opacity-50"></div>
        </div>

        @php $cards = [
            ['Total',       $summary['total'],    'bi-chat-left-text',  'border-primary-100',  'bg-primary-50',  'text-primary-400',  'text-primary-600'],
            ['Approved',    $summary['approved'], 'bi-check-circle',    'border-success-200',  'bg-success-50',  'text-success-500',  'text-success-700'],
            ['Pending',     $summary['pending'],  'bi-clock',           'border-warning-200',  'bg-warning-50',  'text-warning-500',  'text-warning-700'],
            ['Verified',    $summary['verified'], 'bi-patch-check',     'border-info-200',     'bg-info-50',     'text-info-500',     'text-info-700'],
            ['5 ★',         $summary['five_star'],'bi-star-fill',       'border-secondary-200','bg-secondary-50','text-secondary-600','text-secondary-700'],
            ['1 ★',         $summary['one_star'], 'bi-star',            'border-danger-200',   'bg-danger-50',   'text-danger-500',   'text-danger-600'],
        ]; @endphp
        @foreach($cards as [$label,$value,$icon,$border,$ibg,$ic,$vc])
        <div class="relative overflow-hidden bg-white rounded-2xl border {{ $border }} p-4 flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl {{ $ibg }} border {{ $border }} flex items-center justify-center flex-shrink-0">
                <i class="bi {{ $icon }} {{ $ic }}"></i>
            </div>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                <p class="text-lg font-bold {{ $vc }} mt-0.5 tabular-nums">{{ number_format($value) }}</p>
            </div>
            <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full {{ $ibg }} opacity-50"></div>
        </div>
        @endforeach
    </div>

    {{-- Rating distribution bar --}}
    <div class="bg-white rounded-2xl border border-primary-100 px-5 py-4 shadow-sm">
        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-3">Rating Distribution</p>
        <div class="space-y-2">
            @foreach([5,4,3,2,1] as $star)
                @php
                    $key   = match($star){ 5=>'five_star', 4=>'four_star', 3=>'three_star', 2=>'two_star', 1=>'one_star' };
                    $count = $summary[$key] ?? 0;
                    $pct   = $summary['total'] > 0 ? round($count / $summary['total'] * 100) : 0;
                @endphp
                <button wire:click="$set('ratingFilter','{{ $ratingFilter == $star ? '' : $star }}')"
                        class="w-full flex items-center gap-3 group cursor-pointer">
                    <div class="flex items-center gap-1 w-12 flex-shrink-0">
                        <span class="text-xs font-bold text-primary-500">{{ $star }}</span>
                        <i class="bi bi-star-fill text-secondary-400 text-[10px]"></i>
                    </div>
                    <div class="flex-1 h-2.5 rounded-full bg-primary-100 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-500
                                    {{ $ratingFilter == $star ? 'bg-secondary-500' : 'bg-secondary-300 group-hover:bg-secondary-400' }}"
                             style="width: {{ $pct }}%"></div>
                    </div>
                    <span class="w-10 text-right text-xs tabular-nums {{ $ratingFilter == $star ? 'text-secondary-700 font-bold' : 'text-primary-400' }}">
                        {{ number_format($count) }}
                    </span>
                    <span class="w-8 text-right text-[11px] text-primary-300 tabular-nums">{{ $pct }}%</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Filters + bulk actions --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search review, product or customer…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>

        {{-- Approval filter --}}
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach([''  => 'All', '1' => 'Approved', '0' => 'Pending'] as $v => $l)
                <button wire:click="$set('approvedFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $approvedFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                    {{ $l }}
                </button>
            @endforeach
        </div>

        {{-- Verified toggle --}}
        <button wire:click="$set('verifiedFilter', '{{ $verifiedFilter ? '' : '1' }}')"
                class="inline-flex items-center gap-1.5 rounded-xl border px-3.5 py-2.5 text-sm font-medium transition
                       {{ $verifiedFilter ? 'bg-info-500 text-white border-info-500' : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
            <i class="bi bi-patch-check text-sm"></i> Verified only
        </button>

        {{-- Rating filter pill --}}
        @if($ratingFilter)
            <button wire:click="$set('ratingFilter','')"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-secondary-100 border border-secondary-300 px-3.5 py-2.5 text-sm font-semibold text-secondary-700 hover:bg-secondary-200 transition">
                <i class="bi bi-star-fill text-secondary-500 text-xs"></i> {{ $ratingFilter }} stars
                <i class="bi bi-x text-secondary-400 text-sm"></i>
            </button>
        @endif

        {{-- Bulk actions (visible when items selected) --}}
        @if(count($selected))
            <div class="flex items-center gap-2 ml-auto">
                <span class="text-xs text-primary-400">{{ count($selected) }} selected</span>
                <button wire:click="bulkApprove"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-success-500 hover:bg-success-600 px-3.5 py-2.5 text-xs font-semibold text-white transition">
                    <i class="bi bi-check2-all"></i> Approve All
                </button>
                <button wire:click="bulkDelete"
                        wire:confirm="Delete {{ count($selected) }} selected reviews? This cannot be undone."
                        class="inline-flex items-center gap-1.5 rounded-xl bg-danger-500 hover:bg-danger-600 px-3.5 py-2.5 text-xs font-semibold text-white transition">
                    <i class="bi bi-trash3"></i> Delete All
                </button>
            </div>
        @endif
    </div>

    {{-- Reviews table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <div class="px-5 py-3.5 border-b border-primary-100 flex items-center gap-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model.live="selectAll" type="checkbox"
                       class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                <span class="text-xs font-semibold text-primary-400 uppercase tracking-wide">Select All</span>
            </label>
        </div>

        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 w-8"></th>
                    <th wire:click="sort('created_at')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Date <i class="bi bi-arrow-{{ $sortBy==='created_at'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Customer</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Review</th>
                    <th wire:click="sort('rating')" class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-center gap-1.5">Rating <i class="bi bi-arrow-{{ $sortBy==='rating'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($reviews as $review)
                    <tr class="hover:bg-primary-50/40 transition-colors group {{ !$review->is_approved ? 'bg-warning-50/20' : '' }}">
                        <td class="px-5 py-3.5">
                            <input wire:model.live="selected" value="{{ $review->id }}" type="checkbox"
                                   class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20 cursor-pointer" />
                        </td>
                        <td class="px-5 py-3.5 text-xs text-primary-400 whitespace-nowrap">
                            {{ $review->created_at->format('d M Y') }}
                            <div class="text-[11px] text-primary-200">{{ $review->created_at->format('H:i') }}</div>
                        </td>
                        <td class="px-5 py-3.5">
                            <p class="font-medium text-primary-600 text-sm leading-tight line-clamp-1">
                                {{ $review->product?->translations->first()?->name ?? '-' }}
                            </p>
                        </td>
                        <td class="px-5 py-3.5 text-sm">
                            <p class="text-primary-500">{{ $review->user?->name ?? '-' }}</p>
                            @if($review->is_verified_purchase)
                                <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-info-600 mt-0.5">
                                    <i class="bi bi-patch-check-fill"></i> Verified
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 max-w-[220px]">
                            @if($review->title)
                                <p class="text-xs font-semibold text-primary-600 truncate">{{ $review->title }}</p>
                            @endif
                            <p class="text-xs text-primary-400 truncate mt-0.5">{{ $review->review ?: '(No text)' }}</p>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <div class="inline-flex items-center gap-0.5">
                                @for($i = 1; $i <= 5; $i++)
                                    <i class="bi {{ $i <= $review->rating ? 'bi-star-fill text-secondary-400' : 'bi-star text-primary-200' }} text-xs"></i>
                                @endfor
                            </div>
                            <p class="text-[11px] text-primary-300 tabular-nums mt-0.5">{{ $review->rating }}/5</p>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            @if($review->is_approved)
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-success-50 text-success-700 border border-success-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-success-500 mr-1.5"></span>Approved
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-warning-50 text-warning-700 border border-warning-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-warning-400 animate-pulse mr-1.5"></span>Pending
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewReview({{ $review->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition" title="View">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                @if(!$review->is_approved)
                                    <button wire:click="approve({{ $review->id }})"
                                            class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-success-600 hover:bg-success-50 transition" title="Approve">
                                        <i class="bi bi-check2 text-sm"></i>
                                    </button>
                                @else
                                    <button wire:click="reject({{ $review->id }})"
                                            class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-warning-600 hover:bg-warning-50 transition" title="Hide">
                                        <i class="bi bi-eye-slash text-sm"></i>
                                    </button>
                                @endif
                                <button wire:click="delete({{ $review->id }})"
                                        wire:confirm="Permanently delete this review?"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition" title="Delete">
                                    <i class="bi bi-trash3 text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-5 py-16 text-center">
                        <i class="bi bi-chat-left-text text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No reviews found.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($reviews->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $reviews->links() }}</div>
        @endif
    </div>

    {{-- Review detail slide-over --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-lg bg-white shadow-2xl flex flex-col h-full overflow-hidden">

                {{-- Slide header --}}
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-0.5">
                            @for($i = 1; $i <= 5; $i++)
                                <i class="bi {{ $i <= $viewing->rating ? 'bi-star-fill text-secondary-400' : 'bi-star text-primary-200' }}"></i>
                            @endfor
                        </div>
                        @if($viewing->is_approved)
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-success-50 text-success-700 border border-success-200">Approved</span>
                        @else
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-warning-50 text-warning-700 border border-warning-200">Pending</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        @if(!$viewing->is_approved)
                            <button wire:click="approve({{ $viewing->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-success-500 hover:bg-success-600 px-3 py-1.5 text-xs font-semibold text-white transition">
                                <i class="bi bi-check2 text-xs"></i> Approve
                            </button>
                        @else
                            <button wire:click="reject({{ $viewing->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-warning-200 bg-warning-50 hover:bg-warning-100 px-3 py-1.5 text-xs font-semibold text-warning-700 transition">
                                <i class="bi bi-eye-slash text-xs"></i> Hide
                            </button>
                        @endif
                        <button wire:click="$set('showDetail',false)"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">

                    {{-- Product --}}
                    <div class="flex items-center gap-3 rounded-xl bg-primary-50/50 border border-primary-100 p-3.5">
                        @if($viewing->product?->images->first())
                            <img src="{{ $viewing->product->images->first()->image_url }}" alt=""
                                 class="w-14 h-14 rounded-xl object-cover flex-shrink-0 border border-primary-100" />
                        @else
                            <div class="w-14 h-14 rounded-xl bg-primary-100 flex items-center justify-center flex-shrink-0">
                                <i class="bi bi-box text-primary-300 text-xl"></i>
                            </div>
                        @endif
                        <div class="min-w-0">
                            <p class="font-semibold text-primary-600 truncate">{{ $viewing->product?->translations->first()?->name ?? '-' }}</p>
                            <p class="text-xs text-primary-300 mt-0.5">{{ $viewing->product?->sku }}</p>
                        </div>
                    </div>

                    {{-- Customer + meta --}}
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([
                            ['Customer',  $viewing->user?->name ?? '-'],
                            ['Email',     $viewing->user?->email ?? '-'],
                            ['Submitted', $viewing->created_at->format('d M Y, H:i')],
                            ['Order',     $viewing->order?->order_number ?? '-'],
                            ['Helpful',   $viewing->helpful_count . ' people'],
                            ['Verified',  $viewing->is_verified_purchase ? 'Yes' : 'No'],
                        ] as [$l, $v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Review content --}}
                    @if($viewing->title)
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1.5">Title</p>
                            <p class="text-base font-bold text-primary-600">{{ $viewing->title }}</p>
                        </div>
                    @endif

                    @if($viewing->review)
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1.5">Review</p>
                            <div class="rounded-xl bg-primary-50/60 border border-primary-100 px-4 py-4">
                                <p class="text-sm text-primary-600 leading-relaxed">{{ $viewing->review }}</p>
                            </div>
                        </div>
                    @else
                        <div class="rounded-xl bg-primary-50/40 border border-dashed border-primary-200 px-4 py-6 text-center">
                            <i class="bi bi-chat-left text-2xl text-primary-200 block mb-1.5"></i>
                            <p class="text-xs text-primary-300">No written review - rating only</p>
                        </div>
                    @endif

                    {{-- Danger zone --}}
                    <div class="pt-2 border-t border-primary-100">
                        <button wire:click="delete({{ $viewing->id }})"
                                wire:confirm="Permanently delete this review? This cannot be undone."
                                class="inline-flex items-center gap-2 rounded-xl border border-danger-200 bg-danger-50 hover:bg-danger-100 px-4 py-2 text-xs font-semibold text-danger-700 transition">
                            <i class="bi bi-trash3"></i> Delete Review
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>