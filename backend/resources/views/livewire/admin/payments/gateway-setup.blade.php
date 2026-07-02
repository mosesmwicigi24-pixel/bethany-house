<div class="space-y-6 max-w-3xl">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-credit-card"></i><span>Payments</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>{{ $gatewayName }} Setup</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">{{ $gatewayName }}</h1>
            <p class="mt-0.5 text-sm text-primary-300">Configure API credentials and integration settings for {{ $gatewayName }}.</p>
        </div>
        {{-- Active toggle --}}
        <button wire:click="toggleActive"
                class="inline-flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition
                       {{ $isActive ? 'bg-success-50 border-success-200 text-success-700 hover:bg-success-100' : 'bg-primary-50 border-primary-200 text-primary-400 hover:bg-primary-100' }}">
            <i class="bi {{ $isActive ? 'bi-toggle-on text-success-500' : 'bi-toggle-off text-primary-300' }} text-lg"></i>
            {{ $isActive ? 'Enabled' : 'Disabled' }}
        </button>
    </div>

    {{-- Flash --}}
    @if($flashMessage)
        <div class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium border
                    {{ $flashType === 'error' ? 'bg-danger-50 text-danger-700 border-danger-200' : 'bg-success-50 text-success-700 border-success-200' }}">
            <i class="bi {{ $flashType === 'error' ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill' }} flex-shrink-0"></i>
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Validation errors --}}
    @if($errors->any())
        <div class="flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
            <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Test result --}}
    @if($testResult)
        <div class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm border
                    {{ $testStatus === 'success' ? 'bg-success-50 border-success-200 text-success-700' : 'bg-danger-50 border-danger-200 text-danger-700' }}">
            <i class="bi {{ $testStatus === 'success' ? 'bi-patch-check-fill' : 'bi-exclamation-octagon-fill' }} flex-shrink-0"></i>
            {{ $testResult }}
        </div>
    @endif

    {{-- ── M-PESA specific fields ── --}}
    @if($gatewayCode === 'mpesa')
        {{-- Environment --}}
        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-bold text-primary-500">Environment</p>
                <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
                    @foreach(['sandbox' => 'Sandbox', 'production' => 'Production'] as $v => $l)
                        <button wire:click="$set('config.environment','{{ $v }}')"
                                class="px-4 py-2 font-semibold transition border-l first:border-l-0 border-primary-100
                                       {{ ($config['environment'] ?? '') === $v ? 'bg-success-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
                    @endforeach
                </div>
            </div>
            @if(($config['environment'] ?? '') === 'production')
                <div class="flex items-center gap-2 rounded-xl bg-warning-50 border border-warning-200 px-4 py-3 text-sm text-warning-700">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                    You are configuring <strong>production</strong> credentials. Real payments will be processed.
                </div>
            @endif
        </div>

        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-bold text-primary-500">Daraja API Credentials</p>
                <button wire:click="$toggle('showSecrets')" class="text-xs text-primary-400 hover:text-primary-600 font-semibold transition flex items-center gap-1">
                    <i class="bi {{ $showSecrets ? 'bi-eye-slash' : 'bi-eye' }} text-sm"></i>
                    {{ $showSecrets ? 'Hide' : 'Show' }} secrets
                </button>
            </div>
            @foreach([
                ['consumer_key',    'Consumer Key',       'text',     'From Daraja developer portal'],
                ['consumer_secret', 'Consumer Secret',    'password', 'Keep this secret'],
                ['shortcode',       'Shortcode / Paybill','text',     'e.g. 174379'],
                ['passkey',         'Passkey',            'password', 'From Daraja portal'],
            ] as [$key, $label, $type, $hint])
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                        {{ $label }} <span class="text-danger-500">*</span>
                    </label>
                    <input wire:model="config.{{ $key }}" type="{{ ($type === 'password' && !$showSecrets) ? 'password' : 'text' }}"
                           placeholder="{{ $hint }}"
                           class="w-full border {{ $errors->has("config.{$key}") ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 font-mono placeholder:font-sans placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    @error("config.{$key}")<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <p class="text-sm font-bold text-primary-500">Integration Settings</p>
            @foreach([
                ['callback_url',      'Callback URL',          'text', 'https://yoursite.com/api/mpesa/callback'],
                ['account_reference', 'Account Reference',     'text', 'BethanyHouse'],
                ['transaction_desc',  'Transaction Description','text', 'Payment'],
            ] as [$key, $label, $type, $ph])
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">{{ $label }}</label>
                    <input wire:model="config.{{ $key }}" type="{{ $type }}" placeholder="{{ $ph }}"
                           class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── Paystack specific fields ── --}}
    @if($gatewayCode === 'paystack')
        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-bold text-primary-500">Environment</p>
                <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
                    @foreach(['test' => 'Test', 'live' => 'Live'] as $v => $l)
                        <button wire:click="$set('config.environment','{{ $v }}')"
                                class="px-4 py-2 font-semibold transition border-l first:border-l-0 border-primary-100
                                       {{ ($config['environment'] ?? '') === $v ? 'bg-info-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-bold text-primary-500">API Keys</p>
                <button wire:click="$toggle('showSecrets')" class="text-xs text-primary-400 hover:text-primary-600 font-semibold transition flex items-center gap-1">
                    <i class="bi {{ $showSecrets ? 'bi-eye-slash' : 'bi-eye' }} text-sm"></i>
                    {{ $showSecrets ? 'Hide' : 'Show' }} secrets
                </button>
            </div>
            @foreach([
                ['public_key',     'Public Key',     'text',     'pk_test_…'],
                ['secret_key',     'Secret Key',     'password', 'sk_test_…'],
                ['webhook_secret', 'Webhook Secret', 'password', 'For verifying webhook signatures'],
            ] as [$key, $label, $type, $ph])
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                        {{ $label }} @if(in_array($key,['public_key','secret_key']))<span class="text-danger-500">*</span>@endif
                    </label>
                    <input wire:model="config.{{ $key }}" type="{{ ($type === 'password' && !$showSecrets) ? 'password' : 'text' }}"
                           placeholder="{{ $ph }}"
                           class="w-full border {{ $errors->has("config.{$key}") ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 font-mono placeholder:font-sans placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    @error("config.{$key}")<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <p class="text-sm font-bold text-primary-500">Integration Settings</p>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Callback URL</label>
                <input wire:model="config.callback_url" type="text" placeholder="https://yoursite.com/api/paystack/callback"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Supported Channels</label>
                <input wire:model="config.supported_channels" type="text" placeholder="card,bank,ussd,qr,mobile_money"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                <p class="text-xs text-primary-300 mt-1">Comma-separated list of Paystack channels to enable.</p>
            </div>
        </div>
    @endif

    {{-- ── Flutterwave specific fields ── --}}
    @if($gatewayCode === 'flutterwave')
        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-bold text-primary-500">Environment</p>
                <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
                    @foreach(['staging' => 'Staging', 'production' => 'Production'] as $v => $l)
                        <button wire:click="$set('config.environment','{{ $v }}')"
                                class="px-4 py-2 font-semibold transition border-l first:border-l-0 border-primary-100
                                       {{ ($config['environment'] ?? '') === $v ? 'bg-warning-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-bold text-primary-500">API Keys</p>
                <button wire:click="$toggle('showSecrets')" class="text-xs text-primary-400 hover:text-primary-600 font-semibold transition flex items-center gap-1">
                    <i class="bi {{ $showSecrets ? 'bi-eye-slash' : 'bi-eye' }} text-sm"></i>
                    {{ $showSecrets ? 'Hide' : 'Show' }} secrets
                </button>
            </div>
            @foreach([
                ['public_key',     'Public Key',     'text',     'FLWPUBK_TEST-…'],
                ['secret_key',     'Secret Key',     'password', 'FLWSECK_TEST-…'],
                ['encryption_key', 'Encryption Key', 'password', '12-character key from dashboard'],
                ['webhook_secret', 'Webhook Secret', 'password', 'For verifying webhooks'],
            ] as [$key, $label, $type, $ph])
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                        {{ $label }} @if(in_array($key,['public_key','secret_key','encryption_key']))<span class="text-danger-500">*</span>@endif
                    </label>
                    <input wire:model="config.{{ $key }}" type="{{ ($type === 'password' && !$showSecrets) ? 'password' : 'text' }}"
                           placeholder="{{ $ph }}"
                           class="w-full border {{ $errors->has("config.{$key}") ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 font-mono placeholder:font-sans placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    @error("config.{$key}")<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl border border-primary-100 shadow-sm p-6 space-y-5">
            <p class="text-sm font-bold text-primary-500">Checkout Settings</p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Company Name</label>
                    <input wire:model="config.company_name" type="text" placeholder="Bethany House"
                           class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Logo URL</label>
                    <input wire:model="config.logo_url" type="text" placeholder="https://yoursite.com/logo.png"
                           class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Callback URL</label>
                <input wire:model="config.callback_url" type="text" placeholder="https://yoursite.com/api/flutterwave/callback"
                       class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
            </div>
        </div>
    @endif

    {{-- Action buttons --}}
    <div class="flex items-center gap-3">
        <button wire:click="testConnection" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-xl border border-primary-200 bg-white hover:bg-primary-50 px-4 py-2.5 text-sm font-semibold text-primary-500 transition disabled:opacity-60">
            <span wire:loading.remove wire:target="testConnection"><i class="bi bi-wifi mr-1"></i>Test Connection</span>
            <span wire:loading wire:target="testConnection"><i class="bi bi-arrow-clockwise animate-spin mr-1"></i>Testing…</span>
        </button>
        <button wire:click="save" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
            <span wire:loading.remove wire:target="save"><i class="bi bi-floppy mr-1"></i>Save Configuration</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>

</div>