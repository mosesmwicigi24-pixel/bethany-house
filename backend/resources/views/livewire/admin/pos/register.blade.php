<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-calculator"></i><span>Point of Sale</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Cash Register</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Cash Register</h1>
            <p class="mt-0.5 text-sm text-primary-300">Open, monitor and manage the active cash register.</p>
        </div>
        @if(!$register)
            <button wire:click="$set('showOpenModal', true)"
                    class="inline-flex items-center gap-2 rounded-xl bg-success-500 hover:bg-success-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-success-500/20">
                <i class="bi bi-unlock"></i> Open Register
            </button>
        @else
            <div class="flex items-center gap-2">
                <button wire:click="$set('cashAction','in'); $set('showCashModal', true)"
                        class="inline-flex items-center gap-2 rounded-xl bg-success-50 border border-success-200 px-4 py-2.5 text-sm font-semibold text-success-700 hover:bg-success-100 transition">
                    <i class="bi bi-plus-circle"></i> Cash In
                </button>
                <button wire:click="$set('cashAction','out'); $set('showCashModal', true)"
                        class="inline-flex items-center gap-2 rounded-xl bg-danger-50 border border-danger-200 px-4 py-2.5 text-sm font-semibold text-danger-600 hover:bg-danger-100 transition">
                    <i class="bi bi-dash-circle"></i> Cash Out
                </button>
            </div>
        @endif
    </div>

    @if (session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    @if($register)
        {{-- Register status cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @php
                $statCards = [
                    ['label'=>'Expected Cash',    'value'=> number_format($register->expected_cash,2),    'icon'=>'bi-safe2',       'border'=>'border-primary-100',  'ibg'=>'bg-primary-50',  'ic'=>'text-primary-400', 'vc'=>'text-primary-600'],
                    ['label'=>'Total Sales',       'value'=> number_format($register->total_sales,2),      'icon'=>'bi-graph-up',    'border'=>'border-success-200',  'ibg'=>'bg-success-50',  'ic'=>'text-success-500', 'vc'=>'text-success-700'],
                    ['label'=>'Transactions',      'value'=> $register->transaction_count,                 'icon'=>'bi-receipt',     'border'=>'border-info-200',     'ibg'=>'bg-info-50',     'ic'=>'text-info-500',    'vc'=>'text-info-700'],
                    ['label'=>'Total Refunds',     'value'=> number_format($register->total_refunds,2),    'icon'=>'bi-arrow-counterclockwise','border'=>'border-warning-200','ibg'=>'bg-warning-50','ic'=>'text-warning-500','vc'=>'text-warning-700'],
                ];
            @endphp
            @foreach($statCards as $sc)
                <div class="relative overflow-hidden bg-white rounded-2xl border {{ $sc['border'] }} p-4 flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl {{ $sc['ibg'] }} border {{ $sc['border'] }} flex items-center justify-center flex-shrink-0">
                        <i class="bi {{ $sc['icon'] }} {{ $sc['ic'] }} text-xl"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $sc['label'] }}</p>
                        <p class="text-lg font-bold {{ $sc['vc'] }} mt-0.5 tabular-nums">{{ $sc['value'] }}</p>
                    </div>
                    <div class="absolute -right-2 -bottom-2 w-14 h-14 rounded-full {{ $sc['ibg'] }} opacity-50"></div>
                </div>
            @endforeach
        </div>

        {{-- Register details + payment split --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-primary-100 p-5 space-y-3">
                <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1">Register Info</p>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-primary-400">Register</span><span class="font-semibold text-primary-600">{{ $register->register_number }}</span></div>
                    <div class="flex justify-between"><span class="text-primary-400">Outlet</span><span class="font-semibold text-primary-600">{{ $register->outlet?->name }}</span></div>
                    <div class="flex justify-between"><span class="text-primary-400">Opened By</span><span class="font-medium text-primary-500">{{ $register->openedBy?->name }}</span></div>
                    <div class="flex justify-between"><span class="text-primary-400">Opened At</span><span class="font-medium text-primary-500">{{ $register->opened_at->format('d M Y, H:i') }}</span></div>
                    <div class="flex justify-between"><span class="text-primary-400">Opening Balance</span><span class="font-medium text-primary-500 tabular-nums">KES {{ number_format($register->opening_balance,2) }}</span></div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-primary-100 p-5 space-y-3">
                <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-1">Sales by Method</p>
                @foreach(['Cash'=>[$register->total_cash_sales,'bg-warning-400'],'Card'=>[$register->total_card_sales,'bg-info-400'],'M-Pesa'=>[$register->total_mpesa_sales,'bg-success-400']] as $method=>[$amount,$bar])
                    @php $pct = $register->total_sales > 0 ? ($amount/$register->total_sales)*100 : 0; @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <span class="font-medium text-primary-500">{{ $method }}</span>
                            <span class="font-bold text-primary-600 tabular-nums">KES {{ number_format($amount,2) }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-primary-100 overflow-hidden">
                            <div class="h-full rounded-full {{ $bar }} transition-all" style="width: {{ round($pct) }}%"></div>
                        </div>
                    </div>
                @endforeach
                <a href="/admin/pos/end-of-day"
                   class="mt-4 w-full inline-flex items-center justify-center gap-2 rounded-xl border border-primary-200 bg-primary-50 py-2.5 text-sm font-semibold text-primary-500 hover:bg-primary-100 transition">
                    <i class="bi bi-moon-stars"></i> End of Day →
                </a>
            </div>
        </div>

        {{-- Recent transactions --}}
        <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
            <div class="px-5 py-4 border-b border-primary-100">
                <p class="text-sm font-bold text-primary-500">Recent Transactions</p>
            </div>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-primary-100">
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Time</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Type</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Method</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Notes</th>
                        <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Amount</th>
                        <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Balance After</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary-50">
                    @forelse($transactions as $tx)
                        @php
                            $isPos  = $tx->amount >= 0;
                            $txBadge = match($tx->transaction_type) {
                                'sale'     => 'bg-success-50 text-success-700 border border-success-200',
                                'refund'   => 'bg-danger-50 text-danger-600 border border-danger-200',
                                'cash_in'  => 'bg-info-50 text-info-700 border border-info-200',
                                'cash_out' => 'bg-warning-50 text-warning-700 border border-warning-200',
                                default    => 'bg-primary-50 text-primary-400 border border-primary-100',
                            };
                        @endphp
                        <tr class="hover:bg-primary-50/40 transition-colors">
                            <td class="px-5 py-3.5 text-xs text-primary-400 whitespace-nowrap">{{ $tx->created_at->format('H:i:s') }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $txBadge }}">
                                    {{ str_replace('_',' ', ucfirst($tx->transaction_type)) }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-primary-400 capitalize">{{ $tx->payment_method ?? '-' }}</td>
                            <td class="px-5 py-3.5 text-xs text-primary-300 max-w-[150px] truncate">{{ $tx->notes ?? '-' }}</td>
                            <td class="px-5 py-3.5 text-right tabular-nums font-semibold {{ $tx->amount >= 0 ? 'text-success-600' : 'text-danger-600' }}">
                                {{ $tx->amount >= 0 ? '+' : '' }}{{ number_format($tx->amount, 2) }}
                            </td>
                            <td class="px-5 py-3.5 text-right tabular-nums text-primary-500">{{ number_format($tx->balance_after, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-primary-300">No transactions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    @else
        {{-- No register open --}}
        <div class="flex flex-col items-center justify-center py-24 text-center">
            <div class="w-20 h-20 rounded-2xl bg-primary-50 border border-primary-100 flex items-center justify-center mb-5">
                <i class="bi bi-lock text-primary-300 text-3xl"></i>
            </div>
            <h2 class="text-lg font-bold text-primary-500 mb-1">No Register Open</h2>
            <p class="text-sm text-primary-300 mb-6">Open a cash register to start accepting payments.</p>
            <button wire:click="$set('showOpenModal', true)"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-6 py-3 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
                <i class="bi bi-unlock"></i> Open Register
            </button>
        </div>
    @endif

    {{-- Open Register Modal --}}
    @if($showOpenModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showOpenModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showOpenModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Open Cash Register</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Set the opening balance for this shift.</p>
                    </div>
                    <button wire:click="$set('showOpenModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Outlet</label>
                        <select wire:model="openOutletId" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            <option value="">Select outlet…</option>
                            @foreach($outlets as $outlet)
                                <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                            @endforeach
                        </select>
                        @error('openOutletId') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Opening Balance (KES)</label>
                        <input wire:model="openingBalance" type="number" step="0.01" min="0" placeholder="0.00"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-lg font-bold text-primary-600 text-right tabular-nums focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-400 transition" />
                        @error('openingBalance') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <textarea wire:model="openNotes" rows="2" placeholder="Opening shift notes…"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showOpenModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="openRegister" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-success-500 hover:bg-success-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-success-500/20">
                        <span wire:loading.remove wire:target="openRegister"><i class="bi bi-unlock mr-1"></i>Open Register</span>
                        <span wire:loading wire:target="openRegister">Opening…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Cash In/Out Modal --}}
    @if($showCashModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showCashModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showCashModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Cash {{ ucfirst($cashAction) }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Record a cash {{ $cashAction === 'in' ? 'deposit into' : 'withdrawal from' }} the register.</p>
                    </div>
                    <button wire:click="$set('showCashModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Amount (KES)</label>
                        <input wire:model="cashAmount" type="number" step="0.01" min="0.01"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-lg font-bold text-primary-600 text-right tabular-nums focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-400 transition" />
                        @error('cashAmount') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Reason</label>
                        <input wire:model="cashReason" type="text" placeholder="e.g. Float top-up, petty cash…"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        @error('cashReason') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showCashModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="recordCashMovement" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm
                                   {{ $cashAction === 'in' ? 'bg-success-500 hover:bg-success-600 shadow-success-500/20' : 'bg-danger-500 hover:bg-danger-600 shadow-danger-500/20' }}">
                        <span wire:loading.remove wire:target="recordCashMovement">
                            <i class="bi bi-{{ $cashAction === 'in' ? 'plus' : 'dash' }}-circle mr-1"></i>
                            Record Cash {{ ucfirst($cashAction) }}
                        </span>
                        <span wire:loading wire:target="recordCashMovement">Recording…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>