<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Minting a pay session for an order.
 *
 * Extracted from OrderController::paymentLink so the public order page can
 * re-arm its own checkout. That is the fix for the dead-link class: a payment
 * token lives 72 hours, and before this every link Neema sent simply stopped
 * working on day four with no way back — all 88 of them were expired when the
 * owner checked, and the newest answered "Payment link not found or has
 * expired."
 *
 * The 72-hour window is deliberately KEPT. It is an anti-abuse property on the
 * act of paying, and a durable public_token now carries the act of *viewing*,
 * so nothing is lost by re-minting on demand for whoever already holds the
 * order's own token.
 */
class PaymentLinkService
{
    public const TTL_HOURS = 72;

    /**
     * Return a live pay session for the order, minting or renewing the token
     * when it is missing or expired. Caller is responsible for refusing paid
     * orders — this method has no opinion about whether payment is due.
     *
     * @return array{token: string, expires_at: ?string, url: string}
     */
    public static function mint(Order $order): array
    {
        $needsToken = empty($order->payment_token)
            || ($order->payment_token_expires_at && $order->payment_token_expires_at->isPast());

        if ($needsToken) {
            $payload = $order->order_number . $order->created_at->toISOString() . Str::random(8);

            $order->forceFill([
                'payment_token'            => hash_hmac('sha256', $payload, config('app.key')),
                'payment_token_expires_at' => now()->addHours(self::TTL_HOURS),
            ])->save();

            $order->refresh();
        }

        return [
            'token'      => $order->payment_token,
            'expires_at' => $order->payment_token_expires_at?->toISOString(),
            'url'        => rtrim(config('app.frontend_url'), '/') . "/pay/{$order->payment_token}",
        ];
    }

    /** The durable, customer-facing URL for an order — the receipt AND the checkout. */
    public static function publicUrl(Order $order): string
    {
        return rtrim(config('app.frontend_url'), '/') . "/order/{$order->public_token}";
    }
}
