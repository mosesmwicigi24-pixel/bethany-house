<div class="max-w-5xl mx-auto space-y-6 font-dm-sans" x-data="{ tab: @entangle('activeTab') }">

    {{-- Flash --}}
    @if($flashMessage)
    <div class="flex items-start gap-3 rounded-xl px-4 py-3 text-sm font-medium border
                {{ $flashType === 'error' ? 'bg-danger-50 text-danger-700 border-danger-200' : 'bg-success-50 text-success-700 border-success-200' }}">
        <i class="bi {{ $flashType === 'error' ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill' }} flex-shrink-0 mt-0.5"></i>
        {{ $flashMessage }}
    </div>
    @endif

    @if(session('success'))
    <div class="flex items-start gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
        <i class="bi bi-check-circle-fill flex-shrink-0 mt-0.5"></i>{{ session('success') }}
    </div>
    @endif

    {{-- Cross-tab validation errors --}}
    @if($errors->any())
    <div class="flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
        <div>
            <p class="font-semibold mb-1">Please fix the following before saving:</p>
            <ul class="space-y-0.5 list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-primary-500 tracking-tight">Edit Product</h1>
            <nav class="flex items-center gap-1.5 text-xs text-gray-400 mt-1">
                <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-600 transition-colors">Dashboard</a>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <a href="{{ route('admin.products.index') }}" class="hover:text-gray-600 transition-colors">Products</a>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span class="text-gray-600">{{ $name_en ?: $product->sku }}</span>
            </nav>
        </div>
        <div class="flex items-center gap-2">
            <span class="hidden sm:flex items-center gap-1.5 text-xs text-gray-400">
                <code class="font-mono bg-gray-100 px-1.5 py-0.5 rounded text-gray-500">{{ $product->sku }}</code>
                <span class="inline-flex items-center rounded-full px-2 py-0.5
                    {{ match($product->status) {
                        'active'   => 'bg-success-50 text-success-700 border border-success-200',
                        'draft'    => 'bg-gray-100 text-gray-500 border border-gray-200',
                        'archived' => 'bg-danger-50 text-danger-600 border border-danger-200',
                        default    => 'bg-gray-100 text-gray-400 border border-gray-100',
                    } }} font-semibold">
                    {{ ucfirst($product->status) }}
                </span>
            </span>
            <button wire:click="save()" wire:loading.attr="disabled"
                class="px-4 py-2.5 text-sm font-semibold text-gray-600 border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="save()">Save Changes</span>
                <span wire:loading wire:target="save()"><i class="bi bi-arrow-repeat animate-spin me-1"></i>Saving…</span>
            </button>
            @if($product->status !== 'active')
            <button wire:click="save('active')" wire:loading.attr="disabled"
                class="px-4 py-2.5 text-sm font-semibold bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors shadow-sm disabled:opacity-60">
                <span wire:loading.remove wire:target="save('active')">Publish</span>
                <span wire:loading wire:target="save('active')"><i class="bi bi-arrow-repeat animate-spin me-1"></i>Publishing…</span>
            </button>
            @else
            <button wire:click="save('draft')" wire:loading.attr="disabled"
                class="px-4 py-2.5 text-sm font-semibold bg-warning-500 hover:bg-warning-600 text-white rounded-xl transition-colors shadow-sm disabled:opacity-60">
                <span wire:loading.remove wire:target="save('draft')">Unpublish</span>
                <span wire:loading wire:target="save('draft')"><i class="bi bi-arrow-repeat animate-spin me-1"></i>Saving…</span>
            </button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── LEFT: Tabs ──────────────────────────────────────────────── --}}
        <div class="lg:col-span-2 space-y-5">

            <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 overflow-hidden">

                {{-- Tab strip --}}
                <div class="flex border-b border-gray-100 overflow-x-auto">
                    @foreach([
                        ['details',      'bi-box-seam',  'Details'],
                        ['translations', 'bi-translate', 'Translations'],
                        ['variants',     'bi-diagram-3', 'Variants'],
                        ['images',       'bi-images',    'Images'],
                        ['seo',          'bi-search',    'SEO'],
                    ] as [$key, $icon, $label])
                    <button wire:click="$set('activeTab', '{{ $key }}')"
                        :class="tab === '{{ $key }}' ? 'border-b-2 border-primary-500 text-primary-600' : 'border-b-2 border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-5 py-3.5 text-sm font-semibold whitespace-nowrap flex items-center gap-1.5 transition-colors -mb-px relative">
                        <i class="bi {{ $icon }} text-sm"></i>
                        {{ $label }}
                        @if($this->tabErrors[$key] ?? false)
                            <span class="absolute top-2 right-2 w-2 h-2 rounded-full bg-danger-500"></span>
                        @endif
                    </button>
                    @endforeach
                </div>

                {{-- ── TAB: Details ─────────────────────────────────────── --}}
                <div x-show="tab === 'details'" class="p-6 space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Product Name (EN) <span class="text-danger-500">*</span>
                            </label>
                            <input wire:model.live="name_en" type="text"
                                class="w-full border rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 focus:border-primary-400 transition bg-gray-50/30
                                       {{ $errors->has('name_en') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                            @error('name_en')<p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">SKU <span class="text-danger-500">*</span></label>
                            <input wire:model="sku" type="text"
                                class="w-full border rounded-xl px-3.5 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30
                                       {{ $errors->has('sku') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                            @error('sku')<p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Category <span class="text-danger-500">*</span></label>
                            <select wire:model="category_id"
                                class="w-full border rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30
                                       {{ $errors->has('category_id') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                                <option value="">Select category…</option>
                                @foreach($this->categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->translations->firstWhere('language_code', 'en')?->name ?? '-' }}</option>
                                    @foreach($cat->children ?? [] as $child)
                                        <option value="{{ $child->id }}">&nbsp;&nbsp;- {{ $child->translations->firstWhere('language_code', 'en')?->name ?? '-' }}</option>
                                        @foreach($child->children ?? [] as $grandchild)
                                            <option value="{{ $grandchild->id }}">&nbsp;&nbsp;&nbsp;&nbsp;- {{ $grandchild->translations->firstWhere('language_code', 'en')?->name ?? '-' }}</option>
                                        @endforeach
                                    @endforeach
                                @endforeach
                            </select>
                            @error('category_id')<p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Price (KES) <span class="text-danger-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-400">KES</span>
                                <input wire:model="price_kes" type="number" min="0" step="0.01"
                                    class="w-full border rounded-xl pl-11 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30
                                           {{ $errors->has('price_kes') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                            </div>
                            @error('price_kes')<p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Price (USD) <span class="text-danger-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-bold text-gray-400">$</span>
                                <input wire:model="price_usd" type="number" min="0" step="0.01"
                                    class="w-full border rounded-xl pl-8 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30
                                           {{ $errors->has('price_usd') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                            </div>
                            @error('price_usd')<p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Weight (kg)</label>
                            <input wire:model="weight" type="number" min="0" step="0.01"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Description (EN) <span class="text-danger-500">*</span></label>
                        <textarea wire:model="description_en" rows="5"
                            class="w-full border rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition resize-none bg-gray-50/30
                                   {{ $errors->has('description_en') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}"></textarea>
                        @error('description_en')<p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p>@enderror
                    </div>
                </div>

                {{-- ── TAB: Translations ────────────────────────────────── --}}
                <div x-show="tab === 'translations'" class="p-6 space-y-6">
                    <div class="flex items-center gap-2 bg-primary-50 border border-primary-100 rounded-xl px-4 py-3 text-sm text-primary-700">
                        <i class="bi bi-info-circle flex-shrink-0"></i>
                        Translations are optional. English is used as fallback.
                    </div>
                    @foreach([
                        ['fr', 'FR', 'French',     'bg-blue-100 text-blue-700'],
                        ['pt', 'PT', 'Portuguese', 'bg-green-100 text-green-700'],
                    ] as [$code, $badge, $langName, $badgeClass])
                    <div class="space-y-4 {{ $code === 'pt' ? 'pt-5 border-t border-gray-100' : '' }}">
                        <h3 class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-lg {{ $badgeClass }} text-xs font-bold">{{ $badge }}</span>
                            {{ $langName }}
                        </h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Name ({{ $badge }})</label>
                            <input wire:model="name_{{ $code }}" type="text"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Description ({{ $badge }})</label>
                            <textarea wire:model="description_{{ $code }}" rows="4"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition resize-none bg-gray-50/30"></textarea>
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- ── TAB: Variants ────────────────────────────────────── --}}
                <div x-show="tab === 'variants'" class="p-6 space-y-4">
                    @if(in_array($type, ['variant', 'simple', 'made_to_order']))

                        {{-- Existing variants table --}}
                        @if(!empty($variants))
                        <div class="rounded-xl border border-gray-100 overflow-hidden">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 border-b border-gray-100">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Variant</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">SKU</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">KES</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Sale KES</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">USD</th>
                                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Sale USD</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Active</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Default</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    @foreach($variants as $i => $v)
                                    <tr class="{{ !$v['is_active'] ? 'opacity-50' : '' }} hover:bg-gray-50/50 transition-colors">
                                        <td class="px-4 py-3">
                                            <div class="text-xs font-medium text-gray-700">
                                                {{ $v['variant_name'] ?: '-' }}
                                            </div>
                                            @if(!empty($v['attributes']))
                                                <div class="flex flex-wrap gap-1 mt-1">
                                                    @foreach($v['attributes'] as $attrK => $attrV)
                                                        <span class="inline-flex items-center rounded-full bg-primary-50 border border-primary-100 px-1.5 py-0.5 text-[10px] font-medium text-primary-600">
                                                            {{ $attrK }}: {{ $attrV }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <input wire:model="variants.{{ $i }}.sku" type="text"
                                                class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-primary-300 bg-white">
                                        </td>
                                        <td class="px-4 py-3">
                                            <input wire:model="variants.{{ $i }}.price_kes" type="number" min="0" step="0.01"
                                                class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-right tabular-nums focus:outline-none focus:ring-1 focus:ring-primary-300 bg-white">
                                        </td>
                                        <td class="px-4 py-3">
                                            <input wire:model="variants.{{ $i }}.sale_kes" type="number" min="0" step="0.01" placeholder="-"
                                                class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-right tabular-nums placeholder:text-gray-300 focus:outline-none focus:ring-1 focus:ring-primary-300 bg-white">
                                        </td>
                                        <td class="px-4 py-3">
                                            <input wire:model="variants.{{ $i }}.price_usd" type="number" min="0" step="0.01"
                                                class="w-20 border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-right tabular-nums focus:outline-none focus:ring-1 focus:ring-primary-300 bg-white">
                                        </td>
                                        <td class="px-4 py-3">
                                            <input wire:model="variants.{{ $i }}.sale_usd" type="number" min="0" step="0.01" placeholder="-"
                                                class="w-20 border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-right tabular-nums placeholder:text-gray-300 focus:outline-none focus:ring-1 focus:ring-primary-300 bg-white">
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <button wire:click="toggleVariantActive({{ $v['id'] }})"
                                                class="w-8 h-5 rounded-full transition-colors flex-shrink-0 relative inline-flex items-center
                                                       {{ $v['is_active'] ? 'bg-success-500' : 'bg-gray-200' }}">
                                                <span class="absolute w-4 h-4 rounded-full bg-white shadow transition-transform
                                                             {{ $v['is_active'] ? 'translate-x-3.5' : 'translate-x-0.5' }}"></span>
                                            </button>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if($v['is_default'])
                                                <span class="inline-flex items-center rounded-full bg-secondary-50 border border-secondary-200 px-2 py-0.5 text-[10px] font-bold text-secondary-700">Default</span>
                                            @else
                                                <button wire:click="setDefaultVariant({{ $v['id'] }})"
                                                    class="text-[10px] text-gray-400 hover:text-primary-600 font-medium transition-colors">
                                                    Set
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif

                        {{-- Add new variant --}}
                        @if(!$showAddVariant)
                        <button wire:click="$set('showAddVariant', true)"
                            class="w-full py-3 border-2 border-dashed border-primary-200 text-primary-600 text-sm font-semibold rounded-xl hover:bg-primary-50/50 transition-colors flex items-center justify-center gap-2">
                            <i class="bi bi-plus-circle"></i> Add Variant
                        </button>
                        @else
                        <div class="rounded-xl border border-primary-200 bg-primary-50/30 p-4 space-y-3">
                            <p class="text-sm font-semibold text-gray-700">New Variant</p>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Variant Name</label>
                                    <input wire:model="newVariantName" type="text" placeholder="e.g. Red / Large"
                                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-primary-300">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">SKU <span class="text-danger-500">*</span></label>
                                    <input wire:model="newVariantSku" type="text" placeholder="e.g. CHAS-001-red"
                                        class="w-full border rounded-lg px-3 py-2 text-sm font-mono bg-white focus:outline-none focus:ring-2 focus:ring-primary-300
                                               {{ $errors->has('newVariantSku') ? 'border-danger-400' : 'border-gray-200' }}">
                                    @error('newVariantSku')<p class="text-danger-500 text-xs mt-1">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Price (KES) <span class="text-danger-500">*</span></label>
                                    <input wire:model="newVariantKes" type="number" min="0" step="0.01"
                                        class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-primary-300
                                               {{ $errors->has('newVariantKes') ? 'border-danger-400' : 'border-gray-200' }}">
                                    @error('newVariantKes')<p class="text-danger-500 text-xs mt-1">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Price (USD) <span class="text-danger-500">*</span></label>
                                    <input wire:model="newVariantUsd" type="number" min="0" step="0.01"
                                        class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-primary-300
                                               {{ $errors->has('newVariantUsd') ? 'border-danger-400' : 'border-gray-200' }}">
                                    @error('newVariantUsd')<p class="text-danger-500 text-xs mt-1">{{ $message }}</p>@enderror
                                </div>
                            </div>

                            {{-- Attribute builder --}}
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1.5">Attributes</label>
                                @foreach($newVariantAttrs as $attrK => $attrV)
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <span class="text-xs bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 flex-1 font-mono">
                                            {{ $attrK }}: {{ $attrV }}
                                        </span>
                                        <button wire:click="removeNewAttr('{{ $attrK }}')"
                                            class="w-6 h-6 flex items-center justify-center rounded text-gray-300 hover:text-danger-500 transition-colors">
                                            <i class="bi bi-x text-sm"></i>
                                        </button>
                                    </div>
                                @endforeach
                                <div class="flex gap-2">
                                    <input wire:model="newAttrKey" type="text" placeholder="Key (e.g. Color)"
                                        class="flex-1 border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs bg-white focus:outline-none focus:ring-1 focus:ring-primary-300">
                                    <input wire:model="newAttrValue" type="text" placeholder="Value (e.g. Red)"
                                        class="flex-1 border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs bg-white focus:outline-none focus:ring-1 focus:ring-primary-300">
                                    <button wire:click="addNewAttr"
                                        class="px-2.5 py-1.5 rounded-lg bg-primary-50 border border-primary-200 text-primary-600 text-xs font-semibold hover:bg-primary-100 transition-colors">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 pt-1">
                                <button wire:click="saveNewVariant" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold transition-colors disabled:opacity-60">
                                    <span wire:loading.remove wire:target="saveNewVariant">Add Variant</span>
                                    <span wire:loading wire:target="saveNewVariant">Adding…</span>
                                </button>
                                <button wire:click="$set('showAddVariant', false)"
                                    class="px-4 py-2 rounded-xl border border-gray-200 text-gray-500 text-sm font-semibold hover:bg-gray-50 transition-colors">
                                    Cancel
                                </button>
                            </div>
                        </div>
                        @endif
                    @endif
                </div>

                {{-- ── TAB: Images ──────────────────────────────────────── --}}
                <div x-show="tab === 'images'" class="p-6 space-y-5">

                    {{-- Existing images --}}
                    @if(!empty($existingImages))
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Current Images</p>
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                            @foreach($existingImages as $i => $img)
                            <div class="relative group">
                                <div class="aspect-square rounded-xl overflow-hidden ring-1 ring-gray-100 {{ $img['marked_for_deletion'] ? 'opacity-30' : '' }} transition-opacity">
                                    <img src="{{ $img['image_url'] }}" alt="" class="w-full h-full object-cover">
                                </div>
                                {{-- Primary badge --}}
                                @if($img['is_primary'] && !$img['marked_for_deletion'])
                                    <span class="absolute bottom-0 left-0 right-0 text-center text-[10px] font-bold text-white bg-primary-500/80 py-0.5 rounded-b-xl">Primary</span>
                                @endif
                                {{-- Controls overlay --}}
                                <div class="absolute inset-0 rounded-xl bg-primary-900/0 group-hover:bg-primary-900/30 transition-all flex items-center justify-center gap-1 opacity-0 group-hover:opacity-100">
                                    @if(!$img['is_primary'] && !$img['marked_for_deletion'])
                                    <button wire:click="setPrimary({{ $i }})" title="Set as primary"
                                        class="w-7 h-7 rounded-lg bg-white/90 flex items-center justify-center text-primary-600 hover:bg-white transition-colors shadow-sm">
                                        <i class="bi bi-star-fill text-xs"></i>
                                    </button>
                                    @endif
                                    @if($i > 0)
                                    <button wire:click="moveImageUp({{ $i }})" title="Move left"
                                        class="w-7 h-7 rounded-lg bg-white/90 flex items-center justify-center text-gray-600 hover:bg-white transition-colors shadow-sm">
                                        <i class="bi bi-arrow-left text-xs"></i>
                                    </button>
                                    @endif
                                    @if($i < count($existingImages) - 1)
                                    <button wire:click="moveImageDown({{ $i }})" title="Move right"
                                        class="w-7 h-7 rounded-lg bg-white/90 flex items-center justify-center text-gray-600 hover:bg-white transition-colors shadow-sm">
                                        <i class="bi bi-arrow-right text-xs"></i>
                                    </button>
                                    @endif
                                    <button wire:click="toggleDeleteImage({{ $i }})" title="{{ $img['marked_for_deletion'] ? 'Restore' : 'Delete' }}"
                                        class="w-7 h-7 rounded-lg bg-white/90 flex items-center justify-center transition-colors shadow-sm
                                               {{ $img['marked_for_deletion'] ? 'text-success-600 hover:bg-white' : 'text-danger-600 hover:bg-white' }}">
                                        <i class="bi {{ $img['marked_for_deletion'] ? 'bi-arrow-counterclockwise' : 'bi-trash' }} text-xs"></i>
                                    </button>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @if(collect($existingImages)->where('marked_for_deletion', true)->count())
                            <p class="text-xs text-danger-600 mt-2 flex items-center gap-1">
                                <i class="bi bi-exclamation-circle"></i>
                                {{ collect($existingImages)->where('marked_for_deletion', true)->count() }} image(s) will be permanently deleted on save.
                            </p>
                        @endif
                    </div>
                    @endif

                    {{-- Upload new images --}}
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Add New Images</p>
                        <div x-data="{ dragging: false }"
                             @dragover.prevent="dragging = true"
                             @dragleave.prevent="dragging = false"
                             @drop.prevent="dragging = false"
                             :class="dragging ? 'border-primary-400 bg-primary-50' : 'border-gray-200 hover:border-primary-300 hover:bg-primary-50/30'"
                             class="border-2 border-dashed rounded-xl p-6 text-center cursor-pointer transition-all"
                             @click="$refs.imgInput.click()">
                            <i class="bi bi-cloud-arrow-up text-3xl text-gray-300"></i>
                            <p class="text-sm text-gray-500 mt-2">Drop images here or <span class="text-primary-600 font-semibold">browse</span></p>
                            <p class="text-xs text-gray-400 mt-1">JPEG, PNG, WebP · max 5 MB each</p>
                            <input x-ref="imgInput" type="file" wire:model="newImages" multiple accept="image/*" class="hidden">
                        </div>
                        @if(!empty($newImages))
                        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 mt-3">
                            @foreach($newImages as $i => $img)
                            <div class="aspect-square rounded-xl overflow-hidden ring-1 ring-gray-100 relative">
                                <img src="{{ $img->temporaryUrl() }}" class="w-full h-full object-cover">
                                @if($i === 0 && empty(array_filter($existingImages ?? [], fn($e) => $e['is_primary'] && !$e['marked_for_deletion'])))
                                    <span class="absolute bottom-0 left-0 right-0 text-center text-[10px] font-bold text-white bg-primary-500/80 py-0.5">Primary</span>
                                @endif
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>

                {{-- ── TAB: SEO ─────────────────────────────────────────── --}}
                <div x-show="tab === 'seo'" class="p-6 space-y-5">
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-sm font-semibold text-gray-700">Meta Title</label>
                            <span class="text-xs {{ strlen($meta_title) > 50 ? (strlen($meta_title) > 60 ? 'text-danger-500' : 'text-warning-600') : 'text-gray-400' }}">
                                {{ strlen($meta_title) }}/60
                            </span>
                        </div>
                        <input wire:model.live="meta_title" type="text" maxlength="60" placeholder="Defaults to product name if left empty"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-sm font-semibold text-gray-700">Meta Description</label>
                            <span class="text-xs {{ strlen($meta_description) > 130 ? (strlen($meta_description) > 155 ? 'text-danger-500' : 'text-warning-600') : 'text-gray-400' }}">
                                {{ strlen($meta_description) }}/155
                            </span>
                        </div>
                        <textarea wire:model.live="meta_description" rows="3" maxlength="155"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition resize-none bg-gray-50/30"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                            Keywords <span class="text-xs font-normal text-gray-400">(comma-separated)</span>
                        </label>
                        <input wire:model="meta_keywords" type="text" placeholder="vestment, clergy, chasuble, kenya"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                    </div>
                    {{-- SERP preview --}}
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 space-y-1">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2">Google Preview</p>
                        <div class="text-base font-medium text-blue-600 truncate">{{ $this->serpTitle }}</div>
                        <div class="text-xs text-green-700">yourstore.com › products › {{ $this->serpSlug }}</div>
                        <div class="text-sm text-gray-500 leading-snug line-clamp-2">{{ $this->serpDesc }}</div>
                    </div>
                </div>

            </div>{{-- end card --}}
        </div>{{-- end left col --}}

        {{-- ── RIGHT: Sidebar ──────────────────────────────────────────── --}}
        <div class="space-y-5">

            {{-- Product meta --}}
            <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-5 space-y-3 text-xs text-gray-500">
                <h3 class="text-sm font-semibold text-gray-700">Product Info</h3>
                <div class="flex justify-between"><span>ID</span><code class="font-mono text-gray-600">{{ $product->id }}</code></div>
                <div class="flex justify-between"><span>UUID</span><code class="font-mono text-gray-600 truncate max-w-[120px]">{{ $product->uuid }}</code></div>
                <div class="flex justify-between"><span>Created</span><span>{{ $product->created_at->format('d M Y') }}</span></div>
                <div class="flex justify-between"><span>Updated</span><span>{{ $product->updated_at->format('d M Y, H:i') }}</span></div>
                @if($product->published_at)
                <div class="flex justify-between"><span>Published</span><span>{{ $product->published_at->format('d M Y') }}</span></div>
                @endif
            </div>

            {{-- Product Type --}}
            <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-5">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Product Type</h3>
                <div class="space-y-2">
                    @foreach([
                        ['simple',        'Simple',        'Single SKU, no variations'],
                        ['variant',       'Variant',       'Multiple sizes, colours, etc.'],
                        ['made_to_order', 'Made to Order', 'Custom / bespoke items'],
                    ] as [$val, $label, $hint])
                    <label class="flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition-all
                                  {{ $type === $val ? 'border-primary-400 bg-primary-50 ring-1 ring-primary-300' : 'border-gray-200 hover:border-primary-200 hover:bg-gray-50' }}">
                        <input type="radio" wire:model.live="type" value="{{ $val }}"
                            class="text-primary-500 focus:ring-primary-400 border-gray-300">
                        <div>
                            <div class="text-sm font-semibold text-gray-800">{{ $label }}</div>
                            <div class="text-xs text-gray-400">{{ $hint }}</div>
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>

            {{-- Status & Visibility --}}
            <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-5 space-y-4">
                <h3 class="text-sm font-semibold text-gray-700">Status &amp; Visibility</h3>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Publish Status</label>
                    <select wire:model="status"
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input wire:model="is_featured" type="checkbox"
                        class="w-4 h-4 rounded border-gray-300 text-secondary-500 focus:ring-secondary-400">
                    <div>
                        <div class="text-sm font-semibold text-gray-700">Feature this product</div>
                        <div class="text-xs text-gray-400">Show in featured sections on the storefront</div>
                    </div>
                </label>
                @if($type === 'made_to_order')
                <label class="flex items-center gap-3 cursor-pointer">
                    <input wire:model="is_producible" type="checkbox"
                        class="w-4 h-4 rounded border-gray-300 text-primary-500 focus:ring-primary-400">
                    <div>
                        <div class="text-sm font-semibold text-gray-700">Producible</div>
                        <div class="text-xs text-gray-400">Can be made in-house</div>
                    </div>
                </label>
                @endif
            </div>

            {{-- Quick image thumbnail --}}
            @if(!empty($existingImages) && !collect($existingImages)->where('marked_for_deletion', false)->isEmpty())
            <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-5">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Images ({{ collect($existingImages)->where('marked_for_deletion', false)->count() }})</h3>
                <div class="grid grid-cols-3 gap-2">
                    @foreach(collect($existingImages)->where('marked_for_deletion', false)->take(6) as $img)
                    <div class="aspect-square rounded-xl overflow-hidden ring-1 ring-gray-100">
                        <img src="{{ $img['image_url'] }}" alt="" class="w-full h-full object-cover">
                    </div>
                    @endforeach
                </div>
                <button wire:click="$set('activeTab', 'images')"
                    class="mt-2 text-xs text-primary-600 hover:text-primary-700 font-semibold transition-colors">
                    Manage images →
                </button>
            </div>
            @endif

        </div>{{-- end right col --}}

    </div>

</div>