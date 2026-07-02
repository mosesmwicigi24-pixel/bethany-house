<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-scissors"></i><span>Production</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Quality Control</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Quality Control</h1>
            <p class="mt-0.5 text-sm text-primary-300">Inspect and approve completed garments before dispatch.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="relative overflow-hidden bg-white rounded-2xl border border-warning-200 p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-warning-50 border border-warning-200 flex items-center justify-center flex-shrink-0">
                <i class="bi bi-hourglass-split text-warning-500 text-xl"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-warning-500 uppercase tracking-wide">Awaiting QC</p>
                <p class="text-3xl font-bold text-warning-700 mt-0.5">{{ $summary['awaiting_qc'] }}</p>
            </div>
            <div class="absolute -right-3 -bottom-3 w-16 h-16 rounded-full bg-warning-50 opacity-60"></div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-2xl border border-success-200 p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-success-50 border border-success-200 flex items-center justify-center flex-shrink-0">
                <i class="bi bi-patch-check text-success-500 text-xl"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-success-500 uppercase tracking-wide">Passed Today</p>
                <p class="text-3xl font-bold text-success-700 mt-0.5">{{ $summary['passed_today'] }}</p>
            </div>
            <div class="absolute -right-3 -bottom-3 w-16 h-16 rounded-full bg-success-50 opacity-60"></div>
        </div>
        <div class="relative overflow-hidden bg-white rounded-2xl border border-danger-200 p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-danger-50 border border-danger-200 flex items-center justify-center flex-shrink-0">
                <i class="bi bi-x-octagon text-danger-500 text-xl"></i>
            </div>
            <div>
                <p class="text-xs font-semibold text-danger-500 uppercase tracking-wide">Failed Today</p>
                <p class="text-3xl font-bold text-danger-700 mt-0.5">{{ $summary['failed_today'] }}</p>
            </div>
            <div class="absolute -right-3 -bottom-3 w-16 h-16 rounded-full bg-danger-50 opacity-60"></div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Order # or product name…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach([''=>'All', 'pending'=>'Awaiting', 'pass'=>'Passed', 'fail'=>'Failed'] as $val => $label)
                <button wire:click="$set('resultFilter', '{{ $val }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $resultFilter === $val ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Orders table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Order</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Qty</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Tasks Done</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">QC Status</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Inspected</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($orders as $order)
                    @php
                        $qc       = $order->specifications['qc'] ?? null;
                        $qcResult = $qc['result'] ?? null;
                        $qcBadge  = match($qcResult) {
                            'pass'             => 'bg-success-50 text-success-700 border border-success-200',
                            'conditional_pass' => 'bg-info-50 text-info-700 border border-info-200',
                            'fail'             => 'bg-danger-50 text-danger-600 border border-danger-200',
                            default            => 'bg-warning-50 text-warning-700 border border-warning-200',
                        };
                        $qcLabel  = match($qcResult) {
                            'pass'             => 'Passed',
                            'conditional_pass' => 'Cond. Pass',
                            'fail'             => 'Failed',
                            default            => 'Awaiting QC',
                        };
                        $tasks       = $order->tasks;
                        $doneTasks   = $tasks->where('status', 'completed')->count();
                        $totalTasks  = $tasks->count();
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <code class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg">
                                {{ $order->order_number }}
                            </code>
                        </td>
                        <td class="px-5 py-3.5">
                            <p class="font-medium text-primary-600">{{ $order->product?->translations->first()?->name ?? '-' }}</p>
                            @if($order->variant)<p class="text-xs text-primary-300 mt-0.5">{{ $order->variant->variant_name }}</p>@endif
                        </td>
                        <td class="px-5 py-3.5 text-center font-semibold text-primary-600">{{ $order->quantity }}</td>
                        <td class="px-5 py-3.5">
                            @if($totalTasks > 0)
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-1.5 rounded-full bg-primary-100 overflow-hidden">
                                        <div class="h-full rounded-full bg-success-400" style="width: {{ round($doneTasks / $totalTasks * 100) }}%"></div>
                                    </div>
                                    <span class="text-xs text-primary-400 tabular-nums">{{ $doneTasks }}/{{ $totalTasks }}</span>
                                </div>
                            @else
                                <span class="text-xs text-primary-200">No tasks</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $qcBadge }}">
                                @if($qcResult === null)<span class="w-1.5 h-1.5 rounded-full bg-warning-400 animate-pulse inline-block mr-1.5"></span>@endif
                                {{ $qcLabel }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400">
                            @if($qc && isset($qc['checked_at']))
                                {{ \Carbon\Carbon::parse($qc['checked_at'])->format('d M Y, H:i') }}
                            @else
                                <span class="text-primary-200">-</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <button wire:click="openInspect({{ $order->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition
                                           {{ $qcResult === null ? 'bg-primary-500 text-white hover:bg-primary-600 shadow-sm shadow-primary-500/20' : 'border border-primary-200 text-primary-500 hover:bg-primary-50' }}">
                                <i class="bi bi-clipboard-check text-xs"></i>
                                {{ $qcResult === null ? 'Inspect' : 'Re-inspect' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-16 text-center">
                        <i class="bi bi-clipboard-check text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No orders pending quality control.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($orders->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $orders->links() }}</div>
        @endif
    </div>

    {{-- QC Inspection Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal', false)"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-6">

                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">QC Inspection</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Complete the checklist and record the inspection result.</p>
                    </div>
                    <button wire:click="$set('showModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-5">
                    {{-- Checklist --}}
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-2.5">
                            <i class="bi bi-clipboard-check mr-1"></i>Quality Checklist
                        </label>
                        <div class="space-y-2">
                            @foreach($checklistItems as $i => $item)
                                <label class="flex items-center gap-3 rounded-xl border {{ $item['checked'] ? 'border-success-200 bg-success-50/40' : 'border-primary-100 bg-primary-50/40' }} px-3.5 py-2.5 cursor-pointer transition hover:border-primary-200">
                                    <input wire:model.live="checklistItems.{{ $i }}.checked" type="checkbox"
                                           class="w-4 h-4 rounded border-primary-200 text-success-500 focus:ring-success-500/20 flex-shrink-0" />
                                    <span class="text-sm {{ $item['checked'] ? 'text-success-700 line-through decoration-success-400' : 'text-primary-500' }}">
                                        {{ $item['label'] }}
                                    </span>
                                    @if($item['checked'])
                                        <i class="bi bi-check-circle-fill text-success-500 ml-auto text-sm"></i>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        @php $checkedCount = count(array_filter($checklistItems, fn($i) => $i['checked'])); $total = count($checklistItems); @endphp
                        <div class="mt-2.5 flex items-center gap-2">
                            <div class="flex-1 h-1.5 rounded-full bg-primary-100 overflow-hidden">
                                <div class="h-full rounded-full {{ $checkedCount === $total ? 'bg-success-500' : 'bg-info-400' }} transition-all"
                                     style="width: {{ $total > 0 ? round($checkedCount / $total * 100) : 0 }}%"></div>
                            </div>
                            <span class="text-xs text-primary-400 tabular-nums">{{ $checkedCount }}/{{ $total }}</span>
                        </div>
                    </div>

                    {{-- QC Result --}}
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-2">Inspection Result</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach(['pass'=>['Passed','bi-check-circle','success'], 'conditional_pass'=>['Cond. Pass','bi-exclamation-circle','info'], 'fail'=>['Failed','bi-x-circle','danger']] as $val => [$label, $icon, $color])
                                <button wire:click="$set('qcResult', '{{ $val }}')"
                                        class="flex flex-col items-center gap-1.5 rounded-xl border py-3 transition font-semibold text-sm
                                               {{ $qcResult === $val
                                                  ? "bg-{$color}-500 text-white border-{$color}-500 shadow-sm"
                                                  : "bg-white text-primary-400 border-primary-100 hover:border-primary-300" }}">
                                    <i class="bi {{ $icon }} text-lg"></i>
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if($qcResult === 'fail')
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Defect Type</label>
                                <input wire:model="defectType" type="text" placeholder="e.g. loose stitching"
                                       class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Remedy Action</label>
                                <input wire:model="remedyAction" type="text" placeholder="e.g. re-stitch seam"
                                       class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Inspector Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <textarea wire:model="qcNotes" rows="2" placeholder="Additional observations…"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="saveInspection" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm
                                   {{ $qcResult === 'fail' ? 'bg-danger-500 hover:bg-danger-600 shadow-danger-500/20' : ($qcResult === 'conditional_pass' ? 'bg-info-500 hover:bg-info-600 shadow-info-500/20' : 'bg-success-500 hover:bg-success-600 shadow-success-500/20') }}">
                        <span wire:loading.remove wire:target="saveInspection">
                            <i class="bi bi-clipboard-check mr-1"></i>Save Inspection
                        </span>
                        <span wire:loading wire:target="saveInspection">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>