<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
        <!-- Header -->
        <div class="text-center">
            <div class="mx-auto h-16 w-16 bg-blue-600 rounded-full flex items-center justify-center mb-4">
                <svg class="h-10 w-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-white">Forgot Password?</h2>
            <p class="mt-2 text-sm text-gray-400">
                No worries, we'll send you reset instructions
            </p>
        </div>

        <!-- Form Card -->
        <div class="bg-white rounded-xl shadow-2xl p-8">
            @if($emailSent)
                <!-- Success Message -->
                <div class="text-center space-y-4">
                    <div class="mx-auto h-16 w-16 bg-green-100 rounded-full flex items-center justify-center">
                        <svg class="h-10 w-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 19v-8.93a2 2 0 01.89-1.664l7-4.666a2 2 0 012.22 0l7 4.666A2 2 0 0121 10.07V19M3 19a2 2 0 002 2h14a2 2 0 002-2M3 19l6.75-4.5M21 19l-6.75-4.5M3 10l6.75 4.5M21 10l-6.75 4.5m0 0l-1.14.76a2 2 0 01-2.22 0l-1.14-.76"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">Check your email</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            We've sent a password reset link to<br>
                            <strong>{{ $email }}</strong>
                        </p>
                    </div>
                    <div class="bg-blue-50 rounded-lg p-4">
                        <p class="text-xs text-blue-800">
                            Didn't receive the email? Check your spam folder or 
                            <button wire:click="$set('emailSent', false)" class="font-medium underline">
                                try again
                            </button>
                        </p>
                    </div>
                </div>
            @else
                <!-- Reset Form -->
                <form wire:submit.prevent="sendResetLink" class="space-y-6">
                    
                    <!-- Error Messages -->
                    @if ($errors->any())
                        <div class="rounded-lg bg-red-50 border border-red-200 p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
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
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/>
                                </svg>
                            </div>
                            <input 
                                wire:model.defer="email" 
                                id="email" 
                                type="email" 
                                required
                                autofocus
                                autocomplete="email"
                                class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                                placeholder="admin@bethanyhouse.co.ke"
                            >
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div>
                        <button 
                            type="submit"
                            wire:loading.attr="disabled"
                            class="w-full flex justify-center items-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="sendResetLink">
                                Send Reset Link
                            </span>
                            <span wire:loading wire:target="sendResetLink" class="flex items-center">
                                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Sending...
                            </span>
                        </button>
                    </div>
                </form>
            @endif
        </div>

        <!-- Back to Login -->
        <div class="text-center">
            <a href="{{ route('admin.login') }}" class="text-sm text-gray-400 hover:text-gray-300 transition inline-flex items-center">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to login
            </a>
        </div>
    </div>
</div>