{{-- Admin Login Page - Modern Split Design --}}
<div class="min-h-screen flex flex-col lg:flex-row w-full">

    {{-- Left Side - Auth Cover with Branding --}}
    <div class="hidden lg:flex lg:flex-1 relative bg-gradient-to-br from-primary-600 via-primary-700 to-primary-800">
        {{-- Background Image with Overlay --}}
        <div class="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-10"
            style="background-image: url('{{ asset('images/login-bg.jpg') }}');">
        </div>

        {{-- Content (needs to be relative to appear above background) --}}
        <div class="relative w-full h-full p-10 flex flex-col justify-between">
            {{-- Logo --}}
            <div class="space-y-36">
                <div>
                    <a href="/" class="inline-block">
                        <img src="{{ asset('images/logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-12" />
                    </a>
                </div>

                {{-- Center Content --}}
                <div class="space-y-6 text-white">
                    <div class="space-y-4">
                        <h2 class="text-3xl font-bold leading-tight">
                            Bethany House Admin
                        </h2>
                        <p class="text-sm text-primary-100 leading-relaxed">
                            Manage your e-commerce platform, production, inventory,<br> and sales all in one place.
                        </p>
                    </div>

                </div>
            </div>

            {{-- Footer Info --}}
            <div class="text-xs text-primary-100">
                <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            </div>
        </div>
    </div>

    {{-- Right Side - Login Form --}}
    <div class="flex flex-1 items-center justify-center p-6 bg-gray-50 dark:bg-slate-900">
        <div class="w-full max-w-md">

            {{-- Mobile Logo --}}
            <div class="lg:hidden mb-8 text-center">
                <img src="{{ asset('images/logo.svg') }}" alt="{{ config('app.name') }}"
                    class="h-12 mx-auto dark:hidden" />
                <img src="{{ asset('images/logo-light.svg') }}" alt="{{ config('app.name') }}"
                    class="h-12 mx-auto hidden dark:block" />
            </div>

            {{-- Login Card --}}
            <div class="p-8">
                {{-- bg-white dark:bg-slate-800 rounded-2xl shadow-xl --}}
                {{-- Header --}}
                <div class="text-center mb-8">
                    <div
                        class="inline-flex items-center justify-center w-16 h-16 bg-primary-100 dark:bg-primary-900 rounded-full mb-4">
                        <svg class="w-8 h-8 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Admin Login</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                        Sign in to access the admin dashboard
                    </p>
                </div>

                {{-- Login Form --}}
                <form wire:submit.prevent="login" class="space-y-6">

                    <!-- Error Messages -->
                    @if ($errors->any())
                        <div class="rounded-lg bg-red-50 border border-red-200 p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    @foreach ($errors->all() as $error)
                                        <p class="text-sm text-red-800">{{ $error }}</p>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Email Input -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                            Email address
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                                </svg>
                            </div>
                            <input wire:model.defer="email" id="email" type="email" required autofocus
                                autocomplete="email"
                                class="block text-sm w-full pl-10 pr-3 py-2 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                placeholder="admin@bethanyhouse.co.ke">
                        </div>
                    </div>

                    <!-- Password Input -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            Password
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                            <input wire:model.defer="password" id="password" type="password" required
                                autocomplete="current-password"
                                class="block text-sm w-full pl-10 pr-3 py-2 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                placeholder="••••••••">
                        </div>
                    </div>

                    <!-- Remember Me & Forgot Password -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input wire:model.defer="remember" id="remember" type="checkbox"
                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label for="remember" class="ml-2 block text-sm text-gray-900">
                                Remember me for 7 days
                            </label>
                        </div>

                        <div class="text-sm">
                            <a href="{{ route('admin.password.request') }}"
                                class="font-medium text-blue-600 hover:text-blue-500 transition">
                                Forgot password?
                            </a>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div>
                        <button type="submit" wire:loading.attr="disabled"
                            class="w-full flex justify-center items-center py-3 px-4 border border-transparent rounded-full shadow-sm text-sm font-medium text-white hover:text-primary bg-primary hover:bg-secondary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <span wire:loading.remove wire:target="login">                                
                                Sign In
                                <svg class="w-5 h-5 mr-2 inline rotate-180" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                                </svg>
                            </span>
                            <span wire:loading wire:target="login" class="flex items-center">
                                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 inline text-white"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                                Signing in...
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Support Link --}}
            {{-- <div class="mt-6 text-center">
                <p class="text-xs text-gray-600 dark:text-gray-400">
                    Need help? Contact
                    <a href="mailto:support@bethanyhouse.co.ke"
                        class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300 transition">
                        support@bethanyhouse.co.ke
                    </a>
                </p>
            </div> --}}
        </div>
    </div>

</div>
