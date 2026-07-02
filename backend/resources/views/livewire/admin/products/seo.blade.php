<div class="space-y-6 font-dm-sans">

    {{-- Flash --}}
    @if($flashMessage)
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
         x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="rounded-xl px-4 py-3 text-sm font-medium border
                {{ $flashType === 'success' ? 'bg-success-100 text-success-700 border-success-200' : 'bg-danger-100 text-danger-700 border-danger-200' }}">
        {{ $flashMessage }}
    </div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-primary-500 tracking-tight">SEO &amp; Meta</h1>
            <p class="text-sm text-gray-500 mt-0.5">Manage meta titles, descriptions and slugs across all products</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button wire:click="$set('showGenModal', true)"
                class="inline-flex items-center gap-2 border border-secondary-400 bg-secondary-50 hover:bg-secondary-100 text-secondary-800 text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors">
                <i class="bi bi-magic"></i> Auto-generate
            </button>
            <button wire:click="saveAll" wire:loading.attr="disabled"
                @class([
                    'inline-flex items-center gap-2 text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors shadow-sm disabled:opacity-60',
                    'bg-primary-500 hover:bg-primary-600 text-white' => count($pendingEdits) > 0,
                    'bg-gray-200 text-gray-400 cursor-not-allowed' => count($pendingEdits) === 0,
                ])>
                <span wire:loading.remove wire:target="saveAll">
                    <i class="bi bi-check2-all me-1"></i>
                    Save Changes{{ count($pendingEdits) > 0 ? ' (' . count($pendingEdits) . ')' : '' }}
                </span>
                <span wire:loading wire:target="saveAll">Saving…</span>
            </button>
        </div>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-4 text-center">
            <div class="text-2xl font-bold text-primary-500">{{ $this->stats['total'] }}</div>
            <div class="text-xs text-gray-500 mt-1">Total Products</div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-4 text-center">
            <div class="text-2xl font-bold text-success-600">{{ $this->stats['has_title'] }}</div>
            <div class="text-xs text-gray-500 mt-1">Have Meta Title</div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-4 text-center">
            <div class="text-2xl font-bold text-warning-600">{{ $this->stats['missing_title'] }}</div>
            <div class="text-xs text-gray-500 mt-1">Missing Title</div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-4 text-center">
            <div class="text-2xl font-bold text-warning-600">{{ $this->stats['missing_desc'] }}</div>
            <div class="text-xs text-gray-500 mt-1">Missing Description</div>
        </div>
    </div>

    {{-- Completion progress --}}
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-5">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-semibold text-gray-700">SEO Completion</span>
            <span class="text-sm font-bold text-primary-500">{{ $this->stats['completion_pct'] }}%</span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
            <div class="bg-gradient-to-r from-primary-500 to-secondary-400 h-2.5 rounded-full transition-all duration-700"
                 style="width: {{ $this->stats['completion_pct'] }}%"></div>
        </div>
        <p class="text-xs text-gray-400 mt-2">Completion = products with both a meta title AND description.</p>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-4">
        <div class="flex flex-wrap gap-3 items-center">
            <div class="flex-1 min-w-[200px] relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input wire:model.live.debounce.350ms="search" type="text" placeholder="Search name or SKU…"
                    class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/50">
            </div>
            <select wire:model.live="missingMeta"
                class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/50">
                <option value="">All Products</option>
                <option value="title">Missing Meta Title</option>
                <option value="description">Missing Meta Description</option>
            </select>
            <select wire:model.live="perPage"
                class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/50">
                <option value="25">25 / page</option>
                <option value="50">50 / page</option>
                <option value="100">100 / page</option>
            </select>
        </div>
    </div>

    {{-- Unsaved changes bar --}}
    @if(count($pendingEdits) > 0)
    <div class="bg-secondary-50 border border-secondary-300 rounded-2xl px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
        <span class="text-sm text-secondary-800 font-semibold">
            <i class="bi bi-pencil-square me-1.5"></i>{{ count($pendingEdits) }} unsaved change{{ count($pendingEdits) !== 1 ? 's' : '' }}
        </span>
        <div class="flex items-center gap-2">
            <button wire:click="discardEdits"
                class="text-sm text-gray-500 border border-gray-200 px-3 py-1.5 rounded-xl hover:bg-white transition-colors">Discard</button>
            <button wire:click="saveAll"
                class="text-sm bg-primary-500 text-white px-4 py-1.5 rounded-xl hover:bg-primary-600 transition-colors font-semibold">Save Now</button>
        </div>
    </div>
    @endif

    {{-- SEO Table --}}
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 overflow-hidden">
        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50/80 border-b border-gray-100">
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600 min-w-[190px]">Product</th>
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600 min-w-[220px]">
                            Meta Title <span class="text-xs font-normal text-gray-400">max 60</span>
                        </th>
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600 min-w-[260px]">
                            Meta Description <span class="text-xs font-normal text-gray-400">max 155</span>
                        </th>
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600 min-w-[180px]">Slug</th>
                        <th class="px-4 py-3.5 text-center font-semibold text-gray-600 w-24">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($this->products as $product)
                    @php
                        $pending      = $pendingEdits[$product->id] ?? [];
                        $title        = $pending['meta_title']       ?? $product->meta_title       ?? '';
                        $desc         = $pending['meta_description'] ?? $product->meta_description ?? '';
                        $slug         = $pending['slug']             ?? $product->slug             ?? '';
                        $titleLen     = strlen($title);
                        $descLen      = strlen($desc);
                        $isDirty      = isset($pendingEdits[$product->id]);
                    @endphp
                    <tr class="hover:bg-gray-50/40 transition-colors align-top {{ $isDirty ? 'bg-secondary-50/40' : '' }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-800 text-sm leading-snug">{{ $product->name_en }}</div>
                            <div class="font-mono text-xs text-gray-400 mt-0.5">{{ $product->sku }}</div>
                            @if($isDirty)
                            <span class="inline-flex mt-1 px-1.5 py-0.5 rounded text-xs bg-secondary-100 text-secondary-800 font-medium">unsaved</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-between mb-1">
                                <span class="inline-flex items-center gap-1 text-xs">
                                    <span class="w-1.5 h-1.5 rounded-full inline-block
                                        {{ !$titleLen ? 'bg-danger-400' : ($titleLen > 60 ? 'bg-warning-400' : 'bg-success-400') }}"></span>
                                </span>
                                <span class="text-xs {{ $titleLen > 60 ? 'text-danger-500 font-semibold' : ($titleLen > 50 ? 'text-warning-600' : 'text-gray-400') }}">
                                    {{ $titleLen }}/60
                                </span>
                            </div>
                            <input
                                type="text"
                                maxlength="60"
                                value="{{ $title }}"
                                placeholder="Add meta title…"
                                wire:change="updateField({{ $product->id }}, 'meta_title', $event.target.value)"
                                class="w-full border border-gray-200 rounded-xl px-2.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-primary-300 focus:border-primary-400 transition bg-gray-50/30 hover:bg-white">
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-between mb-1">
                                <span class="inline-flex items-center gap-1 text-xs">
                                    <span class="w-1.5 h-1.5 rounded-full inline-block
                                        {{ !$descLen ? 'bg-danger-400' : ($descLen > 155 ? 'bg-warning-400' : 'bg-success-400') }}"></span>
                                </span>
                                <span class="text-xs {{ $descLen > 155 ? 'text-danger-500 font-semibold' : ($descLen > 130 ? 'text-warning-600' : 'text-gray-400') }}">
                                    {{ $descLen }}/155
                                </span>
                            </div>
                            <textarea
                                maxlength="155"
                                rows="2"
                                placeholder="Add meta description…"
                                wire:change="updateField({{ $product->id }}, 'meta_description', $event.target.value)"
                                class="w-full border border-gray-200 rounded-xl px-2.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-primary-300 focus:border-primary-400 transition resize-none bg-gray-50/30 hover:bg-white">{{ $desc }}</textarea>
                        </td>
                        <td class="px-4 py-3">
                            <input
                                type="text"
                                value="{{ $slug }}"
                                wire:change="updateField({{ $product->id }}, 'slug', $event.target.value)"
                                class="w-full border border-gray-200 rounded-xl px-2.5 py-2 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30 hover:bg-white">
                        </td>
                        <td class="px-4 py-3 text-center">
                            @php $statusMap = ['active' => 'bg-success-100 text-success-700', 'draft' => 'bg-warning-100 text-warning-700', 'archived' => 'bg-gray-100 text-gray-500'] @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $statusMap[$product->status] ?? 'bg-gray-100' }}">
                                {{ ucfirst($product->status) }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="text-center py-16">
                            <div class="flex flex-col items-center gap-2 text-gray-400">
                                <i class="bi bi-search text-3xl text-gray-200"></i>
                                <p class="text-sm font-medium text-gray-500">No products found</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->products->hasPages())
        <div class="px-5 py-3.5 border-t border-gray-100 flex items-center justify-between flex-wrap gap-2 bg-gray-50/50">
            <p class="text-sm text-gray-500">
                Showing {{ $this->products->firstItem() }}–{{ $this->products->lastItem() }} of {{ $this->products->total() }}
            </p>
            {{ $this->products->links('livewire.admin.pagination') }}
        </div>
        @endif
    </div>

    {{-- Auto-generate Modal --}}
    @if($showGenModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
         x-data x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6 ring-1 ring-gray-200"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
            <h3 class="font-semibold text-gray-900 mb-1">Auto-generate SEO Data</h3>
            <p class="text-sm text-gray-500 mb-5">Generates meta titles from product names and descriptions from the product body text.</p>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Scope</label>
                    <select wire:model="genScope"
                        class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                        <option value="all">Fill all missing fields</option>
                        <option value="missing_title">Only missing meta titles</option>
                        <option value="missing_description">Only missing meta descriptions</option>
                    </select>
                </div>
                <label class="flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-gray-200 hover:border-danger-300 transition-colors"
                       :class="$wire.genOverwrite ? 'border-danger-300 bg-danger-50/30' : ''">
                    <input wire:model="genOverwrite" type="checkbox"
                        class="mt-0.5 rounded border-gray-300 text-danger-500 focus:ring-danger-400">
                    <div>
                        <div class="text-sm font-semibold text-gray-700">Overwrite existing values</div>
                        <div class="text-xs text-gray-400">Replaces any already-filled meta fields. Use with caution.</div>
                    </div>
                </label>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button wire:click="$set('showGenModal', false)"
                    class="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">Cancel</button>
                <button wire:click="autoGenerate" wire:loading.attr="disabled"
                    class="px-5 py-2 text-sm bg-secondary-500 hover:bg-secondary-600 text-primary-900 font-bold rounded-xl transition-colors disabled:opacity-60">
                    <span wire:loading.remove wire:target="autoGenerate">Generate</span>
                    <span wire:loading wire:target="autoGenerate">Generating…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

</div>