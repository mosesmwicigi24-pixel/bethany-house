<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-calculator"></i><span>Point of Sale</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>End of Day</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">End of Day</h1>
            <p class="mt-0.5 text-sm text-primary-300">Review your shift summary and close the cash register.</p>
        </div>
    </div>

    @if($justClosed && $summary)
        {{-- ── Closed successfully ── --}}
        <div class="max-w-lg mx-auto space-y-5">
            <div class="bg-white rounded-2xl border border-success-200 overflow-hidden shadow-sm">
                <div class="bg-primary-500 px-6 py-8 text-center text-white">
                    <div class="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center mx-auto mb-4">
                        <i class="bi bi-moon-stars text-3xl"></i>
                    </div>
                    <p class="font-bold text-xl">Shift Closed</p>
                    <p class="text-primary-200 text-sm mt-1">{{ $closedReg?->register_number }} · {{ now()->format('d M Y, H:i') }}</p>
                </div>
                <div class="px-6 py-5 space-y-3 text-sm">
                    @foreach([
                        ['Opening Balance',   'KES '.number_format($summary['opening_balance'],2),  'text-primary-500'],
                        ['Total Sales',        'KES '.number_format($summary['total_sales'],2),       'text-success-600'],
                        ['Total Refunds',      '− KES '.number_format($summary['total_refunds'],2),   'text-danger-500'],
                        ['Net Sales',          'KES '.number_format($summary['net_sales'],2),          'text-primary-600 font-bold'],
                        ['Expected Cash',      'KES '.number_format($summary['expected_cash'],2),      'text-primary-500'],
                        ['Actual Cash',        'KES '.number_format($summary['actual_cash'],2),        'text-primary-500'],
                    ] as [$label, $val, $vc])
                        <div class="flex justify-between border-b border-primary-50 pb-2.5">
                            <span class="text-primary-400">{{ $label }}</span>
                            <span class="tabular-nums {{ $vc }}">{{ $val }}</span>
                        </div>
                    @endforeach
                    @php $diff = $summary['cash_difference']; @endphp
                    <div class="flex justify-between items-center rounded-xl px-4 py-3 {{ $diff == 0 ? 'bg-success-50 border border-success-200' : ($diff > 0 ? 'bg-info-50 border border-info-200' : 'bg-danger-50 border border-danger-200') }}">
                        <span class="font-semibold {{ $diff == 0 ? 'text-success-700' : ($diff > 0 ? 'text-info-700' : 'text-danger-700') }}">
                            {{ $diff == 0 ? 'Balanced ✓' : ($diff > 0 ? 'Cash Overage' : 'Cash Shortage') }}
                        </span>
                        <span class="font-bold text-lg tabular-nums {{ $diff == 0 ? 'text-success-700' : ($diff > 0 ? 'text-info-700' : 'text-danger-700') }}">
                            {{ $diff >= 0 ? '+' : '' }}KES {{ number_format($diff,2) }}
                        </span>
                    </div>
                </div>
                <div class="px-6 pb-5 flex gap-2">
                    <button onclick="window.print()" class="flex-1 py-2.5 rounded-xl border border-primary-200 text-sm font-semibold text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-printer mr-1"></i>Print Report
                    </button>
                    <a href="/admin/pos/sale" class="flex-1 py-2.5 rounded-xl bg-primary-500 text-sm font-semibold text-white hover:bg-primary-600 transition text-center">
                        New Shift →
                    </a>
                </div>
            </div>
        </div>

    @elseif(!$register)
        <div class="flex flex-col items-center justify-center py-20 text-center">
            <div class="w-20 h-20 rounded-2xl bg-primary-50 border border-primary-100 flex items-center justify-center mb-5">
                <i class="bi bi-moon text-primary-300 text-3xl"></i>
            </div>
            <h2 class="text-lg font-bold text-primary-500 mb-1">No Active Shift</h2>
            <p class="text-sm text-primary-300 mb-6">There is no open register to close. Open a register first.</p>
            <a href="/admin/pos/register"
               class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white transition">
                <i class="bi bi-unlock"></i> Open Register
            </a>
        </div>

    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {{-- Left: Shift summary --}}
            <div class="space-y-4">
                {{-- Register info --}}
                <div class="bg-white rounded-2xl border border-primary-100 p-5">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-primary-50 border border-primary-100 flex items-center justify-center">
                            <i class="bi bi-safe2 text-primary-400 text-lg"></i>
                        </div>
                        <div>
                            <p class="font-bold text-primary-600 text-sm">{{ $register->register_number }}</p>
                            <p class="text-xs text-primary-300">{{ $register->outlet?->name }} · Opened {{ $register->opened_at->format('H:i') }}</p>
                        </div>
                        <div class="ml-auto flex items-center gap-1.5 rounded-full bg-success-50 border border-success-200 px-2.5 py-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-success-500 animate-pulse"></span>
                            <span class="text-xs font-semibold text-success-700">Open</span>
                        </div>
                    </div>
                    <div class="space-y-2 text-sm">
                        @foreach([
                            ['Transactions',     $shiftSales['transaction_count'] ?? 0,     '', 'text-primary-600'],
                            ['Total Revenue',    'KES '.number_format($shiftSales['total_revenue']??0,2), '', 'text-success-600 font-bold'],
                            ['Cash Sales',       'KES '.number_format($shiftSales['cash_total']??0,2),    '', 'text-warning-600'],
                            ['Card Sales',       'KES '.number_format($shiftSales['card_total']??0,2),    '', 'text-info-600'],
                            ['M-Pesa Sales',     'KES '.number_format($shiftSales['mpesa_total']??0,2),   '', 'text-success-600'],
                            ['Total Discounts',  'KES '.number_format($shiftSales['total_discounts']??0,2),'','text-primary-400'],
                        ] as [$label, $val, , $vc])
                            <div class="flex justify-between border-b border-primary-50 pb-2">
                                <span class="text-primary-400">{{ $label }}</span>
                                <span class="tabular-nums {{ $vc }}">{{ $val }}</span>
                            </div>
                        @endforeach
                        <div class="flex justify-between pt-1">
                            <span class="font-semibold text-primary-500">Opening Balance</span>
                            <span class="tabular-nums font-semibold text-primary-600">KES {{ number_format($register->opening_balance,2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="font-semibold text-primary-500">Expected Cash</span>
                            <span class="tabular-nums font-bold text-primary-600 text-base">KES {{ number_format($register->expected_cash,2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right: Close form --}}
            <div class="space-y-4">
                <div class="bg-white rounded-2xl border border-primary-100 p-5 space-y-4">
                    <p class="text-sm font-bold text-primary-500">Count the Cash Drawer</p>

                    {{-- Denomination toggle --}}
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input wire:model.live="useDenominations" type="checkbox"
                               class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                        <span class="text-sm font-medium text-primary-500">Use denomination count</span>
                    </label>

                    @if($useDenominations)
                        <div class="rounded-xl border border-primary-100 overflow-hidden">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-primary-50/60 border-b border-primary-100">
                                        <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Denomination</th>
                                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Count</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Value</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-primary-50">
                                    @foreach($denominations as $denom => $count)
                                        <tr>
                                            <td class="px-4 py-2.5 font-medium text-primary-500">KES {{ number_format($denom) }}</td>
                                            <td class="px-4 py-2.5 text-center">
                                                <input wire:model.live="denominations.{{ $denom }}"
                                                       type="number" min="0"
                                                       class="w-20 rounded-lg border border-primary-100 px-2 py-1.5 text-sm text-center text-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                                            </td>
                                            <td class="px-4 py-2.5 text-right tabular-nums text-primary-500">
                                                {{ number_format($denom * (int)$count, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="bg-primary-50 border-t border-primary-200">
                                        <td colspan="2" class="px-4 py-3 font-bold text-primary-500">Total Counted</td>
                                        <td class="px-4 py-3 text-right font-bold text-primary-600 tabular-nums text-base">
                                            KES {{ number_format($denomTotal, 2) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                            Actual Cash in Drawer <span class="font-normal normal-case text-primary-200">(KES)</span>
                        </label>
                        <input wire:model.live="actualCash" type="number" step="0.01" min="0"
                               class="w-full rounded-xl border border-primary-200 px-4 py-3 text-xl font-bold text-primary-600 text-right tabular-nums focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-400 transition" />
                        @error('actualCash') <p class="text-xs text-danger-500 mt-1">{{ $message }}</p> @enderror

                        @if($actualCash !== '')
                            @php
                                $diff = (float)$actualCash - (float)$register->expected_cash;
                                $isBalanced = abs($diff) < 0.01;
                            @endphp
                            <div class="mt-2.5 flex items-center justify-between rounded-xl px-4 py-2.5
                                        {{ $isBalanced ? 'bg-success-50 border border-success-200' : ($diff > 0 ? 'bg-info-50 border border-info-200' : 'bg-danger-50 border border-danger-200') }}">
                                <span class="text-sm font-semibold {{ $isBalanced ? 'text-success-700' : ($diff > 0 ? 'text-info-700' : 'text-danger-700') }}">
                                    {{ $isBalanced ? '✓ Balanced' : ($diff > 0 ? 'Overage' : 'Shortage') }}
                                </span>
                                <span class="font-bold tabular-nums {{ $isBalanced ? 'text-success-700' : ($diff > 0 ? 'text-info-700' : 'text-danger-700') }}">
                                    {{ $diff >= 0 ? '+' : '' }}KES {{ number_format($diff, 2) }}
                                </span>
                            </div>
                        @endif
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                            Closing Notes <span class="font-normal normal-case text-primary-200">(optional)</span>
                        </label>
                        <textarea wire:model="closingNotes" rows="2"
                                  placeholder="Any notes for end of day…"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>

                    <button wire:click="confirmClose"
                            wire:loading.attr="disabled"
                            @if(!$actualCash) disabled @endif
                            class="w-full py-3.5 rounded-2xl font-bold text-sm transition
                                   {{ $actualCash ? 'bg-primary-500 hover:bg-primary-600 text-white shadow-sm shadow-primary-500/20 active:scale-[0.98]' : 'bg-primary-100 text-primary-300 cursor-not-allowed' }}">
                        <i class="bi bi-moon-stars mr-2"></i>Close Register & End Shift
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Confirm close modal --}}
    @if($showConfirm && $register)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showConfirm', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showConfirm', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="px-6 py-5 text-center">
                    <div class="w-14 h-14 rounded-full bg-warning-50 border border-warning-200 flex items-center justify-center mx-auto mb-4">
                        <i class="bi bi-exclamation-triangle text-warning-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Close Register?</h2>
                    <p class="text-sm text-primary-400 mt-1.5">
                        This will end your shift and close register <strong>{{ $register->register_number }}</strong>.
                        Actual cash entered: <strong class="text-primary-600">KES {{ number_format((float)$actualCash,2) }}</strong>.
                    </p>
                </div>
                <div class="flex items-center gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showConfirm', false)"
                            class="flex-1 rounded-xl border border-primary-100 bg-white py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">
                        Cancel
                    </button>
                    <button wire:click="closeRegister" wire:loading.attr="disabled"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="closeRegister">Confirm Close</span>
                        <span wire:loading wire:target="closeRegister">Closing…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>