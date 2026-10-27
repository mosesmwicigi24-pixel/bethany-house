<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Timesheets locked</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #18181b; }
        .wrapper { max-width: 560px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .header { background: #162b4d; padding: 22px 32px; }
        .header h1 { color: #fff; font-size: 17px; font-weight: 700; }
        .header p { color: #c7d2e3; font-size: 12px; margin-top: 4px; }
        .section { padding: 20px 32px; font-size: 14px; line-height: 1.55; }
        .btn { display: inline-block; margin-top: 16px; background: #162b4d; color: #fff !important; text-decoration: none; padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; }
        .foot { padding: 14px 32px; font-size: 11px; color: #a1a1aa; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header"><h1>Timesheets locked: {{ $range }}</h1><p>{{ $group }} · Bethany Hub</p></div>
    <div class="section">
        <p>{{ $lockedBy }} locked the pay period {{ $range }}. The approved hours are attached as a CSV ({{ $sheets }} {{ $sheets === 1 ? 'timesheet' : 'timesheets' }}@if($leftOut > 0), {{ $leftOut }} left out unapproved@endif).</p>
        <p style="margin-top:10px;color:#52525b">Hours only. Pay is worked out in Payroll from these hours and each person's pay structure.</p>
        <a class="btn" href="{{ $url }}">Open Timesheets</a>
    </div>
    <div class="foot">Sent because the "Locked period CSV" automation is on (Setup › Automations).</div>
</div>
</body>
</html>
