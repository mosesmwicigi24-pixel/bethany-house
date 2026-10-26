<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * The words — and the status codes — for refusing a payment on a dead order.
 *
 * The rule itself is Order::isPayable() (owner, 2026-10-26: "yes, block
 * payment on cancelled orders"). This class only says it, the same way at
 * every door, so a customer on the pay page and a cashier at the till are
 * never told two different things:
 *
 *   staff     422  "Order BH-… is cancelled — a payment can't be recorded against it."
 *   customer  410  "This order was cancelled — please contact us." + the shop's contact
 *
 * Doors that START or OFFER a payment refuse. Doors that RECORD money which
 * has already moved (gateway callbacks, a proof finance approves) never ask
 * this — see App\Services\Payments\LatePayment.
 */
final class PaymentGate
{
    public const REASON = 'order_not_payable';

    /** How a dead status reads in a sentence. */
    private const STAFF_VERB = [
        'cancelled' => 'is cancelled',
        'voided'    => 'is voided',
        'refunded'  => 'has been refunded',
    ];

    public static function staffMessage(Order $order): string
    {
        $state = self::STAFF_VERB[$order->status] ?? 'is closed';

        return "Order {$order->order_number} {$state} — a payment can't be recorded against it.";
    }

    /** What staff hear when they confirm money that arrived after the cancel. */
    public static function lateMessage(Order $order): string
    {
        $state = self::STAFF_VERB[$order->status] ?? 'is closed';

        return "Payment recorded. Order {$order->order_number} {$state}, so it stays that way — the money is flagged \"Paid after cancel — refund needed\" for finance.";
    }

    public static function customerMessage(Order $order): string
    {
        // A voided invoice is a cancelled order to the customer; "voided" is
        // the office's word, not theirs.
        return $order->status === 'refunded'
            ? 'This order was refunded — please contact us.'
            : 'This order was cancelled — please contact us.';
    }

    /** 422 for a staff door, or null when the order may be paid. */
    public static function refuseStaff(Order $order): ?JsonResponse
    {
        if ($order->isPayable()) {
            return null;
        }

        return response()->json([
            'message'      => self::staffMessage($order),
            'reason'       => self::REASON,
            'order_status' => $order->status,
        ], 422);
    }

    /**
     * 410 for a customer door, or null when the order may be paid. Gone, not
     * 404: the link was real, and the customer should hear what happened to
     * it and whom to call — never "not found".
     */
    public static function refuseCustomer(Order $order): ?JsonResponse
    {
        if ($order->isPayable()) {
            return null;
        }

        return response()->json(self::customerPayload($order), 410);
    }

    /** The refusal body, also embedded in the public order page. */
    public static function customerPayload(Order $order): array
    {
        return [
            'message'      => self::customerMessage($order),
            'reason'       => self::REASON,
            'order_number' => $order->order_number,
            'order_status' => $order->status,
            'contact'      => self::contact(),
        ];
    }

    /** The shop's own contact, from Settings → General. */
    public static function contact(): array
    {
        try {
            $s = \App\Http\Controllers\Api\SettingController::getAll();
        } catch (\Throwable) {
            $s = [];
        }

        return [
            'business_name' => $s['app_name'] ?? 'Bethany House',
            'phone'         => $s['app_phone'] ?? null,
            'email'         => $s['app_email'] ?? null,
        ];
    }
}
