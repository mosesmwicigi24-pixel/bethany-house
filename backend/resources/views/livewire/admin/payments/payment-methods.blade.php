<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-credit-card"></i><span>Payments</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Payment Methods</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Payment Methods</h1>
            <p class="mt-0.5 text-sm text-primary-300">Configure which payment options appear at checkout. Drag to reorder.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> Add Method
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Methods list --}}
    <div class="bg-white rounded-2xl border border-primary-100 overflow-hidden shadow-sm divide-y divide-primary-50">
        @forelse($methods as $method)
            @php $icon = match(strtolower($method->code ?? '')) {
                'mpesa','m-pesa' => ['bi-phone','text-success-500','bg-success-50 border-success-200'],
                'paystack'       => ['bi-credit-card-2-front','text-info-500','bg-info-50 border-info-200'],
                'flutterwave'    => ['bi-lightning-charge','text-warning-500','bg-warning-50 border-warning-200'],
                'cash'           => ['bi-cash','text-primary-500','bg-primary-50 border-primary-200'],
                'card','stripe'  => ['bi-credit-card','text-secondary-600','bg-secondary-50 border-secondary-200'],
                default          => ['bi-wallet2','text-primary-400','bg-primary-50 border-primary-100'],
            }; @endphp
            <div class="flex items-center gap-4 px-5 py-4 hover:bg-primary-50/30 transition-colors group">
                {{-- Sort controls --}}
                <div class="flex flex-col gap-0.5 flex-shrink-0">
                    <button wire:click="moveUp({{ $method->id }})" class="w-5 h-5 flex items-center justify-center rounded text-primary-200 hover:text-primary-500 transition">
                        <i class="bi bi-chevron-up text-xs"></i>
                    </button>
                    <button wire:click="moveDown({{ $method->id }})" class="w-5 h-5 flex items-center justify-center rounded text-primary-200 hover:text-primary-500 transition">
                        <i class="bi bi-chevron-down text-xs"></i>
                    </button>
                </div>
                {{-- Icon --}}
                <div class="w-11 h-11 rounded-xl border {{ $icon[2] }} flex items-center justify-center flex-shrink-0">
                    <i class="bi {{ $icon[0] }} {{ $icon[1] }} text-xl"></i>
                </div>
                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <p class="font-bold text-primary-600 text-sm">{{ $method->name }}</p>
                        <code class="font-mono text-[10px] text-primary-300 bg-primary-50 border border-primary-100 px-1.5 py-0.5 rounded">{{ $method->code }}</code>
                        @if($method->provider)
                            <span class="text-[10px] font-semibold text-primary-300 uppercase tracking-wide">{{ $method->provider }}</span>
                        @endif
                    </div>
                    @if($method->description)
                        <p class="text-xs text-primary-300 mt-0.5 truncate">{{ $method->description }}</p>
                    @endif
                    @if($method->supported_currencies)
                        <div class="flex items-center gap-1 mt-1.5 flex-wrap">
                            @foreach($method->supported_currencies as $cur)
                                <span class="inline-flex items-center rounded-full bg-primary-50 border border-primary-100 px-1.5 py-0.5 text-[10px] font-semibold text-primary-400">{{ $cur }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
                {{-- Actions --}}
                <div class="flex items-center gap-2 flex-shrink-0">
                    <button wire:click="toggleActive({{ $method->id }})"
                            class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition
                                   {{ $method->is_active ? 'bg-success-50 border-success-200 text-success-700 hover:bg-success-100' : 'bg-primary-50 border-primary-200 text-primary-400 hover:bg-primary-100' }}">
                        <i class="bi {{ $method->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} text-sm"></i>
                        {{ $method->is_active ? 'Active' : 'Inactive' }}
                    </button>
                    <button wire:click="openEdit({{ $method->id }})"
                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition" title="Edit">
                        <i class="bi bi-pencil text-sm"></i>
                    </button>
                    <button wire:click="confirmDelete({{ $method->id }})"
                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition" title="Delete">
                        <i class="bi bi-trash3 text-sm"></i>
                    </button>
                </div>
            </div>
        @empty
            <div class="py-16 text-center">
                <i class="bi bi-wallet2 text-4xl text-primary-100 block mb-3"></i>
                <p class="text-sm font-medium text-primary-300">No payment methods configured yet.</p>
                <button wire:click="openCreate" class="mt-3 text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Add the first method →</button>
            </div>
        @endforelse
    </div>

    {{-- Create / Edit Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal',false)"></div>
            <div class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Payment Method' : 'New Payment Method' }}</h2>
                    <button wire:click="$set('showModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="px-6 py-5 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Code <span class="text-danger-500">*</span></label>
                            <input wire:model="code" type="text" placeholder="e.g. mpesa"
                                   class="w-full border {{ $errors->has('code') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('code')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Name <span class="text-danger-500">*</span></label>
                            <input wire:model="name" type="text" placeholder="e.g. M-PESA"
                                   class="w-full border {{ $errors->has('name') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('name')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Description</label>
                        <input wire:model="description" type="text"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Provider</label>
                            <input wire:model="provider" type="text" placeholder="e.g. safaricom"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Sort Order</label>
                            <input wire:model="sortOrder" type="number" min="0"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Supported Currencies <span class="font-normal normal-case text-primary-200">(comma-separated, e.g. KES,USD)</span></label>
                        <input wire:model="currencies" type="text" placeholder="KES,USD,EUR"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                    </div>
                    <label class="flex items-center gap-2.5 cursor-pointer">
                        <input wire:model="isActive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                        <span class="text-sm font-medium text-primary-500">Active (show at checkout)</span>
                    </label>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update' : 'Create' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete Confirm --}}
    @if($showDeleteModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showDeleteModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showDeleteModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="px-6 py-6 text-center space-y-3">
                    <div class="w-14 h-14 rounded-full bg-danger-50 border border-danger-200 flex items-center justify-center mx-auto">
                        <i class="bi bi-trash3 text-danger-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Delete "{{ $deletingName }}"?</h2>
                    <p class="text-sm text-primary-400">This will remove the payment method from checkout. Existing transaction records will not be affected.</p>
                </div>
                <div class="flex items-center justify-center gap-3 px-6 pb-6">
                    <button wire:click="$set('showDeleteModal',false)" class="flex-1 rounded-xl border border-primary-100 bg-white px-4 py-2.5 text-sm font-semibold text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="delete" wire:loading.attr="disabled"
                            class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-danger-500 hover:bg-danger-600 px-4 py-2.5 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-danger-500/20">
                        <span wire:loading.remove wire:target="delete">Delete</span>
                        <span wire:loading wire:target="delete">Deleting…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>