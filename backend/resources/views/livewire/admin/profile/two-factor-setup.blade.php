<div class="min-h-screen flex flex-col lg:flex-row w-full">

    {{-- Left Side - Branding --}}
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

    {{-- Right Side - Setup Form --}}
    <div class="flex flex-1 items-center justify-center p-6 bg-gray-50 dark:bg-slate-900">
        <div class="w-full max-w-lg">

            {{-- Mobile Logo --}}
            <div class="lg:hidden mb-8 text-center">
                <img src="{{ asset('images/logo.svg') }}" alt="{{ config('app.name') }}"
                    class="h-12 mx-auto dark:hidden" />
                <img src="{{ asset('images/logo-light.svg') }}" alt="{{ config('app.name') }}"
                    class="h-12 mx-auto hidden dark:block" />
            </div>

            {{-- Setup Card --}}
            <div class="p-8">

                {{-- Progress Steps --}}
                <div class="mb-8">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center {{ $step >= 1 ? 'text-primary-600' : 'text-gray-400' }}">
                            <div
                                class="w-10 h-10 rounded-full {{ $step >= 1 ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-600' }} flex items-center justify-center font-bold">
                                1
                            </div>
                            <span class="ml-2 text-sm font-medium hidden sm:inline">Scan QR</span>
                        </div>
                        <div class="flex-1 h-1 mx-4 {{ $step >= 2 ? 'bg-primary-600' : 'bg-gray-200' }}"></div>
                        <div class="flex items-center {{ $step >= 2 ? 'text-primary-600' : 'text-gray-400' }}">
                            <div
                                class="w-10 h-10 rounded-full {{ $step >= 2 ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-600' }} flex items-center justify-center font-bold">
                                2
                            </div>
                            <span class="ml-2 text-sm font-medium hidden sm:inline">Verify</span>
                        </div>
                        <div class="flex-1 h-1 mx-4 {{ $step >= 3 ? 'bg-primary-600' : 'bg-gray-200' }}"></div>
                        <div class="flex items-center {{ $step >= 3 ? 'text-primary-600' : 'text-gray-400' }}">
                            <div
                                class="w-10 h-10 rounded-full {{ $step >= 3 ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-600' }} flex items-center justify-center font-bold">
                                3
                            </div>
                            <span class="ml-2 text-sm font-medium hidden sm:inline">Save Codes</span>
                        </div>
                    </div>
                </div>

                {{-- Step 1: Scan QR Code --}}
                @if ($step === 1)
                    <div class="text-center pt-6">
                        {{-- <div
                            class="inline-flex items-center justify-center w-16 h-16 bg-primary-100 dark:bg-primary-900 rounded-full mb-4">
                            <svg class="w-8 h-8 text-primary-600 dark:text-primary-400" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                            </svg>
                        </div> --}}
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Set Up Two-Factor
                            Authentication</h1>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                            Use Google Authenticator, Authy, or any TOTP app
                        </p>

                        {{-- Flash Messages --}}
                        @if (session()->has('info'))
                            <div
                                class="mb-4 rounded-lg bg-info-50 dark:bg-info-900/20 border border-info-200 dark:border-info-800 p-4">
                                <p class="text-sm text-info-800 dark:text-info-200">{{ session('info') }}</p>
                            </div>
                        @endif

                        {{-- QR Code as SVG (using computed property) --}}
                        <div
                            class="bg-white p-6 rounded-lg border-2 border-gray-200 dark:border-slate-600 inline-block mb-6">
                            <div class="w-[150px] h-[150px] flex items-center justify-center">
                                {{-- Use $this->qrCodeSvg which is a computed property --}}
                                {!! $this->qrCodeSvg !!}
                            </div>
                        </div>

                        {{-- Secret Key (Manual Entry) --}}
                        <div class="mb-6">
                            <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">Can't scan? Enter this code
                                manually:</p>
                            <div class="relative">
                                <div class="bg-gray-100 dark:bg-slate-700 rounded-full p-3 font-mono text-sm break-all select-all cursor-pointer"
                                    onclick="navigator.clipboard.writeText('{{ $secret }}'); 
                              this.querySelector('.copy-feedback').classList.remove('hidden'); 
                              setTimeout(() => this.querySelector('.copy-feedback').classList.add('hidden'), 2000)">
                                    {{ $secret }}
                                    <span
                                        class="copy-feedback hidden absolute top-0 right-0 bg-success-500 text-white text-xs px-2 py-1 rounded">
                                        Copied!
                                    </span>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                Account: {{ auth()->user()->email }}
                            </p>
                        </div>

                        {{-- Action Buttons --}}
                        <div class="space-x-3 flex">
                            <button wire:click="$set('step', 2)"
                                class="w-full bg-primary text-white text-sm px-4 py-2 rounded-full hover:bg-primary-700 transition cursor-pointer leading-tight tracking-tight">
                                <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                I've Scanned the QR Code
                            </button>

                            <button wire:click="regenerateSecret"
                                class="w-full bg-secondary text-sm text-primary px-4 py-2 rounded-full hover:bg-secondary-400 transition cursor-pointer leading-tight tracking-tight">
                                <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Generate New QR Code
                            </button>
                        </div>

                        {{-- Help Text --}}
                        {{-- <div class="mt-6 text-left bg-gray-50 dark:bg-slate-700 rounded-lg p-4">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">
                                📱 Recommended Apps:
                            </h3>
                            <ul class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <li>• Google Authenticator (iOS/Android)</li>
                                <li>• Authy (iOS/Android/Desktop)</li>
                                <li>• Microsoft Authenticator (iOS/Android)</li>
                                <li>• 1Password (iOS/Android/Desktop)</li>
                            </ul>
                        </div> --}}
                    </div>
                @endif

                {{-- Step 2: Verify Code --}}
                {{-- Step 2: Verify Code --}}
                {{-- Step 2: Verify Code --}}
                @if ($step === 2)
                    <div wire:key="verify-step">
                        <div class="text-center mb-6 pt-6">
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Verify Code</h1>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Enter the 6-digit code from your authenticator app
                            </p>
                        </div>

                        <form wire:submit.prevent="verifyCode" class="space-y-6">
                            @if ($errors->any())
                                <div
                                    class="rounded-lg bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-800 p-4">
                                    <div class="flex">
                                        <svg class="h-5 w-5 text-danger-400 flex-shrink-0" fill="currentColor"
                                            viewBox="0 0 20 20">
                                            <path fill-rule="evenodd"
                                                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                                clip-rule="evenodd" />
                                        </svg>
                                        <div class="ml-3">
                                            @foreach ($errors->all() as $error)
                                                <p class="text-sm text-danger-800 dark:text-danger-200">
                                                    {{ $error }}</p>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Hidden input that Livewire binds to --}}
                            <input type="hidden" wire:model.live="confirmationCode" id="otp-hidden-input">

                            {{-- OTP Digit Boxes with Alpine.js --}}
                            <div x-data="{
                                digits: ['', '', '', '', '', ''],
                            
                                init() {
                                    this.$nextTick(() => {
                                        if (this.$refs.digit0) {
                                            this.$refs.digit0.focus();
                                        }
                                    });
                                },
                            
                                onInput(index, event) {
                                    const value = event.target.value;
                                    const digit = value.replace(/\D/g, '').slice(-1);
                            
                                    this.digits[index] = digit;
                                    event.target.value = digit;
                            
                                    this.syncToLivewire();
                            
                                    // Auto-advance to next input
                                    if (digit && index < 5) {
                                        const nextInput = this.$refs['digit' + (index + 1)];
                                        if (nextInput) nextInput.focus();
                                    }
                                },
                            
                                onKeydown(index, event) {
                                    // Handle backspace
                                    if (event.key === 'Backspace') {
                                        if (this.digits[index]) {
                                            this.digits[index] = '';
                                            event.target.value = '';
                                            this.syncToLivewire();
                                        } else if (index > 0) {
                                            this.digits[index - 1] = '';
                                            const prevInput = this.$refs['digit' + (index - 1)];
                                            if (prevInput) {
                                                prevInput.value = '';
                                                prevInput.focus();
                                            }
                                            this.syncToLivewire();
                                        }
                                        event.preventDefault();
                                    }
                            
                                    // Handle arrow keys
                                    if (event.key === 'ArrowLeft' && index > 0) {
                                        this.$refs['digit' + (index - 1)].focus();
                                    }
                                    if (event.key === 'ArrowRight' && index < 5) {
                                        this.$refs['digit' + (index + 1)].focus();
                                    }
                            
                                    // Submit on Enter
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        if (this.digits.join('').length === 6) {
                                            this.$el.closest('form').requestSubmit();
                                        }
                                    }
                                },
                            
                                onPaste(event) {
                                    event.preventDefault();
                                    const pasted = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
                            
                                    pasted.split('').forEach((char, i) => {
                                        if (i < 6) {
                                            this.digits[i] = char;
                                            const input = this.$refs['digit' + i];
                                            if (input) input.value = char;
                                        }
                                    });
                            
                                    // Focus last filled input
                                    const focusIndex = Math.min(pasted.length, 5);
                                    const input = this.$refs['digit' + focusIndex];
                                    if (input) input.focus();
                            
                                    this.syncToLivewire();
                                },
                            
                                syncToLivewire() {
                                    const code = this.digits.join('');
                                    @this.set('confirmationCode', code);
                                },
                            
                                clear() {
                                    this.digits = ['', '', '', '', '', ''];
                                    for (let i = 0; i < 6; i++) {
                                        const input = this.$refs['digit' + i];
                                        if (input) input.value = '';
                                    }
                                    this.syncToLivewire();
                                    if (this.$refs.digit0) this.$refs.digit0.focus();
                                }
                            }" class="flex justify-center gap-3"
                                @paste.prevent="onPaste($event)" wire:ignore>
                                @for ($i = 0; $i < 6; $i++)
                                    <input x-ref="digit{{ $i }}" type="text" inputmode="numeric"
                                        maxlength="1" autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                                        @input="onInput({{ $i }}, $event)"
                                        @keydown="onKeydown({{ $i }}, $event)"
                                        @focus="$event.target.select()"
                                        class="w-12 h-14 text-center text-2xl font-mono font-bold border-2 rounded-xl 
                               bg-white dark:bg-slate-700 
                               text-gray-900 dark:text-white 
                               border-gray-300 dark:border-slate-600 
                               focus:border-primary-500 dark:focus:border-primary-400 
                               focus:ring-2 focus:ring-primary-500/20 dark:focus:ring-primary-400/20 
                               focus:outline-none
                               transition-all duration-150
                               placeholder-gray-300 dark:placeholder-slate-600"
                                        placeholder="·">
                                    @if ($i === 2)
                                        <span
                                            class="flex items-center text-gray-300 dark:text-slate-600 text-2xl font-light select-none">-</span>
                                    @endif
                                @endfor
                            </div>

                            {{-- Helper text --}}
                            <p class="text-center text-xs text-gray-500 dark:text-gray-400">
                                Enter the 6-digit code or press Enter to submit
                            </p>

                            <div class="flex gap-3 pt-4">
                                <button type="button" wire:click="$set('step', 1)"
                                    class="flex-1 bg-secondary text-sm text-primary px-4 py-3 rounded-full hover:bg-secondary-400 transition cursor-pointer">
                                    Back
                                </button>
                                <button type="submit" wire:loading.attr="disabled"
                                    class="flex-1 bg-primary text-white text-sm px-4 py-3 rounded-full hover:bg-primary-700 transition disabled:opacity-50 cursor-pointer">
                                    <span wire:loading.remove wire:target="verifyCode">Verify Code</span>
                                    <span wire:loading wire:target="verifyCode"
                                        class="flex items-center justify-center gap-2">
                                        <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg"
                                            fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10"
                                                stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                            </path>
                                        </svg>
                                        Verifying...
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                @endif

                {{-- Step 3: Recovery Codes --}}
                @if ($step === 3)
                    <div>
                        <div class="text-center mb-6 pt-6">
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Save Recovery Codes</h1>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Store these codes in a safe place. You can use them to access your account if you lose
                                your device.
                            </p>
                        </div>

                        <div
                            class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mb-6">
                            <div class="flex">
                                <svg class="h-5 w-5 text-yellow-400 flex-shrink-0" fill="currentColor"
                                    viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                        clip-rule="evenodd" />
                                </svg>
                                <div class="ml-3">
                                    <p class="text-sm text-yellow-800 dark:text-yellow-200">
                                        <strong class="font-semibold">Important:</strong> Each code can only be used
                                        once. Save them now!
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Recovery Codes Grid - Styled for 6-digit codes --}}
                        <div class="bg-gray-100 dark:bg-slate-700 rounded-lg p-6 mb-6">
                            <div class="grid grid-cols-2 gap-4">
                                @foreach ($recoveryCodes as $index => $code)
                                    <div
                                        class="flex items-center gap-3 bg-white dark:bg-slate-800 px-4 py-3 rounded-lg border border-gray-200 dark:border-slate-600 hover:border-primary-300 dark:hover:border-primary-600 transition group">
                                        {{-- Code number --}}
                                        <span
                                            class="text-xs font-medium text-gray-500 dark:text-gray-400 w-5">{{ $index + 1 }}.</span>

                                        {{-- The 6-digit code in monospace --}}
                                        <span
                                            class="font-mono text-lg font-bold text-gray-900 dark:text-white tracking-wider flex-1 text-center select-all">
                                            {{ $code }}
                                        </span>

                                        {{-- Copy button for individual code --}}
                                        <button type="button"
                                            onclick="navigator.clipboard.writeText('{{ $code }}'); this.querySelector('svg').classList.add('text-success-500'); setTimeout(() => this.querySelector('svg').classList.remove('text-success-500'), 1000)"
                                            class="opacity-0 group-hover:opacity-100 transition" title="Copy code">
                                            <svg class="w-4 h-4 text-gray-400 hover:text-primary-500 transition"
                                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                            </svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Action Buttons --}}
                        <div class="flex gap-3 mb-6">
                            <button type="button"
                                onclick="navigator.clipboard.writeText('{{ implode('\n', $recoveryCodes) }}'); 
                         this.querySelector('.copy-text').textContent = 'Copied!'; 
                         setTimeout(() => this.querySelector('.copy-text').textContent = 'Copy All Codes', 1500)"
                                class="flex-1 border-1 text-sm border-primary-600 text-primary-600 hover:bg-primary-100 dark:hover:bg-primary-900/20 px-3 py-2 rounded-full font-medium transition cursor-pointer">
                                <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
                                </svg>
                                <span class="copy-text">Copy All Codes</span>
                            </button>

                            <button type="button" wire:click="downloadRecoveryCodes"
                                class="flex-1 text-sm border-1 border-primary-600 hover:bg-primary-100 dark:border-slate-600 text-primary-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700 px-3 py-2 rounded-full font-medium transition cursor-pointer">
                                <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                Download
                            </button>
                        </div>

                        {{-- Complete Setup Button --}}
                        <button wire:click="complete"
                            class="w-full bg-primary text-white px-6 py-3 rounded-full font-semibold hover:bg-secondary hover:text-primary transition shadow-lg shadow-primary-500/30 cursor-pointer">
                            <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            I've Saved My Recovery Codes - Proceed
                        </button>
                    </div>
                @endif

            </div>

            {{-- Logout Option --}}
            <div class="mt-6 text-center">
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit"
                        class="bg-transparent hover:bg-primary-100 border-primary-300 border text-sm text-primary px-6 py-2 rounded-full dark:text-gray-400 hover:text-primary dark:hover:text-danger-400 transition cursor-pointer">
                        <svg class="w-5 h-5 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                        </svg>
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
