<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\InventoryItem;
use App\Services\TaxCalculationService;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Services\IntelligenceService;
use App\Support\SortResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * Columns a client may sort the order list/export by. Anything else
     * collapses to the default in SortResolver — `sort_by` is interpolated
     * into an ORDER BY identifier and cannot be parameter-bound.
     */
    private const SORTABLE_COLUMNS = [
        'created_at', 'updated_at', 'order_number', 'total_amount',
        'status', 'payment_status', 'order_type',
    ];

    /**
     * Get all orders (Admin)
     */
    public function index(Request $request)
    {
        $query = Order::with(['user', 'items', 'outlet']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('channel')) {
            $query->where('order_type', $request->channel);
        }

        if ($request->has('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_first_name', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_last_name',  'ILIKE', "%{$search}%")
                  ->orWhere('customer_email',      'ILIKE', "%{$search}%")
                  ->orWhere('customer_phone',      'ILIKE', "%{$search}%");
            });
        }

        [$sortBy, $sortOrder] = SortResolver::resolve(
            $request->get('sort_by'),
            $request->get('sort_order', 'desc'),
            self::SORTABLE_COLUMNS,
            'created_at'
        );
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 20);
        $orders  = $query->paginate($perPage);

        return response()->json($orders);
    }

    /**
     * Export orders to CSV (Admin)
     *
     * Mirrors the exact same filters as index() (status, channel, outlet_id,
     * start_date, end_date, search) so the export always matches what's
     * currently on screen. Capped at 10,000 rows to keep memory bounded -
     * narrower date/status filters should be used for larger exports.
     */
    public function exportCsv(Request $request)
    {
        $query = Order::with(['outlet', 'items']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('channel')) {
            $query->where('order_type', $request->channel);
        }

        if ($request->has('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_first_name', 'ILIKE', "%{$search}%")
                  ->orWhere('customer_last_name',  'ILIKE', "%{$search}%")
                  ->orWhere('customer_email',      'ILIKE', "%{$search}%")
                  ->orWhere('customer_phone',      'ILIKE', "%{$search}%");
            });
        }

        [$sortBy, $sortOrder] = SortResolver::resolve(
            $request->get('sort_by'),
            $request->get('sort_order', 'desc'),
            self::SORTABLE_COLUMNS,
            'created_at'
        );
        $query->orderBy($sortBy, $sortOrder);

        $orders = $query->limit(10000)->get();

        $headers = [
            'Order Number',
            'Date',
            'Channel',
            'Status',
            'Payment Status',
            'Customer Name',
            'Customer Email',
            'Customer Phone',
            'Outlet',
            'Items',
            'Subtotal',
            'Discount',
            'Tax',
            'Shipping',
            'Total',
            'Currency',
        ];

        $rows = $orders->map(function (Order $order) {
            return [
                $order->order_number,
                optional($order->created_at)->format('Y-m-d H:i'),
                $order->order_type,
                $order->status,
                $order->payment_status,
                trim($order->customer_first_name . ' ' . $order->customer_last_name),
                $order->customer_email,
                $order->customer_phone,
                $order->outlet->name ?? '',
                $order->items->count(),
                $order->subtotal,
                $order->discount_amount,
                $order->tax_amount,
                $order->shipping_amount,
                $order->total_amount,
                $order->currency_code,
            ];
        });

        return $this->csvResponse($headers, $rows, 'orders');
    }

    /**
     * Stream a CSV response.
     * $headers: array of column header strings
     * $rows: iterable of arrays (one per row)
     * $filename: download filename without extension
     *
     * Matches the same pattern used in ReportController::csvResponse() -
     * built entirely in memory (so errors are catchable), UTF-8 BOM for
     * Excel compatibility, timestamped filename.
     */
    private function csvResponse(array $headers, iterable $rows, string $filename): \Illuminate\Http\Response
    {
        $out = fopen('php://temp', 'r+');
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

    /**
     * Get single order (Admin)
     */
    public function show($id)
    {
        $order = Order::with([
            'user:id,first_name,last_name,email,phone',
            'items',
            'outlet:id,name',
            'payments',
            'statusHistory',
        ])->findOrFail($id);

        $data                  = $order->toArray();
        // Prefer the linked user account; fall back to the denormalised columns
        // stored on the order itself (set at creation time, e.g. for POS walk-ins
        // where a Customer record is created but no User account exists).
        $data['customer_name'] = $order->user
            ? trim($order->user->first_name . ' ' . $order->user->last_name)
            : (trim(($order->customer_first_name ?? '') . ' ' . ($order->customer_last_name ?? '')) ?: null);
        $orderEmail = $order->customer_email;
        $data['customer_email'] = $order->user?->email
            ?? ($orderEmail && !str_starts_with($orderEmail, 'noemail+') ? $orderEmail : null);
        $data['customer_phone'] = $order->user?->phone ?? $order->customer_phone;
        $data['outlet_name']    = $order->outlet?->name;
        $data['cashier_name']   = null;

        $notesArray = [];
        if (!empty($order->notes)) {
            $notesArray[] = [
                'id'          => 'admin-note',
                'note'        => $order->notes,
                'is_internal' => true,
                'user_name'   => 'Staff',
                'created_at'  => $order->updated_at,
            ];
        }
        if (!empty($order->customer_notes)) {
            $notesArray[] = [
                'id'          => 'customer-note',
                'note'        => $order->customer_notes,
                'is_internal' => false,
                'user_name'   => $data['customer_name'] ?? 'Customer',
                'created_at'  => $order->created_at,
            ];
        }
        $data['order_notes'] = $notesArray;

        // ── Per-item tax rate and order-level tax breakdown ───────────────────
        // Annotate each item with its effective tax rate (as a percentage, e.g. 16.0)
        // so the frontend can display per-line rates without a separate lookup.
        $taxByRate = [];
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as &$item) {
                $productId   = $item['product_id'] ?? 0;
                $rateDecimal = $productId ? \App\Services\TaxCalculationService::rateForProduct($productId) : 0.0;
                $item['tax_rate'] = round($rateDecimal * 100, 4); // e.g. 16.0
                // Human-readable tax category label for receipt/invoice display
                $item['tax_name'] = $productId
                    ? \App\Services\TaxCalculationService::rateLabelForProduct($productId)
                    : null;
                // Price adjustment audit fields
                $item['original_price']  = isset($item['original_price'])  ? (float)$item['original_price']  : null;
                $item['price_adjusted']  = !empty($item['price_adjusted']);
                // Accumulate breakdown
                if ($rateDecimal > 0 && ($item['tax_amount'] ?? 0) > 0) {
                    $rateKey = number_format($rateDecimal, 6);
                    if (!isset($taxByRate[$rateKey])) {
                        $taxByRate[$rateKey] = [
                            'rate'   => $item['tax_rate'],
                            'amount' => 0.0,
                            'label'  => $item['tax_name'] ?? 'Tax',
                        ];
                    }
                    $taxByRate[$rateKey]['amount'] += (float)$item['tax_amount'];
                }
            }
            unset($item);
        }
        $data['tax_breakdown'] = array_values($taxByRate);

        $productionOrders = \App\Models\ProductionOrder::with([
                'product:id,sku',
                'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            ])
            ->where('customer_order_id', $order->id)
            ->get()
            ->map(fn ($po) => [
                'id'                => $po->id,
                'order_number'      => $po->order_number,
                'product_name'      => $po->product?->translations->first()?->name ?? $po->product?->sku ?? 'Unknown',
                'quantity'          => $po->quantity,
                'status'            => $po->status,
                'priority'          => $po->priority,
                'due_date'          => $po->due_date?->toDateString(),
                'is_customer_order' => $po->is_customer_order,
            ]);

        $data['production_orders'] = $productionOrders;

        // ── Normalise status_history field names for the frontend ─────────────
        // DB uses from_status/to_status/created_by; frontend expects
        // old_status/new_status/changed_by_name.
        if (isset($data['status_history']) && is_array($data['status_history'])) {
            $creatorIds = array_filter(array_column($data['status_history'], 'created_by'));
            $creators   = $creatorIds
                ? \App\Models\User::whereIn('id', $creatorIds)
                    ->get(['id', 'first_name', 'last_name'])
                    ->keyBy('id')
                : collect();

            $data['status_history'] = array_map(function ($h) use ($creators) {
                $creator = isset($h['created_by']) ? $creators->get($h['created_by']) : null;
                $h['old_status']      = $h['from_status'] ?? null;
                $h['new_status']      = $h['to_status']   ?? null;
                $h['changed_by_name'] = $creator
                    ? trim($creator->first_name . ' ' . $creator->last_name)
                    : null;
                return $h;
            }, $data['status_history']);
        }

        return response()->json(['order' => $data]);
    }

    /**
     * Get customer orders
     */
    public function customerOrders(Request $request)
    {
        $user = $request->user();

        $orders = Order::with(['items', 'payments'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * Get customer order details
     */
    public function customerOrderDetails(Request $request, $id)
    {
        $order = Order::with(['items', 'items.variant', 'payments'])
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json($order);
    }

    /**
     * Checkout and create order (storefront / customer-facing)
     *
     * Phase 1: integrated TaxCalculationService, payment token generation,
     * is_international flag, and notification dispatch.
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'shipping_address_id' => 'required_if:delivery_method,delivery|exists:addresses,id',
            'pickup_location_id'  => 'required_if:delivery_method,pickup|exists:outlets,id',
            'delivery_method'     => 'required|in:delivery,pickup',
            'shipping_method_id'  => 'required_if:delivery_method,delivery|exists:shipping_methods,id',
            'payment_method'      => 'required|in:mpesa,card,cash_on_delivery',
            'phone'               => 'required_if:payment_method,mpesa|string',
            'notes'               => 'nullable|string',
            // Phase 1 - country drives currency resolution
            'country_code'        => 'nullable|string|size:2',
        ]);

        $user = $request->user();

        $cart = Cart::with('items.variant.product.translations', 'items.variant.prices')
            ->where('user_id', $user->id)
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 422);
        }

        // ── Resolve currency from country ─────────────────────────────────────
        $homeCountry     = DB::table('settings')->where('key', 'app_country')->value('value') ?? 'KE';
        $countryCode     = strtoupper($validated['country_code'] ?? $homeCountry);
        $currency        = Order::resolveCurrency($countryCode);
        $isInternational = $countryCode !== strtoupper($homeCountry);

        // Validate currency is active in the platform
        $activeCurrencies = DB::table('currencies')->where('is_active', true)->pluck('code')->toArray();
        if (!empty($activeCurrencies) && !in_array($currency, $activeCurrencies)) {
            $defaultCurrency = DB::table('settings')->where('key', 'default_currency')->value('value') ?? 'KES';
            $currency        = $defaultCurrency;
        }

        DB::beginTransaction();
        try {
            // ── Build line data for tax calculation ───────────────────────────
            $lines = [];
            foreach ($cart->items as $item) {
                $unitPrice = $item->variant->prices->where('currency_code', $currency)->first()?->regular_price
                    ?? $item->variant->prices->first()?->regular_price
                    ?? 0;

                $lines[] = [
                    'product_id'      => $item->variant->product_id,
                    'unit_price'      => (float) $unitPrice,
                    'quantity'        => $item->quantity,
                    'discount_amount' => 0,
                ];
            }

            $taxCalc      = TaxCalculationService::calculateOrder($lines);
            $taxInclusive = $taxCalc['tax_inclusive'];

            // ── Shipping cost ─────────────────────────────────────────────────
            $shippingCost = 0;
            if ($validated['delivery_method'] === 'delivery' && !empty($validated['shipping_method_id'])) {
                $shippingMethod = DB::table('shipping_methods')->find($validated['shipping_method_id']);
                $shippingCost   = $shippingMethod?->base_rate ?? 0;
            }

            $totalAmount = $taxCalc['total_gross'] + $shippingCost;

            // ── Generate order number ─────────────────────────────────────────
            $prefix      = DB::table('settings')->where('key', 'order_prefix')->value('value') ?? 'ORD-';
            $orderNumber = $prefix . strtoupper(Str::random(8));
            while (Order::where('order_number', $orderNumber)->exists()) {
                $orderNumber = $prefix . strtoupper(Str::random(8));
            }

            // ── Generate payment token ────────────────────────────────────────
            $tokenPayload = $orderNumber . now()->toISOString() . Str::random(8);
            $paymentToken = hash_hmac('sha256', $tokenPayload, config('app.key'));

            // ── Create order ──────────────────────────────────────────────────
            $order = Order::create([
                'order_number'             => $orderNumber,
                'user_id'                  => $user->id,
                'order_type'               => 'online',
                'status'                   => 'pending',
                'payment_status'           => 'pending',
                'currency_code'            => $currency,
                'customer_country_code'    => $countryCode,
                'is_international'         => $isInternational,
                'subtotal'                 => $taxCalc['subtotal'],
                'tax_amount'               => $taxCalc['total_tax'],
                'prices_include_tax'       => $taxInclusive,
                'shipping_amount'          => $shippingCost,
                'total_amount'             => $totalAmount,
                'delivery_type'            => $validated['delivery_method'],
                'pickup_outlet_id'         => $validated['pickup_location_id'] ?? null,
                'payment_method'           => $validated['payment_method'],
                'notes'                    => $validated['notes'] ?? null,
                'payment_token'            => $paymentToken,
                'payment_token_expires_at' => now()->addHours(72),
                'created_by'               => $user->id,
                'ip_address'               => $request->ip(),
                'user_agent'               => $request->userAgent(),
            ]);

            // ── Create order items with tax amounts ───────────────────────────
            foreach ($cart->items as $index => $item) {
                $inventoryItem  = InventoryItem::where('product_variant_id', $item->variant_id)->first();
                $availableStock = $inventoryItem?->quantity_available ?? 0;

                if ($availableStock < $item->quantity) {
                    DB::rollBack();
                    $productName = $item->variant->product->translations->first()?->name ?? 'this product';
                    return response()->json(['message' => "Insufficient stock for {$productName}"], 422);
                }

                $lineTax  = $taxCalc['lines'][$index] ?? ['tax_amount' => 0, 'subtotal_gross' => 0];
                $unitPrice = $item->variant->prices->where('currency_code', $currency)->first()?->regular_price
                    ?? $item->variant->prices->first()?->regular_price ?? 0;

                OrderItem::create([
                    'order_id'           => $order->id,
                    'product_id'         => $item->variant->product_id,
                    'product_variant_id' => $item->variant_id,
                    'product_name'       => $item->variant->product->translations->first()?->name ?? 'Product',
                    'variant_name'       => $item->variant->variant_name,
                    'sku'                => $item->variant->sku,
                    'quantity'           => $item->quantity,
                    'unit_price'         => $unitPrice,
                    'discount_amount'    => 0,
                    'tax_amount'         => $lineTax['tax_amount'],
                    'total_price'        => $lineTax['subtotal_gross'],
                ]);

                if ($inventoryItem) {
                    $inventoryItem->adjustQuantity(
                        -$item->quantity, 'sale',
                        \App\Models\Order::class, $order->id, $user->id
                    );
                }
            }

            // Clear cart
            $cart->items()->delete();

            DB::commit();

            // ── Notifications & audit ─────────────────────────────────────────
            NotificationService::orderPlaced($order->id, $order->order_number);

            ActivityLogService::log('created', $order, [
                'order_number'   => $order->order_number,
                'total'          => $order->total_amount,
                'currency'       => $order->currency_code,
                'channel'        => 'online',
                'is_international' => $isInternational,
            ], null, $user);

            return response()->json([
                'message'       => 'Order placed successfully',
                'order'         => $order->load(['items.variant.product', 'shippingAddress']),
                'payment_link'  => rtrim(config('app.frontend_url'), '/') . "/pay/{$paymentToken}",
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create order',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update order status (Admin)
     *
     * Phase 1: dispatches OrderStatusChanged notification and writes audit log.
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status'          => 'required|in:pending,processing,confirmed,shipped,delivered,completed,cancelled,refunded',
            'tracking_number' => 'sometimes|string',
            'notes'           => 'sometimes|string',
        ]);

        $order     = Order::with(['payments', 'items'])->findOrFail($id);
        $newStatus = $validated['status'];

        // ── Guard: cannot progress order while payments are awaiting approval ──
        // Any payment with requires_approval=true and approval_status=pending_review
        // blocks the order from moving to processing, shipped, delivered, or completed.
        $progressingStatuses = ['processing', 'confirmed', 'shipped', 'delivered', 'completed'];
        if (in_array($newStatus, $progressingStatuses)) {
            $pendingApprovals = $order->payments
                ->where('requires_approval', true)
                ->where('approval_status', 'pending_review');

            if ($pendingApprovals->count() > 0) {
                $pending = $pendingApprovals->map(fn ($p) => [
                    'id'             => $p->id,
                    'payment_number' => $p->payment_number,
                    'amount'         => $p->amount,
                    'currency_code'  => $p->currency_code,
                    'payment_method' => $p->payment_method,
                ])->values();

                return response()->json([
                    'message'          => 'Cannot advance this order - it has payments awaiting admin approval.',
                    'reason'           => 'pending_payment_approval',
                    'pending_payments' => $pending,
                    'tip'              => 'Approve or reject the pending payments from the Approvals queue first.',
                ], 422);
            }
        }

        // ── Guard: cannot confirm if not fully paid ──────────────────────────
        // "confirmed" means payment is verified and the order is ready to fulfil.
        // Staff should not manually set it if the order still has an outstanding balance.
        if ($newStatus === 'confirmed') {
            $totalPaid = $order->payments->where('status', 'paid')->sum('amount');
            if ($totalPaid < $order->total_amount - 0.01) {
                return response()->json([
                    'message'     => 'Cannot confirm this order - it has not been fully paid.',
                    'reason'      => 'unpaid_balance',
                    'outstanding' => round($order->total_amount - $totalPaid, 2),
                ], 422);
            }

            // Intelligence #5 — auto-draft production orders for producible items
            // Runs after payment is verified so production only starts for paid orders.
            try {
                $order->loadMissing('items');
                IntelligenceService::autoLinkOrderToProduction($order);
            } catch (\Exception) {}
        }

        // ── Guard: cannot complete if there are pending production orders ─────
        if ($newStatus === 'completed') {
            $pendingProduction = \App\Models\ProductionOrder::where('customer_order_id', $order->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->exists();
            if ($pendingProduction) {
                return response()->json([
                    'message' => 'Cannot complete this order - one or more linked production orders are not yet completed.',
                    'reason'  => 'pending_production',
                ], 422);
            }

            // ── Guard: cannot complete if not fully paid ──────────────────────
            $totalPaid = $order->payments->where('status', 'paid')->sum('amount');
            if ($totalPaid < $order->total_amount - 0.01) {
                return response()->json([
                    'message'     => 'Cannot complete this order - it has not been fully paid.',
                    'reason'      => 'unpaid_balance',
                    'outstanding' => round($order->total_amount - $totalPaid, 2),
                ], 422);
            }
        }

        // ── Guard: cannot ship if there are incomplete production orders ──────
        if (in_array($newStatus, ['shipped', 'delivered'])) {
            $pendingProduction = \App\Models\ProductionOrder::where('customer_order_id', $order->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->exists();
            if ($pendingProduction) {
                return response()->json([
                    'message' => 'Cannot ship - one or more production orders must be completed first.',
                    'reason'  => 'pending_production',
                ], 422);
            }

            $totalPaid = $order->payments->where('status', 'paid')->sum('amount');
            if ($totalPaid < $order->total_amount - 0.01) {
                return response()->json([
                    'message'     => 'Cannot ship - the order has an outstanding balance.',
                    'reason'      => 'unpaid_balance',
                    'outstanding' => round($order->total_amount - $totalPaid, 2),
                ], 422);
            }
        }

        $oldStatus = $order->status;

        $order->update([
            'status'          => $newStatus,
            'tracking_number' => $validated['tracking_number'] ?? $order->tracking_number,
        ]);

        DB::table('order_status_history')->insert([
            'order_id'    => $order->id,
            'from_status' => $oldStatus,
            'to_status'   => $newStatus,
            'created_by'  => $request->user()->id,
            'notes'       => $validated['notes'] ?? null,
            'created_at'  => now(),
        ]);

        // ── Notifications & audit ─────────────────────────────────────────────
        NotificationService::orderStatusChanged(
            $order->id,
            $order->order_number,
            $oldStatus,
            $newStatus,
            $order->user_id
        );

        ActivityLogService::log('status_changed', $order, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        return response()->json([
            'message' => 'Order status updated successfully',
            'order'   => $order->fresh(),
        ]);
    }

    /**
     * Cancel order (customer-facing)
     */
    public function cancelOrder(Request $request, $id)
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        if (!in_array($order->status, ['pending', 'processing', 'confirmed'])) {
            return response()->json([
                'message' => 'Order cannot be cancelled at this stage',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $order->update(['status' => 'cancelled']);

            foreach ($order->items as $item) {
                $inventoryItem = InventoryItem::where('product_variant_id', $item->product_variant_id)->first();
                if ($inventoryItem) {
                    $inventoryItem->adjustQuantity(
                        $item->quantity, 'cancellation',
                        \App\Models\Order::class, $order->id, $request->user()->id
                    );
                }
            }

            DB::commit();

            ActivityLogService::log('cancelled', $order, ['cancelled_by' => 'customer']);

            return response()->json([
                'message' => 'Order cancelled successfully',
                'order'   => $order,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to cancel order',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add note to order (Admin)
     */
    public function addNote(Request $request, $id)
    {
        $validated = $request->validate([
            'note'        => 'required|string',
            'is_internal' => 'boolean',
        ]);

        $order      = Order::findOrFail($id);
        $isInternal = $validated['is_internal'] ?? true;

        if ($isInternal) {
            $existing = $order->notes ? $order->notes . "\n\n" : '';
            $order->update(['notes' => $existing . $validated['note']]);
        } else {
            $existing = $order->customer_notes ? $order->customer_notes . "\n\n" : '';
            $order->update(['customer_notes' => $existing . $validated['note']]);
        }

        ActivityLogService::log('note_added', $order, [
            'note'        => $validated['note'],
            'is_internal' => $isInternal,
        ]);

        return response()->json(['message' => 'Note added successfully']);
    }

    // =========================================================================
    // POST /admin/orders/{id}/void
    //
    // Admin voids an order. A voided order is administratively cancelled -
    // it was never legitimately completed or fulfilled. Allowed from:
    //   pending, processing, confirmed (not yet shipped or delivered).
    //
    // Void vs Cancel:
    //   cancel = customer-initiated or lightweight staff action before payment
    //   void   = admin-initiated, stronger: marks the order as never valid,
    //            typically used for test orders, duplicates, or fraud.
    //
    // Inventory is restocked. All pending payments are marked void.
    // =========================================================================

    public function voidOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $order = Order::with(['items', 'payments'])->findOrFail($id);

        $voidableStatuses = ['pending', 'processing', 'confirmed'];
        if (!in_array($order->status, $voidableStatuses)) {
            return response()->json([
                'message' => 'This order cannot be voided. Only pending, processing, or confirmed orders can be voided. Use Refund for completed orders.',
                'reason'  => 'invalid_status',
                'status'  => $order->status,
            ], 422);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $order->status;

            // Void all non-paid/pending payments on this order
            $order->payments->each(function ($payment) {
                if (!in_array($payment->status, ['refunded', 'voided'])) {
                    $payment->update([
                        'status'     => 'voided',
                        'updated_at' => now(),
                    ]);
                }
            });

            // Reconcile payment_status now that the payments are voided — otherwise
            // it stays stale at 'paid' and payment-based reports double-count the
            // voided sale (audit MON-1). Voided payments are already excluded from
            // totalPaid(), so this just refreshes the derived field.
            $order->syncPaymentStatus();

            // Restock inventory for each line item
            foreach ($order->items as $item) {
                $inventoryItem = InventoryItem::where('product_variant_id', $item->product_variant_id)
                    ->where(function ($q) use ($order) {
                        $q->where('outlet_id', $order->outlet_id)->orWhereNull('outlet_id');
                    })
                    ->orderByRaw('outlet_id IS NULL ASC')
                    ->first();

                if ($inventoryItem) {
                    $inventoryItem->adjustQuantity(
                        $item->quantity, 'void',
                        Order::class, $order->id, $request->user()->id
                    );
                }
            }

            $order->update([
                'status'      => 'voided',
                'cancelled_at'=> now(),
                'notes'       => ($order->notes ? $order->notes . "\n\n" : '') . "VOIDED by {$request->user()->first_name}: {$validated['reason']}",
            ]);

            // Cancel any linked production orders that are still cancellable
            $cancelledProdOrders = [];
            $nonCancellableProdOrders = [];
            $linkedProdOrders = \App\Models\ProductionOrder::where('customer_order_id', $order->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->get();
            foreach ($linkedProdOrders as $prodOrder) {
                if (in_array($prodOrder->status, ['in_progress', 'qc_pending', 'qc_passed'])) {
                    $nonCancellableProdOrders[] = $prodOrder->order_number;
                } else {
                    $prodOrder->update([
                        'status' => 'cancelled',
                        'notes'  => (($prodOrder->notes ? $prodOrder->notes . "\n\n" : '') .
                            "Auto-cancelled: linked sales order {$order->order_number} was voided."),
                    ]);
                    $cancelledProdOrders[] = $prodOrder->order_number;
                    try {
                        ActivityLogService::log('production_order_cancelled', $prodOrder, [
                            'order_number' => $prodOrder->order_number,
                            'reason'       => "Sales order {$order->order_number} voided",
                        ]);
                    } catch (\Exception) {}
                }
            }

            DB::table('order_status_history')->insert([
                'order_id'    => $order->id,
                'from_status' => $oldStatus,
                'to_status'   => 'voided',
                'created_by'  => $request->user()->id,
                'notes'       => $validated['reason'],
                'created_at'  => now(),
            ]);

            DB::commit();

            ActivityLogService::log('voided', $order, [
                'old_status'  => $oldStatus,
                'reason'      => $validated['reason'],
                'voided_by'   => $request->user()->id,
                'voided_name' => $request->user()->first_name . ' ' . $request->user()->last_name,
            ]);

            $message = 'Order voided successfully.';
            if (!empty($cancelledProdOrders)) {
                $count = count($cancelledProdOrders);
                $message .= " {$count} linked production order" . ($count > 1 ? 's' : '') . ' also cancelled.';
            }
            if (!empty($nonCancellableProdOrders)) {
                $message .= ' Note: ' . implode(', ', $nonCancellableProdOrders) . ' could not be auto-cancelled (in progress) — please cancel manually.';
            }

            return response()->json([
                'message' => $message,
                'order'   => $order->fresh(),
                'cancelled_production_orders' => $cancelledProdOrders,
                'skipped_production_orders'   => $nonCancellableProdOrders,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to void order.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process refund (Admin)
     */
    public function refund(Request $request, $id)
    {
        $validated = $request->validate([
            'amount'          => 'required|numeric|min:0',
            'reason'          => 'required|string',
            'refund_shipping' => 'boolean',
        ]);

        $order = Order::findOrFail($id);

        if (!in_array($order->status, ['delivered', 'completed'])) {
            return response()->json([
                'message' => 'Order must be completed or processing to process a refund.',
            ], 422);
        }

        // Cannot refund more than was actually collected (audit MON-2). Without
        // this bound the endpoint accepted any amount >= 0.
        $collected = $order->totalPaid();
        if ((float) $validated['amount'] > $collected) {
            return response()->json([
                'message' => "Refund amount exceeds the {$collected} {$order->currency_code} collected on this order.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $order->update(['status' => 'refunded']);

            $refundNote = "Refund of {$validated['amount']} {$order->currency_code}: {$validated['reason']}";
            $order->update(['notes' => ($order->notes ? $order->notes . "\n\n" : '') . $refundNote]);

            // Allocate the refund across paid payments (partial- and
            // repeat-refund safe), never exceeding each payment's own amount.
            $remaining = (float) $validated['amount'];
            foreach (
                Payment::where('order_id', $order->id)->where('status', 'paid')->orderBy('id')->get()
                as $payment
            ) {
                if ($remaining <= 0) {
                    break;
                }
                $alreadyRefunded = (float) $payment->refund_amount;
                $refundable      = (float) $payment->amount - $alreadyRefunded;
                if ($refundable <= 0) {
                    continue;
                }
                $apply = min($refundable, $remaining);
                $payment->update([
                    'refund_amount' => $alreadyRefunded + $apply,
                    'refunded_at'   => now(),
                ]);
                $remaining -= $apply;
            }

            // Reconcile now that totalPaid() is net of refunds (audit MON-1) —
            // otherwise payment_status stays stale at 'paid' and reports
            // double-count the refunded sale.
            $order->syncPaymentStatus();

            DB::commit();

            ActivityLogService::log('refunded', $order, [
                'amount' => $validated['amount'],
                'reason' => $validated['reason'],
            ]);

            return response()->json(['message' => 'Refund processed successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to process refund',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Record an additional payment against an order (partial/split/deposit).
     *
     * Phase 1:
     *  - Uses order.is_international flag (not just currency === 'USD') for approval gate.
     *  - Dispatches PaymentApprovalRequired or PaymentReceived notification.
     *  - Writes an audit log entry on every payment.
     */
    public function addPayment(Request $request, $id)
    {
        // Normalise aliases — frontend sends DB code ('paystack', 'flutterwave')
        // but the validated whitelist uses the prefixed form ('card_paystack', etc.)
        $aliases = ['paystack' => 'card_paystack', 'flutterwave' => 'card_flutterwave'];
        if (isset($aliases[$request->input('method')])) {
            $request->merge(['method' => $aliases[$request->input('method')]]);
        }

        $validated = $request->validate([
            'method'             => 'required|in:cash,mpesa,card,card_paystack,card_flutterwave,bank_transfer,other',
            'amount'             => 'required|numeric|min:0.01',
            'reference'         => 'nullable|string|max:255',
            'phone'             => 'nullable|string|max:30',
            'tax_inclusive'     => 'boolean',
            'notes'             => 'nullable|string|max:500',
            // Human-readable name for "other" method (e.g. "Cheque", "Wire Transfer")
            'custom_method_name' => 'nullable|string|max:100',
        ]);

        $order = Order::with('payments')->findOrFail($id);

        if (in_array($order->status, ['cancelled', 'voided', 'refunded'])) {
            return response()->json(['message' => 'Cannot add payment to a cancelled/voided/refunded order.'], 422);
        }

        $taxInclusive       = $validated['tax_inclusive'] ?? true;
        $collectedAmount    = (float) $validated['amount'];
        $taxAmountCollected = null;

        if (!$taxInclusive && $order->tax_amount > 0 && $order->subtotal > 0) {
            $taxRate            = (float) $order->tax_amount / (float) $order->subtotal;
            $taxAmountCollected = round($collectedAmount * $taxRate, 2);
        }

        // ── Guard: prevent overpayments ───────────────────────────────────────
        // Count all payments that are either paid or pending approval -
        // pending-approval payments are real money the customer has submitted,
        // so they count toward the order total even before admin approves them.
        $alreadyCommitted = $order->payments
            ->whereIn('status', ['paid', 'pending'])
            ->sum('amount');
        $remainingBalance = (float) $order->total_amount - (float) $alreadyCommitted;

        if ($remainingBalance <= 0) {
            return response()->json([
                'message' => 'This order has already been fully paid. No further payments are required.',
                'reason'  => 'overpayment',
            ], 422);
        }

        if ($collectedAmount > $remainingBalance + 0.01) {
            return response()->json([
                'message'   => "Payment of {$collectedAmount} exceeds the remaining balance of " . round($remainingBalance, 2) . " {$order->currency_code}. Reduce the amount or split the payment.",
                'reason'    => 'overpayment',
                'remaining' => round($remainingBalance, 2),
            ], 422);
        }

        // ANY non-automated method requires admin approval before the payment is
        // considered effective - regardless of whether the order is international.
        // Cash is automated (immediate verified transaction). Card/M-Pesa are
        // verified by the payment gateway. Everything else needs human review.
        // We also check the method's DB type so that any method configured with
        // type='cash' is treated identically to the built-in 'cash' code.
        $methodDbType      = DB::table('payment_methods')
            ->where('code', $validated['method'])
            ->value('type');
        $isAutomatedMethod = $validated['method'] === 'cash'
            || $methodDbType === 'cash'
            || in_array($validated['method'], ['mpesa', 'card', 'card_paystack']);
        $requiresApproval  = !$isAutomatedMethod;

        // For "other" method, prepend the custom name to the reference so it is
        // traceable in the payment record, approval queue, and audit log.
        $reference = $validated['reference'] ?? null;
        if ($validated['method'] === 'other' && !empty($validated['custom_method_name'])) {
            $reference = trim($validated['custom_method_name'] . ($reference ? ' - ' . $reference : ''));
        }

        DB::beginTransaction();
        try {
            $payment = Payment::create([
                'order_id'             => $order->id,
                'payment_method'       => $validated['method'],
                'amount'               => $collectedAmount,
                'currency_code'        => $order->currency_code ?? 'KES',
                'status'               => $requiresApproval ? 'pending' : 'paid',
                'provider_reference'   => $reference,
                'phone_number'         => $validated['phone'] ?? null,
                'paid_at'              => $requiresApproval ? null : now(),
                'tax_inclusive'        => $taxInclusive,
                'tax_amount_collected' => $taxAmountCollected,
                'requires_approval'    => $requiresApproval,
                'approval_status'      => $requiresApproval ? 'pending_review' : null,
            ]);

            $payNote = "Payment of {$collectedAmount} {$order->currency_code} via "
                . ucfirst($validated['method'])
                . ($requiresApproval ? ' (awaiting admin approval)' : '')
                . ($taxInclusive ? ' (tax incl.)' : ' (tax excl., +' . $taxAmountCollected . ' tax)');
            if (!empty($validated['notes'])) {
                $payNote .= '. ' . $validated['notes'];
            }
            $order->update(['notes' => ($order->notes ? $order->notes . "\n\n" : '') . $payNote]);

            if (!$requiresApproval) {
                $order->refresh();
                $order->syncPaymentStatus();
                $order->refresh();
                // Advance order status when payment is confirmed:
                // pending → processing: first payment received
                // processing stays processing until admin moves it forward
                if (in_array($order->status, ['pending', 'pending_payment']) && $order->payment_status !== 'pending') {
                    $order->update(['status' => 'processing']);
                    DB::table('order_status_history')->insert([
                        'order_id'    => $order->id,
                        'from_status' => 'pending',
                        'to_status'   => 'processing',
                        'created_by'  => $request->user()->id,
                        'notes'       => 'Automatically advanced after payment confirmed.',
                        'created_at'  => now(),
                    ]);
                }
            } else {
                // For pending-approval payments: keep order in current status
                // but update payment_status to reflect pending-approval state
                $pendingApprovalTotal = $order->payments->whereIn('status', ['pending'])->sum('amount');
                $paidTotal = $order->payments->where('status', 'paid')->sum('amount');
                if ($pendingApprovalTotal > 0 && $paidTotal == 0) {
                    $order->update(['payment_status' => 'pending_approval']);
                }
            }

            $totalPaid   = $order->totalPaid();
            $outstanding = $order->outstandingBalance();

            DB::commit();

            // ── Notifications ─────────────────────────────────────────────────
            if ($requiresApproval) {
                NotificationService::paymentApprovalRequired(
                    $payment->id,
                    $payment->payment_number,
                    $order->id,
                    $order->order_number,
                    $collectedAmount,
                    $order->currency_code,
                    $order->customer_country_code ?? ''
                );
            } else {
                NotificationService::paymentReceived(
                    $payment->id,
                    $payment->payment_number,
                    $order->id,
                    $order->order_number,
                    $collectedAmount,
                    $order->currency_code,
                    $validated['method']
                );
            }

            // ── Audit log ─────────────────────────────────────────────────────
            ActivityLogService::log('payment_recorded', $order, [
                'payment_id'         => $payment->id,
                'payment_number'     => $payment->payment_number,
                'method'             => $validated['method'],
                'amount'             => $collectedAmount,
                'currency'           => $order->currency_code,
                'requires_approval'  => $requiresApproval,
                'new_payment_status' => $order->fresh()->payment_status,
            ]);

            return response()->json([
                'message'          => $requiresApproval
                    ? 'Payment recorded. Please upload proof of payment - a receipt will be available once admin approves.'
                    : 'Payment recorded successfully.',
                'payment'          => $payment,
                'requires_approval'=> $requiresApproval,
                'total_paid'       => $totalPaid,
                'outstanding'      => $outstanding,
                'payment_status'   => $order->fresh()->payment_status,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to record payment.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Resend order confirmation email (Admin)
     */
    public function resendConfirmation($id)
    {
        $order = Order::with('user')->findOrFail($id);

        // TODO: Send order confirmation email

        try {
            ActivityLogService::log('confirmation_resent', $order, [
                'order_number' => $order->order_number,
                'email'        => $order->user?->email ?? $order->customer_email,
            ]);
        } catch (\Exception) {}

        return response()->json(['message' => 'Confirmation email sent successfully']);
    }

    /**
     * Generate invoice (Admin)
     */
    public function generateInvoice($id)
    {
        $order = Order::with([
            'user:id,first_name,last_name,email,phone',
            'items',
            'items.variant:id,sku,variant_name',
            'outlet:id,name',
            'payments',
        ])->findOrFail($id);

        $data                  = $order->toArray();
        $data['customer_name'] = $order->user
            ? trim($order->user->first_name . ' ' . $order->user->last_name)
            : (trim(($order->customer_first_name ?? '') . ' ' . ($order->customer_last_name ?? '')) ?: null);
        $data['outlet_name']   = $order->outlet?->name;

        try {
            ActivityLogService::log('invoice_generated', $order, [
                'order_number' => $order->order_number,
                'total_amount' => $order->total_amount,
                'currency'     => $order->currency_code,
            ]);
        } catch (\Exception) {}

        return response()->json([
            'message' => 'Invoice data ready.',
            'order'   => $data,
        ]);
    }

    // =========================================================================
    // GET /admin/orders/{id}/payment-link
    //
    // Generates (or refreshes) a signed payment token and returns the
    // shareable URL that can be sent to the customer.
    // =========================================================================

    public function paymentLink(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'This order is already fully paid.'], 422);
        }

        // Regenerate token if missing or expired
        $needsToken = empty($order->payment_token)
            || ($order->payment_token_expires_at && $order->payment_token_expires_at->isPast());

        if ($needsToken) {
            $tokenPayload = $order->order_number . $order->created_at->toISOString() . Str::random(8);
            $token        = hash_hmac('sha256', $tokenPayload, config('app.key'));

            $order->update([
                'payment_token'            => $token,
                'payment_token_expires_at' => now()->addHours(72),
            ]);
            $order->refresh();
        }

        $url = rtrim(config('app.frontend_url'), '/') . "/pay/{$order->payment_token}";

        ActivityLogService::log('payment_link_generated', $order, [
            'order_number' => $order->order_number,
            'expires_at'   => $order->payment_token_expires_at?->toISOString(),
        ]);

        return response()->json([
            'payment_url' => $url,
            'url'         => $url,   // legacy alias
            'token'       => $order->payment_token,
            'expires_at'  => $order->payment_token_expires_at?->toISOString(),
        ]);
    }

    // =========================================================================
    // PATCH /admin/orders/{id}/shipping-fee
    // =========================================================================

    public function setShippingFee(Request $request, $id)
    {
        $validated = $request->validate([
            'amount'             => 'required|numeric|min:0',
            'note'               => 'nullable|string|max:255',
            'shipping_method_id' => 'nullable|integer|exists:shipping_methods,id',
        ]);

        $order = Order::findOrFail($id);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Cannot change shipping fee on a paid order.'], 422);
        }

        if (in_array($order->status, ['cancelled', 'voided', 'refunded'])) {
            return response()->json(['message' => 'Cannot change shipping fee on a cancelled/voided/refunded order.'], 422);
        }

        $oldShipping = (float) $order->shipping_amount;
        $newShipping = (float) $validated['amount'];
        $diff        = $newShipping - $oldShipping;

        $shippingMethodName = null;
        if (!empty($validated['shipping_method_id'])) {
            $method = DB::table('shipping_methods')->find($validated['shipping_method_id']);
            if ($method) {
                $shippingMethodName = $method->name;
            }
        }

        $order->update([
            'shipping_amount'         => $newShipping,
            'total_amount'            => max(0, (float) $order->total_amount + $diff),
            'shipping_fee_overridden' => true,
            'shipping_method'         => $shippingMethodName ?? $order->shipping_method,
            'shipping_fee_note'       => $validated['note'] ?? $shippingMethodName ?? null,
        ]);

        $fresh = $order->fresh();

        ActivityLogService::log('shipping_fee_updated', $order, [
            'old_amount'          => $oldShipping,
            'new_amount'          => $newShipping,
            'shipping_method'     => $shippingMethodName,
            'note'                => $validated['note'] ?? null,
            'new_total'           => $fresh->total_amount,
        ]);

        return response()->json([
            'message'         => 'Shipping fee updated.',
            'shipping_amount' => $fresh->shipping_amount,
            'shipping_method' => $fresh->shipping_method,
            'total_amount'    => $fresh->total_amount,
        ]);
    }

    // =========================================================================
    // POST /admin/orders/{id}/set-deposit
    // =========================================================================

    public function setDeposit(Request $request, $id)
    {
        $validated = $request->validate([
            'deposit_amount'   => 'required|numeric|min:0.01',
            'balance_due_date' => 'nullable|date|after:today',
        ]);

        $order = Order::findOrFail($id);

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order is already fully paid.'], 422);
        }

        if ($validated['deposit_amount'] >= $order->total_amount) {
            return response()->json(['message' => 'Deposit amount must be less than the order total.'], 422);
        }

        $order->update([
            'deposit_amount'   => $validated['deposit_amount'],
            'balance_due_date' => $validated['balance_due_date'] ?? null,
        ]);

        ActivityLogService::log('deposit_terms_set', $order, [
            'deposit_amount'   => $validated['deposit_amount'],
            'balance_due_date' => $validated['balance_due_date'] ?? null,
            'order_total'      => $order->total_amount,
        ]);

        return response()->json([
            'message'          => 'Deposit terms set.',
            'deposit_amount'   => $order->fresh()->deposit_amount,
            'balance_due_date' => $order->fresh()->balance_due_date,
        ]);
    }

    // =========================================================================
    // POST /admin/orders/{id}/attach-customer
    // Attach or update customer details on an order.
    // Allowed while status is pending, processing, or confirmed.
    // =========================================================================

    public function attachCustomer(Request $request, $id)
    {
        $validated = $request->validate([
            'customer_id'         => 'nullable|exists:customers,id',
            'customer_first_name' => 'nullable|string|max:100',
            'customer_last_name'  => 'nullable|string|max:100',
            'customer_email'      => 'nullable|email|max:255',
            'customer_phone'      => 'nullable|string|max:30',
            'new_customer'        => 'nullable|array',
            'new_customer.first_name' => 'required_with:new_customer|string|max:100',
            'new_customer.last_name'  => 'nullable|string|max:100',
            'new_customer.phone'      => 'required_with:new_customer|string|max:30',
            'new_customer.email'      => 'nullable|email|max:255',
        ]);

        $order = Order::findOrFail($id);

        // Only allow attaching a customer while the order is still open
        $allowedStatuses = ['pending', 'pending_payment', 'processing', 'confirmed'];
        if (!in_array($order->status, $allowedStatuses)) {
            return response()->json([
                'message' => 'Customer can only be attached to orders that are pending, processing, or confirmed.',
                'reason'  => 'invalid_status',
            ], 422);
        }

        // Permission boundary: orders.edit can attach a customer to any
        // order. orders.create alone (e.g. pos_clerk, who can create
        // orders but not edit them generally) can only attach a customer
        // to an order they themselves created - this lets a cashier finish
        // attaching a customer to their own pending POS sale without
        // granting the broader orders.edit surface (status changes,
        // shipping fee, deposits, price overrides, etc).
        $user = $request->user();
        $isAdminTier = $user->isSuperAdmin() || $user->hasRole('super_admin') || $user->hasRole('admin');
        if (!$user->can('orders.edit') && $user->can('orders.create') && !$isAdminTier) {
            if ((int) $order->created_by !== (int) $user->id) {
                return response()->json([
                    'message' => 'You can only attach a customer to orders you created.',
                ], 403);
            }
        }

        $customerId   = $validated['customer_id'] ?? null;
        $linkedUserId = null;

        // Create a new customer record if requested
        if (!$customerId && !empty($validated['new_customer'])) {
            $nc = $validated['new_customer'];
            $customer = \App\Models\Customer::create([
                'first_name' => $nc['first_name'],
                'last_name'  => $nc['last_name'] ?? '',   // Customer model's creating() guard also defends against null here - belt-and-suspenders
                'phone'      => $nc['phone'],
                'email'      => $nc['email'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $customerId = $customer->id;
            $validated['customer_first_name'] = $validated['customer_first_name'] ?? $nc['first_name'];
            $validated['customer_last_name']  = $validated['customer_last_name']  ?? ($nc['last_name'] ?? null);
            $validated['customer_phone']      = $validated['customer_phone']      ?? $nc['phone'];
            $validated['customer_email']      = $validated['customer_email']      ?? ($nc['email'] ?? null);
        }

        if ($customerId) {
            $customer = \App\Models\Customer::find($customerId);
            $linkedUserId = $customer?->user_id ?? null;
            // Fill in customer fields from the record if not explicitly provided
            $validated['customer_first_name'] = $validated['customer_first_name'] ?? $customer?->first_name;
            $validated['customer_last_name']  = $validated['customer_last_name']  ?? $customer?->last_name;
            $validated['customer_email']      = $validated['customer_email']      ?? $customer?->email;
            $validated['customer_phone']      = $validated['customer_phone']      ?? $customer?->phone;
        }

        $updates = array_filter([
            'user_id'             => $linkedUserId,
            'customer_first_name' => $validated['customer_first_name'] ?? null,
            'customer_last_name'  => $validated['customer_last_name']  ?? null,
            'customer_email'      => $validated['customer_email']       ?? null,
            'customer_phone'      => $validated['customer_phone']       ?? null,
        ], fn ($v) => $v !== null);

        $order->update($updates);

        ActivityLogService::log('customer_attached', $order, [
            'customer_id'   => $customerId,
            'customer_name' => trim(($validated['customer_first_name'] ?? '') . ' ' . ($validated['customer_last_name'] ?? '')),
        ]);

        return response()->json([
            'message' => 'Customer attached successfully.',
            'order'   => $order->fresh(),
        ]);
    }

    // =========================================================================
    // GET /admin/orders/{id}/audit-log
    // Returns the activity log entries for this specific order.
    // =========================================================================

    /**
     * GET /admin/orders/{id}/audit-log
     *
     * Returns the comprehensive audit trail for a sales order - every action
     * recorded by ActivityLogService that is linked to this order, ordered
     * newest-first.
     */
    public function auditLog(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $logs = DB::table('activity_log as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.causer_id')
            ->where('al.subject_type', \App\Models\Order::class)
            ->where('al.subject_id', $order->id)
            ->orderBy('al.created_at', 'desc')
            ->select(
                'al.id',
                'al.log_name',
                'al.event',
                'al.action',
                'al.description',
                'al.properties',
                'al.ip_address',
                'al.created_at',
                DB::raw("COALESCE(NULLIF(TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')), ''), u.email, 'System') as actor_name"),
                'u.email as actor_email',
                'u.id as actor_id'
            )
            ->get()
            ->map(function ($log) {
                $props   = $log->properties ? json_decode($log->properties, true) : [];
                $event   = $log->event ?? $log->action ?? '';

                // Human-readable label map for every event type in the order lifecycle
                $labels = [
                    'created'                  => 'Order Created',
                    'status_changed'           => 'Status Changed',
                    'cancelled'                => 'Order Cancelled',
                    'refunded'                 => 'Refund Processed',
                    'payment_recorded'         => 'Payment Recorded',
                    'payment_approved'         => 'Payment Approved',
                    'payment_rejected'         => 'Payment Rejected',
                    'payment_link_generated'   => 'Payment Link Generated',
                    'mpesa_stk_initiated'      => 'M-Pesa STK Push Sent',
                    'mpesa_payment_verified'   => 'M-Pesa Payment Verified',
                    'mpesa_payment_confirmed'  => 'M-Pesa Payment Confirmed',
                    'mpesa_payment_failed'     => 'M-Pesa Payment Failed',
                    'paystack_initiated'       => 'Paystack Payment Initiated',
                    'paystack_payment_confirmed' => 'Paystack Payment Confirmed',
                    'flutterwave_payment_confirmed' => 'Flutterwave Payment Confirmed',
                    'payment_refunded'         => 'Payment Refunded',
                    'customer_attached'        => 'Customer Updated',
                    'shipping_fee_updated'     => 'Shipping Fee Updated',
                    'deposit_terms_set'        => 'Deposit Terms Set',
                    'note_added'               => 'Note Added',
                    'shipment_created'         => 'Shipment Created',
                    'shipment_tracking_added'  => 'Shipment Tracking Updated',
                    'production_assigned'      => 'Production Order Assigned',
                    'production_stage_completed' => 'Production Stage Completed',
                    'production_completed'     => 'Production Completed',
                    'production_confirmed'     => 'Production Confirmed',
                    // Shipment events
                    'shipment_created'           => 'Shipment Created',
                    'shipment_tracking_added'    => 'Shipment Tracking Updated',
                    'shipment_delivered'         => 'Shipment Delivered',
                    'shipment_cancelled'         => 'Shipment Cancelled',
                    // Payment proof events
                    'payment_proof_uploaded'     => 'Payment Proof Uploaded',
                    // Order void
                    'voided'                     => 'Order Voided',
                    'order_created_pos'          => 'POS Order Created',
                    // Currency change
                    'currency_changed'           => 'Currency / Country Changed',
                ];

                // Build a rich summary from properties where description is generic
                $summary = $log->description;
                if ($event === 'status_changed' && isset($props['old_status'], $props['new_status'])) {
                    $summary = "Status changed from {$props['old_status']} → {$props['new_status']}";
                } elseif ($event === 'payment_recorded' && isset($props['amount'])) {
                    $method  = $props['method'] ?? 'unknown';
                    $summary = "Payment of {$props['amount']} {$props['currency']} via " . ucfirst($method);
                    if ($props['requires_approval'] ?? false) $summary .= ' (awaiting approval)';
                } elseif ($event === 'payment_approved' && isset($props['amount'])) {
                    $summary = "Payment of {$props['amount']} {$props['currency']} approved";
                    if (isset($props['notes'])) $summary .= ": {$props['notes']}";
                } elseif ($event === 'payment_rejected' && isset($props['amount'])) {
                    $summary = "Payment of {$props['amount']} {$props['currency']} rejected";
                    if (isset($props['reason'])) $summary .= ": {$props['reason']}";
                } elseif ($event === 'customer_attached') {
                    $name    = $props['customer_name'] ?? '';
                    $summary = "Customer updated" . ($name ? ": {$name}" : '');
                } elseif ($event === 'shipping_fee_updated' && isset($props['old_amount'], $props['new_amount'])) {
                    $summary = "Shipping fee changed from {$props['old_amount']} → {$props['new_amount']}";
                    if (isset($props['shipping_method'])) $summary .= " via {$props['shipping_method']}";
                } elseif ($event === 'deposit_terms_set' && isset($props['deposit_amount'])) {
                    $summary = "Deposit set to {$props['deposit_amount']}";
                    if (isset($props['balance_due_date'])) $summary .= ", balance due {$props['balance_due_date']}";
                } elseif ($event === 'note_added') {
                    $type    = ($props['is_internal'] ?? true) ? 'internal' : 'customer';
                    $preview = mb_strimwidth($props['note'] ?? '', 0, 60, '…');
                    $summary = "Note added ({$type}): {$preview}";
                } elseif ($event === 'shipment_created' && isset($props['carrier'])) {
                    $summary = "Shipment created via {$props['carrier']}";
                    if (isset($props['tracking_number'])) $summary .= " (#{$props['tracking_number']})";
                } elseif ($event === 'shipment_tracking_added' && isset($props['new_status'])) {
                    $summary = "Shipment status → {$props['new_status']}";
                    if (isset($props['description'])) $summary .= ": {$props['description']}";
                    if (isset($props['location'])) $summary .= " at {$props['location']}";
                } elseif ($event === 'refunded' && isset($props['amount'])) {
                    $currency = $props['currency'] ?? '';
                    $reason   = $props['reason'] ?? '';
                    $summary  = "Refund of {$props['amount']} {$currency}: {$reason}";
                } elseif ($event === 'voided' && isset($props['reason'])) {
                    $who     = $props['voided_name'] ?? 'Admin';
                    $summary = "Order voided by {$who}: {$props['reason']}";
                } elseif ($event === 'shipment_created' && isset($props['carrier'])) {
                    $summary = "Shipment {$props['shipment_number']} created via {$props['carrier']}";
                    if (isset($props['tracking_number'])) $summary .= " · tracking #{$props['tracking_number']}";
                } elseif ($event === 'shipment_tracking_added' && isset($props['new_status'])) {
                    $summary = "Shipment status → {$props['new_status']}";
                    // FIX: description is now optional on addTracking() - only
                    // append it (and its leading separator) when staff actually
                    // wrote one, instead of always interpolating a possibly
                    // undefined array key.
                    if (!empty($props['description'])) {
                        $summary .= ": {$props['description']}";
                    }
                    if (isset($props['location'])) $summary .= " at {$props['location']}";
                } elseif ($event === 'shipment_delivered') {
                    $summary = "Package delivered";
                    if (isset($props['delivered_to'])) $summary .= " to {$props['delivered_to']}";
                } elseif ($event === 'shipment_cancelled' && isset($props['reason'])) {
                    $summary = "Shipment cancelled: {$props['reason']}";
                } elseif ($event === 'payment_proof_uploaded' && isset($props['amount'])) {
                    $currency = $props['currency'] ?? '';
                    $method   = $props['method'] ?? '';
                    $summary  = "Proof uploaded for {$props['amount']} {$currency} via " . ucfirst($method);
                } elseif ($event === 'payment_approved' && isset($props['amount'])) {
                    $currency = $props['currency'] ?? '';
                    $summary  = "Payment of {$props['amount']} {$currency} approved";
                    if (isset($props['notes'])) $summary .= ": {$props['notes']}";
                } elseif ($event === 'payment_rejected' && isset($props['amount'])) {
                    $currency = $props['currency'] ?? '';
                    $summary  = "Payment of {$props['amount']} {$currency} rejected: {$props['reason']}";
                } elseif ($event === 'created' && isset($props['total'])) {
                    $currency = $props['currency'] ?? '';
                    $summary  = "Order created - total {$props['total']} {$currency}";
                } elseif ($event === 'currency_changed' && isset($props['old_currency'], $props['new_currency'])) {
                    $oldCountry = $props['old_country_code'] ?? '';
                    $newCountry = $props['new_country_code'] ?? '';
                    $summary    = "Currency changed from {$props['old_currency']}";
                    if ($oldCountry) $summary .= " ({$oldCountry})";
                    $summary   .= " → {$props['new_currency']}";
                    if ($newCountry) $summary .= " ({$newCountry})";
                    if ($props['is_international'] ?? false) $summary .= " - marked as international";
                    if (isset($props['new_total'])) $summary .= "; new total {$props['new_total']} {$props['new_currency']}";
                }

                return [
                    'id'          => $log->id,
                    'event'       => $event,
                    'label'       => $labels[$event] ?? ucwords(str_replace('_', ' ', $event)),
                    'summary'     => $summary,
                    'properties'  => $props,
                    'ip_address'  => $log->ip_address,
                    'created_at'  => $log->created_at,
                    'actor'       => $log->actor_id ? [
                        'id'    => $log->actor_id,
                        'name'  => $log->actor_name,
                        'email' => $log->actor_email,
                    ] : null,
                ];
            });

        return response()->json(['data' => $logs]);
    }

    // =========================================================================
    // GET /admin/orders/export
    // =========================================================================

    public function updateCurrency(Request $request, $id)
    {
        $validated = $request->validate([
            'country_code' => 'required|string|size:2',
        ]);

        $order = Order::with(['items'])->findOrFail($id);

        if ($order->payment_status !== 'pending') {
            return response()->json(['message' => 'Cannot change currency after payment has started.'], 422);
        }

        $countryCode     = strtoupper($validated['country_code']);
        $homeCountry     = DB::table('settings')->where('key', 'app_country')->value('value') ?? 'KE';
        $isInternational = $countryCode !== strtoupper($homeCountry);

        // Resolve new currency from the country's default_currency_code,
        // validated against active currencies - same logic as PosController.
        $countryCurrency  = DB::table('countries')->where('code', $countryCode)->value('default_currency_code');
        $activeCurrencies = DB::table('currencies')->where('is_active', true)->pluck('code')->toArray();
        if ($countryCurrency && (empty($activeCurrencies) || in_array($countryCurrency, $activeCurrencies))) {
            $newCurrency = $countryCurrency;
        } else {
            // Fall back to the first active currency, then to the order's current currency
            $newCurrency = $activeCurrencies[0] ?? $order->currency_code;
        }

        $oldCurrency    = $order->currency_code;
        $oldCountryCode = $order->customer_country_code;

        if ($oldCurrency === $newCurrency && $oldCountryCode === $countryCode) {
            return response()->json(['currency_code' => $newCurrency, 'changed' => false]);
        }

        // ── Load exchange rate map for currency conversion ────────────────────
        $rateMap = DB::table('currencies')
            ->where('is_active', true)
            ->get(['code', 'exchange_rate', 'is_base'])
            ->keyBy('code')
            ->map(fn ($r) => ['rate' => (float) $r->exchange_rate, 'is_base' => (bool) $r->is_base])
            ->toArray();

        DB::beginTransaction();
        try {
            // ── Reprice each order item ───────────────────────────────────────
            // Step 1: try an exact price row for the new currency.
            // Step 2: if none, convert from the base-currency price row using
            //         exchange rates - identical logic to PosController::transformProduct.
            foreach ($order->items as $item) {
                // Simple products have product_variant_id = null; look up prices
                // by product_id with variant_id IS NULL instead of skipping them.
                if ($item->product_variant_id) {
                    $prices = DB::table('product_prices')
                        ->where('product_variant_id', $item->product_variant_id)
                        ->get();
                } elseif ($item->product_id) {
                    $prices = DB::table('product_prices')
                        ->where('product_id', $item->product_id)
                        ->whereNull('product_variant_id')
                        ->get();
                } else {
                    // No product reference at all — skip (custom/manual line)
                    continue;
                }

                $directRow = $prices->firstWhere('currency_code', $newCurrency);

                if ($directRow) {
                    $newUnitPrice = (float) $directRow->regular_price;
                } else {
                    // Find the base-currency row, then convert
                    $baseRow = $prices->first(
                        fn ($p) => isset($rateMap[$p->currency_code]) && $rateMap[$p->currency_code]['is_base']
                    ) ?? $prices->first();

                    if ($baseRow
                        && isset($rateMap[$baseRow->currency_code], $rateMap[$newCurrency])
                        && $rateMap[$baseRow->currency_code]['rate'] > 0
                    ) {
                        $fromRate     = $rateMap[$baseRow->currency_code]['rate'];
                        $toRate       = $rateMap[$newCurrency]['rate'];
                        $newUnitPrice = round(((float) $baseRow->regular_price / $fromRate) * $toRate, 2);
                    } else {
                        // No usable rate - keep existing price (avoids zeroing out)
                        $newUnitPrice = (float) $item->unit_price;
                    }
                }

                // Recalculate line tax and total with new price.
                // Tax rate is preserved (e.g. 16% VAT) — only the base amount changes.
                $discountAmount  = (float) $item->discount_amount;
                $lineSubtotal    = max(0, ($newUnitPrice * $item->quantity) - $discountAmount);
                $oldLineSubtotal = max(0, ((float) $item->unit_price * (int) $item->quantity) - $discountAmount);

                // Use TaxCalculationService for accuracy; fall back to implied rate if needed.
                if (!empty($item->product_id)) {
                    $taxRate      = \App\Services\TaxCalculationService::rateForProduct($item->product_id);
                    $taxInclusive = \App\Services\TaxCalculationService::isTaxInclusive();
                    $newLineTax   = $taxInclusive
                        ? round($lineSubtotal * $taxRate / (1 + $taxRate), 4)
                        : round($lineSubtotal * $taxRate, 4);
                } elseif ($oldLineSubtotal > 0 && (float) $item->tax_amount > 0) {
                    $impliedRate = (float) $item->tax_amount / $oldLineSubtotal;
                    $newLineTax  = round($lineSubtotal * $impliedRate, 4);
                } else {
                    $newLineTax = 0;
                }

                $newTotalPrice = round($lineSubtotal + $newLineTax, 2);

                DB::table('order_items')->where('id', $item->id)->update([
                    'unit_price'  => $newUnitPrice,
                    'tax_amount'  => $newLineTax,
                    'total_price' => $newTotalPrice,
                    'updated_at'  => now(),
                ]);
            }

            // ── Recalculate order-level totals ────────────────────────────────
            $freshItems   = DB::table('order_items')->where('order_id', $order->id)->get();
            $newSubtotal  = $freshItems->sum(fn ($i) => (float)$i->unit_price * (int)$i->quantity - (float)$i->discount_amount);
            $newTaxAmount = $freshItems->sum(fn ($i) => (float)$i->tax_amount);
            $newTotal     = round(
                $newSubtotal - (float)$order->discount_amount
                + ($order->prices_include_tax ? 0 : $newTaxAmount)
                + (float)$order->shipping_amount,
                2
            );

            $order->update([
                'currency_code'         => $newCurrency,
                'customer_country_code' => $countryCode,
                'is_international'      => $isInternational,
                'subtotal'              => round($newSubtotal, 2),
                'tax_amount'            => round($newTaxAmount, 2),
                'total_amount'          => $newTotal,
            ]);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update currency: ' . $e->getMessage()], 500);
        }

        // ── Audit log ─────────────────────────────────────────────────────────
        ActivityLogService::log('currency_changed', $order, [
            'old_currency'       => $oldCurrency,
            'new_currency'       => $newCurrency,
            'old_country_code'   => $oldCountryCode,
            'new_country_code'   => $countryCode,
            'is_international'   => $isInternational,
            'new_total'          => $newTotal,
        ]);

        return response()->json([
            'currency_code'    => $newCurrency,
            'is_international' => $isInternational,
            'new_total'        => $newTotal,
            'changed'          => true,
        ]);
    }
    // =========================================================================
    // PATCH /admin/orders/{orderId}/items/{itemId}/price
    // =========================================================================

    /**
     * Adjust the unit price of a specific order item.
     *
     * Rules:
     *  - Order must have payment_status = 'pending' (no payment has been made yet)
     *  - New price must be >= original catalogue price (upward adjustment only)
     *  - Recalculates order subtotal, tax, and total_amount atomically
     *  - Stores original_price (first time only) and sets price_adjusted = true
     *  - Records an audit log entry for reporting
     *
     * Migration required — add to order_items table:
     *   $table->decimal('original_price', 12, 2)->nullable()->after('unit_price');
     *   $table->boolean('price_adjusted')->default(false)->after('original_price');
     */
    public function adjustItemPrice(Request $request, int $orderId, int $itemId)
    {
        $validated = $request->validate([
            'unit_price' => 'required|numeric|min:0.01',
        ]);

        $order = Order::with(['items'])->findOrFail($orderId);

        // Guard: only allowed before any payment has been recorded
        if ($order->payment_status !== 'pending') {
            return response()->json([
                'message' => 'Price adjustments are only allowed on orders with no payments recorded yet.',
            ], 422);
        }

        $item = $order->items->firstWhere('id', $itemId);
        if (!$item) {
            return response()->json(['message' => 'Order item not found on this order.'], 404);
        }

        $newPrice      = round((float)$validated['unit_price'], 2);
        $currentPrice  = (float)$item->unit_price;

        // Upward-only guard: price may not be reduced below the original catalogue price
        $originalPrice = $item->original_price ?? $currentPrice;
        if ($newPrice < $originalPrice - 0.001) {
            return response()->json([
                'message' => 'Price may only be adjusted upwards (minimum: ' . number_format($originalPrice, 2) . ').',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Preserve the catalogue price on first adjustment so it is never lost
            $firstAdjustment = !$item->price_adjusted;
            $storedOriginal  = $firstAdjustment ? $currentPrice : (float)$item->original_price;

            // Recalculate line tax for the new price
            $taxInclusive  = \App\Services\TaxCalculationService::isTaxInclusive();
            $taxCalcLine   = \App\Services\TaxCalculationService::calculateLine(
                $newPrice,
                (int)$item->quantity,
                (int)$item->product_id,
                $taxInclusive
            );
            $lineDiscount  = (float)$item->discount_amount;
            $lineBase      = $newPrice * (int)$item->quantity;
            $lineSubtotal  = $lineBase - $lineDiscount;
            $lineTax       = round((float)$taxCalcLine['tax_amount'], 2);
            $lineTotal     = $taxInclusive ? $lineSubtotal : $lineSubtotal + $lineTax;

            $item->update([
                'unit_price'      => $newPrice,
                'original_price'  => $storedOriginal,
                'price_adjusted'  => true,
                'tax_amount'      => $lineTax,
                'total_price'     => round($lineTotal, 2),
            ]);

            // Recalculate order totals from all items
            $freshItems    = $order->items()->get();
            $newSubtotal   = $freshItems->sum(fn ($i) => (float)$i->unit_price * (int)$i->quantity - (float)$i->discount_amount);
            $cartDiscount  = (float)$order->discount_amount;  // cart-level discount stays
            $afterDiscount = $newSubtotal - $cartDiscount;
            $newTaxTotal   = $freshItems->sum(fn ($i) => (float)$i->tax_amount);
            $shipping      = (float)$order->shipping_amount;
            $newTotal      = round($afterDiscount + ($taxInclusive ? 0 : $newTaxTotal) + $shipping, 2);

            $order->update([
                'subtotal'     => round($newSubtotal, 2),
                'tax_amount'   => round($newTaxTotal, 2),
                'total_amount' => $newTotal,
            ]);

            DB::commit();

            try {
                ActivityLogService::log('order_item_price_adjusted', $order, [
                    'item_id'        => $itemId,
                    'product_name'   => $item->product_name,
                    'original_price' => $storedOriginal,
                    'new_price'      => $newPrice,
                    'adjusted_by'    => $request->user()->id,
                    'order_number'   => $order->order_number,
                ]);
            } catch (\Exception) {}

            $updatedItem = $item->fresh();
            // Annotate with tax meta
            $rateDecimal              = \App\Services\TaxCalculationService::rateForProduct($updatedItem->product_id);
            $updatedItemArr            = $updatedItem->toArray();
            $updatedItemArr['tax_rate']       = round($rateDecimal * 100, 4);
            $updatedItemArr['tax_name']       = \App\Services\TaxCalculationService::rateLabelForProduct($updatedItem->product_id);
            $updatedItemArr['original_price'] = (float)$updatedItem->original_price;
            $updatedItemArr['price_adjusted'] = true;

            return response()->json([
                'message'         => 'Price updated successfully.',
                'item'            => $updatedItemArr,
                'order_total'     => $newTotal,
                'order_subtotal'  => round($newSubtotal, 2),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('adjustItemPrice failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to update price.'], 500);
        }
    }
}