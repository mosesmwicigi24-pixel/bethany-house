<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentLinkService;

/**
 * PublicOrderController — the customer's own view of one order.
 *
 * GET  /api/v1/order/{public_token}              the order: receipt when paid,
 *                                                checkout when not
 * POST /api/v1/order/{public_token}/pay-session  re-arm checkout (mint a 72h
 *                                                payment token on demand)
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * There was no URL in this system that meant "this order". /pay/{token} was a
 * 72-hour pay SESSION that the hub refuses to reissue once an order is paid, so
 * every link a customer was ever given eventually 404'd — all 88 that Neema
 * sent were dead when the owner checked. This route is keyed on the order's
 * durable public_token instead, so a receipt keeps working for years.
 *
 * ── The security rule that governs this file ─────────────────────────────────
 * The TOKEN is the authorisation. An order id or an order number must NEVER
 * yield customer data, because both are business identifiers that travel
 * through invoices, WhatsApp and staff screens. Consequences, all deliberate:
 *
 *   - the route takes no id and offers no lookup by order_number;
 *   - an unknown token is 404, never 403 — a 403 confirms existence;
 *   - withoutViewerScope() is REQUIRED: Order carries a global viewer scope and
 *     an unauthenticated request has no viewer, so the scope would 404 every
 *     public request. That scope is inert today (all roles ship data_scope
 *     'all'), which is exactly why the test for it matters — without the bypass
 *     this route breaks silently the first time anyone narrows a role.
 */
class PublicOrderController extends Controller
{
    public function show(string $token)
    {
        $order = $this->resolve($token);
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $settings  = SettingController::getAll();
        $paid      = Payment::where('order_id', $order->id)->where('status', 'paid');
        $totalPaid = (float) (clone $paid)->sum('amount');
        $amountDue = max(0, (float) $order->total_amount - $totalPaid);
        $shipment  = $order->shipments()->orderByDesc('id')->first();

        return response()->json([
            'order_number'   => $order->order_number,
            'ordered_at'     => $order->created_at?->toIso8601String(),
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'currency_code'  => $order->currency_code,

            // The money, itemised — a receipt that shows only a green tick is
            // not a receipt. (The old paid screen showed exactly that.)
            'subtotal'        => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'shipping_amount' => (float) $order->shipping_amount,
            'tax_amount'      => (float) $order->tax_amount,
            'total_amount'    => (float) $order->total_amount,
            'amount_paid'     => $totalPaid,
            'amount_due'      => $amountDue,
            'prices_include_tax' => (bool) ($order->prices_include_tax ?? false),

            'items' => $order->items->map(fn ($i) => [
                'name'         => $i->product_name,
                'variant_name' => $i->variant_name,
                'quantity'     => (int) $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'total_price'  => (float) $i->total_price,
                'notes'        => $i->notes,
            ])->all(),

            'payments' => (clone $paid)->orderBy('id')->get()->map(fn ($p) => [
                'method'             => $p->payment_method,
                'amount'             => (float) $p->amount,
                'provider_reference' => $p->provider_reference,
                'paid_at'            => $p->created_at?->toIso8601String(),
            ])->all(),

            'shipment' => $shipment ? [
                'status'          => $shipment->status,
                'carrier'         => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
            ] : null,

            // Same rule the pay page follows (owner's direction, 2026-08-08):
            // this is the customer's own receipt, so it shows their own name
            // and number unmasked.
            'customer_name'  => trim(implode(' ', array_filter([
                                    $order->customer_first_name ?? $order->user?->first_name,
                                    $order->customer_last_name ?? $order->user?->last_name,
                                ]))) ?: null,
            'customer_phone' => $order->customer_phone ?? $order->user?->phone,

            'business_name'    => $settings['app_name'] ?? 'Bethany House',
            'business_logo'    => $settings['app_logo_url'] ?? null,
            'business_tagline' => $settings['app_tagline'] ?? null,

            // ONE copy of the rail rule — see PublicPaymentController.
            'available_methods' => $amountDue > 0
                ? PublicPaymentController::availableMethodsFor($order)
                : [],
        ]);
    }

    /**
     * Hand the page a live pay session so it can drive the existing, hardened
     * /api/v1/pay/{payment_token}/* endpoints unchanged. This is what stops
     * links dying: the durable token can always mint a fresh 72-hour one.
     */
    public function startPayment(string $token)
    {
        $order = $this->resolve($token);
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $totalPaid = (float) Payment::where('order_id', $order->id)
            ->where('status', 'paid')->sum('amount');
        if ($order->payment_status === 'paid' || (float) $order->total_amount - $totalPaid <= 0) {
            return response()->json(['message' => 'This order is already fully paid.'], 409);
        }

        $session = PaymentLinkService::mint($order);

        return response()->json([
            'payment_token' => $session['token'],
            'expires_at'    => $session['expires_at'],
        ]);
    }

    private function resolve(string $token): ?Order
    {
        // Length guard first: this route is public and unauthenticated, so a
        // scanner should cost a string comparison, not an indexed lookup.
        if (strlen($token) < 32 || !ctype_xdigit($token)) {
            return null;
        }

        return Order::withoutViewerScope()
            ->with(['items', 'user'])
            ->where('public_token', $token)
            ->first();
    }
}
