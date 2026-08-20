<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The three reports that are served from this class rather than ReportController.
 *
 * This class once held a parallel implementation of nearly every report in
 * ReportController. Only three of its methods were ever mounted in routes/api.php;
 * the other sixteen were reachable only through the duplicate routes/routes/ tree
 * deleted in #267, and each carried a docblock advertising a URL that answered 404
 * (or that answered from ReportController's implementation instead). They were
 * removed — see the PR that added this comment for the per-report reasoning.
 *
 * What is left is genuinely live. Each method below states the route that reaches
 * it; those routes are asserted in tests/Feature/ReportRoutingTest.php so a URL
 * claimed here cannot silently drift from the one mounted in routes/api.php again.
 *
 * Note for anyone extending this class: DON'T. New reports belong in
 * ReportController, which owns the shared date-range, numeric-casting and CSV
 * conventions. Folding these last three in is the remaining half of audit item
 * Q-4 (docs/SYSTEM_AUDIT_AND_ROADMAP.md) and is deliberately not done here,
 * because the two classes default to different date windows — this one to the
 * current calendar month, ReportController to the trailing 30 days — so a
 * straight move would silently change what these three reports return.
 */
class EnhancedReportController extends Controller
{
    // =========================================================================
    // INVENTORY
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/inventory/valuation
     *
     * Retail value comes from product_prices (KES regular_price).
     * inventory_items has no cost_price - cost is not tracked at this level.
     */
    public function inventoryValuation(Request $request)
    {
        $rows = DB::table('inventory_items')
            ->join('product_variants', 'inventory_items.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('outlets', 'inventory_items.outlet_id', '=', 'outlets.id')
            ->leftJoin('product_prices', function($join) {
                $join->on('product_prices.product_variant_id', '=', 'product_variants.id')
                     ->where('product_prices.currency_code', '=', 'KES');
            })
            ->whereRaw('(inventory_items.quantity_on_hand - inventory_items.quantity_reserved) > 0')
            ->selectRaw("
                COALESCE(categories.name_en, 'Uncategorised') AS category_name,
                COALESCE(outlets.name, 'Warehouse') AS outlet_name,
                COUNT(*) AS sku_count,
                SUM(inventory_items.quantity_on_hand - inventory_items.quantity_reserved) AS total_units,
                SUM(
                    (inventory_items.quantity_on_hand - inventory_items.quantity_reserved)
                    * COALESCE(product_prices.regular_price, 0)
                ) AS total_retail_value
            ")
            ->groupBy('categories.name_en', 'outlets.name')
            ->orderBy('categories.name_en')
            ->get();

        $grand = [
            'total_retail_value' => $rows->sum('total_retail_value'),
            'total_sku_count'    => $rows->sum('sku_count'),
            'total_units'        => $rows->sum('total_units'),
        ];

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('inventory_valuation', $rows->toArray(),
                ['category_name', 'outlet_name', 'sku_count', 'total_units', 'total_retail_value']);
        }

        return response()->json(['breakdown' => $rows, 'grand_totals' => $grand]);
    }

    // =========================================================================
    // FINANCIAL
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/financial/tax
     *
     * The route is /financial/tax. This docblock said /financial/tax-report
     * until #267's follow-up; no such URL has ever existed.
     *
     * Tax rates are assigned per-product via product_tax_rates pivot.
     * There is no tax_rate_id on order_items - we join through product_tax_rates.
     */
    public function taxReport(Request $request)
    {
        $p = $this->params($request);

        $rows = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('product_tax_rates', 'order_items.product_id', '=', 'product_tax_rates.product_id')
            ->leftJoin('tax_rates', 'product_tax_rates.tax_rate_id', '=', 'tax_rates.id')
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->selectRaw("
                COALESCE(tax_rates.name, 'No Tax / Default') AS tax_name,
                COALESCE(tax_rates.rate, 0) AS tax_rate,
                COUNT(DISTINCT orders.id) AS order_count,
                SUM(" . \App\Support\ReportingCurrency::kes('order_items.total_price', 'orders.currency_code') . ") AS taxable_amount,
                SUM(" . \App\Support\ReportingCurrency::kes('order_items.tax_amount', 'orders.currency_code') . ")  AS tax_collected
            ")
            ->groupBy('tax_rates.id', 'tax_rates.name', 'tax_rates.rate')
            ->orderByDesc('tax_collected')
            ->get();

        $totals = [
            'total_taxable'  => $rows->sum('taxable_amount'),
            'total_tax'      => $rows->sum('tax_collected'),
            'effective_rate' => $rows->sum('taxable_amount') > 0
                ? round(($rows->sum('tax_collected') / $rows->sum('taxable_amount')) * 100, 2)
                : 0,
        ];

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('tax_report', $rows->toArray(),
                ['tax_name', 'tax_rate', 'order_count', 'taxable_amount', 'tax_collected']);
        }

        return response()->json(['period' => $p, 'by_tax_rate' => $rows, 'totals' => $totals]);
    }

    /**
     * GET /api/v1/admin/reports/financial/cash-flow
     * Simple cash flow: money in (payments received) vs money out (expenses paid)
     *
     * No ?export=csv branch: this endpoint returns two differently-shaped series,
     * so there is no single table to flatten. Callers export from the page.
     */
    public function cashFlow(Request $request)
    {
        $p = $this->params($request);

        // Inflows: completed payments grouped by month
        // Every rail converts at the REPORTING rate before summing — a USD
        // payment added at face value understates silently, the exact defect
        // the 2026-08 audit found on the Collected tile. Rate-less currencies
        // stay out of the sum rather than entering at a guess.
        $inflows = DB::table('payments')
            ->whereBetween('created_at', [$p['start'], $p['end']])
            ->where('status', 'paid')
            ->whereRaw(\App\Support\ReportingCurrency::convertibleFilter('currency_code'))
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') AS month, SUM(" . \App\Support\ReportingCurrency::kes('amount', 'currency_code') . ") AS inflow, payment_method")
            ->groupBy('month', 'payment_method')
            ->orderBy('month')
            ->get();

        // Outflows: approved/paid expenses grouped by month
        $outflows = DB::table('expenses')
            ->whereBetween('expense_date', [$p['start'], $p['end']])
            ->whereIn('status', ['approved', 'paid'])
            ->selectRaw("TO_CHAR(expense_date, 'YYYY-MM') AS month, SUM(amount_kes) AS outflow")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'period'   => $p,
            'inflows'  => $inflows,
            'outflows' => $outflows,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Resolve the reporting window, defaulting to the current calendar month.
     *
     * ReportController::dateRange() defaults to the trailing 30 days instead.
     * The difference is why these three reports have not simply been moved.
     */
    private function params(Request $request, array $extras = []): array
    {
        $base = [
            'start' => $request->get('start_date', now()->startOfMonth()->format('Y-m-d')) . ' 00:00:00',
            'end'   => $request->get('end_date',   now()->endOfMonth()->format('Y-m-d'))   . ' 23:59:59',
        ];

        foreach ($extras as $key) {
            $val = $request->get($key);
            if ($val !== null) {
                $base[$key] = $val;
            }
        }

        return $base;
    }

    /**
     * Stream a CSV response for the given data and columns.
     */
    private function csvResponse(string $filename, array $data, array $columns): StreamedResponse
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}_" . date('Y-m-d') . ".csv\"",
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ];

        return response()->stream(function () use ($data, $columns) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, array_map(fn($c) => str_replace('_', ' ', ucwords($c, '_')), $columns));

            foreach ($data as $row) {
                $row = (array) $row;
                fputcsv($handle, array_map(fn($c) => $row[$c] ?? '', $columns));
            }

            fclose($handle);
        }, 200, $headers);
    }
}
