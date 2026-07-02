@extends('errors.layout')

@section('title', '500 - Server Error')

@section('content')
    {{-- Code badge --}}
    <div class="code-badge">
        <i class="ri-bug-line"></i>
        500
    </div>

    {{-- Icon --}}
    <div class="icon-wrap icon-wrap--500">
        <i class="ri-server-line"></i>
    </div>

    {{-- Copy --}}
    <h1>Something Went Wrong</h1>
    <p class="subtitle">
        An unexpected error occurred on our end. Our team has been notified.
        Try refreshing the page - if the problem persists, please contact support.
    </p>

    {{-- Technical detail - only in non-production --}}
    @if(config('app.debug') && isset($exception) && $exception->getMessage())
    <div class="detail-box">
        <strong>Debug Info (not shown in production)</strong>
        <p>{{ $exception->getMessage() }}</p>
        @if($exception->getFile())
        <p style="margin-top:6px;color:var(--primary-300)">
            {{ $exception->getFile() }} : {{ $exception->getLine() }}
        </p>
        @endif
    </div>
    @else
    <div class="detail-box">
        <strong>Error Reference</strong>
        <p>{{ now()->toIso8601String() }} &nbsp;·&nbsp; {{ request()->method() }} {{ request()->path() }}</p>
    </div>
    @endif

    <div class="divider"></div>

    <div class="actions">
        <a href="javascript:location.reload()" class="btn btn--ghost">
            <i class="ri-refresh-line"></i>
            Try Again
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
    <span>500 Server Error</span>
</div>
@endsection