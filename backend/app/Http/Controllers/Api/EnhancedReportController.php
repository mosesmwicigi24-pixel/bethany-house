<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Order, OrderItem, Product, Customer, Inventory, User, Expense, PurchaseOrder};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comprehensive Reporting & Analytics Controller
 *
 * All endpoints support:
 *  - Date range filtering (start_date / end_date)
 *  - Outlet/channel scoping
 *  - CSV + JSON export (add ?export=csv to any endpoint)
 *  - Role-based data scoping
 */
class EnhancedReportController extends Controller
{
    // =========================================================================
    // SALES REPORTS
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/sales/summary
     */
    public function salesSummary(Request $request)
    {
        $p = $this->params($request, ['outlet_id', 'channel', 'currency']);

        $base = $this->salesBase($p);

        $summary = $base->clone()->selectRaw("
            COUNT(*)                                                         AS total_orders,
            SUM(orders.total_amount)                                         AS total_revenue,
            SUM(orders.subtotal)                                             AS subtotal,
            SUM(orders.discount_amount)                                      AS total_discounts,
            SUM(orders.tax_amount)                                           AS total_tax,
            SUM(orders.shipping_amount)                                      AS total_shipping,
            AVG(orders.total_amount)                                         AS avg_order_value,
            MIN(orders.total_amount)                                         AS min_order_value,
            MAX(orders.total_amount)                                         AS max_order_value,
            SUM(CASE WHEN orders.order_type='online' THEN orders.total_amount ELSE 0 END) AS online_revenue,
            SUM(CASE WHEN orders.order_type='pos'    THEN orders.total_amount ELSE 0 END) AS pos_revenue,
            COUNT(CASE WHEN orders.order_type='online' THEN 1 END)           AS online_count,
            COUNT(CASE WHEN orders.order_type='pos'    THEN 1 END)           AS pos_count
        ")->first();

        // Daily breakdown - qualify created_at with table name to avoid ambiguity
        $daily = $base->clone()
            ->selectRaw("DATE(orders.created_at) AS date, COUNT(*) AS orders, SUM(orders.total_amount) AS revenue, AVG(orders.total_amount) AS avg_value")
            ->groupBy('date')->orderBy('date')->get();

        // By payment method
        $byPayment = $base->clone()
            ->selectRaw("orders.payment_method, COUNT(*) AS count, SUM(orders.total_amount) AS total")
            ->groupBy('orders.payment_method')->orderByDesc('total')->get();

        // By outlet - use leftJoin so orders without an outlet are still counted
        $byOutlet = $base->clone()
            ->leftJoin('outlets', 'orders.outlet_id', '=', 'outlets.id')
            ->selectRaw("outlets.id, outlets.name, COUNT(*) AS orders, SUM(orders.total_amount) AS revenue")
            ->groupBy('outlets.id', 'outlets.name')->orderByDesc('revenue')->get();

        // Compare vs previous period
        $prevStart = Carbon::parse($p['start'])->subDays(
            Carbon::parse($p['start'])->diffInDays(Carbon::parse($p['end'])) + 1
        )->format('Y-m-d');
        $prevEnd   = Carbon::parse($p['start'])->subDay()->format('Y-m-d');

        $prev = $this->salesBase($p, $prevStart, $prevEnd)
            ->selectRaw("COUNT(*) AS orders, SUM(orders.total_amount) AS revenue")
            ->first();

        $result = [
            'period'            => ['start' => $request->get('start_date', now()->startOfMonth()->format('Y-m-d')), 'end' => $request->get('end_date', now()->endOfMonth()->format('Y-m-d'))],
            'currency'          => $p['currency'] ?? 'KES',
            'summary'           => $summary,
            'daily_breakdown'   => $daily,
            'by_payment_method' => $byPayment,
            'by_outlet'         => $byOutlet,
            'previous_period'   => ['start' => $prevStart, 'end' => $prevEnd, 'data' => $prev],
        ];

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('sales_summary', $daily->toArray(), ['date', 'orders', 'revenue', 'avg_value']);
        }

        return response()->json($result);
    }

