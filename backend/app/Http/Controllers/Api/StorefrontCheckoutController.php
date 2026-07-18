<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\StorefrontOrderReceiptMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\OrderShipment;
use App\Models\ProductionOrder;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\TaxCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Guest checkout bridge for the customer-facing storefront.
 *
 * The authenticated /checkout flow requires a registered customer and a
 * server-side cart; the storefront needed a single public endpoint that
 * accepts a complete order — items, guest customer details and structured
 * made-to-order measurements — in one POST. This controller:
 *
 *   • resolves currency exactly like Order::resolveCurrency (KE→KES, else
 *     USD), validated against active currencies with the same fallback as
 *     OrderController::checkout();
 *   • prices lines from product-level product_prices rows for that currency
 *     (falling back to any price row, mirroring checkout());
 *   • creates the order with order_type='online' plus a payment token, so
 *     the customer lands on the normal /pay/{token} page (M-Pesa/Paystack);
 *   • for producible lines, validates the submitted measurements against
 *     the product's measurement template and RAISES the production order
 *     immediately (draft status via ProductionOrder::boot), linking
 *     order_items.production_order_id both ways;
 *   • is idempotent on client_request_id (same key → same order).
 *
 * Deliberately NOT done here: inventory deduction/reservation. Guest orders
 * are unpaid at creation; stock is checked for availability (when the
 * product tracks inventory) but committed by staff on confirmation, the
 * same trust boundary as phone/WhatsApp orders. Shipping is 0 with a
 * shipping_fee_note — Nairobi fees / international freight are confirmed
 * on dispatch.
 */
