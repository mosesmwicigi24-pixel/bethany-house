@extends('errors.layout')

@section('title', '429 - Too Many Requests')

@push('head')
<style>
    /* Auto-reload countdown number */
    .countdown-number {
        font-size: 32px;
        font-weight: 700;
        color: var(--primary);
        letter-spacing: -0.02em;
        line-height: 1;
        margin-bottom: 4px;
    }
    .countdown-unit {
        font-size: 11px;
        font-weight: 600;
        color: var(--primary-300);
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }
</style>
@endpush

@section('content')
    {{-- Code badge --}}
    <div class="code-badge">
        <i class="ri-speed-up-line"></i>
        429
    </div>

    {{-- Icon --}}
    <div class="icon-wrap icon-wrap--429">
        <i class="ri-rest-time-line"></i>
    </div>

    {{-- Copy --}}
    <h1>Slow Down a Little</h1>
    <p class="subtitle">
        You've made too many requests in a short period. This limit keeps the system
        stable for everyone. The page will reload automatically when you're ready.
    </p>

    {{-- Countdown --}}
    <div class="countdown-wrap">
        <div class="countdown-label">Auto-retry in</div>
        <div class="countdown-number" id="countdown">60</div>
        <div class="countdown-unit">seconds</div>
        <div style="margin-top:12px">
            <div class="countdown-bar-track">
                <div class="countdown-bar-fill" id="bar"></div>
            </div>
        </div>
    </div>

    <div class="divider"></div>

    <div class="actions">
        <a href="javascript:history.back()" class="btn btn--ghost">
            <i class="ri-arrow-left-line"></i>
            Go Back
        </a>
        <a href="javascript:location.reload()" class="btn btn--primary">
            <i class="ri-refresh-line"></i>
            Retry Now
        </a>
    </div>
@endsection

@section('nav-hint')
<div class="nav-hint">
    <a href="{{ route('admin.dashboard') }}">Dashboard</a>
    <i class="ri-arrow-right-s-line"></i>
    <span>429 Rate Limited</span>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        let remaining = 60;
        const el = document.getElementById('countdown');

        const interval = setInterval(function () {
            remaining--;
            if (el) el.textContent = remaining;

            if (remaining <= 0) {
                clearInterval(interval);
                location.reload();
            }
        }, 1000);
    })();
</script>
@endpush