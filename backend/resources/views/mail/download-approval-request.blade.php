<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Download approval</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #18181b; }
        .wrapper { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .header { background: #162b4d; padding: 22px 32px; }
        .header h1 { color: #fff; font-size: 17px; font-weight: 700; }
        .header p { color: #c7d2e3; font-size: 13px; margin-top: 4px; }
        .section { padding: 20px 32px; border-bottom: 1px solid #f4f4f5; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        td { padding: 6px 0; vertical-align: top; }
        td.k { width: 130px; color: #71717a; }
        .reason { background: #f4f4f5; border-radius: 8px; padding: 10px 12px; font-size: 13px; line-height: 1.5; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
        .btn { display: inline-block; background: #162b4d; color: #fff !important; text-decoration: none; padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; }
        .foot { padding: 16px 32px; font-size: 11px; color: #a1a1aa; }
    </style>
</head>
<body>
@php
    $who = trim(($dr->user?->first_name ?? '') . ' ' . ($dr->user?->last_name ?? '')) ?: 'Someone';
    $filters = collect((array) $dr->payload)->map(fn ($v, $k) => $k . ': ' . (is_array($v) ? json_encode($v) : $v))->implode(' · ');
@endphp
<div class="wrapper">
    <div class="header">
        <h1>{{ $who }} wants to download {{ $dr->label }}</h1>
        <p>{{ $dr->updated_at?->timezone(config('app.timezone'))->format('D j M Y, H:i') }} · waiting for your approval</p>
    </div>
    <div class="section">
        <p class="reason">“{{ $dr->reason }}”</p>
    </div>
    <div class="section">
        <table>
            <tr><td class="k">Who</td><td>{{ $who }}@if ($dr->user?->email) <span class="mono">({{ $dr->user->email }})</span>@endif</td></tr>
            <tr><td class="k">What</td><td>{{ $dr->label }}</td></tr>
            @if ($filters !== '')<tr><td class="k">Filters</td><td class="mono">{{ $filters }}</td></tr>@endif
        </table>
    </div>
    <div class="section">
        <a class="btn" href="{{ $url }}">Approve or deny in Bethany Hub</a>
        <p style="font-size:12px;color:#71717a;margin-top:10px">Requests expire after 24 hours. Nothing leaves until you decide.</p>
    </div>
    <div class="foot">Sent privately to the owner of Bethany Hub.</div>
</div>
</body>
</html>
