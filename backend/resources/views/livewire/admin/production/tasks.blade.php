<div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-scissors"></i><span>Production</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Tasks</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Production Tasks</h1>
            <p class="mt-0.5 text-sm text-primary-300">Track every stage task across all active production orders.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        @php
            $cards = [
                ['label'=>'Pending',    'key'=>'pending',    'icon'=>'bi-hourglass-split',   'border'=>'border-warning-200',  'ibg'=>'bg-warning-50',  'ic'=>'text-warning-500', 'vc'=>'text-warning-700'],
                ['label'=>'In Progress','key'=>'in_progress','icon'=>'bi-play-circle',        'border'=>'border-info-200',     'ibg'=>'bg-info-50',     'ic'=>'text-info-500',    'vc'=>'text-info-700'],
                ['label'=>'Completed',  'key'=>'completed',  'icon'=>'bi-check-circle',       'border'=>'border-success-200',  'ibg'=>'bg-success-50',  'ic'=>'text-success-500', 'vc'=>'text-success-700'],
                ['label'=>'Blocked',    'key'=>'blocked',    'icon'=>'bi-slash-circle',        'border'=>'border-danger-200',   'ibg'=>'bg-danger-50',   'ic'=>'text-danger-500',  'vc'=>'text-danger-600'],
                ['label'=>'Unassigned', 'key'=>'unassigned', 'icon'=>'bi-person-dash',         'border'=>'border-primary-200',  'ibg'=>'bg-primary-50',  'ic'=>'text-primary-400', 'vc'=>'text-primary-600'],
            ];
        @endphp
        @foreach($cards as $card)
            <div class="relative overflow-hidden bg-white rounded-2xl border {{ $card['border'] }} p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl {{ $card['ibg'] }} border {{ $card['border'] }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $card['icon'] }} {{ $card['ic'] }} text-lg"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $card['label'] }}</p>
                    <p class="text-xl font-bold {{ $card['vc'] }} mt-0.5 tabular-nums">{{ $summary[$card['key']] ?? 0 }}</p>
                </div>
                <div class="absolute -right-2 -bottom-2 w-12 h-12 rounded-full {{ $card['ibg'] }} opacity-50"></div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex-1 min-w-48">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-primary-300 text-sm pointer-events-none"></i>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search by order number…"
                   class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-primary-100 bg-white text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
        </div>
        <select wire:model.live="statusFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Statuses</option>
            @foreach($statuses as $s)<option value="{{ $s }}">{{ str_replace('_',' ',ucfirst($s)) }}</option>@endforeach
        </select>
        <select wire:model.live="stageFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Stages</option>
            @foreach($stages as $stage)<option value="{{ $stage->id }}">{{ $stage->name }}</option>@endforeach
        </select>
        <select wire:model.live="assigneeFilter" class="rounded-xl border border-primary-100 bg-white px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition cursor-pointer">
            <option value="">All Tailors</option>
            @foreach($tailors as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
        </select>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-primary-100">
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Order</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Product</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Stage</th>
                    <th class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider">Assigned To</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Est. Hrs</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actual Hrs</th>
                    <th wire:click="sort('started_at')" class="px-5 py-3.5 text-left text-xs font-semibold text-primary-300 uppercase tracking-wider cursor-pointer select-none hover:text-primary-500 transition">
                        <span class="flex items-center gap-1.5">Started
                            <i class="bi bi-arrow-{{ $sortBy === 'started_at' ? ($sortDir === 'asc' ? 'up' : 'down') : 'down-up' }} text-primary-200"></i>
                        </span>
                    </th>
                    <th class="px-5 py-3.5 text-center text-xs font-semibold text-primary-300 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3.5 text-right text-xs font-semibold text-primary-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary-50">
                @forelse($tasks as $task)
                    @php
                        $stBadge = match($task->status) {
                            'pending'     => 'bg-warning-50 text-warning-700 border border-warning-200',
                            'in_progress' => 'bg-info-50 text-info-700 border border-info-200',
                            'completed'   => 'bg-success-50 text-success-700 border border-success-200',
                            'blocked'     => 'bg-danger-50 text-danger-600 border border-danger-200',
                            default       => 'bg-primary-50 text-primary-400 border border-primary-100',
                        };
                    @endphp
                    <tr class="hover:bg-primary-50/40 transition-colors group">
                        <td class="px-5 py-3.5">
                            <code class="font-mono text-xs font-bold text-secondary-700 bg-secondary-50 border border-secondary-200 px-2 py-0.5 rounded-lg">
                                {{ $task->productionOrder?->order_number }}
                            </code>
                        </td>
                        <td class="px-5 py-3.5">
                            <p class="font-medium text-primary-600 text-sm">{{ $task->productionOrder?->product?->translations->first()?->name ?? '-' }}</p>
                            <p class="text-xs text-primary-300 mt-0.5 tabular-nums">× {{ $task->productionOrder?->quantity }}</p>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-primary-50 text-primary-500 border border-primary-100">
                                {{ $task->stage?->name }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            @if($task->assignedTo)
                                <div class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-primary-100 flex items-center justify-center flex-shrink-0">
                                        <i class="bi bi-person text-primary-400 text-xs"></i>
                                    </div>
                                    <span class="text-sm text-primary-500">{{ $task->assignedTo->name }}</span>
                                </div>
                            @else
                                <span class="text-xs text-primary-200 italic">Unassigned</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-400 text-sm">
                            {{ $task->estimated_hours ? $task->estimated_hours . 'h' : '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-right tabular-nums text-primary-500 text-sm font-medium">
                            {{ $task->actual_hours ? $task->actual_hours . 'h' : '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-sm text-primary-400 whitespace-nowrap">
                            {{ $task->started_at?->format('d M, H:i') ?? '-' }}
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $stBadge }}">
                                {{ str_replace('_',' ', ucfirst($task->status)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button wire:click="openAssign({{ $task->id }})"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition"
                                        title="Assign tailor">
                                    <i class="bi bi-person-plus text-sm"></i>
                                </button>
                                <button wire:click="openStatusUpdate({{ $task->id }}, '{{ $task->status }}')"
                                        class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-600 hover:bg-primary-50 transition"
                                        title="Update status">
                                    <i class="bi bi-pencil-square text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-5 py-16 text-center">
                        <i class="bi bi-list-task text-4xl text-primary-100 block mb-3"></i>
                        <p class="text-sm font-medium text-primary-300">No tasks found.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        @if($tasks->hasPages())
            <div class="px-5 py-3.5 border-t border-primary-50 bg-primary-50/30">{{ $tasks->links() }}</div>
        @endif
    </div>

    {{-- Assign modal --}}
    @if($showAssignModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showAssignModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showAssignModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">Assign Tailor</h2>
                        <p class="text-xs text-primary-300 mt-0.5">Assign a staff member to this task.</p>
                    </div>
                    <button wire:click="$set('showAssignModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Assign To</label>
                        <select wire:model="assignToUserId" class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                            <option value="">Select tailor…</option>
                            @foreach($tailors as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                        </select>
                        @error('assignToUserId')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Estimated Hours <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <input wire:model="estimatedHours" type="number" step="0.5" min="0.5" placeholder="e.g. 4.5"
                               class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Notes <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <textarea wire:model="assignNotes" rows="2" placeholder="Task instructions…"
                                  class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition resize-none"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showAssignModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="saveAssignment" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="saveAssignment">Assign</span>
                        <span wire:loading wire:target="saveAssignment">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Status update modal --}}
    @if($showStatusModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showStatusModal', false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showStatusModal', false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">Update Task Status</h2>
                    <button wire:click="$set('showStatusModal', false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                        <i class="bi bi-x-lg text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Status</label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($statuses as $s)
                                <button wire:click="$set('newStatus', '{{ $s }}')"
                                        class="py-2.5 rounded-xl border text-xs font-semibold transition capitalize
                                               {{ $newStatus === $s ? 'bg-primary-500 text-white border-primary-500' : 'bg-white text-primary-400 border-primary-100 hover:border-primary-300' }}">
                                    {{ str_replace('_',' ', $s) }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    @if($newStatus === 'completed')
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Actual Hours <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                            <input wire:model="actualHours" type="number" step="0.5" min="0" placeholder="e.g. 3.5"
                                   class="w-full rounded-xl border border-primary-100 px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    @endif
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showStatusModal', false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 hover:border-primary-200 transition">Cancel</button>
                    <button wire:click="updateTaskStatus" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="updateTaskStatus">Save</span>
                        <span wire:loading wire:target="updateTaskStatus">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>