@extends('errors.layout')

@section('title', '503 - Under Maintenance')

@push('head')
<style>
    /* Pulsing status dot */
    .status-dot {
        display: inline-block;
        width: 8px; height: 8px;
        border-radius: 50%;
        background: var(--success);
        animation: pulse-dot 2s ease-in-out infinite;
        margin-right: 6px;
        vertical-align: middle;
        position: relative;
        top: -1px;
    }
    @keyframes pulse-dot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%       { opacity: 0.4; transform: scale(0.8); }
    }

    /* Steps list */
    .steps {
        list-style: none;
        text-align: left;
        margin: 0 0 28px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .steps li {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-size: 13px;
        color: var(--primary-400);
        line-height: 1.5;
    }
    .steps li i {
        font-size: 14px;
        color: var(--success-600, #16a34a);
        margin-top: 2px;
        flex-shrink: 0;
    }
</style>
@endpush

@section('content')
    {{-- Code badge --}}
    <div class="code-badge">
        <i class="ri-tools-line"></i>
        503
    </div>

    {{-- Icon --}}
    <div class="icon-wrap icon-wrap--503">
        <i class="ri-settings-3-line" style="animation: spin 6s linear infinite;"></i>
    </div>

    {{-- Copy --}}
    <h1>Under Maintenance</h1>
    <p class="subtitle">
        {{ isset($exception) && $exception->getMessage() ? $exception->getMessage() : "We're making improvements to Bethany House. We'll be back shortly - thank you for your patience." }}
    </p>

    {{-- What's happening --}}
    <ul class="steps">
        <li>
            <i class="ri-checkbox-circle-fill"></i>
            Your data is safe and fully backed up.
        </li>
        <li>
            <i class="ri-checkbox-circle-fill"></i>
            All active orders will continue to be processed.
        </li>
        <li>
            <i class="ri-checkbox-circle-fill"></i>
            The admin panel will be restored as soon as possible.
        </li>
    </ul>

    <div class="detail-box">
        <strong>Live Status</strong>
        <p>
            <span class="status-dot"></span>
            Systems operational &nbsp;&mdash;&nbsp; scheduled maintenance in progress.
        </p>
    </div>

    <div class="divider"></div>

    <div class="actions">
        <a href="javascript:location.reload()" class="btn btn--primary">
            <i class="ri-refresh-line"></i>
            Check Again
        </a>
    </div>
@endsection

@section('nav-hint')
<div class="nav-hint">
    <span>Bethany House Admin</span>
    <i class="ri-arrow-right-s-line"></i>
    <span>503 Maintenance</span>
</div>
@endsection

@push('scripts')
<style>
    @keyframes spin {
        from { transform: rotate(0deg); }
        to   { transform: rotate(360deg); }
    }
</style>
@endpush