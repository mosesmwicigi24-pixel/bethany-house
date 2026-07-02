<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-truck"></i><span>Procurement</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Purchase Returns</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Purchase Returns</h1>
            <p class="mt-0.5 text-sm text-primary-300">Return defective or excess goods to suppliers and track credits.</p>
        </div>
        <button wire:click="$set('showCreateModal', true)"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> New Return
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @php $cards = [
            ['Awaiting Review', $summary['pending'],         'bi-hourglass-split',     'border-warning-200',  'bg-warning-50',  'text-warning-500',  'text-warning-700'],
            ['Approved',        $summary['approved'],        'bi-check-circle',        'border-info-200',     'bg-info-50',     'text-info-500',     'text-info-700'],
            ['Completed',       $summary['completed'],       'bi-arrow-counterclockwise','border-success-200','bg-success-50',  'text-success-500',  'text-success-700'],
            ['Total Credits',   'KES '.number_format($summary['total_credits'],2), 'bi-currency-exchange','border-secondary-200','bg-secondary-50','text-secondary-600','text-secondary-700'],
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Return # or supplier…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            <button wire:click="$set('statusFilter','')" class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100 {{ $statusFilter==='' ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">All</button>
            @foreach($statuses as $s)
                <button wire:click="$set('statusFilter','{{ $s }}')" class="px-3.5 py-2.5 font-medium transition border-l border-primary-100 capitalize {{ $statusFilter===$s ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ ucfirst($s) }}</button>
            @endforeach
        </div>
        <select wire:model.live="supplierFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Suppliers</option>
            @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
        </select>
        <input wire:model.live="dateFrom" type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo"   type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Return #</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">PO</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Supplier</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Reason</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Return Date</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Credit Amt</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($returns as $return)
                    @php $badge = match($return->status){
                        'pending'  =>'bg-warning-50 text-warning-700 border border-warning-200',
                        'approved' =>'bg-info-50 text-info-700 border border-info-200',
                        'completed'=>'bg-success-50 text-success-700 border border-success-200',
                        'rejected' =>'bg-danger-50 text-danger-600 border border-danger-200',
                        default    =>'bg-primary-50 text-primary-400 border border-primary-100',
                    }; @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewReturn({{ $return->id }})"
                                    class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg hover:bg-secondary-100 transition">
                                {{ $return->return_number }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5">
                            <code class="font-mono text-xs text-primary-400">{{ $return->purchaseOrder?->po_number ?? '-' }}</code>
                        </td>
                        <td class="px-5 py-3.5 text-sm font-medium text-primary-600">{{ $return->supplier?->name ?? '-' }}</td>
                        <td class="px-5 py-3.5 text-sm text-primary-500 max-w-[180px]">
                            <p class="truncate">{{ $return->reason ?: '-' }}</p>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">{{ $return->return_date?->format('d M Y') }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-semibold text-primary-600">
                            {{ $return->credit_amount ? number_format($return->credit_amount,2) : '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">{{ ucfirst($return->status) }}</span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button wire:click="viewReturn({{ $return->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition opacity-0 group-hover:opacity-100" title="View">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                @if($return->status === 'pending')
                                    <button wire:click="openApprove({{ $return->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-success-600 hover:bg-success-50 transition">
                                        <i class="bi bi-check2"></i> Approve
                                    </button>
                                @endif
                                @if($return->status === 'approved')
                                    <button wire:click="completeReturn({{ $return->id }})"
                                            wire:confirm="Mark this return as completed?"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-secondary-700 hover:bg-secondary-50 transition">
                                        <i class="bi bi-check2-all"></i> Complete
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                <tr><td colspan="8" class="px-5 py-16 text-center">
                    <i class="bi bi-arrow-return-left text-4xl text-primary-100 block mb-3"></i>
                    <p class="text-sm font-medium text-primary-300">No purchase returns found.</p>
                </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($returns->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $returns->links() }}</div>
        @endif
    </div>

    {{-- Detail slide-over --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-lg bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">{{ $viewing->return_number }}</code>
                        @php $db=match($viewing->status){'pending'=>'bg-warning-50 text-warning-700 border border-warning-200','approved'=>'bg-info-50 text-info-700 border border-info-200','completed'=>'bg-success-50 text-success-700 border border-success-200','rejected'=>'bg-danger-50 text-danger-600 border border-danger-200',default=>'bg-primary-50 text-primary-400 border border-primary-100'}; @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $db }}">{{ ucfirst($viewing->status) }}</span>
                    </div>
                    <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([['Supplier',$viewing->supplier?->name??'-'],['PO',$viewing->purchaseOrder?->po_number??'-'],['Return Date',$viewing->return_date?->format('d M Y')??'-'],['Credit Amount','KES '.number_format($viewing->credit_amount??0,2)]] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-semibold text-primary-600 mt-0.5">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if($viewing->reason)
                        <div class="rounded-xl bg-warning-50 border border-warning-200 p-4">
                            <p class="text-xs font-semibold text-warning-600 uppercase tracking-wide mb-1">Reason</p>
                            <p class="text-sm text-warning-700">{{ $viewing->reason }}</p>
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

    {{-- Create Return Modal --}}
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showCreateModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showCreateModal',false)"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">New Purchase Return</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Find the PO and specify the return details.</p>
                    </div>
                    <button wire:click="$set('showCreateModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Purchase Order Number</label>
                        <div class="flex gap-2">
                            <input wire:model="poSearch" wire:keydown.enter="searchPo" type="text" placeholder="e.g. PO-20240101-0001"
                                   class="flex-1 rounded-xl border border-primary-200 px-3.5 py-2.5 text-sm text-primary-600 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-400 transition" />
                            <button wire:click="searchPo" wire:loading.attr="disabled"
                                    class="rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60">
                                <span wire:loading.remove wire:target="searchPo"><i class="bi bi-search"></i></span>
                                <span wire:loading wire:target="searchPo"><i class="bi bi-arrow-clockwise animate-spin"></i></span>
                            </button>
                        </div>
                        @if($poError)<p class="text-xs text-danger-500 mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $poError }}</p>@endif
                    </div>

                    @if($selectedPo)
                        <div class="rounded-xl bg-success-50 border border-success-200 px-4 py-3 flex items-center gap-3">
                            <i class="bi bi-check-circle-fill text-success-500"></i>
                            <div>
                                <p class="text-sm font-semibold text-success-700">{{ $selectedPo->po_number }}</p>
                                <p class="text-xs text-success-600">{{ $selectedPo->supplier?->name }}</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Return Reason <span class="text-danger-500">*</span></label>
                            <textarea wire:model="returnReason" rows="3" placeholder="Describe why goods are being returned…"
                                      class="w-full rounded-xl border {{ $errors->has('returnReason') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                            @error('returnReason')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Credit Amount <span class="text-danger-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-primary-300">KES</span>
                                <input wire:model="creditAmount" type="number" min="0.01" step="0.01"
                                       class="w-full border {{ $errors->has('creditAmount') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl pl-11 pr-3 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            @error('creditAmount')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                            <textarea wire:model="returnNotes" rows="2"
                                      class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                        </div>
                    @endif
                </div>
                @if($selectedPo)
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showCreateModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="saveReturn" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveReturn">Create Return</span>
                        <span wire:loading wire:target="saveReturn">Creating…</span>
                    </button>
                </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Approve modal --}}
    @if($showApproveModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showApproveModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showApproveModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">Approve Return</h2>
                    <button wire:click="$set('showApproveModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                <div class="px-6 py-5">
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Approval Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                    <textarea wire:model="approveNotes" rows="3" placeholder="Any notes about this approval…"
                              class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showApproveModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="approveReturn" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-success-500 hover:bg-success-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-success-500/20">
                        <span wire:loading.remove wire:target="approveReturn"><i class="bi bi-check2 mr-1"></i>Approve</span>
                        <span wire:loading wire:target="approveReturn">Approving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>