<div class="max-w-5xl mx-auto space-y-6 font-dm-sans" x-data="{ tab: @entangle('activeTab') }">

    {{-- Flash: DB errors --}}
    @if($flashMessage)
    <div class="flex items-start gap-3 rounded-xl px-4 py-3 text-sm font-medium border
                {{ $flashType === 'error' ? 'bg-danger-50 text-danger-700 border-danger-200' : 'bg-success-50 text-success-700 border-success-200' }}">
        <i class="bi {{ $flashType === 'error' ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill' }} flex-shrink-0 mt-0.5"></i>
        {{ $flashMessage }}
    </div>
    @endif

    {{-- Cross-tab validation error summary --}}
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
            <h1 class="text-2xl font-semibold text-primary-500 tracking-tight">Add Product</h1>
            <nav class="flex items-center gap-1.5 text-xs text-gray-400 mt-1">
                <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-600 transition-colors">Dashboard</a>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <a href="{{ route('admin.products.index') }}" class="hover:text-gray-600 transition-colors">Products</a>
                <i class="bi bi-chevron-right text-[10px]"></i>
                <span class="text-gray-600">Add Product</span>
            </nav>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="save('draft')" wire:loading.attr="disabled"
                class="px-4 py-2.5 text-sm font-semibold text-gray-600 border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="save('draft')">Save Draft</span>
                <span wire:loading wire:target="save('draft')"><i class="bi bi-arrow-repeat animate-spin me-1"></i>Saving…</span>
            </button>
            <button wire:click="save('active')" wire:loading.attr="disabled"
                class="px-4 py-2.5 text-sm font-semibold bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors shadow-sm disabled:opacity-60">
                <span wire:loading.remove wire:target="save('active')">Publish Product</span>
                <span wire:loading wire:target="save('active')"><i class="bi bi-arrow-repeat animate-spin me-1"></i>Publishing…</span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── LEFT: Main content ───────────────────────────────────────── --}}
        <div class="lg:col-span-2 space-y-5">

            {{-- Tabs --}}
            <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 overflow-hidden">
                <div class="flex border-b border-gray-100 overflow-x-auto">
                    @foreach([
                        ['details',      'bi-box-seam',  'Details'],
                        ['translations', 'bi-translate', 'Translations'],
                        ['variants',     'bi-diagram-3', 'Variants'],
                        ['seo',          'bi-search',    'SEO'],
                    ] as [$key, $icon, $label])
                    <button wire:click="$set('activeTab', '{{ $key }}')"
                        :class="tab === '{{ $key }}' ? 'border-b-2 border-primary-500 text-primary-600' : 'border-b-2 border-transparent text-gray-500 hover:text-gray-700'"
                        class="px-5 py-3.5 text-sm font-semibold whitespace-nowrap flex items-center gap-1.5 transition-colors -mb-px relative">
                        <i class="bi {{ $icon }} text-sm"></i>
                        {{ $label }}
                        {{-- Error badge: shown when this tab has validation errors --}}
                        @if($this->tabErrors[$key] ?? false)
                            <span class="absolute top-2 right-2 w-2 h-2 rounded-full bg-danger-500"></span>
                        @endif
                    </button>
                    @endforeach
                </div>

                {{-- TAB: Details --}}
                <div x-show="tab === 'details'" class="p-6 space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">
                                Product Name (EN) <span class="text-danger-500">*</span>
                            </label>
                            <input wire:model.live="name_en" type="text" placeholder="e.g. Handcrafted Leather Tote"
                                class="w-full border rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 focus:border-primary-400 transition bg-gray-50/30
                                       {{ $errors->has('name_en') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                            @error('name_en') <p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">SKU <span class="text-danger-500">*</span></label>
                            <input wire:model="sku" type="text" placeholder="e.g. LTB-001"
                                class="w-full border rounded-xl px-3.5 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30
                                       {{ $errors->has('sku') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                            @error('sku') <p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p> @enderror
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
                            @error('category_id') <p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Price (KES) <span class="text-danger-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-400">KES</span>
                                <input wire:model="price_kes" type="number" min="0" step="0.01" placeholder="0"
                                    class="w-full border rounded-xl pl-11 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30
                                           {{ $errors->has('price_kes') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                            </div>
                            @error('price_kes') <p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Price (USD) <span class="text-danger-500">*</span></label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-bold text-gray-400">$</span>
                                <input wire:model="price_usd" type="number" min="0" step="0.01" placeholder="0.00"
                                    class="w-full border rounded-xl pl-8 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30
                                           {{ $errors->has('price_usd') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}">
                            </div>
                            @error('price_usd') <p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Weight (kg)</label>
                            <input wire:model="weight" type="number" min="0" step="0.01" placeholder="0.00"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Description (EN) <span class="text-danger-500">*</span></label>
                        <textarea wire:model="description_en" rows="5" placeholder="Describe your product in detail…"
                            class="w-full border rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition resize-none bg-gray-50/30
                                   {{ $errors->has('description_en') ? 'border-danger-400 bg-danger-50/20' : 'border-gray-200' }}"></textarea>
                        @error('description_en') <p class="text-danger-500 text-xs mt-1 flex items-center gap-1"><i class="bi bi-exclamation-circle"></i>{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- TAB: Translations --}}
                <div x-show="tab === 'translations'" class="p-6 space-y-6">
                    <div class="flex items-center gap-2 bg-primary-50 border border-primary-100 rounded-xl px-4 py-3 text-sm text-primary-700">
                        <i class="bi bi-info-circle flex-shrink-0"></i>
                        Translations are optional. English is shown as fallback when a translation is missing.
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

                {{-- TAB: Variants --}}
                <div x-show="tab === 'variants'" class="p-6 space-y-5">
                    @if($type === 'variant')
                        <div class="flex items-center justify-between">
                            <p class="text-sm text-gray-600">Define attributes (e.g. Size, Colour). Each combination becomes a variant SKU.</p>
                            <button wire:click="addAttributeRow"
                                class="text-sm text-primary-600 hover:text-primary-700 font-semibold flex items-center gap-1.5 transition-colors">
                                <i class="bi bi-plus-circle"></i> Add Attribute
                            </button>
                        </div>
                        <div class="space-y-2">
                            @forelse($variantAttributes as $i => $attr)
                            <div class="flex items-center gap-2">
                                <input wire:model.live="variantAttributes.{{ $i }}.key" type="text" placeholder="Attribute (e.g. Size)"
                                    class="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                                <input wire:model.live="variantAttributes.{{ $i }}.values" type="text" placeholder="Values comma-separated (e.g. S,M,L,XL)"
                                    class="flex-[2] border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                                <button wire:click="removeAttributeRow({{ $i }})"
                                    class="w-8 h-8 rounded-xl flex items-center justify-center text-gray-300 hover:text-danger-500 hover:bg-danger-50 transition-colors flex-shrink-0">
                                    <i class="bi bi-x-lg text-sm"></i>
                                </button>
                            </div>
                            @empty
                            <p class="text-sm text-gray-400 text-center py-4">No attributes yet. Click "Add Attribute" to start.</p>
                            @endforelse
                        </div>

                        @if(!empty($variantAttributes))
                        <button wire:click="generateVariants"
                            class="w-full py-3 border-2 border-dashed border-primary-200 text-primary-600 text-sm font-semibold rounded-xl hover:bg-primary-50/50 transition-colors flex items-center justify-center gap-2">
                            <i class="bi bi-lightning-charge-fill"></i> Generate Variants
                        </button>
                        @endif

                        @if(!empty($generatedVariants))
                        <div class="space-y-2 pt-2">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                {{ count($generatedVariants) }} variant{{ count($generatedVariants) === 1 ? '' : 's' }} to be created
                            </p>
                            @foreach($generatedVariants as $i => $variant)
                            <div class="flex items-center gap-3 bg-gray-50 rounded-xl p-3 text-sm ring-1 ring-gray-100">
                                <span class="text-gray-600 flex-1 text-xs">
                                    @foreach($variant['attrs'] as $attrKey => $attrVal)
                                        <span class="font-semibold text-gray-700">{{ $attrKey }}:</span> {{ $attrVal }}{{ !$loop->last ? ' · ' : '' }}
                                    @endforeach
                                </span>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[10px] text-gray-400 font-bold uppercase">KES</span>
                                    <input wire:change="updateVariantPrice({{ $i }}, 'price_kes', $event.target.value)"
                                        type="number" value="{{ $variant['price_kes'] }}" placeholder="KES"
                                        class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-300 tabular-nums">
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[10px] text-gray-400 font-bold uppercase">USD</span>
                                    <input wire:change="updateVariantPrice({{ $i }}, 'price_usd', $event.target.value)"
                                        type="number" value="{{ $variant['price_usd'] }}" placeholder="USD"
                                        class="w-20 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-300 tabular-nums">
                                </div>
                                <code class="text-[10px] text-gray-300 font-mono hidden sm:block">{{ $sku }}-{{ $variant['sku_suffix'] }}</code>
                            </div>
                            @endforeach
                        </div>
                        @endif

                    @elseif($type === 'simple')
                        <div class="flex flex-col items-center justify-center py-10 text-center text-gray-400">
                            <i class="bi bi-box-seam text-3xl mb-2"></i>
                            <p class="text-sm font-medium text-gray-500">Simple product</p>
                            <p class="text-xs mt-1">A single default variant will be created automatically.</p>
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-10 text-center text-gray-400">
                            <i class="bi bi-scissors text-3xl mb-2"></i>
                            <p class="text-sm font-medium text-gray-500">Made to Order</p>
                            <p class="text-xs mt-1">Custom specifications are collected per order.</p>
                        </div>
                    @endif
                </div>

                {{-- TAB: SEO --}}
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
                        <input wire:model="meta_keywords" type="text" placeholder="bag, leather, handmade, kenya"
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
            </div>
        </div>

        {{-- ── RIGHT: Sidebar ───────────────────────────────────────────── --}}
        <div class="space-y-5">

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
                <label class="flex items-center gap-3 cursor-pointer group">
                    <input wire:model="is_featured" type="checkbox"
                        class="w-4 h-4 rounded border-gray-300 text-secondary-500 focus:ring-secondary-400">
                    <div>
                        <div class="text-sm font-semibold text-gray-700">Feature this product</div>
                        <div class="text-xs text-gray-400">Show in featured sections on the storefront</div>
                    </div>
                </label>
            </div>

            {{-- Image Upload --}}
            <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-5">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Product Images</h3>
                <div x-data="{ dragging: false }"
                     @dragover.prevent="dragging = true"
                     @dragleave.prevent="dragging = false"
                     @drop.prevent="dragging = false"
                     :class="dragging ? 'border-primary-400 bg-primary-50' : 'border-gray-200 hover:border-primary-300 hover:bg-primary-50/30'"
                     class="border-2 border-dashed rounded-xl p-6 text-center cursor-pointer transition-all"
                     @click="$refs.imageInput.click()">
                    <i class="bi bi-cloud-arrow-up text-3xl text-gray-300"></i>
                    <p class="text-sm text-gray-500 mt-2">Drop images here or <span class="text-primary-600 font-semibold">browse</span></p>
                    <p class="text-xs text-gray-400 mt-1">JPEG, PNG, WebP · max 5 MB each</p>
                    <input x-ref="imageInput" type="file" wire:model="images" multiple accept="image/*" class="hidden">
                </div>
                @if(!empty($images))
                <div class="grid grid-cols-3 gap-2 mt-3">
                    @foreach($images as $i => $img)
                    <div class="aspect-square rounded-xl overflow-hidden ring-1 ring-gray-100 relative">
                        <img src="{{ $img->temporaryUrl() }}" class="w-full h-full object-cover">
                        @if($i === 0)
                            <span class="absolute bottom-0 left-0 right-0 text-center text-[10px] font-bold text-white bg-primary-500/80 py-0.5">Primary</span>
                        @endif
                    </div>
                    @endforeach
                </div>
                @endif
                <p class="text-xs text-gray-400 mt-2">Images are uploaded when the product is saved.</p>
            </div>

        </div>
    </div>
</div>