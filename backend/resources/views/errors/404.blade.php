@extends('errors.layout')

@section('title', '404 - Page Not Found')

@section('content')
    {{-- Code badge --}}
    <div class="code-badge">
        <i class="ri-map-pin-line"></i>
        404
    </div>

    {{-- Icon --}}
    <div class="icon-wrap icon-wrap--404">
        <i class="ri-compass-discover-line"></i>
    </div>

    {{-- Copy --}}
    <h1>Page Not Found</h1>
    <p class="subtitle">
        The page you're looking for has moved, been deleted, or never existed.
        Double-check the URL or head back to where you came from.
    </p>

    {{-- URL hint --}}
    <div class="detail-box">
        <strong>Requested URL</strong>
        <p>{{ request()->url() }}</p>
    </div>

    <div class="divider"></div>

    <div class="actions">
        <a href="javascript:history.back()" class="btn btn--ghost">
            <i class="ri-arrow-left-line"></i>
            Go Back
        </a>
        <a href="{{ route('admin.dashboard') }}" class="btn btn--primary">
            <i class="ri-home-4-line"></i>
            Dashboard
        </a>
    </div>
@endsection

@section('nav-hint')
<div class="nav-hint">
    <a href="{{ route('admin.dashboard') }}">Dashboard</a>
    <i class="ri-arrow-right-s-line"></i>
    <span>404 Not Found</span>
</div>
@endsection