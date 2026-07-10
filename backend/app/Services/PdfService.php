<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * PdfService
 *
 * Generates compact, single-page branded HTML for every transaction type.
 * Layout matches a professional invoice style:
 *   - Logo + org details top-left, document title top-right
 *   - Party boxes (From / To) side by side
 *   - Lined items table
 *   - Right-aligned totals summary block
 */
class PdfService
{
    // =========================================================================
    // HELPERS
    // =========================================================================

    protected static function settings(): array
    {
        $defaults = [
            'app_name'     => 'Bethany House',
            'app_tagline'  => '',
            'app_address'  => '',
            'app_city'     => '',
            'app_email'    => '',
            'app_phone'    => '',
            'app_logo_url' => '',
        ];

        $cached = Cache::remember('app_settings', 300, function () use ($defaults) {
            $rows = DB::table('settings')->pluck('value', 'key')->toArray();
            return array_merge($defaults, $rows);
        });

        // Guard against a stale cached Collection (from a previous code version).
        // If the cache holds a non-array, bust it and re-fetch immediately.
        if (!is_array($cached)) {
            Cache::forget('app_settings');
            $rows = DB::table('settings')->pluck('value', 'key')->toArray();
            $cached = array_merge($defaults, $rows);
            Cache::put('app_settings', $cached, 300);
        }

        return $cached;
    }

    protected static function money(float $amount, string $currency = 'KES'): string
    {
        return $currency . ' ' . number_format($amount, 2);
    }

    protected static function date(?string $date): string
    {
        if (!$date) return '—';
        try { return \Carbon\Carbon::parse($date)->format('d M Y'); }
        catch (\Exception) { return $date; }
    }

