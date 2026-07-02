<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-scissors"></i><span>Production</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Orders</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Production Orders</h1>
            <p class="mt-0.5 text-sm text-primary-300">Track and manage all garment production orders.</p>
        </div>
        <a href="{{ route('admin.production.orders.create') }}"
           class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> New Order
        </a>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary tabs --}}
    <div class="grid grid-cols-5 gap-3">
        @php
            $tabs = [
                ''              => ['label'=>'All',           'count'=> array_sum(array_values($summary)),         'color'=>'primary'],
                'pending'       => ['label'=>'Pending',       'count'=> $summary['pending'],                        'color'=>'warning'],
                'in_progress'   => ['label'=>'In Progress',   'count'=> $summary['in_progress'],                    'color'=>'info'],
                'quality_check' => ['label'=>'QC',            'count'=> $summary['quality_check'],                  'color'=>'secondary'],
                'overdue'       => ['label'=>'Overdue',       'count'=> $summary['overdue'],                        'color'=>'danger'],
            ];
            $cm = ['primary'=>'bg-primary-500 text-white border-primary-500','warning'=>'bg-warning-500 text-white border-warning-500','info'=>'bg-info-500 text-white border-info-500','secondary'=>'bg-secondary-600 text-white border-secondary-600','danger'=>'bg-danger-500 text-white border-danger-500'];
            $ci = ['primary'=>'bg-white text-primary-400 border-primary-100 hover:border-primary-300','warning'=>'bg-white text-warning-600 border-primary-100 hover:border-warning-300','info'=>'bg-white text-info-600 border-primary-100 hover:border-info-300','secondary'=>'bg-white text-secondary-700 border-primary-100 hover:border-secondary-300','danger'=>'bg-white text-danger-600 border-primary-100 hover:border-danger-300'];
        @endphp
        @foreach($tabs as $val => $tab)
            <button wire:click="$set('statusFilter', '{{ $val }}')"
                    class="rounded-xl border px-3 py-3 text-center transition cursor-pointer {{ $statusFilter === $val ? $cm[$tab['color']] : $ci[$tab['color']] }}">
                <div class="text-xl font-bold leading-none">{{ $tab['count'] }}</div>
                <div class="text-xs mt-1 font-medium opacity-90">{{ $tab['label'] }}</div>
            </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Order # or product name…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="priorityFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Priorities</option>
            @foreach($priorities as $p)<option value="{{ $p }}">{{ ucfirst($p) }}</option>@endforeach
        </select>
        <select wire:model.live="outletFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Outlets</option>
            @foreach($outlets as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
        </select>
        <input wire:model.live="dateFrom" type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        <input wire:model.live="dateTo"   type="date" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Order</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Qty</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Priority</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Due Date</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Progress</th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($orders as $order)
                    @php
                        $pct     = $order->tasks_count > 0 ? round($order->completed_tasks_count / $order->tasks_count * 100) : 0;
                        $isOver  = $order->isOverdue();
                        $priCfg  = match($order->priority) {
                            'urgent' => 'bg-danger-100 text-danger-700',
                            'high'   => 'bg-warning-100 text-warning-700',
                            'normal' => 'bg-primary-50 text-primary-400',
                            default  => 'bg-primary-50 text-primary-300',
                        };
                        $stBadge = match($order->status) {
                            'pending'       => 'bg-warning-50 text-warning-700 border border-warning-200',
                            'in_progress'   => 'bg-info-50 text-info-700 border border-info-200',
                            'on_hold'       => 'bg-primary-50 text-primary-400 border border-primary-200',
                            'quality_check' => 'bg-secondary-50 text-secondary-700 border border-secondary-200',
                            'completed'     => 'bg-success-50 text-success-700 border border-success-200',
                            'cancelled'     => 'bg-danger-50 text-danger-600 border border-danger-200',
                            default         => 'bg-primary-50 text-primary-400 border border-primary-100',
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group {{ $isOver ? 'bg-danger-50/20' : '' }}">
                        <td class="px-5 py-3.5">
                            <button wire:click="viewOrder({{ $order->id }})"
                                    class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg hover:bg-secondary-100 transition">
                                {{ $order->order_number }}
                            </button>
                        </td>
                        <td class="px-5 py-3.5">
                            <div class="font-medium text-primary-600">{{ $order->product?->translations->first()?->name }}</div>
                            @if($order->variant)<div class="text-xs text-primary-300 mt-0.5">{{ $order->variant->variant_name }}</div>@endif
                        </td>
                        <td class="px-5 py-3.5 text-center font-semibold text-primary-600">{{ $order->quantity }}</td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $priCfg }} capitalize">
                                {{ $order->priority }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="{{ $isOver ? 'text-danger-600 font-semibold' : 'text-primary-400' }} text-sm">
                                @if($order->due_date)
                                    {{ $order->due_date->format('d M Y') }}
                                    @if($isOver)<div class="text-[11px] text-danger-500 font-medium">Overdue</div>@endif
                                @else -
                                @endif
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            @if($order->tasks_count > 0)
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-1.5 rounded-full bg-primary-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $pct === 100 ? 'bg-success-500' : 'bg-info-400' }} transition-all"
                                             style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-xs text-primary-400 tabular-nums w-8 text-right">{{ $pct }}%</span>
                                </div>
                                <div class="text-[11px] text-primary-300 mt-0.5">
                                    {{ $order->completed_tasks_count }}/{{ $order->tasks_count }} tasks
                                </div>
                            @else
                                <span class="text-xs text-primary-200">No tasks</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $stBadge }}">
                                {{ str_replace('_', ' ', ucfirst($order->status)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="viewOrder({{ $order->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition">
                                    <i class="bi bi-eye text-sm"></i>
                                </button>
                                <button wire:click="openStatusModal({{ $order->id }}, '{{ $order->status }}')"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition">
                                    <i class="bi bi-pencil-square text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-5 py-16 text-center">
                        <i class="bi bi-scissors text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No production orders found.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($orders->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $orders->links() }}</div>
        @endif
    </div>

    {{-- Detail slide-over --}}
    @if($showDetail && $viewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showDetail', false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showDetail', false)"></div>
            <div class="w-full max-w-2xl bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                {{-- Slide-over header --}}
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <code class="font-mono text-sm font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2.5 py-0.5 rounded-lg">{{ $viewing->order_number }}</code>
                        @php $sb = match($viewing->status){
                            'pending'=>'bg-warning-50 text-warning-700 border border-warning-200',
                            'in_progress'=>'bg-info-50 text-info-700 border border-info-200',
                            'quality_check'=>'bg-secondary-50 text-secondary-700 border border-secondary-200',
                            'completed'=>'bg-success-50 text-success-700 border border-success-200',
                            'cancelled'=>'bg-danger-50 text-danger-600 border border-danger-200',
                            default=>'bg-primary-50 text-primary-400 border border-primary-100'
                        }; @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $sb }}">
                            {{ str_replace('_',' ', ucfirst($viewing->status)) }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="openStatusModal({{ $viewing->id }}, '{{ $viewing->status }}')"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-primary-100 px-3 py-1.5 text-xs font-semibold text-primary-400 hover:text-primary-600 hover:border-primary-300 transition">
                            <i class="bi bi-pencil-square text-xs"></i> Update Status
                        </button>
                        <button wire:click="$set('showDetail', false)"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6">
                    {{-- Product info --}}
                    <div class="grid grid-cols-3 gap-3">
                        @foreach([
                            ['Product', $viewing->product?->translations->first()?->name ?? '-'],
                            ['Quantity', $viewing->quantity . ' units'],
                            ['Priority', ucfirst($viewing->priority)],
                            ['Due Date', $viewing->due_date?->format('d M Y') ?? '-'],
                            ['Outlet', $viewing->outlet?->name ?? '-'],
                            ['Created By', $viewing->createdBy?->name ?? '-'],
                        ] as [$label, $val])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $label }}</p>
                                <p class="text-sm font-semibold text-primary-600 mt-0.5">{{ $val }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Progress bar --}}
                    @php $pct = $viewing->getCompletionPercentage(); @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1.5">
                            <span class="font-semibold text-primary-400 uppercase tracking-wide">Overall Progress</span>
                            <span class="font-bold text-primary-600 tabular-nums">{{ $pct }}%</span>
                        </div>
                        <div class="h-2.5 rounded-full bg-primary-100 overflow-hidden">
                            <div class="h-full rounded-full {{ $pct === 100 ? 'bg-success-500' : 'bg-info-400' }} transition-all"
                                 style="width: {{ $pct }}%"></div>
                        </div>
                    </div>

                    {{-- Tasks --}}
                    @if($viewing->tasks->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">
                                <i class="bi bi-list-task mr-1"></i> Tasks ({{ $viewing->tasks->count() }})
                            </p>
                            <div class="space-y-2">
                                @foreach($viewing->tasks->sortBy('stage.sort_order') as $task)
                                    @php
                                        $tc = match($task->status){
                                            'completed'  => 'bg-success-50 border-success-200',
                                            'in_progress'=> 'bg-info-50 border-info-200',
                                            'blocked'    => 'bg-danger-50 border-danger-200',
                                            default      => 'bg-primary-50 border-primary-100',
                                        };
                                        $dot = match($task->status){
                                            'completed'  => 'bg-success-500',
                                            'in_progress'=> 'bg-info-400 animate-pulse',
                                            'blocked'    => 'bg-danger-500',
                                            default      => 'bg-primary-200',
                                        };
                                    @endphp
                                    <div class="flex items-center gap-3 rounded-xl border {{ $tc }} px-3.5 py-2.5">
                                        <span class="w-2 h-2 rounded-full {{ $dot }} flex-shrink-0"></span>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-primary-600">{{ $task->stage?->name }}</p>
                                            @if($task->assignedTo)
                                                <p class="text-xs text-primary-300 mt-0.5">
                                                    <i class="bi bi-person mr-1"></i>{{ $task->assignedTo->name }}
                                                </p>
                                            @endif
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            @if($task->estimated_hours)
                                                <p class="text-xs text-primary-300 tabular-nums">Est. {{ $task->estimated_hours }}h</p>
                                            @endif
                                            @if($task->actual_hours)
                                                <p class="text-xs text-primary-500 tabular-nums">Act. {{ $task->actual_hours }}h</p>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Material Allocations --}}
                    @if($viewing->materialAllocations->count())
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2.5">
                                <i class="bi bi-box-seam mr-1"></i> Materials
                            </p>
                            <div class="rounded-xl border border-primary-100 overflow-hidden">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-primary-50/60 border-b border-primary-100">
                                        <tr>
                                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-primary-300 uppercase">Material</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Required</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Allocated</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-primary-300 uppercase">Used</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-primary-50">
                                        @foreach($viewing->materialAllocations as $alloc)
                                            <tr>
                                                <td class="px-4 py-2.5 font-medium text-primary-600">{{ $alloc->material?->name }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums text-primary-400">{{ $alloc->quantity_required }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums {{ $alloc->quantity_allocated >= $alloc->quantity_required ? 'text-success-600' : 'text-warning-600' }}">{{ $alloc->quantity_allocated }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums text-primary-500">{{ $alloc->quantity_used }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    {{-- Specifications --}}
                    @if($viewing->specifications)
                        <div>
                            <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-2">
                                <i class="bi bi-rulers mr-1"></i> Specifications
                            </p>
                            <div class="rounded-xl bg-primary-50/50 border border-primary-100 p-4 grid grid-cols-2 gap-2 text-sm">
                                @foreach($viewing->specifications as $key => $val)
                                    @if($key !== 'qc')
                                        <div><span class="text-primary-400 capitalize">{{ str_replace('_',' ',$key) }}:</span>
                                             <span class="font-medium text-primary-600 ml-1">{{ $val }}</span></div>
                                    @endif
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

    {{-- Status modal --}}
    @if($showStatusModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showStatusModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showStatusModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">Update Status</h2>
                    <button wire:click="$set('showStatusModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">New Status</label>
                        <select wire:model="newStatus" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            @foreach($statuses as $s)<option value="{{ $s }}">{{ str_replace('_',' ', ucfirst($s)) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <textarea wire:model="statusNotes" rows="2" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showStatusModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="updateStatus" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="updateStatus">Save</span>
                        <span wire:loading wire:target="updateStatus">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>