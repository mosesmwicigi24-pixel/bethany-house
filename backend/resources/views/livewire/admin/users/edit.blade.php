<div class="min-h-screen bg-primary-50/30">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-8">

        {{-- ── Header ──────────────────────────────────────────── --}}
        <div class="mb-6">
            <a href="/admin/users"
                class="mb-3 inline-flex items-center gap-1.5 text-sm text-primary-300 hover:text-primary-500 transition">
                <i class="ri-arrow-left-line"></i>
                Back to Users
            </a>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-primary-500">Edit User</h1>
                    @if ($userData)
                        <p class="mt-0.5 text-sm text-primary-300">{{ $userData['email'] }}</p>
                    @endif
                </div>
                {{-- Quick stats for POS/Tailor roles --}}
                @if (!empty($userStats))
                    <div class="flex gap-4">
                        @foreach ($userStats as $key => $val)
                            <div class="text-center">
                                <p class="text-xl font-bold text-primary-500">
                                    {{ is_numeric($val) ? number_format($val) : $val }}</p>
                                <p class="text-xs text-primary-300">{{ ucwords(str_replace('_', ' ', $key)) }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- ── API errors --}}
        {{-- @if (count($apiErrors))
            <div class="mb-5 rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
                <p class="mb-1 font-semibold">Please fix the following errors:</p>
                <ul class="list-inside list-disc space-y-0.5">
                    @foreach ($apiErrors as $msgs)
                        @foreach ((array) $msgs as $msg)
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
                            <input wire:model="name" type="text"
                                class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('name') border-danger-400 @enderror">
                            @error('name')
                                <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                Email <span class="text-danger-500">*</span>
                            </label>
                            <input wire:model="email" type="email"
                                class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('email') border-danger-400 @enderror">
                            @error('email')
                                <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label
                                class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">Phone</label>
                            <input wire:model="phone" type="tel"
                                class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20">
                        </div>

                        <div class="sm:col-span-2">
                            <label
                                class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">Outlet</label>
                            <select wire:model="outletId"
                                class="w-full rounded-lg border border-primary-200 bg-white py-2.5 pl-3 pr-8 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                                <option value="">No outlet assigned</option>
                                @foreach ($outlets as $o)
                                    <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                @endforeach
                            </select>
                        </div>

                    </div>
                </div>

                {{-- ── Role & Status ─────────────────────────── --}}
                <div class="rounded-xl border border-primary-100 bg-white p-6 shadow-sm">
                    <h2
                        class="mb-5 flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-primary-300">
                        <i class="ri-key-2-line text-secondary-500"></i>
                        Role & Status
                    </h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div>
                            <label
                                class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">Role</label>
                            <select wire:change="updateRole($event.target.value)" x-data
                                class="w-full rounded-lg border border-primary-200 bg-white py-2.5 pl-3 pr-8 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                                @foreach (['super_admin', 'admin', 'outlet_manager', 'pos_clerk', 'tailor', 'customer'] as $r)
                                    <option value="{{ $r }}" @selected($role === $r)>
                                        {{ ucwords(str_replace('_', ' ', $r)) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label
                                class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">Status</label>
                            <select wire:change="updateStatus($event.target.value)" x-data
                                class="w-full rounded-lg border border-primary-200 bg-white py-2.5 pl-3 pr-8 text-sm text-primary-500 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 appearance-none cursor-pointer">
                                @foreach (['active', 'inactive', 'suspended'] as $s)
                                    <option value="{{ $s }}" @selected($status === $s)>
                                        {{ ucfirst($s) }}</option>
                                @endforeach
                            </select>
                        </div>

                    </div>
                </div>

                {{-- ── Reset Password ───────────────────────────── --}}
                <div class="rounded-xl border border-primary-100 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="flex items-center gap-2 text-sm font-bold uppercase tracking-wider text-primary-300">
                            <i class="ri-lock-password-line text-secondary-500"></i>
                            Reset Password
                        </h2>
                        <button wire:click="$toggle('showPwReset')"
                            class="text-sm font-semibold text-primary-400 hover:text-primary-500 transition">
                            {{ $showPwReset ? 'Cancel' : 'Change password' }}
                        </button>
                    </div>

                    @if ($showPwReset)
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                    New Password <span class="text-danger-500">*</span>
                                </label>
                                <div class="relative">
                                    <input wire:model="newPassword" :type="$wire.showPassword ? 'text' : 'password'"
                                        x-data placeholder="Min. 8 characters"
                                        class="w-full rounded-lg border border-primary-200 py-2.5 pl-3 pr-10 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('newPassword') border-danger-400 @enderror">
                                    <button type="button" wire:click="$toggle('showPassword')"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-primary-300 hover:text-primary-500">
                                        <i class="ri-eye{{ $showPassword ? '-off' : '' }}-line"></i>
                                    </button>
                                </div>
                                @error('newPassword')
                                    <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label
                                    class="mb-1.5 block text-xs font-semibold uppercase tracking-wider text-primary-300">
                                    Confirm Password <span class="text-danger-500">*</span>
                                </label>
                                <input wire:model="newPasswordConfirm" :type="$wire.showPassword ? 'text' : 'password'"
                                    x-data placeholder="Repeat password"
                                    class="w-full rounded-lg border border-primary-200 py-2.5 px-3 text-sm text-primary-500 placeholder-primary-200 focus:border-secondary-500 focus:outline-none focus:ring-2 focus:ring-secondary-500/20 @error('newPasswordConfirm') border-danger-400 @enderror">
                                @error('newPasswordConfirm')
                                    <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="sm:col-span-2 flex justify-end">
                                <button wire:click="resetPassword"
                                    class="inline-flex items-center gap-2 rounded-lg bg-warning-600 px-4 py-2 text-sm font-semibold text-white hover:bg-warning-700 transition">
                                    <i class="ri-lock-password-line"></i>
                                    Reset Password
                                </button>
                            </div>
                        </div>
                    @else
                        <p class="mt-3 text-sm text-primary-300">
                            Resetting the password will invalidate all active sessions for this user.
                        </p>
                    @endif
                </div>

            </div>

            {{-- ── Sidebar ──────────────────────────────────────── --}}
            <div class="space-y-4">
                <div class="sticky top-6 rounded-xl border border-primary-100 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-primary-100 px-5 py-4">
                        <h3 class="text-sm font-bold text-primary-500">Save Changes</h3>
                    </div>
                    <div class="p-4 space-y-2">
                        <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary-500 py-2.5 text-sm font-semibold text-secondary-400 shadow-sm hover:bg-primary-600 transition disabled:opacity-60">
                            <span wire:loading.remove wire:target="save">
                                <i class="ri-save-line"></i> Save Changes
                            </span>
                            <span wire:loading wire:target="save" class="flex items-center gap-2">
                                <i class="ri-loader-4-line animate-spin"></i> Saving…
                            </span>
                        </button>
                        <a href="/admin/users"
                            class="flex w-full items-center justify-center rounded-lg border border-primary-200 py-2.5 text-sm font-medium text-primary-400 hover:bg-primary-50 transition">
                            Cancel
                        </a>
                    </div>
                    @if ($userData)
                        <div
                            class="border-t border-primary-50 bg-primary-50/60 px-5 py-4 text-xs text-primary-300 space-y-1">
                            <p><span class="font-semibold">ID:</span> #{{ $userData['id'] }}</p>
                            <p><span class="font-semibold">Member since:</span>
                                {{ \Carbon\Carbon::parse($userData['created_at'])->format('d M Y') }}</p>
                            <p><span class="font-semibold">2FA:</span>
                                {{ $userData['two_factor_enabled'] ?? false ? 'Enabled' : 'Disabled' }}</p>
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>

    {{-- ── Toast ────────────────────────────────────────────── --}}
    @include('livewire.admin._partials.toast')

</div>
