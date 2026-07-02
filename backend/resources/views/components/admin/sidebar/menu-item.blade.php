@props(['icon', 'title', 'url'])

@php
    // Extract just the path from the full URL (e.g. /admin/products)
    $urlPath = trim(parse_url($url, PHP_URL_PATH), '/');

    // Determine if the current path starts with the url path
    $isActive = request()->is($urlPath) || request()->is($urlPath . '/*');
@endphp

<div class="px-3">
    <a href="{{ $url }}"
        class="flex items-center rounded-lg px-3 py-1.5 text-sm font-normal {{ $isActive ? 'bg-secondary text-primary' : 'text-white hover:bg-secondary hover:text-primary dark:text-slate-400' }} transition-all">
        <i class="{{ $icon }} text-lg"></i>
        <span x-show="hovered || !$store.sidebar.collapsed" class="ml-2 whitespace-nowrap transition-opacity duration-200">
            {{ $title }}
        </span>
    </a>
</div>
