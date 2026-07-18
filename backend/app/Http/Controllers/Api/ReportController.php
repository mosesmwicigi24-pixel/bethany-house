<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Order, Product, Customer, Inventory, InventoryItem, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ReportController
 *
 * All queries are written for PostgreSQL.
 * Key schema facts:
 *  - orders: user_id (not customer_id), total_amount, tax_amount, shipping_amount,
 *            discount_amount, currency_code, order_type ('online'|'pos'),
 *            payment_status, payment_method, outlet_id
 *  - customers: first_name, last_name, email, phone (no separate users join needed)
 *  - products: name_en, sku, category_id
 *  - order_items: product_id, product_variant_id, quantity, unit_price, total_price,
 *                 discount_amount, tax_amount, product_name, variant_name, sku
 *
 * Export formats: CSV (all endpoints), JSON (default)
 * Append ?export=csv to any endpoint to download as CSV.
 * Append ?export=excel to any endpoint to download as Excel-compatible CSV.
 * Append ?compare=1 to sales/financial endpoints to include prior-period comparison.
 */
class ReportController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function dateRange(Request $request): array
    {
        $start = $request->get('start_date', now()->subDays(29)->format('Y-m-d'));
        $end   = $request->get('end_date',   now()->format('Y-m-d'));
        return [$start, $end . ' 23:59:59'];
    }

    /**
     * Return the equivalent prior period date range for comparison.
     * e.g. if range is 30 days, prior period is the previous 30 days.
     */
    private function priorPeriod(string $start, string $end): array
    {
        $startDt = Carbon::parse($start);
        $endDt   = Carbon::parse($end);
        $days    = $startDt->diffInDays($endDt) + 1;

        $priorEnd   = $startDt->copy()->subDay();
        $priorStart = $priorEnd->copy()->subDays($days - 1);

        return [$priorStart->format('Y-m-d'), $priorEnd->format('Y-m-d') . ' 23:59:59'];
    }

    /**
     * Stream a CSV response.
     * $headers: array of column header strings
     * $rows: iterable of arrays (one per row)
     * $filename: download filename without extension
     */
    private function csvResponse(array $headers, iterable $rows, string $filename): \Illuminate\Http\Response
    {
        // Build the CSV entirely in memory so errors are catchable and
        // output-buffering conflicts with streamDownload are avoided.
        $out = fopen('php://temp', 'r+');
        // UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_values((array) $row));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '_' . now()->format('Ymd_His') . '.csv"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function wantsExport(Request $request): bool
    {
        return $request->filled('export');
    }

    private function pct(float $new, float $old): ?float
    {
        if ($old == 0) return null;
        return round((($new - $old) / abs($old)) * 100, 1);
    }

    // =========================================================================
    // SALES
    // =========================================================================

    /**
     * GET /admin/reports/sales/summary
     * Optional: ?compare=1 for prior-period comparison
     * Optional: ?export=csv
     */
    public function salesSummary(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $currency  = strtoupper($request->get('currency_code', $request->get('currency', 'KES')));
        $outletId  = $request->filled('outlet_id')  ? (int) $request->outlet_id  : null;
        $orderType = $request->filled('order_type') ? $request->order_type : null;

        // Sales truth (docs/REPORTS_SPEC.md): a sale is any non-voided,
        // non-cancelled order. The old payment_status='paid' filter silently
        // erased every part-paid and deposit order from "revenue".
        $base = fn () => Order::whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', ['voided', 'cancelled'])
            ->whereRaw('UPPER(currency_code) = ?', [$currency])
            ->when($outletId,  fn ($q) => $q->where('outlet_id',  $outletId))
            ->when($orderType, fn ($q) => $q->where('order_type', $orderType));

        // Money truth beside it: what actually settled in the same window.
        $collected = (float) DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', 'paid')
            ->whereRaw('UPPER(p.currency_code) = ?', [$currency])
            ->when($outletId,  fn ($q) => $q->where('o.outlet_id',  $outletId))
            ->when($orderType, fn ($q) => $q->where('o.order_type', $orderType))
            ->whereBetween(DB::raw('COALESCE(p.paid_at, p.created_at)'), [$start, $end])
            ->selectRaw('COALESCE(SUM(p.amount - COALESCE(p.refund_amount,0)),0) AS v')
            ->value('v');

        $summary = $base()->selectRaw("
            COUNT(*)                                                          AS total_orders,
            COALESCE(SUM(total_amount), 0)                                    AS total_revenue,
            COALESCE(SUM(shipping_amount), 0)                                 AS total_shipping,
            COALESCE(SUM(tax_amount), 0)                                      AS total_tax,
            COALESCE(SUM(discount_amount), 0)                                 AS total_discounts,
            COALESCE(AVG(total_amount), 0)                                    AS average_order_value,
            COALESCE(MIN(total_amount), 0)                                    AS min_order_value,
            COALESCE(MAX(total_amount), 0)                                    AS max_order_value,
            COALESCE(SUM(CASE WHEN order_type = 'online' THEN total_amount ELSE 0 END), 0) AS online_revenue,
            COALESCE(SUM(CASE WHEN order_type = 'pos'    THEN total_amount ELSE 0 END), 0) AS pos_revenue,
            COUNT(CASE WHEN order_type = 'online' THEN 1 END)                 AS online_count,
            COUNT(CASE WHEN order_type = 'pos'    THEN 1 END)                 AS pos_count,
            COUNT(DISTINCT user_id)                                            AS unique_customers,
            COALESCE(SUM(discount_amount) / NULLIF(SUM(total_amount + discount_amount), 0) * 100, 0) AS discount_rate_percent
        ")->first();

        $daily = $base()->selectRaw("
            DATE(created_at)               AS date,
            COUNT(*)                       AS orders,
            COALESCE(SUM(total_amount), 0) AS revenue,
            COUNT(DISTINCT user_id)        AS unique_customers
        ")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Weekly aggregation for longer date ranges
        $weekly = $base()->selectRaw("
            DATE_TRUNC('week', created_at)::date AS week_start,
            COUNT(*)                             AS orders,
            COALESCE(SUM(total_amount), 0)       AS revenue
        ")
            ->groupBy(DB::raw("DATE_TRUNC('week', created_at)"))
            ->orderBy('week_start')
            ->get();

        $byPaymentMethod = $base()->selectRaw("
            payment_method,
            COUNT(*) AS count,
            COALESCE(SUM(total_amount), 0) AS total
        ")
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->orderByRaw('COALESCE(SUM(total_amount), 0) DESC')
            ->get();

        // Hourly distribution - useful for staffing decisions
        $byHour = $base()->selectRaw("
            EXTRACT(HOUR FROM created_at)::int AS hour,
            COUNT(*)                           AS orders,
            COALESCE(SUM(total_amount), 0)     AS revenue
        ")
            ->groupBy(DB::raw("EXTRACT(HOUR FROM created_at)"))
            ->orderBy('hour')
            ->get();

        // Day-of-week distribution
        $byDayOfWeek = $base()->selectRaw("
            EXTRACT(DOW FROM created_at)::int AS dow,
            TO_CHAR(created_at, 'Day')        AS day_name,
            COUNT(*)                          AS orders,
            COALESCE(SUM(total_amount), 0)    AS revenue
        ")
            ->groupBy(DB::raw("EXTRACT(DOW FROM created_at)"), DB::raw("TO_CHAR(created_at, 'Day')"))
            ->orderBy('dow')
            ->get();

        // Prior period comparison
        $comparison = null;
        if ($request->boolean('compare')) {
            [$ps, $pe] = $this->priorPeriod($start, $end);
            $prior = Order::whereBetween('created_at', [$ps, $pe])
                ->whereNotIn('status', ['voided', 'cancelled'])
                ->whereRaw('UPPER(currency_code) = ?', [$currency])
                ->when($outletId,  fn ($q) => $q->where('outlet_id',  $outletId))
                ->when($orderType, fn ($q) => $q->where('order_type', $orderType))
                ->selectRaw("
                    COUNT(*) AS total_orders,
                    COALESCE(SUM(total_amount), 0) AS total_revenue,
                    COALESCE(AVG(total_amount), 0) AS average_order_value
                ")->first();

            $comparison = [
                'period'              => ['start' => $ps, 'end' => $pe],
                'total_orders'        => $prior->total_orders,
                'total_revenue'       => $prior->total_revenue,
                'average_order_value' => $prior->average_order_value,
                'revenue_change_pct'  => $this->pct((float)$summary->total_revenue, (float)$prior->total_revenue),
                'orders_change_pct'   => $this->pct((float)$summary->total_orders,  (float)$prior->total_orders),
                'aov_change_pct'      => $this->pct((float)$summary->average_order_value, (float)$prior->average_order_value),
            ];
        }

        // CSV export
        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Date', 'Orders', 'Revenue', 'Unique Customers'],
                $daily->map(fn ($d) => [$d->date, $d->orders, $d->revenue, $d->unique_customers]),
                'sales_summary'
            );
        }

        return response()->json([
            'period'             => ['start' => $start, 'end' => $end],
            'currency'           => $currency,
            'summary'            => array_merge((array) $summary->toArray() ?: (array) $summary, ['total_collected' => round($collected, 2)]),
            'daily_breakdown'    => $daily,
            'weekly_breakdown'   => $weekly,
            'by_payment_method'  => $byPaymentMethod,
            'by_hour'            => $byHour,
            'by_day_of_week'     => $byDayOfWeek,
            'comparison'         => $comparison,
        ]);
    }

    /**
     * GET /admin/reports/sales/by-product
     */
    public function salesByProduct(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $currency = $request->get('currency_code', 'KES');
        $limit    = (int) $request->get('limit', 50);

        $products = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.payment_status', 'paid')
            ->whereRaw('UPPER(orders.currency_code) = ?', [strtoupper($currency)])
            ->whereNotNull('order_items.product_name')
            ->groupBy('order_items.product_id', 'order_items.product_name', 'order_items.sku')
            ->selectRaw("
                order_items.product_id                                             AS id,
                order_items.product_name                                           AS name_en,
                order_items.sku,
                SUM(order_items.quantity)                                          AS units_sold,
                COALESCE(SUM(order_items.total_price), 0)                          AS total_revenue,
                COALESCE(AVG(order_items.unit_price), 0)                           AS avg_selling_price,
                COALESCE(MIN(order_items.unit_price), 0)                           AS min_price,
                COALESCE(MAX(order_items.unit_price), 0)                           AS max_price,
                COALESCE(SUM(order_items.discount_amount), 0)                      AS total_discounts,
                COUNT(DISTINCT orders.id)                                          AS order_count,
                COUNT(DISTINCT orders.user_id)                                     AS unique_customers,
                COALESCE(SUM(order_items.total_price) / NULLIF(SUM(order_items.quantity), 0), 0) AS revenue_per_unit
            ")
            ->orderByRaw('COALESCE(SUM(order_items.total_price), 0) DESC')
            ->limit($limit)
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Product', 'SKU', 'Units Sold', 'Orders', 'Unique Customers', 'Total Revenue', 'Avg Price', 'Total Discounts'],
                $products->map(fn ($p) => [$p->name_en, $p->sku, $p->units_sold, $p->order_count, $p->unique_customers, $p->total_revenue, $p->avg_selling_price, $p->total_discounts]),
                'sales_by_product'
            );
        }

        return response()->json([
            'period'   => ['start' => $start, 'end' => $end],
            'currency' => $currency,
            'products' => $products,
        ]);
    }

    /**
     * GET /admin/reports/sales/by-category
     */
    public function salesByCategory(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $currency = $request->get('currency_code', 'KES');

        $categories = DB::table('order_items')
            ->join('orders',     'order_items.order_id',  '=', 'orders.id')
            ->join('products',   'order_items.product_id','=', 'products.id')
            ->join('categories', 'products.category_id',  '=', 'categories.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.payment_status', 'paid')
            ->whereRaw('UPPER(orders.currency_code) = ?', [strtoupper($currency)])
            ->groupBy('categories.id', 'categories.name_en')
            ->selectRaw("
                categories.id,
                categories.name_en                                          AS category_name,
                SUM(order_items.quantity)                                   AS units_sold,
                COALESCE(SUM(order_items.total_price), 0)                   AS total_revenue,
                COALESCE(AVG(order_items.unit_price), 0)                    AS avg_price,
                COUNT(DISTINCT orders.id)                                   AS order_count,
                COUNT(DISTINCT order_items.product_id)                      AS product_count
            ")
            ->orderByRaw('COALESCE(SUM(order_items.total_price), 0) DESC')
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Category', 'Units Sold', 'Orders', 'Products', 'Total Revenue', 'Avg Price'],
                $categories->map(fn ($c) => [$c->category_name, $c->units_sold, $c->order_count, $c->product_count, $c->total_revenue, $c->avg_price]),
                'sales_by_category'
            );
        }

        return response()->json([
            'period'     => ['start' => $start, 'end' => $end],
            'categories' => $categories,
        ]);
    }

    /**
     * GET /admin/reports/sales/by-customer
     */
    public function salesByCustomer(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $limit    = (int) $request->get('limit', 50);
        $currency = $request->get('currency_code', 'KES');

        $customers = DB::table('orders')
            ->leftJoin('customers', function ($join) {
                $join->on('customers.user_id', '=', 'orders.user_id')
                     ->whereNotNull('orders.user_id');
            })
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.payment_status', 'paid')
            ->whereRaw('UPPER(orders.currency_code) = ?', [strtoupper($currency)])
            ->whereNotNull('orders.user_id')
            ->groupBy(
                'orders.user_id',
                'orders.customer_first_name',
                'orders.customer_last_name',
                'orders.customer_email',
                'orders.customer_phone',
                'customers.id'
            )
            ->selectRaw("
                orders.user_id,
                customers.id                                    AS customer_id,
                CONCAT(orders.customer_first_name, ' ', COALESCE(orders.customer_last_name, '')) AS name,
                orders.customer_email                           AS email,
                orders.customer_phone                           AS phone,
                COUNT(orders.id)                                AS order_count,
                COALESCE(SUM(orders.total_amount), 0)           AS total_spent,
                COALESCE(AVG(orders.total_amount), 0)           AS avg_order_value,
                MAX(orders.created_at)                          AS last_order_date
            ")
            ->orderByRaw('COALESCE(SUM(orders.total_amount), 0) DESC')
            ->limit($limit)
            ->get();

        return response()->json([
            'period'    => ['start' => $start, 'end' => $end],
            'customers' => $customers,
        ]);
    }

    /**
     * GET /admin/reports/sales/by-outlet
     */
    public function salesByOutlet(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $currency = $request->get('currency_code', 'KES');

        $outlets = DB::table('orders')
            ->join('outlets', 'orders.outlet_id', '=', 'outlets.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('orders.payment_status', 'paid')
            ->whereRaw('UPPER(orders.currency_code) = ?', [strtoupper($currency)])
            ->groupBy('outlets.id', 'outlets.name', 'outlets.city')
            ->selectRaw("
                outlets.id                                    AS outlet_id,
                outlets.name                                  AS outlet_name,
                outlets.city,
                COUNT(orders.id)                              AS order_count,
                COALESCE(SUM(orders.total_amount), 0)         AS total_revenue,
                COALESCE(AVG(orders.total_amount), 0)         AS avg_order_value,
                COALESCE(SUM(orders.total_amount) / NULLIF(COUNT(DISTINCT DATE(orders.created_at)), 0), 0) AS avg_daily_revenue,
                MAX(orders.created_at)                        AS last_sale_date
            ")
            ->orderByRaw('COALESCE(SUM(orders.total_amount), 0) DESC')
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Outlet', 'City', 'Orders', 'Total Revenue', 'Avg Order Value', 'Avg Daily Revenue'],
                $outlets->map(fn ($o) => [$o->outlet_name, $o->city, $o->order_count, $o->total_revenue, $o->avg_order_value, $o->avg_daily_revenue]),
                'sales_by_outlet'
            );
        }

        return response()->json([
            'period'  => ['start' => $start, 'end' => $end],
            'outlets' => $outlets,
        ]);
    }

    /**
     * GET /admin/reports/sales/by-payment-method
     */
    public function salesByPaymentMethod(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $currency = $request->get('currency_code', 'KES');

        $rows = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereRaw('UPPER(currency_code) = ?', [strtoupper($currency)])
            ->whereNotNull('payment_method')
            ->selectRaw("
                payment_method,
                COUNT(*) AS count,
                COALESCE(SUM(total_amount), 0) AS total
            ")
            ->groupBy('payment_method')
            ->orderByRaw('COALESCE(SUM(total_amount), 0) DESC')
            ->get();

        return response()->json([
            'period'          => ['start' => $start, 'end' => $end],
            'payment_methods' => $rows,
        ]);
    }

    /**
     * GET /admin/reports/sales/returns
     * Summarise return/refund activity.
     */
    public function salesReturns(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        // order_returns columns: return_reason, refund_amount, status
        // Join orders to get user_id for unique customer count
        $returns = DB::table('order_returns')
            ->join('orders', 'order_returns.order_id', '=', 'orders.id')
            ->whereBetween('order_returns.created_at', [$start, $end])
            ->selectRaw("
                COUNT(*)                                        AS total_returns,
                COALESCE(SUM(order_returns.refund_amount), 0)   AS total_refunded,
                COALESCE(AVG(order_returns.refund_amount), 0)   AS avg_refund,
                COUNT(DISTINCT orders.user_id)                  AS unique_customers
            ")
            ->first();

        // Breakdown by return_reason on order_returns (header-level reason)
        $byReason = DB::table('order_returns')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('return_reason')
            ->selectRaw("
                return_reason AS reason,
                COUNT(*) AS count,
                COALESCE(SUM(refund_amount), 0) AS total_refunded
            ")
            ->groupBy('return_reason')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        // Also aggregate per-item reasons from return_items for a finer breakdown
        $byItemReason = DB::table('return_items')
            ->join('order_returns', 'return_items.return_id', '=', 'order_returns.id')
            ->whereBetween('order_returns.created_at', [$start, $end])
            ->whereNotNull('return_items.reason')
            ->selectRaw("
                return_items.reason,
                COUNT(*) AS count,
                SUM(return_items.quantity) AS total_quantity
            ")
            ->groupBy('return_items.reason')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return response()->json([
            'period'         => ['start' => $start, 'end' => $end],
            'summary'        => $returns,
            'by_reason'      => $byReason,
            'by_item_reason' => $byItemReason,
        ]);
    }

    // =========================================================================
    // CUSTOMERS
    // =========================================================================

    /**
     * GET /admin/reports/customers/summary
     */
    public function customerSummary(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        $totalCustomers = Customer::count();
        $newCustomers   = Customer::whereBetween('created_at', [$start, $end])->count();

        $uniqueBuyers = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        // Repeat purchase rate: customers who placed >= 2 orders in the period
        // Use DB::table with explicit selectRaw so PostgreSQL GROUP BY is satisfied
        $repeatBuyers = DB::table(
            DB::table('orders')
                ->selectRaw('user_id, COUNT(*) AS order_count')
                ->whereBetween('created_at', [$start, $end])
                ->where('payment_status', 'paid')
                ->whereNotNull('user_id')
                ->groupBy('user_id'),
            'buyer_counts'
        )
            ->where('order_count', '>=', 2)
            ->count();

        $repeatRate = $uniqueBuyers > 0 ? round(($repeatBuyers / $uniqueBuyers) * 100, 1) : 0;

        // New vs returning in period
        $returningBuyers = DB::table('orders as o1')
            ->whereBetween('o1.created_at', [$start, $end])
            ->where('o1.payment_status', 'paid')
            ->whereNotNull('o1.user_id')
            ->whereExists(function ($q) use ($start) {
                $q->from('orders as o2')
                  ->whereRaw('o2.user_id = o1.user_id')
                  ->where('o2.payment_status', 'paid')
                  ->where('o2.created_at', '<', $start);
            })
            ->distinct('o1.user_id')
            ->count('o1.user_id');

        // New customer acquisition by month
        $acquisitionTrend = DB::table('customers')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') AS month, COUNT(*) AS new_customers")
            ->groupBy(DB::raw("TO_CHAR(created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get();

        return response()->json([
            'period'               => ['start' => $start, 'end' => $end],
            'total_customers'      => $totalCustomers,
            'new_customers'        => $newCustomers,
            'unique_buyers'        => $uniqueBuyers,
            'repeat_buyers'        => $repeatBuyers,
            'repeat_purchase_rate' => $repeatRate,
            'returning_buyers'     => $returningBuyers,
            'new_buyers'           => max(0, $uniqueBuyers - $returningBuyers),
            'acquisition_trend'    => $acquisitionTrend,
        ]);
    }

    /**
     * GET /admin/reports/customers/analytics
     */
    public function customerAnalytics(Request $request)
    {
        $period = (int) $request->get('period', 30);

        $stats = [
            'total_customers'  => Customer::count(),
            'new_customers'    => Customer::where('created_at', '>=', now()->subDays($period))->count(),
            'active_customers' => DB::table('orders')
                ->where('created_at', '>=', now()->subDays($period))
                ->where('payment_status', 'paid')
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id'),
        ];

        // Segment by lifetime order count
        $segments = DB::table('customers')
            ->leftJoin('orders', function ($join) {
                $join->on('orders.user_id', '=', 'customers.user_id')
                     ->where('orders.payment_status', 'paid');
            })
            ->groupBy('customers.id')
            ->selectRaw("
                COUNT(orders.id) AS order_count,
                CASE
                    WHEN COUNT(orders.id) >= 10 THEN 'VIP'
                    WHEN COUNT(orders.id) >= 5  THEN 'Regular'
                    WHEN COUNT(orders.id) >= 2  THEN 'Repeat'
                    ELSE 'New'
                END AS segment
            ")
            ->get()
            ->groupBy('segment')
            ->map(fn ($group) => $group->count());

        // RFM-style spend distribution
        $spendBrackets = DB::table('orders')
            ->where('payment_status', 'paid')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw("
                user_id,
                SUM(total_amount) AS lifetime_value,
                CASE
                    WHEN SUM(total_amount) >= 100000 THEN '100k+'
                    WHEN SUM(total_amount) >= 50000  THEN '50k-100k'
                    WHEN SUM(total_amount) >= 10000  THEN '10k-50k'
                    WHEN SUM(total_amount) >= 5000   THEN '5k-10k'
                    ELSE 'Under 5k'
                END AS bracket
            ")
            ->get()
            ->groupBy('bracket')
            ->map(fn ($g) => $g->count());

        return response()->json([
            'period_days'    => $period,
            'stats'          => $stats,
            'segments'       => $segments,
            'spend_brackets' => $spendBrackets,
        ]);
    }

    /**
     * GET /admin/reports/customers/lifetime-value
     */
    public function customerLifetimeValue(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $limit = (int) $request->get('limit', 25);

        $customers = DB::table('orders')
            ->leftJoin('customers', 'customers.user_id', '=', 'orders.user_id')
            ->where('orders.payment_status', 'paid')
            ->whereNotNull('orders.user_id')
            ->groupBy(
                'orders.user_id',
                'orders.customer_first_name',
                'orders.customer_last_name',
                'orders.customer_email',
                'orders.customer_phone',
                'customers.id'
            )
            ->selectRaw("
                customers.id,
                CONCAT(orders.customer_first_name, ' ', COALESCE(orders.customer_last_name, '')) AS name,
                orders.customer_email                                AS email,
                orders.customer_phone                                AS phone,
                COUNT(orders.id)                                     AS order_count,
                COALESCE(SUM(orders.total_amount), 0)                AS total_spent,
                COALESCE(AVG(orders.total_amount), 0)                AS avg_order_value,
                COALESCE(MAX(orders.total_amount), 0)                AS max_order_value,
                MIN(orders.created_at)                               AS first_order_date,
                MAX(orders.created_at)                               AS last_order_date,
                EXTRACT(DAY FROM (MAX(orders.created_at) - MIN(orders.created_at))) AS customer_lifespan_days
            ")
            ->orderByRaw('COALESCE(SUM(orders.total_amount), 0) DESC')
            ->limit($limit)
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Name', 'Email', 'Phone', 'Orders', 'Total Spent', 'Avg Order', 'First Order', 'Last Order'],
                $customers->map(fn ($c) => [$c->name, $c->email, $c->phone, $c->order_count, $c->total_spent, $c->avg_order_value, $c->first_order_date, $c->last_order_date]),
                'customer_lifetime_value'
            );
        }

        return response()->json([
            'period'    => ['start' => $start, 'end' => $end],
            'customers' => $customers,
        ]);
    }

    /**
     * GET /admin/reports/customers/aging - stub (no credit/invoice data yet)
     */
    public function customerAging(Request $request)
    {
        return response()->json([
            'message' => 'Customer aging report is not yet available.',
            'aging'   => [],
        ]);
    }

    /**
     * GET /admin/reports/customers/retention
     * Monthly cohort retention (simplified).
     */
    public function customerRetention(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        // Monthly new customers as cohorts
        $cohorts = DB::table('customers')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') AS cohort_month, COUNT(*) AS cohort_size")
            ->groupBy(DB::raw("TO_CHAR(created_at, 'YYYY-MM')"))
            ->orderBy('cohort_month')
            ->get();

        // For each cohort, count how many made a purchase in subsequent months
        $retention = $cohorts->map(function ($cohort) {
            $cohortMonth = $cohort->cohort_month;

            // Customers acquired in this cohort month
            $cohortUserIds = DB::table('customers')
                ->whereRaw("TO_CHAR(created_at, 'YYYY-MM') = ?", [$cohortMonth])
                ->whereNotNull('user_id')
                ->pluck('user_id');

            if ($cohortUserIds->isEmpty()) {
                return ['cohort' => $cohortMonth, 'size' => $cohort->cohort_size, 'months' => []];
            }

            // Purchases per month by cohort members (up to 6 months forward)
            $purchaseMonths = DB::table('orders')
                ->whereIn('user_id', $cohortUserIds)
                ->where('payment_status', 'paid')
                ->whereRaw("TO_CHAR(created_at, 'YYYY-MM') >= ?", [$cohortMonth])
                ->selectRaw("
                    TO_CHAR(created_at, 'YYYY-MM') AS month,
                    COUNT(DISTINCT user_id) AS retained
                ")
                ->groupBy(DB::raw("TO_CHAR(created_at, 'YYYY-MM')"))
                ->orderBy('month')
                ->limit(7)
                ->get()
                ->mapWithKeys(fn ($r) => [$r->month => $r->retained]);

            return [
                'cohort' => $cohortMonth,
                'size'   => $cohort->cohort_size,
                'months' => $purchaseMonths,
            ];
        });

        return response()->json([
            'period'    => ['start' => $start, 'end' => $end],
            'retention' => $retention,
        ]);
    }

    // =========================================================================
    // INVENTORY
    // =========================================================================

    /**
     * GET /admin/reports/inventory/stock-on-hand
     */
    public function stockOnHand(Request $request)
    {
        $query = InventoryItem::with([
            'product:id,sku',
            'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'product.category:id,name_en',
            'variant:id,sku,variant_name',
            'outlet:id,name',
        ])->where('quantity_on_hand', '>', 0);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }
        if ($request->boolean('low_stock_only')) {
            $query->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_point')
                  ->where('reorder_point', '>', 0);
        }
        if ($request->filled('category_id')) {
            $query->whereHas('product', fn ($q) => $q->where('category_id', $request->category_id));
        }

        $inventory = $query->get();

        $items = $inventory->map(function ($item) {
            $available   = $item->quantity_on_hand - $item->quantity_reserved;
            $status      = $available <= 0
                ? 'out_of_stock'
                : ($item->reorder_point > 0 && $available <= $item->reorder_point
                    ? 'low_stock'
                    : 'in_stock');
            $productName = $item->product?->translations?->first()?->name ?? $item->product?->sku ?? '-';

            return [
                'variant_id'          => $item->product_variant_id,
                'product_name'        => $productName,
                'variant_name'        => $item->variant?->variant_name ?? null,
                'variant_sku'         => $item->variant?->sku ?? $item->product?->sku ?? null,
                'category_name'       => $item->product?->category?->name_en ?? null,
                'outlet_name'         => $item->outlet?->name ?? 'Warehouse',
                'quantity'            => $item->quantity_on_hand,
                'quantity_reserved'   => $item->quantity_reserved,
                'quantity_available'  => max(0, $available),
                'low_stock_threshold' => $item->reorder_point,
                'cost_per_unit'       => null,
                'stock_value'         => null,
                'stock_status'        => $status,
            ];
        });

        $lowStockCount   = $inventory->filter(fn ($i) => $i->reorder_point > 0 && ($i->quantity_on_hand - $i->quantity_reserved) <= $i->reorder_point && $i->quantity_on_hand > 0)->count();
        $outOfStockCount = $inventory->filter(fn ($i) => ($i->quantity_on_hand - $i->quantity_reserved) <= 0)->count();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Product', 'SKU', 'Variant', 'Category', 'Outlet', 'Qty On Hand', 'Reserved', 'Available', 'Reorder Point', 'Status'],
                $items->map(fn ($i) => [$i['product_name'], $i['variant_sku'], $i['variant_name'], $i['category_name'], $i['outlet_name'], $i['quantity'], $i['quantity_reserved'], $i['quantity_available'], $i['low_stock_threshold'], $i['stock_status']]),
                'stock_on_hand'
            );
        }

        return response()->json([
            'totals' => [
                'total_items'        => $inventory->count(),
                'total_stock_value'  => null,
                'low_stock_count'    => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
            ],
            'items' => $items,
        ]);
    }

    /**
     * GET /admin/reports/inventory/low-stock
     */
    public function lowStockReport(Request $request)
    {
        $query = InventoryItem::with([
            'product:id,sku',
            'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'variant:id,sku,variant_name',
            'outlet:id,name',
        ])
            ->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->where('quantity_on_hand', '>', 0);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        $items = $query->orderBy('quantity_on_hand')->get()
            ->map(fn ($i) => [
                'id'                 => $i->id,
                'product_name'       => $i->product?->translations?->first()?->name ?? $i->product?->sku ?? '-',
                'sku'                => $i->variant?->sku ?? $i->product?->sku ?? '-',
                'variant_name'       => $i->variant?->variant_name ?? null,
                'outlet_name'        => $i->outlet?->name ?? 'Warehouse',
                'quantity_on_hand'   => $i->quantity_on_hand,
                'quantity_available' => max(0, $i->quantity_on_hand - $i->quantity_reserved),
                'reorder_point'      => $i->reorder_point,
                'shortage'           => max(0, $i->reorder_point - ($i->quantity_on_hand - $i->quantity_reserved)),
            ]);

        return response()->json([
            'low_stock_items' => $items,
            'total_count'     => $items->count(),
        ]);
    }

    /**
     * GET /admin/reports/inventory/valuation
     */
    public function inventoryValuation(Request $request)
    {
        $query = InventoryItem::with(['outlet:id,name'])
            ->where('quantity_on_hand', '>', 0);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        $inventory = $query->get();

        $byOutlet = $inventory->groupBy(fn ($i) => $i->outlet_id ?? 'warehouse')
            ->map(function ($items) {
                $outletName = $items->first()->outlet?->name ?? 'Warehouse';
                return [
                    'outlet_name'    => $outletName,
                    'item_count'     => $items->count(),
                    'total_quantity' => $items->sum('quantity_on_hand'),
                    'total_value'    => null,
                ];
            })->values();

        return response()->json([
            'by_outlet'   => $byOutlet,
            'total_value' => null,
        ]);
    }

    /**
     * GET /admin/reports/inventory/aging  - stub
     */
    public function inventoryAging(Request $request)
    {
        return response()->json(['message' => 'Inventory aging not yet implemented.', 'aging' => []]);
    }

    /**
     * GET /admin/reports/inventory/movement
     */
    public function inventoryMovement(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        $query = DB::table('inventory_transactions')
            ->join('inventory_items', 'inventory_transactions.inventory_item_id', '=', 'inventory_items.id')
            ->leftJoin('product_variants', 'inventory_items.product_variant_id', '=', 'product_variants.id')
            ->leftJoin('products',         'inventory_items.product_id',          '=', 'products.id')
            ->leftJoin('product_translations', function ($join) {
                $join->on('product_translations.product_id', '=', 'products.id')
                     ->where('product_translations.language_code', '=', 'en');
            })
            ->whereBetween('inventory_transactions.created_at', [$start, $end])
            ->selectRaw("
                inventory_transactions.id,
                inventory_transactions.created_at,
                inventory_transactions.transaction_type                AS type,
                inventory_transactions.quantity_change                 AS quantity,
                inventory_transactions.notes                           AS reference,
                COALESCE(product_translations.name, products.sku)     AS product_name,
                COALESCE(product_variants.sku, products.sku)           AS sku
            ")
            ->orderByDesc('inventory_transactions.created_at');

        if ($request->filled('product_id')) {
            $query->where('products.id', $request->product_id);
        }

        $transactions = $query->limit(200)->get();

        // Movement summary by type
        $byType = DB::table('inventory_transactions')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                transaction_type AS type,
                COUNT(*) AS count,
                SUM(ABS(quantity_change)) AS total_units
            ")
            ->groupBy('transaction_type')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Date', 'Product', 'SKU', 'Type', 'Quantity Change', 'Reference'],
                $transactions->map(fn ($t) => [$t->created_at, $t->product_name, $t->sku, $t->type, $t->quantity, $t->reference]),
                'inventory_movement'
            );
        }

        return response()->json([
            'period'       => ['start' => $start, 'end' => $end],
            'transactions' => $transactions,
            'by_type'      => $byType,
        ]);
    }

    // =========================================================================
    // FINANCIAL
    // =========================================================================

    /**
     * GET /admin/reports/financial/profit-loss
     * Optional: ?compare=1 for prior-period comparison
     */
    public function profitLoss(Request $request)
    {
        try {
        [$start, $end] = $this->dateRange($request);
        $currency = $request->get('currency_code', 'KES');

        $revenue = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereRaw('UPPER(currency_code) = ?', [strtoupper($currency)])
            ->sum('total_amount');

        $cogs = 0; // No cost_price column on order_items yet

        $grossProfit = $revenue - $cogs;
        $grossMargin = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0;

        $opex = DB::table('expenses')
            ->whereBetween('expense_date', [substr($start, 0, 10), substr($end, 0, 10)])
            ->whereIn('status', ['approved', 'paid'])
            ->sum('amount_kes');

        // Expenses by category for breakdown
        $expensesByCategory = DB::table('expenses')
            ->leftJoin('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->whereBetween('expenses.expense_date', [substr($start, 0, 10), substr($end, 0, 10)])
            ->whereIn('expenses.status', ['approved', 'paid'])
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->selectRaw("
                COALESCE(expense_categories.name, 'Uncategorized') AS category,
                COUNT(*) AS count,
                COALESCE(SUM(expenses.amount_kes), 0) AS total
            ")
            ->orderByRaw('COALESCE(SUM(expenses.amount_kes), 0) DESC')
            ->get();

        // Tax collected
        $taxCollected = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereRaw('UPPER(currency_code) = ?', [strtoupper($currency)])
            ->sum('tax_amount');

        // Discounts given
        $discountsGiven = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereRaw('UPPER(currency_code) = ?', [strtoupper($currency)])
            ->sum('discount_amount');

        $netProfit = $grossProfit - $opex;
        $netMargin = $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0;

        // Prior period comparison
        $comparison = null;
        if ($request->boolean('compare')) {
            [$ps, $pe] = $this->priorPeriod($start, $end);
            $priorRevenue = DB::table('orders')
                ->whereBetween('created_at', [$ps, $pe])
                ->where('payment_status', 'paid')
                ->whereRaw('UPPER(currency_code) = ?', [strtoupper($currency)])
                ->sum('total_amount');
            $priorOpex = DB::table('expenses')
                ->whereBetween('expense_date', [substr($ps, 0, 10), substr($pe, 0, 10)])
                ->whereIn('status', ['approved', 'paid'])
                ->sum('amount_kes');
            $priorNetProfit = $priorRevenue - $priorOpex;

            $comparison = [
                'period'               => ['start' => $ps, 'end' => $pe],
                'revenue'              => round($priorRevenue, 2),
                'operating_expenses'   => round($priorOpex, 2),
                'net_profit'           => round($priorNetProfit, 2),
                'revenue_change_pct'   => $this->pct((float)$revenue, (float)$priorRevenue),
                'opex_change_pct'      => $this->pct((float)$opex, (float)$priorOpex),
                'net_profit_change_pct'=> $this->pct((float)$netProfit, (float)$priorNetProfit),
            ];
        }

        if ($this->wantsExport($request)) {
            $plRows = [
                ['Revenue', round($revenue, 2)],
                ['Cost of Goods Sold', round($cogs, 2)],
                ['Gross Profit', round($grossProfit, 2)],
                ['Gross Margin %', $grossMargin],
                ['Operating Expenses', round($opex, 2)],
                ['Net Profit', round($netProfit, 2)],
                ['Net Margin %', $netMargin],
                ['Tax Collected', round($taxCollected, 2)],
                ['Discounts Given', round($discountsGiven, 2)],
            ];
            return $this->csvResponse(['Line Item', 'Amount (KES)'], $plRows, 'profit_loss');
        }

        return response()->json([
            'period'                     => ['start' => $start, 'end' => $end],
            'revenue'                    => round($revenue, 2),
            'cost_of_goods_sold'         => round($cogs, 2),
            'gross_profit'               => round($grossProfit, 2),
            'gross_profit_margin_percent'=> $grossMargin,
            'operating_expenses'         => round($opex, 2),
            'net_profit'                 => round($netProfit, 2),
            'net_margin'                 => $netMargin,
            'tax_collected'              => round($taxCollected, 2),
            'discounts_given'            => round($discountsGiven, 2),
            'expenses_by_category'       => $expensesByCategory,
            'comparison'                 => $comparison,
        ]);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('profitLoss failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return response()->json(['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 500);
        }
    }

    /**
     * GET /admin/reports/financial/revenue
     */
    public function revenue(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $currency = $request->get('currency_code', 'KES');

        $monthly = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('payment_status', 'paid')
            ->whereRaw('UPPER(currency_code) = ?', [strtoupper($currency)])
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') AS month, COALESCE(SUM(total_amount), 0) AS total, COUNT(*) AS orders")
            ->groupBy(DB::raw("TO_CHAR(created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get();

        return response()->json([
            'period'  => ['start' => $start, 'end' => $end],
            'monthly' => $monthly,
            'total'   => $monthly->sum('total'),
        ]);
    }

    /**
     * GET /admin/reports/financial/expenses
     */
    public function expenses(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        $startDate = substr($start, 0, 10);
        $endDate   = substr($end, 0, 10);

        $expensesList = DB::table('expenses')
            ->leftJoin('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->leftJoin('outlets', 'expenses.outlet_id', '=', 'outlets.id')
            ->whereBetween('expenses.expense_date', [$startDate, $endDate])
            ->when($request->filled('category_id'), fn ($q) => $q->where('expenses.category_id', $request->category_id))
            ->when($request->filled('status'),      fn ($q) => $q->where('expenses.status', $request->status))
            ->orderByDesc('expenses.expense_date')
            ->select(
                'expenses.id',
                'expenses.reference_number',
                'expenses.title',
                'expenses.expense_date AS date',
                'expenses.amount_kes AS amount',
                'expenses.currency_code',
                'expenses.status',
                'expenses.vendor_name',
                'expenses.payment_method',
                'expense_categories.name AS category',
                'outlets.name AS outlet_name'
            )
            ->get();

        $monthly = DB::table('expenses')
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->whereIn('status', ['approved', 'paid'])
            ->selectRaw("TO_CHAR(expense_date::date, 'YYYY-MM') AS month, COALESCE(SUM(amount_kes), 0) AS total, COUNT(*) AS count")
            ->groupBy(DB::raw("TO_CHAR(expense_date::date, 'YYYY-MM')"))
            ->orderBy('month')
            ->get();

        $byStatus = DB::table('expenses')
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->selectRaw("status, COUNT(*) AS count, COALESCE(SUM(amount_kes), 0) AS total")
            ->groupBy('status')
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Ref #', 'Title', 'Date', 'Category', 'Outlet', 'Vendor', 'Amount (KES)', 'Currency', 'Payment Method', 'Status'],
                $expensesList->map(fn ($e) => [$e->reference_number, $e->title, $e->date, $e->category, $e->outlet_name, $e->vendor_name, $e->amount, $e->currency_code, $e->payment_method, $e->status]),
                'expenses'
            );
        }

        return response()->json([
            'period'    => ['start' => $start, 'end' => $end],
            'expenses'  => $expensesList,
            'monthly'   => $monthly,
            'by_status' => $byStatus,
            'total'     => $expensesList->sum('amount'),
        ]);
    }

    // =========================================================================
    // PRODUCTION
    // =========================================================================

    /**
     * GET /admin/reports/production/summary
     */
    public function productionSummary(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        $summary = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                COUNT(*)                                                                           AS total_orders,
                COALESCE(SUM(quantity), 0)                                                         AS total_units_planned,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN quantity ELSE 0 END), 0)          AS total_units_produced,
                COUNT(CASE WHEN status = 'completed' THEN 1 END)                                   AS completed_count,
                COUNT(CASE WHEN status IN ('pending','assigned','in_progress') THEN 1 END)         AS active_count,
                COUNT(CASE WHEN status = 'qc_failed' THEN 1 END)                                   AS failed_count,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END)                                   AS cancelled_count,
                COALESCE(AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (completed_at - created_at)) / 3600 END), 0)           AS avg_completion_hours,
                COALESCE(AVG(CASE WHEN due_date IS NOT NULL AND completed_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (due_date::timestamp - completed_at)) / 3600 END), 0)  AS avg_hours_before_deadline
            ")
            ->first();

        // On-time delivery rate
        $onTime = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->whereNotNull('due_date')
            ->whereNotNull('completed_at')
            ->whereRaw('completed_at::date <= due_date::date')
            ->count();

        $completedWithDue = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'completed')
            ->whereNotNull('due_date')
            ->count();

        $onTimeRate = $completedWithDue > 0 ? round(($onTime / $completedWithDue) * 100, 1) : null;

        // By product
        $byProduct = DB::table('production_orders')
            ->join('products', 'production_orders.product_id', '=', 'products.id')
            ->leftJoin('product_translations', function ($join) {
                $join->on('product_translations.product_id', '=', 'products.id')
                     ->where('product_translations.language_code', '=', 'en');
            })
            ->whereBetween('production_orders.created_at', [$start, $end])
            ->groupBy('products.id', 'product_translations.name', 'products.sku')
            ->selectRaw("
                COALESCE(product_translations.name, products.sku) AS name_en,
                products.sku,
                COUNT(*)                                                                         AS order_count,
                COALESCE(SUM(production_orders.quantity), 0)                                    AS units_planned,
                COALESCE(SUM(CASE WHEN production_orders.status = 'completed' THEN production_orders.quantity ELSE 0 END), 0) AS units_produced,
                COUNT(CASE WHEN production_orders.status = 'qc_failed' THEN 1 END)              AS qc_failures,
                COALESCE(AVG(CASE WHEN production_orders.status = 'completed' AND production_orders.completed_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (production_orders.completed_at - production_orders.created_at)) / 3600 END), 0) AS avg_hours
            ")
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        // By tailor
        $byTailor = DB::table('production_order_assignees')
            ->join('production_orders', 'production_order_assignees.production_order_id', '=', 'production_orders.id')
            ->join('users', 'production_order_assignees.user_id', '=', 'users.id')
            ->whereBetween('production_orders.created_at', [$start, $end])
            ->where('production_orders.status', 'completed')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->selectRaw("
                users.id,
                CONCAT(users.first_name, ' ', users.last_name)          AS tailor_name,
                COUNT(DISTINCT production_orders.id)                     AS completed_orders,
                COALESCE(SUM(production_orders.quantity), 0)             AS units_produced,
                AVG(EXTRACT(EPOCH FROM (production_orders.completed_at - production_orders.created_at)) / 3600) AS avg_hours_per_order
            ")
            ->orderByRaw('COUNT(DISTINCT production_orders.id) DESC')
            ->get();

        // Daily production trend
        $dailyTrend = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                DATE(created_at) AS date,
                COUNT(*) AS orders_created,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS orders_completed
            ")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Status distribution
        $byStatus = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("status, COUNT(*) AS count, COALESCE(SUM(quantity), 0) AS units")
            ->groupBy('status')
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Product', 'SKU', 'Orders', 'Units Planned', 'Units Produced', 'QC Failures', 'Avg Hours'],
                $byProduct->map(fn ($p) => [$p->name_en, $p->sku, $p->order_count, $p->units_planned, $p->units_produced, $p->qc_failures, round($p->avg_hours, 1)]),
                'production_summary'
            );
        }

        return response()->json([
            'period'       => ['start' => $start, 'end' => $end],
            'summary'      => array_merge((array)$summary, [
                'on_time_rate'       => $onTimeRate,
                'on_time_count'      => $onTime,
                'completed_with_due' => $completedWithDue,
            ]),
            'by_product'   => $byProduct,
            'by_tailor'    => $byTailor,
            'daily_trend'  => $dailyTrend,
            'by_status'    => $byStatus,
        ]);
    }

    /**
     * GET /admin/reports/production/efficiency
     */
    public function productionEfficiency(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        $efficiency = DB::table('production_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                COUNT(*) AS total,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                COUNT(CASE WHEN status = 'qc_failed' THEN 1 END) AS qc_failed,
                AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (completed_at - created_at)) / 3600
                    ELSE NULL END) AS avg_completion_hours
            ")
            ->first();

        return response()->json([
            'period'     => ['start' => $start, 'end' => $end],
            'efficiency' => $efficiency,
        ]);
    }

    /**
     * GET /admin/reports/production/tailor-productivity
     */
    public function tailorProductivity(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        $tailors = DB::table('production_order_assignees')
            ->join('production_orders', 'production_order_assignees.production_order_id', '=', 'production_orders.id')
            ->join('users', 'production_order_assignees.user_id', '=', 'users.id')
            ->whereBetween('production_orders.created_at', [$start, $end])
            ->where('production_orders.status', 'completed')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->selectRaw("
                users.id,
                CONCAT(users.first_name, ' ', users.last_name)   AS tailor_name,
                COUNT(DISTINCT production_orders.id)              AS completed_orders,
                COALESCE(SUM(production_orders.quantity), 0)      AS units_produced,
                AVG(EXTRACT(EPOCH FROM (production_orders.completed_at - production_orders.created_at)) / 3600) AS avg_hours_per_order
            ")
            ->orderByRaw('COUNT(DISTINCT production_orders.id) DESC')
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Tailor', 'Completed Orders', 'Units Produced', 'Avg Hours/Order'],
                $tailors->map(fn ($t) => [$t->tailor_name, $t->completed_orders, $t->units_produced, round($t->avg_hours_per_order ?? 0, 1)]),
                'tailor_productivity'
            );
        }

        return response()->json([
            'period'  => ['start' => $start, 'end' => $end],
            'tailors' => $tailors,
        ]);
    }

    // =========================================================================
    // PROCUREMENT
    // =========================================================================

    /**
     * GET /admin/reports/purchase-orders
     */
    public function purchaseOrderReport(Request $request)
    {
        [$start, $end] = $this->dateRange($request);

        $summary = DB::table('purchase_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                COUNT(*)                                                                           AS total_orders,
                COALESCE(SUM(total_amount), 0)                                                     AS total_value,
                COALESCE(SUM(CASE WHEN status = 'received' THEN total_amount ELSE 0 END), 0)       AS received_value,
                COALESCE(SUM(CASE WHEN status = 'partial'  THEN total_amount ELSE 0 END), 0)       AS partial_value,
                COUNT(CASE WHEN status IN ('pending','approved','ordered') THEN 1 END)             AS pending_count,
                COUNT(CASE WHEN status = 'received' THEN 1 END)                                    AS received_count,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END)                                   AS cancelled_count,
                COALESCE(AVG(total_amount), 0)                                                     AS avg_po_value,
                COALESCE(AVG(CASE WHEN status = 'received' AND expected_delivery_date IS NOT NULL
                    THEN EXTRACT(DAY FROM (updated_at - created_at)) END), 0)                      AS avg_lead_days
            ")
            ->first();

        $bySupplier = DB::table('purchase_orders')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->whereBetween('purchase_orders.created_at', [$start, $end])
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.email')
            ->selectRaw("
                suppliers.id,
                suppliers.name,
                suppliers.email,
                COUNT(*)                                         AS order_count,
                COALESCE(SUM(purchase_orders.total_amount), 0)  AS total_value,
                COALESCE(AVG(purchase_orders.total_amount), 0)  AS avg_value,
                COUNT(CASE WHEN purchase_orders.status = 'received' THEN 1 END) AS received_count,
                COUNT(CASE WHEN purchase_orders.status IN ('pending','ordered') THEN 1 END) AS pending_count
            ")
            ->orderByRaw('COALESCE(SUM(purchase_orders.total_amount), 0) DESC')
            ->get();

        // Monthly spend trend
        $monthlyTrend = DB::table('purchase_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                TO_CHAR(created_at, 'YYYY-MM') AS month,
                COUNT(*) AS orders,
                COALESCE(SUM(total_amount), 0) AS total_value
            ")
            ->groupBy(DB::raw("TO_CHAR(created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get();

        // Status breakdown
        $byStatus = DB::table('purchase_orders')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("status, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total")
            ->groupBy('status')
            ->get();

        // Top purchased items
        $topItems = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->leftJoin('products', 'purchase_order_items.product_id', '=', 'products.id')
            ->leftJoin('product_translations', function ($join) {
                $join->on('product_translations.product_id', '=', 'products.id')
                     ->where('product_translations.language_code', '=', 'en');
            })
            ->whereBetween('purchase_orders.created_at', [$start, $end])
            ->groupBy('purchase_order_items.product_id', 'product_translations.name', 'products.sku')
            ->selectRaw("
                COALESCE(product_translations.name, products.sku, 'Unknown') AS product_name,
                products.sku,
                SUM(purchase_order_items.quantity) AS total_quantity,
                COALESCE(SUM(purchase_order_items.total_price), 0) AS total_spend,
                COUNT(DISTINCT purchase_orders.id) AS po_count
            ")
            ->orderByRaw('COALESCE(SUM(purchase_order_items.total_price), 0) DESC')
            ->limit(15)
            ->get();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Supplier', 'Email', 'POs', 'Total Spend', 'Avg PO Value', 'Received', 'Pending'],
                $bySupplier->map(fn ($s) => [$s->name, $s->email, $s->order_count, $s->total_value, $s->avg_value, $s->received_count, $s->pending_count]),
                'procurement_report'
            );
        }

        return response()->json([
            'period'        => ['start' => $start, 'end' => $end],
            'summary'       => $summary,
            'by_supplier'   => $bySupplier,
            'monthly_trend' => $monthlyTrend,
            'by_status'     => $byStatus,
            'top_items'     => $topItems,
        ]);
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    /**
     * GET /admin/reports/dashboard/kpis
     */
    public function dashboardKPIs(Request $request)
    {
        $period   = (int) $request->get('days', 30);
        $since    = now()->subDays($period);
        $currency = $request->get('currency_code', 'KES');

        $kpis = [
            'sales' => [
                'total' => DB::table('orders')
                    ->where('payment_status', 'paid')
                    ->whereRaw('UPPER(currency_code) = ?', [strtoupper($currency)])
                    ->where('created_at', '>=', $since)
                    ->sum('total_amount'),
                'count' => DB::table('orders')
                    ->where('payment_status', 'paid')
                    ->where('created_at', '>=', $since)
                    ->count(),
                'average' => DB::table('orders')
                    ->where('payment_status', 'paid')
                    ->whereRaw('UPPER(currency_code) = ?', [strtoupper($currency)])
                    ->where('created_at', '>=', $since)
                    ->avg('total_amount') ?? 0,
            ],
            'customers' => [
                'total' => Customer::count(),
                'new'   => Customer::where('created_at', '>=', $since)->count(),
            ],
            'products' => [
                'total'     => Product::where('status', 'active')->count(),
                'low_stock' => InventoryItem::whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_point')->where('reorder_point', '>', 0)->count(),
            ],
            'production' => [
                'active' => DB::table('production_orders')
                    ->whereIn('status', ['pending', 'assigned', 'in_progress'])
                    ->count(),
                'completed_this_period' => DB::table('production_orders')
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', $since)
                    ->count(),
            ],
        ];

        return response()->json([
            'period_days' => $period,
            'kpis'        => $kpis,
        ]);
    }

    // =========================================================================
    // SCHEDULED REPORTS
    // =========================================================================

    /**
     * GET /admin/reports/schedules
     * List all scheduled report configs (stored in system_settings as JSON).
     */
    public function listSchedules(Request $request)
    {
        $schedules = DB::table('system_settings')
            ->where('key', 'like', 'report_schedule_%')
            ->get()
            ->map(fn ($s) => json_decode($s->value, true));

        return response()->json(['schedules' => $schedules]);
    }

    /**
     * POST /admin/reports/schedules
     * Create or update a scheduled report.
     * Body: { report_type, frequency, recipients, format, filters, name }
     */
    public function saveSchedule(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'report_type'  => 'required|in:sales,customers,inventory,financial,production,procurement',
            'frequency'    => 'required|in:daily,weekly,monthly',
            'recipients'   => 'required|array|min:1',
            'recipients.*' => 'email',
            'format'       => 'required|in:csv,pdf',
            'filters'      => 'nullable|array',
            'is_active'    => 'boolean',
        ]);

        $id  = $validated['report_type'] . '_' . \Illuminate\Support\Str::slug($validated['name']);
        $key = 'report_schedule_' . $id;

        $schedule = array_merge($validated, [
            'id'         => $id,
            'created_by' => $request->user()->id,
            'created_at' => now()->toIso8601String(),
            'is_active'  => $validated['is_active'] ?? true,
        ]);

        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($schedule), 'updated_at' => now()]
        );

        return response()->json(['message' => 'Schedule saved.', 'schedule' => $schedule], 201);
    }

    /**
     * DELETE /admin/reports/schedules/{id}
     */
    public function deleteSchedule(Request $request, string $id)
    {
        DB::table('system_settings')->where('key', 'report_schedule_' . $id)->delete();
        return response()->json(['message' => 'Schedule deleted.']);
    }

    // =========================================================================
    // LEGACY EXPORT STUBS (now replaced by ?export=csv on each endpoint)
    // =========================================================================

    public function exportPDF(Request $request)
    {
        return response()->json(['message' => 'Use ?export=csv on any report endpoint for data export. PDF export requires a server-side PDF library (e.g. barryvdh/laravel-dompdf).'], 501);
    }

    public function exportExcel(Request $request)
    {
        return response()->json(['message' => 'Use ?export=csv on any report endpoint. The CSV uses UTF-8 BOM so it opens correctly in Excel.'], 200);
    }
    // =========================================================================
    // PRODUCT COSTING & PROFITABILITY
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/production/costing/{id}
     *
     * Builds a full Product Costing & Profitability Report for a single
     * production order. Follows the 8-section format:
     *   1. Report Header
     *   2. Production Cost Breakdown  (material allocations + overrides)
     *   3. Cost Per Unit
     *   4. Sales Summary
     *   5. Gross Profit Analysis
     *   6. Net Profit Analysis  (less selling expenses)
     *   7. Profit Margins
     *   8. Recommendation / Management Decision
     *
     * Query parameters (all optional — fall back to DB data when omitted):
     *   selling_price       Override the selling price per unit (KES)
     *   quantity_sold       Override how many units were sold from this batch
     *   labour_cost         Total labour / tailoring cost for the batch
     *   packaging_cost      Total packaging cost for the batch
     *   other_costs         Other costs (transport, adjustments, etc.)
     *   delivery_cost       Selling expense: delivery / dispatch
     *   commission          Selling expense: sales commission
     *   marketing_cost      Selling expense: marketing
     *   payment_charges     Selling expense: payment gateway fees
     *   management_comment  Free-text management note appended to recommendation
     */
    public function productCostingReport(Request $request, int $id)
    {
        // ── 1. Load production order with all relations ───────────────────────
        $order = \App\Models\ProductionOrder::with([
            'product:id,sku',
            'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'product.images'       => fn ($q) => $q->where('is_primary', true)->select('product_id', 'image_url'),
            'variant:id,variant_name,sku',
            'outlet:id,name',
            'materialAllocations.material:id,name,code,unit_of_measure,unit_cost',
            'createdBy:id,first_name,last_name',
        ])->findOrFail($id);

        $productName = $order->product->translations->first()?->name
            ?? $order->product->sku
            ?? "Production Order #{$order->order_number}";

        $sku = $order->variant?->sku ?? $order->product->sku;

        // ── 2. Production Cost Breakdown ──────────────────────────────────────
        // Each material_allocation row is one cost line.
        // Additional lines come from the order's specifications JSON or request params.

        $specs = is_array($order->specifications) ? $order->specifications : [];

        $materialLines = $order->materialAllocations->map(function ($alloc) {
            $qty      = (float) ($alloc->quantity_allocated ?? $alloc->quantity_required ?? 0);
            $unitCost = (float) ($alloc->material?->unit_cost ?? 0);
            $total    = round($qty * $unitCost, 2);
            return [
                'cost_item'   => $alloc->material?->name ?? 'Material',
                'description' => $alloc->material
                    ? ($alloc->material->name . ' (' . $alloc->material->code . ')')
                    : '—',
                'quantity'    => $qty,
                'unit'        => $alloc->material?->unit_of_measure ?? 'pcs',
                'unit_cost'   => $unitCost,
                'total_cost'  => $total,
                'type'        => 'material',
            ];
        })->values()->toArray();

        $additionalLines = [];

        $labourCost = (float) ($request->get('labour_cost') ?? $specs['labour_cost'] ?? 0);
        if ($labourCost > 0) {
            $additionalLines[] = [
                'cost_item'   => 'Labour',
                'description' => 'Tailoring cost',
                'quantity'    => $order->quantity,
                'unit'        => 'pcs',
                'unit_cost'   => round($labourCost / max($order->quantity, 1), 2),
                'total_cost'  => $labourCost,
                'type'        => 'labour',
            ];
        }

        $packagingCost = (float) ($request->get('packaging_cost') ?? $specs['packaging_cost'] ?? 0);
        if ($packagingCost > 0) {
            $additionalLines[] = [
                'cost_item'   => 'Packaging',
                'description' => 'Bags, labels, wrapping',
                'quantity'    => $order->quantity,
                'unit'        => 'pcs',
                'unit_cost'   => round($packagingCost / max($order->quantity, 1), 2),
                'total_cost'  => $packagingCost,
                'type'        => 'packaging',
            ];
        }

        $otherCosts = (float) ($request->get('other_costs') ?? $specs['other_costs'] ?? 0);
        if ($otherCosts > 0) {
            $additionalLines[] = [
                'cost_item'   => 'Other Costs',
                'description' => 'Transport, adjustments, etc.',
                'quantity'    => null,
                'unit'        => null,
                'unit_cost'   => null,
                'total_cost'  => $otherCosts,
                'type'        => 'other',
            ];
        }

        $costLines            = array_merge($materialLines, $additionalLines);
        $totalProductionCost  = array_sum(array_column($costLines, 'total_cost'));
        $quantityProduced     = (int) $order->quantity;
        $costPerUnit          = $quantityProduced > 0
            ? round($totalProductionCost / $quantityProduced, 2)
            : 0;

        // ── 3. Sales Summary ──────────────────────────────────────────────────
        // ── Sales: scoped strictly to this production order ─────────────────
        //
        // Two cases:
        //  A) Customer / make-to-order: the production order has a customer_order_id
        //     and optionally an order_item_id. Pull figures directly from that specific
        //     order row — this is the only sale attributable to this batch.
        //  B) Stock production (no customer_order_id): we cannot reliably attribute
        //     any particular sale to this batch (the stock is pooled), so we return
        //     zero and let the manager supply quantity_sold via the request override.

        $salesRow = null;

        if ($order->customer_order_id) {
            // Case A — linked sales order
            if ($order->order_item_id) {
                // Most precise: pull from the specific order item
                $salesRow = DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->where('order_items.id', $order->order_item_id)
                    ->selectRaw("
                        order_items.quantity              AS quantity_sold,
                        order_items.unit_price            AS avg_selling_price,
                        order_items.total_price           AS total_sales_value
                    ")
                    ->first();
            } else {
                // Fallback: sum all items on the linked order for this product
                $salesRow = DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->where('order_items.order_id', $order->customer_order_id)
                    ->where('order_items.product_id', $order->product_id)
                    ->when($order->product_variant_id, fn ($q) =>
                        $q->where('order_items.product_variant_id', $order->product_variant_id))
                    ->selectRaw("
                        SUM(order_items.quantity)         AS quantity_sold,
                        AVG(order_items.unit_price)       AS avg_selling_price,
                        SUM(order_items.total_price)      AS total_sales_value
                    ")
                    ->first();
            }
        }
        // Case B: $salesRow remains null — quantity_sold will be 0 unless overridden

        // Fallback selling price: use regular_price from product_prices (no outlet_id column on this table)
        $defaultPrice = DB::table('product_prices')
            ->where('product_id', $order->product_id)
            ->whereNull('product_variant_id')
            ->where('currency_code', 'KES')
            ->value('regular_price') ?? 0;

        $quantitySold   = (int)   ($request->get('quantity_sold')   ?? $salesRow?->quantity_sold    ?? 0);
        $sellingPrice   = (float) ($request->get('selling_price')   ?? $salesRow?->avg_selling_price ?? $defaultPrice);
        $totalSales     = round($quantitySold * $sellingPrice, 2);
        $remainingStock = max(0, $quantityProduced - $quantitySold);

        // ── 4. Gross Profit ───────────────────────────────────────────────────
        $cogs        = round($costPerUnit * $quantitySold, 2);
        $grossProfit = round($totalSales - $cogs, 2);

        // ── 5. Selling Expenses ───────────────────────────────────────────────
        $deliveryCost   = (float) ($request->get('delivery_cost')   ?? 0);
        $commission     = (float) ($request->get('commission')      ?? 0);
        $marketingCost  = (float) ($request->get('marketing_cost')  ?? 0);
        $paymentCharges = (float) ($request->get('payment_charges') ?? 0);
        $totalSellingExp = round($deliveryCost + $commission + $marketingCost + $paymentCharges, 2);

        $sellingExpenses = [
            ['label' => 'Delivery / Dispatch Cost', 'amount' => $deliveryCost],
            ['label' => 'Sales Commission',          'amount' => $commission],
            ['label' => 'Marketing Cost',            'amount' => $marketingCost],
            ['label' => 'Payment Charges',           'amount' => $paymentCharges],
        ];

        // ── 6. Net Profit ─────────────────────────────────────────────────────
        $netProfit = round($grossProfit - $totalSellingExp, 2);

        // ── 7. Margins ────────────────────────────────────────────────────────
        $grossMargin = $totalSales > 0 ? round(($grossProfit / $totalSales) * 100, 1) : 0;
        $netMargin   = $totalSales > 0 ? round(($netProfit   / $totalSales) * 100, 1) : 0;
        $markup      = $cogs > 0       ? round(($grossProfit / $cogs)       * 100, 1) : 0;

        // ── 8. Auto-generate recommendation ──────────────────────────────────
        $profitabilityStatus = match(true) {
            $netMargin >= 40 => 'Highly profitable',
            $netMargin >= 20 => 'Profitable',
            $netMargin >= 0  => 'Marginally profitable',
            default          => 'Loss-making – review pricing or costs',
        };

        $pricingRecommendation = match(true) {
            $netMargin >= 40 => 'Maintain or increase selling price slightly',
            $netMargin >= 20 => 'Maintain current pricing',
            $netMargin >= 0  => 'Consider a price increase or cost reduction',
            default          => 'Urgent: increase price or reduce production cost',
        };

        $stockAction = match(true) {
            $remainingStock === 0 => 'All units sold – plan next production batch',
            $remainingStock <= 2  => "Push remaining {$remainingStock} piece(s) through sales team",
            default               => "Move {$remainingStock} units: consider promotions or reallocation",
        };

        $managementDecision = $netMargin >= 20 ? 'Continue production' : 'Review before next batch';

        // ── 9. Assemble response ──────────────────────────────────────────────
        return response()->json([
            'report' => [
                'header' => [
                    'report_name'         => 'Product Costing & Profitability Report',
                    'product_name'        => $productName,
                    'product_code'        => $sku,
                    'batch_number'        => $order->order_number,
                    'production_date'     => $order->created_at?->format('d M Y'),
                    'completion_date'     => $order->completed_at?->format('d M Y'),
                    'quantity_produced'   => $quantityProduced,
                    'outlet'              => $order->outlet?->name,
                    'is_customer_order'   => (bool) $order->customer_order_id,
                    'customer_order_id'   => $order->customer_order_id,
                    'prepared_by'         => $order->createdBy
                        ? trim($order->createdBy->first_name . ' ' . $order->createdBy->last_name)
                        : 'Production Team',
                    'generated_at'      => now()->format('d M Y H:i'),
                ],
                'cost_breakdown' => [
                    'lines'                => $costLines,
                    'total_production_cost' => $totalProductionCost,
                ],
                'cost_summary' => [
                    'total_production_cost' => $totalProductionCost,
                    'quantity_produced'     => $quantityProduced,
                    'cost_per_unit'         => $costPerUnit,
                ],
                'sales_summary' => [
                    'product_name'      => $productName,
                    'quantity_sold'     => $quantitySold,
                    'selling_price'     => $sellingPrice,
                    'total_sales'       => $totalSales,
                    'quantity_produced' => $quantityProduced,
                    'remaining_stock'   => $remainingStock,
                ],
                'gross_profit' => [
                    'total_sales'  => $totalSales,
                    'cogs'         => $cogs,
                    'gross_profit' => $grossProfit,
                ],
                'net_profit' => [
                    'selling_expenses'       => $sellingExpenses,
                    'total_selling_expenses' => $totalSellingExp,
                    'gross_profit'           => $grossProfit,
                    'net_profit'             => $netProfit,
                ],
                'margins' => [
                    'gross_margin' => $grossMargin,
                    'net_margin'   => $netMargin,
                    'markup'       => $markup,
                ],
                'final_summary' => [
                    'quantity_produced'     => $quantityProduced,
                    'quantity_sold'         => $quantitySold,
                    'remaining_stock'       => $remainingStock,
                    'total_production_cost' => $totalProductionCost,
                    'cost_per_unit'         => $costPerUnit,
                    'total_sales'           => $totalSales,
                    'gross_profit'          => $grossProfit,
                    'net_profit'            => $netProfit,
                    'net_margin'            => $netMargin,
                ],
                'recommendation' => [
                    'profitability_status'   => $profitabilityStatus,
                    'pricing_recommendation' => $pricingRecommendation,
                    'cost_control_note'      => 'Monitor labour and material costs each batch',
                    'stock_action'           => $stockAction,
                    'management_decision'    => $managementDecision,
                    'management_comment'     => $request->get('management_comment'),
                ],
            ],
        ]);
    }


    // =========================================================================
    // PRODUCTION COSTING SUMMARY
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/production/costing-summary
     *
     * Aggregated costing & profitability report across all completed production
     * orders within the date range.
     *
     * For each production order we compute:
     *   - Material cost  : sum of (material_allocations.quantity_allocated * materials.unit_cost)
     *   - Revenue        : from the linked order item (customer_order_id / order_item_id)
     *   - Gross profit   : revenue - material cost
     *   - Net margin     : gross profit / revenue
     *
     * Totals are also aggregated for the period.
     *
     * Query params:
     *   start_date   YYYY-MM-DD  (default: 30 days ago)
     *   end_date     YYYY-MM-DD  (default: today)
     *   outlet_id    int         (optional)
     *   status       string      (default: completed)
     *   export       csv         (optional)
     */
    public function productionCostingSummary(Request $request)
    {
        [$start, $end] = $this->dateRange($request);
        $outletId = $request->filled('outlet_id') ? (int) $request->outlet_id : null;
        $status   = $request->get('status', 'completed');

        // ── Per-order aggregation ─────────────────────────────────────────────
        //
        // We join production_orders → material_allocations → materials to get
        // material cost, and left-join order_items to get revenue where a
        // customer order is linked.

        $rows = DB::table('production_orders AS po')
            ->join('products AS p',    'po.product_id', '=', 'p.id')
            ->leftJoin('product_translations AS pt', function ($j) {
                $j->on('pt.product_id', '=', 'p.id')
                  ->where('pt.language_code', '=', 'en');
            })
            ->leftJoin('product_variants AS pv', 'po.product_variant_id', '=', 'pv.id')
            ->leftJoin('material_allocations AS ma', 'ma.production_order_id', '=', 'po.id')
            ->leftJoin('materials AS m', 'm.id', '=', 'ma.material_id')
            // Revenue from linked order item (most precise) or order
            ->leftJoin('order_items AS oi', function ($j) {
                $j->on('oi.id', '=', 'po.order_item_id')
                  ->orOn(function ($sub) {
                      // fallback: match by order_id + product_id when no order_item_id
                      $sub->whereNull('po.order_item_id')
                          ->on('oi.order_id', '=', 'po.customer_order_id')
                          ->on('oi.product_id', '=', 'po.product_id');
                  });
            })
            ->leftJoin('orders AS o', 'o.id', '=', 'po.customer_order_id')
            ->leftJoin('outlets AS out', 'out.id', '=', 'po.outlet_id')
            ->whereBetween('po.created_at', [$start, $end])
            ->where('po.status', $status)
            ->when($outletId, fn ($q) => $q->where('po.outlet_id', $outletId))
            ->groupBy(
                'po.id', 'po.order_number', 'po.quantity',
                'po.created_at', 'po.completed_at',
                'po.customer_order_id', 'po.is_customer_order',
                'p.id', 'p.sku',
                'pt.name',
                'pv.variant_name', 'pv.sku',
                'out.name'
            )
            ->selectRaw("
                po.id,
                po.order_number                                                  AS batch_number,
                po.quantity                                                      AS qty_produced,
                po.created_at                                                    AS produced_at,
                po.completed_at,
                po.customer_order_id,
                po.is_customer_order,
                COALESCE(pt.name, p.sku)                                        AS product_name,
                COALESCE(pv.sku, p.sku)                                         AS sku,
                COALESCE(pv.variant_name, '')                                   AS variant_name,
                out.name                                                         AS outlet_name,

                -- Material cost: allocated qty × material unit_cost
                COALESCE(SUM(
                    COALESCE(ma.quantity_allocated, ma.quantity_required, 0)
                    * COALESCE(m.unit_cost, 0)
                ), 0)                                                            AS material_cost,

                -- Revenue: from linked order item(s)
                COALESCE(SUM(oi.total_price), 0)                                AS revenue,

                -- Selling price per unit (avg)
                COALESCE(AVG(oi.unit_price), 0)                                 AS selling_price_per_unit,

                -- Qty sold (from linked order)
                COALESCE(SUM(oi.quantity), 0)                                   AS qty_sold
            ")
            ->orderByDesc('po.created_at')
            ->get();

        // ── Derive profit fields for each row ─────────────────────────────────
        $orders = $rows->map(function ($r) {
            $materialCost = (float) $r->material_cost;
            $revenue      = (float) $r->revenue;
            $grossProfit  = $revenue - $materialCost;
            $grossMargin  = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : null;
            $costPerUnit  = $r->qty_produced > 0 ? round($materialCost / $r->qty_produced, 2) : 0;
            $remaining    = max(0, $r->qty_produced - (int) $r->qty_sold);

            return [
                'id'                  => $r->id,
                'batch_number'        => $r->batch_number,
                'product_name'        => $r->product_name,
                'sku'                 => $r->sku,
                'variant_name'        => $r->variant_name ?: null,
                'outlet_name'         => $r->outlet_name,
                'is_customer_order'   => (bool) $r->is_customer_order,
                'produced_at'         => $r->produced_at ? substr($r->produced_at, 0, 10) : null,
                'completed_at'        => $r->completed_at ? substr($r->completed_at, 0, 10) : null,
                'qty_produced'        => (int) $r->qty_produced,
                'qty_sold'            => (int) $r->qty_sold,
                'qty_remaining'       => $remaining,
                'material_cost'       => round($materialCost, 2),
                'cost_per_unit'       => $costPerUnit,
                'revenue'             => round($revenue, 2),
                'selling_price_per_unit' => round((float) $r->selling_price_per_unit, 2),
                'gross_profit'        => round($grossProfit, 2),
                'gross_margin'        => $grossMargin,
                'is_profitable'       => $grossProfit >= 0,
            ];
        });

        // ── Period totals ─────────────────────────────────────────────────────
        $totalMaterialCost = $orders->sum('material_cost');
        $totalRevenue      = $orders->sum('revenue');
        $totalGrossProfit  = $orders->sum('gross_profit');
        $avgGrossMargin    = $totalRevenue > 0
            ? round(($totalGrossProfit / $totalRevenue) * 100, 1)
            : null;

        $totals = [
            'order_count'       => $orders->count(),
            'total_qty_produced' => $orders->sum('qty_produced'),
            'total_qty_sold'     => $orders->sum('qty_sold'),
            'total_material_cost' => round($totalMaterialCost, 2),
            'total_revenue'      => round($totalRevenue, 2),
            'total_gross_profit' => round($totalGrossProfit, 2),
            'avg_gross_margin'   => $avgGrossMargin,
            'profitable_count'   => $orders->where('is_profitable', true)->count(),
            'loss_count'         => $orders->where('is_profitable', false)->count(),
        ];

        // ── By-product aggregation ────────────────────────────────────────────
        $byProduct = $orders->groupBy('product_name')->map(function ($group, $name) {
            $rev  = $group->sum('revenue');
            $cost = $group->sum('material_cost');
            $gp   = $group->sum('gross_profit');
            return [
                'product_name'   => $name,
                'batch_count'    => $group->count(),
                'qty_produced'   => $group->sum('qty_produced'),
                'material_cost'  => round($cost, 2),
                'revenue'        => round($rev, 2),
                'gross_profit'   => round($gp, 2),
                'gross_margin'   => $rev > 0 ? round(($gp / $rev) * 100, 1) : null,
            ];
        })->values()->sortByDesc('gross_profit')->values();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Batch', 'Product', 'SKU', 'Produced', 'Sold', 'Material Cost', 'Revenue', 'Gross Profit', 'Gross Margin %', 'Produced At', 'Completed At'],
                $orders->map(fn ($r) => [
                    $r['batch_number'], $r['product_name'], $r['sku'],
                    $r['qty_produced'], $r['qty_sold'], $r['material_cost'],
                    $r['revenue'], $r['gross_profit'], $r['gross_margin'],
                    $r['produced_at'], $r['completed_at'],
                ]),
                'production_costing_summary'
            );
        }

        return response()->json([
            'period'     => ['start' => $start, 'end' => $end],
            'totals'     => $totals,
            'orders'     => $orders,
            'by_product' => $byProduct,
        ]);
    }


}