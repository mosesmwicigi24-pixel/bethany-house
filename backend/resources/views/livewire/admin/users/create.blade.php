<div class="min-h-screen bg-primary-50/30">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-8">

        {{-- ── Header ──────────────────────────────────────────── --}}
        <div class="mb-6">
            <a href="/admin/users"
                class="mb-3 inline-flex items-center gap-1.5 text-sm text-primary-300 hover:text-primary-500 transition">
                <i class="ri-arrow-left-line"></i>
                Back to Users
            </a>
            <h1 class="text-2xl font-bold text-primary-500">Create User</h1>
        </div>

        {{-- ── API errors --}}
        {{-- @if (count($apiErrors))
            <div class="mb-5 rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
                <p class="mb-1 font-semibold">Please fix the following errors:</p>
                <ul class="list-inside list-disc space-y-0.5">
                    @foreach ($apiErrors as $field => $msgs)
                        @foreach ($msgs as $msg)
                            <li>{{ $msg }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif --}}

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_260px]">
            <div class="space-y-5">

                {{-- ── Personal info ──────────────────────────── --}}
                <div class="rounded-xl border border-primary-100 bg-white p-6 shadow-sm">
                    <h2
                        class="mb-5 flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-primary-300">
                        <i class="ri-user-line text-secondary-500"></i>
                        Personal Information
                    </h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                Full Name <span class="text-danger-500">*</span>
                            </label>
                            <input wire:model="name" type="text" placeholder="e.g. Jane Wambui" autocomplete="off"
                                class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('name') border-danger-400 @enderror">
                            @error('name')
                                <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                Email <span class="text-danger-500">*</span>
                            </label>
                            <input wire:model="email" type="email" placeholder="jane@example.com" autocomplete="off"
                                class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('email') border-danger-400 @enderror">
                            @error('email')
                                <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                Phone <span class="font-normal normal-case text-primary-200">(optional)</span>
                            </label>
                            <input wire:model="phone" type="tel" placeholder="+254712345678"
                                class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20">
                        </div>

                    </div>
                </div>

                {{-- ── Password ──────────────────────────────── --}}
                <div class="rounded-xl border border-primary-100 bg-white p-6 shadow-sm">
                    <h2
                        class="mb-5 flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-primary-300">
                        <i class="ri-shield-keyhole-line text-secondary-500"></i>
                        Password
                    </h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                Password <span class="text-danger-500">*</span>
                            </label>
                            <div class="relative">
                                <input wire:model.live="password" :type="$wire.showPassword ? 'text' : 'password'"
                                    placeholder="Min. 8 characters" x-data
                                    class="w-full rounded-lg border border-primary-200 py-2.5 pl-3 pr-10 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('password') border-danger-400 @enderror">
                                <button type="button" wire:click="$toggle('showPassword')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-primary-300 hover:text-primary-500">
                                    <i class="ri-eye{{ $showPassword ? '-off' : '' }}-line"></i>
                                </button>
                            </div>
                            @error('password')
                                <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                            @enderror

                            {{-- Strength bars --}}
                            @if ($password)
                                @php $strength = $this->passwordStrength(); @endphp
                                <div class="mt-2 flex items-center gap-2">
                                    <div class="flex flex-1 gap-1">
                                        @for ($i = 1; $i <= 4; $i++)
                                            <div @class([
                                                'h-1 flex-1 rounded-full transition-all duration-300',
                                                'bg-primary-100' => $i > $strength,
                                                'bg-danger-500' => $i <= $strength && $strength === 1,
                                                'bg-warning-500' => $i <= $strength && $strength === 2,
                                                'bg-info-500' => $i <= $strength && $strength === 3,
                                                'bg-success-500' => $i <= $strength && $strength === 4,
                                            ])></div>
                                        @endfor
                                    </div>
                                    <span @class([
                                        'text-xs font-semibold',
                                        'text-danger-600' => $strength === 1,
                                        'text-warning-600' => $strength === 2,
                                        'text-info-600' => $strength === 3,
                                        'text-success-600' => $strength === 4,
                                    ])>
                                        {{ ['', 'Weak', 'Fair', 'Good', 'Strong'][$strength] }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                Confirm Password <span class="text-danger-500">*</span>
                            </label>
                            <input wire:model="passwordConfirm" :type="$wire.showPassword ? 'text' : 'password'"
                                placeholder="Repeat password" x-data
                                class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('passwordConfirm') border-danger-400 @enderror">
                            @error('passwordConfirm')
                                <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                            @enderror
                        </div>

                    </div>

                    <label class="mt-4 inline-flex cursor-pointer items-center gap-2 text-sm text-primary-400">
                        <input wire:model="sendWelcomeEmail" type="checkbox"
                            class="size-4 cursor-pointer rounded accent-primary-500">
                        Send welcome email with login credentials
                    </label>
                </div>

                {{-- ── Role & Access ─────────────────────────── --}}
                <div class="rounded-xl border border-primary-100 bg-white p-6 shadow-sm">
                    <h2
                        class="mb-5 flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-primary-300">
                        <i class="ri-key-2-line text-secondary-500"></i>
                        Role & Access
                    </h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                Role <span class="text-danger-500">*</span>
                            </label>
                            <select wire:model.live="role"
                                class="w-full rounded-lg border border-primary-200 bg-white py-2.5 pl-3 pr-8 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer @error('role') border-danger-400 @enderror">
                                <option value="customer">Customer</option>
                                <option value="tailor">Tailor</option>
                                <option value="pos_clerk">POS Clerk</option>
                                <option value="outlet_manager">Outlet Manager</option>
                                <option value="admin">Admin</option>
                                <option value="super_admin">Super Admin</option>
                            </select>
                            @error('role')
                                <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label
                                class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">Status</label>
                            <select wire:model="status"
                                class="w-full rounded-lg border border-primary-200 bg-white py-2.5 pl-3 pr-8 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        @if ($this->needsOutlet())
                            <div class="sm:col-span-2">
                                <label
                                    class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                    Outlet <span class="text-danger-500">*</span>
                                </label>
                                <select wire:model="outletId"
                                    class="w-full rounded-lg border border-primary-200 bg-white py-2.5 pl-3 pr-8 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                                    <option value="">Select an outlet…</option>
                                    @foreach ($outlets as $o)
                                        <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-primary-300">POS Clerks and Outlet Managers must be assigned
                                    to an outlet.</p>
                            </div>
                        @endif

                    </div>

                    {{-- Role permission preview --}}
                    @php
                        $perms = match ($role) {
                            'super_admin' => [
                                'All admin areas',
                                'User management',
                                'System settings',
                                'All reports',
                                'All outlets',
                            ],
                            'admin' => ['Products', 'Orders', 'Inventory', 'Production', 'Suppliers', 'Reports'],
                            'outlet_manager' => ['Orders', 'Inventory', 'Reports', 'POS access'],
                            'pos_clerk' => ['POS sales', 'Cash register'],
                            'tailor' => ['Production orders', 'Stage updates'],
                            default => ['Shop', 'Order history', 'Profile'],
                        };
                    @endphp
                    <div class="mt-4 rounded-lg bg-primary-50 p-3.5">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-primary-300">This role grants
                            access to:</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($perms as $perm)
                                <span
                                    class="inline-flex items-center gap-1 rounded-full bg-secondary-100 px-2.5 py-0.5 text-xs font-semibold text-secondary-700">
                                    <i class="ri-check-line text-secondary-600"></i>
                                    {{ $perm }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                </div>

            </div>

            {{-- ── Sidebar ──────────────────────────────────────── --}}
            <div class="space-y-4">
                <div class="sticky top-6 rounded-xl border border-primary-100 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-primary-100 px-5 py-4">
                        <h3 class="text-sm font-bold text-primary-500">Actions</h3>
                    </div>
                    <div class="p-4 space-y-2">
                        <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary-500 py-2.5 text-sm font-semibold text-secondary-400 shadow-sm hover:bg-primary-600 transition disabled:opacity-60">
                            <span wire:loading.remove wire:target="save">
                                <i class="ri-user-follow-line"></i> Create User
                            </span>
                            <span wire:loading wire:target="save" class="flex items-center gap-2">
                                <i class="ri-loader-4-line animate-spin"></i> Creating…
                            </span>
                        </button>
                        <a href="/admin/users"
                            class="flex w-full items-center justify-center gap-2 rounded-lg border border-primary-200 py-2.5 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                            Cancel
                        </a>
                    </div>
                    <div class="border-t border-primary-50 bg-primary-50/60 px-5 py-4">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-primary-300">Tips</p>
                        <ul class="space-y-2 text-xs text-primary-300">
                            <li class="flex gap-1.5"><i
                                    class="ri-arrow-right-s-line shrink-0 text-secondary-500"></i>Use at least 8 chars
                                with uppercase and numbers for a strong password.</li>
                            <li class="flex gap-1.5"><i
                                    class="ri-arrow-right-s-line shrink-0 text-secondary-500"></i>POS Clerks and Outlet
                                Managers need an outlet assignment.</li>
                            <li class="flex gap-1.5"><i
                                    class="ri-arrow-right-s-line shrink-0 text-secondary-500"></i>Super Admin gives
                                unrestricted access - assign carefully.</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- ── Toast ────────────────────────────────────────────── --}}
    @include('livewire.admin._partials.toast')

</div>
