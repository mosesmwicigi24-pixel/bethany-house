<?php

namespace App\Services\Neema;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells Neema what just happened to an order, so she can act on it —
 * thank the customer the minute payment lands, announce shipping, or hand
 * a disappointment (partial payment / refund) to a human with context.
 *
 * Contract: POST {NEEMA_API_URL}/api/hub/events, HMAC-signed with
 * NEEMA_EVENTS_SECRET (X-Hub-Events-Signature: sha256=<hexdigest>).
 * Neema deduplicates on the event id, so re-fires are harmless.
 *
 * INERT until NEEMA_EVENTS_SECRET is configured. Best-effort by design:
 * an unreachable Neema must NEVER break an order save.
 */
class NeemaEventEmitter
{
    public static function emit(Order $order, string $type, array $extra = []): void
    {
        $secret = (string) config('services.neema.events_secret');
        $base   = rtrim((string) config('services.neema.url'), '/');
        if ($secret === '' || $base === '') {
            return;
        }

        $payload = array_merge([
            'id'             => "hub:{$order->id}:{$type}",
            'type'           => $type,
            'order_number'   => $order->order_number,
            'customer_phone' => $order->customer_phone,
            'amount'         => $order->total_amount !== null ? (float) $order->total_amount : null,
            'currency'       => $order->currency_code ?: 'KES',
        ], $extra);

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $sig  = 'sha256=' . hash_hmac('sha256', $body, $secret);
            Http::timeout(4)
                ->withHeaders([
                    'Content-Type'           => 'application/json',
                    'X-Hub-Events-Signature' => $sig,
                ])
                ->withBody($body, 'application/json')
                ->post($base . '/api/hub/events');
        } catch (\Throwable $e) {
            Log::info("Neema event {$type} for order {$order->order_number} not delivered: " . $e->getMessage());
        }
    }
}
