<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-person-circle"></i><span>Customers</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">All Customers</h1>
            <p class="mt-0.5 text-sm text-primary-300">Manage your customer base, balances and loyalty points.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> Add Customer
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        @php $cards = [
            ['Total',        $summary['total'],                           'bi-people',            'border-primary-100',   'bg-primary-50',   'text-primary-400',   'text-primary-600'],
            ['Active',       $summary['active'],                          'bi-person-check',      'border-success-200',   'bg-success-50',   'text-success-500',   'text-success-700'],
            ['Business',     $summary['business'],                        'bi-building',          'border-info-200',      'bg-info-50',      'text-info-500',      'text-info-700'],
            ['With Balance', $summary['with_balance'],                    'bi-exclamation-circle','border-warning-200',   'bg-warning-50',   'text-warning-500',   'text-warning-700'],
            ['Loyalty Pts',  number_format($summary['total_loyalty_pts']),'bi-star',              'border-secondary-200', 'bg-secondary-50', 'text-secondary-600', 'text-secondary-700'],
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Name, email, phone or customer #…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'] as $v => $l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All Types', 'individual' => 'Individual', 'business' => 'Business'] as $v => $l)
                <button wire:click="$set('typeFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $typeFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th wire:click="sort('first_name')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Customer <i class="bi bi-arrow-{{ $sortBy==='first_name'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Contact</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Type</th>
                    <th wire:click="sort('orders_count')" class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-center gap-1.5">Orders <i class="bi bi-arrow-{{ $sortBy==='orders_count'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th wire:click="sort('loyalty_points')" class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">Loyalty <i class="bi bi-arrow-{{ $sortBy==='loyalty_points'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th wire:click="sort('outstanding_balance')" class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">Balance <i class="bi bi-arrow-{{ $sortBy==='outstanding_balance'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th wire:click="sort('last_purchase_at')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Last Purchase <i class="bi bi-arrow-{{ $sortBy==='last_purchase_at'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($customers as $customer)
                    @php $stBadge = match($customer->status) {
                        'active'   => 'bg-success-50 text-success-700 border border-success-200',
                        'inactive' => 'bg-primary-50 text-primary-400 border border-primary-100',
                        'blocked'  => 'bg-danger-50 text-danger-600 border border-danger-200',
                        default    => 'bg-primary-50 text-primary-300 border border-primary-100',
                    }; @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewCustomer({{ $customer->id }})" class="text-left">
                                <p class="font-semibold text-primary-600 hover:text-primary-500 transition">{{ $customer->full_name ?: '(No name)' }}</p>
                                <code class="text-[11px] font-mono text-primary-300">{{ $customer->customer_number }}</code>
                            </button>
                        </td>
                        <td class="px-5 py-3.5 text-sm">
                            <p class="text-primary-500 truncate max-w-[180px]">{{ $customer->email ?: '-' }}</p>
                            @if($customer->phone)<p class="text-xs text-primary-300 mt-0.5">{{ $customer->phone }}</p>@endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold
                                {{ $customer->customer_type === 'business' ? 'bg-info-50 text-info-700 border border-info-200' : 'bg-primary-50 text-primary-400 border border-primary-100' }}">
                                <i class="bi {{ $customer->customer_type === 'business' ? 'bi-building' : 'bi-person' }} text-[10px]"></i>
                                {{ ucfirst($customer->customer_type) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">{{ $customer->orders_count }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums">
                            @if($customer->loyalty_points > 0)
                                <span class="text-sm font-semibold text-secondary-700">{{ number_format($customer->loyalty_points) }}</span>
                                <span class="text-xs text-secondary-500 ml-0.5">pts</span>
                            @else
                                <span class="text-xs text-primary-200">-</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums">
                            @if($customer->outstanding_balance > 0)
                                <span class="text-sm font-semibold text-warning-700">{{ number_format($customer->outstanding_balance, 2) }}</span>
                            @else
                                <span class="text-xs text-primary-200">-</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">{{ $customer->last_purchase_at?->format('d M Y') ?? '-' }}</td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $stBadge }}">{{ ucfirst($customer->status) }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewCustomer({{ $customer->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition" title="View">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                <button wire:click="openEdit({{ $customer->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Edit">
                                    <i class="bi bi-pencil text-sm"></i>
                                </button>
                                <button wire:click="openLoyaltyModal({{ $customer->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-secondary-600 hover:bg-secondary-50 transition" title="Loyalty points">
                                    <i class="bi bi-star text-sm"></i>
                                </button>
                                <button wire:click="openStatusModal({{ $customer->id }}, '{{ $customer->status }}')"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-warning-600 hover:bg-warning-50 transition" title="Change status">
                                    <i class="bi bi-toggle-on text-sm"></i>
                                </button>
                                <button wire:click="confirmDelete({{ $customer->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition" title="Delete">
                                    <i class="bi bi-trash3 text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-5 py-16 text-center">
                        <i class="bi bi-person-x text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No customers found.</p>
                        <button wire:click="openCreate" class="mt-3 text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Add the first customer →</button>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($customers->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $customers->links() }}</div>
        @endif
    </div>

    {{-- ═══════════════════ DETAIL SLIDE-OVER ═══════════════════ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-lg bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <div class="flex items-center gap-3">
                            <h2 class="text-base font-bold text-primary-500">{{ $viewing->full_name ?: '(No name)' }}</h2>
                            @php $sb=match($viewing->status){'active'=>'bg-success-50 text-success-700 border border-success-200','inactive'=>'bg-primary-50 text-primary-400 border border-primary-100','blocked'=>'bg-danger-50 text-danger-600 border border-danger-200',default=>'bg-primary-50 text-primary-300 border border-primary-100'}; @endphp
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $sb }}">{{ ucfirst($viewing->status) }}</span>
                        </div>
                        <code class="text-xs font-mono text-primary-300">{{ $viewing->customer_number }}</code>
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
                        @foreach([['Email',$viewing->email??'-'],['Phone',$viewing->phone??'-'],['Type',ucfirst($viewing->customer_type)],['Company',$viewing->company??'-'],['Language',$viewing->preferred_language??'-'],['Currency',$viewing->preferred_currency??'-'],['Credit Limit',$viewing->credit_limit?'KES '.number_format($viewing->credit_limit,2):'-'],['Tax ID',$viewing->tax_id??'-']] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-3 text-center">
                            <p class="text-xl font-bold text-secondary-700">{{ number_format($viewing->loyalty_points ?? 0) }}</p>
                            <p class="text-[11px] text-secondary-500 font-semibold uppercase tracking-wide mt-0.5">Loyalty Pts</p>
                            <button wire:click="openLoyaltyModal({{ $viewing->id }})" class="mt-1.5 text-[10px] font-semibold text-secondary-600 hover:text-secondary-800 transition">Adjust →</button>
                        </div>
                        <div class="rounded-xl bg-primary-50/50 border border-primary-100 p-3 text-center">
                            <p class="text-xl font-bold text-primary-600">{{ $viewing->orders_count }}</p>
                            <p class="text-[11px] text-primary-400 font-semibold uppercase tracking-wide mt-0.5">Orders</p>
                        </div>
                        <div class="rounded-xl {{ $viewing->outstanding_balance > 0 ? 'bg-warning-50 border-warning-200' : 'bg-primary-50/50 border-primary-100' }} border p-3 text-center">
                            <p class="text-xl font-bold {{ $viewing->outstanding_balance > 0 ? 'text-warning-700' : 'text-primary-600' }}">{{ number_format($viewing->outstanding_balance ?? 0, 2) }}</p>
                            <p class="text-[11px] {{ $viewing->outstanding_balance > 0 ? 'text-warning-500' : 'text-primary-400' }} font-semibold uppercase tracking-wide mt-0.5">Balance</p>
                        </div>
                    </div>
                    @if($viewing->addresses->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5"><i class="bi bi-geo-alt mr-1"></i>Addresses</p>
                            <div class="space-y-2">
                                @foreach($viewing->addresses->take(3) as $addr)
                                    <div class="rounded-xl bg-primary-50/50 border border-primary-100 px-3.5 py-3">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[10px] font-bold uppercase text-primary-300">{{ $addr->address_type }}</span>
                                            @if($addr->is_default)<span class="text-[10px] font-semibold text-secondary-600 bg-secondary-50 border border-secondary-200 px-1.5 py-0.5 rounded-full">Default</span>@endif
                                        </div>
                                        <p class="text-sm text-primary-500">{{ $addr->full_address }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($viewing->orders->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5"><i class="bi bi-bag-check mr-1"></i>Recent Orders</p>
                            <div class="rounded-xl border border-primary-100 overflow-hidden">
                                <table class="min-w-full text-sm">
                                    <tbody class="divide-y divide-primary-50">
                                        @foreach($viewing->orders as $order)
                                            <tr class="hover:bg-primary-50/40">
                                                <td class="px-4 py-2.5"><code class="font-mono text-xs font-bold text-secondary-700">{{ $order->order_number }}</code></td>
                                                <td class="px-4 py-2.5 text-xs text-primary-400">{{ $order->created_at->format('d M Y') }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-primary-600 text-xs">KES {{ number_format($order->total_amount,2) }}</td>
                                                <td class="px-4 py-2.5 text-right">
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium capitalize {{ match($order->status){'completed'=>'bg-success-50 text-success-700 border border-success-200','cancelled'=>'bg-danger-50 text-danger-600 border border-danger-200',default=>'bg-primary-50 text-primary-400 border border-primary-100'} }}">{{ $order->status }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                    @if($viewing->notes)
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">Notes</p>
                            <p class="text-sm text-secondary-700">{{ $viewing->notes }}</p>
                        </div>
                    @endif
                    <div class="pt-2 border-t border-primary-100 flex items-center gap-2">
                        <button wire:click="openStatusModal({{ $viewing->id }},'{{ $viewing->status }}')"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-primary-200 bg-white hover:bg-primary-50 px-3.5 py-2 text-xs font-semibold text-primary-500 transition">
                            <i class="bi bi-toggle-on text-xs"></i> Change Status
                        </button>
                        <button wire:click="confirmDelete({{ $viewing->id }})"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-danger-200 bg-danger-50 hover:bg-danger-100 px-3.5 py-2 text-xs font-semibold text-danger-700 transition">
                            <i class="bi bi-trash3 text-xs"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════ CREATE / EDIT MODAL ═══════════════════ --}}
    @if($showFormModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showFormModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showFormModal',false)"></div>
            <div class="relative w-full max-w-2xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-6">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Customer' : 'New Customer' }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5">{{ $isEditing ? 'Update customer profile details.' : 'Create a new customer record.' }}</p>
                    </div>
                    <button wire:click="$set('showFormModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>

                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="space-y-0.5 list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <div class="px-6 py-5 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">First Name <span class="text-danger-500">*</span></label>
                            <input wire:model="firstName" type="text"
                                   class="w-full border {{ $errors->has('firstName') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('firstName')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Last Name</label>
                            <input wire:model="lastName" type="text"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Email</label>
                            <input wire:model="email" type="email"
                                   class="w-full border {{ $errors->has('email') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('email')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Phone</label>
                            <input wire:model="phone" type="text"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Company</label>
                            <input wire:model="company" type="text"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Tax ID</label>
                            <input wire:model="taxId" type="text"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Type</label>
                            <select wire:model="customerType" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="individual">Individual</option>
                                <option value="business">Business</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Currency</label>
                            <select wire:model="preferredCurrency" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="KES">KES</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Status</label>
                            <select wire:model="formStatus" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                @foreach($statuses as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Credit Limit (KES)</label>
                            <input wire:model="creditLimit" type="number" min="0" step="0.01"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Loyalty Points</label>
                            <input wire:model="loyaltyPoints" type="number" min="0"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Outstanding Balance</label>
                            <input wire:model="outstandingBalance" type="number" min="0" step="0.01"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes</label>
                        <textarea wire:model="notes" rows="2"
                                  class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showFormModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update Customer' : 'Create Customer' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════ STATUS MODAL ═══════════════════ --}}
    @if($showStatusModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showStatusModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showStatusModal',false)"></div>
            <div class="relative w-full max-w-xs rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">Change Status</h2>
                    <button wire:click="$set('showStatusModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5">
                    <div class="grid grid-cols-3 gap-2">
                        @foreach(['active'=>['Active','success'],'inactive'=>['Inactive','primary'],'blocked'=>['Blocked','danger']] as $s=>[$l,$color])
                            <button wire:click="$set('newStatus','{{ $s }}')"
                                    class="py-2.5 rounded-xl border text-xs font-semibold transition capitalize
                                           {{ $newStatus===$s ? "bg-{$color}-500 text-white border-{$color}-500" : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
                                {{ $l }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showStatusModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 transition">Cancel</button>
                    <button wire:click="updateStatus" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="updateStatus">Save</span>
                        <span wire:loading wire:target="updateStatus">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════ LOYALTY MODAL ═══════════════════ --}}
    @if($showLoyaltyModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showLoyaltyModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showLoyaltyModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">Adjust Loyalty Points</h2>
                    <button wire:click="$set('showLoyaltyModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Action</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach(['add'=>['Add','success'],'subtract'=>['Subtract','warning'],'set'=>['Set to','info']] as $v=>[$l,$c])
                                <button wire:click="$set('loyaltyType','{{ $v }}')"
                                        class="py-2.5 rounded-xl border text-xs font-semibold transition
                                               {{ $loyaltyType===$v ? "bg-{$c}-500 text-white border-{$c}-500" : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
                                    {{ $l }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Points</label>
                        <input wire:model="loyaltyAdjust" type="number" min="0" placeholder="e.g. 100"
                               class="w-full border {{ $errors->has('loyaltyAdjust') ? 'border-danger-400' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('loyaltyAdjust')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showLoyaltyModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 transition">Cancel</button>
                    <button wire:click="saveLoyalty" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-secondary-500 hover:bg-secondary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-secondary-500/20">
                        <span wire:loading.remove wire:target="saveLoyalty"><i class="bi bi-star mr-1"></i>Update Points</span>
                        <span wire:loading wire:target="saveLoyalty">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════ DELETE CONFIRM ═══════════════════ --}}
    @if($showDeleteModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showDeleteModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showDeleteModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="px-6 py-6 text-center space-y-3">
                    <div class="w-14 h-14 rounded-full bg-danger-50 border border-danger-200 flex items-center justify-center mx-auto">
                        <i class="bi bi-person-x text-danger-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Delete Customer?</h2>
                    <p class="text-sm text-primary-400">
                        Are you sure you want to delete <span class="font-semibold text-primary-600">{{ $deletingName }}</span>?
                        Their order history will be preserved.
                    </p>
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