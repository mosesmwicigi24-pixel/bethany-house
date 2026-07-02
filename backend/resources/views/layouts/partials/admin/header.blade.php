<header :class="{'ps-4': !$store.sidebar.isDesktop,'md:ps-20': $store.sidebar.collapsed,'md:ps-60': !$store.sidebar.collapsed}"
    class="fixed top-0 start-0 z-[49] h-[4.05rem] w-full max-w-full border-b border-b-gray-100 bg-white bg-opacity-[var(--tw-bg-opacity)] transition-all duration-300 ease-in-out dark:border-b dark:border-b-[#ffffff1a] dark:bg-[rgb(var(--body-bg))] flex justify-between items-center px-4"
    style="--tw-bg-opacity: 1;" data-header-styles="color">
    <div class="flex items-center gap-4">
        <!-- Collapse Sidebar Toggle for Desktop -->
        <button @click="$store.sidebar.toggleCollapse()"
            class="hidden md:block ms-2 text-2xl text-slate-900 dark:text-slate-200">
            <i class="bi " :class="$store.sidebar.collapsed ? 'bi-x' : 'bi-list'"></i>
        </button>

        <!-- Open Sidebar Toggle for Mobile -->
        <button @click="$store.sidebar.open = !$store.sidebar.open" class="md:hidden">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
    </div>

    <div class="header-content-right flex gap-2">
        <!-- Theme toggle -->
        <div class="header-element hidden sm:block px-2 py-4">
            <button @click="theme = theme === 'dark' ? 'light' : 'dark'; localStorage.setItem('theme', theme)"
                class="flex items-center justify-center text-xl text-slate-600 dark:text-slate-300">
                <i :class="theme === 'dark' ? 'bx bx-sun' : 'bx bx-moon'"></i>
            </button>
        </div>

        <!-- Notification -->
        <div class="header-element hidden md:block px-2 py-4 relative">
            <button class="relative flex items-center text-xl">
                <i class="bx bx-bell"></i>
                <span
                    class="absolute top-0 right-0 -mt-1 -mr-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-600 text-xs text-white">5</span>
            </button>
        </div>

        <!-- Fullscreen -->
        <div class="header-element px-2 py-4">
            <a href="javascript:void(0);" onclick="openFullscreen();" class="text-xl">
                <i class="bx bx-fullscreen full-screen-open"></i>
                <i class="bx bx-exit-fullscreen full-screen-close hidden"></i>
            </a>
        </div>

        <!-- Profile -->
        <x-admin.header.profile-dropdown :user="auth()->user()" />
    </div>
</header>
