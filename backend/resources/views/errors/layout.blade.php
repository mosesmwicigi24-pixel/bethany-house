<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Error') - Bethany House</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">

    {{-- Icons --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/remixicon@4.3.0/fonts/remixicon.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        /* ── Reset ──────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── Theme tokens ───────────────────────────────────────── */
        :root {
            --primary:          #101F3C;
            --primary-50:       #F3F4F5;
            --primary-100:      #E7E9EC;
            --primary-200:      #C3C7CE;
            --primary-300:      #9FA5B1;
            --primary-400:      #586277;
            --primary-600:      #0E1C36;
            --primary-700:      #0A1324;

            --secondary:        #F7CA80;
            --secondary-100:    #FEFAF2;
            --secondary-200:    #FDF2DF;
            --secondary-300:    #FCEACC;
            --secondary-400:    #F9DAA6;
            --secondary-600:    #DEB673;
            --secondary-700:    #94794D;

            --danger:           #ef4444;
            --danger-50:        #fef2f2;
            --danger-100:       #fee2e2;
            --danger-600:       #dc2626;

            --warning:          #f59e0b;
            --warning-50:       #fffbeb;
            --warning-100:      #fef3c7;
            --warning-600:      #d97706;

            --success:          #22c55e;
            --success-50:       #f0fdf4;
            --success-100:      #dcfce7;
            --success-600:      #16a34a;

            --info:             #0ea5e9;
            --info-50:          #f0f9ff;
            --info-100:         #e0f2fe;
            --info-600:         #0284c7;
        }

        /* ── Base ───────────────────────────────────────────────── */
        html, body { height: 100%; }

        body {
            font-family: 'DM Sans', ui-sans-serif, system-ui, sans-serif;
            background-color: var(--primary-50);
            color: var(--primary);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Background grid pattern ────────────────────────────── */
        .bg-grid {
            position: fixed;
            inset: 0;
            z-index: 0;
            background-image:
                linear-gradient(to right, rgba(16,31,60,0.04) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(16,31,60,0.04) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        /* ── Gradient blob ──────────────────────────────────────── */
        .blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.18;
            pointer-events: none;
            z-index: 0;
        }
        .blob--1 {
            width: 600px; height: 600px;
            background: var(--secondary);
            top: -200px; right: -150px;
        }
        .blob--2 {
            width: 400px; height: 400px;
            background: var(--primary);
            bottom: -100px; left: -100px;
        }

        /* ── Layout ─────────────────────────────────────────────── */
        .page {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
            padding: 40px 24px;
            min-height: 100vh;
        }

        /* ── Card ───────────────────────────────────────────────── */
        .card {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(16,31,60,0.08);
            border-radius: 20px;
            box-shadow:
                0 1px 2px rgba(16,31,60,0.04),
                0 8px 24px rgba(16,31,60,0.08),
                0 32px 64px rgba(16,31,60,0.06);
            padding: 48px 40px;
            width: 100%;
            max-width: 520px;
            text-align: center;
        }

        @media (max-width: 480px) {
            .card { padding: 36px 24px; border-radius: 16px; }
        }

        /* ── Icon badge ─────────────────────────────────────────── */
        .icon-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 80px; height: 80px;
            border-radius: 24px;
            margin-bottom: 28px;
            position: relative;
        }
        .icon-wrap i {
            font-size: 36px;
            line-height: 1;
        }
        /* Subtle inner glow ring */
        .icon-wrap::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: 25px;
            padding: 1px;
            background: linear-gradient(135deg, rgba(255,255,255,0.8), rgba(255,255,255,0.1));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
        }

        /* Per-error colour variants */
        .icon-wrap--403 { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #b45309; }
        .icon-wrap--404 { background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #0369a1; }
        .icon-wrap--419 { background: linear-gradient(135deg, #ede9fe, #ddd6fe); color: #6d28d9; }
        .icon-wrap--429 { background: linear-gradient(135deg, #ffedd5, #fed7aa); color: #c2410c; }
        .icon-wrap--500 { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #b91c1c; }
        .icon-wrap--503 { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #15803d; }

        /* ── Code badge (the big number) ────────────────────────── */
        .code-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary);
            color: var(--secondary);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 4px 12px;
            border-radius: 99px;
            margin-bottom: 16px;
        }
        .code-badge i { font-size: 10px; }

        /* ── Typography ─────────────────────────────────────────── */
        h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.25;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }
        .subtitle {
            font-size: 15px;
            font-weight: 400;
            color: var(--primary-400);
            line-height: 1.6;
            margin-bottom: 32px;
            max-width: 380px;
            margin-left: auto;
            margin-right: auto;
        }

        /* ── Divider ─────────────────────────────────────────────── */
        .divider {
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(16,31,60,0.08), transparent);
            margin: 28px 0;
        }

        /* ── Action buttons ─────────────────────────────────────── */
        .actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn i { font-size: 15px; }

        .btn--primary {
            background: var(--primary);
            color: var(--secondary);
            box-shadow: 0 1px 2px rgba(16,31,60,0.2), 0 4px 12px rgba(16,31,60,0.15);
        }
        .btn--primary:hover {
            background: var(--primary-600);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(16,31,60,0.2), 0 8px 20px rgba(16,31,60,0.18);
        }
        .btn--primary:active { transform: translateY(0); }

        .btn--ghost {
            background: white;
            color: var(--primary-400);
            border: 1.5px solid rgba(16,31,60,0.12);
        }
        .btn--ghost:hover {
            background: var(--primary-50);
            color: var(--primary);
            border-color: rgba(16,31,60,0.2);
        }

        /* ── Detail box (for 500 technical info) ────────────────── */
        .detail-box {
            background: var(--primary-50);
            border: 1px solid rgba(16,31,60,0.08);
            border-radius: 10px;
            padding: 14px 16px;
            text-align: left;
            margin-bottom: 28px;
        }
        .detail-box p {
            font-size: 12px;
            color: var(--primary-400);
            font-family: 'DM Mono', 'Courier New', monospace;
            line-height: 1.7;
            word-break: break-word;
        }
        .detail-box strong {
            color: var(--primary-600);
            font-family: 'DM Sans', sans-serif;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            display: block;
            margin-bottom: 4px;
        }

        /* ── Countdown bar (429) ─────────────────────────────────── */
        .countdown-wrap {
            background: var(--primary-50);
            border: 1px solid rgba(16,31,60,0.08);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 28px;
        }
        .countdown-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--primary-300);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 8px;
        }
        .countdown-bar-track {
            height: 6px;
            background: var(--primary-100);
            border-radius: 99px;
            overflow: hidden;
        }
        .countdown-bar-fill {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(to right, var(--secondary-600), var(--secondary));
            animation: shrink 60s linear forwards;
        }
        @keyframes shrink {
            from { width: 100%; }
            to   { width: 0%; }
        }

        /* ── Breadcrumb / nav hint ───────────────────────────────── */
        .nav-hint {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 12px;
            color: var(--primary-300);
            margin-top: 24px;
        }
        .nav-hint a {
            color: var(--primary-400);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.15s;
        }
        .nav-hint a:hover { color: var(--primary); }
        .nav-hint i { font-size: 10px; }

        /* ── Brand mark ─────────────────────────────────────────── */
        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 36px;
        }
        .brand-dot {
            width: 8px; height: 8px;
            background: var(--secondary);
            border-radius: 50%;
        }
        .brand-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--primary-300);
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        /* ── Footer ─────────────────────────────────────────────── */
        .footer {
            position: relative;
            z-index: 1;
            text-align: center;
            padding: 20px 24px;
            font-size: 12px;
            color: var(--primary-300);
        }
        .footer a {
            color: var(--primary-300);
            text-decoration: none;
            font-weight: 500;
        }
        .footer a:hover { color: var(--primary); }
    </style>

    @stack('head')
</head>
<body>
    <div class="bg-grid"></div>
    <div class="blob blob--1"></div>
    <div class="blob blob--2"></div>

    <main class="page">
        <div class="brand">
            <div class="brand-dot"></div>
            <span class="brand-name">Bethany House</span>
            <div class="brand-dot"></div>
        </div>

        <div class="card">
            @yield('content')
        </div>

        @yield('nav-hint')
    </main>

    <footer class="footer">
        &copy; {{ date('Y') }} Bethany House &nbsp;&middot;&nbsp;
        <a href="{{ route('admin.dashboard') }}">Back to Admin</a>
    </footer>

    @stack('scripts')
</body>
</html>