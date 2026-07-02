@extends('errors.layout')

@section('title', '403 - Forbidden')

@section('content')
    {{-- Code badge --}}
    <div class="code-badge">
        <i class="ri-error-warning-line"></i>
        403
    </div>

    {{-- Icon --}}
    <div class="icon-wrap icon-wrap--403">
        <i class="ri-shield-keyhole-line"></i>
    </div>

    {{-- Copy --}}
    <h1>Access Denied</h1>
    <p class="subtitle">
        {{ $exception->getMessage() ?: "You don't have permission to access this area. Contact your administrator if you believe this is a mistake." }}
    </p>

    {{-- Role info (only shown when role data is available) --}}
    @if(auth()->check())
    <div class="detail-box">
        <strong>Your Account</strong>
        <p>
            Signed in as <strong style="font-family:inherit;font-size:inherit;text-transform:none;letter-spacing:0;display:inline;color:var(--primary)">{{ auth()->user()->name }}</strong>
            &nbsp;&mdash;&nbsp;
            Role: <strong style="font-family:inherit;font-size:inherit;text-transform:none;letter-spacing:0;display:inline;color:var(--primary)">{{ ucwords(str_replace('_', ' ', auth()->user()->role ?? 'unknown')) }}</strong>
        </p>
        <p style="margin-top:4px;color:var(--primary-300)">
            If you need access, ask a Super Admin to update your role.
        </p>
    </div>
    @endif

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
    <span>403 Forbidden</span>
</div>
@endsection