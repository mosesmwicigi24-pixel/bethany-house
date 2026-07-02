<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-truck"></i><span>Procurement</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Suppliers</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Suppliers</h1>
            <p class="mt-0.5 text-sm text-primary-300">Manage your supplier network and vendor relationships.</p>
        </div>
        <a href="{{ route('admin.procurement.suppliers.create') }}"
           class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> Add Supplier
        </a>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @php $cards = [
            ['Total Suppliers', $summary['total'],      'bi-people',        'border-primary-100',  'bg-primary-50',  'text-primary-400', 'text-primary-600'],
            ['Active',          $summary['active'],     'bi-check-circle',  'border-success-200',  'bg-success-50',  'text-success-500', 'text-success-700'],
            ['Top Rated (4+)',  $summary['top_rated'],  'bi-star-fill',     'border-secondary-200','bg-secondary-50','text-secondary-600','text-secondary-700'],
            ['Total Spend',     'KES '.number_format($summary['total_spend'],2), 'bi-currency-exchange','border-info-200','bg-info-50','text-info-500','text-info-700'],
        ]; @endphp
        @foreach($cards as [$label,$value,$icon,$border,$ibg,$ic,$vc])
        <div class="relative overflow-hidden bg-white rounded-2xl border {{ $border }} p-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl {{ $ibg }} border {{ $border }} flex items-center justify-center flex-shrink-0">
                <i class="bi {{ $icon }} {{ $ic }} text-lg"></i>
            </div>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                <p class="text-lg font-bold {{ $vc }} mt-0.5 truncate tabular-nums">{{ $value }}</p>
            </div>
            <div class="absolute -right-2 -bottom-2 w-12 h-12 rounded-full {{ $ibg }} opacity-50"></div>
        </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by name, code or email…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach([''=>'All','active'=>'Active','inactive'=>'Inactive'] as $v=>$l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter===$v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
        <select wire:model.live="ratingFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Ratings</option>
            <option value="4">4+ Stars</option>
            <option value="4.5">4.5+ Stars</option>
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th wire:click="sort('name')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Supplier <i class="bi bi-arrow-{{ $sortBy==='name' ? ($sortDir==='asc'?'up':'down') : 'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Contact</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Location</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">POs</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Rating</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($suppliers as $supplier)
                <tr class="hover:bg-primary-50/40 transition-colors group">
                    <td class="px-5 py-3.5">
                        <button wire:click="viewSupplier({{ $supplier->id }})" class="font-semibold text-primary-600 hover:text-primary-500 transition text-left">{{ $supplier->name }}</button>
                        <div class="text-xs font-mono text-primary-300 mt-0.5">{{ $supplier->code }}</div>
                    </td>
                    <td class="px-5 py-3.5 text-sm">
                        <p class="text-primary-500">{{ $supplier->contact_person ?? '-' }}</p>
                        @if($supplier->email)<p class="text-xs text-primary-300">{{ $supplier->email }}</p>@endif
                    </td>
                    <td class="px-5 py-3.5 text-sm text-primary-400">
                        {{ collect([$supplier->city, $supplier->country_code])->filter()->implode(', ') ?: '-' }}
                    </td>
                    <td class="px-5 py-3.5 text-center">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">
                            {{ $supplier->purchase_orders_count }}
                        </span>
                    </td>
                    <td class="px-5 py-3.5 text-center">
                        @if($supplier->rating)
                            <span class="inline-flex items-center gap-1 text-sm font-semibold text-secondary-700">
                                <i class="bi bi-star-fill text-secondary-500 text-xs"></i>
                                {{ number_format($supplier->rating, 1) }}
                            </span>
                        @else
                            <span class="text-xs text-primary-200">-</span>
                        @endif
                    </td>
                    <td class="px-5 py-3.5 text-center">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            {{ $supplier->is_active ? 'bg-success-50 text-success-700 border border-success-200' : 'bg-primary-50 text-primary-300 border border-primary-100' }}">
                            {{ $supplier->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-5 py-3.5 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button wire:click="viewSupplier({{ $supplier->id }})"
                                    class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition" title="View">
                                <i class="bi bi-eye text-sm"></i>
                            </button>
                            <a href="{{ route('procurement.suppliers.edit', $supplier->id) }}"
                               class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Edit">
                                <i class="bi bi-pencil text-sm"></i>
                            </a>
                            <button wire:click="toggleActive({{ $supplier->id }})"
                                    class="w-7 h-7 inline-flex items-center justify-center rounded-lg transition
                                           {{ $supplier->is_active ? 'text-primary-300 hover:text-danger-600 hover:bg-danger-50' : 'text-primary-300 hover:text-success-600 hover:bg-success-50' }}"
                                    title="{{ $supplier->is_active ? 'Deactivate' : 'Activate' }}">
                                <i class="bi {{ $supplier->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} text-base"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-5 py-16 text-center">
                    <i class="bi bi-truck text-4xl text-primary-100 block mb-3"></i>
                    <p class="text-sm font-medium text-primary-300">No suppliers found.</p>
                </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($suppliers->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $suppliers->links() }}</div>
        @endif
    </div>

    {{-- Detail slide-over --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-md bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $viewing->name }}</h2>
                        <code class="text-xs font-mono text-primary-300">{{ $viewing->code }}</code>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('procurement.suppliers.edit', $viewing->id) }}"
                           class="inline-flex items-center gap-1.5 rounded-xl border border-primary-100 px-3 py-1.5 text-xs font-semibold text-primary-400 hover:text-primary-600 hover:border-primary-300 transition">
                            <i class="bi bi-pencil text-xs"></i> Edit
                        </a>
                        <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        @foreach([
                            ['Contact',       $viewing->contact_person ?? '-'],
                            ['Email',         $viewing->email ?? '-'],
                            ['Phone',         $viewing->phone ?? '-'],
                            ['Tax ID',        $viewing->tax_id ?? '-'],
                            ['Payment Terms', $viewing->payment_terms ?? '-'],
                            ['Rating',        $viewing->rating ? number_format($viewing->rating,1).' / 5.0' : '-'],
                        ] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if($viewing->full_address)
                        <div class="rounded-xl bg-primary-50/50 border border-primary-100 p-4">
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1"><i class="bi bi-geo-alt mr-1"></i>Address</p>
                            <p class="text-sm text-primary-500">{{ $viewing->full_address }}</p>
                        </div>
                    @endif
                    @if($viewing->purchaseOrders->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">Recent Purchase Orders</p>
                            <div class="space-y-2">
                                @foreach($viewing->purchaseOrders as $po)
                                    <div class="flex items-center justify-between rounded-xl bg-primary-50/50 border border-primary-100 px-3.5 py-2.5">
                                        <code class="font-mono text-xs font-bold text-secondary-700">{{ $po->po_number }}</code>
                                        <span class="text-xs font-medium text-primary-500 tabular-nums">KES {{ number_format($po->total_amount,2) }}</span>
                                        <span class="text-xs text-primary-300 capitalize">{{ $po->status }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($viewing->notes)
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">Notes</p>
                            <p class="text-sm text-secondary-700">{{ $viewing->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

</div>