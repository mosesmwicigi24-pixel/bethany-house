<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Audit digest</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #18181b; }
        .wrapper { max-width: 680px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .header { background: #162b4d; padding: 24px 32px; }
        .header h1 { color: #fff; font-size: 18px; font-weight: 700; }
        .header p { color: #c7d2e3; font-size: 13px; margin-top: 4px; }
        .kpis { display: flex; border-bottom: 1px solid #f4f4f5; }
        .kpi { flex: 1; padding: 16px 12px; text-align: center; border-right: 1px solid #f4f4f5; }
        .kpi:last-child { border-right: none; }
        .kpi b { display: block; font-size: 18px; }
        .kpi span { font-size: 10px; color: #71717a; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
        .section { padding: 18px 32px; border-bottom: 1px solid #f4f4f5; }
        h2 { font-size: 13px; font-weight: 700; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { text-align: left; color: #71717a; font-weight: 600; padding: 4px 6px 4px 0; border-bottom: 1px solid #f4f4f5; }
        td { padding: 5px 6px 5px 0; vertical-align: top; border-bottom: 1px solid #fafafa; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; }
        .muted { color: #71717a; font-size: 12px; }
        .ok { background: #dcfce7; color: #166534; border-radius: 8px; padding: 10px 12px; font-size: 12px; }
        .bad { background: #fee2e2; color: #991b1b; border-radius: 8px; padding: 10px 12px; font-size: 13px; font-weight: 700; }
        .warn { background: #fef3c7; color: #92400e; border-radius: 8px; padding: 10px 12px; font-size: 12px; }
        .foot { padding: 16px 32px; font-size: 11px; color: #a1a1aa; }
        a { color: #162b4d; }
    </style>
</head>
<body>
@php
    $name = fn ($u) => $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->email ?? '—')) : 'System';
    $t = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('H:i') : '—';
    $failed = ($integrity?->event ?? null) === 'audit_verification_failed';
@endphp
<div class="wrapper">
    <div class="header">
        <h1>Bethany Hub — {{ $day->format('l j F Y') }}</h1>
        <p>Downloads, security and the integrity of the audit trail</p>
    </div>

    <div class="kpis">
        <div class="kpi"><b>{{ $downloads->count() }}</b><span>Downloads</span></div>
        <div class="kpi"><b>{{ $exempt->count() }}</b><span>Invoices · quotes · receipts</span></div>
        <div class="kpi"><b>{{ $pending->count() }}</b><span>Waiting for you</span></div>
        <div class="kpi"><b>{{ $security->count() }}</b><span>Security events</span></div>
    </div>

    <div class="section">
        @if ($failed)
            <div class="bad">The audit trail failed its integrity check ({{ \Illuminate\Support\Carbon::parse($integrity->created_at)->format('j M H:i') }}). A record was changed or removed. Open the Activity Log.</div>
        @elseif ($integrity)
            <div class="ok">Audit trail verified intact · {{ \Illuminate\Support\Carbon::parse($integrity->created_at)->format('j M H:i') }} · {{ number_format($changes) }} changes and {{ number_format($staffCalls) }} staff calls recorded yesterday.</div>
        @else
            <div class="warn">The audit trail has not been verified yet (first seal runs at 00:20).</div>
        @endif
        @unless ($enforcing)
            <p class="muted" style="margin-top:8px">Download approval is <b>recording only</b>: nothing is held yet. Downloads below marked "not held" would have needed your approval.</p>
        @endunless
    </div>

    @foreach ($imprest as $im)
    <div class="section">
        <h2>Imprest — {{ $im['name'] }}</h2>
        @if ($im['low'])<div class="warn" style="margin-bottom:8px">Below its alert level — KES {{ number_format((float) $im['balance'], 2) }} of KES {{ number_format((float) $im['float'], 2) }}.</div>@endif
        <table>
            <tr><td style="width:180px" class="muted">Balance</td><td><b>KES {{ number_format((float) $im['balance'], 2) }}</b> of KES {{ number_format((float) $im['float'], 2) }} · held by {{ $im['custodian'] ?: '—' }}</td></tr>
            <tr><td class="muted">Spent yesterday</td><td>KES {{ number_format((float) $im['spent'], 2) }}</td></tr>
            @foreach ($im['received'] as $r)<tr><td class="muted">Top-up received</td><td>KES {{ number_format((float) $r->received_amount, 2) }}@if ((float) $r->received_amount !== (float) $r->sent_amount) <span style="color:#991b1b">(you sent KES {{ number_format((float) $r->sent_amount, 2) }})</span>@endif</td></tr>@endforeach
            @foreach ($im['waiting'] as $w)<tr><td class="muted">Top-up requested</td><td>KES {{ number_format((float) $w->requested_amount, 2) }} — <a href="{{ $consoleUrl }}/expenses/imprest">decide</a></td></tr>@endforeach
            @foreach ($im['in_transit'] as $t)<tr><td class="muted">Sent, not yet confirmed</td><td>KES {{ number_format((float) $t->sent_amount, 2) }} since {{ optional($t->sent_at)->format('j M H:i') }}</td></tr>@endforeach
            @if ($im['unresolved'])<tr><td class="muted">Rejected spends</td><td>{{ $im['unresolved'] }} waiting: cash returned or written off?</td></tr>@endif
            @if ($im['counts'])<tr><td class="muted">Cash counts</td><td>{{ $im['counts'] }} differ from the books — approve or reject</td></tr>@endif
        </table>
    </div>
    @endforeach

    @if ($pending->count())
    <div class="section">
        <h2>Waiting for your decision</h2>
        <table>
            <tr><th>Who</th><th>What</th><th>Reason</th><th>Since</th></tr>
            @foreach ($pending as $p)
                <tr><td>{{ $name($p->user) }}</td><td>{{ $p->label }}</td><td>{{ $p->reason }}</td><td>{{ $p->updated_at?->format('j M H:i') }}</td></tr>
            @endforeach
        </table>
        <p style="margin-top:10px"><a href="{{ $consoleUrl }}/settings/downloads">Decide in Bethany Hub →</a></p>
    </div>
    @endif

    <div class="section">
        <h2>Downloads</h2>
        @if ($downloads->isEmpty())
            <p class="muted">None.</p>
        @else
        <table>
            <tr><th>Time</th><th>Who</th><th>What</th><th>Approval</th><th>Export id</th></tr>
            @foreach ($downloads as $d)
                <tr>
                    <td>{{ $t($d->downloaded_at) }}</td>
                    <td>{{ $name($d->user) }}</td>
                    <td>{{ $d->label }}</td>
                    <td>{{ $d->decider ? 'Approved by ' . $name($d->decider) : ($d->shadow ? 'not held' : ucfirst($d->status)) }}</td>
                    <td class="mono">{{ $d->export_id }}</td>
                </tr>
            @endforeach
        </table>
        @endif
    </div>

    @if ($decisions->count())
    <div class="section">
        <h2>Decisions</h2>
        <table>
            <tr><th>Time</th><th>Request</th><th>Decision</th></tr>
            @foreach ($decisions as $d)
                <tr><td>{{ $t($d->decided_at) }}</td><td>{{ $name($d->user) }} — {{ $d->label }}</td><td>{{ ucfirst($d->status === 'denied' ? 'denied' : 'approved') }} by {{ $name($d->decider) }}{{ $d->decision_note ? ': ' . $d->decision_note : '' }}</td></tr>
            @endforeach
        </table>
    </div>
    @endif

    <div class="section">
        <h2>Security</h2>
        @if ($security->isEmpty())
            <p class="muted">Nothing unusual.</p>
        @else
        <table>
            <tr><th>Time</th><th>Event</th><th>From</th></tr>
            @foreach ($security as $e)
                <tr><td>{{ $t($e->created_at) }}</td><td>{{ $e->description }}</td><td class="mono">{{ $e->ip_address ?? '—' }}</td></tr>
            @endforeach
        </table>
        @endif
    </div>

    @if ($bulkReads->count())
    <div class="section">
        <h2>Bulk reads (calls returning 50+ records)</h2>
        <table>
            <tr><th>Who</th><th>Screen / endpoint</th><th>Calls</th><th>Records</th></tr>
            @foreach ($bulkReads as $b)
                <tr><td>{{ trim(($b->first_name ?? '') . ' ' . ($b->last_name ?? '')) ?: 'user #' . $b->user_id }}</td><td class="mono">{{ $b->path }}</td><td>{{ $b->calls }}</td><td>{{ number_format($b->records) }}</td></tr>
            @endforeach
        </table>
    </div>
    @endif

    @if ($sensitive->count())
    <div class="section">
        <h2>Sensitive changes</h2>
        <table>
            <tr><th>Time</th><th>Who</th><th>What</th></tr>
            @foreach ($sensitive as $e)
                <tr><td>{{ $t($e->created_at) }}</td><td>{{ trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')) ?: 'System' }}</td><td>{{ $e->description }}</td></tr>
            @endforeach
        </table>
    </div>
    @endif

    @if ($exempt->count())
    <div class="section">
        <h2>Invoices, quotations and receipts</h2>
        <table>
            <tr><th>Time</th><th>Who</th><th>Document</th></tr>
            @foreach ($exempt as $d)
                <tr><td>{{ $t($d->downloaded_at) }}</td><td>{{ $name($d->user) }}</td><td>{{ $d->file_name ?? $d->label }}</td></tr>
            @endforeach
        </table>
    </div>
    @endif

    <div class="section">
        <h2>Seal fingerprints</h2>
        <p class="muted" style="margin-bottom:6px">Kept here, off the server. If the trail is ever rewritten, these will not match.</p>
        <table>
            @foreach ($seals as $table => $s)
                <tr><td>{{ $table }}</td><td class="mono">{{ $s ? 'to #' . $s->last_id . ' · ' . $s->hash : 'not yet sealed' }}</td></tr>
            @endforeach
        </table>
    </div>

    <div class="foot">Sent privately to the owner of Bethany Hub every morning. Your own actions are logged but not listed here.</div>
</div>
</body>
</html>
