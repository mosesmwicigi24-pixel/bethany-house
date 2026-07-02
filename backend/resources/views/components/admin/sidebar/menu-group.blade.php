@props(['icon', 'title', 'slug', 'badge' => null, 'links' => []])

@php
    // Extract just the path from the full URL (e.g. /admin/products)
    $urlPath = $slug;

    // Determine if the current path starts with the url path
    $isActive = request()->is($urlPath) || request()->is($urlPath . '/*');
@endphp

<div class="px-3">
    <a href="#"
        @click.prevent="$store.sidebar.group === '{{ $slug }}' ? $store.sidebar.group = null : $store.sidebar.group = '{{ $slug }}'"
        class="flex items-center justify-between rounded-lg px-3 py-1.5 text-sm font-normal {{ $isActive ? 'bg-secondary text-primary' : 'text-white hover:bg-secondary hover:text-primary dark:text-slate-400' }} transition-all">
        <span class="flex items-center space-x-2">
            <i class="{{ $icon }} text-lg"></i>
            <span x-show="hovered || !$store.sidebar.collapsed" class="whitespace-nowrap transition-opacity duration-200">
                {{ $title }}
                @if ($badge)
                    <span
                        class="ml-2 rounded bg-yellow-100 px-2 py-0.5 text-xs text-yellow-700">{{ $badge }}</span>
                @endif
            </span>
        </span>

        <!-- Toggle chevron icon (visible only when not collapsed) -->
        <i x-show="hovered || !$store.sidebar.collapsed"
            :class="$store.sidebar.group === '{{ $slug }}' ? 'bi-chevron-down' : 'bi-chevron-right'"
            class="bi text-sm transition-transform"></i>
    </a>

    <!-- Submenu -->
    <ul x-show="$store.sidebar.group === '{{ $slug }}' && (hovered || !$store.sidebar.collapsed)" x-collapse
        class="ml-6 mt-1 space-y-1">
        @foreach ($links as $link)
            <li>
                <a href="{{ $link['url'] }}"
                    class="flex items-center space-x-2 px-2 py-1 font-normal text-[13px] text-secondary-100 hover:text-secondary dark:text-slate-400 transition-all">
                    <span
                        class="inline-block h-2 w-2 rounded-full border border-secondary-100 dark:border-slate-400"></span>
                    <span>{{ $link['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
