<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Download copy</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #18181b; }
        .wrapper { max-width: 640px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .header { background: #162b4d; padding: 24px 32px; }
        .header h1 { color: #fff; font-size: 17px; font-weight: 700; }
        .header p { color: #c7d2e3; font-size: 13px; margin-top: 4px; }
        .section { padding: 20px 32px; border-bottom: 1px solid #f4f4f5; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        td { padding: 6px 0; vertical-align: top; }
        td.k { width: 150px; color: #71717a; }
        td.v { color: #18181b; word-break: break-word; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
        .note { font-size: 12px; color: #52525b; line-height: 1.5; }
        .warn { background: #fef3c7; color: #92400e; border-radius: 8px; padding: 10px 12px; font-size: 12px; }
        .danger { background: #fee2e2; color: #991b1b; border-radius: 8px; padding: 10px 12px; font-size: 12px; font-weight: 600; }
        .btn { display: inline-block; background: #162b4d; color: #fff !important; text-decoration: none; padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; }
        .foot { padding: 16px 32px; font-size: 11px; color: #a1a1aa; }
    </style>
</head>
<body>
@php
    $who = trim(($dr->user?->first_name ?? '') . ' ' . ($dr->user?->last_name ?? '')) ?: 'Unknown user';
    $approvedBy = $dr->decider ? trim($dr->decider->first_name . ' ' . $dr->decider->last_name) : null;
    $filters = collect((array) $dr->payload)->map(fn ($v, $k) => $k . ': ' . (is_array($v) ? json_encode($v) : $v))->implode(' · ');
    $size = $dr->file_bytes ? ($dr->file_bytes >= 1048576 ? number_format($dr->file_bytes / 1048576, 1) . ' MB' : number_format($dr->file_bytes / 1024, 0) . ' KB') : '—';
@endphp
<div class="wrapper">
    <div class="header">
        <h1>{{ $dr->label }}</h1>
        <p>Downloaded by {{ $who }} · {{ optional($dr->downloaded_at)->timezone(config('app.timezone'))->format('D j M Y, H:i') }}</p>
    </div>

    @if ($never)
        <div class="section"><div class="danger">A full database backup left the hub. It is not attached — a database belongs on the server, not in a mailbox.</div></div>
    @elseif ($dr->shadow)
        <div class="section"><div class="warn">Download approval was not yet switched on, so this was not held. It is recorded as one that would have needed your approval.</div></div>
    @endif

    <div class="section">
        <table>
            <tr><td class="k">Who</td><td class="v">{{ $who }}@if ($dr->user?->email) <span class="mono">({{ $dr->user->email }})</span>@endif</td></tr>
            <tr><td class="k">Approval</td><td class="v">
                @if ($approvedBy) Approved by {{ $approvedBy }}{{ $dr->decided_at ? ' on ' . $dr->decided_at->timezone(config('app.timezone'))->format('j M, H:i') : '' }}
                @elseif ($dr->shadow) Not held (approval not switched on)
                @else {{ ucfirst($dr->status) }} @endif
            </td></tr>
            @if ($dr->reason)<tr><td class="k">Reason given</td><td class="v">{{ $dr->reason }}</td></tr>@endif
            @if ($dr->decision_note)<tr><td class="k">Approver's note</td><td class="v">{{ $dr->decision_note }}</td></tr>@endif
            <tr><td class="k">File</td><td class="v"><span class="mono">{{ $dr->file_name ?? '—' }}</span> · {{ $size }}</td></tr>
            @if ($filters !== '')<tr><td class="k">Filters</td><td class="v mono">{{ $filters }}</td></tr>@endif
            <tr><td class="k">From</td><td class="v"><span class="mono">{{ $dr->download_ip ?? '—' }}</span></td></tr>
            <tr><td class="k">Device</td><td class="v note">{{ $dr->download_user_agent ?? '—' }}</td></tr>
            <tr><td class="k">Export id</td><td class="v mono">{{ $dr->export_id }}</td></tr>
            <tr><td class="k">Fingerprint</td><td class="v mono">{{ $dr->file_sha256 ? substr($dr->file_sha256, 0, 32) . '…' : '—' }}</td></tr>
        </table>
    </div>

    <div class="section">
        <p class="note" style="margin-bottom:12px">
            @if ($attached) The file is attached, exactly as it left the hub.
            @elseif ($never) Nothing is attached.
            @else The file is too large to attach. A copy is kept on the server for 90 days.
            @endif
            The export id is in the file's name — a copy found anywhere later names this download.
        </p>
        <a class="btn" href="{{ $consoleUrl }}">Open in Bethany Hub</a>
    </div>

    <div class="foot">Sent privately to the owner of Bethany Hub. The person who downloaded this is not told.</div>
</div>
</body>
</html>
