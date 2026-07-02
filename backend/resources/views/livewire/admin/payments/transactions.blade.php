<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-credit-card"></i><span>Payments</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Transactions</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Payment Transactions</h1>
            <p class="mt-0.5 text-sm text-primary-300">All payment records across every order and channel.</p>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        @php $cards = [
            ['Total',      $summary['total'],                                    'bi-receipt',         'border-primary-100',  'bg-primary-50',  'text-primary-400',  'text-primary-600'],
            ['Paid',       $summary['paid'],                                     'bi-check-circle',    'border-success-200',  'bg-success-50',  'text-success-500',  'text-success-700'],
            ['Pending',    $summary['pending'],                                  'bi-clock',           'border-warning-200',  'bg-warning-50',  'text-warning-500',  'text-warning-700'],
            ['Failed',     $summary['failed'],                                   'bi-x-circle',        'border-danger-200',   'bg-danger-50',   'text-danger-500',   'text-danger-600'],
            ['Collected',  'KES '.number_format($summary['total_collected'],2),  'bi-cash-stack',      'border-success-200',  'bg-success-50',  'text-success-500',  'text-success-700'],
            ['Refunded',   'KES '.number_format($summary['total_refunded'],2),   'bi-arrow-counterclockwise','border-warning-200','bg-warning-50','text-warning-500','text-warning-700'],
        ]; @endphp
        @foreach($cards as [$label,$value,$icon,$border,$ibg,$ic,$vc])
            <div class="relative overflow-hidden bg-white rounded-2xl border {{ $border }} p-4 flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl {{ $ibg }} border {{ $border }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $icon }} {{ $ic }}"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                    <p class="text-base font-bold {{ $vc }} mt-0.5 truncate tabular-nums">{{ $value }}</p>
                </div>
                <div class="absolute -right-2 -bottom-2 w-10 h-10 rounded-full {{ $ibg }} opacity-50"></div>
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
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All', 'paid' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $v => $l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
        @if(!empty($methods))
            <select wire:model.live="methodFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
                <option value="">All Methods</option>
                @foreach($methods as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
            </select>
        @endif
        @if(!empty($providers))
            <select wire:model.live="providerFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
                <option value="">All Providers</option>
                @foreach($providers as $p)<option value="{{ $p }}">{{ ucfirst($p) }}</option>@endforeach
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
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Provider Ref</th>
                    <th wire:click="sort('amount')" class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center justify-end gap-1.5">Amount <i class="bi bi-arrow-{{ $sortBy==='amount'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th wire:click="sort('created_at')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Date <i class="bi bi-arrow-{{ $sortBy==='created_at'?($sortDir==='asc'?'up':'down'):'down-up' }} text-primary-200"></i></span>
                    </th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($payments as $payment)
                    @php $badge = match($payment->status) {
                        'paid'           => 'bg-success-50 text-success-700 border border-success-200',
                        'pending'        => 'bg-warning-50 text-warning-700 border border-warning-200',
                        'failed'         => 'bg-danger-50 text-danger-600 border border-danger-200',
                        'refunded'       => 'bg-info-50 text-info-700 border border-info-200',
                        'partial_refund' => 'bg-secondary-50 text-secondary-700 border border-secondary-200',
                        default          => 'bg-primary-50 text-primary-400 border border-primary-100',
                    }; @endphp
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
                        <td class="px-5 py-3.5 text-sm text-primary-500 max-w-[160px] truncate">
                            {{ $payment->order?->customer_first_name }} {{ $payment->order?->customer_last_name }}
                            <p class="text-xs text-primary-300 truncate">{{ $payment->order?->customer_email }}</p>
                        </td>
                        <td class="px-5 py-3.5">
                            <div class="flex items-center gap-2">
                                @php $icon = match(strtolower($payment->payment_method ?? '')) {
                                    'mpesa','m-pesa' => 'bi-phone',
                                    'card','stripe'  => 'bi-credit-card',
                                    'cash'           => 'bi-cash',
                                    default          => 'bi-wallet2',
                                }; @endphp
                                <i class="bi {{ $icon }} text-primary-300 text-sm"></i>
                                <span class="text-sm text-primary-500 capitalize">{{ $payment->payment_method }}</span>
                            </div>
                            @if($payment->provider)
                                <p class="text-xs text-primary-300 mt-0.5 capitalize">{{ $payment->provider }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            @if($payment->provider_transaction_id)
                                <code class="text-[11px] font-mono text-primary-400 bg-primary-50 border border-primary-100 px-1.5 py-0.5 rounded">
                                    {{ Str::limit($payment->provider_transaction_id, 18) }}
                                </code>
                            @else
                                <span class="text-xs text-primary-200">-</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <p class="font-semibold text-primary-600 tabular-nums">{{ number_format($payment->amount, 2) }}</p>
                            <p class="text-xs text-primary-300">{{ $payment->currency_code }}</p>
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                                {{ str_replace('_',' ',ucfirst($payment->status)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">
                            {{ $payment->paid_at?->format('d M Y, H:i') ?? $payment->created_at->format('d M Y, H:i') }}
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
                        <i class="bi bi-receipt text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No transactions found.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($payments->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $payments->links() }}</div>
        @endif
    </div>

    {{-- Detail slide-over --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail',false)"></div>
            <div class="w-full max-w-lg bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">{{ $viewing->payment_number }}</code>
                        @php $sb=match($viewing->status){'paid'=>'bg-success-50 text-success-700 border border-success-200','pending'=>'bg-warning-50 text-warning-700 border border-warning-200','failed'=>'bg-danger-50 text-danger-600 border border-danger-200','refunded'=>'bg-info-50 text-info-700 border border-info-200',default=>'bg-primary-50 text-primary-400 border border-primary-100'}; @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $sb }}">{{ str_replace('_',' ',ucfirst($viewing->status)) }}</span>
                    </div>
                    <button wire:click="$set('showDetail',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    {{-- Info grid --}}
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([
                            ['Amount',        $viewing->currency_code . ' ' . number_format($viewing->amount,2)],
                            ['Method',        ucfirst($viewing->payment_method)],
                            ['Provider',      $viewing->provider ? ucfirst($viewing->provider) : '-'],
                            ['Phone',         $viewing->phone_number ?? '-'],
                            ['Order',         $viewing->order?->order_number ?? '-'],
                            ['Customer',      trim(($viewing->order?->customer_first_name ?? '').' '.($viewing->order?->customer_last_name ?? '')) ?: '-'],
                            ['Paid At',       $viewing->paid_at?->format('d M Y, H:i') ?? '-'],
                            ['Refunded',      $viewing->refund_amount > 0 ? $viewing->currency_code.' '.number_format($viewing->refund_amount,2) : '-'],
                        ] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                    {{-- Provider transaction ID --}}
                    @if($viewing->provider_transaction_id)
                        <div class="rounded-xl bg-primary-50/50 border border-primary-100 p-4">
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1.5">Provider Transaction ID</p>
                            <code class="text-sm font-mono text-primary-600 break-all">{{ $viewing->provider_transaction_id }}</code>
                            @if($viewing->provider_reference)
                                <p class="text-xs text-primary-400 mt-1">Ref: {{ $viewing->provider_reference }}</p>
                            @endif
                        </div>
                    @endif
                    {{-- Provider response --}}
                    @if($viewing->provider_response)
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1.5">Provider Response</p>
                            <pre class="text-[11px] font-mono bg-primary-50 border border-primary-100 rounded-xl p-4 overflow-x-auto text-primary-500">{{ json_encode($viewing->provider_response, JSON_PRETTY_PRINT) }}</pre>
                        </div>
                    @endif
                    {{-- Transaction log --}}
                    @if($viewing->transactions->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">Transaction Log</p>
                            <div class="space-y-2">
                                @foreach($viewing->transactions->sortByDesc('created_at') as $txn)
                                    @php $tc=match($txn->status){'success'=>'bg-success-50 border-success-200','failed'=>'bg-danger-50 border-danger-200',default=>'bg-primary-50 border-primary-100'}; @endphp
                                    <div class="rounded-xl border {{ $tc }} px-3.5 py-3 flex items-center justify-between">
                                        <div>
                                            <p class="text-xs font-semibold text-primary-600 capitalize">{{ str_replace('_',' ',$txn->transaction_type) }}</p>
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