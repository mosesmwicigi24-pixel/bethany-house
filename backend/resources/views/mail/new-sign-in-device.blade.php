<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>New sign-in to your Bethany Hub account</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #18181b; }
        .wrapper { max-width: 560px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .header { background: #162b4d; padding: 22px 32px; }
        .header h1 { color: #fff; font-size: 17px; font-weight: 700; }
        .header p { color: #c7d2e3; font-size: 12px; margin-top: 4px; }
        .section { padding: 20px 32px; font-size: 14px; line-height: 1.55; }
        .section p + p { margin-top: 12px; }
        table { margin-top: 12px; font-size: 13px; }
        td { padding: 3px 12px 3px 0; vertical-align: top; }
        td.k { color: #71717a; }
        .foot { padding: 14px 32px; font-size: 11px; color: #a1a1aa; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header"><h1>New sign-in to your account</h1><p>Security · Bethany Hub</p></div>
    <div class="section">
        <p>Hello {{ $name }},</p>
        <p>Your Bethany Hub password was just used from a device we have not seen you sign in from before.</p>
        <table>
            <tr><td class="k">When</td><td>{{ $at }}</td></tr>
            <tr><td class="k">Device</td><td>{{ $device }}</td></tr>
            <tr><td class="k">Address</td><td>{{ $ip }}@if($country) ({{ $country }})@endif</td></tr>
        </table>
        <p>If this was you, there is nothing to do. If it was not, change your password now and tell your system administrator — they can end every session on your account.</p>
    </div>
    <div class="foot">Sent automatically by Bethany Hub. Activity on the hub is logged and reviewed.</div>
</div>
</body>
</html>