class StorefrontCheckoutController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_request_id'      => 'nullable|string|max:100',
            'country_code'           => 'nullable|string|size:2',
            'customer.first_name'    => 'required|string|max:100',
            'customer.last_name'     => 'required|string|max:100',
            'customer.phone'         => 'required|string|max:20',
            'customer.email'         => 'nullable|email|max:255',
            'customer.church'        => 'nullable|string|max:255',
            'delivery.method'        => 'required|in:delivery,pickup',
            'delivery.address'       => 'required_if:delivery.method,delivery|nullable|string|max:255',
            'delivery.city'          => 'nullable|string|max:100',
            'payment_method'         => 'required|in:mpesa,card,cash_on_delivery',
            'notes'                  => 'nullable|string|max:5000',
            'items'                  => 'required|array|min:1|max:50',
            'items.*.slug'           => 'required|string|exists:products,slug',
            'items.*.quantity'       => 'required|integer|min:1|max:500',
            'items.*.measurements'   => 'nullable|array|max:30',
            'items.*.measurements.*' => 'nullable|string|max:50',
            'items.*.size'           => 'nullable|string|max:30',
        ]);

        // ── Idempotency: same client_request_id → same order ─────────────────
        if (!empty($validated['client_request_id'])) {
            $existing = Order::where('client_request_id', $validated['client_request_id'])->first();
            if ($existing) {
                return response()->json([
                    'message'      => 'Order already placed',
                    'order'        => $this->orderSummary($existing),
                    'payment_link' => $this->paymentLink($existing->payment_token),
                ], 200);
            }
        }

        // ── Currency from country (Order::resolveCurrency) ───────────────────
        $homeCountry     = DB::table('settings')->where('key', 'app_country')->value('value') ?? 'KE';
        $countryCode     = strtoupper($validated['country_code'] ?? $homeCountry);
        $currency        = Order::resolveCurrency($countryCode);
        $isInternational = $countryCode !== strtoupper($homeCountry);

        $activeCurrencies = DB::table('currencies')->where('is_active', true)->pluck('code')->toArray();
        if (!empty($activeCurrencies) && !in_array($currency, $activeCurrencies)) {
            $currency = DB::table('settings')->where('key', 'default_currency')->value('value') ?? 'KES';
        }

        // ── Load & vet products, price lines ─────────────────────────────────
        $lines = [];
        foreach ($validated['items'] as $index => $item) {
            $product = Product::published()
                ->with(['translations', 'prices' => fn ($q) => $q->whereNull('product_variant_id')])
                ->where('slug', $item['slug'])
                ->first();

            if (!$product) {
                return response()->json([
                    'message' => "Product '{$item['slug']}' is not available",
                ], 422);
            }

            $price = $product->prices->firstWhere('currency_code', $currency)
                ?? $product->prices->first();
            if (!$price) {
                return response()->json([
                    'message' => "Product '{$item['slug']}' has no price configured",
                ], 422);
            }

            $measurements = $this->cleanMeasurements($item['measurements'] ?? []);
            $size         = trim((string) ($item['size'] ?? ''));

            // A producible product is sold two ways: ready-made (a size, or
            // no customisation at all -> stocked line) and made-to-measure
            // (measurements supplied -> production line). Only lines that
            // actually carry measurements go to production.
            $isCustom = $product->is_producible && !empty($measurements);

            if ($isCustom) {
                $missing = $this->missingRequiredMeasurements($product, $measurements);
                if (!empty($missing)) {
                    return response()->json([
                        'message' => "Missing required measurements for '{$item['slug']}': " . implode(', ', $missing),
                    ], 422);
                }
            } elseif ($product->inventoryItems()->exists()
                && $product->getAvailableStock() < $item['quantity']) {
                $name = $product->translations->first()?->name ?? $item['slug'];
                return response()->json([
                    'message' => "Insufficient stock for {$name}",
                ], 422);
            }

            $lines[] = [
                'product'         => $product,
                'quantity'        => (int) $item['quantity'],
                'unit_price'      => (float) $price->regular_price,
                'measurements'    => $isCustom ? $measurements : [],
                'size'            => $size,
                // TaxCalculationService line shape (mirrors checkout()):
                'product_id'      => $product->id,
                'discount_amount' => 0,
            ];
        }

        $taxCalc      = TaxCalculationService::calculateOrder(array_map(fn ($l) => [
            'product_id'      => $l['product_id'],
            'unit_price'      => $l['unit_price'],
            'quantity'        => $l['quantity'],
            'discount_amount' => 0,
        ], $lines));
        $taxInclusive = $taxCalc['tax_inclusive'];
        $totalAmount  = $taxCalc['total_gross']; // shipping confirmed later, see class doc

        $prefix      = DB::table('settings')->where('key', 'order_prefix')->value('value') ?? 'ORD-';
        $orderNumber = $prefix . strtoupper(Str::random(8));
        while (Order::where('order_number', $orderNumber)->exists()) {
            $orderNumber = $prefix . strtoupper(Str::random(8));
        }

        $paymentToken = hash_hmac('sha256', $orderNumber . now()->toISOString() . Str::random(8), config('app.key'));

        $cust     = $validated['customer'];
        $delivery = $validated['delivery'];
        $isPickup = $delivery['method'] === 'pickup';

        DB::beginTransaction();
        try {
            // ── CRM: find or create the customer record ───────────────────────
            $customer = Customer::where('phone', $cust['phone'])->first();
            if (!$customer) {
                $customer = Customer::create([
                    'first_name'         => $cust['first_name'],
                    'last_name'          => $cust['last_name'],
                    'phone'              => $cust['phone'],
                    'email'              => $cust['email'] ?? null,
                    'preferred_currency' => $currency,
                    'notes'              => !empty($cust['church']) ? "Church/parish: {$cust['church']}" : null,
                ]);
            }

            $order = Order::create([
                'order_number'             => $orderNumber,
                'client_request_id'        => $validated['client_request_id'] ?? null,
                'user_id'                  => null,
                'order_type'               => 'online',
                'status'                   => 'pending',
                'currency_code'            => $currency,
                'customer_country_code'    => $countryCode,
                'is_international'         => $isInternational,
                'subtotal'                 => $taxCalc['subtotal'],
                'tax_amount'               => $taxCalc['total_tax'],
                'prices_include_tax'       => $taxInclusive,
                'shipping_amount'          => 0,
                'shipping_fee_overridden'  => false,
                'shipping_fee_note'        => $isPickup
                    ? null
                    : ($countryCode === 'KE'
                        ? 'Storefront guest order — Nairobi delivery fee confirmed on dispatch (free over KES 2,000)'
                        : 'International order — freight quoted before dispatch'),
                'total_amount'             => $totalAmount,
                'delivery_type'            => $delivery['method'],
                'payment_method'           => $validated['payment_method'],
                'customer_email'           => $cust['email'] ?? null,
                'customer_phone'           => $cust['phone'],
                'customer_first_name'      => $cust['first_name'],
                'customer_last_name'       => $cust['last_name'],
                'shipping_address_line1'   => $delivery['address'] ?? null,
                'shipping_city'            => $delivery['city'] ?? null,
                'shipping_country_code'    => $countryCode,
                'notes'                    => $validated['notes'] ?? null,
                'customer_notes'           => $this->customerNotes($cust, $lines),
                'payment_token'            => $paymentToken,
                'payment_token_expires_at' => now()->addHours(72),
                'ip_address'               => $request->ip(),
                'user_agent'               => $request->userAgent(),
            ]);

            foreach ($lines as $index => $line) {
                $product = $line['product'];
                $lineTax = $taxCalc['lines'][$index] ?? ['tax_amount' => 0, 'subtotal_gross' => $line['unit_price'] * $line['quantity']];

                $orderItem = OrderItem::create([
                    'order_id'        => $order->id,
                    'product_id'      => $product->id,
                    'product_name'    => $product->translations->first()?->name ?? $line['product']->slug,
                    'sku'             => $product->sku,
                    'quantity'        => $line['quantity'],
                    'unit_price'      => $line['unit_price'],
                    'discount_amount' => 0,
                    'tax_amount'      => $lineTax['tax_amount'],
                    'total_price'     => $lineTax['subtotal_gross'],
                    'notes'           => !empty($line['measurements'])
                        ? 'Measurements: ' . $this->measurementsToText($line['measurements'])
                        : ($line['size'] !== '' ? "Ready-made — Size {$line['size']}" : null),
                ]);

                if (!empty($line['measurements'])) {
                    // requires_production is intentionally not mass-assignable
                    $orderItem->requires_production = true;

                    $productionOrder = ProductionOrder::create([
                        'is_customer_order' => true,
                        'customer_id'       => $customer->id,
                        'customer_order_id' => $order->id,
                        'order_item_id'     => $orderItem->id,
                        'product_id'        => $product->id,
                        'quantity'          => $line['quantity'],
                        'priority'          => 'normal',
                        'due_date'          => now()->addDays(7),
                        'measurements'      => $line['measurements'] ?: null,
                        'notes'             => "Storefront order {$order->order_number}"
                            . (!empty($cust['church']) ? " — {$cust['church']}" : ''),
                    ]);

                    $orderItem->production_order_id = $productionOrder->id;
                }

                $orderItem->save();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create order',
                'error'   => $e->getMessage(),
            ], 500);
        }

        try {
            NotificationService::orderPlaced($order->id, $order->order_number);
        } catch (\Throwable $e) {
            // The order exists — a notification failure must not fail the request.
        }
        if (!empty($cust['email'])) {
            try {
                Mail::to($cust['email'])->send(new StorefrontOrderReceiptMail($order, $this->paymentLink($paymentToken)));
            } catch (\Throwable $e) {
                // Receipt email is best-effort — never fail the order for it.
            }
        }
        ActivityLogService::log('created', $order, [
            'order_number'     => $order->order_number,
            'total'            => $order->total_amount,
            'currency'         => $order->currency_code,
            'channel'          => 'online',
            'source'           => 'storefront-guest',
            'is_international' => $isInternational,
        ]);

        return response()->json([
            'message'      => 'Order placed successfully',
            'order'        => $this->orderSummary($order),
            'payment_link' => $this->paymentLink($paymentToken),
        ], 201);
    }

    /** Drop empty values, trim keys/values, cap lengths. */
    private function cleanMeasurements(array $raw): array
    {
        $clean = [];
        foreach ($raw as $k => $v) {
            $k = trim((string) $k);
            $v = trim((string) $v);
            if ($k !== '' && $v !== '') {
                $clean[mb_substr($k, 0, 100)] = mb_substr($v, 0, 50);
            }
        }
        return $clean;
    }

    /** Names of required template fields the submission is missing. */
    private function missingRequiredMeasurements(Product $product, array $measurements): array
    {
        $missing = [];
        foreach ($product->measurements ?? [] as $field) {
            if (!empty($field['required']) && empty($measurements[$field['name'] ?? ''])) {
                $missing[] = $field['name'];
            }
        }
        return $missing;
    }

    private function measurementsToText(array $m): string
    {
        return implode(', ', array_map(fn ($k) => "{$k}: {$m[$k]}", array_keys($m)));
    }

    /** Staff-facing summary on the order itself. */
    private function customerNotes(array $cust, array $lines): ?string
    {
        $parts = [];
        if (!empty($cust['church'])) {
            $parts[] = "Church/parish: {$cust['church']}";
        }
        foreach ($lines as $line) {
            $name = $line['product']->translations->first()?->name ?? $line['product']->slug;
            if (!empty($line['measurements'])) {
                $parts[] = "MADE TO ORDER — {$name} ×{$line['quantity']} — " . $this->measurementsToText($line['measurements']);
            } elseif (($line['size'] ?? '') !== '') {
                $parts[] = "READY-MADE — {$name} ×{$line['quantity']} — Size {$line['size']}";
            }
        }
        return $parts ? implode("\n", $parts) : null;
    }

    private function orderSummary(Order $order): array
    {
        return [
            'id'             => $order->id,
            'order_number'   => $order->order_number,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'currency_code'  => $order->currency_code,
            'total_amount'   => $order->total_amount,
            'payment_token'  => $order->payment_token,
        ];
    }

    /**
     * Public order status for the storefront's live receipt page, keyed by
     * the unguessable payment token. Returns payment status, the issued
     * invoice number and — once staff ship — the public tracking link, so
     * the customer's receipt turns into a live order tracker.
     */
    public function status(string $token)
    {
        $order = Order::where('payment_token', $token)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $shipment = OrderShipment::where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        $invoice = $order->invoiceDocument()->first();

        return response()->json([
            'order_number'   => $order->order_number,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'currency_code'  => $order->currency_code,
            'total_amount'   => $order->total_amount,
            'invoice_number' => $invoice?->number,
            'payment_link'   => $order->payment_status !== 'paid' ? $this->paymentLink($order->payment_token) : null,
            'shipment'       => $shipment ? [
                'status'                  => $shipment->status,
                'carrier'                 => $shipment->carrier,
                'tracking_number'         => $shipment->tracking_number,
                'tracking_url'            => $shipment->tracking_token ? $shipment->trackingPageUrl() : null,
                'shipped_at'              => $shipment->shipped_at,
                'estimated_delivery_date' => $shipment->estimated_delivery_date,
            ] : null,
        ]);
    }

    private function paymentLink(?string $token): ?string
    {
        return $token ? rtrim(config('app.frontend_url'), '/') . "/pay/{$token}" : null;
    }
}
