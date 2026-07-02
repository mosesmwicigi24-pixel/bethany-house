@props(['user' => null])

<div x-data="{ open: false }" class="relative header-element flex items-center px-2">
    <button @click="open = !open" @click.outside="open = false" class="flex items-center gap-2 focus:outline-none">
        <img src="{{ $user?->avatar_url ?? 'https://laravelui.spruko.com/tailwind/ynex/build/assets/images/faces/9.jpg' }}"
     class="rounded-full w-8 h-8" alt="{{ $user?->name ?? 'User' }}" />
        <div class="hidden md:flex flex-col text-left">
            <span class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-0">
                {{ $user?->name ?? 'Guest User' }}
            </span>
            <span class="text-xs text-slate-500 dark:text-slate-400">
                {{ $user?->getRoleNames()->first() ?? 'Guest User' }}
            </span>
        </div>
    </button>

    <!-- Dropdown -->
    <div x-show="open" x-transition.origin.top.right
         class="absolute right-0 top-full mt-0 w-48 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-md shadow-lg z-50"
         @click.outside="open = false">
        <ul class="text-sm text-gray-700 dark:text-white/70 divide-y divide-gray-100 dark:divide-slate-600">
            <li>
                <a href="/admin/profile" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-slate-700">
                    <i class="ti ti-user-circle text-lg opacity-70"></i> Profile
                </a>
            </li>
            <li>
                <a href="/admin/settings" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-slate-700">
                    <i class="ti ti-adjustments-horizontal text-lg opacity-70"></i> Settings
                </a>
            </li>
            <li>
                <a href="/logout" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-100 dark:hover:bg-slate-700">
                    <i class="ti ti-logout text-lg opacity-70"></i> Log Out
                </a>
            </li>
        </ul>
    </div>
</div>
