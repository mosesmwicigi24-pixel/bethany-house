{{--
    The owner's morning briefing (owner, 2026-11-05: an executive dashboard,
    readable on an iPhone in seconds). Data: App\Console\Commands\SendAuditDigest.

    Email-safe by design: table layout, inline styles, no scripts, no external
    CSS. The <style> block only enhances (2×2 KPIs on a phone, dark-mode-safe
    links); Outlook and older clients get the same report without it.
    Every record is a two-column card (what / when), never a wide grid, so
    nothing is squeezed on a phone. Every link opens a page of the hub that
    asks for the owner's login; nothing in this email opens data by itself.
--}}
@php
    $navy = '#192B4D'; $gold = '#C9A45C'; $bg = '#F5F7FA'; $ink = '#1F2937'; $muted = '#6B7280'; $line = '#E6EAF0';
    $tones = [
        'green' => ['bg' => '#ECF7F0', 'bar' => '#2E8B57', 'ink' => '#1E5E3B'],
        'amber' => ['bg' => '#FFF6E5', 'bar' => '#D99A1E', 'ink' => '#7A4E00'],
        'red'   => ['bg' => '#FDECEC', 'bar' => '#C0392B', 'ink' => '#8A1F17'],
        'blue'  => ['bg' => '#EBF2FB', 'bar' => '#2F6DB5', 'ink' => '#1D4A80'],
    ];
    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $mono = "ui-monospace, SFMono-Regular, Menlo, Consolas, 'Courier New', monospace";

    $name = fn ($u) => $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->email ?? '—')) : 'System';
    $t    = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('H:i') : '—';
    $kes  = fn ($v) => 'KES ' . number_format((float) $v, 2);
    $hub  = fn (string $path = '') => $consoleUrl . $path;
    $ledger = fn ($d) => $consoleUrl . '/settings/downloads?view=all&open=' . urlencode((string) $d->uuid);

    $failed = ($integrity?->event ?? null) === 'audit_verification_failed';

    // A security event, read for a person: what happened, to which account, how serious.
    $event = function ($e) {
        $p = is_string($e->properties ?? null) ? (json_decode($e->properties, true) ?: []) : (array) ($e->properties ?? []);
        $account = $p['attempted_email'] ?? null;
        $account = $account && filter_var($account, FILTER_VALIDATE_EMAIL) ? $account : null;
        $reason  = isset($p['reason']) ? str_replace('_', ' ', (string) $p['reason']) : null;
        [$title, $tone, $badge] = match ($e->event) {
            'admin_login_failed'         => ['Login refused' . ($reason ? " — {$reason}" : ''), 'red', 'Refused'],
            'admin_login_2fa_failed'     => ['Two-step code refused', 'red', 'Refused'],
            'download_token_refused'     => ['Download link refused', 'amber', 'Blocked'],
            'audit_clear_refused'        => ['Attempt to clear the audit trail', 'red', 'Critical'],
            'download_owner_copy_failed' => ['Your copy of a download was not delivered', 'amber', 'Failed'],
            'audit_verification_failed'  => ['Audit trail failed its integrity check', 'red', 'Critical'],
            default                      => [(string) $e->description, 'blue', 'Event'],
        };
        return ['title' => $title, 'tone' => $tone, 'badge' => $badge, 'account' => $account,
                'detail' => $account ? null : (string) $e->description];
    };

    // What needs the owner, most serious first.
    $attention = [];
    if ($failed) {
        $attention[] = ['red', 'Audit trail failed its integrity check', 'A record was changed or removed since the last seal.', $hub('/settings/activity-logs'), 'Open the activity log'];
    }
    foreach ($imprest as $im) {
        if ($im['low']) {
            $attention[] = ['amber', "Below its alert level — {$im['name']}",
                $kes($im['balance']) . ' left of ' . $kes($im['float']) . ' (alert below ' . $kes($im['alert_level'] ?? 0) . ')' . ($im['custodian'] ? " · held by {$im['custodian']}" : ''),
                $hub('/expenses/imprest'), 'Open petty cash'];
        }
        foreach ($im['waiting'] as $w) {
            $attention[] = ['amber', "Top-up requested — {$im['name']}", $kes($w->requested_amount) . ' waiting for your decision', $hub('/expenses/imprest'), 'Decide'];
        }
        if ($im['unresolved']) {
            $attention[] = ['amber', "Rejected spends — {$im['name']}", $im['unresolved'] . ' waiting: cash returned or written off?', $hub('/expenses/imprest'), 'Resolve'];
        }
        if ($im['counts']) {
            $attention[] = ['amber', "Cash counts differ — {$im['name']}", $im['counts'] . ' differ from the books — approve or reject', $hub('/expenses/imprest'), 'Review'];
        }
    }
    if ($pending->count()) {
        $attention[] = ['amber', $pending->count() . ' download ' . ($pending->count() === 1 ? 'request' : 'requests') . ' waiting for you', 'Staff are waiting on your approval to download.', $hub('/settings/downloads'), 'Review approvals'];
    }
    $refused = $security->count();
    if ($refused) {
        $critical = $security->whereIn('event', ['audit_clear_refused', 'audit_verification_failed'])->count();
        $attention[] = [$critical ? 'red' : 'amber', $refused . ' security ' . ($refused === 1 ? 'event' : 'events') . ' yesterday',
            'Refused logins, codes or links — listed under Security below.', $hub('/settings/activity-logs'), 'Open the activity log'];
    }

    $summary = $failed
        ? 'The audit trail failed its integrity check. Open the activity log before anything else.'
        : (count($attention)
            ? count($attention) . ' ' . (count($attention) === 1 ? 'item needs' : 'items need') . ' your attention. Audit trail ' . ($integrity ? 'verified intact.' : 'not yet verified.')
            : 'Nothing needs your attention. Audit trail ' . ($integrity ? 'verified intact.' : 'not yet verified.'));

    $kpis = [
        ['n' => $downloads->count(), 'label' => 'Downloads',          'tone' => $downloads->count() ? 'blue' : null],
        ['n' => $exempt->count(),    'label' => 'Documents',          'tone' => null],
        ['n' => $pending->count(),   'label' => 'Waiting for you',    'tone' => $pending->count() ? 'amber' : null],
        ['n' => $security->count(),  'label' => 'Security events',    'tone' => $security->count() ? 'red' : null],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    {{-- iPhone turns times, IPs and numbers into links of its own; the report sets its own links. --}}
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="light" />
    <meta name="supported-color-schemes" content="light" />
    <title>Bethany Hub — {{ $day->format('l j F Y') }}</title>
    <!--[if mso]><style>table,td,div,p,a,span{font-family:Segoe UI,Arial,sans-serif !important;}</style><![endif]-->
    <style>
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
        img { border: 0; outline: none; text-decoration: none; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
        .kpi { display: inline-block; width: 25%; min-width: 130px; vertical-align: top; }
        @media only screen and (max-width: 520px) {
            .px { padding-left: 18px !important; padding-right: 18px !important; }
            .kpi { width: 50% !important; }
            .kpi-n { font-size: 26px !important; }
            .h1 { font-size: 22px !important; }
            .btn-full { display: block !important; text-align: center !important; }
            .hide-sm { display: none !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:{{ $bg }}; font-family:{{ $font }}; color:{{ $ink }};">
{{-- Inbox preview line --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:{{ $bg }};">{{ $summary }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }};">
<tr><td align="center" style="padding:24px 10px;">
<!--[if mso]><table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; background:#FFFFFF; border:1px solid {{ $line }}; border-radius:14px; overflow:hidden;">

    {{-- ── Header ─────────────────────────────────────────────────────── --}}
    <tr><td class="px" style="background:{{ $navy }}; padding:28px 32px 24px 32px; border-bottom:3px solid {{ $gold }};">
        <p style="margin:0; font-size:11px; letter-spacing:2px; text-transform:uppercase; color:{{ $gold }}; font-weight:700;">Bethany Hub &nbsp;·&nbsp; Morning briefing</p>
        <h1 class="h1" style="margin:8px 0 0 0; font-size:24px; line-height:1.25; color:#FFFFFF; font-weight:700;">{{ $day->format('l j F Y') }}</h1>
        <p style="margin:10px 0 0 0; font-size:14px; line-height:1.5; color:#D5DCE8;">{{ $summary }}</p>
    </td></tr>

    {{-- ── KPIs: four across, two by two on a phone ───────────────────── --}}
    <tr><td style="padding:16px 16px 4px 16px; font-size:0; text-align:center;">
        <!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><![endif]-->
        @foreach ($kpis as $k)
        <!--[if mso]><td width="25%" valign="top"><![endif]-->
        <div class="kpi" style="font-size:14px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:6px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }}; border-radius:10px; border-top:3px solid {{ $k['tone'] ? $tones[$k['tone']]['bar'] : $gold }};">
                    <tr><td style="padding:14px 8px 12px 8px; text-align:center;">
                        <div class="kpi-n" style="font-size:28px; line-height:1; font-weight:700; color:{{ $k['tone'] ? $tones[$k['tone']]['ink'] : $navy }};">{{ number_format($k['n']) }}</div>
                        <div style="margin-top:6px; font-size:11px; line-height:1.3; letter-spacing:.4px; text-transform:uppercase; color:{{ $muted }}; font-weight:600; white-space:nowrap;">{{ $k['label'] }}</div>
                    </td></tr>
                </table>
            </td></tr></table>
        </div>
        <!--[if mso]></td><![endif]-->
        @endforeach
        <!--[if mso]></tr></table><![endif]-->
    </td></tr>

    {{-- ── Audit trail status ─────────────────────────────────────────── --}}
    <tr><td class="px" style="padding:16px 32px 4px 32px;">
        @php $it = $failed ? $tones['red'] : ($integrity ? $tones['green'] : $tones['amber']); @endphp
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $it['bg'] }}; border-left:4px solid {{ $it['bar'] }}; border-radius:8px;">
            <tr><td style="padding:12px 14px; font-size:14px; line-height:1.5; color:{{ $it['ink'] }};">
                @if ($failed)
                    <b>Audit trail failed its integrity check</b> ({{ \Illuminate\Support\Carbon::parse($integrity->created_at)->format('j M H:i') }}). A record was changed or removed. <a href="{{ $hub('/settings/activity-logs') }}" style="color:{{ $it['ink'] }}; font-weight:700;">Open the activity log</a>
                @elseif ($integrity)
                    <b>✓ Audit trail verified intact</b> · {{ \Illuminate\Support\Carbon::parse($integrity->created_at)->format('j M H:i') }}<br>
                    <span style="font-size:13px;">{{ number_format($changes) }} changes and {{ number_format($staffCalls) }} staff calls recorded yesterday.</span>
                @else
                    <b>Not verified yet.</b> The first seal of the audit trail runs at 00:20.
                @endif
            </td></tr>
        </table>
        @unless ($enforcing)
            <p style="margin:10px 0 0 0; font-size:13px; line-height:1.5; color:{{ $muted }};">Download approval is <b style="color:{{ $ink }};">recording only</b>: nothing is held yet. Downloads marked "not held" would have needed your approval.</p>
        @endunless
    </td></tr>

    {{-- ── Needs your attention ───────────────────────────────────────── --}}
    @if (count($attention))
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        <h2 style="margin:0 0 10px 0; font-size:16px; color:{{ $navy }};">Needs your attention</h2>
        @foreach ($attention as [$tone, $title, $detail, $link, $action])
            @php $c = $tones[$tone]; @endphp
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:10px; background:{{ $c['bg'] }}; border-left:4px solid {{ $c['bar'] }}; border-radius:8px;">
                <tr>
                    <td style="padding:12px 14px; font-size:14px; line-height:1.45; color:{{ $c['ink'] }};">
                        <b>{{ $title }}</b><br>
                        <span style="font-size:13px;">{{ $detail }}</span>
                        <div style="margin-top:10px;">
                            <a href="{{ $link }}" style="display:inline-block; background:{{ $navy }}; color:#FFFFFF; text-decoration:none; font-size:13px; font-weight:600; padding:9px 16px; border-radius:7px;">{{ $action }} &rarr;</a>
                        </div>
                    </td>
                </tr>
            </table>
        @endforeach
    </td></tr>
    @endif

    {{-- ── Petty cash ─────────────────────────────────────────────────── --}}
    @foreach ($imprest as $im)
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        <h2 style="margin:0 0 10px 0; font-size:16px; color:{{ $navy }};">{{ $im['name'] }}</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $line }}; border-radius:10px;">
            <tr><td style="padding:14px 16px; border-bottom:1px solid {{ $line }};">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="font-size:12px; color:{{ $muted }}; text-transform:uppercase; letter-spacing:.4px; font-weight:600;">Balance</td>
                    @if ($im['low'])<td align="right"><span style="display:inline-block; background:{{ $tones['amber']['bg'] }}; color:{{ $tones['amber']['ink'] }}; font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap;">● Below alert level</span></td>@endif
                </tr></table>
                <div style="margin-top:4px; font-size:24px; font-weight:700; color:{{ $im['low'] ? $tones['amber']['ink'] : $navy }}; white-space:nowrap;">{{ $kes($im['balance']) }}</div>
                <div style="margin-top:2px; font-size:13px; color:{{ $muted }};">of {{ $kes($im['float']) }} float · alert below {{ $kes($im['alert_level'] ?? 0) }}</div>
            </td></tr>
            @php
                $rows = [['Held by', $im['custodian'] ?: '—', null], ['Spent yesterday', $kes($im['spent']), null]];
                foreach ($im['received'] as $r) {
                    $rows[] = ['Top-up received', $kes($r->received_amount), (float) $r->received_amount !== (float) $r->sent_amount ? 'You sent ' . $kes($r->sent_amount) : null];
                }
                foreach ($im['waiting'] as $w)    { $rows[] = ['Top-up requested', $kes($w->requested_amount), 'Waiting for your decision']; }
                foreach ($im['in_transit'] as $x) { $rows[] = ['Sent, not yet confirmed', $kes($x->sent_amount), 'Since ' . optional($x->sent_at)->format('j M H:i')]; }
                if ($im['unresolved']) { $rows[] = ['Rejected spends', $im['unresolved'] . ' waiting', 'Cash returned or written off?']; }
                if ($im['counts'])     { $rows[] = ['Cash counts', $im['counts'] . ' differ', 'Approve or reject']; }
            @endphp
            @foreach ($rows as $i => [$label, $value, $note])
            <tr><td style="padding:10px 16px; {{ $i < count($rows) - 1 ? 'border-bottom:1px solid ' . $line . ';' : '' }} {{ $i % 2 ? 'background:#FAFBFD;' : '' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="font-size:14px; color:{{ $muted }}; vertical-align:top;">{{ $label }}</td>
                    <td align="right" style="font-size:14px; font-weight:600; color:{{ $ink }}; vertical-align:top; white-space:nowrap; padding-left:12px;">{{ $value }}</td>
                </tr>
                @if ($note)<tr><td colspan="2" style="padding-top:2px; font-size:12px; color:{{ str_starts_with($note, 'You sent') ? $tones['red']['ink'] : $muted }}; text-align:right;">{{ $note }}</td></tr>@endif
                </table>
            </td></tr>
            @endforeach
        </table>
        <p style="margin:10px 0 0 0;"><a href="{{ $hub('/expenses/imprest') }}" style="font-size:13px; font-weight:600; color:{{ $navy }};">Open petty cash &rarr;</a></p>
    </td></tr>
    @endforeach

    {{-- A section of record cards: a heading, then each record as what (left) / when (right). --}}
    @php
        $section = function (string $title, ?string $count = null) use ($navy, $muted) {
            return '<h2 style="margin:0 0 10px 0; font-size:16px; color:' . $navy . ';">' . e($title)
                . ($count !== null ? ' <span style="font-size:13px; font-weight:600; color:' . $muted . ';">' . e($count) . '</span>' : '') . '</h2>';
        };
        $badge = fn (string $tone, string $text) => '<span style="display:inline-block; background:' . $tones[$tone]['bg'] . '; color:' . $tones[$tone]['ink']
            . '; font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; white-space:nowrap;">● ' . e($text) . '</span>';
    @endphp

    {{-- ── Waiting for your decision ──────────────────────────────────── --}}
    @if ($pending->count())
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        {!! $section('Waiting for your decision', (string) $pending->count()) !!}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $line }}; border-radius:10px;">
        @foreach ($pending as $i => $p)
            <tr><td style="padding:12px 16px; {{ !$loop->last ? 'border-bottom:1px solid ' . $line . ';' : '' }} {{ $i % 2 ? 'background:#FAFBFD;' : '' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="vertical-align:top; font-size:14px; line-height:1.45;">
                        <b style="color:{{ $ink }};">{{ $name($p->user) }}</b> &nbsp;{!! $badge('amber', 'Pending') !!}<br>
                        <span style="color:{{ $ink }};">{{ $p->label }}</span>
                        @if ($p->reason)<br><span style="font-size:13px; color:{{ $muted }};">“{{ $p->reason }}”</span>@endif
                    </td>
                    <td align="right" style="vertical-align:top; font-size:12px; color:{{ $muted }}; white-space:nowrap; padding-left:12px;">{{ $p->updated_at?->format('j M H:i') }}</td>
                </tr></table>
            </td></tr>
        @endforeach
        </table>
        <div style="margin-top:12px;"><a class="btn-full" href="{{ $hub('/settings/downloads') }}" style="display:inline-block; background:{{ $navy }}; color:#FFFFFF; text-decoration:none; font-size:14px; font-weight:600; padding:11px 20px; border-radius:8px; text-align:center;">Review approvals &rarr;</a></div>
    </td></tr>
    @endif

    {{-- ── Downloads ──────────────────────────────────────────────────── --}}
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        {!! $section('Downloads', $downloads->count() ? (string) $downloads->count() : null) !!}
        @if ($downloads->isEmpty())
            <p style="margin:0; font-size:14px; color:{{ $muted }};">None yesterday.</p>
        @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $line }}; border-radius:10px;">
        @foreach ($downloads as $i => $d)
            @php
                [$st, $sl] = $d->decider ? ['green', 'Approved by ' . $name($d->decider)] : ($d->shadow ? ['blue', 'Not held'] : [in_array($d->status, ['denied', 'failed', 'expired'], true) ? 'red' : 'blue', ucfirst($d->status)]);
            @endphp
            <tr><td style="padding:12px 16px; {{ !$loop->last ? 'border-bottom:1px solid ' . $line . ';' : '' }} {{ $i % 2 ? 'background:#FAFBFD;' : '' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="vertical-align:top; font-size:14px; line-height:1.45;">
                        <a href="{{ $ledger($d) }}" style="color:{{ $navy }}; font-weight:600; text-decoration:underline; word-break:break-word;">{{ $d->label }}</a><br>
                        <span style="color:{{ $ink }};">{{ $name($d->user) }}</span> &nbsp;{!! $badge($st, $sl) !!}
                        @if ($d->export_id)<br><span style="font-family:{{ $mono }}; font-size:11px; color:{{ $muted }}; word-break:break-all;">Export {{ $d->export_id }}</span>@endif
                    </td>
                    <td align="right" style="vertical-align:top; font-size:13px; color:{{ $muted }}; white-space:nowrap; padding-left:12px;">{{ $t($d->downloaded_at) }}</td>
                </tr></table>
            </td></tr>
        @endforeach
        </table>
        @endif
    </td></tr>

    {{-- ── Decisions ──────────────────────────────────────────────────── --}}
    @if ($decisions->count())
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        {!! $section('Decisions', (string) $decisions->count()) !!}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $line }}; border-radius:10px;">
        @foreach ($decisions as $i => $d)
            @php $denied = $d->status === 'denied'; @endphp
            <tr><td style="padding:12px 16px; {{ !$loop->last ? 'border-bottom:1px solid ' . $line . ';' : '' }} {{ $i % 2 ? 'background:#FAFBFD;' : '' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="vertical-align:top; font-size:14px; line-height:1.45;">
                        <b style="color:{{ $ink }};">{{ $name($d->user) }}</b> — {{ $d->label }}<br>
                        {!! $badge($denied ? 'red' : 'green', $denied ? 'Denied' : 'Approved') !!}
                        <span style="font-size:13px; color:{{ $muted }};">by {{ $name($d->decider) }}{{ $d->decision_note ? ': ' . $d->decision_note : '' }}</span>
                    </td>
                    <td align="right" style="vertical-align:top; font-size:13px; color:{{ $muted }}; white-space:nowrap; padding-left:12px;">{{ $t($d->decided_at) }}</td>
                </tr></table>
            </td></tr>
        @endforeach
        </table>
    </td></tr>
    @endif

    {{-- ── Security ───────────────────────────────────────────────────── --}}
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        {!! $section('Security', $security->count() ? (string) $security->count() : null) !!}
        @if ($security->isEmpty())
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $tones['green']['bg'] }}; border-left:4px solid {{ $tones['green']['bar'] }}; border-radius:8px;">
                <tr><td style="padding:12px 14px; font-size:14px; color:{{ $tones['green']['ink'] }};">Nothing unusual.</td></tr>
            </table>
        @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $line }}; border-radius:10px;">
        @foreach ($security as $i => $e)
            @php $ev = $event($e); @endphp
            <tr><td style="padding:12px 16px; border-left:3px solid {{ $tones[$ev['tone']]['bar'] }}; {{ !$loop->last ? 'border-bottom:1px solid ' . $line . ';' : '' }} {{ $i % 2 ? 'background:#FAFBFD;' : '' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="vertical-align:top; font-size:14px; line-height:1.45;">
                        <b style="color:{{ $ink }};">{{ $ev['title'] }}</b> &nbsp;{!! $badge($ev['tone'], $ev['badge']) !!}<br>
                        @if ($ev['account'])
                            <span style="font-size:13px; color:{{ $muted }};">Account</span>
                            <a href="mailto:{{ $ev['account'] }}" style="font-size:13px; color:{{ $navy }}; word-break:break-all;">{{ $ev['account'] }}</a><br>
                        @elseif ($ev['detail'] && $ev['detail'] !== $ev['title'])
                            <span style="font-size:13px; color:{{ $muted }}; word-break:break-word;">{{ $ev['detail'] }}</span><br>
                        @endif
                        <span style="font-size:13px; color:{{ $muted }};">From</span>
                        <span style="font-family:{{ $mono }}; font-size:12px; color:{{ $ink }};">{{ $e->ip_address ?? 'unknown' }}</span>
                    </td>
                    <td align="right" style="vertical-align:top; font-size:13px; font-weight:600; color:{{ $ink }}; white-space:nowrap; padding-left:12px;">{{ $t($e->created_at) }}</td>
                </tr></table>
            </td></tr>
        @endforeach
        </table>
        <p style="margin:10px 0 0 0;"><a href="{{ $hub('/settings/activity-logs') }}" style="font-size:13px; font-weight:600; color:{{ $navy }};">Open the activity log &rarr;</a></p>
        @endif
    </td></tr>

    {{-- ── Bulk reads ─────────────────────────────────────────────────── --}}
    @if ($bulkReads->count())
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        {!! $section('Bulk reads') !!}
        <p style="margin:-4px 0 10px 0; font-size:13px; color:{{ $muted }};">Staff calls that returned 50 or more records.</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $line }}; border-radius:10px;">
        @foreach ($bulkReads as $i => $b)
            <tr><td style="padding:12px 16px; {{ !$loop->last ? 'border-bottom:1px solid ' . $line . ';' : '' }} {{ $i % 2 ? 'background:#FAFBFD;' : '' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="vertical-align:top; font-size:14px; line-height:1.45;">
                        <b style="color:{{ $ink }};">{{ trim(($b->first_name ?? '') . ' ' . ($b->last_name ?? '')) ?: 'user #' . $b->user_id }}</b><br>
                        <span style="font-family:{{ $mono }}; font-size:12px; color:{{ $muted }}; word-break:break-all;">{{ $b->path }}</span>
                    </td>
                    <td align="right" style="vertical-align:top; white-space:nowrap; padding-left:12px;">
                        <div style="font-size:15px; font-weight:700; color:{{ $navy }};">{{ number_format($b->records) }}</div>
                        <div style="font-size:11px; color:{{ $muted }};">records · {{ $b->calls }} {{ $b->calls == 1 ? 'call' : 'calls' }}</div>
                    </td>
                </tr></table>
            </td></tr>
        @endforeach
        </table>
    </td></tr>
    @endif

    {{-- ── Sensitive changes ──────────────────────────────────────────── --}}
    @if ($sensitive->count())
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        {!! $section('Sensitive changes', (string) $sensitive->count()) !!}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $line }}; border-radius:10px;">
        @foreach ($sensitive as $i => $e)
            <tr><td style="padding:12px 16px; {{ !$loop->last ? 'border-bottom:1px solid ' . $line . ';' : '' }} {{ $i % 2 ? 'background:#FAFBFD;' : '' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="vertical-align:top; font-size:14px; line-height:1.45;">
                        <b style="color:{{ $ink }};">{{ trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')) ?: 'System' }}</b><br>
                        <span style="color:{{ $ink }}; word-break:break-word;">{{ $e->description }}</span>
                    </td>
                    <td align="right" style="vertical-align:top; font-size:13px; color:{{ $muted }}; white-space:nowrap; padding-left:12px;">{{ $t($e->created_at) }}</td>
                </tr></table>
            </td></tr>
        @endforeach
        </table>
        <p style="margin:10px 0 0 0;"><a href="{{ $hub('/settings/activity-logs') }}" style="font-size:13px; font-weight:600; color:{{ $navy }};">Open the activity log &rarr;</a></p>
    </td></tr>
    @endif

    {{-- ── Customer documents ─────────────────────────────────────────── --}}
    @if ($exempt->count())
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        {!! $section('Invoices, quotations and receipts', (string) $exempt->count()) !!}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid {{ $line }}; border-radius:10px;">
        @foreach ($exempt as $i => $d)
            <tr><td style="padding:12px 16px; {{ !$loop->last ? 'border-bottom:1px solid ' . $line . ';' : '' }} {{ $i % 2 ? 'background:#FAFBFD;' : '' }}">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="vertical-align:top; font-size:14px; line-height:1.45;">
                        <a href="{{ $ledger($d) }}" style="color:{{ $navy }}; font-weight:600; text-decoration:underline; word-break:break-all;">{{ $d->file_name ?? $d->label }}</a><br>
                        <span style="font-size:13px; color:{{ $muted }};">{{ $name($d->user) }}</span>
                    </td>
                    <td align="right" style="vertical-align:top; font-size:13px; color:{{ $muted }}; white-space:nowrap; padding-left:12px;">{{ $t($d->downloaded_at) }}</td>
                </tr></table>
            </td></tr>
        @endforeach
        </table>
    </td></tr>
    @endif

    {{-- ── Seal fingerprints ──────────────────────────────────────────── --}}
    <tr><td class="px" style="padding:22px 32px 8px 32px;">
        {!! $section('Seal fingerprints') !!}
        <p style="margin:-4px 0 10px 0; font-size:13px; line-height:1.5; color:{{ $muted }};">Kept here, off the server. If the trail is ever rewritten, these will not match.</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }}; border-radius:10px;">
        @foreach ($seals as $table => $s)
            <tr><td style="padding:10px 14px; {{ !$loop->last ? 'border-bottom:1px solid ' . $line . ';' : '' }}">
                <div style="font-size:12px; font-weight:700; color:{{ $navy }};">{{ $table }} <span style="font-weight:400; color:{{ $muted }};">{{ $s ? 'to #' . number_format($s->last_id) : '' }}</span></div>
                <div style="margin-top:3px; font-family:{{ $mono }}; font-size:11px; line-height:1.5; color:{{ $ink }}; word-break:break-all;">{{ $s ? $s->hash : 'not yet sealed' }}</div>
            </td></tr>
        @endforeach
        </table>
    </td></tr>

    {{-- ── Footer ─────────────────────────────────────────────────────── --}}
    <tr><td class="px" align="center" style="padding:24px 32px 28px 32px;">
        <a class="btn-full" href="{{ $hub() }}" style="display:inline-block; background:{{ $navy }}; color:#FFFFFF; text-decoration:none; font-size:15px; font-weight:700; padding:13px 28px; border-radius:8px; border-bottom:3px solid {{ $gold }};">Open Bethany Hub</a>
        <p style="margin:18px 0 0 0; font-size:12px; line-height:1.6; color:{{ $muted }};">
            Sent privately to the owner of Bethany Hub every morning at 06:30.<br>Your own actions are logged but not listed here.<br>
            <span style="color:{{ $gold }}; font-weight:700; letter-spacing:1px;">BETHANY HOUSE CREATIONS LIMITED</span>
        </p>
    </td></tr>

</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
