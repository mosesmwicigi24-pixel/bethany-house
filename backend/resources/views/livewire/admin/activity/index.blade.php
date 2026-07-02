<div class="min-h-screen bg-primary-50/30">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">

        {{-- ── Header ──────────────────────────────────────────── --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold text-primary-500">
                    <i class="ri-history-line text-secondary-500"></i>
                    Activity Logs
                </h1>
                <p class="mt-0.5 text-sm text-primary-300">
                    {{ number_format($total) }} entries
                </p>
            </div>
            <button wire:click="$set('showClearModal', true)"
                    class="inline-flex items-center gap-2 rounded-lg border border-primary-200 bg-white px-3.5 py-2 text-sm font-medium text-primary-400 hover:bg-danger-50 hover:border-danger-200 hover:text-danger-600 transition">
                <i class="ri-delete-bin-line"></i>
                Clear Old Logs
            </button>
        </div>

        {{-- ── Filters ──────────────────────────────────────────── --}}
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <i class="ri-search-line absolute left-3 top-1/2 -translate-y-1/2 text-primary-300"></i>
                <input wire:model.live.debounce.300ms="search"
                       type="text"
                       placeholder="Search descriptions or user names…"
                       class="w-full rounded-lg border border-primary-200 bg-white py-2 pl-9 pr-3 text-sm text-primary-500 placeholder-primary-300 shadow-sm focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20">
                @if($search)
                <button wire:click="$set('search', '')"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-primary-300 hover:text-primary-500">
                    <i class="ri-close-line"></i>
                </button>
                @endif
            </div>

            <select wire:model.live="action"
                    class="rounded-lg border border-primary-200 bg-white py-2 pl-3 pr-8 text-sm text-primary-500 shadow-sm focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                <option value="">All Actions</option>
                <option value="user_created">User Created</option>
                <option value="user_updated">User Updated</option>
                <option value="user_deleted">User Deleted</option>
                <option value="user_role_changed">Role Changed</option>
                <option value="user_status_changed">Status Changed</option>
                <option value="password_reset">Password Reset</option>
                <option value="settings_updated">Settings Updated</option>
                <option value="login">Login</option>
                <option value="logout">Logout</option>
            </select>

            <input wire:model.live="startDate" type="date"
                   title="From date"
                   class="rounded-lg border border-primary-200 bg-white py-2 px-3 text-sm text-primary-500 shadow-sm focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20">

            <input wire:model.live="endDate" type="date"
                   title="To date"
                   class="rounded-lg border border-primary-200 bg-white py-2 px-3 text-sm text-primary-500 shadow-sm focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20">

            @if($search || $action || $startDate || $endDate)
            <button wire:click="clearFilters"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-primary-200 bg-white px-3 py-2 text-sm text-primary-400 hover:bg-primary-50 transition">
                <i class="ri-refresh-line"></i> Clear
            </button>
            @endif
        </div>

        {{-- ── Timeline ─────────────────────────────────────────── --}}
        <div class="overflow-hidden rounded-xl border border-primary-100 bg-white shadow-sm">
            @forelse($logs as $log)
            @php $meta = $this->actionMeta($log['action'] ?? ''); @endphp
            <div wire:key="log-{{ $log['id'] }}"
                 class="flex gap-4 border-b border-primary-50 px-5 py-3.5 last:border-b-0 hover:bg-primary-50/40 transition-colors group">

                {{-- Icon dot --}}
                <div @class([
                    'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full text-sm',
                    'bg-success-100 text-success-600'  => $meta['color'] === 'success',
                    'bg-danger-100 text-danger-600'    => $meta['color'] === 'danger',
                    'bg-info-100 text-info-600'        => $meta['color'] === 'info',
                    'bg-warning-100 text-warning-600'  => $meta['color'] === 'warning',
                    'bg-primary-100 text-primary-400'  => in_array($meta['color'], ['secondary', 'gray']),
                ])>
                    <i class="{{ $meta['icon'] }}"></i>
                </div>

                {{-- Body --}}
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-sm font-semibold text-primary-500">
                                {{ $this->actionLabel($log['action'] ?? 'unknown') }}
                            </span>
                            @if($log['user_name'] ?? null)
                            <span class="text-xs text-primary-300">
                                by
                                <a href="/admin/users?search={{ urlencode($log['user_name']) }}"
                                   class="font-semibold text-primary-400 hover:text-secondary-600 hover:underline">
                                    {{ $log['user_name'] }}
                                </a>
                            </span>
                            @endif
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            @if($log['ip_address'] ?? null)
                            <span class="font-mono text-xs text-primary-200">{{ $log['ip_address'] }}</span>
                            @endif
                            <time class="text-xs text-primary-300 whitespace-nowrap"
                                  datetime="{{ $log['created_at'] }}"
                                  title="{{ \Carbon\Carbon::parse($log['created_at'])->format('d M Y, H:i:s') }}">
                                {{ \Carbon\Carbon::parse($log['created_at'])->diffForHumans() }}
                            </time>
                            <button wire:click="viewDetail({{ $log['id'] }})"
                                    class="flex size-6 items-center justify-center rounded-md text-primary-200 hover:bg-primary-100 hover:text-primary-500 transition opacity-0 group-hover:opacity-100">
                                <i class="ri-external-link-line text-xs"></i>
                            </button>
                        </div>
                    </div>
                    @if($log['description'] ?? null)
                    <p class="mt-0.5 truncate text-xs text-primary-300">{{ $log['description'] }}</p>
                    @endif
                </div>

            </div>
            @empty
            <div class="py-16 text-center">
                <i class="ri-file-search-line mb-3 block text-4xl text-primary-200"></i>
                <p class="font-semibold text-primary-400">No activity logs found</p>
                <p class="mt-1 text-sm text-primary-300">
                    @if($search || $action || $startDate || $endDate)
                        Try adjusting your filters.
                    @else
                        Activity will appear here as users interact with the system.
                    @endif
                </p>
            </div>
            @endforelse
        </div>

        {{-- ── Pagination ────────────────────────────────────────── --}}
        @if($lastPage > 1)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-primary-300">
                Page {{ $currentPage }} of {{ $lastPage }} &middot; {{ number_format($total) }} entries
            </p>
            <div class="flex gap-1">
                @if($currentPage > 1)
                <button wire:click="previousPage"
                        class="flex size-8 items-center justify-center rounded-lg border border-primary-200 bg-white text-sm text-primary-400 hover:border-secondary-400 transition">
                    <i class="ri-arrow-left-s-line"></i>
                </button>
                @endif
                @for($p = max(1, $currentPage - 2); $p <= min($lastPage, $currentPage + 2); $p++)
                <button wire:click="gotoPage({{ $p }})"
                        @class([
                            'flex h-8 min-w-8 items-center justify-center rounded-lg border px-2 text-sm font-medium transition',
                            'border-primary-500 bg-primary-500 text-secondary-400' => $p === $currentPage,
                            'border-primary-200 bg-white text-primary-400 hover:border-secondary-400' => $p !== $currentPage,
                        ])>
                    {{ $p }}
                </button>
                @endfor
                @if($currentPage < $lastPage)
                <button wire:click="nextPage"
                        class="flex size-8 items-center justify-center rounded-lg border border-primary-200 bg-white text-sm text-primary-400 hover:border-secondary-400 transition">
                    <i class="ri-arrow-right-s-line"></i>
                </button>
                @endif
            </div>
        </div>
        @endif

    </div>

    {{-- ════════════ MODALS & PANELS ════════════ --}}

    {{-- Detail slide-over panel --}}
    @if($showDetail && $detailLog)
    <div class="fixed inset-0 z-50 flex justify-end bg-primary-900/40 backdrop-blur-sm"
         wire:click.self="$set('showDetail', false)">
        <aside class="flex h-full w-full max-w-md flex-col bg-white shadow-xl">
            <div class="flex shrink-0 items-center justify-between border-b border-primary-100 px-6 py-4">
                <h3 class="text-base font-bold text-primary-500">
                    @php $meta = $this->actionMeta($detailLog['action'] ?? ''); @endphp
                    <i class="{{ $meta['icon'] }} mr-1.5 text-secondary-500"></i>
                    Log Detail
                </h3>
                <button wire:click="$set('showDetail', false)"
                        class="flex size-7 items-center justify-center rounded-lg text-primary-300 hover:bg-primary-100 transition">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-5">
                <dl class="space-y-5">
                    @foreach([
                        ['Action',       $this->actionLabel($detailLog['action'] ?? '')],
                        ['Performed by', ($detailLog['user_name'] ?? 'System') . ($detailLog['user_email'] ?? null ? ' - '.$detailLog['user_email'] : '')],
                        ['Description',  $detailLog['description'] ?? '-'],
                        ['IP Address',   $detailLog['ip_address'] ?? '-'],
                        ['Timestamp',    \Carbon\Carbon::parse($detailLog['created_at'])->format('d M Y, H:i:s').' ('.\Carbon\Carbon::parse($detailLog['created_at'])->diffForHumans().')'],
                        ['Log ID',       '#'.$detailLog['id']],
                    ] as [$label, $value])
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wider text-primary-300">{{ $label }}</dt>
                        <dd class="mt-1 text-sm text-primary-500 break-words">{{ $value }}</dd>
                    </div>
                    @endforeach

                    @if($detailLog['metadata'] ?? null)
                    <div>
                        <dt class="mb-1 text-xs font-bold uppercase tracking-wider text-primary-300">Metadata</dt>
                        <pre class="overflow-x-auto rounded-lg bg-primary-50 p-3 text-xs text-primary-500 font-mono">{{ json_encode($detailLog['metadata'], JSON_PRETTY_PRINT) }}</pre>
                    </div>
                    @endif
                </dl>
            </div>
        </aside>
    </div>
    @endif

    {{-- Clear old logs modal --}}
    @if($showClearModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showClearModal', false)">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
            <div class="mb-4 flex size-12 items-center justify-center rounded-full bg-danger-100">
                <i class="ri-delete-bin-fill text-xl text-danger-600"></i>
            </div>
            <h3 class="text-lg font-bold text-primary-500">Clear Old Logs</h3>
            <p class="mt-1 text-sm text-primary-300">
                Permanently delete all audit entries older than the specified number of days. This cannot be undone.
            </p>
            <div class="mt-4">
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                    Delete logs older than <span class="text-danger-500">*</span>
                </label>
                <div class="flex items-center gap-2">
                    <input wire:model="clearDays" type="number" min="30"
                           class="w-24 rounded-lg border border-primary-200 py-2 px-3 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('clearDays') border-danger-400 @enderror">
                    <span class="text-sm text-primary-400">days</span>
                </div>
                @error('clearDays') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-primary-300">Minimum 30 days to prevent accidental data loss.</p>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button wire:click="$set('showClearModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="clearOldLogs"
                        class="inline-flex items-center gap-2 rounded-lg bg-danger-600 px-4 py-2 text-sm font-semibold text-white hover:bg-danger-700 transition">
                    <i class="ri-delete-bin-line"></i>
                    Clear Logs
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Toast --}}
    @include('livewire.admin._partials.toast')

</div>