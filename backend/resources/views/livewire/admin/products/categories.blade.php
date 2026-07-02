<div class="space-y-6 font-dm-sans">

    {{-- Flash --}}
    @if($flashMessage)
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
         x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="rounded-xl px-4 py-3 text-sm font-medium border
                {{ $flashType === 'success' ? 'bg-success-100 text-success-700 border-success-200' : 'bg-danger-100 text-danger-700 border-danger-200' }}">
        {{ $flashMessage }}
    </div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-primary-500 tracking-tight">Categories</h1>
            <p class="text-sm text-gray-500 mt-0.5">Organise your product taxonomy</p>
        </div>
        <button wire:click="openCreate"
            class="inline-flex items-center gap-2 bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors shadow-sm">
            <i class="bi bi-plus-lg"></i> Add Category
        </button>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        {{-- Category Table --}}
        <div class="xl:col-span-2 bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-gray-700">All Categories</h2>
                <div class="relative">
                    <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search…"
                        class="pl-8 pr-3 py-1.5 border border-gray-200 rounded-xl text-sm w-44 focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/50">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50/80 border-b border-gray-100">
                            <th class="px-5 py-3 text-left w-10 font-semibold text-gray-500 text-xs">#</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600">Name</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600">Parent</th>
                            <th class="px-5 py-3 text-center font-semibold text-gray-600">Products</th>
                            <th class="px-5 py-3 text-center font-semibold text-gray-600">Status</th>
                            <th class="px-5 py-3 text-center font-semibold text-gray-600">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse($this->categories as $cat)
                        <tr class="hover:bg-gray-50/60 transition-colors">
                            <td class="px-5 py-3.5 text-gray-400 text-xs tabular-nums">{{ $cat->sort_order ?? '-' }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    @if($cat->image_path)
                                        <img src="{{ Storage::url($cat->image_path) }}" class="w-8 h-8 rounded-lg object-cover ring-1 ring-gray-100">
                                    @elseif($cat->icon)
                                        <div class="w-8 h-8 rounded-lg bg-primary-50 flex items-center justify-center">
                                            <i class="{{ $cat->icon }} text-primary-500 text-sm"></i>
                                        </div>
                                    @else
                                        <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center">
                                            <i class="bi bi-folder text-gray-300 text-sm"></i>
                                        </div>
                                    @endif
                                    <span class="font-semibold text-gray-800">{{ ($cat->translations->firstWhere('language_code', 'en')?->name ?? '-') }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-gray-500 text-xs">
                                {{ ($cat->parent?->translations?->firstWhere('language_code', 'en')?->name ?? '') ?? '<span class="text-gray-300">-</span>' }}
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary-50 text-primary-600">
                                    {{ $cat->products_count }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                @if($cat->is_active !== false)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-success-100 text-success-700">Active</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Inactive</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-center gap-1.5">
                                    <button wire:click="openEdit({{ $cat->id }})"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-primary-600 hover:bg-primary-50 transition-colors">
                                        <i class="bi bi-pencil text-sm"></i>
                                    </button>
                                    <button wire:click="confirmDelete({{ $cat->id }}, '{{ addslashes(($cat->translations->firstWhere('language_code', 'en')?->name ?? '-')) }}')"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-danger-600 hover:bg-danger-50 transition-colors">
                                        <i class="bi bi-trash text-sm"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center py-16">
                                <div class="flex flex-col items-center gap-2 text-gray-400">
                                    <i class="bi bi-folder text-3xl text-gray-200"></i>
                                    <p class="text-sm font-medium text-gray-500">No categories yet</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Tree View --}}
        <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-700">Hierarchy</h2>
                <p class="text-xs text-gray-400 mt-0.5">Visual tree of your category structure</p>
            </div>
            <div class="p-4 space-y-0.5">
                @forelse($this->tree as $cat)
                    <div class="flex items-center gap-2 py-1.5 text-gray-700">
                        @if($cat->icon)
                            <i class="{{ $cat->icon }} text-primary-400 text-sm w-4"></i>
                        @else
                            <i class="bi bi-folder text-gray-300 text-sm w-4"></i>
                        @endif
                        <span class="text-sm font-semibold">{{ ($cat->translations->firstWhere('language_code', 'en')?->name ?? '-') }}</span>
                        <span class="ml-auto text-xs text-gray-400">{{ $cat->products_count }}</span>
                    </div>
                    @foreach($cat->children ?? [] as $child)
                    <div class="flex items-center gap-2 py-1 pl-5 text-gray-600">
                        <span class="text-gray-300 text-xs">└</span>
                        @if($child->icon) <i class="{{ $child->icon }} text-primary-300 text-xs w-4"></i>
                        @else <i class="bi bi-folder text-gray-200 text-xs w-4"></i> @endif
                        <span class="text-sm">{{ ($child->translations->firstWhere('language_code', 'en')?->name ?? '-') }}</span>
                        <span class="ml-auto text-xs text-gray-400">{{ $child->products_count }}</span>
                    </div>
                        @foreach($child->children ?? [] as $gc)
                        <div class="flex items-center gap-2 py-1 pl-10 text-gray-500">
                            <span class="text-gray-200 text-xs">└</span>
                            <span class="text-xs">{{ ($gc->translations->firstWhere('language_code', 'en')?->name ?? '-') }}</span>
                        </div>
                        @endforeach
                    @endforeach
                @empty
                    <p class="text-xs text-gray-400 py-4 text-center">No categories yet.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Add / Edit Modal ──────────────────────────────────────────────── --}}
    @if($showModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
         x-data x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4 overflow-hidden ring-1 ring-gray-200 max-h-[90vh] flex flex-col"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">

            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
                <h3 class="font-semibold text-gray-900 text-base">{{ $editId ? 'Edit Category' : 'Add Category' }}</h3>
                <button wire:click="$set('showModal', false)"
                    class="w-8 h-8 rounded-xl flex items-center justify-center text-gray-400 hover:bg-gray-100 transition-colors">
                    <i class="bi bi-x-lg text-sm"></i>
                </button>
            </div>

            {{-- Lang tabs --}}
            <div class="flex border-b border-gray-100 px-6 flex-shrink-0">
                @foreach([['en','EN'],['fr','FR'],['pt','PT']] as [$code,$label])
                <button wire:click="$set('activeTab', '{{ $code }}')"
                    class="px-4 py-3 text-sm font-semibold -mb-px transition-colors
                           {{ $activeTab === $code ? 'border-b-2 border-primary-500 text-primary-600' : 'border-b-2 border-transparent text-gray-500 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>

            <div class="p-6 overflow-y-auto flex-1 space-y-5">
                {{-- EN fields --}}
                @if($activeTab === 'en')
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1.5">Name (EN) <span class="text-danger-500">*</span></label>
                        <input wire:model="name_en" type="text"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                        @error('name_en') <p class="text-danger-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (EN)</label>
                        <textarea wire:model="description_en" rows="3"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition resize-none bg-gray-50/30"></textarea>
                    </div>
                </div>
                @elseif($activeTab === 'fr')
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Name (FR)</label>
                        <input wire:model="name_fr" type="text" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (FR)</label>
                        <textarea wire:model="description_fr" rows="3" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition resize-none bg-gray-50/30"></textarea>
                    </div>
                </div>
                @elseif($activeTab === 'pt')
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Name (PT)</label>
                        <input wire:model="name_pt" type="text" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition bg-gray-50/30">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (PT)</label>
                        <textarea wire:model="description_pt" rows="3" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 transition resize-none bg-gray-50/30"></textarea>
                    </div>
                </div>
                @endif

                {{-- Common fields --}}
                <div class="grid grid-cols-2 gap-4 pt-4 border-t border-gray-100">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Parent Category</label>
                        <select wire:model="parent_id" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                            <option value="">- None (top level) -</option>
                            @foreach($this->parentOptions as $p)
                                <option value="{{ $p->id }}">{{ ($p->translations->firstWhere('language_code', 'en')?->name ?? '-') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Icon class</label>
                        <input wire:model="icon" type="text" placeholder="bi bi-bag"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Sort Order</label>
                        <input wire:model="sort_order" type="number" min="0"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2.5 cursor-pointer">
                            <input wire:model="is_active" type="checkbox"
                                class="w-4 h-4 rounded border-gray-300 text-primary-500 focus:ring-primary-400">
                            <span class="text-sm font-medium text-gray-700">Active</span>
                        </label>
                    </div>
                </div>

                {{-- Image Upload --}}
                <div class="pt-3 border-t border-gray-100">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Category Image</label>
                    <input wire:model="image" type="file" accept="image/*"
                        class="w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100 transition cursor-pointer">
                    @if($image)
                        <img src="{{ $image->temporaryUrl() }}" class="mt-2 h-20 w-20 object-cover rounded-xl ring-1 ring-gray-100">
                    @endif
                </div>

                {{-- SEO --}}
                <div class="space-y-3 pt-3 border-t border-gray-100">
                    <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wide">SEO</h4>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Meta Title</label>
                        <input wire:model="meta_title" type="text" maxlength="255"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/30">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Meta Description</label>
                        <textarea wire:model="meta_description" rows="2"
                            class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 resize-none bg-gray-50/30"></textarea>
                    </div>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2 flex-shrink-0 bg-gray-50/50">
                <button wire:click="$set('showModal', false)"
                    class="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-xl hover:bg-white transition-colors">Cancel</button>
                <button wire:click="save" wire:loading.attr="disabled"
                    class="px-5 py-2 text-sm bg-primary-500 text-white rounded-xl hover:bg-primary-600 transition-colors font-semibold disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">Save Category</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Delete Modal --}}
    @if($showDeleteModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 ring-1 ring-gray-200">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-danger-100 flex items-center justify-center flex-shrink-0">
                    <i class="bi bi-exclamation-triangle text-danger-600 text-lg"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-900">Delete Category</h3>
                    <p class="text-sm text-gray-500 mt-1">Delete <strong class="text-gray-800">{{ $deleteName }}</strong>? Categories with products or subcategories cannot be deleted.</p>
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button wire:click="$set('showDeleteModal', false)"
                    class="px-4 py-2 text-sm border border-gray-200 rounded-xl hover:bg-gray-50">Cancel</button>
                <button wire:click="delete" wire:loading.attr="disabled"
                    class="px-4 py-2 text-sm bg-danger-600 text-white rounded-xl hover:bg-danger-700 transition-colors font-semibold disabled:opacity-60">
                    <span wire:loading.remove wire:target="delete">Delete</span>
                    <span wire:loading wire:target="delete">Deleting…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

</div>