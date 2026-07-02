<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GlobalSearchController
 *
 * Powers the CommandPalette live search.
 *
 * Route:
 *   GET /api/v1/admin/search?q={query}&types[]={type}
 *
 * Supported types: products, orders, customers, suppliers, purchase_orders
 * Returns up to 5 results per type, max 25 total.
 *
 * Response shape:
 *   { results: SearchResult[] }
 *
 * SearchResult: { id, type, title, subtitle?, href, meta? }
 */
class GlobalSearchController extends Controller
{
    private const MAX_PER_TYPE = 5;

    public function search(Request $request): JsonResponse
    {
        $q     = trim($request->get('q', ''));
        $types = $request->get('types', ['products', 'orders', 'customers', 'suppliers', 'purchase_orders']);

        // Normalise — frontend sends types[] as an array
        if (is_string($types)) {
            $types = [$types];
        }

        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = [];

        if (in_array('products', $types)) {
            $results = array_merge($results, $this->searchProducts($q));
        }
        if (in_array('orders', $types)) {
            $results = array_merge($results, $this->searchOrders($q));
        }
        if (in_array('customers', $types)) {
            $results = array_merge($results, $this->searchCustomers($q));
        }
        if (in_array('suppliers', $types)) {
            $results = array_merge($results, $this->searchSuppliers($q));
        }
        if (in_array('purchase_orders', $types)) {
            $results = array_merge($results, $this->searchPurchaseOrders($q));
        }

        return response()->json(['results' => $results]);
    }

    // ── Products ──────────────────────────────────────────────────────────────

    private function searchProducts(string $q): array
    {
        $rows = Product::with([
                'translations' => fn ($tq) => $tq->where('language_code', 'en')->select('product_id', 'name'),
            ])
            ->where(function ($w) use ($q) {
                $w->whereHas('translations', fn ($tq) => $tq->where('name', 'ILIKE', "%{$q}%"))
                  ->orWhere('sku', 'ILIKE', "%{$q}%");
            })
            ->select('id', 'sku', 'status', 'product_type')
            ->limit(self::MAX_PER_TYPE)
            ->get();

        return $rows->map(fn ($p) => [
            'id'       => $p->id,
            'type'     => 'product',
            'title'    => $p->translations->first()?->name ?? $p->sku,
            'subtitle' => $p->sku,
            'href'     => "/catalogue/products/{$p->id}",
            'meta'     => $p->status,
        ])->values()->toArray();
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    private function searchOrders(string $q): array
    {
        $rows = Order::where(function ($w) use ($q) {
                $w->where('order_number',       'ILIKE', "%{$q}%")
                  ->orWhere('customer_email',   'ILIKE', "%{$q}%")
                  ->orWhere('customer_first_name', 'ILIKE', "%{$q}%")
                  ->orWhere('customer_last_name',  'ILIKE', "%{$q}%")
                  ->orWhere(\DB::raw("CONCAT(customer_first_name, ' ', customer_last_name)"), 'ILIKE', "%{$q}%");
            })
            ->select('id', 'order_number', 'status', 'total_amount', 'currency_code',
                     'customer_first_name', 'customer_last_name', 'customer_email')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PER_TYPE)
            ->get();

        return $rows->map(fn ($o) => [
            'id'       => $o->id,
            'type'     => 'order',
            'title'    => $o->order_number,
            'subtitle' => trim("{$o->customer_first_name} {$o->customer_last_name}") ?: $o->customer_email,
            'href'     => "/sales/orders/{$o->id}",
            'meta'     => $o->currency_code . ' ' . number_format($o->total_amount, 2),
        ])->values()->toArray();
    }

    // ── Customers ─────────────────────────────────────────────────────────────

    private function searchCustomers(string $q): array
    {
        $rows = Customer::where(function ($w) use ($q) {
                $w->where('first_name',  'ILIKE', "%{$q}%")
                  ->orWhere('last_name',  'ILIKE', "%{$q}%")
                  ->orWhere('email',      'ILIKE', "%{$q}%")
                  ->orWhere('phone',      'ILIKE', "%{$q}%")
                  ->orWhere('company',    'ILIKE', "%{$q}%")
                  ->orWhere(\DB::raw("CONCAT(first_name, ' ', last_name)"), 'ILIKE', "%{$q}%");
            })
            ->select('id', 'first_name', 'last_name', 'email', 'phone', 'status')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PER_TYPE)
            ->get();

        return $rows->map(fn ($c) => [
            'id'       => $c->id,
            'type'     => 'customer',
            'title'    => trim("{$c->first_name} {$c->last_name}") ?: $c->email,
            'subtitle' => $c->email,
            'href'     => "/sales/customers/{$c->id}",
            'meta'     => $c->phone,
        ])->values()->toArray();
    }

    // ── Suppliers ─────────────────────────────────────────────────────────────

    private function searchSuppliers(string $q): array
    {
        $rows = Supplier::where(function ($w) use ($q) {
                $w->where('name',           'ILIKE', "%{$q}%")
                  ->orWhere('company_code', 'ILIKE', "%{$q}%")
                  ->orWhere('email',        'ILIKE', "%{$q}%")
                  ->orWhere('contact_person', 'ILIKE', "%{$q}%");
            })
            ->select('id', 'name', 'company_code', 'email', 'status', 'type')
            ->limit(self::MAX_PER_TYPE)
            ->get();

        return $rows->map(fn ($s) => [
            'id'       => $s->id,
            'type'     => 'supplier',
            'title'    => $s->name,
            'subtitle' => $s->company_code,
            'href'     => "/procurement/suppliers/{$s->id}",
            'meta'     => $s->status,
        ])->values()->toArray();
    }

    // ── Purchase orders ───────────────────────────────────────────────────────

    private function searchPurchaseOrders(string $q): array
    {
        $rows = PurchaseOrder::with([
                'supplier' => fn ($sq) => $sq->select('id', 'name'),
            ])
            ->where(function ($w) use ($q) {
                $w->where('po_number', 'ILIKE', "%{$q}%")
                  ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'ILIKE', "%{$q}%"));
            })
            ->select('id', 'po_number', 'status', 'total_amount', 'currency_code', 'supplier_id')
            ->orderByDesc('created_at')
            ->limit(self::MAX_PER_TYPE)
            ->get();

        return $rows->map(fn ($po) => [
            'id'       => $po->id,
            'type'     => 'purchase_order',
            'title'    => $po->po_number,
            'subtitle' => $po->supplier?->name,
            'href'     => "/procurement/purchase-orders/{$po->id}",
            'meta'     => $po->currency_code . ' ' . number_format($po->total_amount, 2),
        ])->values()->toArray();
    }
}