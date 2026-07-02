<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-scissors"></i><span>Production</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Work in Progress</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Work in Progress</h1>
            <p class="mt-0.5 text-sm text-primary-300">Live Kanban view of all active production orders.</p>
        </div>
        <a href="{{ route('admin.production.orders.create') }}"
           class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> New Order
        </a>
    </div>

    {{-- Summary strip --}}
    <div class="flex flex-wrap gap-3">
        @php
            $strips = [
                'pending'       => ['Pending',     'bg-warning-50 border-warning-200 text-warning-700',    $summary['pending']],
                'in_progress'   => ['In Progress', 'bg-info-50 border-info-200 text-info-700',             $summary['in_progress']],
                'on_hold'       => ['On Hold',     'bg-primary-50 border-primary-200 text-primary-400',    $summary['on_hold']],
                'quality_check' => ['QC',          'bg-secondary-50 border-secondary-200 text-secondary-700', $summary['quality_check']],
                'overdue'       => ['Overdue',     'bg-danger-50 border-danger-200 text-danger-600',       $summary['overdue']],
            ];
        @endphp
        @foreach($strips as [$label, $style, $count])
            <div class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold {{ $style }}">
                {{ $label }}
                <span class="font-bold text-sm">{{ $count }}</span>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Filter by order # or product…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="outletFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Outlets</option>
            @foreach($outlets as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
        </select>
    </div>

    {{-- Kanban Board --}}
    @php
        $colConfig = [
            'pending'       => ['label'=>'Pending',      'icon'=>'bi-hourglass-split',   'border'=>'border-warning-200',   'head'=>'bg-warning-50',   'badge'=>'bg-warning-100 text-warning-700'],
            'in_progress'   => ['label'=>'In Progress',  'icon'=>'bi-play-circle',        'border'=>'border-info-200',      'head'=>'bg-info-50',      'badge'=>'bg-info-100 text-info-700'],
            'on_hold'       => ['label'=>'On Hold',      'icon'=>'bi-pause-circle',       'border'=>'border-primary-200',   'head'=>'bg-primary-50',   'badge'=>'bg-primary-100 text-primary-500'],
            'quality_check' => ['label'=>'QC Review',    'icon'=>'bi-clipboard-check',    'border'=>'border-secondary-200', 'head'=>'bg-secondary-50', 'badge'=>'bg-secondary-100 text-secondary-700'],
        ];
        $nextStatus = [
            'pending'       => 'in_progress',
            'in_progress'   => 'quality_check',
            'on_hold'       => 'in_progress',
            'quality_check' => 'completed',
        ];
        $nextLabel = [
            'pending'       => 'Start',
            'in_progress'   => 'Send to QC',
            'on_hold'       => 'Resume',
            'quality_check' => 'Mark Complete',
        ];
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 items-start">
        @foreach($colConfig as $status => $col)
            @php $colOrders = $columns[$status] ?? collect(); @endphp
            <div class="flex flex-col rounded-2xl border {{ $col['border'] }} overflow-hidden">
                {{-- Column header --}}
                <div class="{{ $col['head'] }} border-b {{ $col['border'] }} px-4 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <i class="bi {{ $col['icon'] }} text-sm {{ str_replace(['bg-','50'],'text-',explode(' ',$col['head'])[0]).'600' }}"></i>
                        <span class="text-xs font-bold text-primary-600 uppercase tracking-wide">{{ $col['label'] }}</span>
                    </div>
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold {{ $col['badge'] }}">
                        {{ $colOrders->count() }}
                    </span>
                </div>

                {{-- Cards --}}
                <div class="p-2 space-y-2 min-h-[120px] bg-white/50">
                    @forelse($colOrders as $order)
                        @php
                            $pct     = $order->tasks_count > 0 ? round($order->completed_tasks_count / $order->tasks_count * 100) : 0;
                            $isOver  = $order->isOverdue();
                            $priDot  = match($order->priority) {
                                'urgent' => 'bg-danger-500',
                                'high'   => 'bg-warning-500',
                                'normal' => 'bg-info-400',
                                default  => 'bg-primary-200',
                            };
                        @endphp
                        <div class="group bg-white rounded-xl border {{ $isOver ? 'border-danger-200' : 'border-primary-100' }} p-3.5 hover:border-primary-300 hover:shadow-sm transition-all">

                            {{-- Card top --}}
                            <div class="flex items-start justify-between mb-2.5">
                                <code class="font-mono text-[11px] font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-1.5 py-0.5 rounded-md">
                                    {{ $order->order_number }}
                                </code>
                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                    <span class="w-2 h-2 rounded-full {{ $priDot }}" title="{{ ucfirst($order->priority) }} priority"></span>
                                    @if($isOver)
                                        <span class="text-[10px] font-bold text-danger-600 bg-danger-50 border border-danger-200 px-1.5 py-0.5 rounded-full">LATE</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Product --}}
                            <p class="text-sm font-semibold text-primary-600 leading-tight line-clamp-2 mb-1">
                                {{ $order->product?->translations->first()?->name ?? '-' }}
                            </p>
                            @if($order->variant)
                                <p class="text-xs text-primary-300 mb-1">{{ $order->variant->variant_name }}</p>
                            @endif

                            {{-- Meta --}}
                            <div class="flex items-center gap-3 text-xs text-primary-300 mb-2.5">
                                <span class="flex items-center gap-1">
                                    <i class="bi bi-layers text-[10px]"></i>{{ $order->quantity }} units
                                </span>
                                @if($order->due_date)
                                    <span class="flex items-center gap-1 {{ $isOver ? 'text-danger-500 font-semibold' : '' }}">
                                        <i class="bi bi-calendar3 text-[10px]"></i>{{ $order->due_date->format('d M') }}
                                    </span>
                                @endif
                                @if($order->outlet)
                                    <span class="flex items-center gap-1 ml-auto">
                                        <i class="bi bi-shop text-[10px]"></i>{{ Str::words($order->outlet->name, 1, '') }}
                                    </span>
                                @endif
                            </div>

                            {{-- Progress bar --}}
                            @if($order->tasks_count > 0)
                                <div class="mb-2.5">
                                    <div class="h-1.5 rounded-full bg-primary-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $pct === 100 ? 'bg-success-500' : 'bg-info-400' }}"
                                             style="width: {{ $pct }}%"></div>
                                    </div>
                                    <div class="flex justify-between text-[11px] text-primary-300 mt-0.5">
                                        <span>{{ $order->completed_tasks_count }}/{{ $order->tasks_count }} tasks</span>
                                        <span class="tabular-nums">{{ $pct }}%</span>
                                    </div>
                                </div>
                            @endif

                            {{-- Actions --}}
                            <div class="flex items-center gap-1.5 pt-2 border-t border-primary-50 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="moveOrder({{ $order->id }}, '{{ $nextStatus[$status] }}')"
                                        wire:confirm="Move order {{ $order->order_number }} to '{{ str_replace('_',' ', $nextLabel[$status]) }}'?"
                                        class="flex-1 py-1.5 rounded-lg bg-primary-500 text-white text-[11px] font-semibold hover:bg-primary-600 transition text-center">
                                    {{ $nextLabel[$status] }} →
                                </button>
                                @if($status !== 'on_hold')
                                    <button wire:click="moveOrder({{ $order->id }}, 'on_hold')"
                                            class="w-7 h-7 flex items-center justify-center rounded-lg text-primary-300 hover:text-warning-600 hover:bg-warning-50 border border-primary-100 transition"
                                            title="Put on hold">
                                        <i class="bi bi-pause text-sm"></i>
                                    </button>
                                @endif
                                <a href="{{ route('admin.production.orders') }}?search={{ $order->order_number }}"
                                   class="w-7 h-7 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 border border-primary-100 transition"
                                   title="View details">
                                    <i class="bi bi-box-arrow-up-right text-xs"></i>
                                </a>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center py-8 text-primary-200">
                            <i class="bi bi-inbox text-2xl mb-1.5"></i>
                            <p class="text-xs">No orders</p>
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    {{-- Legend --}}
    <div class="flex flex-wrap items-center gap-4 text-xs text-primary-300 pt-2">
        <span class="font-semibold text-primary-400">Priority:</span>
        @foreach(['urgent'=>['bg-danger-500','Urgent'], 'high'=>['bg-warning-500','High'], 'normal'=>['bg-info-400','Normal'], 'low'=>['bg-primary-200','Low']] as $p => [$dot, $label])
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full {{ $dot }}"></span>{{ $label }}
            </span>
        @endforeach
    </div>

</div>