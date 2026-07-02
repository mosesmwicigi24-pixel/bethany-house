<div class="space-y-6">

    {{-- ── Page Header ── --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-cart-check"></i>
                <span>Orders</span>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span>Returns & Refunds</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Returns & Refunds</h1>
            <p class="mt-0.5 text-sm text-primary-300">Review, approve and process customer return requests.</p>
        </div>
    </div>

    {{-- ── Flash ── --}}
    @if (session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>
            {{ session('success') }}
        </div>
    @endif

    {{-- ── Summary Cards ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @php
            $cards = [
                ['label' => 'Awaiting Review', 'value' => $summary['requested'], 'icon' => 'bi-hourglass-split',    'color' => 'warning'],
                ['label' => 'Approved',         'value' => $summary['approved'],  'icon' => 'bi-check-circle',       'color' => 'info'],
                ['label' => 'Items Received',   'value' => $summary['received'],  'icon' => 'bi-box-arrow-in-down',  'color' => 'secondary'],
                ['label' => 'Refunded',         'value' => $summary['completed'], 'icon' => 'bi-arrow-counterclockwise', 'color' => 'success'],
            ];
            $cardColors = [
                'warning'   => ['border' => 'border-warning-200',   'icon_bg' => 'bg-warning-50',   'icon'  => 'text-warning-500',  'val' => 'text-warning-700'],
                'info'      => ['border' => 'border-info-200',      'icon_bg' => 'bg-info-50',      'icon'  => 'text-info-600',     'val' => 'text-info-700'],
                'secondary' => ['border' => 'border-secondary-200', 'icon_bg' => 'bg-secondary-50', 'icon'  => 'text-secondary-700','val' => 'text-secondary-800'],
                'success'   => ['border' => 'border-success-200',   'icon_bg' => 'bg-success-50',   'icon'  => 'text-success-600',  'val' => 'text-success-700'],
            ];
        @endphp
        @foreach($cards as $card)
            @php $cc = $cardColors[$card['color']]; @endphp
            <div class="relative overflow-hidden bg-white rounded-2xl border {{ $cc['border'] }} p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl {{ $cc['icon_bg'] }} border {{ $cc['border'] }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $card['icon'] }} {{ $cc['icon'] }} text-xl"></i>
                </div>
                <div>
                    <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide">{{ $card['label'] }}</p>
                    <p class="text-2xl font-bold {{ $cc['val'] }} mt-0.5">{{ number_format($card['value']) }}</p>
                </div>
                <div class="absolute -right-2 -bottom-2 w-14 h-14 rounded-full {{ $cc['icon_bg'] }} opacity-50"></div>
            </div>
        @endforeach
    </div>

    {{-- Total refunded banner --}}
    @if($summary['total_refunded'] > 0)
        <div class="flex items-center justify-between rounded-xl bg-primary-50/70 border border-primary-100 px-5 py-3.5">
            <div class="flex items-center gap-2 text-sm text-primary-400">
                <i class="bi bi-currency-exchange text-primary-300"></i>
                <span>Total refunded to date</span>
            </div>
            <span class="font-bold text-lg text-primary-600 tabular-nums">
                {{ number_format($summary['total_refunded'], 2) }}
            </span>
        </div>
    @endif

    {{-- ── Filters ── --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-56">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text"
                   placeholder="Return #, order # or customer email…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            <button wire:click="$set('statusFilter', '')"
                    class="px-3.5 py-2.5 font-medium transition {{ $statusFilter === '' ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                All
            </button>
            @foreach($statuses as $s)
                <button wire:click="$set('statusFilter', '{{ $s }}')"
                        class="px-3.5 py-2.5 font-medium border-l border-primary-100 capitalize transition {{ $statusFilter === $s ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                    {{ ucfirst($s) }}
                </button>
            @endforeach
        </div>
        <input wire:model.live="dateFrom" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo" type="date"
               class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
    </div>

    {{-- ── Returns Table ── --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Return #</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Order</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Reason</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Requested</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Refund Amt</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($returns as $return)
                    @php
                        $badge = match($return->status) {
                            'requested' => 'bg-warning-50 text-warning-700 border border-warning-200',
                            'approved'  => 'bg-info-50 text-info-700 border border-info-200',
                            'received'  => 'bg-secondary-50 text-secondary-700 border border-secondary-200',
                            'completed' => 'bg-success-50 text-success-700 border border-success-200',
                            'rejected'  => 'bg-danger-50 text-danger-600 border border-danger-200',
                            default     => 'bg-primary-50 text-primary-400 border border-primary-100',
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewReturn({{ $return->id }})"
                                    class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg hover:bg-secondary-100 transition">
                                {{ $return->return_number }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5">
                            <code class="font-mono text-xs text-primary-400 bg-primary-50 px-2 py-0.5 rounded-lg">
                                {{ $return->order?->order_number }}
                            </code>
                            <div class="text-xs text-primary-300 mt-0.5">
                                {{ $return->order?->customer_email }}
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-500 max-w-[180px]">
                            <p class="truncate">{{ $return->return_reason ?: '-' }}</p>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">
                            {{ $return->requested_at->format('d M Y') }}
                            <div class="text-[11px] text-primary-200">{{ $return->requested_at->format('H:i') }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-semibold text-primary-600">
                            {{ $return->refund_amount ? number_format($return->refund_amount, 2) : '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                                {{ ucfirst($return->status) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button wire:click="viewReturn({{ $return->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition"
                                        title="View details">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                @if($return->status === 'requested')
                                    <button wire:click="openProcess({{ $return->id }}, 'approve')"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-success-600 hover:bg-success-50 transition">
                                        <i class="bi bi-check2"></i> Approve
                                    </button>
                                @endif
                                @if($return->status === 'approved')
                                    <button wire:click="openProcess({{ $return->id }}, 'receive')"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-info-600 hover:bg-info-50 transition">
                                        <i class="bi bi-box-arrow-in-down"></i> Receive
                                    </button>
                                @endif
                                @if($return->status === 'received')
                                    <button wire:click="openProcess({{ $return->id }}, 'refund')"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-secondary-700 hover:bg-secondary-50 transition">
                                        <i class="bi bi-arrow-counterclockwise"></i> Refund
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-16 text-center">
                            <i class="bi bi-arrow-return-left text-4xl text-primary-100 block mb-3"></i>
                            <p class="text-sm font-medium text-primary-300">No return requests found.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        @if($returns->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">
                {{ $returns->links() }}
            </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════
         RETURN DETAIL SLIDE-OVER
         ══════════════════════════════════════════════ --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex"
             x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail', false)"></div>
            <div class="w-full max-w-xl bg-white shadow-2xl shadow-primary-900/20 flex flex-col h-full overflow-hidden">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">
                                {{ $viewing->return_number }}
                            </code>
                            @php
                                $db = match($viewing->status) {
                                    'requested' => 'bg-warning-50 text-warning-700 border border-warning-200',
                                    'approved'  => 'bg-info-50 text-info-700 border border-info-200',
                                    'received'  => 'bg-secondary-50 text-secondary-700 border border-secondary-200',
                                    'completed' => 'bg-success-50 text-success-700 border border-success-200',
                                    'rejected'  => 'bg-danger-50 text-danger-600 border border-danger-200',
                                    default     => 'bg-primary-50 text-primary-400 border border-primary-100',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $db }}">
                                {{ ucfirst($viewing->status) }}
                            </span>
                        </div>
                        <p class="text-xs text-primary-300 mt-1.5">
                            Requested {{ $viewing->requested_at->format('d M Y, H:i') }}
                        </p>
                    </div>
                    <button wire:click="$set('showDetail', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">

                    {{-- Linked Order --}}
                    <div class="bg-primary-50/50 rounded-xl border border-primary-100 p-4 grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1">Linked Order</p>
                            <code class="font-mono text-sm font-bold text-primary-600">{{ $viewing->order?->order_number }}</code>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1">Customer</p>
                            <p class="text-sm font-medium text-primary-600">{{ $viewing->order?->customer_email }}</p>
                        </div>
                    </div>

                    {{-- Reason & Notes --}}
                    @if($viewing->return_reason)
                        <div class="rounded-xl border border-primary-100 p-4">
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1.5">Return Reason</p>
                            <p class="text-sm text-primary-500">{{ $viewing->return_reason }}</p>
                        </div>
                    @endif
                    @if($viewing->customer_notes)
                        <div class="rounded-xl bg-secondary-50 border border-secondary-200 p-4">
                            <p class="text-xs font-semibold text-secondary-600 uppercase tracking-wide mb-1">Customer Notes</p>
                            <p class="text-sm text-secondary-700">{{ $viewing->customer_notes }}</p>
                        </div>
                    @endif
                    @if($viewing->admin_notes)
                        <div class="rounded-xl bg-info-50 border border-info-200 p-4">
                            <p class="text-xs font-semibold text-info-600 uppercase tracking-wide mb-1">Admin Notes</p>
                            <p class="text-sm text-info-700">{{ $viewing->admin_notes }}</p>
                        </div>
                    @endif

                    {{-- Return Items --}}
                    @if($viewing->items->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">Return Items</p>
                            <div class="rounded-xl border border-primary-100 overflow-hidden">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="bg-primary-50/60 border-b border-primary-100">
                                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                                            <th class="px-4 py-2.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Qty</th>
                                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Reason</th>
                                            <th class="px-4 py-2.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Condition</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-primary-50">
                                        @foreach($viewing->items as $item)
                                            <tr>
                                                <td class="px-4 py-3 font-medium text-primary-600">{{ $item->orderItem?->product_name }}</td>
                                                <td class="px-4 py-3 text-center text-primary-500">{{ $item->quantity }}</td>
                                                <td class="px-4 py-3 text-primary-400 text-xs">{{ $item->reason ?? '-' }}</td>
                                                <td class="px-4 py-3 text-center text-xs capitalize text-primary-400">{{ $item->condition ?? '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    {{-- Refund Info --}}
                    @if($viewing->refund_amount)
                        <div class="rounded-xl bg-success-50 border border-success-200 p-4 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-success-600 uppercase tracking-wide">Refund Amount</p>
                                <p class="text-2xl font-bold text-success-700 mt-0.5">{{ number_format($viewing->refund_amount, 2) }}</p>
                                @if($viewing->refund_method)
                                    <p class="text-xs text-success-500 mt-0.5">via {{ ucfirst(str_replace('_', ' ', $viewing->refund_method)) }}</p>
                                @endif
                            </div>
                            @if($viewing->refunded_at)
                                <div class="text-right">
                                    <p class="text-xs text-success-500">Refunded</p>
                                    <p class="text-sm font-semibold text-success-700">{{ $viewing->refunded_at->format('d M Y') }}</p>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Timeline --}}
                    <div>
                        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-3">Timeline</p>
                        <div class="relative pl-5 space-y-3">
                            <div class="absolute left-[7px] top-1 bottom-1 w-px bg-primary-100"></div>
                            @foreach(array_filter([
                                ['Requested', $viewing->requested_at, 'bg-warning-400'],
                                ['Approved',  $viewing->approved_at,  'bg-info-400'],
                                ['Received',  $viewing->received_at,  'bg-secondary-400'],
                                ['Refunded',  $viewing->refunded_at,  'bg-success-400'],
                            ], fn($e) => $e[1]) as $event)
                                <div class="relative flex gap-3">
                                    <div class="absolute -left-5 top-1.5 w-2.5 h-2.5 rounded-full border-2 border-white {{ $event[2] }} shadow-sm"></div>
                                    <div>
                                        <span class="text-sm font-semibold text-primary-600">{{ $event[0] }}</span>
                                        <p class="text-xs text-primary-300">{{ $event[1]->format('d M Y, H:i') }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════
         PROCESS RETURN MODAL
         ══════════════════════════════════════════════ --}}
    @if($showProcessModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showProcessModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showProcessModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">
                            @if($processAction === 'approve') Approve Return
                            @elseif($processAction === 'receive') Mark as Received
                            @else Process Refund
                            @endif
                        </h2>
                        <p class="text-xs text-primary-300 mt-0.5">
                            @if($processAction === 'approve') Confirm the return request and notify the customer.
                            @elseif($processAction === 'receive') Confirm items have been received back.
                            @else Issue the refund to the customer.
                            @endif
                        </p>
                    </div>
                    <button wire:click="$set('showProcessModal', false)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-4">
                    @if($processAction === 'refund')
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Refund Amount</label>
                            <input wire:model="refundAmount" type="number" step="0.01" min="0"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('refundAmount') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Refund Method</label>
                            <select wire:model="refundMethod"
                                    class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                @foreach($refundMethods as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                            Admin Notes <span class="font-normal normal-case text-primary-200">(optional)</span>
                        </label>
                        <textarea wire:model="adminNotes" rows="3"
                                  placeholder="Internal notes for this action…"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showProcessModal', false)"
                            class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Cancel
                    </button>
                    <button wire:click="processReturn" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="processReturn">Confirm</span>
                        <span wire:loading wire:target="processReturn">Processing…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>