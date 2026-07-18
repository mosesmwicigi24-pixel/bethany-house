<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Arial,sans-serif;color:#0d1220;">
  <div style="max-width:560px;margin:0 auto;padding:24px 16px;">
    <div style="background:#0a1425;border-radius:14px 14px 0 0;padding:22px 28px;">
      <span style="color:#e6bf47;font-size:18px;font-weight:800;letter-spacing:.02em;">BETHANY HOUSE</span>
      <div style="color:#aeb9c9;font-size:12px;margin-top:4px;">Sonalux Building, Moi Avenue, Nairobi · +254 727 891 989</div>
    </div>

    <div style="background:#ffffff;border-radius:0 0 14px 14px;padding:28px;">
      <h1 style="font-size:20px;margin:0 0 6px;">Asante — order received.</h1>
      <p style="font-size:14px;color:#555;margin:0 0 20px;">
        Your order <b>{{ $order->order_number }}</b> was placed on
        {{ $order->created_at->format('j F Y, H:i') }}.
      </p>

      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead>
          <tr>
            <th style="text-align:left;padding:8px 4px;border-bottom:2px solid #0a1425;font-size:11px;letter-spacing:.08em;color:#6f7480;">ITEM</th>
            <th style="text-align:right;padding:8px 4px;border-bottom:2px solid #0a1425;font-size:11px;letter-spacing:.08em;color:#6f7480;">QTY</th>
            <th style="text-align:right;padding:8px 4px;border-bottom:2px solid #0a1425;font-size:11px;letter-spacing:.08em;color:#6f7480;">TOTAL</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($order->items as $item)
          <tr>
            <td style="padding:10px 4px;border-bottom:1px solid #e7e8ec;">
              {{ $item->product_name }}
              @if ($item->notes)
                <div style="font-size:11px;color:#8a6d1a;background:#f6ecd2;border-radius:6px;padding:3px 7px;display:inline-block;margin-top:4px;">{{ $item->notes }}</div>
              @endif
            </td>
            <td style="padding:10px 4px;border-bottom:1px solid #e7e8ec;text-align:right;">{{ $item->quantity }}</td>
            <td style="padding:10px 4px;border-bottom:1px solid #e7e8ec;text-align:right;">{{ number_format((float) $item->total_price, 2) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>

      <table style="width:100%;font-size:14px;margin-top:14px;">
        <tr><td style="color:#555;">Subtotal</td><td style="text-align:right;">{{ $order->currency_code }} {{ number_format((float) $order->subtotal, 2) }}</td></tr>
        <tr><td style="color:#555;">Delivery</td><td style="text-align:right;">{{ (float) $order->shipping_amount > 0 ? $order->currency_code . ' ' . number_format((float) $order->shipping_amount, 2) : 'Confirmed on dispatch' }}</td></tr>
        <tr><td style="font-weight:800;padding-top:8px;border-top:2px solid #0a1425;font-size:16px;">Total</td>
            <td style="font-weight:800;padding-top:8px;border-top:2px solid #0a1425;text-align:right;font-size:16px;">{{ $order->currency_code }} {{ number_format((float) $order->total_amount, 2) }}</td></tr>
      </table>

      @if ($paymentLink)
      <div style="text-align:center;margin:26px 0 8px;">
        <a href="{{ $paymentLink }}" style="background:#c9a227;color:#0a1425;font-weight:800;text-decoration:none;border-radius:99px;padding:13px 34px;font-size:15px;display:inline-block;">Complete payment</a>
        <div style="font-size:12px;color:#888;margin-top:10px;">M-Pesa, Visa or Mastercard — secure payment page.</div>
      </div>
      @endif

      <p style="font-size:12px;color:#777;line-height:1.7;margin-top:22px;border-top:1px solid #e7e8ec;padding-top:14px;">
        Made-to-order items are sewn after payment confirmation (5–7 days).
        Questions about this order: +254 727 891 989, quoting <b>{{ $order->order_number }}</b>.
        Asante for shopping with Bethany House.
      </p>
    </div>
  </div>
</body>
</html>
