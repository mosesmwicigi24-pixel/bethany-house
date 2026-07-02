<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>End of Day Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #18181b; }
        .wrapper { max-width: 640px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .header { background: #18181b; padding: 28px 32px; }
        .header h1 { color: #fff; font-size: 18px; font-weight: 700; }
        .header p  { color: #a1a1aa; font-size: 13px; margin-top: 4px; }
        .summary   { display: flex; gap: 0; border-bottom: 1px solid #f4f4f5; }
        .kpi       { flex: 1; padding: 20px 24px; text-align: center; border-right: 1px solid #f4f4f5; }
        .kpi:last-child { border-right: none; }
        .kpi-label { font-size: 11px; color: #71717a; text-transform: uppercase; letter-spacing: .05em; font-weight: 600; }
        .kpi-value { font-size: 17px; font-weight: 800; margin-top: 4px; }
        .kpi-value.brand   { color: #6d28d9; }
        .kpi-value.success { color: #16a34a; }
        .kpi-value.warning { color: #d97706; }
        .kpi-value.neutral { color: #18181b; }
        .section   { padding: 24px 32px; border-bottom: 1px solid #f4f4f5; }
        .section:last-child { border-bottom: none; }
        .cashier-header { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 12px; }
        .cashier-name   { font-size: 14px; font-weight: 700; color: #18181b; }
        .outlet-badge   { font-size: 11px; background: #f4f4f5; color: #52525b; padding: 2px 8px; border-radius: 20px; font-weight: 600; }
        .cashier-kpis   { display: flex; gap: 16px; margin-bottom: 12px; flex-wrap: wrap; }
        .cashier-kpi    { font-size: 12px; color: #71717a; }
        .cashier-kpi span { font-weight: 700; }
        .cashier-kpi .brand   { color: #6d28d9; }
        .cashier-kpi .success { color: #16a34a; }
        .cashier-kpi .warning { color: #d97706; }
        .orders-table   { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
        .orders-table th { text-align: left; padding: 6px 8px; color: #71717a; font-weight: 600; border-bottom: 1px solid #f4f4f5; text-transform: uppercase; font-size: 10px; letter-spacing: .05em; }
        .orders-table td { padding: 8px 8px; border-bottom: 1px solid #fafafa; vertical-align: top; }
        .orders-table tr:last-child td { border-bottom: none; }
        .order-note     { font-size: 11px; color: #71717a; font-style: italic; margin-top: 2px; }
        .sentiments     { margin-top: 12px; background: #fafafa; border-radius: 8px; padding: 12px 14px; font-size: 12px; color: #3f3f46; line-height: 1.7; border-left: 3px solid #e4e4e7; }
        .sentiments-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #a1a1aa; margin-bottom: 6px; }
        .footer { background: #fafafa; padding: 20px 32px; text-align: center; }
        .footer p { font-size: 11px; color: #a1a1aa; }
        @media (max-width: 600px) {
            .wrapper { margin: 0; border-radius: 0; }
            .summary { flex-wrap: wrap; }
            .kpi { min-width: 50%; border-right: none; border-bottom: 1px solid #f4f4f5; }
            .section { padding: 20px 16px; }
            .header { padding: 20px 16px; }
        }
    </style>
</head>
<body>
<div class="wrapper">

    {{-- Header --}}
    <div class="header">
        <h1>End of Day Report</h1>
        <p>{{ \Carbon\Carbon::parse($date)->format('l, j F Y') }}</p>
    </div>

    {{-- Global KPI summary --}}
    @php
        $grandSales   = collect($reports)->sum('total_sales');
        $grandPaid    = collect($reports)->sum('total_paid');
        $grandBalance = collect($reports)->sum('total_balance');
        $fmt = fn($n) => 'KES ' . number_format($n, 2);
    @endphp
    <div class="summary">
        <div class="kpi">
            <div class="kpi-label">Cashiers</div>
            <div class="kpi-value neutral">{{ count($reports) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Total Sales</div>
            <div class="kpi-value brand">{{ $fmt($grandSales) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Total Paid</div>
            <div class="kpi-value success">{{ $fmt($grandPaid) }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Balance</div>
            <div class="kpi-value {{ $grandBalance > 0.01 ? 'warning' : 'neutral' }}">
                {{ $grandBalance > 0.01 ? $fmt($grandBalance) : '—' }}
            </div>
        </div>
    </div>

    {{-- Per-cashier sections --}}
    @foreach ($reports as $r)
    <div class="section">

        <div class="cashier-header">
            <span class="cashier-name">{{ $r->user_name }}</span>
            <span class="outlet-badge">{{ $r->outlet_name }}</span>
        </div>

        <div class="cashier-kpis">
            <span class="cashier-kpi">
                {{ $r->order_count }} order{{ $r->order_count !== 1 ? 's' : '' }}
            </span>
            <span class="cashier-kpi">
                Sales: <span class="brand">{{ $fmt($r->total_sales) }}</span>
            </span>
            <span class="cashier-kpi">
                Paid: <span class="success">{{ $fmt($r->total_paid) }}</span>
            </span>
            @if ($r->total_balance > 0.01)
            <span class="cashier-kpi">
                Balance: <span class="warning">{{ $fmt($r->total_balance) }}</span>
            </span>
            @endif
        </div>

        {{-- Orders table --}}
        @if (!empty($r->orders))
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th style="text-align:right">Total</th>
                    <th style="text-align:right">Paid</th>
                    <th style="text-align:right">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($r->orders as $order)
                <tr>
                    <td>
                        #{{ $order['order_number'] }}
                        @if (!empty($order['eod_note']))
                        <div class="order-note">{{ $order['eod_note'] }}</div>
                        @endif
                    </td>
                    <td>{{ $order['customer_name'] }}</td>
                    <td style="text-align:right; font-weight:600">{{ $fmt($order['total_amount']) }}</td>
                    <td style="text-align:right; color:#16a34a; font-weight:600">{{ $fmt($order['amount_paid']) }}</td>
                    <td style="text-align:right; color:{{ $order['balance'] > 0.01 ? '#d97706' : '#a1a1aa' }}; font-weight:600">
                        {{ $order['balance'] > 0.01 ? $fmt($order['balance']) : '—' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        {{-- Daily sentiments --}}
        @if (!empty(trim(strip_tags($r->sentiments ?? ''))))
        <div class="sentiments">
            <div class="sentiments-label">Daily Notes & Sentiments</div>
            {!! $r->sentiments !!}
        </div>
        @endif

    </div>
    @endforeach

    {{-- Footer --}}
    <div class="footer">
        <p>Bethany House · Automated EoD Report · {{ now()->format('d M Y, H:i') }}</p>
    </div>

</div>
</body>
</html>