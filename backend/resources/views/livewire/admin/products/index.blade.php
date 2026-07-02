<div class="space-y-6 font-dm-sans">

    {{-- Flash --}}
    @if($flashMessage)
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
         x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="rounded-xl px-4 py-3 text-sm font-medium flex items-center justify-between
                {{ $flashType === 'success' ? 'bg-success-100 text-success-700 border border-success-200' : 'bg-danger-100 text-danger-700 border border-danger-200' }}">
        <span>{{ $flashMessage }}</span>
        <button @click="show = false" class="ml-4 opacity-60 hover:opacity-100"><i class="bi bi-x-lg text-xs"></i></button>
    </div>
    @endif

    {{-- Page Header --}}
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-primary-500 tracking-tight">Products</h1>
            <p class="text-sm text-gray-500 mt-0.5">Manage your entire product catalogue</p>
        </div>
        <a href="{{ route('admin.products.create') }}"
           class="inline-flex items-center gap-2 bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition-colors shadow-sm">
            <i class="bi bi-plus-lg"></i> Add Product
        </a>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        @foreach([
            ['',         'All',      $this->summary['total'],    'text-primary-500'],
            ['active',   'Active',   $this->summary['active'],   'text-success-600'],
            ['draft',    'Draft',    $this->summary['draft'],    'text-warning-600'],
            ['archived', 'Archived', $this->summary['archived'], 'text-gray-400'],
            ['featured', 'Featured', $this->summary['featured'], 'text-secondary-600'],
        ] as [$key, $label, $count, $color])
        <button wire:click="setStatus('{{ $key }}')"
            class="bg-white rounded-2xl p-4 text-center shadow-sm hover:shadow-md transition-all
                   {{ $status === $key ? 'ring-2 ring-primary-500 ring-offset-1' : 'ring-1 ring-gray-100' }}">
            <div class="text-2xl font-bold {{ $color }}">{{ $count }}</div>
            <div class="text-xs text-gray-500 mt-1">{{ $label }}</div>
        </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 p-4">
        <div class="flex flex-wrap gap-3 items-center">
            <div class="flex-1 min-w-[200px] relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input wire:model.live.debounce.350ms="search" type="text" placeholder="Search name, SKU…"
                    class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 focus:border-primary-400 transition bg-gray-50/50">
            </div>
            <select wire:model.live="category_id"
                class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/50 min-w-[160px]">
                <option value="">All Categories</option>
                @foreach($this->categories as $cat)
                    <option value="{{ $cat->id }}">{{ ($cat->translations->firstWhere('language_code', 'en')?->name ?? '-') }} ({{ $cat->products_count }})</option>
                @endforeach
            </select>
            <select wire:model.live="type"
                class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300 bg-gray-50/50">
                <option value="">All Types</option>
                <option value="simple">Simple</option>
                <option value="variant">Variant</option>
                <option value="made_to_order">Made to Order</option>
            </select>
            <button wire:click="clearFilters"
                class="px-4 py-2 text-sm text-gray-500 border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors flex items-center gap-1.5">
                <i class="bi bi-x-circle"></i> Clear
            </button>
        </div>
    </div>

    {{-- Bulk Bar --}}
    @if(count($selected) > 0)
    <div class="bg-primary-50 border border-primary-200 rounded-2xl px-4 py-3 flex items-center justify-between gap-3 flex-wrap">
        <span class="text-sm text-primary-700 font-semibold">
            <i class="bi bi-check2-square me-1.5"></i>{{ count($selected) }} product{{ count($selected) !== 1 ? 's' : '' }} selected
        </span>
        <div class="flex items-center gap-2 flex-wrap">
            <select wire:model="bulkAction"
                class="border border-primary-200 bg-white rounded-xl px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-300">
                <option value="">Bulk action…</option>
                <option value="active">Set Active</option>
                <option value="draft">Set Draft</option>
                <option value="archived">Archive</option>
                <option value="delete">Delete Selected</option>
            </select>
            <button wire:click="applyBulkAction"
                class="bg-primary-500 text-white text-sm px-4 py-1.5 rounded-xl hover:bg-primary-600 transition-colors font-medium">
                Apply
            </button>
            <button wire:click="$set('selected', [])"
                class="text-sm px-3 py-1.5 border border-gray-200 rounded-xl text-gray-500 hover:bg-white transition-colors">
                Cancel
            </button>
        </div>
    </div>
    @endif

    {{-- Table --}}
    <div class="bg-white rounded-2xl shadow-sm ring-1 ring-gray-100 overflow-hidden">
        {{-- Loading overlay --}}
        <div wire:loading.delay class="relative">
            <div class="absolute inset-0 bg-white/60 backdrop-blur-sm z-10 flex items-center justify-center rounded-2xl">
                <div class="flex items-center gap-2 text-primary-500">
                    <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <span class="text-sm font-medium">Loading…</span>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/80">
                        <th class="px-4 py-3.5 text-left w-10">
                            <input type="checkbox" wire:model.live="selectAll"
                                class="rounded border-gray-300 text-primary-500 focus:ring-primary-400 cursor-pointer">
                        </th>
                        <th class="px-4 py-3.5 w-14"></th>
                        <th class="px-4 py-3.5 text-left">
                            <button wire:click="sortBy('name_en')" class="flex items-center gap-1.5 font-semibold text-gray-600 hover:text-gray-900 transition-colors">
                                Product
                                @if($sort_by === 'name_en')
                                    <i class="bi bi-arrow-{{ $sort_order === 'asc' ? 'up' : 'down' }} text-primary-500 text-xs"></i>
                                @else
                                    <i class="bi bi-arrow-down-up text-gray-300 text-xs"></i>
                                @endif
                            </button>
                        </th>
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600">SKU</th>
                        <th class="px-4 py-3.5 text-left font-semibold text-gray-600">Category</th>
                        <th class="px-4 py-3.5 text-right">
                            <button wire:click="sortBy('price_kes')" class="flex items-center gap-1.5 font-semibold text-gray-600 hover:text-gray-900 transition-colors ml-auto">
                                KES
                                @if($sort_by === 'price_kes')
                                    <i class="bi bi-arrow-{{ $sort_order === 'asc' ? 'up' : 'down' }} text-primary-500 text-xs"></i>
                                @else
                                    <i class="bi bi-arrow-down-up text-gray-300 text-xs"></i>
                                @endif
                            </button>
                        </th>
                        <th class="px-4 py-3.5 text-right font-semibold text-gray-600">USD</th>
                        <th class="px-4 py-3.5 text-center font-semibold text-gray-600">Type</th>
                        <th class="px-4 py-3.5 text-center">
                            <button wire:click="sortBy('status')" class="flex items-center gap-1.5 font-semibold text-gray-600 hover:text-gray-900 transition-colors mx-auto">
                                Status
                                @if($sort_by === 'status')
                                    <i class="bi bi-arrow-{{ $sort_order === 'asc' ? 'up' : 'down' }} text-primary-500 text-xs"></i>
                                @endif
                            </button>
                        </th>
                        <th class="px-4 py-3.5 text-center font-semibold text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($this->products as $product)
                    <tr class="hover:bg-gray-50/60 transition-colors group">
                        <td class="px-4 py-3.5">
                            <input type="checkbox" wire:model.live="selected" value="{{ $product->id }}"
                                class="rounded border-gray-300 text-primary-500 focus:ring-primary-400 cursor-pointer">
                        </td>
                        <td class="px-4 py-3.5">
                            @if($product->images->first()?->image_path)
                                <img src="{{ Storage::url($product->images->first()->image_path) }}"
                                     class="w-10 h-10 rounded-xl object-cover ring-1 ring-gray-100" alt="{{ $product->name_en }}">
                            @else
                                <div class="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center">
                                    <i class="bi bi-image text-gray-300 text-base"></i>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3.5 max-w-[220px]">
                            <div class="font-semibold text-gray-800 leading-snug truncate">{{ $product->name_en }}</div>
                            <div class="text-xs text-gray-400 mt-0.5 flex items-center gap-1.5">
                                {{ $product->variants_count }} variant{{ $product->variants_count !== 1 ? 's' : '' }}
                                @if($product->is_featured)
                                    <i class="bi bi-star-fill text-secondary-400 text-xs" title="Featured"></i>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <span class="font-mono text-xs text-gray-400 bg-gray-50 px-2 py-1 rounded-lg">{{ $product->sku }}</span>
                        </td>
                        <td class="px-4 py-3.5 text-gray-600 text-xs">{{ $product->category?->translations?->firstWhere('language_code', 'en')?->name ?? '-' }}</td>
                        <td class="px-4 py-3.5 text-right font-medium text-gray-700 tabular-nums text-xs">
                            {{ number_format($product->price_kes) }}
                        </td>
                        <td class="px-4 py-3.5 text-right text-gray-700 tabular-nums text-xs">
                            ${{ number_format($product->price_usd, 2) }}
                        </td>
                        <td class="px-4 py-3.5 text-center">
                            @php $typeMap = ['simple' => ['bg-gray-100 text-gray-600', 'Simple'], 'variant' => ['bg-primary-50 text-primary-600 border border-primary-200', 'Variant'], 'made_to_order' => ['bg-secondary-100 text-secondary-800', 'MTO']] @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-xs font-medium {{ ($typeMap[$product->type] ?? ['bg-gray-100 text-gray-500','?'])[0] }}">
                                {{ ($typeMap[$product->type] ?? ['','?'])[1] }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5 text-center">
                            @php $statusMap = ['active' => 'bg-success-100 text-success-700', 'draft' => 'bg-warning-100 text-warning-700', 'archived' => 'bg-gray-100 text-gray-500'] @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $statusMap[$product->status] ?? 'bg-gray-100 text-gray-500' }}">
                                {{ ucfirst($product->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center justify-center gap-1.5">
                                <a href="{{ route('admin.products.edit', $product->id) }}"
                                   class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-primary-600 hover:bg-primary-50 transition-colors"
                                   title="Edit">
                                    <i class="bi bi-pencil text-sm"></i>
                                </a>
                                <button wire:click="confirmDelete({{ $product->id }}, '{{ addslashes($product->name_en) }}')"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-gray-400 hover:text-danger-600 hover:bg-danger-50 transition-colors"
                                    title="Delete">
                                    <i class="bi bi-trash text-sm"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="text-center py-20">
                            <div class="flex flex-col items-center gap-3 text-gray-400">
                                <i class="bi bi-box-seam text-4xl text-gray-200"></i>
                                <div>
                                    <p class="font-medium text-gray-500">No products found</p>
                                    <p class="text-sm mt-0.5">Try adjusting your filters or <a href="{{ route('admin.products.create') }}" class="text-primary-500 underline">add a new product</a></p>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($this->products->hasPages())
        <div class="px-5 py-3.5 border-t border-gray-100 flex items-center justify-between flex-wrap gap-2 bg-gray-50/50">
            <p class="text-sm text-gray-500">
                Showing <span class="font-semibold text-gray-700">{{ $this->products->firstItem() }}</span>–<span class="font-semibold text-gray-700">{{ $this->products->lastItem() }}</span>
                of <span class="font-semibold text-gray-700">{{ $this->products->total() }}</span> products
            </p>
            {{ $this->products->links('livewire.admin.pagination') }}
        </div>
        @endif
    </div>

    {{-- Delete Modal --}}
    @if($showDeleteModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm"
         x-data x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 ring-1 ring-gray-200"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
            <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-danger-100 flex items-center justify-center flex-shrink-0">
                    <i class="bi bi-exclamation-triangle text-danger-600 text-lg"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-gray-900">Delete Product</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        Delete <strong class="text-gray-800">{{ $deleteProductName }}</strong>?
                        This cannot be undone. Products with existing orders cannot be deleted.
                    </p>
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button wire:click="$set('showDeleteModal', false)"
                    class="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button wire:click="deleteProduct" wire:loading.attr="disabled"
                    class="px-4 py-2 text-sm bg-danger-600 text-white rounded-xl hover:bg-danger-700 transition-colors font-semibold disabled:opacity-60">
                    <span wire:loading.remove wire:target="deleteProduct">Delete</span>
                    <span wire:loading wire:target="deleteProduct">Deleting…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

</div>