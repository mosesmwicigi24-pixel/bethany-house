<?php

namespace App\Support;

/**
 * What a payment counts for on its order, in the ORDER's currency
 * (owner, 2026-11-05). The one rule every balance uses.
 *
 * A payment keeps its own amount and currency (what arrived). When it was
 * made in another currency — the order switched currency after it — its
 * order_amount / order_currency_code say what it counts for in the order's
 * currency now (stamped by App\Services\OrderCurrencySwitch). Otherwise it
 * counts as its own amount.
 *
 * Refunds are in the payment's own currency; the counted refund is the same
 * share of the counted amount.
 *
 * Money truth (reports in KES) keeps converting each payment at its own
 * currency and rate — that is what arrived. Only balances read this.
 */
final class PaymentCredit
{
    /** Counted, gross of refunds, as SQL on a payments alias. */
    public static function grossSql(string $p = 'payments'): string
    {
        return "(CASE WHEN {$p}.order_currency_code IS NOT NULL THEN {$p}.order_amount ELSE {$p}.amount END)";
    }

    /** Counted, net of refunds, as SQL on a payments alias. */
    public static function netSql(string $p = 'payments'): string
    {
        return "(CASE WHEN {$p}.order_currency_code IS NOT NULL AND {$p}.amount <> 0"
            . " THEN ({$p}.amount - COALESCE({$p}.refund_amount, 0)) * {$p}.order_amount / {$p}.amount"
            . " ELSE {$p}.amount - COALESCE({$p}.refund_amount, 0) END)";
    }

    /** Counted, gross of refunds, for one payment row (model or stdClass). */
    public static function gross(object $p): float
    {
        return $p->order_currency_code !== null ? (float) $p->order_amount : (float) $p->amount;
    }

    /** Counted, net of refunds, for one payment row. */
    public static function net(object $p): float
    {
        $amount = (float) $p->amount;
        $net    = $amount - (float) ($p->refund_amount ?? 0);
        if ($p->order_currency_code === null || $amount == 0.0) {
            return $net;
        }

        return round($net * (float) $p->order_amount / $amount, 2);
    }
}
