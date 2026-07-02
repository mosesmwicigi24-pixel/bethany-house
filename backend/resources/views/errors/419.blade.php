@extends('errors.layout')

@section('title', '419 - Session Expired')

@section('content')
    {{-- Code badge --}}
    <div class="code-badge">
        <i class="ri-timer-2-line"></i>
        419
    </div>

    {{-- Icon --}}
    <div class="icon-wrap icon-wrap--419">
        <i class="ri-lock-2-line"></i>
    </div>

    {{-- Copy --}}
    <h1>Session Expired</h1>
    <p class="subtitle">
        Your session has timed out for security reasons or the page token is no longer valid.
        Refresh the page to continue - you won't lose any unsaved work if you act quickly.
    </p>

    <div class="divider"></div>

    <div class="actions">
        <a href="javascript:location.reload()" class="btn btn--ghost">
            <i class="ri-refresh-line"></i>
            Refresh Page
        </a>
        <a href="{{ route('admin.login') }}" class="btn btn--primary">
            <i class="ri-login-box-line"></i>
            Sign In Again
        </a>
    </div>
@endsection

@section('nav-hint')
<div class="nav-hint">
    <a href="{{ route('admin.login') }}">Sign In</a>
    <i class="ri-arrow-right-s-line"></i>
    <span>419 Session Expired</span>
</div>
@endsection