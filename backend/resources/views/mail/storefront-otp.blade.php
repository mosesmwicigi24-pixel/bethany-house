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
      <h1 style="font-size:20px;margin:0 0 6px;">Your order-lookup code</h1>
      <p style="font-size:14px;color:#555;margin:0 0 22px;">
        Use this code to see your Bethany House orders. It expires in
        {{ $expiresMinutes }} minutes.
      </p>

      <div style="text-align:center;margin:0 0 22px;">
        <div style="display:inline-block;background:#f6ecd2;border:1px solid #e6bf47;border-radius:12px;padding:16px 30px;">
          <span style="font-size:34px;font-weight:800;letter-spacing:.34em;color:#0a1425;">{{ $code }}</span>
        </div>
      </div>

      <p style="font-size:12.5px;color:#8a8f98;margin:0;line-height:1.5;">
        If you didn’t request this, you can safely ignore this email — your
        orders stay private. Never share this code with anyone; Bethany House
        staff will never ask for it.
      </p>
    </div>

    <p style="text-align:center;color:#9aa0aa;font-size:11px;margin:16px 0 0;">
      © {{ date('Y') }} Bethany House · bethanyhouse.co.ke
    </p>
  </div>
</body>
</html>
