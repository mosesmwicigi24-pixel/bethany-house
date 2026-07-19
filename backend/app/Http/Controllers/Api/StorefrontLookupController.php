<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\StorefrontOtpService;
use Illuminate\Http\Request;

/**
 * Passwordless "Find my orders" for the storefront.
 *
 * Guests check out without an account; this lets them retrieve their full
 * order history on any device by proving control of the phone/email on the
 * order via a one-time code. It never gates checkout — it is a pure lookup.
 *
 *   POST /storefront/otp/request  { contact }          → send a code
 *   POST /storefront/otp/verify   { contact, code }    → { token, orders }
 *   GET  /storefront/my-orders    (X-BH-Session: token) → { orders }
 *
 * Codes and the verified session live in the cache (see StorefrontOtpService);
 * there is no account table. Responses never confirm whether a contact exists.
 */
class StorefrontLookupController extends Controller
{
    public function __construct(private StorefrontOtpService $otp)
    {
    }

    public function requestCode(Request $request)
    {
        $data = $request->validate([
            'contact' => 'required|string|max:120',
        ]);

        ['type' => $type, 'value' => $value] = StorefrontOtpService::normalizeContact($data['contact']);
        if (!$type) {
            return response()->json([
                'message' => 'Enter a valid phone number or email address.',
            ], 422);
        }

        $result = $this->otp->requestCode($type, $value);

        if (!empty($result['throttled'])) {
            return response()->json([
                'message'     => 'A code was just sent. Check your messages or wait a moment before retrying.',
                'destination' => $result['hint'],
            ], 429);
        }

        return response()->json([
            'sent'        => true,
            'channels'    => $result['channels'],
            'destination' => $result['hint'],
            'expires_in'  => StorefrontOtpService::CODE_TTL,
        ]);
    }

    public function verifyCode(Request $request)
    {
        $data = $request->validate([
            'contact' => 'required|string|max:120',
            'code'    => 'required|string|min:4|max:8',
        ]);

        ['type' => $type, 'value' => $value] = StorefrontOtpService::normalizeContact($data['contact']);
        if (!$type) {
            return response()->json(['message' => 'Enter a valid phone number or email address.'], 422);
        }

        $token = $this->otp->verify($type, $value, $data['code']);
        if (!$token) {
            return response()->json(['message' => 'That code is invalid or has expired.'], 422);
        }

        return response()->json([
            'token'  => $token,
            'orders' => $this->ordersFor($type, $value),
        ]);
    }

    public function myOrders(Request $request)
    {
        $token = $request->header('X-BH-Session') ?: (string) $request->query('token', '');
        if ($token === '') {
            return response()->json(['message' => 'Sign in to view your orders.'], 401);
        }

        $contact = $this->otp->sessionContact($token);
        if (!$contact) {
            return response()->json(['message' => 'Your session has expired. Request a new code.'], 401);
        }

        return response()->json([
            'orders' => $this->ordersFor($contact['type'], $contact['value']),
        ]);
    }

    /** Every order tied to a verified contact, newest first, richly serialised. */
    private function ordersFor(string $type, string $value): array
    {
        $query = Order::with(['items'])
            ->orderByDesc('id')
            ->limit(100);

        if ($type === 'phone') {
            $query->whereIn('customer_phone', StorefrontOtpService::phoneVariants($value));
        } else {
            $query->where('customer_email', $value);
        }

        return $query->get()->map(fn (Order $o) => $this->serialize($o))->all();
    }

    private function serialize(Order $order): array
    {
        $shipment = $order->shipments()->orderByDesc('id')->first();
        $invoice  = $order->invoiceDocument()->first();
        $unpaid   = $order->payment_status !== 'paid';

        return [
            'order_number'   => $order->order_number,
            'created_at'     => optional($order->created_at)->toIso8601String(),
            'order_type'     => $order->order_type,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'currency_code'  => $order->currency_code,
            'subtotal'       => (float) $order->subtotal,
            'tax_amount'     => (float) $order->tax_amount,
            'shipping_amount' => (float) $order->shipping_amount,
            'total_amount'   => (float) $order->total_amount,
            'delivery_type'  => $order->delivery_type,
            'invoice_number' => $invoice?->number,
            'payment_token'  => $unpaid ? $order->payment_token : null,
            'payment_link'   => $unpaid ? $this->paymentLink($order->payment_token) : null,
            'customer'       => [
                'first_name' => $order->customer_first_name,
                'last_name'  => $order->customer_last_name,
                'phone'      => $order->customer_phone,
                'email'      => $order->customer_email,
            ],
            'shipping'       => [
                'address' => $order->shipping_address_line1,
                'city'    => $order->shipping_city,
                'country' => $order->shipping_country_code,
            ],
            'items'          => $order->items->map(fn ($i) => [
                'name'         => $i->product_name,
                'variant_name' => $i->variant_name,
                'quantity'     => (int) $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'total_price'  => (float) $i->total_price,
                'notes'        => $i->notes,
            ])->all(),
            'shipment'       => $shipment ? [
                'status'                  => $shipment->status,
                'carrier'                 => $shipment->carrier,
                'tracking_number'         => $shipment->tracking_number,
                'tracking_url'            => $shipment->tracking_token ? $shipment->trackingPageUrl() : null,
                'shipped_at'              => optional($shipment->shipped_at)->toIso8601String(),
                'estimated_delivery_date' => $shipment->estimated_delivery_date,
            ] : null,
        ];
    }

    private function paymentLink(?string $token): ?string
    {
        return $token ? rtrim(config('app.frontend_url'), '/') . "/pay/{$token}" : null;
    }
}
