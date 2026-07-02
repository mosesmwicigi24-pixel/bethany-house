<!DOCTYPE html>
<html lang="en" x-data="{ theme: localStorage.getItem('theme') || 'light' }" x-init="$watch('theme', val => {
    localStorage.setItem('theme', val);
    document.documentElement.classList.toggle('dark', val === 'dark');
})" :class="{ 'dark': theme === 'dark' }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="font-dm-sans bg-primary-50 text-slate-800 dark:bg-slate-900 dark:text-slate-100">
    <div class="min-h-screen" x-data>
        <!-- Header -->
        @include('layouts.partials.admin.header')
        <!-- End Header -->

        <!-- Main Content Wrapper -->
        <div class="flex flex-col md:flex-row">
            <!-- Sidebar -->
            @include('layouts.partials.admin.left-sidebar')
            <!-- End Sidebar -->

            <!-- Main Page Content -->
            <main class="container mx-auto mt-[60px] w-full p-4 transition-all duration-300 ease-in-out"
                :class="$store.sidebar.collapsed ? 'md:ms-20' : 'md:ms-3'">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts

    <!-- Alpine Store for Sidebar Control -->
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('sidebar', {
                open: window.innerWidth >= 768,
                isDesktop: window.innerWidth >= 768,
                collapsed: false,
                toggle() {
                    this.open = !this.open;
                    document.body.classList.toggle('overflow-hidden', this.open && !this.isDesktop);
                },
                toggleCollapse() {
                    this.collapsed = !this.collapsed;
                },
                handleResize() {
                    const isNowDesktop = window.innerWidth >= 768;
                    if (this.isDesktop !== isNowDesktop) {
                        this.isDesktop = isNowDesktop;
                        this.open = isNowDesktop; // Open on desktop, closed on mobile
                        this.collapsed = false;
                        document.body.classList.remove('overflow-hidden');
                    }
                },
                group: null
            });

            // ✅ Run handleResize on page load to correct initial state
            Alpine.store('sidebar').handleResize();

            // ✅ Debounced resize listener (optional but better UX)
            let resizeTimeout;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(() => {
                    Alpine.store('sidebar').handleResize();
                }, 100);
            });
        });
    </script>
</body>

</html>
