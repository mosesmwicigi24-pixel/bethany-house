<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-credit-card"></i><span>Payments</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Refunds</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Refunds</h1>
            <p class="mt-0.5 text-sm text-primary-300">Track and issue refunds against paid payment records.</p>
        </div>
        <button wire:click="$set('showRefundModal', true)"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-arrow-counterclockwise"></i> Issue Refund
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
            ['Total Refunds',    $summary['total_refunds'],                              'bi-arrow-counterclockwise','border-primary-100',  'bg-primary-50',   'text-primary-400',  'text-primary-600'],
            ['Full Refunds',     $summary['full_refunds'],                               'bi-check-circle',         'border-info-200',      'bg-info-50',      'text-info-500',     'text-info-700'],
            ['Partial Refunds',  $summary['partial_refunds'],                            'bi-dash-circle',          'border-warning-200',   'bg-warning-50',   'text-warning-500',  'text-warning-700'],
            ['Total Refunded',   'KES '.number_format($summary['total_refunded'],2),     'bi-cash-stack',           'border-danger-200',    'bg-danger-50',    'text-danger-500',   'text-danger-600'],
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Payment #, order # or email…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        @if($methods->count())
            <select wire:model.live="methodFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
                <option value="">All Methods</option>
                @foreach($methods as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
            </select>
        @endif
        <input wire:model.live="dateFrom" type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo"   type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Payment #</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Order</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Customer</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Method</th>
                    <th wire:click="sort('amount')" class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">Original <i class="bi bi-arrow-{{ $sortBy==='amount'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Refunded</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Type</th>
                    <th wire:click="sort('refunded_at')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Refunded At <i class="bi bi-arrow-{{ $sortBy==='refunded_at'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($refunds as $payment)
                    @php
                        $isFullRefund = $payment->refund_amount >= $payment->amount;
                        $pct          = $payment->amount > 0 ? round($payment->refund_amount / $payment->amount * 100) : 0;
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewPayment({{ $payment->id }})"
                                    class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg hover:bg-secondary-100 transition">
                                {{ $payment->payment_number }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5">
                            <code class="text-xs font-mono text-primary-400">{{ $payment->order?->order_number ?? '-' }}</code>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-500 truncate max-w-[160px]">
                            {{ trim(($payment->order?->customer_first_name ?? '').' '.($payment->order?->customer_last_name ?? '')) ?: '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-500 capitalize">{{ $payment->payment_method }}</td>
                        <td class="px-5 py-3.5 text-right tabular-nums font-semibold text-primary-600">
                            {{ number_format($payment->amount, 2) }}
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="text-right">
                                <p class="font-bold text-danger-600 tabular-nums">{{ number_format($payment->refund_amount, 2) }}</p>
                                <div class="flex items-center gap-1 justify-end mt-1">
                                    <div class="w-16 h-1 rounded-full bg-primary-100 overflow-hidden">
                                        <div class="h-full rounded-full bg-danger-400" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-[11px] text-primary-300 tabular-nums">{{ $pct }}%</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                {{ $isFullRefund ? 'bg-info-50 text-info-700 border border-info-200' : 'bg-warning-50 text-warning-700 border border-warning-200' }}">
                                {{ $isFullRefund ? 'Full' : 'Partial' }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">
                            {{ $payment->refunded_at?->format('d M Y, H:i') ?? '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <button wire:click="viewPayment({{ $payment->id }})"
                                    class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition opacity-0 group-hover:opacity-100" title="View">
                                <i class="bi bi-eye text-sm"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-5 py-16 text-center">
                        <i class="bi bi-arrow-counterclockwise text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No refunds found.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($refunds->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $refunds->links() }}</div>
        @endif
    </div>

    {{-- Issue Refund Modal --}}
    @if($showRefundModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showRefundModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showRefundModal',false)"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl shadow-primary-900/20">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Issue Refund</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Enter a payment or order number to refund.</p>
                    </div>
                    <button wire:click="$set('showRefundModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-5">
                    {{-- Payment lookup --}}
                    @if(!$refundPayment)
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Payment # or Order #</label>
                            <div class="flex gap-2">
                                <input wire:model="refundPaySearch" wire:keydown.enter="searchPayment" type="text" placeholder="e.g. PAY-… or #10001"
                                       class="flex-1 rounded-xl border border-primary-200 px-4 py-3 text-sm text-primary-600 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-400 transition" />
                                <button wire:click="searchPayment" wire:loading.attr="disabled"
                                        class="rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-3 text-sm font-semibold text-white transition disabled:opacity-60">
                                    <span wire:loading.remove wire:target="searchPayment"><i class="bi bi-search"></i></span>
                                    <span wire:loading wire:target="searchPayment"><i class="bi bi-arrow-clockwise animate-spin"></i></span>
                                </button>
                            </div>
                            @if($refundPayError)
                                <p class="text-xs text-danger-500 mt-1.5 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $refundPayError }}</p>
                            @endif
                        </div>
                    @else
                        {{-- Found payment --}}
                        <div class="rounded-xl bg-success-50 border border-success-200 px-4 py-3 flex items-center gap-3">
                            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-success-700 font-mono">{{ $refundPayment->payment_number }}</p>
                                <p class="text-xs text-success-600 mt-0.5">
                                    {{ $refundPayment->currency_code }} {{ number_format($refundPayment->amount,2) }}
                                    · {{ ucfirst($refundPayment->payment_method) }}
                                    @if($refundPayment->refund_amount > 0)
                                        · <span class="font-semibold">{{ number_format($refundPayment->getRemainingRefundableAmount(),2) }} remaining</span>
                                    @endif
                                </p>
                            </div>
                            <button wire:click="$set('refundPayment', null); $set('refundPaySearch', '')"
                                    class="ml-auto text-success-400 hover:text-success-600 transition flex-shrink-0">
                                <i class="bi bi-x-circle text-sm"></i>
                            </button>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                                Refund Amount <span class="text-danger-500">*</span>
                                <span class="font-normal normal-case text-primary-200">(max {{ number_format($refundPayment->getRemainingRefundableAmount(),2) }})</span>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-primary-300">{{ $refundPayment->currency_code }}</span>
                                <input wire:model="refundAmount" type="number" min="0.01" step="0.01"
                                       max="{{ $refundPayment->getRemainingRefundableAmount() }}"
                                       class="w-full border {{ $errors->has('refundAmount') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl pl-12 pr-3 py-2.5 text-sm text-primary-500 tabular-nums focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            @error('refundAmount')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                                Reason <span class="font-normal normal-case text-primary-200">(optional)</span>
                            </label>
                            <textarea wire:model="refundReason" rows="2" placeholder="e.g. Customer requested cancellation"
                                      class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                        </div>
                    @endif
                </div>

                @if($refundPayment)
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showRefundModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="issueRefund" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-danger-500 hover:bg-danger-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-danger-500/20">
                        <span wire:loading.remove wire:target="issueRefund"><i class="bi bi-arrow-counterclockwise mr-1"></i>Issue Refund</span>
                        <span wire:loading wire:target="issueRefund">Processing…</span>
                    </button>
                </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Detail slide-over (reused from Transactions) --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-lg bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">{{ $viewing->payment_number }}</code>
                    <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([
                            ['Amount',        $viewing->currency_code.' '.number_format($viewing->amount,2)],
                            ['Refunded',      $viewing->currency_code.' '.number_format($viewing->refund_amount,2)],
                            ['Method',        ucfirst($viewing->payment_method)],
                            ['Status',        str_replace('_',' ',ucfirst($viewing->status))],
                            ['Order',         $viewing->order?->order_number ?? '-'],
                            ['Refunded At',   $viewing->refunded_at?->format('d M Y, H:i') ?? '-'],
                        ] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if($viewing->transactions->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">Transaction Log</p>
                            <div class="space-y-2">
                                @foreach($viewing->transactions->sortByDesc('created_at') as $txn)
                                    @php $tc=match($txn->status){'success'=>'bg-success-50 border-success-200','failed'=>'bg-danger-50 border-danger-200',default=>'bg-primary-50 border-primary-100'}; @endphp
                                    <div class="rounded-xl border {{ $tc }} px-3.5 py-3 flex items-center justify-between">
                                        <div>
                                            <p class="text-xs font-semibold text-primary-600 capitalize">{{ str_replace('_',' ',$txn->transaction_type) }}</p>
                                            @if(!empty($txn->request_payload['reason']))
                                                <p class="text-[11px] text-primary-400 mt-0.5">{{ $txn->request_payload['reason'] }}</p>
                                            @endif
                                            <p class="text-[11px] text-primary-300 mt-0.5">{{ $txn->created_at->format('d M Y, H:i') }}</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm font-bold text-primary-600 tabular-nums">{{ number_format($txn->amount,2) }}</p>
                                            <span class="text-[10px] font-semibold capitalize {{ $txn->status==='success'?'text-success-600':'text-danger-600' }}">{{ $txn->status }}</span>
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

</div>