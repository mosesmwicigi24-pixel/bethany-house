<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-person-circle"></i><span>Customers</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Customer Groups</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Customer Groups</h1>
            <p class="mt-0.5 text-sm text-primary-300">Segments derived from customer type and status. Click any card to drill in.</p>
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

    {{-- ─── SEGMENT DRILL-DOWN ─── --}}
    @if($activeSegment)
        @php [$segType, $segStatus] = explode('_', $activeSegment, 2); @endphp

        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <button wire:click="selectSegment('')"
                        class="inline-flex items-center gap-2 text-sm font-semibold text-primary-400 hover:text-primary-600 transition">
                    <i class="bi bi-arrow-left"></i> All Groups
                </button>
                <span class="text-primary-200">|</span>
                <h2 class="text-lg font-bold text-primary-500">
                    {{ ucfirst($segType) }} · {{ ucfirst($segStatus) }}
                </h2>
                @if($segmentCustomers)
                    <span class="text-sm text-primary-300">({{ $segmentCustomers->total() }} customers)</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                @if(count($selectedIds))
                    <button wire:click="openMoveModal"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-info-200 bg-info-50 hover:bg-info-100 px-3.5 py-2 text-xs font-semibold text-info-700 transition">
                        <i class="bi bi-arrow-left-right text-xs"></i> Move {{ count($selectedIds) }}
                    </button>
                    <button wire:click="bulkDelete"
                            wire:confirm="Delete {{ count($selectedIds) }} customers? This cannot be undone."
                            class="inline-flex items-center gap-1.5 rounded-xl border border-danger-200 bg-danger-50 hover:bg-danger-100 px-3.5 py-2 text-xs font-semibold text-danger-700 transition">
                        <i class="bi bi-trash3 text-xs"></i> Delete {{ count($selectedIds) }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Search within segment --}}
        <div class="flex items-center gap-2.5">
            <div class="relative flex-1 max-w-sm">
                <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
                <input wire:model.live.debounce.300ms="segmentSearch" type="text" placeholder="Search by name, email or phone…"
                       class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
        </div>

        @if($segmentCustomers)
            <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
                {{-- Select all bar --}}
                <div class="px-5 py-3 border-b border-primary-100 flex items-center gap-3 bg-primary-50/30">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input wire:model.live="selectAll" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                        <span class="text-xs font-semibold text-primary-400 uppercase tracking-wide">Select All</span>
                    </label>
                    @if(count($selectedIds))
                        <span class="text-xs text-primary-300">{{ count($selectedIds) }} selected</span>
                    @endif
                </div>

                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-primary-100">
                            <th class="px-5 py-3.5 w-8"></th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Customer</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Email</th>
                            <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Orders</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Loyalty</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Balance</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Joined</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary-50">
                        @forelse($segmentCustomers as $customer)
                            <tr class="hover:bg-primary-50/40 transition-colors group {{ in_array((string)$customer->id, $selectedIds) ? 'bg-primary-50/60' : '' }}">
                                <td class="px-5 py-3.5">
                                    <input wire:model.live="selectedIds" value="{{ $customer->id }}" type="checkbox"
                                           class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20 cursor-pointer" />
                                </td>
                                <td class="px-5 py-3.5">
                                    <p class="font-semibold text-primary-600">{{ $customer->full_name ?: '(No name)' }}</p>
                                    <code class="text-[11px] font-mono text-primary-300">{{ $customer->customer_number }}</code>
                                </td>
                                <td class="px-5 py-3.5 text-sm text-primary-400 truncate max-w-[180px]">{{ $customer->email ?: '-' }}</td>
                                <td class="px-5 py-3.5 text-center">
                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary-100 text-primary-500 text-xs font-bold">{{ $customer->orders_count }}</span>
                                </td>
                                <td class="px-5 py-3.5 text-right tabular-nums">
                                    <span class="text-sm {{ $customer->loyalty_points > 0 ? 'font-semibold text-secondary-700' : 'text-primary-200' }}">
                                        {{ number_format($customer->loyalty_points ?? 0) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right tabular-nums">
                                    <span class="text-sm {{ $customer->outstanding_balance > 0 ? 'font-semibold text-warning-700' : 'text-primary-200' }}">
                                        {{ $customer->outstanding_balance > 0 ? number_format($customer->outstanding_balance,2) : '-' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-sm text-primary-400">{{ $customer->created_at->format('d M Y') }}</td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button wire:click="openEdit({{ $customer->id }})"
                                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Edit">
                                            <i class="bi bi-pencil text-sm"></i>
                                        </button>
                                        <button wire:click="confirmDelete({{ $customer->id }})"
                                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition" title="Delete">
                                            <i class="bi bi-trash3 text-sm"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-5 py-12 text-center text-sm text-primary-300">No customers in this segment.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if($segmentCustomers->hasPages())
                    <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $segmentCustomers->links() }}</div>
                @endif
            </div>
        @endif

    {{-- ─── OVERVIEW GRID ─── --}}
    @else
        @php
            $typeIcons = ['individual' => 'bi-person', 'business' => 'bi-building'];
            $statusConfig = [
                'active'   => ['border'=>'border-success-200',  'head'=>'bg-success-50',   'badge'=>'bg-success-100 text-success-700',   'icon'=>'text-success-500'],
                'inactive' => ['border'=>'border-primary-200',  'head'=>'bg-primary-50',   'badge'=>'bg-primary-100 text-primary-500',   'icon'=>'text-primary-400'],
                'blocked'  => ['border'=>'border-danger-200',   'head'=>'bg-danger-50',    'badge'=>'bg-danger-100 text-danger-600',     'icon'=>'text-danger-500'],
            ];
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($groupStats as $key => $group)
                @php
                    $sc   = $statusConfig[$group['status']] ?? $statusConfig['inactive'];
                    $icon = $typeIcons[$group['type']] ?? 'bi-person';
                @endphp
                <div class="group bg-white rounded-2xl border {{ $sc['border'] }} hover:shadow-md transition-all duration-200 overflow-hidden">
                    {{-- Card header --}}
                    <button wire:click="selectSegment('{{ $key }}')" class="w-full text-left {{ $sc['head'] }} px-5 py-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-white/60 flex items-center justify-center">
                                <i class="bi {{ $icon }} {{ $sc['icon'] }} text-lg"></i>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-primary-600 capitalize">{{ $group['type'] }}</p>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold {{ $sc['badge'] }} capitalize">{{ $group['status'] }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-2xl font-bold text-primary-600">{{ number_format($group['count']) }}</p>
                            <p class="text-[11px] text-primary-400 uppercase tracking-wide font-semibold">Customers</p>
                        </div>
                    </button>

                    {{-- Stats row --}}
                    <div class="px-5 py-4 grid grid-cols-3 gap-3 text-center border-b border-primary-50">
                        <div>
                            <p class="text-base font-bold text-secondary-700 tabular-nums">{{ number_format($group['avg_loyalty']) }}</p>
                            <p class="text-[11px] text-primary-300 uppercase tracking-wide font-semibold mt-0.5">Avg Pts</p>
                        </div>
                        <div>
                            <p class="text-base font-bold text-primary-600 tabular-nums">{{ number_format($group['total_balance'], 0) }}</p>
                            <p class="text-[11px] text-primary-300 uppercase tracking-wide font-semibold mt-0.5">Balance</p>
                        </div>
                        <div>
                            <p class="text-base font-bold text-success-700 tabular-nums">{{ number_format($group['total_spend'], 0) }}</p>
                            <p class="text-[11px] text-primary-300 uppercase tracking-wide font-semibold mt-0.5">Spend</p>
                        </div>
                    </div>

                    {{-- Actions row --}}
                    <div class="px-5 py-3 flex items-center justify-between">
                        <span class="text-xs text-primary-400">{{ number_format($group['total_loyalty']) }} total pts</span>
                        <div class="flex items-center gap-2">
                            <button wire:click="openCreate(); $wire.createType = '{{ $group['type'] }}'; $wire.createStatus = '{{ $group['status'] }}';"
                                    class="inline-flex items-center gap-1 text-xs font-semibold text-primary-400 hover:text-primary-600 transition">
                                <i class="bi bi-person-plus text-xs"></i> Add
                            </button>
                            <span class="text-primary-200">·</span>
                            <button wire:click="selectSegment('{{ $key }}')"
                                    class="text-xs font-semibold text-primary-400 group-hover:text-primary-600 transition flex items-center gap-1">
                                View <i class="bi bi-arrow-right text-[11px]"></i>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach

            @if(empty($groupStats))
                <div class="col-span-3 py-20 text-center">
                    <i class="bi bi-people text-4xl text-primary-100 block mb-3"></i>
                    <p class="text-sm font-medium text-primary-300">No customer data yet.</p>
                </div>
            @endif
        </div>

        {{-- Summary totals --}}
        @if(!empty($groupStats))
            @php $totals = ['customers'=>array_sum(array_column($groupStats,'count')),'loyalty'=>array_sum(array_column($groupStats,'total_loyalty')),'balance'=>array_sum(array_column($groupStats,'total_balance'))]; @endphp
            <div class="flex flex-wrap items-center gap-6 text-sm text-primary-400 bg-primary-50/60 border border-primary-100 rounded-xl px-5 py-3.5">
                <span><span class="font-bold text-primary-600">{{ number_format($totals['customers']) }}</span> total customers</span>
                <span><span class="font-bold text-secondary-700">{{ number_format($totals['loyalty']) }}</span> total loyalty points</span>
                <span><span class="font-bold text-warning-700">KES {{ number_format($totals['balance'],2) }}</span> total outstanding</span>
            </div>
        @endif
    @endif

    {{-- ═══════════════════ EDIT MODAL ═══════════════════ --}}
    @if($showEditModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showEditModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showEditModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">Edit Customer Segment</h2>
                    <button wire:click="$set('showEditModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Type</label>
                            <select wire:model="editType" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="individual">Individual</option>
                                <option value="business">Business</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Status</label>
                            <select wire:model="editStatus" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Credit Limit (KES)</label>
                        <input wire:model="editCreditLimit" type="number" min="0" step="0.01"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes</label>
                        <textarea wire:model="editNotes" rows="2"
                                  class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showEditModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="saveEdit" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveEdit">Save Changes</span>
                        <span wire:loading wire:target="saveEdit">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════ MOVE MODAL ═══════════════════ --}}
    @if($showMoveModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showMoveModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showMoveModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Move Customers</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Move {{ count($selectedIds) }} customer(s) to a different segment.</p>
                    </div>
                    <button wire:click="$set('showMoveModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Type</label>
                            <select wire:model="moveTargetType" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="individual">Individual</option>
                                <option value="business">Business</option>
                            </select>
                            @error('moveTargetType')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Status</label>
                            <select wire:model="moveTargetStatus" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="blocked">Blocked</option>
                            </select>
                            @error('moveTargetStatus')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showMoveModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="saveMove" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-info-500 hover:bg-info-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-info-500/20">
                        <span wire:loading.remove wire:target="saveMove"><i class="bi bi-arrow-left-right mr-1"></i>Move Customers</span>
                        <span wire:loading wire:target="saveMove">Moving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════ CREATE MODAL ═══════════════════ --}}
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto" x-data x-on:keydown.escape.window="$wire.set('showCreateModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showCreateModal',false)"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-6">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">New Customer</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Pre-assigned to <span class="font-semibold capitalize">{{ $createType }}</span> · <span class="capitalize">{{ $createStatus }}</span></p>
                    </div>
                    <button wire:click="$set('showCreateModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
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
                            <input wire:model="createFirstName" type="text"
                                   class="w-full border {{ $errors->has('createFirstName') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('createFirstName')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Last Name</label>
                            <input wire:model="createLastName" type="text"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Email</label>
                            <input wire:model="createEmail" type="email"
                                   class="w-full border {{ $errors->has('createEmail') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('createEmail')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Phone</label>
                            <input wire:model="createPhone" type="text"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Company</label>
                            <input wire:model="createCompany" type="text"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Currency</label>
                            <select wire:model="createCurrency" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="KES">KES</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Type</label>
                            <select wire:model="createType" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="individual">Individual</option>
                                <option value="business">Business</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Status</label>
                            <select wire:model="createStatus" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="blocked">Blocked</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Credit Limit (KES)</label>
                        <input wire:model="createCreditLimit" type="number" min="0" step="0.01"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes</label>
                        <textarea wire:model="createNotes" rows="2"
                                  class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showCreateModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="saveCreate" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveCreate">Create Customer</span>
                        <span wire:loading wire:target="saveCreate">Creating…</span>
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