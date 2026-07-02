{{-- Two-Factor Authentication Verification Page --}}
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
            <div>
                <a href="/" class="inline-block">
                    <img src="{{ asset('images/logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-12" />
                </a>
            </div>

            {{-- Center Content --}}
            <div class="space-y-6 text-white">
                <div class="space-y-4">
                    <h2 class="text-4xl font-bold leading-tight">
                        Bethany House Admin
                    </h2>
                    <p class="text-base text-primary-100 leading-relaxed">
                        Manage your e-commerce platform, production, inventory,<br> and sales all in one place.
                    </p>
                </div>

            </div>

            {{-- Footer Info --}}
            <div class="text-sm text-primary-100">
                <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            </div>
        </div>
    </div>

    {{-- Right Side - 2FA Form --}}
    <div class="flex flex-1 items-center justify-center p-6 bg-gray-50 dark:bg-slate-900">
        <div class="w-full max-w-md">
            
            {{-- Mobile Logo --}}
            <div class="lg:hidden mb-8 text-center">
                <img src="{{ asset('images/logo.svg') }}" alt="{{ config('app.name') }}" class="h-12 mx-auto dark:hidden" />
                <img src="{{ asset('images/logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-12 mx-auto hidden dark:block" />
            </div>

            {{-- 2FA Card --}}
            <div class="p-8">
                {{-- Header --}}
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-primary-100 dark:bg-primary-900 rounded-full mb-4">
                        <svg class="w-8 h-8 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Two-Factor Authentication</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                        Enter the 6-digit code from your authenticator app
                    </p>
                </div>

                {{-- 2FA Form --}}
                <form wire:submit.prevent="verify" class="space-y-6">
                    
                    {{-- Error Messages --}}
                    @if ($errors->any())
                        <div class="rounded-lg bg-danger-50 dark:bg-danger-900 dark:bg-opacity-20 border border-danger-200 dark:border-danger-800 p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-danger-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    @foreach ($errors->all() as $error)
                                        <p class="text-sm text-danger-800 dark:text-danger-200">{{ $error }}</p>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Success Message (if code sent to email) --}}
                    @if (session()->has('success'))
                        <div class="rounded-lg bg-success-50 dark:bg-success-900 dark:bg-opacity-20 border border-success-200 dark:border-success-800 p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-success-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-success-800 dark:text-success-200">{{ session('success') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- 2FA Code Input --}}
                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Authentication Code
                        </label>
                        <input 
                            wire:model.defer="code" 
                            id="code" 
                            type="text" 
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="6"
                            required
                            autofocus
                            autocomplete="one-time-code"
                            class="block w-full px-4 py-4 text-center text-2xl font-mono tracking-widest border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition"
                            placeholder="000000"
                        >
                        @error('code')
                            <p class="text-danger-600 dark:text-danger-400 text-sm mt-2">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 text-center">
                            Enter the 6-digit code from Google Authenticator or similar app
                        </p>
                    </div>

                    {{-- Submit Button --}}
                    <div>
                        <button 
                            type="submit"
                            wire:loading.attr="disabled"
                            class="w-full flex justify-center items-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span wire:loading.remove wire:target="verify" class="flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Verify & Continue
                            </span>
                            <span wire:loading wire:target="verify" class="flex items-center">
                                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Verifying...
                            </span>
                        </button>
                    </div>

                    {{-- Divider --}}
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300 dark:border-slate-600"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-white dark:bg-slate-800 text-gray-500 dark:text-gray-400">
                                Or use recovery code
                            </span>
                        </div>
                    </div>

                    {{-- Recovery Code Toggle --}}
                    <div x-data="{ useRecovery: false }">
                        <button 
                            type="button"
                            @click="useRecovery = !useRecovery"
                            class="w-full text-center text-sm text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300 font-medium transition"
                        >
                            <span x-show="!useRecovery">Use a recovery code instead</span>
                            <span x-show="useRecovery">Use authenticator code</span>
                        </button>

                        {{-- Recovery Code Input --}}
                        <div x-show="useRecovery" x-transition class="mt-4">
                            <label for="recovery_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Recovery Code
                            </label>
                            <input 
                                wire:model.defer="recovery_code" 
                                id="recovery_code" 
                                type="text" 
                                class="block w-full px-4 py-3 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition"
                                placeholder="Enter your recovery code"
                            >
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                Enter one of your recovery codes provided during 2FA setup
                            </p>
                        </div>
                    </div>
                </form>
            </div>

            {{-- Help Text --}}
            {{-- <div class="mt-6 bg-white dark:bg-slate-800 bg-opacity-50 dark:bg-opacity-50 backdrop-blur-sm rounded-lg p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-info-500" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            <strong class="font-semibold">Tip:</strong> Lost access to your authenticator app? Use a recovery code to sign in, then set up 2FA again.
                        </p>
                    </div>
                </div>
            </div> --}}

            {{-- Back to Login --}}
            <div class="mt-6 text-center">
                <a href="{{ route('admin.login') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition inline-flex items-center">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to Login
                </a>
            </div>

            {{-- Support Link --}}
            <div class="mt-4 text-center">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Need help? Contact 
                    <a href="mailto:support@bethanyhouse.co.ke" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300 transition">
                        support@bethanyhouse.co.ke
                    </a>
                </p>
            </div>
        </div>
    </div>

</div>