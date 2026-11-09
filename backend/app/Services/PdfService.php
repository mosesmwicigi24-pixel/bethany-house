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

    /** "KES 4,500" — cents only when there are some (CustomerMoney::format). */
    protected static function money(float $amount, string $currency = 'KES'): string
    {
        return \App\Support\CustomerMoney::format($amount, $currency);
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
            'cancelled', 'rejected', 'voided', 'void', 'failed'                => '#dc2626',
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
        $year        = date('Y');
        $generatedAt = date('d M Y, H:i');

        $logo     = self::logoHtml($logoUrl, $appName);
        $logoChip = $logo
            ? '<div style="background:#fff;border-radius:8px;padding:6px 10px;display:inline-block;">' . $logo . '</div>'
            : '';
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
            $metaRows .= "<td><span class=\"ml\">{$label}</span><span class=\"mv\">{$v}</span></td>";
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

        $badge = "<span style=\"display:inline-block;padding:3px 12px;border-radius:20px;font-size:8px;font-weight:700;letter-spacing:1px;background:{$statusColour};color:#fff;\">{$statusLabel}</span>";

        // Design language: Bethany navy (#152441, from the logo) anchors the
        // document; the brand gold (#D98A2A) appears only as accents — the rule
        // under the header, the doc number, dividers — never as a fill flood.
        // Soft 8-10px corners, zebra rows instead of grid lines, air instead of
        // boxes. dompdf constraints honoured: table layouts (no flex), solid
        // fills (no gradients/shadows), DejaVu Sans (bundled, full glyph set).
        $css = '
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:10.5px;color:#25303f;background:#fff;line-height:1.5}
table{border-collapse:collapse;width:100%}
.page{padding:26px 30px;max-width:780px;margin:0 auto}

.banner{background:#152441;border-radius:12px;padding:16px 20px;color:#fff}
.banner-table{width:100%}
.banner-left{vertical-align:middle;width:55%}
.banner-right{vertical-align:middle;text-align:right}
.org-name{font-size:14px;font-weight:700;color:#fff;margin-top:5px}
.org-contact{font-size:8.5px;color:#aeb9cc;line-height:1.65;margin-top:2px}
.doc-title{font-size:21px;font-weight:700;color:#fff;letter-spacing:.2px}
.doc-number{font-size:10.5px;font-weight:700;color:#E8A857;margin-top:2px}
.status-wrap{margin-top:7px}

.gold-rule{border:none;border-top:2.5px solid #D98A2A;border-radius:2px;margin:14px 2px 12px;width:64px}

.meta-card{margin:0 0 12px}
.meta-table{width:100%;background:#f7f8fb;border-radius:10px}
.meta-table td{padding:8px 6px;text-align:center;vertical-align:top}
.meta-table .ml{display:block;font-size:7.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#8b95a6;margin-bottom:2px}
.meta-table .mv{display:block;font-size:10px;font-weight:700;color:#25303f;white-space:nowrap}

.party-row{display:table;width:100%;margin-bottom:14px;border-spacing:0}
.party-cell{display:table-cell;width:50%;vertical-align:top;padding-right:12px}
.party-cell:last-child{padding-right:0}
.party-box{background:#faf7f1;border:1px solid #f0e6d6;border-radius:10px;padding:11px 14px;min-height:64px}
.party-label{font-size:7.5px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#B9721E;margin-bottom:4px}
.party-name{font-size:11.5px;font-weight:700;color:#152441}
.party-detail{font-size:9px;color:#5d6878;line-height:1.65;margin-top:3px}

.items-table{width:100%;margin-top:2px}
.items-table thead th{background:#152441;color:#fff;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;padding:8px 9px;text-align:left}
.items-table thead th:first-child{border-radius:8px 0 0 8px}
.items-table thead th:last-child{border-radius:0 8px 8px 0}
.items-table tbody td{font-size:10px;padding:8px 9px;vertical-align:top;border-bottom:1px solid #eef1f5;color:#3a4656}
.items-table tbody tr.zebra td{background:#f8f9fb}
.items-table tbody tr:last-child td{border-bottom:none}
.row-num{color:#a7b0bf;font-weight:700;font-size:9px}
.tr{text-align:right}
.tc{text-align:center}
.num{white-space:nowrap}
.sub-desc{font-size:8.5px;color:#8b95a6;margin-top:2px}

.bottom-row{display:table;width:100%;margin-top:12px}
.bottom-left{display:table-cell;vertical-align:top;width:52%;padding-right:18px}
.bottom-right{display:table-cell;vertical-align:top;width:48%}
.totals-table{width:100%;background:#f7f8fb;border-radius:10px}
.totals-table td{padding:6px 13px;font-size:10px}
.totals-table .tl{color:#5d6878}
.totals-table .tv{text-align:right;font-weight:700;color:#25303f;white-space:nowrap}
.totals-table tr.total-line td{background:#152441;color:#fff;font-size:12.5px;font-weight:700;padding:10px 13px}
.totals-table tr.total-line td.tl{border-radius:0 0 0 10px;color:#c9d2e0;font-size:9px;letter-spacing:1px;text-transform:uppercase}
.totals-table tr.total-line td.tv{border-radius:0 0 10px 0;color:#fff}
.totals-table tr.total-line td .gold{color:#E8A857}

.pay-table{width:100%;margin-top:5px;font-size:9.5px}
.pay-table th{text-align:left;font-size:7.5px;text-transform:uppercase;letter-spacing:.7px;color:#8b95a6;padding:3px 5px;border-bottom:1.5px solid #e4e8ee}
.pay-table td{padding:4px 5px;border-bottom:1px solid #f0f2f6}
.pay-title{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#B9721E;margin-top:10px;margin-bottom:3px}
.notes-box{margin-top:4px;font-size:9.5px;color:#5d6878;line-height:1.6;background:#f7f8fb;border-radius:8px;padding:9px 12px}
.notes-label{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#B9721E;margin-top:10px;margin-bottom:3px}

.sig-row{display:table;width:100%;margin-top:26px}
.sig-cell{display:table-cell;width:33%;font-size:8.5px;color:#8b95a6;padding-right:22px}
.sig-line{border-bottom:1px solid #c8cfda;margin-bottom:5px;height:26px}

.footer{margin-top:18px;padding-top:9px;border-top:1px solid #eef1f5;font-size:8px;color:#a7b0bf;display:table;width:100%}
.footer-l{display:table-cell;text-align:left}
.footer-r{display:table-cell;text-align:right}
.footer .gold-dot{color:#D98A2A;font-weight:700}
@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.page{padding:14px}}';

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
  <div class="banner">
    <table class="banner-table"><tr>
      <td class="banner-left">
        {$logoChip}
        <div class="org-name">{$appName}</div>
        <div class="org-contact">{$orgLines}</div>
      </td>
      <td class="banner-right">
        <div class="doc-title">{$docTitle}</div>
        <div class="doc-number">{$docNumber}</div>
        <div class="status-wrap">{$badge}</div>
      </td>
    </tr></table>
  </div>
  <hr class="gold-rule">
  <div class="meta-card"><table class="meta-table"><tr>{$metaRows}</tr></table></div>
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
    <div class="footer-l">{$docNumber} <span class="gold-dot">&bull;</span> {$appName}</div>
    <div class="footer-r">Generated {$generatedAt} <span class="gold-dot">&bull;</span> &copy; {$year} {$appName}</div>
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
        // Every document gets a row-number column for free — clean, consistent
        // numbering was one of the explicit asks. Numeric/mono cells are
        // nowrap so amounts never fold onto two lines mid-figure.
        $thead = '<th style="text-align:center" width="4%">#</th>';
        foreach ($columns as $col) {
            $align = $col['align'] ?? 'left';
            $w     = isset($col['width']) ? " width=\"{$col['width']}\"" : '';
            $thead .= "<th style=\"text-align:{$align}\"{$w}>{$col['label']}</th>";
        }

        $tbody = '';
        foreach ($rows as $i => $row) {
            $zebra  = $i % 2 === 1 ? ' class="zebra"' : '';
            $tbody .= "<tr{$zebra}>";
            $tbody .= '<td class="tc row-num">' . ($i + 1) . '</td>';
            foreach ($columns as $col) {
                $align  = $col['align'] ?? 'left';
                $nowrap = (($col['mono'] ?? false) || $align === 'right') ? ' num' : '';
                $val    = $row[$col['key']] ?? '—';
                $sub    = isset($row[$col['key'] . '_sub']) && $row[$col['key'] . '_sub']
                    ? '<div class="sub-desc">' . htmlspecialchars($row[$col['key'] . '_sub']) . '</div>'
                    : '';
                $tbody .= "<td class=\"{$nowrap}\" style=\"text-align:{$align}\">" . htmlspecialchars((string)$val) . "{$sub}</td>";
            }
            $tbody .= '</tr>';
        }

        if (!$rows) {
            $colspan = count($columns) + 1;
            $tbody   = "<tr><td colspan=\"{$colspan}\" style=\"text-align:center;color:#a7b0bf;padding:14px\">No items.</td></tr>";
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
            'PO Number'         => ($po['po_number'] ?? '—') . (!empty($po['revision']) ? ' · Rev ' . (int) $po['revision'] : ''),
            'Payment Terms'     => $po['payment_terms'] ?? '—',
            'Payment Status'    => ucfirst(str_replace('_', ' ', $po['payment_status'] ?? '—')),
        ];
        // The supplier's own invoice, once recorded (2026-10-16).
        if (!empty($po['supplier_invoice_number'])) {
            $meta['Supplier Invoice'] = $po['supplier_invoice_number']
                . (!empty($po['supplier_invoice_date']) ? ' · ' . self::date($po['supplier_invoice_date']) : '');
        }

        $parties = self::partyRow(
            'From', $s['app_name'] ?? 'Bethany House',
            array_filter([$s['app_address'] ?? '', $s['app_email'] ?? '', $s['app_phone'] ?? '']),
            'Supplier', $sup['name'] ?? '—',
            array_filter([$sup['company_code'] ?? '', $sup['email'] ?? '', $sup['phone'] ?? '', $sup['address'] ?? ''])
        );

        $columns = [
            ['key' => 'desc',       'label' => 'Description'],
            ['key' => 'sku',        'label' => 'SKU / Code',  'width' => '14%'],
            ['key' => 'qty',        'label' => 'Qty',         'align' => 'right', 'width' => '13%'],
            ['key' => 'unit_price', 'label' => 'Unit Price',  'align' => 'right', 'width' => '15%'],
            ['key' => 'total',      'label' => 'Amount',      'align' => 'right', 'width' => '16%'],
        ];
        $rows = array_map(function ($item) use ($currency) {
            $isMaterial = ($item['item_type'] ?? '') === 'material';
            $unit       = $isMaterial ? ($item['material']['unit_of_measure'] ?? '') : '';
            return [
                'desc'       => $item['description'] ?? ($item['product']['name'] ?? ($item['material']['name'] ?? '—')),
                // Type reads better as a sub-line than as its own cramped column.
                'desc_sub'   => $isMaterial ? 'Raw material' : 'Product',
                'sku'        => $item['product']['sku'] ?? ($item['material']['code'] ?? '—'),
                // The unit belongs WITH the quantity — "100.00 meters", not a
                // stray "meters" sitting in the SKU column.
                'qty'        => trim(number_format((float)($item['quantity'] ?? 0), 2) . ' ' . $unit),
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
            'GRN Number'    => ($grn['grn_number'] ?? '—') . (!empty($grn['revision']) ? ' · Rev ' . (int) $grn['revision'] : ''),
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
        // A quote-born order's issued invoice number, and its revision (2026-10-16).
        if ($isInvoice && !empty($order['invoice_number'])) {
            $meta = ['Invoice #' => $order['invoice_number'] . (!empty($order['invoice_revision']) ? ' · Rev ' . (int) $order['invoice_revision'] : '')] + $meta;
        }

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
        if ($isInvoice && array_key_exists('balance_due', $order)) {
            // An invoice asks to be paid (owner, 2026-10-15): only money that
            // has SETTLED is shown as received, and what is still owed says so.
            $due    = (float) $order['balance_due'];
            $extra  = self::paymentsReceivedHtml($order['settled_payments'] ?? [], $currency)
                . '<table class="totals-table" style="margin-top:8px">'
                . '<tr><td class="tl">Total Paid</td><td class="tv">' . self::money((float) ($order['total_paid'] ?? 0), $currency) . '</td></tr>'
                . ($due < 0.01
                    ? '<tr class="total-line"><td class="tl">PAID IN FULL</td><td class="tv">' . self::money(0, $currency) . ' due</td></tr>'
                    : '<tr class="total-line"><td class="tl">Amount Due</td><td class="tv">' . self::money($due, $currency) . '</td></tr>')
                . '</table>'
                . ($due < 0.01 ? '' : '<div class="notes-box" style="margin-top:10px">Please pay the amount due of <strong>'
                    . self::money($due, $currency) . '</strong>, quoting order <strong>'
                    . htmlspecialchars((string) ($order['order_number'] ?? '')) . '</strong>. Thank you.</div>');
        } elseif (!empty($order['payments'])) {
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

    /**
     * "Payments received" for a customer document — the receipt and the
     * invoice share it so they can never disagree. Rows are SETTLED payments
     * only (status paid); a refund on one is shown as its own negative row.
     */
    protected static function paymentsReceivedHtml(array $payments, string $currency): string
    {
        $rows = '';
        foreach ($payments as $p) {
            $method   = htmlspecialchars(ucfirst(str_replace('_', ' ', $p['payment_method'] ?? '—')));
            $ref      = htmlspecialchars((string) ($p['provider_reference'] ?? ''));
            $ref      = $ref !== '' ? $ref : '—';
            $dt       = self::date($p['paid_at'] ?? ($p['created_at'] ?? null));
            $refunded = (float) ($p['refund_amount'] ?? 0);
            // What arrived, in its own currency (PaymentCredit keeps both).
            $pc       = $p['currency_code'] ?? $currency;
            $counted  = (!empty($p['order_currency_code']) && strtoupper((string) $pc) !== strtoupper($currency))
                ? '<div class="sub-desc">counts ' . self::money((float) ($p['order_amount'] ?? 0), $currency) . '</div>' : '';
            $rows    .= "<tr><td>{$dt}</td><td>{$method}</td><td>{$ref}</td><td class=\"tr\">"
                . self::money((float) ($p['amount'] ?? 0), $pc) . $counted . '</td></tr>';
            if ($refunded > 0.005) {
                $rows .= "<tr><td>{$dt}</td><td>Refund</td><td>{$ref}</td><td class=\"tr\">- "
                    . self::money($refunded, $pc) . '</td></tr>';
            }
        }

        return '<div class="pay-title">Payments received</div>'
            . '<table class="pay-table"><thead><tr><th>Date</th><th>Paid By</th><th>Reference</th><th class="tr">Amount</th></tr></thead>'
            . '<tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="4">No payment received yet.</td></tr>') . '</tbody></table>';
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
            'Quotation #' => ($q['quote_number'] ?? 'DRAFT') . (!empty($q['revision']) ? ' · Rev ' . (int) $q['revision'] : ''),
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
        if (($q['status'] ?? null) === 'void') {
            // Voided with its invoice (2026-10-26): the copy says so plainly.
            $extra .= '<div class="notes-box" style="margin-top:10px;color:#dc2626;font-weight:700">This quotation was voided'
                . (!empty($q['voided_at']) ? ' on ' . self::date($q['voided_at']) : '')
                . ' and is no longer valid.</div>';
        } else {
            $extra .= '<div class="notes-box" style="margin-top:10px;color:#666">This is a quotation, not a demand for '
                . 'payment. Prices are valid until the date shown above.</div>';
        }

        return self::page('Quotation', $q['quote_number'] ?? 'DRAFT', $q['status'] ?? 'draft',
            $meta, $parties, self::itemsTable($columns, $rows), $totals, $extra);
    }

    // =========================================================================
    // RECEIPT
    // =========================================================================

    /**
     * Renders from a receipt sales_document's frozen snapshot (see ReceiptService),
     * so it always reflects exactly what was issued at payment time. One payment,
     * and the invoice position right after it.
     */
    public static function receipt(array $snap): string
    {
        $currency = $snap['currency_code'] ?? 'KES';
        $cust     = $snap['customer'] ?? [];
        $pay      = $snap['payment'] ?? [];
        $balance  = (float) ($snap['balance_due'] ?? 0);
        $paidToDate = (float) ($snap['paid_to_date'] ?? 0);

        return self::receiptDocument([
            'number'      => $snap['receipt_number'] ?? '—',
            'revision'    => (int) ($snap['revision'] ?? 0),
            'state'       => !empty($snap['fully_paid']) ? 'paid' : ($paidToDate > 0.005 ? 'partial' : 'unpaid'),
            'currency'    => $currency,
            'facts'       => array_filter([
                'Receipt date' => self::date($snap['issued_at'] ?? null),
                'Payment date' => self::date($pay['paid_at'] ?? ($snap['issued_at'] ?? null)),
                'Order no.'    => $snap['order_number'] ?? '—',
                'Served by'    => $snap['served_by'] ?? null,
            ]),
            'customer'    => trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')) ?: 'Customer',
            'contacts'    => array_filter([$cust['email'] ?? '', $cust['phone'] ?? '']),
            'items'       => [],
            'totals'      => null,
            'payments'    => [[
                'date'      => $pay['paid_at'] ?? ($snap['issued_at'] ?? null),
                'method'    => $pay['method'] ?? null,
                'reference' => $pay['reference'] ?? null,
                // Snapshots written since 2026-11-05 carry the payment's own currency.
                'amount'    => (float) ($pay['amount'] ?? 0),
                'currency'  => $pay['currency_code'] ?? $currency,
                'counted'   => null,
                'refunded'  => 0,
            ]],
            'order_total' => (float) ($snap['invoice_total'] ?? 0),
            'paid'        => $paidToDate,
            'balance'     => $balance,
            'paid_label'  => 'Received to date',
        ]);
    }

    /**
     * One receipt for a whole order (owner, 2026-10-15): what was bought,
     * every payment that has SETTLED (SettledPayment, net of refunds — the
     * same money truth as Order::totalPaid), and the balance still due, or
     * PAID IN FULL. Built from the order as it stands, so a reprint shows
     * today's position. Pending, awaiting-approval, rejected and voided
     * payments are not money received and are left off.
     *
     * $order needs: items, settled_payments[{paid_at, payment_method,
     * provider_reference, amount, currency_code, order_amount,
     * order_currency_code, refund_amount}], total_paid, balance_due.
     */
    public static function orderReceipt(array $order): string
    {
        $currency = $order['currency_code'] ?? 'KES';
        $balance  = (float) ($order['balance_due'] ?? 0);
        $paid     = (float) ($order['total_paid'] ?? 0);

        $items = array_map(function ($item) {
            $qty   = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['unit_price'] ?? 0);
            $disc  = (float) ($item['discount_amount'] ?? 0);
            $line  = (float) ($item['subtotal'] ?? ($qty * $price - $disc));
            return [
                'name'    => $item['product_name'] ?? '—',
                'variant' => $item['variant_name'] ?? '',
                'sku'     => $item['sku'] ?? '',
                'qty'     => $qty,
                // The price each unit was actually sold at (after any line discount),
                // so qty × unit always equals the line amount shown beside it.
                'unit'    => $qty > 0 ? $line / $qty : $price,
                'line'    => $line,
            ];
        }, $order['items'] ?? []);

        $payments = array_map(fn ($p) => [
            'date'      => $p['paid_at'] ?? ($p['created_at'] ?? null),
            'method'    => $p['payment_method'] ?? null,
            'reference' => $p['provider_reference'] ?? null,
            'amount'    => (float) ($p['amount'] ?? 0),
            'currency'  => $p['currency_code'] ?? $currency,
            'counted'   => !empty($p['order_currency_code']) ? (float) ($p['order_amount'] ?? 0) : null,
            'refunded'  => (float) ($p['refund_amount'] ?? 0),
        ], $order['settled_payments'] ?? []);

        return self::receiptDocument([
            'number'      => $order['order_number'] ?? '—',
            'revision'    => 0,
            'state'       => $balance < 0.01 ? 'paid' : ($paid > 0.005 ? 'partial' : 'unpaid'),
            'currency'    => $currency,
            'facts'       => array_filter([
                'Receipt date' => self::date(now()->toIso8601String()),
                'Order date'   => self::date($order['created_at'] ?? null),
                'Order no.'    => $order['order_number'] ?? '—',
                'Location'     => $order['outlet_name'] ?? null,
                'Served by'    => $order['cashier_name'] ?? null,
            ]),
            'customer'    => $order['customer_name'] ?? 'Customer',
            'contacts'    => array_filter([$order['customer_email'] ?? '', $order['customer_phone'] ?? '']),
            'items'       => $items,
            'payments'    => $payments,
            'totals'      => [
                'subtotal'      => (float) ($order['subtotal'] ?? 0),
                'discount'      => (float) ($order['discount_amount'] ?? 0),
                'tax'           => (float) ($order['tax_amount'] ?? 0),
                'tax_inclusive' => !empty($order['prices_include_tax']),
                'shipping'      => (float) ($order['shipping_amount'] ?? 0),
            ],
            'order_total' => (float) ($order['total_amount'] ?? 0),
            'paid'        => $paid,
            'balance'     => $balance,
            'paid_label'  => 'Amount received',
        ]);
    }

    /**
     * The receipt page (owner, 2026-11-05: "a professionally designed
     * corporate financial document"). Its own layout, not the shared
     * page() shell: a white letterhead, one line of transaction facts, the
     * customer in a single line, a ruled items table, then payments beside
     * the money summary. Every figure is passed in from the order, its
     * settled payments or the frozen snapshot — nothing here computes money
     * except presenting it. dompdf-safe: tables only, solid colours,
     * DejaVu Sans (full glyph set).
     *
     * Tax is shown the way the order counted it (OrderTotals): ADDED to the
     * total only when prices exclude it; when prices include it, it is shown
     * as "included", never as a line that seems to add.
     */
    protected static function receiptDocument(array $r): string
    {
        $s        = self::settings();
        $ccy      = (string) $r['currency'];
        $m        = fn (float $v, ?string $c = null) => htmlspecialchars(self::money($v, $c ?? $ccy));
        $e        = fn ($v) => htmlspecialchars((string) $v);
        $appName  = $e($s['app_name'] ?: 'Bethany House');
        $address  = $e(trim(($s['app_address'] ?? '') . (!empty($s['app_city']) ? ', ' . $s['app_city'] : ''), ', '));
        $email    = $e($s['app_email'] ?? '');
        $phone    = $e($s['app_phone'] ?? '');
        $website  = '';
        if ($url = config('app.storefront_url')) {
            $website = $e(preg_replace('#^https?://(www\.)?#', '', rtrim((string) $url, '/')));
        }
        $logo = '';
        if (!empty($s['app_logo_url'])) {
            // Height only: the logo keeps its own proportions.
            $logo = str_replace(
                'style="max-height:52px;max-width:140px;object-fit:contain;display:block;"',
                'style="height:46px;display:block;"',
                self::logoHtml($s['app_logo_url'], $appName)
            );
        }

        // ── Status ────────────────────────────────────────────────────────
        [$stateLabel, $stateFg, $stateBg] = match ($r['state']) {
            'paid'    => ['PAID', '#ffffff', '#1f7a4c'],
            'partial' => ['PARTIALLY PAID', '#ffffff', '#C08A2E'],
            default   => ['UNPAID', '#ffffff', '#b42318'],
        };
        $number   = $e($r['number']) . (!empty($r['revision']) ? ' <span class="muted">· Rev ' . (int) $r['revision'] . '</span>' : '');

        // ── Letterhead ────────────────────────────────────────────────────
        $contact = implode('<br>', array_filter([
            $address,
            implode('&nbsp;&nbsp;·&nbsp;&nbsp;', array_filter([$phone, $email])),
        ]));
        $brand = $logo !== '' ? $logo : "<div class=\"brand-name\">{$appName}</div>";

        // ── Facts strip ───────────────────────────────────────────────────
        $facts = '';
        $n = max(1, count($r['facts']));
        $w = round(100 / $n, 2);
        foreach ($r['facts'] as $label => $value) {
            $facts .= "<td style=\"width:{$w}%\"><div class=\"k\">" . $e($label) . '</div><div class="v">' . $e($value) . '</div></td>';
        }

        // ── Customer ──────────────────────────────────────────────────────
        $contacts = $r['contacts'] ? '<div class="cust-lines">' . implode('&nbsp;&nbsp;·&nbsp;&nbsp;', array_map($e, $r['contacts'])) . '</div>' : '';

        // ── Items ─────────────────────────────────────────────────────────
        $itemsHtml = '';
        if ($r['items']) {
            $rows = '';
            foreach ($r['items'] as $i => $it) {
                $sub = implode('&nbsp;&nbsp;·&nbsp;&nbsp;', array_filter([
                    $it['variant'] !== '' ? $e($it['variant']) : '',
                    $it['sku'] !== '' ? 'SKU ' . $e($it['sku']) : '',
                ]));
                $qty = abs($it['qty'] - round($it['qty'])) < 0.001 ? number_format($it['qty']) : number_format($it['qty'], 2);
                $rows .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>'
                    . '<td class="c-no">' . ($i + 1) . '</td>'
                    . '<td class="c-desc"><div class="item">' . $e($it['name']) . '</div>' . ($sub ? "<div class=\"item-sub\">{$sub}</div>" : '') . '</td>'
                    . "<td class=\"c-qty\">{$qty}</td>"
                    . '<td class="c-num">' . $m($it['unit']) . '</td>'
                    . '<td class="c-num strong">' . $m($it['line']) . '</td>'
                    . '</tr>';
            }
            $itemsHtml = '<div class="section-title">Items</div>'
                . '<table class="items"><thead><tr>'
                . '<th class="c-no">#</th><th class="c-desc">Description</th><th class="c-qty">Qty</th>'
                . '<th class="c-num">Unit price</th><th class="c-num">Amount</th>'
                . "</tr></thead><tbody>{$rows}</tbody></table>";
        }

        // ── Payments received ─────────────────────────────────────────────
        $payRows = '';
        foreach ($r['payments'] as $p) {
            $method = $e(ucwords(str_replace(['_', '-'], ' ', (string) ($p['method'] ?: '—'))));
            $ref    = trim((string) ($p['reference'] ?? ''));
            $pc     = strtoupper((string) ($p['currency'] ?: $ccy));
            // A payment made in another currency shows what arrived, and what
            // it counts for on this order (PaymentCredit) beneath it.
            $counted = ($p['counted'] !== null && $pc !== strtoupper($ccy))
                ? '<div class="pay-sub">counts ' . $m((float) $p['counted']) . '</div>' : '';
            $payRows .= '<tr>'
                . '<td class="p-date">' . self::date($p['date']) . '</td>'
                . "<td><div>{$method}</div>" . ($ref !== '' ? '<div class="pay-sub">' . $e($ref) . '</div>' : '') . '</td>'
                . '<td class="c-num">' . $m((float) $p['amount'], $pc) . $counted . '</td>'
                . '</tr>';
            if ($p['refunded'] > 0.005) {
                $payRows .= '<tr><td class="p-date">' . self::date($p['date']) . '</td><td>Refund</td>'
                    . '<td class="c-num">&minus; ' . $m((float) $p['refunded'], $pc) . '</td></tr>';
            }
        }
        if ($payRows === '') {
            $payRows = '<tr><td colspan="3" class="muted">No payment received yet.</td></tr>';
        }
        $paymentsHtml = '<div class="section-title">Payments received</div>'
            . '<table class="pays"><thead><tr><th>Date</th><th>Method &amp; reference</th><th class="c-num">Amount</th></tr></thead>'
            . "<tbody>{$payRows}</tbody></table>";

        // ── Money summary ─────────────────────────────────────────────────
        $sum = '';
        $line = fn (string $k, string $v, string $cls = '') => "<tr class=\"{$cls}\"><td class=\"s-k\">{$k}</td><td class=\"s-v\">{$v}</td></tr>";
        $t = $r['totals'];
        $taxNote = '';
        if ($t !== null) {
            $sum .= $line('Subtotal', $m($t['subtotal']));
            if ($t['discount'] > 0.005) $sum .= $line('Discount', '&minus; ' . $m($t['discount']));
            if ($t['tax'] > 0.005) {
                if ($t['tax_inclusive']) {
                    $taxNote = '<tr class="note"><td class="s-k" colspan="2">Prices include tax of ' . $m($t['tax']) . '</td></tr>';
                } else {
                    $sum .= $line('Tax', $m($t['tax']));
                }
            }
            if ($t['shipping'] > 0.005) $sum .= $line('Shipping', $m($t['shipping']));
        }
        $sum .= $line('Total', $m($r['order_total']), 'grand');
        $sum .= $taxNote;
        $sum .= $line($e($r['paid_label']), $m($r['paid']), 'gap');
        if ($r['balance'] < 0.01) {
            $over = round($r['paid'] - $r['order_total'], 2);
            if ($over >= 0.01) {
                $sum .= $line('Received above total', $m($over));
            }
            $settle = '<table class="settle paid"><tr><td class="s-k">PAID IN FULL</td><td class="s-v">' . $m(0) . ' due</td></tr></table>';
        } else {
            $settle = '<table class="settle due"><tr><td class="s-k">Balance due</td><td class="s-v">' . $m($r['balance']) . '</td></tr></table>';
        }

        // ── Footer ────────────────────────────────────────────────────────
        $footRight = implode('&nbsp;&nbsp;·&nbsp;&nbsp;', array_filter([$website, $email, $phone]));
        $generated = now()->format('d M Y, H:i');
        $title     = 'Receipt ' . $e($r['number']);
        $orgName   = $logo !== '' ? "<span class=\"name\">{$appName}</span><br>" : '';

        $css = '
@page{margin:34px 0 34px}
body,div,table,td,th,tr{margin:0;padding:0}
body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:9.5px;color:#2b3442;line-height:1.45;background:#fff}
table{border-collapse:collapse;width:100%}
td,th{vertical-align:top}
.muted{color:#8a93a3}
.strong{font-weight:700;color:#152441}

.topband{position:fixed;top:-34px;left:0;right:0;height:7px;background:#152441}
.topgold{position:fixed;top:-27px;left:0;right:0;height:2px;background:#C9A45C}
.sheet{padding:0 42px}

.head td{vertical-align:bottom}
.brand-name{font-size:17px;font-weight:700;color:#152441}
.org{font-size:8px;color:#5d6878;line-height:1.6;margin-top:7px}
.org .name{font-size:9.5px;font-weight:700;color:#152441;letter-spacing:.2px}
.doc{text-align:right}
.doc .title{font-size:9px;font-weight:700;letter-spacing:3px;color:#B9721E}
.doc .no{font-size:16px;font-weight:700;color:#152441;margin-top:3px}
.state{display:inline-block;margin-top:8px;padding:4px 12px;border-radius:12px;font-size:7.5px;font-weight:700;letter-spacing:1.5px;color:#fff}

.facts{margin-top:18px;background:#f2f4f9;border-radius:8px}
.facts td{padding:9px 12px;border-left:1px solid #e1e6ef}
.facts td:first-child{border-left:none}
.k{font-size:6.8px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:#8a93a3}
.v{font-size:9.5px;font-weight:700;color:#152441;margin-top:2px}

.cust{margin-top:14px;padding:8px 0 8px 12px;border-left:3px solid #C9A45C}
.cust .k{color:#B9721E}
.cust .name{font-size:12px;font-weight:700;color:#152441;margin-top:2px}
.cust-lines{font-size:8.5px;color:#5d6878;margin-top:1px}

.section-title{font-size:7px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#B9721E;margin:18px 0 6px}

.items thead th{font-size:7px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#fff;background:#152441;padding:7px 8px;text-align:left;border-bottom:2px solid #C9A45C}
.items tbody td{padding:7px 8px;border-bottom:1px solid #edf0f4}
.items tbody tr.alt td{background:#faf7f0}
.items tbody tr{page-break-inside:avoid}
.c-no{width:4%;text-align:left}
.items td.c-no{font-size:8.5px;color:#B9721E;font-weight:700}
.c-qty{width:7%;text-align:center !important}
.c-num{text-align:right !important;white-space:nowrap}
.items .c-num{width:16%}
.item{font-size:9.5px;color:#1f2937}
.item-sub{font-size:7.8px;color:#8a93a3;margin-top:1px}

.lower{margin-top:2px}
.lower td.l{width:54%;padding-right:22px}
.lower td.r{width:46%}

.pays thead th{font-size:6.8px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#152441;padding:0 4px 5px 0;border-bottom:1.5px solid #152441;text-align:left}
.pays tbody td{padding:6px 4px 6px 0;border-bottom:1px solid #edf0f4;font-size:8.8px}
.p-date{white-space:nowrap;width:24%}
.pay-sub{font-size:7.6px;color:#8a93a3;margin-top:1px}

.sumbox{background:#f2f4f9;border-radius:8px;padding:8px 12px 10px}
.sum td{padding:4px 0;font-size:9.3px}
.sum .s-k{color:#5d6878}
.sum .s-v{text-align:right;white-space:nowrap;color:#1f2937;font-weight:700}
.sum tr.grand td{border-top:1.5px solid #152441;padding-top:7px;font-size:12px;color:#152441;font-weight:700}
.sum tr.grand .s-k{color:#152441}
.sum tr.note td{font-size:7.8px;color:#8a93a3;padding-top:0;font-weight:400}
.sum tr.gap td{padding-top:9px}
.settle{margin-top:8px;border-radius:6px}
.settle td{padding:9px 12px;font-weight:700;font-size:11px;color:#fff}
.settle.paid{background:#1f7a4c}
.settle.due{background:#152441}
.settle.due .s-v{color:#E8C27A}
.settle .s-k{letter-spacing:.8px}
.settle .s-v{text-align:right;white-space:nowrap}

.thanks{margin-top:24px;padding:11px 14px;background:#faf7f0;border-radius:8px}
.thanks .t{font-size:10px;font-weight:700;color:#152441}
.thanks .s{font-size:8px;color:#6b7585;margin-top:2px}
.sign td{padding-top:26px;font-size:7.5px;color:#8a93a3;width:50%}
.sign .line{border-top:1px solid #9aa5b8;padding-top:4px;width:78%}

.foot{margin-top:18px;padding-top:7px;border-top:2px solid #C9A45C;font-size:7.3px;color:#6b7585}
.foot td.r{text-align:right}
.foot .brand{color:#152441;font-weight:700}
@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}';

        $items    = $itemsHtml;
        $custName = $e($r['customer']);
        $plainNo  = $e($r['number']);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>{$css}</style>
</head>
<body>
<div class="topband"></div><div class="topgold"></div>
<div class="sheet">
  <table class="head"><tr>
    <td style="width:60%">
      {$brand}
      <div class="org">{$orgName}{$contact}</div>
    </td>
    <td class="doc" style="width:40%">
      <div class="title">RECEIPT</div>
      <div class="no">{$number}</div>
      <div class="state" style="color:{$stateFg};background:{$stateBg}">{$stateLabel}</div>
    </td>
  </tr></table>

  <table class="facts"><tr>{$facts}</tr></table>

  <div class="cust">
    <div class="k">Received from</div>
    <div class="name">{$custName}</div>
    {$contacts}
  </div>

  {$items}

  <table class="lower"><tr>
    <td class="l">{$paymentsHtml}</td>
    <td class="r"><div class="section-title">Summary</div><div class="sumbox"><table class="sum">{$sum}</table>{$settle}</div></td>
  </tr></table>

  <div class="thanks">
    <div class="t">Thank you for choosing {$appName}.</div>
    <div class="s">This receipt confirms the payments listed above. Please keep it for your records.</div>
  </div>
  <table class="sign"><tr>
    <td><div class="line">Received by (name &amp; signature)</div></td>
    <td><div class="line">Date</div></td>
  </tr></table>

  <table class="foot"><tr>
    <td><span class="brand">{$appName}</span>&nbsp;&nbsp;·&nbsp;&nbsp;Receipt {$plainNo}&nbsp;&nbsp;·&nbsp;&nbsp;Generated {$generated}</td>
    <td class="r">{$footRight}</td>
  </tr></table>
</div>
</body>
</html>
HTML;
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