<div class="min-h-screen bg-primary-50/30">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">

        {{-- ── Header ──────────────────────────────────────────── --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold text-primary-500">
                    <i class="ri-team-line text-secondary-500"></i>
                    Users
                </h1>
                <p class="mt-0.5 text-sm text-primary-300">
                    {{ number_format($total) }} total users
                </p>
            </div>
            <a href="/admin/users/create"
               class="inline-flex items-center gap-2 rounded-lg bg-primary-500 px-4 py-2 text-sm font-semibold text-secondary-500 shadow-sm transition hover:bg-primary-600">
                <i class="ri-user-add-line"></i>
                New User
            </a>
        </div>

        {{-- ── Flash ───────────────────────────────────────────── --}}
        @if(session('flash.success'))
        <div class="mb-4 flex items-center gap-3 rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-700">
            <i class="ri-checkbox-circle-fill text-base text-success-500"></i>
            {{ session('flash.success') }}
        </div>
        @endif

        {{-- ── Filters ──────────────────────────────────────────── --}}
        <div class="mb-4 flex flex-wrap items-center gap-3">
            {{-- Search --}}
            <div class="relative flex-1 min-w-[200px]">
                <i class="ri-search-line absolute left-3 top-1/2 -translate-y-1/2 text-primary-300"></i>
                <input wire:model.live.debounce.300ms="search"
                       type="text"
                       placeholder="Search name, email, phone…"
                       class="w-full rounded-lg border border-primary-200 bg-white py-2 pl-9 pr-3 text-sm text-primary-500 placeholder-primary-300 shadow-sm focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20">
                @if($search)
                <button wire:click="$set('search', '')"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-primary-300 hover:text-primary-500">
                    <i class="ri-close-line"></i>
                </button>
                @endif
            </div>

            {{-- Role filter --}}
            <select wire:model.live="role"
                    class="rounded-lg border border-primary-200 bg-white py-2 pl-3 pr-8 text-sm text-primary-500 shadow-sm focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                <option value="">All Roles</option>
                <option value="super_admin">Super Admin</option>
                <option value="admin">Admin</option>
                <option value="outlet_manager">Outlet Manager</option>
                <option value="pos_clerk">POS Clerk</option>
                <option value="tailor">Tailor</option>
                <option value="customer">Customer</option>
            </select>

            {{-- Status filter --}}
            <select wire:model.live="status"
                    class="rounded-lg border border-primary-200 bg-white py-2 pl-3 pr-8 text-sm text-primary-500 shadow-sm focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
            </select>

            {{-- Per page --}}
            <select wire:model.live="perPage"
                    class="rounded-lg border border-primary-200 bg-white py-2 pl-3 pr-8 text-sm text-primary-500 shadow-sm focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                <option value="10">10 / page</option>
                <option value="20">20 / page</option>
                <option value="50">50 / page</option>
                <option value="100">100 / page</option>
            </select>

            @if($search || $role || $status)
            <button wire:click="clearFilters"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-primary-200 bg-white px-3 py-2 text-sm text-primary-400 hover:bg-primary-50 transition">
                <i class="ri-refresh-line"></i>
                Clear
            </button>
            @endif
        </div>

        {{-- ── Bulk bar ─────────────────────────────────────────── --}}
        @if(count($selected) > 0)
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-secondary-300 bg-secondary-50 px-4 py-2.5">
            <span class="flex items-center gap-2 text-sm font-semibold text-primary-500">
                <i class="ri-checkbox-multiple-line text-secondary-600"></i>
                {{ count($selected) }} selected
            </span>
            <div class="flex items-center gap-2">
                <select wire:model="bulkAction"
                        class="rounded-lg border border-primary-200 bg-white py-1.5 pl-3 pr-8 text-sm text-primary-500 appearance-none cursor-pointer">
                    <option value="">Bulk action…</option>
                    <option value="active">Set Active</option>
                    <option value="inactive">Set Inactive</option>
                    <option value="suspended">Suspend</option>
                    <option value="delete">Delete</option>
                </select>
                <button wire:click="confirmBulkAction"
                        @if(!$bulkAction) disabled @endif
                        class="rounded-lg bg-primary-500 px-3 py-1.5 text-sm font-semibold text-secondary-500 disabled:opacity-40 hover:bg-primary-600 transition">
                    Apply
                </button>
                <button wire:click="$set('selected', [])"
                        class="rounded-lg border border-primary-200 bg-white px-3 py-1.5 text-sm text-primary-400 hover:bg-primary-50 transition">
                    Clear
                </button>
            </div>
        </div>
        @endif

        {{-- ── Table ────────────────────────────────────────────── --}}
        <div class="overflow-hidden rounded-xl border border-primary-100 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-primary-100 bg-primary-50/60">
                            <th class="w-11 px-4 py-3 text-left">
                                <input wire:model.live="selectAll"
                                       type="checkbox"
                                       class="size-4 cursor-pointer rounded accent-primary-500">
                            </th>
                            {{-- Sortable headers --}}
                            @foreach([
                                ['name',       'User'],
                                ['email',      'Contact'],
                                ['role',       'Role'],
                                ['status',     'Status'],
                                ['created_at', 'Joined'],
                            ] as [$col, $label])
                            <th wire:click="sort('{{ $col }}')"
                                class="cursor-pointer select-none px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-primary-300 hover:text-primary-500 transition">
                                <span class="flex items-center gap-1">
                                    {{ $label }}
                                    @if($sortBy === $col)
                                        <i class="ri-arrow-{{ $sortOrder === 'asc' ? 'up' : 'down' }}-s-line text-secondary-500"></i>
                                    @else
                                        <i class="ri-arrow-up-down-line opacity-30"></i>
                                    @endif
                                </span>
                            </th>
                            @endforeach
                            <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-primary-300">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary-50">
                        @forelse($users as $user)
                        <tr wire:key="user-{{ $user['id'] }}"
                            class="group hover:bg-primary-50/40 transition-colors">

                            {{-- Checkbox --}}
                            <td class="px-4 py-3">
                                <input wire:model.live="selected"
                                       type="checkbox"
                                       value="{{ $user['id'] }}"
                                       class="size-4 cursor-pointer rounded accent-primary-500">
                            </td>

                            {{-- User --}}
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div @class([
                                        'flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-bold text-white',
                                        'bg-primary-500'         => $user['role'] === 'super_admin',
                                        'bg-purple-600'          => $user['role'] === 'admin',
                                        'bg-info-600'            => $user['role'] === 'outlet_manager',
                                        'bg-success-600'         => $user['role'] === 'pos_clerk',
                                        'bg-warning-600'         => $user['role'] === 'tailor',
                                        'bg-primary-300'         => $user['role'] === 'customer',
                                    ])>
                                        {{ strtoupper(substr($user['name'], 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-primary-500">{{ $user['name'] }}</p>
                                        <p class="text-xs text-primary-300">#{{ $user['id'] }}</p>
                                    </div>
                                </div>
                            </td>

                            {{-- Contact --}}
                            <td class="px-4 py-3">
                                <p class="text-primary-500">{{ $user['email'] }}</p>
                                @if($user['phone'] ?? null)
                                <p class="text-xs text-primary-300">{{ $user['phone'] }}</p>
                                @endif
                            </td>

                            {{-- Role --}}
                            <td class="px-4 py-3">
                                <button wire:click="openRoleModal({{ $user['id'] }}, '{{ $user['role'] }}')"
                                        title="Click to change role"
                                        @class([
                                            'inline-flex cursor-pointer items-center rounded-full px-2.5 py-0.5 text-xs font-semibold transition hover:opacity-80',
                                            'bg-primary-500 text-secondary-400'                 => $user['role'] === 'super_admin',
                                            'bg-purple-100 text-purple-700'                     => $user['role'] === 'admin',
                                            'bg-info-100 text-info-700'                         => $user['role'] === 'outlet_manager',
                                            'bg-success-100 text-success-700'                   => $user['role'] === 'pos_clerk',
                                            'bg-warning-100 text-warning-700'                   => $user['role'] === 'tailor',
                                            'bg-primary-100 text-primary-400'                   => $user['role'] === 'customer',
                                        ])>
                                    {{ ucwords(str_replace('_', ' ', $user['role'])) }}
                                </button>
                            </td>

                            {{-- Status --}}
                            <td class="px-4 py-3">
                                <button wire:click="openStatusModal({{ $user['id'] }}, '{{ $user['status'] }}')"
                                        title="Click to change status"
                                        @class([
                                            'inline-flex cursor-pointer items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold transition hover:opacity-80',
                                            'bg-success-100 text-success-700' => $user['status'] === 'active',
                                            'bg-primary-100 text-primary-300' => $user['status'] === 'inactive',
                                            'bg-danger-100 text-danger-700'   => $user['status'] === 'suspended',
                                        ])>
                                    <span @class([
                                        'size-1.5 rounded-full',
                                        'bg-success-500' => $user['status'] === 'active',
                                        'bg-primary-300' => $user['status'] === 'inactive',
                                        'bg-danger-500'  => $user['status'] === 'suspended',
                                    ])></span>
                                    {{ ucfirst($user['status']) }}
                                </button>
                            </td>

                            {{-- Joined --}}
                            <td class="px-4 py-3 text-xs text-primary-300">
                                {{ \Carbon\Carbon::parse($user['created_at'])->format('d M Y') }}
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1 opacity-0 transition group-hover:opacity-100">
                                    <a href="/admin/users/{{ $user['id'] }}/edit"
                                       class="flex size-7 items-center justify-center rounded-lg text-primary-300 hover:bg-primary-100 hover:text-primary-500 transition">
                                        <i class="ri-pencil-line text-sm"></i>
                                    </a>
                                    <button wire:click="confirmDelete({{ $user['id'] }})"
                                            class="flex size-7 items-center justify-center rounded-lg text-primary-300 hover:bg-danger-100 hover:text-danger-600 transition">
                                        <i class="ri-delete-bin-line text-sm"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-16 text-center">
                                <i class="ri-user-search-line mb-3 block text-4xl text-primary-200"></i>
                                <p class="font-semibold text-primary-400">No users found</p>
                                <p class="mt-1 text-sm text-primary-300">
                                    @if($search || $role || $status)
                                        Try adjusting your filters.
                                    @else
                                        Create your first user to get started.
                                    @endif
                                </p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Pagination ────────────────────────────────────────── --}}
        @if($lastPage > 1)
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-primary-300">
                Page {{ $currentPage }} of {{ $lastPage }} &middot; {{ number_format($total) }} users
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

    {{-- ══════════════════ MODALS ══════════════════ --}}

    {{-- Delete modal --}}
    @if($showDeleteModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showDeleteModal', false)">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
            <div class="mb-4 flex size-12 items-center justify-center rounded-full bg-danger-100">
                <i class="ri-error-warning-line text-xl text-danger-600"></i>
            </div>
            <h3 class="text-lg font-bold text-primary-500">Delete User?</h3>
            <p class="mt-2 text-sm text-primary-300">
                This is permanent. The user will lose all access. If they have order history, deletion will be blocked - deactivate instead.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <button wire:click="$set('showDeleteModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="deleteUser" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg bg-danger-600 px-4 py-2 text-sm font-semibold text-white hover:bg-danger-700 transition disabled:opacity-60">
                    <span wire:loading.remove wire:target="deleteUser">Delete User</span>
                    <span wire:loading wire:target="deleteUser" class="flex items-center gap-1.5">
                        <i class="ri-loader-4-line animate-spin"></i> Deleting…
                    </span>
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Status modal --}}
    @if($showStatusModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showStatusModal', false)">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
            <h3 class="text-lg font-bold text-primary-500">Change User Status</h3>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">New Status</label>
                    <select wire:model="newStatus"
                            class="w-full rounded-lg border border-primary-200 bg-white py-2 pl-3 pr-8 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                        Reason <span class="font-normal normal-case text-primary-200">(optional)</span>
                    </label>
                    <input wire:model="statusReason"
                           type="text"
                           placeholder="Reason for status change…"
                           class="w-full rounded-lg border border-primary-200 py-2 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20">
                </div>
                @if($newStatus === 'suspended')
                <div class="flex items-start gap-2 rounded-lg bg-warning-50 border border-warning-200 px-3 py-2.5 text-sm text-warning-700">
                    <i class="ri-alert-line mt-0.5 shrink-0"></i>
                    Suspending immediately revokes all active sessions.
                </div>
                @endif
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button wire:click="$set('showStatusModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="updateStatus"
                        class="rounded-lg bg-primary-500 px-4 py-2 text-sm font-semibold text-secondary-400 hover:bg-primary-600 transition">
                    Update Status
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Role modal --}}
    @if($showRoleModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showRoleModal', false)">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
            <h3 class="text-lg font-bold text-primary-500">Change Role</h3>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">Role</label>
                    <select wire:model="newRole"
                            class="w-full rounded-lg border border-primary-200 bg-white py-2 pl-3 pr-8 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none">
                        <option value="super_admin">Super Admin</option>
                        <option value="admin">Admin</option>
                        <option value="outlet_manager">Outlet Manager</option>
                        <option value="pos_clerk">POS Clerk</option>
                        <option value="tailor">Tailor</option>
                        <option value="customer">Customer</option>
                    </select>
                </div>
                <div class="flex items-start gap-2 rounded-lg bg-info-50 border border-info-200 px-3 py-2.5 text-sm text-info-700">
                    <i class="ri-information-line mt-0.5 shrink-0"></i>
                    Role changes take effect on the user's next request.
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button wire:click="$set('showRoleModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="updateRole"
                        class="rounded-lg bg-primary-500 px-4 py-2 text-sm font-semibold text-secondary-400 hover:bg-primary-600 transition">
                    Update Role
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Bulk confirm modal --}}
    @if($showBulkModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showBulkModal', false)">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
            <div @class([
                'mb-4 flex size-12 items-center justify-center rounded-full',
                'bg-danger-100' => $bulkAction === 'delete',
                'bg-warning-100' => $bulkAction !== 'delete',
            ])>
                <i @class([
                    'text-xl',
                    'ri-delete-bin-line text-danger-600' => $bulkAction === 'delete',
                    'ri-group-line text-warning-600' => $bulkAction !== 'delete',
                ])></i>
            </div>
            <h3 class="text-lg font-bold text-primary-500">Confirm Bulk Action</h3>
            <p class="mt-2 text-sm text-primary-300">
                You're about to
                <strong>{{ $bulkAction === 'delete' ? 'permanently delete' : "set status to «{$bulkAction}»" }}</strong>
                for <strong>{{ count($selected) }}</strong> user(s).
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <button wire:click="$set('showBulkModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="executeBulkAction"
                        @class([
                            'rounded-lg px-4 py-2 text-sm font-semibold text-white transition',
                            'bg-danger-600 hover:bg-danger-700' => $bulkAction === 'delete',
                            'bg-primary-500 hover:bg-primary-600 text-secondary-400' => $bulkAction !== 'delete',
                        ])>
                    Confirm
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Toast ────────────────────────────────────────────── --}}
    @include('livewire.admin._partials.toast')

</div>