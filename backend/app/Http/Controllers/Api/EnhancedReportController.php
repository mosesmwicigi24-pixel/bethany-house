<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    // The section's one export door (cycle 9): these two endpoints had their
    // own `export === 'csv'` branch and handed a file to anyone with
    // reports.view, never asking for reports.export.
    use ExportsCsv;
    // The report's outlet filter at the query layer (reports build, 2026-10-01).
    use \App\Http\Controllers\Api\Concerns\RecognisesIncome;

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
        // Stock hangs off inventory_items.product_id, which is never null; the
        // variant is optional. Joining product_variants INNER therefore valued
        // only the catalogue's variant stock and silently dropped every simple
        // product: 91 of 164 SKUs and 47,264 of 50,967 available units on
        // 2026-09-29, reporting KES 26.6m against the engine's 86.7m. Join the
        // product, and let the variant be optional as the data has it.
        //
        // Price follows the house rule (CurrencyPricing): the variant's own KES
        // row when the line is a variant, otherwise the product-level KES row.
        $rows = DB::table('inventory_items as ii')
            ->join('products as p', 'p.id', '=', 'ii.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'p.category_id')
            ->leftJoin('outlets', 'outlets.id', '=', 'ii.outlet_id')
            // One price row per key, guaranteed. `unique_product_price` covers
            // (product_id, product_variant_id, currency_code), but Postgres
            // treats NULLs as distinct, so it does NOT stop a second
            // product-level row — the same NULL hole the inventory_items unique
            // index was rebuilt to close. None exists today (checked
            // 2026-09-30); joining raw would silently multiply the units of any
            // product that grew one.
            ->leftJoin(DB::raw("(SELECT product_variant_id, MAX(regular_price) AS regular_price
                                   FROM product_prices
                                  WHERE currency_code = 'KES' AND product_variant_id IS NOT NULL
                               GROUP BY product_variant_id) AS vprice"),
                'vprice.product_variant_id', '=', 'ii.product_variant_id')
            ->leftJoin(DB::raw("(SELECT product_id, MAX(regular_price) AS regular_price
                                   FROM product_prices
                                  WHERE currency_code = 'KES' AND product_variant_id IS NULL
                               GROUP BY product_id) AS pprice"),
                'pprice.product_id', '=', 'ii.product_id')
            ->whereRaw('(ii.quantity_on_hand - ii.quantity_reserved) > 0')
            ->selectRaw("
                COALESCE(categories.name_en, 'Uncategorised') AS category_name,
                COALESCE(outlets.name, 'Warehouse') AS outlet_name,
                COUNT(*) AS sku_count,
                SUM(ii.quantity_on_hand - ii.quantity_reserved) AS total_units,
                SUM(
                    (ii.quantity_on_hand - ii.quantity_reserved)
                    * COALESCE(vprice.regular_price, pprice.regular_price, 0)
                ) AS total_retail_value,
                -- On-hand beside available, because the engine's inventory
                -- health values on-hand and the two could not be reconciled
                -- without it. The headline stays available: that is what the
                -- report has always meant and changing it is the owner's call.
                SUM(ii.quantity_on_hand) AS total_units_on_hand,
                SUM(ii.quantity_on_hand * COALESCE(vprice.regular_price, pprice.regular_price, 0))
                    AS total_retail_value_on_hand
            ")
            ->groupBy('categories.name_en', 'outlets.name')
            ->orderBy('categories.name_en')
            ->get();

        $grand = [
            'total_retail_value' => $rows->sum('total_retail_value'),
            'total_sku_count'    => $rows->sum('sku_count'),
            'total_units'        => $rows->sum('total_units'),
            'basis'              => 'available (on hand less reserved)',
            'total_units_on_hand'        => $rows->sum('total_units_on_hand'),
            'total_retail_value_on_hand' => $rows->sum('total_retail_value_on_hand'),
        ];

        if ($this->wantsExport($request)) {
            return $this->csvTable('inventory_valuation', $rows->toArray(),
                ['category_name', 'outlet_name', 'sku_count', 'total_units', 'total_retail_value',
                 'total_units_on_hand', 'total_retail_value_on_hand']);
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
     *
     * BASIS (owner delegated the decision, 2026-10-02): the SOLD basis —
     * recognised orders, the same scope as every sales figure — not only the
     * orders money has reached. VAT falls due on the supply or the invoice,
     * whichever is first; a confirmed sale awaiting payment is still taxable.
     * The paid-only basis left out KES 33,500 of September's confirmed sales.
     *
     * ONE RATE PER LINE: a product carrying two rates (product 108 is tagged
     * both VAT 16% and No Tax) was counted once under EACH, so its line was in
     * the taxable total twice. It now counts once, under its higher rate — the
     * cautious reading for a VAT return — and is named in
     * `conflicting_rate_products` so the product's tax setup is corrected.
     * Rated currencies only, like every KES figure.
     */
    public function taxReport(Request $request)
    {
        $p = $this->params($request);
        $lineRate = '(SELECT ptr.tax_rate_id FROM product_tax_rates ptr JOIN tax_rates trx ON trx.id = ptr.tax_rate_id
                      WHERE ptr.product_id = order_items.product_id ORDER BY trx.rate DESC, ptr.tax_rate_id LIMIT 1)';
        $base = fn () => $this->recognisedOrders(DB::table('orders')
                ->join('order_items', 'orders.id', '=', 'order_items.order_id'))
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereRaw(\App\Support\ReportingCurrency::convertibleFilter('orders.currency_code'));

        $rows = $base()
            ->leftJoin('tax_rates', DB::raw('tax_rates.id'), '=', DB::raw($lineRate))
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

        $conflicting = $base()
            ->whereIn('order_items.product_id', DB::table('product_tax_rates')->select('product_id')
                ->groupBy('product_id')->havingRaw('COUNT(*) > 1'))
            ->distinct()->orderBy('order_items.product_id')
            ->pluck('order_items.product_id')->map(fn ($id) => (int) $id)->all();

        $totals = [
            'total_taxable'  => $rows->sum('taxable_amount'),
            'total_tax'      => $rows->sum('tax_collected'),
            'effective_rate' => $rows->sum('taxable_amount') > 0
                ? round(($rows->sum('tax_collected') / $rows->sum('taxable_amount')) * 100, 2)
                : 0,
        ];

        if ($this->wantsExport($request)) {
            return $this->csvTable('tax_report', $rows->toArray(),
                ['tax_name', 'tax_rate', 'order_count', 'taxable_amount', 'tax_collected']);
        }

        return response()->json(['period' => $p, 'by_tax_rate' => $rows, 'totals' => $totals,
            'basis' => 'sold', 'conflicting_rate_products' => $conflicting]);
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

        // Inflows ARE Collected (owner delegated the decision, 2026-10-02):
        // settled payments, approved where approval applies, by PAYMENT date,
        // net of refunds, in KES at the reporting rate — so a month's inflow
        // is that month's Collected tile. It read the record's creation date
        // and the gross amount, so a refund never left the cash flow and a
        // payment recorded in one month and settled in the next sat in the
        // wrong one (July 2026: 500 apart). Rate-less currencies stay out of
        // the sum rather than entering at a guess.
        $paidAt  = 'COALESCE(payments.paid_at, payments.created_at)';
        $inflows = \App\Support\SettledPayment::where(DB::table('payments'))
            ->when($this->reportOutletId(), fn ($q, $outlet) => $q->whereIn('order_id',
                DB::table('orders')->where('outlet_id', $outlet)->select('id')))
            ->whereBetween(DB::raw($paidAt), [$p['start'], $p['end']])
            ->whereRaw(\App\Support\ReportingCurrency::convertibleFilter('payments.currency_code'))
            ->selectRaw("TO_CHAR({$paidAt}, 'YYYY-MM') AS month, SUM(" . \App\Support\ReportingCurrency::kes('payments.amount - COALESCE(payments.refund_amount, 0)', 'payments.currency_code') . ") AS inflow, payment_method")
            ->groupBy('month', 'payment_method')
            ->orderBy('month')
            ->get();

        // Outflows: approved/paid expenses grouped by month
        $outflows = DB::table('expenses')
            ->tap(fn ($q) => $this->inOutlet($q, 'expenses.outlet_id'))
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
     * Pick the named columns, title the headers, and hand them to the shared
     * ExportsCsv::csvResponse — the same in-memory file every other report
     * produces (BOM, no-store), instead of a stream of its own.
     */
    private function csvTable(string $filename, array $data, array $columns): \Illuminate\Http\Response
    {
        $headers = array_map(fn ($c) => str_replace('_', ' ', ucwords($c, '_')), $columns);
        $rows    = array_map(function ($row) use ($columns) {
            $row = (array) $row;

            return array_map(fn ($c) => $row[$c] ?? '', $columns);
        }, $data);

        return $this->csvResponse($headers, $rows, $filename);
    }
}
