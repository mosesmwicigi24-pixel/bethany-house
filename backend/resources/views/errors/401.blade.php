@extends('errors.layout')

@section('title', '401 - Unauthenticated')

@section('content')
    {{-- Code badge --}}
    <div class="code-badge">
        <i class="ri-user-forbid-line"></i>
        401
    </div>

    {{-- Icon --}}
    <div class="icon-wrap icon-wrap--403">
        <i class="ri-login-box-line"></i>
    </div>

    {{-- Copy --}}
    <h1>Sign In Required</h1>
    <p class="subtitle">
        You need to be signed in to access this page.
        Please log in with your admin credentials to continue.
    </p>

    <div class="divider"></div>

    <div class="actions">
        <a href="javascript:history.back()" class="btn btn--ghost">
            <i class="ri-arrow-left-line"></i>
            Go Back
        </a>
        <a href="{{ route('admin.login') }}" class="btn btn--primary">
            <i class="ri-login-box-line"></i>
            Sign In
        </a>
    </div>
@endsection

@section('nav-hint')
<div class="nav-hint">
    <a href="{{ route('admin.login') }}">Sign In</a>
    <i class="ri-arrow-right-s-line"></i>
    <span>401 Unauthenticated</span>
</div>
@endsection