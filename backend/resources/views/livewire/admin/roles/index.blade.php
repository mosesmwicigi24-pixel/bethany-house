<div class="min-h-screen bg-primary-50/30">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">

        {{-- ── Header ──────────────────────────────────────────── --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold text-primary-500">
                    <i class="ri-key-2-fill text-secondary-500"></i>
                    Roles & Permissions
                </h1>
                <p class="mt-0.5 text-sm text-primary-300">
                    {{ count($roles) }} roles &middot; {{ count($permissions) }} permissions defined
                </p>
            </div>
            <button wire:click="openCreate"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary-500 px-4 py-2 text-sm font-semibold text-secondary-400 shadow-sm hover:bg-primary-600 transition">
                <i class="ri-add-line"></i>
                New Role
            </button>
        </div>

        {{-- ── Role cards ────────────────────────────────────────── --}}
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($roles as $role)
            <div wire:key="role-{{ $role['id'] }}"
                 class="group relative flex flex-col rounded-xl border border-primary-100 bg-white shadow-sm hover:border-secondary-300 hover:shadow-md transition-all">

                <div class="flex items-start gap-3 p-5 pb-4 border-b border-primary-50">
                    {{-- Icon --}}
                    <div @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-xl text-lg',
                        'bg-primary-500 text-secondary-400'   => in_array($role['name'], ['super_admin','admin']),
                        'bg-secondary-100 text-secondary-700' => !in_array($role['name'], ['super_admin','admin']),
                    ])>
                        <i class="{{ in_array($role['name'], ['super_admin','admin']) ? 'ri-shield-fill' : 'ri-shield-check-line' }}"></i>
                    </div>

                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-primary-500 leading-tight">
                            {{ $role['display_name'] ?? $role['name'] }}
                        </h3>
                        <code class="text-xs text-primary-300 bg-primary-50 rounded px-1.5 py-0.5">
                            {{ $role['name'] }}
                        </code>
                    </div>

                    {{-- Kebab menu --}}
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open"
                                class="flex size-7 items-center justify-center rounded-lg text-primary-300 hover:bg-primary-100 hover:text-primary-500 transition">
                            <i class="ri-more-2-fill"></i>
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak
                             class="absolute right-0 top-8 z-20 w-44 rounded-xl border border-primary-100 bg-white py-1 shadow-lg">
                            <button wire:click="openEdit({{ $role['id'] }}, '{{ addslashes($role['display_name'] ?? '') }}', '{{ addslashes($role['description'] ?? '') }}')"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-primary-500 hover:bg-primary-50 transition">
                                <i class="ri-pencil-line text-primary-300"></i> Edit
                            </button>
                            <button wire:click="openPermissions({{ $role['id'] }}, '{{ addslashes($role['display_name'] ?? $role['name']) }}')"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-primary-500 hover:bg-primary-50 transition">
                                <i class="ri-key-line text-primary-300"></i> Edit Permissions
                            </button>
                            <button wire:click="duplicate({{ $role['id'] }})"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-primary-500 hover:bg-primary-50 transition">
                                <i class="ri-file-copy-line text-primary-300"></i> Duplicate
                            </button>
                            <hr class="my-1 border-primary-100">
                            <button wire:click="confirmDelete({{ $role['id'] }})"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm text-danger-600 hover:bg-danger-50 transition">
                                <i class="ri-delete-bin-line"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>

                @if($role['description'] ?? null)
                <p class="px-5 pt-3 text-xs text-primary-300 leading-relaxed">{{ $role['description'] }}</p>
                @endif

                <div class="mt-auto flex border-t border-primary-50">
                    <div class="flex-1 border-r border-primary-50 py-3 text-center">
                        <p class="text-xl font-bold text-primary-500">{{ $role['users_count'] ?? '-' }}</p>
                        <p class="text-xs text-primary-300">Users</p>
                    </div>
                    <div class="flex-1 py-3 text-center">
                        <p class="text-xl font-bold text-primary-500">{{ $role['permissions_count'] ?? '-' }}</p>
                        <p class="text-xs text-primary-300">Permissions</p>
                    </div>
                </div>

                <div class="p-4 pt-0">
                    <button wire:click="openPermissions({{ $role['id'] }}, '{{ addslashes($role['display_name'] ?? $role['name']) }}')"
                            class="flex w-full items-center justify-center gap-2 rounded-lg border border-primary-200 py-2 text-xs font-semibold text-primary-400 hover:border-secondary-400 hover:bg-secondary-50 hover:text-secondary-700 transition">
                        <i class="ri-sliders-2-line"></i>
                        Manage Permissions
                    </button>
                </div>

            </div>
            @empty
            <div class="col-span-full py-16 text-center">
                <i class="ri-key-line mb-3 block text-4xl text-primary-200"></i>
                <p class="font-semibold text-primary-400">No roles defined yet</p>
                <p class="mt-1 text-sm text-primary-300">Create your first role to manage user access.</p>
            </div>
            @endforelse
        </div>

        {{-- ── All permissions reference ─────────────────────────── --}}
        <div class="rounded-xl border border-primary-100 bg-white shadow-sm overflow-hidden">
            <div class="flex items-center gap-3 border-b border-primary-100 px-6 py-4">
                <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-primary-300">
                    <i class="ri-list-check-2 text-secondary-500"></i>
                    All Permissions
                </h2>
                <span class="rounded-full bg-primary-100 px-2 py-0.5 text-xs font-bold text-primary-400">
                    {{ count($permissions) }}
                </span>
            </div>
            <div class="divide-y divide-primary-50">
                @foreach($grouped as $group => $perms)
                <div class="px-6 py-4">
                    <h4 class="mb-3 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-primary-300">
                        <i class="ri-folder-2-line text-secondary-400"></i>
                        {{ $group }}
                    </h4>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($perms as $perm)
                        <div class="flex items-start gap-3 rounded-lg bg-primary-50/60 p-3">
                            <i class="ri-check-double-line mt-0.5 shrink-0 text-success-500"></i>
                            <div>
                                <p class="text-xs font-semibold text-primary-500">{{ $perm['display_name'] ?? $perm['name'] }}</p>
                                <code class="text-xs text-primary-300">{{ $perm['name'] }}</code>
                                @if($perm['description'] ?? null)
                                <p class="mt-0.5 text-xs text-primary-300">{{ $perm['description'] }}</p>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </div>

    </div>

    {{-- ════════════════════ MODALS ════════════════════ --}}

    {{-- Create Role --}}
    @if($showCreateModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showCreateModal', false)">
        <div class="w-full max-w-md rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-primary-100 px-6 py-4">
                <h3 class="text-base font-bold text-primary-500">
                    <i class="ri-add-circle-line mr-1 text-secondary-500"></i> Create Role
                </h3>
                <button wire:click="$set('showCreateModal', false)"
                        class="flex size-7 items-center justify-center rounded-lg text-primary-300 hover:bg-primary-100 hover:text-primary-500 transition">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="space-y-4 p-6">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                        Role Key <span class="text-danger-500">*</span>
                        <span class="font-normal normal-case text-primary-200">(no spaces)</span>
                    </label>
                    <input wire:model="newRoleName" type="text" placeholder="e.g. store_manager"
                           class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('newRoleName') border-danger-400 @enderror">
                    @error('newRoleName') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-primary-300">Letters, numbers, dashes and underscores only.</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                        Display Name <span class="text-danger-500">*</span>
                    </label>
                    <input wire:model="newRoleDisplay" type="text" placeholder="e.g. Store Manager"
                           class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('newRoleDisplay') border-danger-400 @enderror">
                    @error('newRoleDisplay') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                        Description <span class="font-normal normal-case text-primary-200">(optional)</span>
                    </label>
                    <textarea wire:model="newRoleDescription" rows="2" placeholder="What does this role do?"
                              class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 resize-none"></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-primary-100 px-6 py-4">
                <button wire:click="$set('showCreateModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="createRole"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary-500 px-4 py-2 text-sm font-semibold text-secondary-400 hover:bg-primary-600 transition">
                    <i class="ri-add-line"></i> Create Role
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Edit Role --}}
    @if($showEditModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showEditModal', false)">
        <div class="w-full max-w-md rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-primary-100 px-6 py-4">
                <h3 class="text-base font-bold text-primary-500">
                    <i class="ri-pencil-line mr-1 text-secondary-500"></i> Edit Role
                </h3>
                <button wire:click="$set('showEditModal', false)"
                        class="flex size-7 items-center justify-center rounded-lg text-primary-300 hover:bg-primary-100 transition">
                    <i class="ri-close-line"></i>
                </button>
            </div>
            <div class="space-y-4 p-6">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                        Display Name <span class="text-danger-500">*</span>
                    </label>
                    <input wire:model="editRoleDisplay" type="text"
                           class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('editRoleDisplay') border-danger-400 @enderror">
                    @error('editRoleDisplay') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">Description</label>
                    <textarea wire:model="editRoleDescription" rows="2"
                              class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 resize-none"></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-primary-100 px-6 py-4">
                <button wire:click="$set('showEditModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="saveEdit"
                        class="rounded-lg bg-primary-500 px-4 py-2 text-sm font-semibold text-secondary-400 hover:bg-primary-600 transition">
                    Save Changes
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Permissions editor --}}
    @if($showPermModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showPermModal', false)">
        <div class="flex h-[90vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-xl">
            <div class="flex shrink-0 items-center justify-between border-b border-primary-100 px-6 py-4">
                <div>
                    <h3 class="text-base font-bold text-primary-500">
                        <i class="ri-key-line mr-1 text-secondary-500"></i> Permissions
                    </h3>
                    <p class="text-xs text-primary-300 mt-0.5">Role: <strong>{{ $permRoleName }}</strong></p>
                </div>
                <button wire:click="$set('showPermModal', false)"
                        class="flex size-7 items-center justify-center rounded-lg text-primary-300 hover:bg-primary-100 transition">
                    <i class="ri-close-line"></i>
                </button>
            </div>

            {{-- Toolbar --}}
            <div class="shrink-0 flex items-center justify-between border-b border-primary-50 px-6 py-3">
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-primary-400">
                    <input type="checkbox"
                           class="size-4 cursor-pointer rounded accent-primary-500"
                           :checked="{{ count($selectedPerms) }} === {{ count($permissions) }}"
                           wire:change="toggleAllPerms($event.target.checked)"
                           x-data>
                    Select all
                </label>
                <span class="rounded-full bg-secondary-100 px-2.5 py-0.5 text-xs font-bold text-secondary-700">
                    {{ count($selectedPerms) }} / {{ count($permissions) }} selected
                </span>
            </div>

            {{-- Scrollable permissions list --}}
            <div class="flex-1 overflow-y-auto px-6 py-4 space-y-5">
                @foreach($grouped as $group => $perms)
                <div>
                    <h4 class="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-primary-300 sticky top-0 bg-white py-1">
                        <i class="ri-folder-2-line text-secondary-400"></i>
                        {{ $group }}
                    </h4>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach($perms as $perm)
                        <label wire:key="perm-{{ $perm['id'] }}"
                               class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-all
                                      {{ in_array((string)$perm['id'], $selectedPerms)
                                         ? 'border-secondary-400 bg-secondary-50'
                                         : 'border-primary-100 hover:border-primary-200 hover:bg-primary-50/60' }}">
                            <input wire:model.live="selectedPerms"
                                   type="checkbox"
                                   value="{{ $perm['id'] }}"
                                   class="mt-0.5 size-4 shrink-0 cursor-pointer rounded accent-primary-500">
                            <div>
                                <p class="text-sm font-semibold text-primary-500">{{ $perm['display_name'] }}</p>
                                <code class="text-xs text-primary-300">{{ $perm['name'] }}</code>
                                @if($perm['description'] ?? null)
                                <p class="mt-0.5 text-xs text-primary-300">{{ $perm['description'] }}</p>
                                @endif
                            </div>
                        </label>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            <div class="shrink-0 flex justify-end gap-3 border-t border-primary-100 px-6 py-4">
                <button wire:click="$set('showPermModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="syncPermissions"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary-500 px-4 py-2 text-sm font-semibold text-secondary-400 hover:bg-primary-600 transition">
                    <i class="ri-check-line"></i> Save Permissions
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Delete role --}}
    @if($showDeleteModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-primary-900/50 p-4 backdrop-blur-sm"
         wire:click.self="$set('showDeleteModal', false)">
        <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
            <div class="mb-4 flex size-12 items-center justify-center rounded-full bg-danger-100">
                <i class="ri-error-warning-line text-xl text-danger-600"></i>
            </div>
            <h3 class="text-lg font-bold text-primary-500">Delete Role?</h3>
            <p class="mt-2 text-sm text-primary-300">
                This permanently deletes the role and its permission assignments. Roles with assigned users cannot be deleted.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <button wire:click="$set('showDeleteModal', false)"
                        class="rounded-lg border border-primary-200 px-4 py-2 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                    Cancel
                </button>
                <button wire:click="deleteRole"
                        class="inline-flex items-center gap-2 rounded-lg bg-danger-600 px-4 py-2 text-sm font-semibold text-white hover:bg-danger-700 transition">
                    <i class="ri-delete-bin-line"></i> Delete Role
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Toast --}}
    @include('livewire.admin._partials.toast')

</div>