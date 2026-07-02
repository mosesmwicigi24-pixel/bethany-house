<div class="space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-primary-300 font-semibold mb-1.5 uppercase tracking-widest">
                <i class="bi bi-megaphone"></i><span>Marketing</span>
                <i class="bi bi-chevron-right text-[10px]"></i><span>Banners & Sliders</span>
            </div>
            <h1 class="text-2xl font-bold text-primary-500 font-dm-sans tracking-tight">Banners & Sliders</h1>
            <p class="mt-0.5 text-sm text-primary-300">Manage hero banners, sidebars, popups and promotional sliders.</p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white transition shadow-sm shadow-primary-500/20">
            <i class="bi bi-plus-lg"></i> Add Banner
        </button>
    </div>

    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-success-50 border border-success-200 px-4 py-3 text-sm text-success-700">
            <i class="bi bi-check-circle-fill text-success-500 flex-shrink-0"></i>{{ session('success') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2.5">
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All Positions', 'hero' => 'Hero', 'sidebar' => 'Sidebar', 'popup' => 'Popup', 'footer' => 'Footer', 'category_top' => 'Category'] as $v => $l)
                <button wire:click="$set('positionFilter','{{ $v }}')"
                        class="px-3.5 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $positionFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
        <div class="flex items-center rounded-xl border border-primary-100 bg-white overflow-hidden text-sm">
            @foreach(['' => 'All', 'live' => 'Live', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'inactive' => 'Inactive'] as $v => $l)
                <button wire:click="$set('statusFilter','{{ $v }}')"
                        class="px-3 py-2.5 font-medium transition border-l first:border-l-0 border-primary-100
                               {{ $statusFilter === $v ? 'bg-primary-500 text-white' : 'text-primary-400 hover:text-primary-600' }}">{{ $l }}</button>
            @endforeach
        </div>
    </div>

    {{-- Grouped by position --}}
    @if($banners->isEmpty())
        <div class="py-20 text-center bg-white rounded-2xl border border-primary-100 shadow-sm">
            <i class="bi bi-images text-4xl text-primary-100 block mb-3"></i>
            <p class="text-sm font-medium text-primary-300">No banners yet.</p>
            <button wire:click="openCreate" class="mt-3 text-sm text-primary-400 hover:text-primary-600 font-semibold transition">Add your first banner →</button>
        </div>
    @else
        @foreach($positions as $position)
            @if(isset($banners[$position]) && $banners[$position]->count())
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <h2 class="text-xs font-bold text-primary-400 uppercase tracking-widest">
                            {{ ucfirst(str_replace('_',' ',$position)) }}
                        </h2>
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-primary-100 text-primary-500 text-[10px] font-bold">
                            {{ $banners[$position]->count() }}
                        </span>
                        <div class="flex-1 h-px bg-primary-100"></div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                        @foreach($banners[$position] as $banner)
                            @php
                                $status  = $banner->status;
                                $badge   = match($status) {
                                    'live'      => 'bg-success-50 text-success-700 border border-success-200',
                                    'scheduled' => 'bg-info-50 text-info-700 border border-info-200',
                                    'expired'   => 'bg-warning-50 text-warning-700 border border-warning-200',
                                    default     => 'bg-primary-50 text-primary-400 border border-primary-100',
                                };
                                $styles = $banner->styles ?? [];
                            @endphp
                            <div class="group bg-white rounded-2xl border border-primary-100 hover:border-primary-200 hover:shadow-md transition-all duration-200 overflow-hidden flex flex-col">
                                {{-- Banner image preview --}}
                                <div class="relative aspect-[16/5] overflow-hidden bg-primary-100">
                                    @if($banner->image_url)
                                        <img src="{{ $banner->image_url }}" alt="{{ $banner->title }}"
                                             class="w-full h-full object-cover" />
                                        {{-- Overlay --}}
                                        <div class="absolute inset-0 flex flex-col justify-center px-5"
                                             style="background: rgba({{ implode(',', sscanf($styles['bg_color'] ?? '#000000', '#%02x%02x%02x') ?? [0,0,0]) }}, {{ $styles['overlay_opacity'] ?? 0.3 }});">
                                            <p class="text-white font-bold text-sm leading-tight line-clamp-1 drop-shadow"
                                               style="color: {{ $styles['text_color'] ?? '#ffffff' }}">{{ $banner->title }}</p>
                                            @if($banner->subtitle)
                                                <p class="text-xs mt-0.5 line-clamp-1 drop-shadow opacity-80"
                                                   style="color: {{ $styles['text_color'] ?? '#ffffff' }}">{{ $banner->subtitle }}</p>
                                            @endif
                                        </div>
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <i class="bi bi-image text-3xl text-primary-300"></i>
                                        </div>
                                    @endif
                                    <span class="absolute top-2 right-2 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold {{ $badge }} capitalize backdrop-blur-sm">{{ $status }}</span>
                                </div>

                                {{-- Card body --}}
                                <div class="px-4 py-3.5 flex-1 space-y-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="font-bold text-primary-600 text-sm truncate">{{ $banner->title }}</p>
                                            @if($banner->link_url)
                                                <p class="text-xs text-primary-300 truncate mt-0.5">
                                                    <i class="bi bi-link-45deg mr-0.5"></i>{{ $banner->link_url }}
                                                </p>
                                            @endif
                                        </div>
                                        {{-- Sort order controls --}}
                                        <div class="flex flex-col gap-0.5 flex-shrink-0">
                                            <button wire:click="moveUp({{ $banner->id }})" class="w-5 h-5 flex items-center justify-center rounded text-primary-200 hover:text-primary-500 transition">
                                                <i class="bi bi-chevron-up text-xs"></i>
                                            </button>
                                            <button wire:click="moveDown({{ $banner->id }})" class="w-5 h-5 flex items-center justify-center rounded text-primary-200 hover:text-primary-500 transition">
                                                <i class="bi bi-chevron-down text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                    @if($banner->starts_at || $banner->ends_at)
                                        <p class="text-xs text-primary-400">
                                            <i class="bi bi-calendar3 mr-1"></i>
                                            {{ $banner->starts_at?->format('d M Y') ?? '∞' }} - {{ $banner->ends_at?->format('d M Y') ?? '∞' }}
                                        </p>
                                    @endif
                                </div>

                                {{-- Actions --}}
                                <div class="px-4 py-3 border-t border-primary-50 flex items-center justify-between">
                                    <button wire:click="viewBanner({{ $banner->id }})"
                                            class="text-xs font-semibold text-primary-400 hover:text-primary-600 transition">
                                        <i class="bi bi-eye mr-1"></i>Preview
                                    </button>
                                    <div class="flex items-center gap-1">
                                        <button wire:click="toggleActive({{ $banner->id }})"
                                                class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-[11px] font-semibold transition
                                                       {{ $banner->is_active ? 'bg-success-50 border-success-200 text-success-700 hover:bg-success-100' : 'bg-primary-50 border-primary-200 text-primary-400 hover:bg-primary-100' }}">
                                            <i class="bi {{ $banner->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} text-sm"></i>
                                            {{ $banner->is_active ? 'Live' : 'Off' }}
                                        </button>
                                        <button wire:click="openEdit({{ $banner->id }})"
                                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-info-600 hover:bg-info-50 transition">
                                            <i class="bi bi-pencil text-xs"></i>
                                        </button>
                                        <button wire:click="confirmDelete({{ $banner->id }})"
                                                class="w-7 h-7 inline-flex items-center justify-center rounded-lg text-primary-300 hover:text-danger-600 hover:bg-danger-50 transition">
                                            <i class="bi bi-trash3 text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    @endif

    {{-- ═══ PREVIEW SLIDE-OVER ═══ --}}
    @if($showPreview && $previewing)
        <div class="fixed inset-0 z-50 flex" x-data x-on:keydown.escape.window="$wire.set('showPreview',false)">
            <div class="flex-1 bg-primary-900/30 backdrop-blur-sm" wire:click="$set('showPreview',false)"></div>
            <div class="w-full max-w-lg bg-white shadow-2xl flex flex-col h-full overflow-hidden">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100 flex-shrink-0">
                    <div>
                        <h2 class="text-base font-bold text-primary-500">{{ $previewing->title }}</h2>
                        <p class="text-xs text-primary-300 mt-0.5 capitalize">{{ str_replace('_',' ',$previewing->position) }} · {{ $previewing->placement }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="openEdit({{ $previewing->id }})"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-primary-100 px-3 py-1.5 text-xs font-semibold text-primary-400 hover:text-primary-600 hover:border-primary-300 transition">
                            <i class="bi bi-pencil text-xs"></i> Edit
                        </button>
                        <button wire:click="$set('showPreview',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition">
                            <i class="bi bi-x-lg text-sm"></i>
                        </button>
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                    {{-- Live preview --}}
                    @if($previewing->image_url)
                        @php $s = $previewing->styles ?? []; @endphp
                        <div class="relative rounded-2xl overflow-hidden aspect-[16/6]">
                            <img src="{{ $previewing->image_url }}" alt="{{ $previewing->title }}" class="w-full h-full object-cover" />
                            <div class="absolute inset-0 flex flex-col justify-center px-8"
                                 style="background: rgba({{ implode(',', sscanf($s['bg_color'] ?? '#000000', '#%02x%02x%02x') ?? [0,0,0]) }}, {{ $s['overlay_opacity'] ?? 0.3 }});">
                                <h3 class="text-xl font-black drop-shadow-md" style="color: {{ $s['text_color'] ?? '#ffffff' }}">{{ $previewing->title }}</h3>
                                @if($previewing->subtitle)
                                    <p class="text-sm mt-1 drop-shadow opacity-90" style="color: {{ $s['text_color'] ?? '#ffffff' }}">{{ $previewing->subtitle }}</p>
                                @endif
                                @if($previewing->link_text)
                                    <div class="mt-3">
                                        <span class="inline-flex items-center rounded-lg bg-white/90 px-3.5 py-1.5 text-sm font-semibold text-primary-600 shadow">
                                            {{ $previewing->link_text }} →
                                        </span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                    <div class="grid grid-cols-2 gap-3">
                        @foreach([
                            ['Position',   ucfirst(str_replace('_',' ',$previewing->position))],
                            ['Placement',  ucfirst($previewing->placement)],
                            ['Link',       $previewing->link_url ?? '-'],
                            ['New Tab',    $previewing->open_in_new_tab ? 'Yes' : 'No'],
                            ['Starts',     $previewing->starts_at?->format('d M Y, H:i') ?? 'Immediately'],
                            ['Ends',       $previewing->ends_at?->format('d M Y, H:i')   ?? 'No end'],
                            ['Status',     ucfirst($previewing->status)],
                            ['Sort Order', $previewing->sort_order],
                        ] as [$l,$v])
                            <div class="bg-primary-50/50 rounded-xl border border-primary-100 px-3.5 py-3">
                                <p class="text-[11px] font-semibold text-primary-300 uppercase tracking-wide">{{ $l }}</p>
                                <p class="text-sm font-medium text-primary-600 mt-0.5 truncate">{{ $v }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ CREATE / EDIT MODAL ═══ --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto"
             x-data x-on:keydown.escape.window="$wire.set('showModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showModal',false)"></div>
            <div class="relative w-full max-w-xl rounded-2xl bg-white shadow-2xl shadow-primary-900/20 my-6">
                <div class="flex items-center justify-between px-6 py-5 border-b border-primary-100">
                    <h2 class="text-base font-bold text-primary-500">{{ $isEditing ? 'Edit Banner' : 'New Banner' }}</h2>
                    <button wire:click="$set('showModal',false)" class="w-8 h-8 flex items-center justify-center rounded-lg text-primary-300 hover:text-primary-500 hover:bg-primary-50 transition"><i class="bi bi-x-lg text-sm"></i></button>
                </div>
                @if($errors->any())
                    <div class="mx-6 mt-4 flex items-start gap-3 rounded-xl bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700">
                        <i class="bi bi-exclamation-circle-fill flex-shrink-0 mt-0.5"></i>
                        <ul class="list-disc list-inside space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="px-6 py-5 space-y-4">
                    {{-- Title / Subtitle --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Title <span class="text-danger-500">*</span></label>
                            <input wire:model="title" type="text" placeholder="e.g. Summer Collection"
                                   class="w-full border {{ $errors->has('title') ? 'border-danger-400 bg-danger-50/20' : 'border-primary-100' }} rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('title')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Subtitle</label>
                            <input wire:model="subtitle" type="text" placeholder="e.g. Up to 40% off"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    {{-- Image --}}
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">
                            Banner Image {{ $isEditing ? '' : '*' }}
                        </label>
                        <input wire:model="newImage" type="file" accept="image/*"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:text-primary-600 file:text-xs file:font-semibold file:px-3 file:py-1 hover:file:bg-primary-100 transition" />
                        @error('newImage')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        @if($isEditing && $imageUrl)
                            <div class="mt-2 flex items-center gap-2 text-xs text-primary-400">
                                <i class="bi bi-image"></i>
                                <span>Current: {{ basename($imageUrl) }}</span>
                            </div>
                        @endif
                        @if($newImage)
                            <img src="{{ $newImage->temporaryUrl() }}" alt="Preview" class="mt-2 h-24 rounded-xl object-cover border border-primary-100" />
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Mobile Image <span class="font-normal normal-case text-primary-200">(optional)</span></label>
                        <input wire:model="newMobileImage" type="file" accept="image/*"
                               class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:text-primary-600 file:text-xs file:font-semibold file:px-3 file:py-1 hover:file:bg-primary-100 transition" />
                    </div>
                    {{-- Link --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Link URL</label>
                            <input wire:model="linkUrl" type="text" placeholder="https://…"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Button Text</label>
                            <input wire:model="linkText" type="text" placeholder="e.g. Shop Now"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    {{-- Position / Placement --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Position <span class="text-danger-500">*</span></label>
                            <select wire:model="position" class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition bg-white">
                                @foreach(['hero','sidebar','popup','footer','category_top'] as $p)
                                    <option value="{{ $p }}">{{ ucfirst(str_replace('_',' ',$p)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Placement</label>
                            <input wire:model="placement" type="text" placeholder="e.g. homepage, sale-page"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 placeholder:text-primary-200 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                    </div>
                    {{-- Style --}}
                    <div>
                        <p class="text-xs font-semibold text-primary-400 uppercase tracking-wide mb-2">Overlay Style</p>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-[11px] text-primary-400 mb-1">Text Color</label>
                                <input wire:model="textColor" type="color" class="w-full h-9 rounded-xl border border-primary-100 cursor-pointer" />
                            </div>
                            <div>
                                <label class="block text-[11px] text-primary-400 mb-1">Overlay Color</label>
                                <input wire:model="bgColor" type="color" class="w-full h-9 rounded-xl border border-primary-100 cursor-pointer" />
                            </div>
                            <div>
                                <label class="block text-[11px] text-primary-400 mb-1">Opacity (0–1)</label>
                                <input wire:model="overlayOpacity" type="number" min="0" max="1" step="0.05"
                                       class="w-full border border-primary-100 rounded-xl px-2.5 py-2 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            </div>
                        </div>
                    </div>
                    {{-- Schedule --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Starts At</label>
                            <input wire:model="startsAt" type="datetime-local"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Ends At</label>
                            <input wire:model="endsAt" type="datetime-local"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                            @error('endsAt')<p class="text-xs text-danger-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-primary-400 uppercase tracking-wide mb-1.5">Sort Order</label>
                            <input wire:model="sortOrder" type="number" min="0"
                                   class="w-full border border-primary-100 rounded-xl px-3.5 py-2.5 text-sm text-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15 focus:border-primary-300 transition" />
                        </div>
                        <div class="flex flex-col gap-3 pt-5">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input wire:model="isActive" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                                <span class="text-sm font-medium text-primary-500">Active</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input wire:model="openInNewTab" type="checkbox" class="w-4 h-4 rounded border-primary-200 text-primary-500 focus:ring-primary-500/20" />
                                <span class="text-sm font-medium text-primary-500">Open in new tab</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2.5 px-6 py-4 border-t border-primary-100 bg-primary-50/30">
                    <button wire:click="$set('showModal',false)" class="rounded-xl border border-primary-100 bg-white px-4 py-2 text-sm font-medium text-primary-400 hover:text-primary-500 transition">Cancel</button>
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary-500 hover:bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60 shadow-sm shadow-primary-500/20">
                        <span wire:loading.remove wire:target="save">{{ $isEditing ? 'Update Banner' : 'Create Banner' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══ DELETE CONFIRM ═══ --}}
    @if($showDeleteModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showDeleteModal',false)">
            <div class="absolute inset-0 bg-primary-900/40 backdrop-blur-sm" wire:click="$set('showDeleteModal',false)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white shadow-2xl shadow-primary-900/20">
                <div class="px-6 py-6 text-center space-y-3">
                    <div class="w-14 h-14 rounded-full bg-danger-50 border border-danger-200 flex items-center justify-center mx-auto">
                        <i class="bi bi-images text-danger-500 text-2xl"></i>
                    </div>
                    <h2 class="text-base font-bold text-primary-500">Delete Banner?</h2>
                    <p class="text-sm text-primary-400"><span class="font-semibold text-primary-600">{{ $deletingTitle }}</span> will be permanently removed.</p>
                </div>
                <div class="flex items-center justify-center gap-3 px-6 pb-6">
                    <button wire:click="$set('showDeleteModal',false)" class="flex-1 rounded-xl border border-primary-100 bg-white px-4 py-2.5 text-sm font-semibold text-primary-400 transition">Cancel</button>
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