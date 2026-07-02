<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ReportPdfController
 *
 * Generates PDF documents for each report type by running the same
 * queries as ReportController and passing data through PdfService.
 *
 * GET /api/v1/admin/reports/pdf/sales
 * GET /api/v1/admin/reports/pdf/financial
 * GET /api/v1/admin/reports/pdf/inventory
 * GET /api/v1/admin/reports/pdf/procurement
 * GET /api/v1/admin/reports/pdf/production
 * GET /api/v1/admin/reports/pdf/customers
 *
 * All accept ?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 */
class ReportPdfController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function dateRange(Request $request): array
    {
        $start = $request->get('start_date', now()->subDays(29)->format('Y-m-d'));
        $end   = $request->get('end_date',   now()->format('Y-m-d'));
        return [$start, $end . ' 23:59:59'];
    }

    private function fmt(float $amount, string $currency = 'KES'): string
    {
        return $currency . ' ' . number_format($amount, 2);
    }

    private function makePdf(string $html, string $filename): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        $pdf = Pdf::loadHTML($html)
            ->setPaper('A4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'Helvetica');

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$safe}.pdf\"",
            'Cache-Control'       => 'no-cache, no-store',
        ]);
    }

    // ── Shared CSS + shell ────────────────────────────────────────────────────

    private function shell(string $title, string $start, string $end, string $body): string
    {
        $s        = \Illuminate\Support\Facades\Cache::remember('app_settings', 300, fn () =>
            DB::table('settings')->pluck('value', 'key')->toArray()
        );
        $appName  = htmlspecialchars($s['app_name'] ?? 'Bethany House');
        $address  = htmlspecialchars(trim(($s['app_address'] ?? '') . ($s['app_city'] ? ', ' . $s['app_city'] : '')));
        $email    = htmlspecialchars($s['app_email'] ?? '');
        $phone    = htmlspecialchars($s['app_phone'] ?? '');
        $logoUrl  = $s['app_logo_url'] ?? '';
        $year     = date('Y');
        $today    = Carbon::now()->format('d M Y');
        $range    = htmlspecialchars(Carbon::parse($start)->format('d M Y') . ' – ' . Carbon::parse($end)->format('d M Y'));

        // Embed logo
        $logo = '';
        if ($logoUrl) {
            try {
                $path = str_replace(config('app.url') . '/storage/', '', $logoUrl);
                $full = storage_path('app/public/' . $path);
                if (file_exists($full)) {
                    $mime = mime_content_type($full);
                    $b64  = base64_encode(file_get_contents($full));
                    $logo = "<img src=\"data:{$mime};base64,{$b64}\" style=\"max-height:48px;max-width:130px;object-fit:contain;display:block;margin-bottom:4px;\" alt=\"{$appName}\">";
                }
            } catch (\Exception $e) {}
        }

        $orgLines = '';
        if ($address) $orgLines .= "<div>{$address}</div>";
        if ($email)   $orgLines .= "<div>{$email}</div>";
        if ($phone)   $orgLines .= "<div>{$phone}</div>";

        $css = '
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,Helvetica,sans-serif;font-size:10.5px;color:#111;background:#fff;line-height:1.45}
.page{padding:24px 28px;max-width:900px;margin:0 auto}
.top{display:table;width:100%;margin-bottom:8px}
.top-l{display:table-cell;vertical-align:top;width:58%}
.top-r{display:table-cell;vertical-align:top;text-align:right}
.rep-title{font-size:19px;font-weight:700;color:#111;margin-bottom:1px}
.org-name{font-size:12px;font-weight:700;margin-bottom:2px}
.org-contact{font-size:9.5px;color:#555;line-height:1.55}
.date-range{font-size:9.5px;color:#555;margin-top:2px}
.divider{border:none;border-top:2px solid #111;margin:8px 0 14px}
.section{margin-bottom:18px}
.sec-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#444;margin-bottom:7px;padding-bottom:3px;border-bottom:1px solid #ccc}
.kpi-grid{display:table;width:100%;margin-bottom:14px}
.kpi-cell{display:table-cell;width:25%;padding-right:8px;vertical-align:top}
.kpi-cell:last-child{padding-right:0}
.kpi-box{border:1px solid #ccc;padding:7px 9px;background:#fafafa}
.kpi-lbl{font-size:8.5px;text-transform:uppercase;letter-spacing:.4px;color:#666;margin-bottom:2px}
.kpi-val{font-size:15px;font-weight:700;color:#111}
.kpi-sub{font-size:8.5px;color:#888;margin-top:1px}
table{border-collapse:collapse;width:100%}
th{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:4px 7px;background:#efefef;border-bottom:1px solid #aaa;text-align:left}
th.r{text-align:right}
td{font-size:10px;padding:4px 7px;border-bottom:1px solid #e5e5e5;vertical-align:top}
td.r{text-align:right;font-family:"Courier New",monospace}
tr:last-child td{border-bottom:none}
.tot td{font-weight:700;background:#f3f3f3;border-top:1.5px solid #999}
.footer{margin-top:14px;padding-top:6px;border-top:1px solid #ccc;font-size:8.5px;color:#888;display:table;width:100%}
.footer-l{display:table-cell;text-align:left}
.footer-r{display:table-cell;text-align:right}
@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}.page{padding:14px}}';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>{$css}</style>
</head>
<body>
<div class="page">
  <div class="top">
    <div class="top-l">
      {$logo}
      <div class="org-name">{$appName}</div>
      <div class="org-contact">{$orgLines}</div>
    </div>
    <div class="top-r">
      <div class="rep-title">{$title}</div>
      <div class="date-range">{$range}</div>
      <div class="date-range" style="margin-top:3px">Generated: {$today}</div>
    </div>
  </div>
  <hr class="divider">
  {$body}
  <div class="footer">
    <div class="footer-l">{$title} &bull; {$range}</div>
    <div class="footer-r">&copy; {$year} {$appName}</div>
  </div>
</div>
</body>
</html>
HTML;
    }

    // ── Table + KPI helpers ───────────────────────────────────────────────────

    private function kpiGrid(array $kpis): string
    {
        $cells = '';
        foreach ($kpis as $k) {
            $lbl = htmlspecialchars($k['label']);
            $val = htmlspecialchars((string)($k['value'] ?? '—'));
            $sub = isset($k['sub']) ? '<div class="kpi-sub">' . htmlspecialchars($k['sub']) . '</div>' : '';
            $cells .= "<div class=\"kpi-cell\"><div class=\"kpi-box\"><div class=\"kpi-lbl\">{$lbl}</div><div class=\"kpi-val\">{$val}</div>{$sub}</div></div>";
        }
        return "<div class=\"kpi-grid\">{$cells}</div>";
    }

    private function section(string $title, string $content): string
    {
        return "<div class=\"section\"><div class=\"sec-title\">{$title}</div>{$content}</div>";
    }

    private function table(array $headers, array $rows, ?array $totalRow = null): string
    {
        $ths = '';
        foreach ($headers as $h) {
            $cls = ($h['right'] ?? false) ? ' class="r"' : '';
            $ths .= "<th{$cls}>" . htmlspecialchars($h['label']) . '</th>';
        }

        $trs = '';
        foreach ($rows as $row) {
            $trs .= '<tr>';
            foreach ($row as $i => $cell) {
                $cls = ($headers[$i]['right'] ?? false) ? ' class="r"' : '';
                $trs .= "<td{$cls}>" . htmlspecialchars((string)($cell ?? '—')) . '</td>';
            }
            $trs .= '</tr>';
        }

        $totTr = '';
        if ($totalRow) {
            $totTr = '<tr class="tot">';
            foreach ($totalRow as $i => $cell) {
                $cls = ($headers[$i]['right'] ?? false) ? ' class="r"' : '';
                $totTr .= "<td{$cls}>" . htmlspecialchars((string)($cell ?? '')) . '</td>';
            }
            $totTr .= '</tr>';
        }

        if (!$rows) {
            $colspan = count($headers);
            $trs = "<tr><td colspan=\"{$colspan}\" style=\"text-align:center;color:#888;padding:10px\">No data for this period.</td></tr>";
        }

        return "<table><thead><tr>{$ths}</tr></thead><tbody>{$trs}{$totTr}</tbody></table>";
    }

    // =========================================================================
    // SALES REPORT PDF
    // GET /api/v1/admin/reports/pdf/sales
    // =========================================================================

    public function sales(Request $request): Response
    {
        [$start, $end] = $this->dateRange($request);
        $currency = strtoupper($request->get('currency', 'KES'));

        // Summary
        $summary = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereRaw('UPPER(currency_code) = ?', [$currency])
            ->selectRaw("
                COUNT(*) AS total_orders,
                COALESCE(SUM(total_amount), 0) AS total_revenue,
                COALESCE(AVG(total_amount), 0) AS avg_order_value,
                COALESCE(SUM(tax_amount), 0)   AS total_tax,
                COALESCE(SUM(discount_amount), 0) AS total_discounts,
                COUNT(DISTINCT user_id) AS unique_customers
            ")->first();

        // By product (top 20)
        $byProduct = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.payment_status', 'paid')
            ->whereRaw('UPPER(orders.currency_code) = ?', [$currency])
            ->groupBy('order_items.product_name')
            ->selectRaw("
                order_items.product_name,
                SUM(order_items.quantity) AS quantity_sold,
                COALESCE(SUM(order_items.total_price), 0) AS total_revenue,
                COALESCE(AVG(order_items.unit_price), 0) AS avg_price
            ")
            ->orderByDesc('total_revenue')
            ->limit(20)
            ->get();

        // By category
        $byCategory = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('category_translations', function ($j) {
                $j->on('category_translations.category_id', '=', 'categories.id')
                  ->where('category_translations.language_code', '=', 'en');
            })
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.payment_status', 'paid')
            ->groupBy('categories.id', 'category_translations.name')
            ->selectRaw("
                COALESCE(category_translations.name, 'Uncategorised') AS category_name,
                COUNT(DISTINCT orders.id) AS order_count,
                COALESCE(SUM(order_items.total_price), 0) AS total_revenue
            ")
            ->orderByDesc('total_revenue')
            ->get();

        // By payment method
        $byPayment = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->selectRaw("payment_method, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total")
            ->orderByDesc('total')
            ->get();
        $pmTotal = $byPayment->sum('total');

        // Daily breakdown (last portion)
        $daily = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->selectRaw("DATE(created_at) AS date, COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        $cur = $currency;
        $kpis = $this->kpiGrid([
            ['label' => 'Total Revenue',   'value' => $this->fmt((float)$summary->total_revenue, $cur), 'sub' => "{$summary->total_orders} paid orders"],
            ['label' => 'Avg Order Value', 'value' => $this->fmt((float)$summary->avg_order_value, $cur)],
            ['label' => 'Total Orders',    'value' => $summary->total_orders],
            ['label' => 'Unique Customers','value' => $summary->unique_customers],
            ['label' => 'Tax Collected',   'value' => $this->fmt((float)$summary->total_tax, $cur)],
            ['label' => 'Discounts Given', 'value' => $this->fmt((float)$summary->total_discounts, $cur)],
        ]);

        $productsTable = $this->table(
            [['label'=>'Product'],['label'=>'Units Sold','right'=>true],['label'=>'Revenue','right'=>true],['label'=>'Avg Price','right'=>true]],
            $byProduct->map(fn($p) => [$p->product_name, number_format($p->quantity_sold), $this->fmt($p->total_revenue, $cur), $this->fmt($p->avg_price, $cur)])->toArray()
        );

        $catTable = $this->table(
            [['label'=>'Category'],['label'=>'Orders','right'=>true],['label'=>'Revenue','right'=>true],['label'=>'Share','right'=>true]],
            $byCategory->map(function($c) use ($cur, $summary) {
                $share = $summary->total_revenue > 0 ? round(($c->total_revenue / $summary->total_revenue) * 100, 1) : 0;
                return [$c->category_name, number_format($c->order_count), $this->fmt($c->total_revenue, $cur), "{$share}%"];
            })->toArray()
        );

        $pmTable = $this->table(
            [['label'=>'Payment Method'],['label'=>'Transactions','right'=>true],['label'=>'Amount','right'=>true],['label'=>'Share','right'=>true]],
            $byPayment->map(function($p) use ($cur, $pmTotal) {
                $share = $pmTotal > 0 ? round(($p->total / $pmTotal) * 100, 1) : 0;
                return [ucfirst(str_replace('_', ' ', $p->payment_method)), number_format($p->count), $this->fmt($p->total, $cur), "{$share}%"];
            })->toArray()
        );

        $dailyTable = $this->table(
            [['label'=>'Date'],['label'=>'Orders','right'=>true],['label'=>'Revenue','right'=>true]],
            $daily->map(fn($d) => [$d->date, number_format($d->orders), $this->fmt($d->revenue, $cur)])->toArray(),
            ['Total', number_format($summary->total_orders), $this->fmt($summary->total_revenue, $cur)]
        );

        $body = $kpis
            . $this->section('Top Products by Revenue', $productsTable)
            . $this->section('Sales by Category', $catTable)
            . $this->section('Payment Methods', $pmTable)
            . $this->section('Daily Breakdown', $dailyTable);

        $html = $this->shell('Sales Report', $start, $end, $body);
        return $this->makePdf($html, 'Sales-Report-' . substr($start, 0, 10));
    }

    // =========================================================================
    // FINANCIAL REPORT PDF
    // GET /api/v1/admin/reports/pdf/financial
    // =========================================================================

    public function financial(Request $request): Response
    {
        [$start, $end] = $this->dateRange($request);
        $currency = strtoupper($request->get('currency', 'KES'));

        $revenue = (float) DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereRaw('UPPER(currency_code) = ?', [$currency])
            ->sum('total_amount');

        $taxCollected = (float) DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->sum('tax_amount');

        $discounts = (float) DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->sum('discount_amount');

        $opex = (float) DB::table('expenses')
            ->whereBetween('expense_date', [substr($start, 0, 10), substr($end, 0, 10)])
            ->whereIn('status', ['approved', 'paid'])
            ->sum('amount_kes');

        $grossProfit  = $revenue;  // no COGS tracked yet
        $grossMargin  = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : 0;
        $netProfit    = $grossProfit - $opex;
        $netMargin    = $revenue > 0 ? round(($netProfit / $revenue) * 100, 1) : 0;

        $expCats = DB::table('expenses')
            ->leftJoin('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->whereBetween('expenses.expense_date', [substr($start, 0, 10), substr($end, 0, 10)])
            ->whereIn('expenses.status', ['approved', 'paid'])
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->selectRaw("COALESCE(expense_categories.name, 'Uncategorized') AS name, COUNT(*) AS count, COALESCE(SUM(expenses.amount_kes), 0) AS total")
            ->orderByDesc('total')
            ->get();

        $expenses = DB::table('expenses')
            ->leftJoin('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->whereBetween('expenses.expense_date', [substr($start, 0, 10), substr($end, 0, 10)])
            ->whereIn('expenses.status', ['approved', 'paid'])
            ->selectRaw("expenses.id, expenses.title, expense_categories.name AS category, expenses.expense_date, expenses.amount_kes, expenses.status")
            ->orderByDesc('expenses.expense_date')
            ->limit(30)
            ->get();

        $monthly = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') AS month, COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS orders")
            ->groupBy(DB::raw("TO_CHAR(created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get();

        $cur = $currency;
        $kpis = $this->kpiGrid([
            ['label' => 'Revenue',            'value' => $this->fmt($revenue, $cur)],
            ['label' => 'Gross Profit',       'value' => $this->fmt($grossProfit, $cur), 'sub' => "{$grossMargin}% margin"],
            ['label' => 'Operating Expenses', 'value' => $this->fmt($opex, $cur)],
            ['label' => 'Net Profit',         'value' => $this->fmt($netProfit, $cur), 'sub' => "{$netMargin}% margin"],
            ['label' => 'Tax Collected',      'value' => $this->fmt($taxCollected, $cur)],
            ['label' => 'Discounts Given',    'value' => $this->fmt($discounts, $cur)],
        ]);

        $plTable = $this->table(
            [['label' => 'Line Item'], ['label' => 'Amount', 'right' => true]],
            [
                ['Revenue',                    $this->fmt($revenue, $cur)],
                ['(–) Cost of Goods Sold',     $this->fmt(0, $cur)],
                ['Gross Profit (' . $grossMargin . '% margin)', $this->fmt($grossProfit, $cur)],
                ['(–) Operating Expenses',     $this->fmt($opex, $cur)],
                ['Net Profit (' . $netMargin . '% margin)', $this->fmt($netProfit, $cur)],
                ['Tax Collected',              $this->fmt($taxCollected, $cur)],
                ['Discounts Given',            $this->fmt($discounts, $cur)],
            ]
        );

        $catTable = $this->table(
            [['label'=>'Category'],['label'=>'Count','right'=>true],['label'=>'Amount','right'=>true],['label'=>'Share','right'=>true]],
            $expCats->map(function($c) use ($opex, $cur) {
                $share = $opex > 0 ? round(($c->total / $opex) * 100, 1) : 0;
                return [$c->name, number_format($c->count), $this->fmt($c->total, $cur), "{$share}%"];
            })->toArray(),
            ['Total', '', $this->fmt($opex, $cur), '100%']
        );

        $expTable = $this->table(
            [['label'=>'Expense'],['label'=>'Category'],['label'=>'Date'],['label'=>'Amount','right'=>true],['label'=>'Status']],
            $expenses->map(fn($e) => [$e->title ?? '—', $e->category ?? '—', $e->expense_date, $this->fmt((float)$e->amount_kes, $cur), ucfirst($e->status)])->toArray(),
            ['', '', 'Total', $this->fmt($opex, $cur), '']
        );

        $monthlyTable = $this->table(
            [['label'=>'Month'],['label'=>'Orders','right'=>true],['label'=>'Revenue','right'=>true]],
            $monthly->map(fn($m) => [$m->month, number_format($m->orders), $this->fmt((float)$m->revenue, $cur)])->toArray()
        );

        $body = $kpis
            . $this->section('Profit & Loss Statement', $plTable)
            . $this->section('Expenses by Category', $catTable)
            . $this->section('Expense Details (Latest 30)', $expTable)
            . $this->section('Monthly Revenue Trend', $monthlyTable);

        $html = $this->shell('Financial Report', $start, $end, $body);
        return $this->makePdf($html, 'Financial-Report-' . substr($start, 0, 10));
    }

    // =========================================================================
    // INVENTORY REPORT PDF
    // GET /api/v1/admin/reports/pdf/inventory
    // =========================================================================

    public function inventory(Request $request): Response
    {
        [$start, $end] = $this->dateRange($request);

        $totals = DB::table('inventory_items')
            ->join('products', 'inventory_items.product_id', '=', 'products.id')
            ->selectRaw("
                COUNT(DISTINCT inventory_items.product_id) AS total_skus,
                COALESCE(SUM(inventory_items.quantity_on_hand), 0) AS total_units,
                COUNT(CASE WHEN inventory_items.quantity_on_hand > COALESCE(inventory_items.reorder_point, 0) AND inventory_items.quantity_on_hand > 0 THEN 1 END) AS in_stock,
                COUNT(CASE WHEN inventory_items.quantity_on_hand > 0 AND inventory_items.quantity_on_hand <= COALESCE(inventory_items.reorder_point, 0) THEN 1 END) AS low_stock,
                COUNT(CASE WHEN inventory_items.quantity_on_hand <= 0 THEN 1 END) AS out_of_stock
            ")->first();

        $items = DB::table('inventory_items')
            ->join('products', 'inventory_items.product_id', '=', 'products.id')
            ->leftJoin('product_translations', function ($j) {
                $j->on('product_translations.product_id', '=', 'products.id')
                  ->where('product_translations.language_code', '=', 'en');
            })
            ->leftJoin('outlets', 'inventory_items.outlet_id', '=', 'outlets.id')
            ->selectRaw("
                COALESCE(product_translations.name, products.sku) AS product_name,
                products.sku,
                COALESCE(outlets.name, 'Warehouse') AS outlet_name,
                inventory_items.quantity_on_hand,
                COALESCE(inventory_items.reorder_point, 0) AS reorder_point,
                CASE
                    WHEN inventory_items.quantity_on_hand <= 0 THEN 'Out of Stock'
                    WHEN inventory_items.quantity_on_hand <= COALESCE(inventory_items.reorder_point, 0) THEN 'Low Stock'
                    ELSE 'In Stock'
                END AS stock_status
            ")
            ->orderByRaw("inventory_items.quantity_on_hand ASC")
            ->limit(50)
            ->get();

        $byOutlet = DB::table('inventory_items')
            ->leftJoin('outlets', 'inventory_items.outlet_id', '=', 'outlets.id')
            ->groupBy('outlets.id', 'outlets.name')
            ->selectRaw("
                COALESCE(outlets.name, 'Warehouse') AS outlet_name,
                COUNT(DISTINCT inventory_items.product_id) AS sku_count,
                COALESCE(SUM(inventory_items.quantity_on_hand), 0) AS total_units
            ")
            ->orderByDesc('total_units')
            ->get();

        $movement = DB::table('inventory_transactions')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('transaction_type')
            ->selectRaw("transaction_type, COUNT(*) AS count, COALESCE(SUM(quantity_change), 0) AS net_qty")
            ->get();

        $kpis = $this->kpiGrid([
            ['label' => 'Total SKUs',    'value' => number_format($totals->total_skus)],
            ['label' => 'Total Units',   'value' => number_format($totals->total_units)],
            ['label' => 'In Stock',      'value' => number_format($totals->in_stock),     'sub' => 'SKUs'],
            ['label' => 'Low Stock',     'value' => number_format($totals->low_stock),    'sub' => 'SKUs'],
            ['label' => 'Out of Stock',  'value' => number_format($totals->out_of_stock), 'sub' => 'SKUs'],
        ]);

        $stockTable = $this->table(
            [['label'=>'Product'],['label'=>'SKU'],['label'=>'Outlet'],['label'=>'On Hand','right'=>true],['label'=>'Reorder Pt','right'=>true],['label'=>'Status']],
            $items->map(fn($i) => [$i->product_name, $i->sku, $i->outlet_name, number_format($i->quantity_on_hand), number_format($i->reorder_point), $i->stock_status])->toArray()
        );

        $outletTable = $this->table(
            [['label'=>'Outlet'],['label'=>'SKUs','right'=>true],['label'=>'Total Units','right'=>true]],
            $byOutlet->map(fn($o) => [$o->outlet_name, number_format($o->sku_count), number_format($o->total_units)])->toArray()
        );

        $movTable = $this->table(
            [['label'=>'Transaction Type'],['label'=>'Count','right'=>true],['label'=>'Net Qty Change','right'=>true]],
            $movement->map(fn($m) => [ucfirst(str_replace('_', ' ', $m->transaction_type)), number_format($m->count), ($m->net_qty >= 0 ? '+' : '') . number_format($m->net_qty)])->toArray()
        );

        $body = $kpis
            . $this->section('Stock on Hand (Top 50 by Priority)', $stockTable)
            . $this->section('Inventory by Outlet', $outletTable)
            . $this->section('Stock Movement by Type', $movTable);

        $html = $this->shell('Inventory Report', $start, $end, $body);
        return $this->makePdf($html, 'Inventory-Report-' . substr($start, 0, 10));
    }

    // =========================================================================
    // PROCUREMENT REPORT PDF
    // GET /api/v1/admin/reports/pdf/procurement
    // =========================================================================

    public function procurement(Request $request): Response
    {
        [$start, $end] = $this->dateRange($request);

        $summary = DB::table('purchase_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                COUNT(*) AS total_orders,
                COALESCE(SUM(total_amount), 0) AS total_value,
                COALESCE(AVG(total_amount), 0) AS avg_po_value,
                COUNT(CASE WHEN status = 'received' THEN 1 END) AS received_count,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled_count,
                COUNT(CASE WHEN status IN ('pending_approval','approved','ordered') THEN 1 END) AS pending_count
            ")->first();

        $bySupplier = DB::table('purchase_orders')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->whereBetween('purchase_orders.created_at', [$start, $end])
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.email')
            ->selectRaw("
                suppliers.name,
                suppliers.email,
                COUNT(*) AS order_count,
                COALESCE(SUM(purchase_orders.total_amount), 0) AS total_value,
                COALESCE(AVG(purchase_orders.total_amount), 0) AS avg_value,
                COUNT(CASE WHEN purchase_orders.status = 'received' THEN 1 END) AS received_count
            ")
            ->orderByDesc('total_value')
            ->get();

        $byStatus = DB::table('purchase_orders')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('status')
            ->selectRaw("status, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total")
            ->get();

        $topItems = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->leftJoin('products', 'purchase_order_items.product_id', '=', 'products.id')
            ->leftJoin('product_translations', function ($j) {
                $j->on('product_translations.product_id', '=', 'products.id')
                  ->where('product_translations.language_code', '=', 'en');
            })
            ->whereBetween('purchase_orders.created_at', [$start, $end])
            ->groupBy('purchase_order_items.product_id', 'product_translations.name', 'products.sku')
            ->selectRaw("
                COALESCE(product_translations.name, products.sku, 'Unknown') AS item_name,
                SUM(purchase_order_items.quantity) AS total_qty,
                COALESCE(SUM(purchase_order_items.total_price), 0) AS total_cost,
                COUNT(DISTINCT purchase_orders.id) AS po_count
            ")
            ->orderByDesc('total_cost')
            ->limit(20)
            ->get();

        $monthly = DB::table('purchase_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') AS month, COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS spend")
            ->groupBy(DB::raw("TO_CHAR(created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get();

        $fulfillmentRate = $summary->total_orders > 0
            ? round(($summary->received_count / $summary->total_orders) * 100, 1) : 0;

        $kpis = $this->kpiGrid([
            ['label' => 'Total POs',         'value' => number_format($summary->total_orders)],
            ['label' => 'Total Spend',        'value' => $this->fmt((float)$summary->total_value)],
            ['label' => 'Avg PO Value',       'value' => $this->fmt((float)$summary->avg_po_value)],
            ['label' => 'Fulfilment Rate',    'value' => "{$fulfillmentRate}%"],
            ['label' => 'Fully Received',     'value' => number_format($summary->received_count)],
            ['label' => 'Cancelled',          'value' => number_format($summary->cancelled_count)],
        ]);

        $supplierTable = $this->table(
            [['label'=>'Supplier'],['label'=>'Email'],['label'=>'POs','right'=>true],['label'=>'Total Spend','right'=>true],['label'=>'Avg PO','right'=>true],['label'=>'Received','right'=>true]],
            $bySupplier->map(fn($s) => [$s->name, $s->email, number_format($s->order_count), $this->fmt((float)$s->total_value), $this->fmt((float)$s->avg_value), number_format($s->received_count)])->toArray()
        );

        $statusTable = $this->table(
            [['label'=>'Status'],['label'=>'Count','right'=>true],['label'=>'Value','right'=>true]],
            $byStatus->map(fn($s) => [ucfirst(str_replace('_', ' ', $s->status)), number_format($s->count), $this->fmt((float)$s->total)])->toArray()
        );

        $itemsTable = $this->table(
            [['label'=>'Item'],['label'=>'Qty Ordered','right'=>true],['label'=>'Total Cost','right'=>true],['label'=>'# POs','right'=>true]],
            $topItems->map(fn($i) => [$i->item_name, number_format($i->total_qty), $this->fmt((float)$i->total_cost), number_format($i->po_count)])->toArray()
        );

        $monthlyTable = $this->table(
            [['label'=>'Month'],['label'=>'POs','right'=>true],['label'=>'Spend','right'=>true]],
            $monthly->map(fn($m) => [$m->month, number_format($m->orders), $this->fmt((float)$m->spend)])->toArray()
        );

        $body = $kpis
            . $this->section('Supplier Summary', $supplierTable)
            . $this->section('PO Status Breakdown', $statusTable)
            . $this->section('Top Purchased Items', $itemsTable)
            . $this->section('Monthly Spend Trend', $monthlyTable);

        $html = $this->shell('Procurement Report', $start, $end, $body);
        return $this->makePdf($html, 'Procurement-Report-' . substr($start, 0, 10));
    }

    // =========================================================================
    // PRODUCTION REPORT PDF
    // GET /api/v1/admin/reports/pdf/production
    // =========================================================================

    public function production(Request $request): Response
    {
        [$start, $end] = $this->dateRange($request);

        $summary = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                COUNT(*) AS total_orders,
                COALESCE(SUM(quantity), 0) AS units_planned,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN quantity ELSE 0 END), 0) AS units_produced,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_count,
                COUNT(CASE WHEN status IN ('pending','assigned','in_progress') THEN 1 END) AS active_count,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) AS cancelled_count,
                COALESCE(AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (completed_at - created_at)) / 3600 END), 0) AS avg_hours
            ")->first();

        $completionRate = $summary->units_planned > 0
            ? round(($summary->units_produced / $summary->units_planned) * 100, 1) : 0;

        $onTime = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->whereNotNull('due_date')
            ->whereRaw('completed_at::date <= due_date::date')
            ->count();

        $completedWithDue = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->whereNotNull('due_date')
            ->count();

        $onTimeRate = $completedWithDue > 0 ? round(($onTime / $completedWithDue) * 100, 1) : 0;

        $byProduct = DB::table('production_orders')
            ->join('products', 'production_orders.product_id', '=', 'products.id')
            ->leftJoin('product_translations', function ($j) {
                $j->on('product_translations.product_id', '=', 'products.id')
                  ->where('product_translations.language_code', '=', 'en');
            })
            ->whereBetween('production_orders.created_at', [$start, $end])
            ->groupBy('products.id', 'product_translations.name', 'products.sku')
            ->selectRaw("
                COALESCE(product_translations.name, products.sku) AS name_en,
                COUNT(*) AS order_count,
                COALESCE(SUM(production_orders.quantity), 0) AS units_planned,
                COALESCE(SUM(CASE WHEN production_orders.status = 'completed' THEN production_orders.quantity ELSE 0 END), 0) AS units_produced
            ")
            ->orderByDesc('order_count')
            ->limit(25)
            ->get();

        $byTailor = DB::table('production_order_assignees')
            ->join('production_orders', 'production_order_assignees.production_order_id', '=', 'production_orders.id')
            ->join('users', 'production_order_assignees.user_id', '=', 'users.id')
            ->whereBetween('production_orders.created_at', [$start, $end])
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->selectRaw("
                CONCAT(users.first_name, ' ', users.last_name) AS tailor_name,
                COUNT(DISTINCT production_orders.id) AS completed_orders,
                COALESCE(SUM(production_orders.quantity), 0) AS units_produced,
                AVG(EXTRACT(EPOCH FROM (production_orders.completed_at - production_orders.created_at)) / 3600) AS avg_hours
            ")
            ->orderByDesc('completed_orders')
            ->get();

        $byStatus = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('status')
            ->selectRaw("status, COUNT(*) AS count, COALESCE(SUM(quantity), 0) AS units")
            ->get();

        $kpis = $this->kpiGrid([
            ['label' => 'Total Orders',    'value' => number_format($summary->total_orders)],
            ['label' => 'Completed',       'value' => number_format($summary->completed_count)],
            ['label' => 'Active',          'value' => number_format($summary->active_count)],
            ['label' => 'Completion Rate', 'value' => "{$completionRate}%"],
            ['label' => 'Units Planned',   'value' => number_format($summary->units_planned)],
            ['label' => 'Units Produced',  'value' => number_format($summary->units_produced)],
            ['label' => 'On-Time Rate',    'value' => "{$onTimeRate}%"],
            ['label' => 'Avg Lead Time',   'value' => round((float)$summary->avg_hours, 1) . 'h'],
        ]);

        $productTable = $this->table(
            [['label'=>'Product'],['label'=>'Orders','right'=>true],['label'=>'Planned','right'=>true],['label'=>'Produced','right'=>true],['label'=>'Completion','right'=>true]],
            $byProduct->map(function($p) {
                $rate = $p->units_planned > 0 ? round(($p->units_produced / $p->units_planned) * 100, 0) . '%' : '—';
                return [$p->name_en, number_format($p->order_count), number_format($p->units_planned), number_format($p->units_produced), $rate];
            })->toArray()
        );

        $tailorTable = $this->table(
            [['label'=>'Tailor'],['label'=>'Orders','right'=>true],['label'=>'Units','right'=>true],['label'=>'Avg Hours','right'=>true]],
            $byTailor->map(fn($t) => [$t->tailor_name, number_format($t->completed_orders), number_format($t->units_produced), round((float)($t->avg_hours ?? 0), 1) . 'h'])->toArray()
        );

        $statusTable = $this->table(
            [['label'=>'Status'],['label'=>'Orders','right'=>true],['label'=>'Units','right'=>true]],
            $byStatus->map(fn($s) => [ucfirst(str_replace('_', ' ', $s->status)), number_format($s->count), number_format($s->units)])->toArray()
        );

        $body = $kpis
            . $this->section('Production by Product', $productTable)
            . $this->section('Tailor Productivity', $tailorTable)
            . $this->section('Orders by Status', $statusTable);

        $html = $this->shell('Production Report', $start, $end, $body);
        return $this->makePdf($html, 'Production-Report-' . substr($start, 0, 10));
    }

    // =========================================================================
    // CUSTOMERS REPORT PDF
    // GET /api/v1/admin/reports/pdf/customers
    // =========================================================================

    public function customers(Request $request): Response
    {
        [$start, $end] = $this->dateRange($request);
        $currency = strtoupper($request->get('currency', 'KES'));

        $summary = DB::table('customers')
            ->selectRaw("
                COUNT(*) AS total_customers,
                COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) AS new_customers
            ", [$start, $end])
            ->first();

        $topCustomers = DB::table('orders')
            ->leftJoin('customers', function ($join) {
                $join->on('customers.user_id', '=', 'orders.user_id')
                     ->whereNotNull('orders.user_id');
            })
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.payment_status', 'paid')
            ->whereNotNull('orders.user_id')
            ->groupBy('orders.user_id', 'customers.first_name', 'customers.last_name', 'customers.email', 'orders.customer_first_name', 'orders.customer_last_name', 'orders.customer_email')
            ->selectRaw("
                COALESCE(customers.first_name, orders.customer_first_name, 'Guest') || ' ' ||
                COALESCE(customers.last_name,  orders.customer_last_name,  '')       AS customer_name,
                COALESCE(customers.email, orders.customer_email)                     AS email,
                COUNT(orders.id)                             AS order_count,
                COALESCE(SUM(orders.total_amount), 0)        AS total_spent,
                COALESCE(AVG(orders.total_amount), 0)        AS avg_order,
                MAX(orders.created_at::date)                 AS last_order_date
            ")
            ->orderByDesc('total_spent')
            ->limit(30)
            ->get();

        // Customer acquisition trend
        $acquisition = DB::table('customers')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') AS month, COUNT(*) AS new_customers")
            ->groupBy(DB::raw("TO_CHAR(created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get();

        // Avg LTV — group by user_id (orders have no customer_id)
        $ltvStats = DB::table('orders')
            ->where('payment_status', 'paid')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw("COALESCE(SUM(total_amount), 0) AS lifetime_value")
            ->get();

        $avgLtv    = $ltvStats->avg('lifetime_value') ?? 0;
        $maxLtv    = $ltvStats->max('lifetime_value') ?? 0;

        $cur = $currency;
        $kpis = $this->kpiGrid([
            ['label' => 'Total Customers',   'value' => number_format($summary->total_customers)],
            ['label' => 'New (This Period)', 'value' => number_format($summary->new_customers)],
            ['label' => 'Avg Lifetime Value','value' => $this->fmt(round((float)$avgLtv, 2), $cur)],
            ['label' => 'Top Customer LTV',  'value' => $this->fmt(round((float)$maxLtv, 2), $cur)],
        ]);

        $topTable = $this->table(
            [['label'=>'Customer'],['label'=>'Email'],['label'=>'Orders','right'=>true],['label'=>'Total Spent','right'=>true],['label'=>'Avg Order','right'=>true],['label'=>'Last Order']],
            $topCustomers->map(fn($c) => [$c->customer_name, $c->email, number_format($c->order_count), $this->fmt((float)$c->total_spent, $cur), $this->fmt((float)$c->avg_order, $cur), $c->last_order_date ?? '—'])->toArray()
        );

        $acqTable = $this->table(
            [['label'=>'Month'],['label'=>'New Customers','right'=>true]],
            $acquisition->map(fn($m) => [$m->month, number_format($m->new_customers)])->toArray()
        );

        $body = $kpis
            . $this->section('Top 30 Customers by Lifetime Spend', $topTable)
            . $this->section('New Customer Acquisition', $acqTable);

        $html = $this->shell('Customers Report', $start, $end, $body);
        return $this->makePdf($html, 'Customers-Report-' . substr($start, 0, 10));
    }
}