    protected static function statusColour(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'approved', 'completed', 'received', 'delivered', 'passed' => '#16a34a',
            'pending', 'draft', 'pending_approval', 'pending_review'           => '#d97706',
            'processing', 'in_progress', 'partially_received', 'partial'       => '#2563eb',
            'shipped', 'ordered', 'dispatched'                                 => '#7c3aed',
            'cancelled', 'rejected', 'voided', 'failed'                        => '#dc2626',
            'refunded', 'partially_refunded'                                   => '#0891b2',
            default                                                            => '#6b7280',
        };
    }

    protected static function statusLabel(string $status): string
    {
        return strtoupper(str_replace(['_', '-'], ' ', $status));
    }

    /** Embed logo as base64 so it renders offline in DomPDF */
    protected static function logoHtml(string $logoUrl, string $appName): string
    {
        if (!$logoUrl) return '';
        try {
            $path = str_replace(config('app.url') . '/storage/', '', $logoUrl);
            $full = storage_path('app/public/' . $path);
            if (file_exists($full)) {
                $mime = mime_content_type($full);
                $b64  = base64_encode(file_get_contents($full));
                return "<img src=\"data:{$mime};base64,{$b64}\" style=\"max-height:52px;max-width:140px;object-fit:contain;display:block;\" alt=\"{$appName}\">";
            }
        } catch (\Exception $e) {}
        return "<img src=\"{$logoUrl}\" style=\"max-height:52px;max-width:140px;object-fit:contain;display:block;\" alt=\"{$appName}\">";
    }

    // =========================================================================
    // SHARED PAGE SHELL
    // =========================================================================

    public static function page(
        string $docTitle,
        string $docNumber,
        string $status,
        array  $meta,
        string $parties,
        string $table,
        array  $totals = [],
        string $extra  = ''
    ): string {
        $s       = self::settings();
        $appName = htmlspecialchars($s['app_name'] ?? 'Bethany House');
        $address = htmlspecialchars(trim(($s['app_address'] ?? '') . ($s['app_city'] ? ', ' . $s['app_city'] : '')));
        $email   = htmlspecialchars($s['app_email'] ?? '');
        $phone   = htmlspecialchars($s['app_phone'] ?? '');
        $logoUrl = $s['app_logo_url'] ?? '';
        $year    = date('Y');

        $logo         = self::logoHtml($logoUrl, $appName);
        $statusColour = self::statusColour($status);
        $statusLabel  = self::statusLabel($status);

        // Org contact lines
        $orgLines = '';
        if ($address) $orgLines .= "<div>{$address}</div>";
        if ($email)   $orgLines .= "<div>Email: {$email}</div>";
        if ($phone)   $orgLines .= "<div>Phone: {$phone}</div>";

        // Meta rows
        $metaRows = '';
        foreach ($meta as $label => $value) {
            $v = htmlspecialchars((string)$value);
            $metaRows .= "<tr><td class=\"ml\">{$label}:</td><td class=\"mv\">{$v}</td></tr>";
        }

        // Totals block
        $totalsHtml = '';
        if (!empty($totals)) {
            $currency = $totals['currency'] ?? 'KES';
            $trows    = [];
            if (isset($totals['subtotal']))                         $trows[] = ['Sub Total', self::money((float)$totals['subtotal'], $currency), false];
            if (!empty($totals['discount']) && $totals['discount']) $trows[] = ['Discount',  '- ' . self::money((float)$totals['discount'], $currency), false];
            if (!empty($totals['tax'])      && $totals['tax'])      $trows[] = ['Tax',       self::money((float)$totals['tax'],      $currency), false];
            if (!empty($totals['shipping']) && $totals['shipping']) $trows[] = ['Shipping',  self::money((float)$totals['shipping'], $currency), false];
            if (isset($totals['total']))                            $trows[] = ['Total',     self::money((float)$totals['total'],    $currency), true];

            $totalsHtml = '<table class="totals-table">';
            foreach ($trows as [$lbl, $val, $isTotal]) {
                $cls = $isTotal ? ' class="total-line"' : '';
                $totalsHtml .= "<tr{$cls}><td class=\"tl\">{$lbl}</td><td class=\"tv\">{$val}</td></tr>";
            }
            $totalsHtml .= '</table>';
        }

        $badge = "<span style=\"display:inline-block;padding:2px 9px;border-radius:3px;font-size:9px;font-weight:700;letter-spacing:.6px;background:{$statusColour};color:#fff;\">{$statusLabel}</span>";

        $css = '
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#222;background:#fff;line-height:1.45}
table{border-collapse:collapse;width:100%}
.page{padding:28px 32px;max-width:780px;margin:0 auto}
.top-header{display:table;width:100%;margin-bottom:10px}
.top-left{display:table-cell;vertical-align:top;width:55%}
.top-right{display:table-cell;vertical-align:top;text-align:right}
.doc-title{font-size:22px;font-weight:700;color:#222;letter-spacing:-.3px}
.status-wrap{margin-top:5px}
.org-name{font-size:13px;font-weight:700;margin-top:6px;margin-bottom:3px}
.org-contact{font-size:10px;color:#555;line-height:1.6}
.divider{border:none;border-top:2px solid #222;margin:10px 0 8px}
.meta-table{font-size:10px;margin-top:2px}
.meta-table .ml{color:#555;white-space:nowrap;padding:1px 6px 1px 0;text-align:right}
.meta-table .mv{font-weight:600;text-align:right;white-space:nowrap}
.party-row{display:table;width:100%;margin-bottom:10px}
.party-cell{display:table-cell;width:50%;vertical-align:top;padding-right:12px}
.party-cell:last-child{padding-right:0}
.party-box{border:1px solid #bbb;padding:7px 9px;min-height:58px}
.party-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#555;margin-bottom:3px}
.party-name{font-size:11px;font-weight:700}
.party-detail{font-size:10px;color:#444;line-height:1.55;margin-top:2px}
.items-table{width:100%;border:1px solid #ccc}
.items-table thead tr{background:#f0f0f0}
.items-table thead th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:5px 7px;border-bottom:1px solid #bbb;border-right:1px solid #ddd;text-align:left}
.items-table thead th:last-child{border-right:none}
.items-table tbody tr{border-bottom:1px solid #e0e0e0}
.items-table tbody td{font-size:10.5px;padding:5px 7px;border-right:1px solid #e8e8e8;vertical-align:top}
.items-table tbody td:last-child{border-right:none}
.tr{text-align:right}
.tc{text-align:center}
.sub-desc{font-size:9.5px;color:#666;margin-top:2px}
.bottom-row{display:table;width:100%;border-top:2px solid #222}
.bottom-left{display:table-cell;vertical-align:top;width:55%;padding-top:8px;padding-right:16px}
.bottom-right{display:table-cell;vertical-align:top;width:45%;padding-top:0}
.totals-table{width:100%;border:1px solid #ccc;border-top:none}
.totals-table td{padding:4px 8px;font-size:10.5px}
.totals-table .tl{color:#444;border-right:1px solid #ddd;width:55%}
.totals-table .tv{text-align:right;font-weight:600}
.totals-table tr.total-line td{font-size:12px;font-weight:700;background:#f0f0f0;border-top:2px solid #222}
.pay-table{width:100%;margin-top:4px;font-size:10px}
.pay-table th{text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.4px;color:#555;padding:2px 4px;border-bottom:1px solid #ccc}
.pay-table td{padding:3px 4px;border-bottom:1px solid #eee}
.pay-title{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#555;margin-top:8px;margin-bottom:3px}
.notes-box{margin-top:4px;font-size:10px;color:#444;line-height:1.5}
.notes-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#555;margin-top:8px;margin-bottom:2px}
.sig-row{display:table;width:100%;margin-top:14px;border-top:1px solid #ccc;padding-top:8px}
.sig-cell{display:table-cell;width:33%;font-size:10px;color:#555;padding-right:12px}
.sig-line{border-bottom:1px solid #555;margin-bottom:3px;height:18px}
.footer{margin-top:12px;padding-top:6px;border-top:1px solid #ddd;font-size:9px;color:#888;display:table;width:100%}
.footer-l{display:table-cell;text-align:left}
.footer-r{display:table-cell;text-align:right}
@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.page{padding:16px}}';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$docTitle} {$docNumber}</title>
<style>{$css}</style>
</head>
<body>
<div class="page">
  <div class="top-header">
    <div class="top-left">
      {$logo}
      <div class="org-name">{$appName}</div>
      <div class="org-contact">{$orgLines}</div>
    </div>
    <div class="top-right">
      <div class="doc-title">{$docTitle}</div>
      <div class="status-wrap">{$badge}</div>
      <table class="meta-table" style="margin-left:auto;margin-top:4px">{$metaRows}</table>
    </div>
  </div>
  <hr class="divider">
  {$parties}
  {$table}
  <div class="bottom-row">
    <div class="bottom-left">{$extra}</div>
    <div class="bottom-right">{$totalsHtml}</div>
  </div>
  <div class="sig-row">
    <div class="sig-cell"><div class="sig-line"></div>Signature</div>
    <div class="sig-cell"><div class="sig-line"></div>Name (print)</div>
    <div class="sig-cell"><div class="sig-line"></div>Date</div>
  </div>
  <div class="footer">
    <div class="footer-l">Generated {$docNumber} &bull; {$appName}</div>
    <div class="footer-r">&copy; {$year} {$appName}</div>
  </div>
</div>
</body>
</html>
HTML;
    }

    // =========================================================================
    // SHARED BUILDERS
    // =========================================================================

    protected static function partyRow(
        string $leftLabel,  string $leftName,  array $leftLines  = [],
        string $rightLabel = '', string $rightName = '', array $rightLines = []
    ): string {
        $left  = self::partyBox($leftLabel, $leftName, $leftLines);
        $right = $rightName
            ? self::partyBox($rightLabel, $rightName, $rightLines)
            : '<div class="party-box"></div>';
        return "<div class=\"party-row\">
            <div class=\"party-cell\">{$left}</div>
            <div class=\"party-cell\">{$right}</div>
        </div>";
    }

    protected static function partyBox(string $label, string $name, array $lines = []): string
    {
        $name   = htmlspecialchars($name);
        $label  = htmlspecialchars($label);
        $detail = implode('<br>', array_map('htmlspecialchars', array_filter($lines)));
        $detHtml = $detail ? "<div class=\"party-detail\">{$detail}</div>" : '';
        return "<div class=\"party-box\">
            <div class=\"party-label\">{$label}</div>
            <div class=\"party-name\">{$name}</div>
            {$detHtml}
        </div>";
    }

    protected static function itemsTable(array $columns, array $rows): string
    {
        $thead = '';
        foreach ($columns as $col) {
            $align = $col['align'] ?? 'left';
            $w     = isset($col['width']) ? " width=\"{$col['width']}\"" : '';
            $thead .= "<th style=\"text-align:{$align}\"{$w}>{$col['label']}</th>";
        }

        $tbody = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ($columns as $col) {
                $align  = $col['align'] ?? 'left';
                $mono   = ($col['mono'] ?? false) ? 'font-family:\'Courier New\',monospace;' : '';
                $val    = $row[$col['key']] ?? '—';
                $sub    = isset($row[$col['key'] . '_sub']) && $row[$col['key'] . '_sub']
                    ? '<div class="sub-desc">' . htmlspecialchars($row[$col['key'] . '_sub']) . '</div>'
                    : '';
                $tbody .= "<td style=\"text-align:{$align};{$mono}\">" . htmlspecialchars((string)$val) . "{$sub}</td>";
            }
            $tbody .= '</tr>';
        }

        if (!$rows) {
            $colspan = count($columns);
            $tbody   = "<tr><td colspan=\"{$colspan}\" style=\"text-align:center;color:#888;padding:10px\">No items.</td></tr>";
        }

        return "<table class=\"items-table\">
            <thead><tr>{$thead}</tr></thead>
            <tbody>{$tbody}</tbody>
        </table>";
    }

    // =========================================================================
    // PURCHASE ORDER
    // =========================================================================

    public static function purchaseOrder(array $po): string
    {
        $currency = $po['currency_code'] ?? 'KES';
        $s        = self::settings();
        $sup      = $po['supplier'] ?? [];

        $meta = [
            'Order Date'        => self::date($po['order_date'] ?? null),
            'Expected Delivery' => self::date($po['expected_delivery_date'] ?? null),
            'PO Number'         => $po['po_number'] ?? '—',
            'Payment Terms'     => $po['payment_terms'] ?? '—',
            'Payment Status'    => ucfirst(str_replace('_', ' ', $po['payment_status'] ?? '—')),
        ];

        $parties = self::partyRow(
            'From', $s['app_name'] ?? 'Bethany House',
            array_filter([$s['app_address'] ?? '', $s['app_email'] ?? '', $s['app_phone'] ?? '']),
            'Supplier', $sup['name'] ?? '—',
            array_filter([$sup['company_code'] ?? '', $sup['email'] ?? '', $sup['phone'] ?? '', $sup['address'] ?? ''])
        );

        $columns = [
            ['key' => 'type',       'label' => 'Type',       'width' => '8%'],
            ['key' => 'desc',       'label' => 'Product / Description'],
            ['key' => 'sku',        'label' => 'SKU',        'width' => '12%', 'mono' => true],
            ['key' => 'qty',        'label' => 'Qty',        'align' => 'right', 'width' => '7%'],
            ['key' => 'unit_price', 'label' => 'Unit Price', 'align' => 'right', 'width' => '13%', 'mono' => true],
            ['key' => 'total',      'label' => 'Amount',     'align' => 'right', 'width' => '13%', 'mono' => true],
        ];
        $rows = array_map(function ($item) use ($currency) {
            return [
                'type'       => ucfirst($item['item_type'] ?? '—'),
                'desc'       => $item['description'] ?? ($item['product']['name'] ?? ($item['material']['name'] ?? '—')),
                'sku'        => $item['product']['sku'] ?? ($item['material']['code'] ?? '—'),
                'qty'        => number_format((float)($item['quantity'] ?? 0), 2),
                'unit_price' => self::money((float)($item['unit_price'] ?? 0), $currency),
                'total'      => self::money((float)(($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0)), $currency),
            ];
        }, $po['items'] ?? []);

        $totals = [
            'subtotal' => $po['subtotal']        ?? 0,
            'shipping' => $po['shipping_amount'] ?? ($po['shipping_cost'] ?? 0),
            'tax'      => $po['tax_amount']      ?? ($po['tax'] ?? 0),
            'total'    => $po['total_amount']    ?? ($po['total'] ?? 0),
            'currency' => $currency,
        ];

        $notes = '';
        if (!empty($po['notes'])) {
            $n     = htmlspecialchars($po['notes']);
            $notes = "<div class=\"notes-label\">Notes</div><div class=\"notes-box\">{$n}</div>";
        }

        return self::page('Purchase Order', $po['po_number'] ?? '—', $po['status'] ?? 'draft',
            $meta, $parties, self::itemsTable($columns, $rows), $totals, $notes);
    }

    // =========================================================================
    // GOODS RECEIVED NOTE
    // =========================================================================

    public static function grn(array $grn): string
    {
        $s   = self::settings();
        $sup = $grn['purchase_order']['supplier'] ?? [];

        $meta = [
            'GRN Number'    => $grn['grn_number'] ?? '—',
            'PO Reference'  => $grn['purchase_order']['po_number'] ?? '—',
            'Received Date' => self::date($grn['received_date'] ?? null),
            'Received By'   => trim(($grn['received_by']['first_name'] ?? '') . ' ' . ($grn['received_by']['last_name'] ?? '')) ?: '—',
            'Invoice No.'   => $grn['invoice_number'] ?? '—',
        ];

        $parties = self::partyRow(
            'Received By', $s['app_name'] ?? 'Bethany House',
            array_filter([$s['app_address'] ?? '']),
            'Supplier', $sup['name'] ?? '—',
            array_filter([$sup['company_code'] ?? ''])
        );

        $columns = [
            ['key' => 'type',     'label' => 'Type',     'width' => '8%'],
            ['key' => 'desc',     'label' => 'Item / Description'],
            ['key' => 'sku',      'label' => 'SKU',      'width' => '12%', 'mono' => true],
            ['key' => 'received', 'label' => 'Received', 'align' => 'right', 'width' => '9%'],
            ['key' => 'rejected', 'label' => 'Rejected', 'align' => 'right', 'width' => '9%'],
            ['key' => 'accepted', 'label' => 'Accepted', 'align' => 'right', 'width' => '9%'],
            ['key' => 'cond',     'label' => 'Condition','align' => 'center','width' => '10%'],
        ];
        $rows = array_map(function ($item) {
            $poi = $item['purchase_order_item'] ?? [];
            $rec = (float)($item['quantity_received'] ?? 0);
            $rej = (float)($item['quantity_rejected'] ?? 0);
            return [
                'type'     => ucfirst($poi['item_type'] ?? '—'),
                'desc'     => $poi['description'] ?? ($poi['product']['name'] ?? ($poi['material']['name'] ?? '—')),
                'sku'      => $poi['product']['sku'] ?? '—',
                'received' => number_format($rec, 2),
                'rejected' => number_format($rej, 2),
                'accepted' => number_format(max($rec - $rej, 0), 2),
                'cond'     => ucfirst($item['condition'] ?? '—'),
            ];
        }, $grn['items'] ?? []);

        $notes = '';
        if (!empty($grn['notes'])) {
            $n     = htmlspecialchars($grn['notes']);
            $notes = "<div class=\"notes-label\">Notes</div><div class=\"notes-box\">{$n}</div>";
        }

        return self::page('Goods Received Note', $grn['grn_number'] ?? '—', 'received',
            $meta, $parties, self::itemsTable($columns, $rows), [], $notes);
    }

    // =========================================================================
    // PURCHASE RETURN
    // =========================================================================

    public static function purchaseReturn(array $pr): string
    {
        $currency = $pr['currency_code'] ?? 'KES';
        $s        = self::settings();

        $meta = [
            'Return Number' => $pr['return_number'] ?? '—',
            'Return Date'   => self::date($pr['created_at'] ?? null),
            'PO Reference'  => $pr['po_number'] ?? '—',
            'Reason'        => $pr['reason'] ?? '—',
        ];

        $parties = self::partyRow(
            'Returned By', $s['app_name'] ?? 'Bethany House',
            array_filter([$s['app_address'] ?? '']),
            'Supplier', $pr['supplier_name'] ?? '—',
            ['PO: ' . ($pr['po_number'] ?? '—')]
        );

        $columns = [
            ['key' => 'desc',       'label' => 'Item'],
            ['key' => 'sku',        'label' => 'SKU',       'width' => '12%', 'mono' => true],
            ['key' => 'qty',        'label' => 'Qty',       'align' => 'right', 'width' => '7%'],
            ['key' => 'unit_price', 'label' => 'Unit Price','align' => 'right', 'width' => '13%', 'mono' => true],
            ['key' => 'total',      'label' => 'Amount',    'align' => 'right', 'width' => '13%', 'mono' => true],
            ['key' => 'reason',     'label' => 'Reason',    'width' => '18%'],
        ];
        $rows = array_map(function ($item) use ($currency) {
            $qty   = (float)($item['quantity'] ?? 0);
            $price = (float)($item['unit_price'] ?? ($item['purchase_order_item']['unit_price'] ?? 0));
            return [
                'desc'       => $item['description'] ?? ($item['product']['name'] ?? '—'),
                'sku'        => $item['sku'] ?? '—',
                'qty'        => number_format($qty, 2),
                'unit_price' => self::money($price, $currency),
                'total'      => self::money($qty * $price, $currency),
                'reason'     => $item['reason'] ?? '—',
            ];
        }, $pr['items'] ?? []);

        $totals = ['total' => $pr['total_amount'] ?? 0, 'currency' => $currency];

        return self::page('Purchase Return', $pr['return_number'] ?? '—', $pr['status'] ?? 'pending',
            $meta, $parties, self::itemsTable($columns, $rows), $totals);
    }

    // =========================================================================
    // SALES ORDER / INVOICE
    // =========================================================================

    public static function order(array $order, bool $isInvoice = false): string
    {
        $currency  = $order['currency_code'] ?? ($order['currency'] ?? 'KES');
        $title     = $isInvoice ? 'Invoice' : 'Sales Order';
        $s         = self::settings();
        $custName  = $order['customer_name'] ?? trim(($order['user']['first_name'] ?? '') . ' ' . ($order['user']['last_name'] ?? '')) ?: 'Walk-in';
        $custEmail = $order['customer_email'] ?? ($order['user']['email'] ?? '');
        $custPhone = $order['customer_phone'] ?? ($order['user']['phone'] ?? '');

        $meta = [
            'Associate'      => $order['cashier_name'] ?? ($order['outlet_name'] ?? '—'),
            'Location'       => $order['outlet_name'] ?? '—',
            'Date'           => self::date($order['created_at'] ?? null),
            'Order #'        => $order['order_number'] ?? '—',
            'Payment Method' => ucfirst(str_replace('_', ' ', $order['payment_method'] ?? '—')),
        ];

        $shipName  = '';
        $shipLines = [];
        if (!empty($order['shipping_address'])) {
            $a         = $order['shipping_address'];
            $shipName  = $a['name'] ?? $custName;
            $shipLines = array_filter([
                $a['address_line_1'] ?? '',
                trim(($a['city'] ?? '') . ' ' . ($a['country'] ?? '')),
                $a['phone'] ?? '',
            ]);
        }

        $parties = self::partyRow(
            'Bill To', $custName, array_filter([$custEmail, $custPhone]),
            'Ship To', $shipName ?: $custName, $shipLines ?: array_filter([$custEmail, $custPhone])
        );

        $columns = [
            ['key' => 'qty',        'label' => 'Qty',           'align' => 'right', 'width' => '6%'],
            ['key' => 'desc',       'label' => 'Product / Description'],
            ['key' => 'orig_price', 'label' => 'Original Price','align' => 'right', 'width' => '13%', 'mono' => true],
            ['key' => 'discount',   'label' => 'Discount',      'align' => 'right', 'width' => '10%'],
            ['key' => 'unit_price', 'label' => 'Unit Price',    'align' => 'right', 'width' => '13%', 'mono' => true],
            ['key' => 'amount',     'label' => 'Amount',        'align' => 'right', 'width' => '12%', 'mono' => true],
        ];
        $rows = array_map(function ($item) use ($currency) {
            $qty      = (float)($item['quantity'] ?? 0);
            $price    = (float)($item['unit_price'] ?? 0);
            $disc     = (float)($item['discount_amount'] ?? 0);
            $subtotal = (float)($item['subtotal'] ?? ($qty * $price - $disc));
            $discPct  = ($price > 0 && $disc > 0) ? number_format(($disc / $price) * 100, 2) . '%' : '0.00%';
            $variant  = $item['variant_name'] ?? '';
            $sku      = $item['sku'] ?? '';
            $sub      = trim(($variant ? "Variant: {$variant}" : '') . ($sku ? " | SKU: {$sku}" : ''));
            return [
                'qty'            => number_format($qty),
                'desc'           => $item['product_name'] ?? '—',
                'desc_sub'       => $sub,
                'orig_price'     => self::money($price, $currency),
                'discount'       => $discPct,
                'unit_price'     => self::money($price - ($qty > 0 ? $disc / $qty : 0), $currency),
                'amount'         => self::money($subtotal, $currency),
            ];
        }, $order['items'] ?? []);

        $totals = [
            'subtotal' => $order['subtotal']        ?? 0,
            'discount' => $order['discount_amount'] ?? 0,
            'tax'      => $order['tax_amount']      ?? 0,
            'shipping' => $order['shipping_amount'] ?? 0,
            'total'    => $order['total_amount']    ?? ($order['total'] ?? 0),
            'currency' => $currency,
        ];

        $extra = '';
        if (!empty($order['payments'])) {
            $payRows = '';
            foreach ($order['payments'] as $p) {
                $method  = htmlspecialchars(ucfirst(str_replace('_', ' ', $p['payment_method'] ?? '—')));
                $dt      = self::date($p['paid_at'] ?? ($p['created_at'] ?? null));
                $amt     = self::money((float)($p['amount'] ?? 0), $currency);
                $payRows .= "<tr><td>{$p['id']}</td><td>{$dt}</td><td>{$method}</td><td class=\"tr\">{$amt}</td></tr>";
            }
            $extra = "<div class=\"pay-title\">Payments</div>
                <table class=\"pay-table\">
                  <thead><tr><th>Ref#</th><th>Date</th><th>Paid By</th><th class=\"tr\">Amount</th></tr></thead>
                  <tbody>{$payRows}</tbody>
                </table>";
        }
        if (!empty($order['notes'])) {
            $n      = htmlspecialchars($order['notes']);
            $extra .= "<div class=\"notes-label\" style=\"margin-top:8px\">Terms</div><div class=\"notes-box\">{$n}</div>";
        }

        return self::page($title, $order['order_number'] ?? '—', $order['status'] ?? 'pending',
            $meta, $parties, self::itemsTable($columns, $rows), $totals, $extra);
    }

    public static function invoice(array $order): string
    {
        return self::order($order, true);
    }

    // =========================================================================
    // QUOTATION
    // =========================================================================

    public static function quotation(array $q): string
    {
        $currency = $q['currency_code'] ?? 'KES';
        $custName = trim(($q['customer_first_name'] ?? '') . ' ' . ($q['customer_last_name'] ?? '')) ?: 'Customer';

        $meta = [
            'Quotation #' => $q['quote_number'] ?? 'DRAFT',
            'Date'        => self::date($q['issued_at'] ?? $q['created_at'] ?? null),
            'Valid Until' => self::date($q['valid_until'] ?? null),
            'Served By'   => $q['served_by'] ?? '—',
            'Location'    => $q['outlet_name'] ?? '—',
        ];

        $parties = self::partyRow(
            'Prepared For', $custName,
            array_filter([$q['customer_email'] ?? '', $q['customer_phone'] ?? ''])
        );

        $columns = [
            ['key' => 'qty',        'label' => 'Qty',                 'align' => 'right', 'width' => '7%'],
            ['key' => 'desc',       'label' => 'Product / Description'],
            ['key' => 'unit_price', 'label' => 'Unit Price', 'align' => 'right', 'width' => '15%', 'mono' => true],
            ['key' => 'amount',     'label' => 'Amount',     'align' => 'right', 'width' => '15%', 'mono' => true],
        ];
        $rows = array_map(function ($item) use ($currency) {
            $sku = $item['sku'] ?? '';
            $var = $item['variant_name'] ?? '';
            return [
                'qty'        => number_format((float)($item['quantity'] ?? 0)),
                'desc'       => $item['product_name'] ?? '—',
                'desc_sub'   => trim(($var ? "Variant: {$var}" : '') . ($sku ? " | SKU: {$sku}" : '')),
                'unit_price' => self::money((float)($item['unit_price'] ?? 0), $currency),
                'amount'     => self::money((float)($item['total_price'] ?? 0), $currency),
            ];
        }, $q['items'] ?? []);

        $totals = [
            'subtotal' => $q['subtotal']        ?? 0,
            'discount' => $q['discount_amount'] ?? 0,
            'tax'      => $q['tax_amount']       ?? 0,
            'shipping' => $q['shipping_amount']  ?? 0,
            'total'    => $q['total_amount']     ?? 0,
            'currency' => $currency,
        ];

        $extra = '';
        if (!empty($q['notes'])) {
            $extra .= '<div class="notes-label" style="margin-top:8px">Notes</div><div class="notes-box">'
                . htmlspecialchars($q['notes']) . '</div>';
        }
        if (!empty($q['terms'])) {
            $extra .= '<div class="notes-label" style="margin-top:8px">Terms</div><div class="notes-box">'
                . htmlspecialchars($q['terms']) . '</div>';
        }
        $extra .= '<div class="notes-box" style="margin-top:10px;color:#666">This is a quotation, not a demand for '
            . 'payment. Prices are valid until the date shown above.</div>';

        return self::page('Quotation', $q['quote_number'] ?? 'DRAFT', $q['status'] ?? 'draft',
            $meta, $parties, self::itemsTable($columns, $rows), $totals, $extra);
    }

    // =========================================================================
    // RECEIPT
    // =========================================================================

    /**
     * Renders from a receipt sales_document's frozen snapshot (see ReceiptService),
     * so it always reflects exactly what was issued at payment time.
     */
    public static function receipt(array $snap): string
    {
        $currency = $snap['currency_code'] ?? 'KES';
        $cust     = $snap['customer'] ?? [];
        $custName = trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')) ?: 'Customer';
        $pay      = $snap['payment'] ?? [];

        $meta = [
            'Receipt #'   => $snap['receipt_number'] ?? '—',
            'Order #'     => $snap['order_number'] ?? '—',
            'Date'        => self::date($snap['issued_at'] ?? null),
            'Paid Via'    => ucfirst(str_replace('_', ' ', $pay['method'] ?? '—')),
        ];

        $parties = self::partyRow(
            'Received From', $custName,
            array_filter([$cust['email'] ?? '', $cust['phone'] ?? ''])
        );

        $columns = [
            ['key' => 'label',  'label' => 'Description'],
            ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'width' => '25%', 'mono' => true],
        ];
        $rows = [
            ['label' => 'Payment received' . (!empty($pay['reference']) ? ' (Ref: ' . $pay['reference'] . ')' : ''),
             'amount' => self::money((float)($pay['amount'] ?? 0), $currency)],
        ];

        $status = !empty($snap['fully_paid']) ? 'paid' : 'partial';
        $extra  = '<table class="totals-table" style="margin-top:8px">'
            . '<tr><td class="tl">Invoice Total</td><td class="tv">' . self::money((float)($snap['invoice_total'] ?? 0), $currency) . '</td></tr>'
            . '<tr><td class="tl">Paid To Date</td><td class="tv">' . self::money((float)($snap['paid_to_date'] ?? 0), $currency) . '</td></tr>'
            . '<tr class="total-line"><td class="tl">Balance Due</td><td class="tv">' . self::money((float)($snap['balance_due'] ?? 0), $currency) . '</td></tr>'
            . '</table>';

        return self::page('Receipt', $snap['receipt_number'] ?? '—', $status,
            $meta, $parties, self::itemsTable($columns, $rows), [], $extra);
    }

    // =========================================================================
    // SHIPMENT
    // =========================================================================

    public static function shipment(array $shipment): string
    {
        $s         = self::settings();
        $recipient = $shipment['recipient_name'] ?? ($shipment['order']['customer_name'] ?? '—');

        $meta = [
            'Shipment No.' => $shipment['shipment_number'] ?? '—',
            'Order Ref.'   => $shipment['order']['order_number'] ?? '—',
            'Carrier'      => $shipment['carrier'] ?? '—',
            'Tracking No.' => $shipment['tracking_number'] ?? '—',
            'Shipped Date' => self::date($shipment['shipped_at'] ?? null),
            'Est. Arrival' => self::date($shipment['estimated_delivery_date'] ?? null),
        ];

        $parties = self::partyRow(
            'Dispatched By', $s['app_name'] ?? 'Bethany House',
            array_filter([$s['app_address'] ?? '', $s['app_phone'] ?? '']),
            'Deliver To', $recipient,
            array_filter([$shipment['recipient_phone'] ?? '', $shipment['delivery_address'] ?? ''])
        );

        $columns = [
            ['key' => 'date',   'label' => 'Date',    'width' => '14%'],
            ['key' => 'status', 'label' => 'Status',  'width' => '18%'],
            ['key' => 'loc',    'label' => 'Location'],
            ['key' => 'note',   'label' => 'Note'],
        ];
        $rows = array_map(function ($t) {
            return [
                'date'   => self::date($t['created_at'] ?? null),
                'status' => ucfirst(str_replace('_', ' ', $t['status'] ?? '—')),
                'loc'    => $t['location'] ?? '—',
                'note'   => $t['note'] ?? '—',
            ];
        }, $shipment['tracking'] ?? []);

        $notes = '';
        if (!empty($shipment['notes'])) {
            $n     = htmlspecialchars($shipment['notes']);
            $notes = "<div class=\"notes-label\">Notes</div><div class=\"notes-box\">{$n}</div>";
        }

        return self::page('Shipment Note', $shipment['shipment_number'] ?? '—', $shipment['status'] ?? 'pending',
            $meta, $parties, self::itemsTable($columns, $rows), [], $notes);
    }

    // =========================================================================
    // ORDER RETURN
    // =========================================================================

    public static function orderReturn(array $ret): string
    {
        $currency = $ret['currency_code'] ?? 'KES';
        $s        = self::settings();
        $custName = $ret['customer_name'] ?? '—';

        $meta = [
            'Return Number' => $ret['return_number'] ?? '—',
            'Order Ref.'    => $ret['order']['order_number'] ?? ($ret['order_number'] ?? '—'),
            'Return Date'   => self::date($ret['created_at'] ?? null),
            'Reason'        => $ret['reason'] ?? '—',
            'Refund Amount' => self::money((float)($ret['refund_amount'] ?? 0), $currency),
        ];

        $parties = self::partyRow(
            'Processed By', $s['app_name'] ?? 'Bethany House', [],
            'Customer', $custName,
            array_filter([$ret['customer_email'] ?? ''])
        );

        $columns = [
            ['key' => 'desc',      'label' => 'Product'],
            ['key' => 'sku',       'label' => 'SKU',      'width' => '12%', 'mono' => true],
            ['key' => 'qty',       'label' => 'Qty',      'align' => 'right', 'width' => '7%'],
            ['key' => 'condition', 'label' => 'Condition','width' => '12%'],
            ['key' => 'reason',    'label' => 'Reason'],
        ];
        $rows = array_map(function ($item) {
            return [
                'desc'      => $item['product_name'] ?? ($item['variant']['product']['name'] ?? '—'),
                'sku'       => $item['sku'] ?? '—',
                'qty'       => number_format((float)($item['quantity'] ?? 0)),
                'condition' => $item['condition'] ?? '—',
                'reason'    => $item['reason'] ?? '—',
            ];
        }, $ret['items'] ?? []);

        return self::page('Order Return', $ret['return_number'] ?? '—', $ret['status'] ?? 'pending',
            $meta, $parties, self::itemsTable($columns, $rows));
    }

    // =========================================================================
    // PRODUCTION ORDER
    // =========================================================================

    public static function productionOrder(array $po): string
    {
        $s         = self::settings();
        $createdBy = trim(($po['created_by']['first_name'] ?? '') . ' ' . ($po['created_by']['last_name'] ?? '')) ?: '—';
        $productName = $po['product_name'] ?? '—';
        $sku         = $po['sku'] ?? ($po['product']['sku'] ?? '');
        $variant     = $po['variant_name'] ?? '';
        $outlet      = $po['outlet']['name'] ?? '—';

        $meta = [
            'Order Number' => $po['order_number'] ?? '—',
            'Due Date'     => self::date($po['due_date'] ?? null),
            'Priority'     => ucfirst($po['priority'] ?? '—'),
            'Quantity'     => number_format((float)($po['quantity'] ?? 0)),
            'Created By'   => $createdBy,
        ];

        $parties = self::partyRow(
            'Organisation', $s['app_name'] ?? 'Bethany House',
            array_filter([$s['app_address'] ?? '']),
            'Product', $productName,
            array_filter([
                $sku     ? "SKU: {$sku}"         : '',
                $variant ? "Variant: {$variant}"  : '',
                "Outlet: {$outlet}",
            ])
        );

        $columns = [
            ['key' => 'stage',    'label' => 'Stage',    'width' => '18%'],
            ['key' => 'task',     'label' => 'Task'],
            ['key' => 'assignee', 'label' => 'Assignee', 'width' => '18%'],
            ['key' => 'due',      'label' => 'Due',      'width' => '12%'],
            ['key' => 'status',   'label' => 'Status',   'width' => '12%'],
        ];
        $rows = array_map(function ($t) {
            return [
                'stage'    => $t['stage']['name']  ?? '—',
                'task'     => $t['name']            ?? '—',
                'assignee' => trim(($t['assigned_to']['first_name'] ?? '') . ' ' . ($t['assigned_to']['last_name'] ?? '')) ?: '—',
                'due'      => self::date($t['due_date'] ?? null),
                'status'   => ucfirst(str_replace('_', ' ', $t['status'] ?? '—')),
            ];
        }, $po['tasks'] ?? []);

        $extra = '';
        if (!empty($po['material_allocations'])) {
            $matRows = '';
            foreach ($po['material_allocations'] as $m) {
                $mname    = htmlspecialchars($m['material']['name'] ?? '—');
                $unit     = htmlspecialchars($m['material']['unit_of_measure'] ?? '');
                $required = number_format((float)($m['quantity_required'] ?? 0), 2);
                $issued   = number_format((float)($m['quantity_issued'] ?? 0), 2);
                $matRows .= "<tr><td>{$mname}</td><td class=\"tr\">{$required} {$unit}</td><td class=\"tr\">{$issued} {$unit}</td></tr>";
            }
            $extra = "<div class=\"pay-title\">Material Allocations</div>
                <table class=\"pay-table\">
                  <thead><tr><th>Material</th><th class=\"tr\">Required</th><th class=\"tr\">Issued</th></tr></thead>
                  <tbody>{$matRows}</tbody>
                </table>";
        }
        if (!empty($po['notes'])) {
            $n      = htmlspecialchars($po['notes']);
            $extra .= "<div class=\"notes-label\" style=\"margin-top:8px\">Notes</div><div class=\"notes-box\">{$n}</div>";
        }

        return self::page('Production Order', $po['order_number'] ?? '—', $po['status'] ?? 'draft',
            $meta, $parties, self::itemsTable($columns, $rows), [], $extra);
    }

    // =========================================================================
    // STOCK TRANSFER
    // =========================================================================

    public static function stockTransfer(array $transfer): string
    {
        $createdBy = trim(($transfer['created_by']['first_name'] ?? '') . ' ' . ($transfer['created_by']['last_name'] ?? '')) ?: '—';

        $meta = [
            'Transfer No.' => $transfer['transfer_number'] ?? '—',
            'Date'         => self::date($transfer['created_at'] ?? null),
            'Reference'    => $transfer['reference_number'] ?? '—',
            'Created By'   => $createdBy,
        ];

        $parties = self::partyRow(
            'From', $transfer['from_outlet']['name'] ?? '—', [],
            'To',   $transfer['to_outlet']['name']   ?? '—', []
        );

        $columns = [
            ['key' => 'product', 'label' => 'Product'],
            ['key' => 'sku',     'label' => 'SKU',  'width' => '14%', 'mono' => true],
            ['key' => 'qty',     'label' => 'Qty',  'align' => 'right', 'width' => '8%'],
            ['key' => 'notes',   'label' => 'Notes'],
        ];
        $rows = array_map(function ($item) {
            return [
                'product' => $item['product_name'] ?? ($item['inventory_item']['product']['name'] ?? '—'),
                'sku'     => $item['sku'] ?? '—',
                'qty'     => number_format((float)($item['quantity'] ?? 0)),
                'notes'   => $item['notes'] ?? '—',
            ];
        }, $transfer['items'] ?? []);

        $notes = '';
        if (!empty($transfer['notes'])) {
            $n     = htmlspecialchars($transfer['notes']);
            $notes = "<div class=\"notes-label\">Notes</div><div class=\"notes-box\">{$n}</div>";
        }

        return self::page('Stock Transfer', $transfer['transfer_number'] ?? '—', $transfer['status'] ?? 'pending',
            $meta, $parties, self::itemsTable($columns, $rows), [], $notes);
    }

    // =========================================================================
    // STOCK ADJUSTMENT
    // =========================================================================

    public static function stockAdjustment(array $adj): string
    {
        $s          = self::settings();
        $approvedBy = trim(($adj['approved_by']['first_name'] ?? '') . ' ' . ($adj['approved_by']['last_name'] ?? '')) ?: '—';

        $meta = [
            'Adjustment No.' => $adj['adjustment_number'] ?? ($adj['reference'] ?? '—'),
            'Date'           => self::date($adj['created_at'] ?? null),
            'Reason'         => $adj['reason'] ?? ($adj['reason_code'] ?? '—'),
            'Outlet'         => $adj['outlet']['name'] ?? '—',
            'Approved By'    => $approvedBy,
        ];

        $parties = self::partyRow(
            'Organisation', $s['app_name'] ?? 'Bethany House',
            array_filter([$adj['outlet']['name'] ?? '']),
            'Approved By', $approvedBy, []
        );

        $columns = [
            ['key' => 'product',    'label' => 'Product'],
            ['key' => 'sku',        'label' => 'SKU',       'width' => '12%', 'mono' => true],
            ['key' => 'type',       'label' => 'Type',      'width' => '10%'],
            ['key' => 'qty_before', 'label' => 'Before',    'align' => 'right', 'width' => '9%'],
            ['key' => 'qty_adj',    'label' => 'Adjustment','align' => 'right', 'width' => '11%'],
            ['key' => 'qty_after',  'label' => 'After',     'align' => 'right', 'width' => '9%'],
        ];
        $rows = array_map(function ($item) {
            $before = (float)($item['quantity_before'] ?? 0);
            $change = (float)($item['quantity'] ?? ($item['quantity_adjusted'] ?? 0));
            return [
                'product'    => $item['product_name'] ?? ($item['inventory_item']['product']['name'] ?? '—'),
                'sku'        => $item['sku'] ?? '—',
                'type'       => ucfirst($item['adjustment_type'] ?? ($item['type'] ?? '—')),
                'qty_before' => number_format($before, 2),
                'qty_adj'    => ($change >= 0 ? '+' : '') . number_format($change, 2),
                'qty_after'  => number_format($before + $change, 2),
            ];
        }, $adj['items'] ?? []);

        $notes = '';
        if (!empty($adj['notes'])) {
            $n     = htmlspecialchars($adj['notes']);
            $notes = "<div class=\"notes-label\">Notes</div><div class=\"notes-box\">{$n}</div>";
        }

        return self::page(
            'Stock Adjustment', $adj['adjustment_number'] ?? ($adj['reference'] ?? '—'),
            $adj['status'] ?? 'pending',
            $meta, $parties, self::itemsTable($columns, $rows), [], $notes);
    }

    // =========================================================================
    // EXPENSE
    // =========================================================================

    public static function expense(array $exp): string
    {
        $currency    = $exp['currency_code'] ?? 'KES';
        $s           = self::settings();
        $submittedBy = trim(($exp['submitted_by']['first_name'] ?? '') . ' ' . ($exp['submitted_by']['last_name'] ?? '')) ?: '—';

        $meta = [
            'Expense No.'  => $exp['expense_number'] ?? ($exp['reference_number'] ?? '—'),
            'Date'         => self::date($exp['expense_date'] ?? ($exp['created_at'] ?? null)),
            'Category'     => $exp['category']['name'] ?? '—',
            'Total Amount' => self::money((float)($exp['total_amount'] ?? ($exp['amount'] ?? 0)), $currency),
            'Submitted By' => $submittedBy,
        ];

        $parties = self::partyRow(
            'Organisation', $s['app_name'] ?? 'Bethany House',
            array_filter([$s['app_address'] ?? '']),
            'Submitted By', $submittedBy,
            array_filter([
                $exp['submitted_by']['email'] ?? '',
                isset($exp['department']) ? 'Dept: ' . $exp['department'] : '',
            ])
        );

        $columns = [
            ['key' => 'desc',     'label' => 'Description'],
            ['key' => 'category', 'label' => 'Category', 'width' => '16%'],
            ['key' => 'amount',   'label' => 'Amount',   'align' => 'right', 'width' => '14%', 'mono' => true],
            ['key' => 'notes',    'label' => 'Notes',    'width' => '22%'],
        ];

        $lineItems = !empty($exp['line_items']) ? $exp['line_items'] : [[
            'description' => $exp['description'] ?? ($exp['title'] ?? '—'),
            'category'    => $exp['category'] ?? [],
            'amount'      => $exp['amount'] ?? 0,
            'notes'       => $exp['notes'] ?? '',
        ]];

        $rows = array_map(function ($item) use ($currency) {
            return [
                'desc'     => $item['description'] ?? '—',
                'category' => $item['category']['name'] ?? '—',
                'amount'   => self::money((float)($item['amount'] ?? 0), $currency),
                'notes'    => $item['notes'] ?? '—',
            ];
        }, $lineItems);

        $totals = ['total' => $exp['total_amount'] ?? ($exp['amount'] ?? 0), 'currency' => $currency];

        $notes = '';
        if (!empty($exp['notes'])) {
            $n     = htmlspecialchars($exp['notes']);
            $notes = "<div class=\"notes-label\">Notes</div><div class=\"notes-box\">{$n}</div>";
        }

        return self::page(
            'Expense Report', $exp['expense_number'] ?? ($exp['reference_number'] ?? '—'),
            $exp['status'] ?? 'pending',
            $meta, $parties, self::itemsTable($columns, $rows), $totals, $notes);
    }
}