    /**
     * GET /api/v1/admin/reports/sales/by-product
     */
    public function salesByProduct(Request $request)
    {
        $p     = $this->params($request, ['outlet_id', 'category_id', 'currency']);
        $limit = min((int) $request->get('limit', 50), 200);

        $rows = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('product_translations', function($join) {
                $join->on('product_translations.product_id', '=', 'products.id')
                     ->where('product_translations.language_code', '=', 'en');
            })
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->when(isset($p['outlet_id']),   fn($q) => $q->where('orders.outlet_id',    $p['outlet_id']))
            ->when(isset($p['category_id']), fn($q) => $q->where('products.category_id', $p['category_id']))
            ->selectRaw("
                products.id,
                COALESCE(product_translations.name, products.sku) AS product_name,
                products.sku,
                categories.name_en       AS category_name,
                SUM(order_items.quantity)               AS units_sold,
                SUM(order_items.total_price)            AS revenue,
                AVG(order_items.unit_price)             AS avg_price,
                COUNT(DISTINCT orders.id)               AS order_count,
                SUM(order_items.discount_amount)        AS total_discounts
            ")
            ->groupBy('products.id', 'product_translations.name', 'products.sku', 'categories.name_en')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('sales_by_product', $rows->toArray(),
                ['product_name', 'sku', 'category_name', 'units_sold', 'revenue', 'avg_price', 'order_count']);
        }

        return response()->json(['period' => $p, 'products' => $rows]);
    }

    /**
     * GET /api/v1/admin/reports/sales/by-category
     */
    public function salesByCategory(Request $request)
    {
        $p = $this->params($request);

        $rows = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->when(isset($p['outlet_id']), fn($q) => $q->where('orders.outlet_id', $p['outlet_id']))
            ->selectRaw("
                categories.id,
                categories.name_en AS category_name,
                SUM(order_items.quantity) AS units_sold,
                SUM(order_items.total_price) AS revenue,
                COUNT(DISTINCT orders.id) AS order_count,
                COUNT(DISTINCT products.id) AS product_count
            ")
            ->groupBy('categories.id', 'categories.name_en')
            ->orderByDesc('revenue')
            ->get();

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('sales_by_category', $rows->toArray(),
                ['category_name', 'units_sold', 'revenue', 'order_count', 'product_count']);
        }

        return response()->json(['period' => $p, 'categories' => $rows]);
    }

    /**
     * GET /api/v1/admin/reports/sales/by-customer
     *
     * Orders carry customer_first_name / customer_last_name / customer_email directly.
     * The customers table has no user_id FK on orders - joining it would drop all
     * guest / walk-in orders. We group on the denormalised order columns instead.
     */
    public function salesByCustomer(Request $request)
    {
        $p     = $this->params($request);
        $limit = min((int) $request->get('limit', 50), 200);

        $rows = DB::table('orders')
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->whereNotNull('orders.customer_email')
            ->selectRaw("
                orders.customer_first_name,
                orders.customer_last_name,
                orders.customer_email,
                COUNT(orders.id)           AS order_count,
                SUM(orders.total_amount)   AS total_spent,
                AVG(orders.total_amount)   AS avg_order_value,
                MAX(orders.created_at)     AS last_order_date,
                MIN(orders.created_at)     AS first_order_date
            ")
            ->groupBy('orders.customer_email', 'orders.customer_first_name', 'orders.customer_last_name')
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get();

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('sales_by_customer', $rows->toArray(),
                ['customer_first_name', 'customer_last_name', 'customer_email', 'order_count', 'total_spent', 'avg_order_value']);
        }

        return response()->json(['period' => $p, 'customers' => $rows]);
    }

    /**
     * GET /api/v1/admin/reports/sales/by-outlet
     */
    public function salesByOutlet(Request $request)
    {
        $p = $this->params($request);

        $rows = DB::table('orders')
            ->join('outlets', 'orders.outlet_id', '=', 'outlets.id')
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->selectRaw("
                outlets.id,
                outlets.name AS outlet_name,
                COUNT(*) AS orders,
                SUM(orders.total_amount) AS revenue,
                AVG(orders.total_amount) AS avg_order_value,
                SUM(orders.discount_amount) AS total_discounts,
                SUM(orders.tax_amount) AS total_tax
            ")
            ->groupBy('outlets.id', 'outlets.name')
            ->orderByDesc('revenue')
            ->get();

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('sales_by_outlet', $rows->toArray(),
                ['outlet_name', 'orders', 'revenue', 'avg_order_value', 'total_discounts']);
        }

        return response()->json(['period' => $p, 'outlets' => $rows]);
    }

