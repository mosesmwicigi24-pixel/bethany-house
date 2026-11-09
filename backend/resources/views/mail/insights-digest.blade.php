{{--
    The 07:30 morning brief (owner, 2026-11-05: the same executive look as the
    06:30 briefing). Data: App\Console\Commands\SendInsightsDigest —
    MetricEngine::attention() and the Engine Room. Every figure is the one the
    plain-text brief already carried; nothing is computed here.

    Email-safe: table layout, inline styles, no scripts. Each finding links to
    the hub page MetricEngine names for it (`link`); the hub asks for a login
    and applies the reader's own permissions.
--}}
@php
    $navy = '#192B4D'; $gold = '#C9A45C'; $bg = '#F5F7FA'; $ink = '#1F2937'; $muted = '#6B7280'; $line = '#E6EAF0';
    $tones = [
        'high'  => ['bg' => '#FDECEC', 'bar' => '#C0392B', 'ink' => '#8A1F17', 'pill' => 'Urgent'],
        'watch' => ['bg' => '#FFF6E5', 'bar' => '#D99A1E', 'ink' => '#7A4E00', 'pill' => 'Watch'],
    ];
    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $hub  = fn (string $path = '') => $consoleUrl . (str_starts_with($path, '/') ? $path : '');

    $urgent = count(array_filter($items, fn ($i) => ($i['severity'] ?? '') === 'high'));
    $watch  = count($items) - $urgent;
    $summary = count($items) . ' ' . (count($items) === 1 ? 'item needs' : 'items need') . ' your attention'
        . ($urgent ? " — {$urgent} urgent." : '.');
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="light" />
    <meta name="supported-color-schemes" content="light" />
    <title>Morning brief — {{ $day->format('l j F Y') }}</title>
    <!--[if mso]><style>table,td,div,p,a,span{font-family:Segoe UI,Arial,sans-serif !important;}</style><![endif]-->
    <style>
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
        .tile { display: inline-block; width: 33.33%; min-width: 150px; vertical-align: top; }
        @media only screen and (max-width: 520px) {
            .px { padding-left: 18px !important; padding-right: 18px !important; }
            .tile { width: 50% !important; min-width: 0 !important; }
            .kpi { width: 50% !important; min-width: 0 !important; }
            .h1 { font-size: 22px !important; }
            .btn-full { display: block !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:{{ $bg }}; font-family:{{ $font }}; color:{{ $ink }};">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:{{ $bg }};">{{ $summary }} {{ $items[0]['title'] ?? '' }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }};">
<tr><td align="center" style="padding:24px 10px;">
<!--[if mso]><table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; background:#FFFFFF; border:1px solid {{ $line }}; border-radius:14px; overflow:hidden;">

    {{-- ── Header ─────────────────────────────────────────────────────── --}}
    <tr><td class="px" style="background:{{ $navy }}; padding:28px 32px 24px 32px; border-bottom:3px solid {{ $gold }};">
        <p style="margin:0; font-size:11px; letter-spacing:2px; text-transform:uppercase; color:{{ $gold }}; font-weight:700;">Bethany Hub &nbsp;·&nbsp; Morning brief</p>
        <h1 class="h1" style="margin:8px 0 0 0; font-size:24px; line-height:1.25; color:#FFFFFF; font-weight:700;">{{ $day->format('l j F Y') }}</h1>
        <p style="margin:10px 0 0 0; font-size:14px; line-height:1.5; color:#D5DCE8;">Good morning. {{ $summary }}</p>
    </td></tr>

    {{-- ── KPIs: four across, two by two on a phone ───────────────────── --}}
    @php
        $tileValue = fn (string $label) => collect($engineRoom)->firstWhere('label', $label)['value'] ?? null;
        $kpis = array_values(array_filter([
            ['n' => (string) $urgent, 'label' => 'Urgent',   'color' => $urgent ? $tones['high'] : null],
            ['n' => (string) $watch,  'label' => 'To watch', 'color' => $watch ? $tones['watch'] : null],
            ($v = $tileValue('On the table')) ? ['n' => $v, 'label' => 'On the table', 'color' => null] : null,
            ($v = $tileValue('At risk'))      ? ['n' => $v, 'label' => 'At risk',      'color' => null] : null,
        ]));
        $kpiWidth = count($kpis) === 4 ? '25%' : (count($kpis) === 3 ? '33.33%' : '50%');
    @endphp
    <tr><td style="padding:16px 16px 4px 16px; font-size:0; text-align:center;">
        <!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><![endif]-->
        @foreach ($kpis as $k)
        <!--[if mso]><td valign="top"><![endif]-->
        <div class="kpi" style="display:inline-block; width:{{ $kpiWidth }}; min-width:130px; vertical-align:top; font-size:14px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:6px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }}; border-radius:10px; border-top:3px solid {{ $k['color']['bar'] ?? $gold }};">
                    <tr><td style="padding:14px 8px 12px 8px; text-align:center;">
                        <div class="kpi-n" style="font-size:{{ strlen($k['n']) > 6 ? '18px' : '28px' }}; height:32px; line-height:32px; font-weight:700; color:{{ $k['color']['ink'] ?? $navy }}; white-space:nowrap;">{{ $k['n'] }}</div>
                        <div style="margin-top:6px; font-size:11px; letter-spacing:.4px; text-transform:uppercase; color:{{ $muted }}; font-weight:600; white-space:nowrap;">{{ $k['label'] }}</div>
                    </td></tr>
                </table>
            </td></tr></table>
        </div>
        <!--[if mso]></td><![endif]-->
        @endforeach
        <!--[if mso]></tr></table><![endif]-->
    </td></tr>

    {{-- ── What needs you, urgent first (MetricEngine's own order) ─────── --}}
    <tr><td class="px" style="padding:22px 32px 4px 32px;">
        <h2 style="margin:0 0 10px 0; font-size:16px; color:{{ $navy }};">What needs you today</h2>
        @foreach ($items as $n => $item)
            @php
                $tone = $tones[($item['severity'] ?? '') === 'high' ? 'high' : 'watch'];
                $link = $item['link'] ?? null;
                $label = $item['actions'][0]['label'] ?? 'Open in Bethany Hub';
            @endphp
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:10px; border:1px solid {{ $line }}; border-left:4px solid {{ $tone['bar'] }}; border-radius:8px;">
                <tr><td style="padding:13px 14px; font-size:14px; line-height:1.45;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                        <td style="vertical-align:top;">
                            <span style="display:inline-block; background:{{ $tone['bg'] }}; color:{{ $tone['ink'] }}; font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; white-space:nowrap;">● {{ $tone['pill'] }}</span>
                        </td>
                        <td align="right" style="vertical-align:top; font-size:12px; color:{{ $muted }}; white-space:nowrap;">{{ $n + 1 }} of {{ count($items) }}</td>
                    </tr></table>
                    <div style="margin-top:6px; font-size:15px; font-weight:700; color:{{ $ink }}; word-break:break-word;">{{ $item['title'] }}</div>
                    <div style="margin-top:3px; font-size:13px; color:{{ $muted }}; word-break:break-word;">{{ $item['detail'] }}</div>
                    @if ($link)
                        <div style="margin-top:10px;">
                            <a href="{{ $hub($link) }}" style="display:inline-block; background:{{ $navy }}; color:#FFFFFF; text-decoration:none; font-size:13px; font-weight:600; padding:8px 14px; border-radius:7px;">{{ $label }} &rarr;</a>
                        </div>
                    @endif
                </td></tr>
            </table>
        @endforeach
    </td></tr>

    {{-- ── Engine room: one headline number per revenue engine ────────── --}}
    @if (count($engineRoom))
    <tr><td class="px" style="padding:18px 32px 4px 32px;">
        <h2 style="margin:0 0 4px 0; font-size:16px; color:{{ $navy }};">Engine room</h2>
        <p style="margin:0 0 10px 0; font-size:13px; color:{{ $muted }};">One headline number per revenue engine. Tap one to see the rows behind it.</p>
    </td></tr>
    <tr><td style="padding:0 26px 4px 26px; font-size:0;">
        <!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><![endif]-->
        @foreach ($engineRoom as $i => $t)
        <!--[if mso]>@if ($i > 0 && $i % 3 === 0)</tr><tr>@endif<td width="33%" valign="top"><![endif]-->
        <div class="tile" style="font-size:14px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:6px;">
                <a href="{{ $hub($t['link']) }}" style="display:block; text-decoration:none; color:{{ $ink }};">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $bg }}; border-radius:10px; border-top:3px solid {{ $gold }};">
                    <tr><td style="padding:12px 12px;">
                        <div style="font-size:12px; color:{{ $muted }}; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $t['icon'] }} {{ $t['label'] }}</div>
                        <div style="margin-top:4px; font-size:17px; font-weight:700; color:{{ $navy }}; white-space:nowrap;">{{ $t['value'] }}<span style="font-size:12px; font-weight:600; color:{{ $muted }};">{{ $t['suffix'] }}</span></div>
                    </td></tr>
                </table>
                </a>
            </td></tr></table>
        </div>
        <!--[if mso]></td><![endif]-->
        @endforeach
        <!--[if mso]></tr></table><![endif]-->
    </td></tr>
    @endif

    {{-- ── Footer ─────────────────────────────────────────────────────── --}}
    <tr><td class="px" align="center" style="padding:22px 32px 28px 32px;">
        <p style="margin:0 0 16px 0; font-size:13px; line-height:1.5; color:{{ $muted }};">Every number above is computed from live records. Open Reports to drill into the rows behind it.</p>
        <a class="btn-full" href="{{ $hub('/reports') }}" style="display:inline-block; background:{{ $navy }}; color:#FFFFFF; text-decoration:none; font-size:15px; font-weight:700; padding:13px 28px; border-radius:8px; border-bottom:3px solid {{ $gold }};">Open Reports</a>
        <p style="margin:18px 0 0 0; font-size:12px; line-height:1.6; color:{{ $muted }};">
            Sent every morning at 07:30 to the end-of-day recipients set in Bethany Hub.<br>
            <span style="color:{{ $gold }}; font-weight:700; letter-spacing:1px;">BETHANY HOUSE CREATIONS LIMITED</span>
        </p>
    </td></tr>

</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
