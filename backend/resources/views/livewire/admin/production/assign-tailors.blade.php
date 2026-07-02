<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-scissors"></i><span>Production</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Assign Tailors</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Assign Tailors</h1>
            <p class="mt-0.5 text-sm text-primary-300">Distribute production tasks across your tailoring team.</p>
        </div>
        @if(count($selectedTasks))
            <button wire:click="openBulkAssign"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
                <i class="bi bi-people"></i> Assign {{ count($selectedTasks) }} Selected
            </button>
        @endif
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Tailor Workload Overview --}}
    <div>
        <p class="text-xs font-semibold text-primary-300 uppercase tracking-wide mb-3">
            <i class="bi bi-people mr-1.5"></i>Tailor Workload
        </p>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
            @foreach($tailors as $tailor)
                @php $wl = $workload[$tailor->id] ?? []; $active = $wl['active_tasks_count'] ?? 0; $done = $wl['completed_tasks_count'] ?? 0; @endphp
                <div class="bg-white rounded-xl border {{ $active > 5 ? 'border-warning-200' : 'border-primary-100' }} p-3.5 cursor-pointer hover:border-primary-300 transition {{ $tailorFilter == $tailor->id ? 'ring-2 ring-primary-400' : '' }}"
                     wire:click="$set('tailorFilter', {{ $tailorFilter == $tailor->id ? 'null' : $tailor->id }})">
                    <div class="flex items-center gap-2.5 mb-2">
                        <div class="w-8 h-8 rounded-full bg-primary-100 flex items-center justify-center flex-shrink-0">
                            <i class="bi bi-person text-primary-400 text-sm"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-primary-600 truncate">{{ $tailor->name }}</p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between text-xs mt-1">
                        <span class="{{ $active > 5 ? 'text-warning-600 font-semibold' : 'text-primary-400' }}">{{ $active }} active</span>
                        <span class="text-success-600">{{ $done }} done</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-primary-100 overflow-hidden mt-2">
                        @php $pct = $active > 0 ? min(100, $active * 12.5) : 0; @endphp
                        <div class="h-full rounded-full {{ $active > 5 ? 'bg-warning-400' : ($active > 2 ? 'bg-info-400' : 'bg-success-400') }}"
                             style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by order number…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="stageFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Stages</option>
            @foreach($stages as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
        </select>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['pending'=>'Pending', 'in_progress'=>'In Progress', ''=>'All'] as $val => $label)
                <button wire:click="$set('statusFilter', '{{ $val }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $val ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Tasks table with bulk selection --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <div class="px-5 py-3.5 border-b border-primary-100 flex items-center gap-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model.live="selectAll" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                <span class="text-xs font-semibold text-primary-400 uppercase tracking-wide">Select All</span>
            </label>
            @if(count($selectedTasks))
                <span class="text-xs text-primary-300">{{ count($selectedTasks) }} selected</span>
            @endif
        </div>
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 w-8"></th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Order</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Stage</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Priority</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Due</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Currently Assigned</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Quick Assign</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($tasks as $task)
                    @php
                        $priCfg = match($task->productionOrder?->priority ?? 'normal') {
                            'urgent' => 'bg-danger-100 text-danger-700',
                            'high'   => 'bg-warning-100 text-warning-700',
                            'normal' => 'bg-primary-50 text-primary-400',
                            default  => 'bg-primary-50 text-primary-300',
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors {{ in_array((string)$task->id, $selectedTasks) ? 'bg-primary-50/60' : '' }}">
                        <td class="px-5 py-3.5">
                            <input wire:model.live="selectedTasks" value="{{ $task->id }}" type="checkbox"
                                   class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20 cursor-pointer" />
                        </td>
                        <td class="px-5 py-3.5">
                            <code class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg">
                                {{ $task->productionOrder?->order_number }}
                            </code>
                        </td>
                        <td class="px-5 py-3.5">
                            <p class="font-medium text-primary-600 text-sm">{{ $task->productionOrder?->product?->translations->first()?->name ?? '-' }}</p>
                            <p class="text-xs text-primary-300 tabular-nums">× {{ $task->productionOrder?->quantity }}</p>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-primary-50 text-primary-500 border border-primary-100">
                                {{ $task->stage?->name }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $priCfg }} capitalize">
                                {{ $task->productionOrder?->priority ?? 'normal' }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">
                            {{ $task->productionOrder?->due_date?->format('d M Y') ?? '-' }}
                        </td>
                        <td class="px-5 py-3.5">
                            @if($task->assignedTo)
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-success-100 flex items-center justify-center flex-shrink-0">
                                        <i class="bi bi-person-check text-success-500 text-xs"></i>
                                    </div>
                                    <span class="text-sm text-primary-500">{{ $task->assignedTo->name }}</span>
                                    <button wire:click="unassignTask({{ $task->id }})"
                                            class="text-primary-200 hover:text-danger-500 transition ml-1"
                                            title="Unassign">
                                        <i class="bi bi-x-circle text-xs"></i>
                                    </button>
                                </div>
                            @else
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium bg-warning-50 text-warning-600 border border-warning-200">
                                    Unassigned
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <select wire:change="quickAssign({{ $task->id }}, $event.target.value)"
                                        class="rounded-lg border border-primary-100 bg-white px-2.5 py-1.5 text-xs text-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-400 transition cursor-pointer">
                                    <option value="">Assign to…</option>
                                    @foreach($tailors as $t)
                                        <option value="{{ $t->id }}" {{ $task->assigned_to == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-5 py-16 text-center">
                        <i class="bi bi-person-check text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">All tasks are assigned!</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Bulk assign modal --}}
    @if($showBulkModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showBulkModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showBulkModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Bulk Assign</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Assign {{ count($selectedTasks) }} tasks to one tailor.</p>
                    </div>
                    <button wire:click="$set('showBulkModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-5">
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Assign To</label>
                    <select wire:model="bulkAssignTo" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                        <option value="">Select tailor…</option>
                        @foreach($tailors as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                    </select>
                    @error('bulkAssignTo')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showBulkModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="saveBulkAssign" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveBulkAssign">Assign All</span>
                        <span wire:loading wire:target="saveBulkAssign">Assigning…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>