    /**
     * GET /api/v1/admin/reports/sales/payment-methods
     *
     * Payment.status values in use: 'pending', 'paid', 'failed', 'refunded'.
     */
    public function paymentMethodSummary(Request $request)
    {
        $p = $this->params($request, ['outlet_id']);

        $rows = DB::table('payments')
            ->join('orders', 'payments.order_id', '=', 'orders.id')
            ->whereBetween('payments.created_at', [$p['start'], $p['end']])
            ->where('payments.status', 'paid')
            ->when(isset($p['outlet_id']), fn($q) => $q->where('orders.outlet_id', $p['outlet_id']))
            ->selectRaw("
                payments.payment_method,
                COUNT(*) AS transaction_count,
                SUM(payments.amount) AS total_amount,
                AVG(payments.amount) AS avg_amount
            ")
            ->groupBy('payments.payment_method')
            ->orderByDesc('total_amount')
            ->get();

        return response()->json(['period' => $p, 'payment_methods' => $rows]);
    }

    // =========================================================================
    // CUSTOMER REPORTS
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/customers/overview
     */
    public function customersOverview(Request $request)
    {
        $p = $this->params($request);

        // customers table has its own created_at - count all + new in period
        $summary = DB::table('customers')
            ->selectRaw("
                COUNT(*) AS total_customers,
                COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) AS new_customers
            ", [$p['start'], $p['end']])
            ->first();

        // Unique buyers (by email) and order count in period
        $orderStats = DB::table('orders')
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->whereNotNull('orders.customer_email')
            ->selectRaw("
                COUNT(DISTINCT customer_email) AS unique_buyers,
                COUNT(*) AS total_orders
            ")
            ->first();

        // Top 20 customers by lifetime value - grouped on order columns (no bad join)
        $topCustomers = DB::table('orders')
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->whereNotNull('orders.customer_email')
            ->selectRaw("
                orders.customer_first_name,
                orders.customer_last_name,
                orders.customer_email,
                COUNT(*) AS total_orders,
                SUM(orders.total_amount) AS lifetime_value,
                MAX(orders.created_at) AS last_order
            ")
            ->groupBy('orders.customer_email', 'orders.customer_first_name', 'orders.customer_last_name')
            ->orderByDesc('lifetime_value')
            ->limit(20)
            ->get();

        // New customers trend (last 12 months)
        $newCustomersTrend = DB::table('customers')
            ->where('customers.created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("TO_CHAR(customers.created_at, 'YYYY-MM') AS month, COUNT(*) AS count")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'period'               => $p,
            'summary'              => $summary,
            'order_stats'          => $orderStats,
            'top_customers'        => $topCustomers,
            'new_customers_trend'  => $newCustomersTrend,
        ]);
    }

    // =========================================================================
    // INVENTORY REPORTS
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/inventory/stock-on-hand
     *
     * Uses inventory_items (InventoryItem model):
     *   quantity_on_hand, quantity_reserved, reorder_point
     * Available quantity = quantity_on_hand - quantity_reserved
     */
    public function stockOnHand(Request $request)
    {
        $p = $this->params($request, ['outlet_id', 'category_id', 'low_stock_only']);

        $rows = DB::table('inventory_items')
            ->join('product_variants', 'inventory_items.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('outlets', 'inventory_items.outlet_id', '=', 'outlets.id')
            ->leftJoin('product_translations', function($join) {
                $join->on('product_translations.product_id', '=', 'products.id')
                     ->where('product_translations.language_code', '=', 'en');
            })
            // Join the first active price per variant for retail value display
            ->leftJoin('product_prices', function($join) {
                $join->on('product_prices.product_variant_id', '=', 'product_variants.id')
                     ->where('product_prices.currency_code', '=', 'KES');
            })
            ->when(isset($p['outlet_id']),   fn($q) => $q->where('inventory_items.outlet_id', $p['outlet_id']))
            ->when(isset($p['category_id']), fn($q) => $q->where('products.category_id', $p['category_id']))
            ->when($p['low_stock_only'] ?? false, fn($q) => $q->whereRaw(
                '(inventory_items.quantity_on_hand - inventory_items.quantity_reserved) > 0
                 AND (inventory_items.quantity_on_hand - inventory_items.quantity_reserved) <= inventory_items.reorder_point'
            ))
            ->selectRaw("
                products.id AS product_id,
                COALESCE(product_translations.name, products.sku) AS product_name,
                products.sku AS product_sku,
                product_variants.id AS variant_id,
                product_variants.sku AS variant_sku,
                product_variants.variant_name,
                categories.name_en AS category_name,
                outlets.name AS outlet_name,
                (inventory_items.quantity_on_hand - inventory_items.quantity_reserved) AS quantity,
                inventory_items.reorder_point AS low_stock_threshold,
                COALESCE(product_prices.regular_price, 0) AS retail_price,
                CASE WHEN (inventory_items.quantity_on_hand - inventory_items.quantity_reserved) <= 0 THEN 'out_of_stock'
                     WHEN (inventory_items.quantity_on_hand - inventory_items.quantity_reserved) <= inventory_items.reorder_point THEN 'low_stock'
                     ELSE 'in_stock' END AS stock_status
            ")
            ->orderByRaw('COALESCE(product_translations.name, products.sku)')
            ->get();

        $totals = [
            'total_items'        => $rows->count(),
            'out_of_stock_count' => $rows->where('stock_status', 'out_of_stock')->count(),
            'low_stock_count'    => $rows->where('stock_status', 'low_stock')->count(),
        ];

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('stock_on_hand', $rows->toArray(),
                ['product_name', 'variant_sku', 'variant_name', 'category_name', 'outlet_name',
                 'quantity', 'low_stock_threshold', 'retail_price', 'stock_status']);
        }

        return response()->json(['period' => $p, 'items' => $rows, 'totals' => $totals]);
    }

    /**
     * GET /api/v1/admin/reports/inventory/stock-movement
     *
     * InventoryTransaction: inventory_item_id, transaction_type, quantity_change,
     *   quantity_before, quantity_after, reference_type, reference_id, notes, created_by
     */
    public function stockMovement(Request $request)
    {
        $p = $this->params($request, ['product_id', 'outlet_id', 'transaction_type']);

        $rows = DB::table('inventory_transactions')
            ->join('inventory_items', 'inventory_transactions.inventory_item_id', '=', 'inventory_items.id')
            ->join('product_variants', 'inventory_items.product_variant_id', '=', 'product_variants.id')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('users', 'inventory_transactions.created_by', '=', 'users.id')
            ->leftJoin('product_translations', function($join) {
                $join->on('product_translations.product_id', '=', 'products.id')
                     ->where('product_translations.language_code', '=', 'en');
            })
            ->whereBetween('inventory_transactions.created_at', [$p['start'], $p['end']])
            ->when(isset($p['product_id']),       fn($q) => $q->where('products.id',                             $p['product_id']))
            ->when(isset($p['outlet_id']),         fn($q) => $q->where('inventory_items.outlet_id',              $p['outlet_id']))
            ->when(isset($p['transaction_type']), fn($q) => $q->where('inventory_transactions.transaction_type', $p['transaction_type']))
            ->selectRaw("
                inventory_transactions.id,
                inventory_transactions.created_at,
                COALESCE(product_translations.name, products.sku) AS product_name,
                product_variants.sku AS variant_sku,
                inventory_transactions.transaction_type AS type,
                inventory_transactions.quantity_change,
                inventory_transactions.quantity_before,
                inventory_transactions.quantity_after,
                inventory_transactions.reference_type,
                inventory_transactions.reference_id,
                inventory_transactions.notes,
                CONCAT(users.first_name, ' ', users.last_name) AS created_by
            ")
            ->orderByDesc('inventory_transactions.created_at')
            ->paginate(50);

        return response()->json(['period' => $p, 'transactions' => $rows]);
    }

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
    // PROCUREMENT REPORTS
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/procurement/purchase-orders
     */
    public function purchaseOrderReport(Request $request)
    {
        $p = $this->params($request, ['supplier_id', 'status']);

        $rows = DB::table('purchase_orders')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->leftJoin('users', 'purchase_orders.created_by', '=', 'users.id')
            ->whereBetween('purchase_orders.created_at', [$p['start'], $p['end']])
            ->when(isset($p['supplier_id']), fn($q) => $q->where('purchase_orders.supplier_id', $p['supplier_id']))
            ->when(isset($p['status']),      fn($q) => $q->where('purchase_orders.status',      $p['status']))
            ->selectRaw("
                purchase_orders.id,
                purchase_orders.po_number,
                purchase_orders.created_at,
                purchase_orders.expected_delivery_date,
                purchase_orders.status,
                purchase_orders.total_amount,
                purchase_orders.currency_code AS currency,
                suppliers.name AS supplier_name,
                CONCAT(users.first_name, ' ', users.last_name) AS created_by
            ")
            ->orderByDesc('purchase_orders.created_at')
            ->get();

        $summary = [
            'total_orders' => $rows->count(),
            'total_value'  => $rows->sum('total_amount'),
            'by_status'    => $rows->groupBy('status')->map->count(),
        ];

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('purchase_orders', $rows->toArray(),
                ['po_number', 'created_at', 'supplier_name', 'status', 'total_amount', 'currency', 'expected_delivery_date']);
        }

        return response()->json(['period' => $p, 'purchase_orders' => $rows, 'summary' => $summary]);
    }

    /**
     * GET /api/v1/admin/reports/procurement/by-supplier
     */
    public function spendBySupplier(Request $request)
    {
        $p = $this->params($request);

        $rows = DB::table('purchase_orders')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->whereBetween('purchase_orders.created_at', [$p['start'], $p['end']])
            ->selectRaw("
                suppliers.id,
                suppliers.name AS supplier_name,
                suppliers.email,
                COUNT(*) AS po_count,
                SUM(purchase_orders.total_amount) AS total_spend,
                AVG(purchase_orders.total_amount) AS avg_po_value
            ")
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.email')
            ->orderByDesc('total_spend')
            ->get();

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('spend_by_supplier', $rows->toArray(),
                ['supplier_name', 'po_count', 'total_spend', 'avg_po_value']);
        }

        return response()->json(['period' => $p, 'suppliers' => $rows]);
    }

    // =========================================================================
    // PRODUCTION REPORTS
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/production/summary
     *
     * ProductionOrder statuses: draft | pending | in_progress | qc_pending |
     *   completed | qc_failed | cancelled | on_hold
     */
    public function productionSummary(Request $request)
    {
        $p = $this->params($request, ['status', 'outlet_id']);

        $summary = DB::table('production_orders')
            ->whereBetween('production_orders.created_at', [$p['start'], $p['end']])
            ->when(isset($p['outlet_id']), fn($q) => $q->where('production_orders.outlet_id', $p['outlet_id']))
            ->selectRaw("
                COUNT(*) AS total_orders,
                SUM(quantity) AS total_units_planned,
                COUNT(CASE WHEN status = 'completed'   THEN 1 END) AS completed_count,
                COUNT(CASE WHEN status = 'in_progress' THEN 1 END) AS in_progress_count,
                COUNT(CASE WHEN status = 'pending'     THEN 1 END) AS pending_count,
                COUNT(CASE WHEN status = 'qc_pending'  THEN 1 END) AS qc_pending_count,
                COUNT(CASE WHEN status = 'qc_failed'   THEN 1 END) AS qc_failed_count,
                COUNT(CASE WHEN status = 'cancelled'   THEN 1 END) AS cancelled_count,
                AVG(CASE WHEN completed_at IS NOT NULL AND started_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (completed_at - started_at)) / 3600 END) AS avg_completion_hours
            ")->first();

        $byProduct = DB::table('production_orders')
            ->join('products', 'production_orders.product_id', '=', 'products.id')
            ->leftJoin('product_translations', function($join) {
                $join->on('product_translations.product_id', '=', 'products.id')
                     ->where('product_translations.language_code', '=', 'en');
            })
            ->whereBetween('production_orders.created_at', [$p['start'], $p['end']])
            ->selectRaw("
                COALESCE(product_translations.name, products.sku) AS product_name,
                COUNT(*) AS order_count,
                SUM(production_orders.quantity) AS units_planned,
                COUNT(CASE WHEN production_orders.status='completed' THEN 1 END) AS completed
            ")
            ->groupBy('products.id', 'product_translations.name', 'products.sku')
            ->orderByDesc('order_count')
            ->get();

        // On-time delivery rate
        $timeliness = DB::table('production_orders')
            ->whereBetween('production_orders.created_at', [$p['start'], $p['end']])
            ->where('production_orders.status', 'completed')
            ->whereNotNull('production_orders.due_date')
            ->selectRaw("
                COUNT(*) AS total_completed,
                COUNT(CASE WHEN completed_at <= due_date THEN 1 END) AS on_time
            ")->first();

        return response()->json([
            'period'     => $p,
            'summary'    => $summary,
            'by_product' => $byProduct,
            'timeliness' => $timeliness,
        ]);
    }

    /**
     * GET /api/v1/admin/reports/production/tailor-productivity
     */
    public function tailorProductivity(Request $request)
    {
        $p = $this->params($request);

        $rows = DB::table('production_order_assignees')
            ->join('production_orders', 'production_order_assignees.production_order_id', '=', 'production_orders.id')
            ->join('users', 'production_order_assignees.user_id', '=', 'users.id')
            ->whereBetween('production_orders.completed_at', [$p['start'], $p['end']])
            ->where('production_orders.status', 'completed')
            ->selectRaw("
                users.id,
                CONCAT(users.first_name, ' ', users.last_name) AS tailor_name,
                COUNT(DISTINCT production_orders.id) AS completed_orders,
                SUM(production_orders.quantity) AS units_produced,
                AVG(EXTRACT(EPOCH FROM (production_orders.completed_at - production_orders.started_at)) / 3600) AS avg_hours_per_order
            ")
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('completed_orders')
            ->get();

        if ($request->get('export') === 'csv') {
            return $this->csvResponse('tailor_productivity', $rows->toArray(),
                ['tailor_name', 'completed_orders', 'units_produced', 'avg_hours_per_order']);
        }

        return response()->json(['period' => $p, 'tailors' => $rows]);
    }

    // =========================================================================
    // FINANCIAL REPORTS
    // =========================================================================

    /**
     * GET /api/v1/admin/reports/financial/profit-loss
     * Full P&L statement: Revenue → Gross Profit → Operating Expenses → Net Profit
     *
     * COGS: inventory_items has no cost_price. We use the unit_cost stored on
     * inventory_transactions (type='sale') as the best available proxy for COGS.
     */
    public function profitLoss(Request $request)
    {
        $p    = $this->params($request, ['outlet_id', 'currency']);
        $curr = $p['currency'] ?? 'KES';

        // ── Revenue ──────────────────────────────────────────────────────────

        $revenue = DB::table('orders')
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->when(isset($p['outlet_id']), fn($q) => $q->where('orders.outlet_id', $p['outlet_id']))
            ->selectRaw("
                SUM(subtotal)                              AS gross_sales,
                SUM(discount_amount)                       AS discounts,
                SUM(subtotal - discount_amount)            AS net_sales,
                SUM(shipping_amount)                       AS shipping_revenue,
                SUM(tax_amount)                            AS tax_collected,
                SUM(total_amount)                          AS total_revenue
            ")->first();

        // ── Cost of Goods Sold via sale transactions ──────────────────────────
        // inventory_transactions records unit_cost at time of sale (type='sale').
        // quantity_change is negative for sales; multiply by -1 to get positive units.

        $cogsRow = DB::table('inventory_transactions')
            ->join('inventory_items', 'inventory_transactions.inventory_item_id', '=', 'inventory_items.id')
            ->join('orders', function($join) {
                $join->on('inventory_transactions.reference_id', '=', 'orders.id')
                     ->where('inventory_transactions.reference_type', '=', 'App\Models\Order');
            })
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->where('inventory_transactions.transaction_type', 'sale')
            ->when(isset($p['outlet_id']), fn($q) => $q->where('inventory_items.outlet_id', $p['outlet_id']))
            ->selectRaw("
                SUM(ABS(inventory_transactions.quantity_change) * COALESCE(inventory_transactions.unit_cost, 0)) AS cogs
            ")->first();

        $cogs = (float) ($cogsRow->cogs ?? 0);

        // ── Operating Expenses ────────────────────────────────────────────────

        $expenseRows = Expense::whereIn('status', ['approved', 'paid'])
            ->whereBetween('expense_date', [$p['start'], $p['end']])
            ->when(isset($p['outlet_id']), fn($q) => $q->where('outlet_id', $p['outlet_id']))
            ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->selectRaw("
                expense_categories.name AS category,
                expense_categories.code,
                SUM(expenses.amount_kes) AS amount
            ")
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.code')
            ->orderByDesc('amount')
            ->get();

        $totalExpenses = $expenseRows->sum('amount');

        // ── P&L Calculations ─────────────────────────────────────────────────

        $netSales    = (float) ($revenue->net_sales    ?? 0);
        $grossProfit = $netSales - $cogs;
        $grossMargin = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0;
        $ebitda      = $grossProfit - $totalExpenses;
        $netMargin   = $netSales > 0 ? round(($ebitda / $netSales) * 100, 2) : 0;

        // Monthly breakdown for charting
        $monthlyRevenue = DB::table('orders')
            ->whereBetween('orders.created_at', [$p['start'], $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->selectRaw("TO_CHAR(orders.created_at, 'YYYY-MM') AS month, SUM(orders.total_amount) AS revenue")
            ->groupBy('month')->orderBy('month')->get();

        $monthlyExpenses = Expense::whereIn('status', ['approved', 'paid'])
            ->whereBetween('expense_date', [$p['start'], $p['end']])
            ->selectRaw("TO_CHAR(expense_date, 'YYYY-MM') AS month, SUM(amount_kes) AS expenses")
            ->groupBy('month')->orderBy('month')->get();

        $result = [
            'period'           => $p,
            'currency'         => $curr,
            'revenue'          => $revenue,
            'cogs'             => round($cogs, 2),
            'gross_profit'     => round($grossProfit, 2),
            'gross_margin'     => $grossMargin,
            'expenses'         => $expenseRows,
            'total_expenses'   => round($totalExpenses, 2),
            'ebitda'           => round($ebitda, 2),
            'net_margin'       => $netMargin,
            'monthly_revenue'  => $monthlyRevenue,
            'monthly_expenses' => $monthlyExpenses,
        ];

        if ($request->get('export') === 'csv') {
            $flat = [
                ['item' => 'Gross Sales',        'amount' => $revenue->gross_sales],
                ['item' => 'Discounts',           'amount' => -$revenue->discounts],
                ['item' => 'Net Sales',           'amount' => $revenue->net_sales],
                ['item' => 'Cost of Goods Sold',  'amount' => -$cogs],
                ['item' => 'Gross Profit',        'amount' => $grossProfit],
                ...$expenseRows->map(fn($e) => ['item' => 'Expense: ' . $e->category, 'amount' => -$e->amount])->toArray(),
                ['item' => 'Total Expenses',      'amount' => -$totalExpenses],
                ['item' => 'Net Profit (EBITDA)', 'amount' => $ebitda],
            ];
            return $this->csvResponse('profit_loss', $flat, ['item', 'amount']);
        }

        return response()->json($result);
    }

    /**
     * GET /api/v1/admin/reports/financial/expenses-report
     */
    public function expensesReport(Request $request)
    {
        $p = $this->params($request, ['category_id', 'outlet_id', 'status']);

        $rows = DB::table('expenses')
            ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->leftJoin('outlets', 'expenses.outlet_id', '=', 'outlets.id')
            ->leftJoin('users', 'expenses.created_by', '=', 'users.id')
            ->whereBetween('expenses.expense_date', [$p['start'], $p['end']])
            ->when(isset($p['category_id']), fn($q) => $q->where('expenses.category_id', $p['category_id']))
            ->when(isset($p['outlet_id']),   fn($q) => $q->where('expenses.outlet_id',   $p['outlet_id']))
            ->when(isset($p['status']),      fn($q) => $q->where('expenses.status',       $p['status']))
            ->selectRaw("
                expenses.reference_number,
                expenses.expense_date,
                expenses.title,
                expense_categories.name AS category,
                expenses.vendor_name,
                expenses.amount,
                expenses.currency_code,
                expenses.amount_kes,
                expenses.payment_method,
                expenses.status,
                outlets.name AS outlet_name,
                CONCAT(users.first_name, ' ', users.last_name) AS created_by
            ")
            ->orderByDesc('expenses.expense_date')
            ->paginate(50);

        if ($request->get('export') === 'csv') {
            $allRows = DB::table('expenses')
                ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
                ->leftJoin('outlets', 'expenses.outlet_id', '=', 'outlets.id')
                ->leftJoin('users', 'expenses.created_by', '=', 'users.id')
                ->whereBetween('expenses.expense_date', [$p['start'], $p['end']])
                ->when(isset($p['category_id']), fn($q) => $q->where('expenses.category_id', $p['category_id']))
                ->when(isset($p['status']),      fn($q) => $q->where('expenses.status',       $p['status']))
                ->selectRaw("
                    expenses.reference_number, expenses.expense_date, expenses.title,
                    expense_categories.name AS category, expenses.vendor_name,
                    expenses.amount, expenses.currency_code, expenses.amount_kes,
                    expenses.payment_method, expenses.status, outlets.name AS outlet_name
                ")
                ->orderByDesc('expenses.expense_date')
                ->get();

            return $this->csvResponse('expenses_report', $allRows->toArray(),
                ['reference_number', 'expense_date', 'title', 'category', 'vendor_name',
                 'amount', 'currency_code', 'amount_kes', 'payment_method', 'status', 'outlet_name']);
        }

        return response()->json(['period' => $p, 'expenses' => $rows]);
    }

    /**
     * GET /api/v1/admin/reports/financial/tax-report
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
                SUM(order_items.total_price) AS taxable_amount,
                SUM(order_items.tax_amount)  AS tax_collected
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
     */
    public function cashFlow(Request $request)
    {
        $p = $this->params($request);

        // Inflows: completed payments grouped by month
        $inflows = DB::table('payments')
            ->whereBetween('created_at', [$p['start'], $p['end']])
            ->where('status', 'paid')
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') AS month, SUM(amount) AS inflow, payment_method")
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
    // DASHBOARD KPIs
    // =========================================================================

    public function dashboardKpis(Request $request)
    {
        $days  = max(1, min((int) $request->get('days', 30), 365));
        // Use startOfDay so the window aligns with salesSummary's midnight boundaries.
        // Without this, now()->subDays(N) preserves wall-clock time and excludes
        // orders from early in the day N days ago.
        $since = now()->subDays($days)->startOfDay();

        $kpis = [
            'sales' => [
                'revenue'   => Order::whereIn('payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('status', ['cancelled', 'refunded', 'voided'])->where('created_at', '>=', $since)->sum('total_amount'),
                'orders'    => Order::whereIn('payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('status', ['cancelled', 'refunded', 'voided'])->where('created_at', '>=', $since)->count(),
                'avg_value' => Order::whereIn('payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('status', ['cancelled', 'refunded', 'voided'])->where('created_at', '>=', $since)->avg('total_amount'),
                'refunds'   => Order::where('status', 'refunded')->where('created_at', '>=', $since)->sum('total_amount'),
            ],
            'customers' => [
                'total' => Customer::count(),
                'new'   => Customer::where('created_at', '>=', $since)->count(),
            ],
            'inventory' => [
                // Available qty = on_hand - reserved; compare against reorder_point
                'low_stock'    => DB::table('inventory_items')
                    ->whereRaw('(quantity_on_hand - quantity_reserved) > 0 AND (quantity_on_hand - quantity_reserved) <= reorder_point')
                    ->count(),
                'out_of_stock' => DB::table('inventory_items')
                    ->whereRaw('(quantity_on_hand - quantity_reserved) <= 0')
                    ->count(),
            ],
            'production' => [
                // Active = orders currently being worked on (not yet complete or cancelled)
                'active'    => DB::table('production_orders')
                    ->whereIn('status', ['pending', 'in_progress', 'qc_pending', 'on_hold'])
                    ->count(),
                'overdue'   => DB::table('production_orders')
                    ->whereIn('status', ['pending', 'in_progress', 'qc_pending', 'on_hold'])
                    ->whereNotNull('due_date')->where('due_date', '<', now())
                    ->count(),
                'completed' => DB::table('production_orders')
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', $since)
                    ->count(),
            ],
            'expenses' => [
                'pending_approval' => Expense::where('status', 'pending_approval')->count(),
                'this_period'      => Expense::whereIn('status', ['approved', 'paid'])
                    ->where('expense_date', '>=', $since)->sum('amount_kes'),
            ],
            'procurement' => [
                'open_pos' => DB::table('purchase_orders')
                    ->whereIn('status', ['pending', 'approved', 'ordered'])
                    ->count(),
            ],
        ];

        // Revenue trend — daily for the requested window.
        // Reuses $since so the trend window is identical to the KPI figures above.
        $revenueTrend = Order::whereIn('payment_status', ['paid', 'partial', 'deposit'])->whereNotIn('status', ['cancelled', 'refunded', 'voided'])
            ->where('created_at', '>=', $since)
            ->selectRaw("DATE(created_at) AS date, SUM(total_amount) AS revenue, COUNT(*) AS orders")
            ->groupBy('date')->orderBy('date')->get();

        return response()->json(['period_days' => $days, 'kpis' => $kpis, 'revenue_trend' => $revenueTrend]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

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
     * Base Eloquent query for all sales reports.
     *
     * payment_status values that represent received money:
     *   paid     - fully settled
     *   partial  - partial payment received
     *   deposit  - deposit paid, balance outstanding
     *
     * NOTE: no table-prefix on columns - Eloquent model builder does not
     * need it without a join; the prefix causes issues in some contexts.
     */
    private function salesBase(array $p, ?string $start = null, ?string $end = null)
    {
        // All columns are table-qualified so this base query can be safely
        // cloned and extended with join() calls without causing ambiguity.
        return Order::whereBetween('orders.created_at', [$start ?? $p['start'], $end ?? $p['end']])
            ->whereIn('orders.payment_status', ['paid', 'partial', 'deposit'])
            ->whereNotIn('orders.status', ['cancelled', 'refunded', 'voided'])
            ->when(isset($p['outlet_id']), fn($q) => $q->where('orders.outlet_id',     $p['outlet_id']))
            ->when(isset($p['channel']),   fn($q) => $q->where('orders.order_type',    $p['channel']))
            ->when(isset($p['currency']),  fn($q) => $q->where('orders.currency_code', $p['currency']));
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